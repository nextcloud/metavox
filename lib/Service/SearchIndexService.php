<?php
declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCA\MetaVox\Access\VisibleFolder;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IMimeTypeLoader;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Searching metadata, newest file first, only where the user sees the files.
 * A search gives ['files' => up to $count, 'next' => the file id to go on
 * before, or null when nothing follows]. A file is 'checked' when the query
 * already left out what its team folder's ACL hides; the others are in a
 * folder groupfolders can't tell about, and the caller looks each one up.
 */
class SearchIndexService {

    /** The start of a value the filter index holds: MySQL's prefix, PostgreSQL's LEFT() (Version20250101000023). */
    public const VALUE_INDEX_CHARS = 100;

    /** Free-text matches checked per query, at least. */
    private const CHECK_CHUNK = 50;
    /** Parameters per filter statement at most; a part takes a few, more with ACL paths. */
    private const PARAMS_PER_QUERY = 5000;

    /** @var array<string, array{hits: list<array{file_id: int, groupfolder_id: int}>, cut: bool}> free-text matches, for the rounds of one request */
    private array $hits = [];

    public function __construct(
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
        private readonly SearchWordsService $searchWords,
        private readonly GroupfolderRootService $roots,
        private readonly IMimeTypeLoader $mimeTypes,
        private readonly FieldService $fields,
    ) {
    }

    /**
     * Free-text search in these team folders: files whose value of one field
     * holds every word, with their values.
     *
     * 'incomplete': every word was too common to read all its files, so some
     * may be missing. Only said where the user sees every file: elsewhere it
     * would tell that hidden files hold the words.
     *
     * @param list<VisibleFolder> $folders
     * @param ?int $before only files with a lower id
     * @return array{files: list<array<string, mixed>>, next: ?int, incomplete: bool}
     */
    public function searchFreeText(string $term, array $folders, int $count, ?int $before = null): array {
        try {
            $byId = $this->byId($folders);
            $found = $this->hits[$term . '|' . implode(',', array_keys($byId))] ??= $this->searchWords->search($term, array_keys($byId), PHP_INT_MAX, $this->assigned());
            $hits = $found['hits'];
            $at = 0;
            while ($before !== null && $at < \count($hits) && $hits[$at]['file_id'] >= $before) {
                $at++;
            }
            $files = [];
            while (\count($files) < $count && $at < \count($hits)) {
                $chunk = \array_slice($hits, $at, max(2 * ($count - \count($files)), self::CHECK_CHUNK));
                $seen = $this->visible($chunk, $byId);
                foreach ($chunk as $hit) {
                    $at++;
                    if (isset($seen[$hit['file_id']])) {
                        $files[] = $seen[$hit['file_id']];
                        if (\count($files) === $count) {
                            break;
                        }
                    }
                }
            }
            return [
                'files' => $this->withValues($files),
                'next' => $at > 0 && $at < \count($hits) ? $hits[$at - 1]['file_id'] : null,
                'incomplete' => $found['cut'] && $this->nothingHidden($byId),
            ];
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: free-text search failed', ['exception' => $e]);
            return ['files' => [], 'next' => null, 'incomplete' => false];
        }
    }

    /**
     * Files whose field holds one of these values (';#' between them). What
     * the user may not see is left out in the query itself, as in Nextcloud's
     * own search.
     *
     * @param list<VisibleFolder> $folders
     * @param ?int $before only files with a lower id
     * @return array{files: list<array<string, mixed>>, next: ?int}
     */
    public function searchByFieldValue(string $fieldName, string $fieldValue, array $folders, int $count, ?int $before = null): array {
        $byId = $this->byId($folders);
        $tokens = $this->splitMultiselectTokens($fieldValue);
        if ($byId === [] || $tokens === []) {
            return ['files' => [], 'next' => null];
        }
        $field = $this->fields->fieldByName($fieldName);
        // SQLite is for testing (see SqliteCheck): it keeps the plain query.
        if ($field !== null && $this->searchWords->isComplete()
            && !\in_array($field['field_type'], FileReferenceService::FILELINK_TYPES, true)
            && $this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_SQLITE) {
            return $this->searchValueIndex($field, $tokens, $byId, $count, $before);
        }
        // Until the index holds every value: the metadata itself, which reads
        // every match.
        try {
            $qb = $this->db->getQueryBuilder();
            $inFolders = [];
            foreach ($byId as $id => $folder) {
                $isFolder = $qb->expr()->eq('m.groupfolder_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT));
                $where = $this->inFolder($qb, $folder);
                $inFolders[] = $where === null ? $isFolder : $qb->expr()->andX($isFolder, $where);
            }
            // + 0 on purpose, as Nextcloud's own search does with mtime: on the bare
            // column the database may walk every file by id to find a few matches.
            $fileId = $qb->func()->add('m.file_id', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT));

            // One row per file and team folder for a field (the unique index on
            // file_id, groupfolder_id, field_name), so no DISTINCT. The file's
            // own value is shown: with several values it holds any of them.
            $qb->select('f.fileid', 'f.name', 'f.path', 'f.storage', 'f.mimetype', 'm.groupfolder_id', 'm.field_value')
               ->from('metavox_file_gf_meta', 'm')
               ->innerJoin('m', 'filecache', 'f', $qb->expr()->eq('m.file_id', 'f.fileid'))
               ->where($qb->expr()->eq('m.field_name', $qb->createNamedParameter($fieldName)))
               // Also as one list, so the database reads only these folders from its index.
               ->andWhere($qb->expr()->in('m.groupfolder_id', $qb->createNamedParameter(array_keys($byId), IQueryBuilder::PARAM_INT_ARRAY)))
               ->andWhere($this->holdsValue($qb, $fieldName, $tokens))
               ->andWhere($qb->expr()->orX(...$inFolders))
               ->orderBy($fileId, 'DESC')
               // One more than asked: whether anything follows.
               ->setMaxResults($count + 1);
            if ($before !== null) {
                $qb->andWhere($qb->expr()->lt($fileId, $qb->createNamedParameter($before, IQueryBuilder::PARAM_INT)));
            }

            $result = $qb->executeQuery();
            $files = [];
            $rows = 0;
            $last = null;
            while ($row = $result->fetch()) {
                if (++$rows > $count) {
                    break;
                }
                $last = (int)$row['fileid'];
                $file = $this->toFile($row, $byId[(int)$row['groupfolder_id']], [$fieldName => (string)$row['field_value']]);
                if ($file !== null) {
                    $files[] = $file;
                }
            }
            $result->closeCursor();

            return ['files' => $files, 'next' => $rows > $count ? $last : null];
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: field search failed', ['exception' => $e, 'fieldName' => $fieldName]);
            return ['files' => [], 'next' => null];
        }
    }

    /**
     * The filter through the index: per team folder and value the newest files
     * that pass the folder's conditions, all in one statement. Each part reads
     * at most $count + 1 of them, however many files hold the value.
     *
     * @param array{id: int, field_name: string, field_type: string} $field
     * @param string[] $tokens
     * @param array<int, VisibleFolder> $byId
     * @return array{files: list<array<string, mixed>>, next: ?int}
     */
    private function searchValueIndex(array $field, array $tokens, array $byId, int $count, ?int $before): array {
        $wanted = [];
        foreach ($tokens as $token) {
            foreach (SearchWordsService::valueItems($field['field_type'], $token) as $item) {
                $wanted[$item] = true;
            }
        }
        $keys = array_map(fn ($item) => SearchWordsService::valueKey((int)$field['id'], (string)$item), array_keys($wanted));
        $parts = [];
        foreach ($byId as $folder) {
            foreach ($keys as $key) {
                $parts[] = [$folder, $key];
            }
        }
        try {
            $rows = $this->newestIndexed($parts, $count + 1, $before);
            usort($rows, fn (array $a, array $b) => (int)$b['fileid'] <=> (int)$a['fileid']);
            // A file with two of the asked items comes from two parts: once is enough.
            $unique = [];
            foreach ($rows as $row) {
                $unique[(int)$row['fileid']] ??= $row;
            }
            $rows = array_values($unique);

            $files = [];
            foreach (\array_slice($rows, 0, $count) as $row) {
                $file = $this->toFile($row, $byId[(int)$row['groupfolder_id']], []);
                if ($file !== null) {
                    $files[] = $file;
                }
            }
            return [
                'files' => $this->withFieldValue($files, $field, $wanted),
                'next' => \count($rows) > $count ? (int)$rows[$count - 1]['fileid'] : null,
            ];
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: field search failed', ['exception' => $e, 'fieldName' => $field['field_name']]);
            return ['files' => [], 'next' => null];
        }
    }

    /**
     * For each team folder and value key, the newest $limit files of the index
     * that pass the folder's conditions. The database walks the index backwards
     * from the newest file and stops at $limit; UNION ALL of derived tables, a
     * statement per PARAMS_PER_QUERY parameters.
     *
     * @param list<array{0: VisibleFolder, 1: string}> $parts
     * @return list<array<string, mixed>>
     */
    private function newestIndexed(array $parts, int $limit, ?int $before): array {
        $rows = [];
        $qb = null;
        $sql = [];
        foreach ($parts as $i => [$folder, $key]) {
            if ($qb === null || \count($qb->getParameters()) > self::PARAMS_PER_QUERY) {
                if ($sql !== []) {
                    array_push($rows, ...$this->runUnion($qb, $sql));
                }
                $qb = $this->db->getQueryBuilder();
                $words = $qb->getTableName(SearchWordsService::TABLE);
                $filecache = $qb->getTableName('filecache');
                $sql = [];
            }
            $where = [
                'w.groupfolder_id = ' . $qb->createNamedParameter($folder->id, IQueryBuilder::PARAM_INT),
                'w.word_start = ' . $qb->createNamedParameter(SearchWordsService::VALUE_MARK),
                'w.word = ' . $qb->createNamedParameter($key),
            ];
            if ($before !== null) {
                $where[] = 'w.file_id < ' . $qb->createNamedParameter($before, IQueryBuilder::PARAM_INT);
            }
            $inFolder = $this->inFolder($qb, $folder);
            if ($inFolder !== null) {
                // Nextcloud leaves out the outer brackets of an OR.
                $where[] = '(' . $inFolder . ')';
            }
            $sql[] = 'SELECT * FROM (SELECT w.file_id AS fileid, w.groupfolder_id, f.name, f.path, f.storage, f.mimetype'
                . ' FROM ' . $words . ' w INNER JOIN ' . $filecache . ' f ON f.fileid = w.file_id'
                . ' WHERE ' . implode(' AND ', $where)
                . ' ORDER BY w.file_id DESC LIMIT ' . $limit . ') p' . $i;
        }
        if ($sql !== []) {
            array_push($rows, ...$this->runUnion($qb, $sql));
        }
        return $rows;
    }

    /**
     * @param list<string> $sql
     * @return list<array<string, mixed>>
     */
    private function runUnion(IQueryBuilder $qb, array $sql): array {
        $result = $this->db->executeQuery(implode(' UNION ALL ', $sql), $qb->getParameters(), $qb->getParameterTypes());
        $rows = $result->fetchAll();
        $result->closeCursor();
        return $rows;
    }

    /**
     * The file's own value for the subline, from the metadata itself. A file
     * whose value no longer holds what was asked drops out: the index is
     * only where to look.
     *
     * @param list<array<string, mixed>> $files
     * @param array{field_name: string, field_type: string} $field
     * @param array<string, true> $wanted
     * @return list<array<string, mixed>>
     */
    private function withFieldValue(array $files, array $field, array $wanted): array {
        if ($files === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('file_id', 'groupfolder_id', 'field_value')
           ->from('metavox_file_gf_meta')
           ->where($qb->expr()->in('file_id', $qb->createNamedParameter(array_column($files, 'id'), IQueryBuilder::PARAM_INT_ARRAY)))
           ->andWhere($qb->expr()->eq('field_name', $qb->createNamedParameter($field['field_name'])));
        $result = $qb->executeQuery();
        $values = [];
        while ($row = $result->fetch()) {
            $values[$row['file_id'] . ':' . $row['groupfolder_id']] = (string)$row['field_value'];
        }
        $result->closeCursor();

        $kept = [];
        foreach ($files as $file) {
            $value = $values[$file['id'] . ':' . $file['groupfolder_id']] ?? null;
            if ($value === null || array_intersect_key(array_flip(SearchWordsService::valueItems($field['field_type'], $value)), $wanted) === []) {
                continue;
            }
            $file['metadata'] = [$field['field_name'] => $value];
            $kept[] = $file;
        }
        return $kept;
    }

    /**
     * Equal to one of the values, which the index finds; for a field that
     * holds a list, one of its items.
     *
     * @param string[] $tokens
     */
    private function holdsValue(IQueryBuilder $qb, string $fieldName, array $tokens): string|ICompositeExpression {
        $type = $this->fields->fieldType($fieldName);
        // An unknown field counts as a list: that finds the most.
        if ($type !== null && !FieldService::holdsList($type)) {
            $equal = $qb->expr()->in('m.field_value', $qb->createNamedParameter($tokens, IQueryBuilder::PARAM_STR_ARRAY));
            if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_POSTGRES) {
                return $equal;
            }
            // PostgreSQL's index holds the start of each value: asked like that, it is used.
            $starts = array_values(array_unique(array_map(static fn (string $token) => mb_substr($token, 0, self::VALUE_INDEX_CHARS), $tokens)));
            return $qb->expr()->andX(
                $qb->expr()->in($qb->createFunction('LEFT(m.field_value, ' . self::VALUE_INDEX_CHARS . ')'), $qb->createNamedParameter($starts, IQueryBuilder::PARAM_STR_ARRAY)),
                $equal,
            );
        }
        $anyToken = [];
        foreach ($tokens as $token) {
            $anyToken[] = $qb->expr()->eq('m.field_value', $qb->createNamedParameter($token));
            $anyToken[] = $qb->expr()->like(
                $this->delimitedFieldValueExpr($qb),
                $qb->createNamedParameter('%;#' . $this->db->escapeLikeParameter($token) . ';#%')
            );
        }
        return $qb->expr()->orX(...$anyToken);
    }

    /**
     * Where the user sees a team folder's files, on the joined filecache row f:
     * below its root (not its trash or versions), outside what its ACL forbids.
     * Null when its root is not recorded (groupfolders before 19.1.10): then
     * every file is looked up on its own, see toFile().
     */
    private function inFolder(IQueryBuilder $qb, VisibleFolder $folder): ?ICompositeExpression {
        $roots = [];
        foreach ($this->roots->getRoots($folder->id) as $root) {
            // storage + 0 on purpose, like mtime + 0: the database then starts
            // from the field's index, not from every file in the storage.
            $roots[] = $qb->expr()->andX(
                $qb->expr()->eq($qb->func()->add('f.storage', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)), $qb->createNamedParameter($root['storage'], IQueryBuilder::PARAM_INT)),
                $qb->expr()->like('f.path', $qb->createNamedParameter($this->db->escapeLikeParameter($root['path']) . '/%')),
            );
        }
        if ($roots === []) {
            return null;
        }
        $inRoots = $qb->expr()->orX(...$roots);
        $allowed = $folder->scope?->onRow($qb, 'f');
        return $allowed === null ? $inRoots : $qb->expr()->andX($inRoots, $allowed);
    }

    /**
     * Which of these free-text matches the user sees, in one query.
     *
     * @param list<array{file_id: int, groupfolder_id: int}> $hits
     * @param array<int, VisibleFolder> $byId
     * @return array<int, array<string, mixed>> by file id
     */
    private function visible(array $hits, array $byId): array {
        $idsByFolder = [];
        $folderOf = [];
        foreach ($hits as $hit) {
            $idsByFolder[$hit['groupfolder_id']][] = $hit['file_id'];
            $folderOf[$hit['file_id']] = $hit['groupfolder_id'];
        }
        $qb = $this->db->getQueryBuilder();
        $inFolders = [];
        foreach ($idsByFolder as $id => $fileIds) {
            if (!isset($byId[$id])) {
                continue;
            }
            $isHit = $qb->expr()->in('f.fileid', $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY));
            $where = $this->inFolder($qb, $byId[$id]);
            $inFolders[] = $where === null ? $isHit : $qb->expr()->andX($isHit, $where);
        }
        if ($inFolders === []) {
            return [];
        }
        $qb->select('f.fileid', 'f.name', 'f.path', 'f.storage', 'f.mimetype')
           ->from('filecache', 'f')
           ->where($qb->expr()->orX(...$inFolders));
        $result = $qb->executeQuery();
        $seen = [];
        while ($row = $result->fetch()) {
            $id = (int)$row['fileid'];
            if (!isset($folderOf[$id])) {
                continue;
            }
            $file = $this->toFile($row, $byId[$folderOf[$id]], []);
            if ($file !== null) {
                $seen[$id] = $file;
            }
        }
        $result->closeCursor();
        return $seen;
    }

    /**
     * A result as the user sees it, its path in their Files. Null when the row
     * is not in the folder after all. Without a recorded root the caller looks
     * the file up, and gets the path from there.
     *
     * @param array<string, string> $metadata
     * @return array<string, mixed>|null
     */
    private function toFile(array $row, VisibleFolder $folder, array $metadata): ?array {
        $rootless = $this->roots->getRoots($folder->id) === [];
        $inside = $rootless ? '' : $this->roots->pathInFolder($folder->id, (int)$row['storage'], (string)$row['path']);
        if ($inside === null) {
            return null;
        }
        return [
            'id' => (int)$row['fileid'],
            'name' => (string)$row['name'],
            'path' => '/' . $folder->mountPoint . ($inside === '' ? '' : '/' . $inside),
            'mimetype' => (string)$this->mimeTypes->getMimetypeById((int)$row['mimetype']),
            'groupfolder_id' => $folder->id,
            'metadata' => $metadata,
            'checked' => $folder->scope !== null && !$rootless,
        ];
    }

    /**
     * The values for the subline, without file links: those hold only ids.
     *
     * @param list<array<string, mixed>> $files
     * @return list<array<string, mixed>>
     */
    private function withValues(array $files): array {
        if ($files === []) {
            return [];
        }
        $at = [];
        foreach ($files as $i => $file) {
            $at[$file['id']] = $i;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('m.file_id', 'm.groupfolder_id', 'm.field_name', 'm.field_value')
           ->from('metavox_file_gf_meta', 'm')
           ->innerJoin('m', 'metavox_gf_fields', 'f', $qb->expr()->eq('f.field_name', 'm.field_name'))
           // Only fields the file's team folder has, as the sidebar shows them.
           ->innerJoin('m', 'metavox_gf_assigns', 'a', $qb->expr()->andX(
               $qb->expr()->eq('a.field_id', 'f.id'),
               $qb->expr()->eq('a.groupfolder_id', 'm.groupfolder_id'),
           ))
           ->where($qb->expr()->in('m.file_id', $qb->createNamedParameter(array_keys($at), IQueryBuilder::PARAM_INT_ARRAY)))
           ->andWhere($qb->expr()->notIn('f.field_type', $qb->createNamedParameter(FileReferenceService::FILELINK_TYPES, IQueryBuilder::PARAM_STR_ARRAY)));
        $result = $qb->executeQuery();
        while ($row = $result->fetch()) {
            $i = $at[(int)$row['file_id']];
            if ((int)$row['groupfolder_id'] === $files[$i]['groupfolder_id'] && (string)$row['field_value'] !== '') {
                $files[$i]['metadata'][(string)$row['field_name']] = (string)$row['field_value'];
            }
        }
        $result->closeCursor();
        return $files;
    }

    /** @param array<int, VisibleFolder> $byId */
    private function nothingHidden(array $byId): bool {
        foreach ($byId as $folder) {
            if ($folder->scope === null || !$folder->scope->isEverything()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a field counts in a team folder: it is assigned there. From
     * FieldService's cache, read once per field per search.
     *
     * @return callable(int, int): bool
     */
    private function assigned(): callable {
        $folders = [];
        return function (int $groupfolderId, int $fieldId) use (&$folders): bool {
            $folders[$fieldId] ??= $this->fields->foldersOfField($fieldId);
            return isset($folders[$fieldId][$groupfolderId]);
        };
    }

    /**
     * @param list<VisibleFolder> $folders
     * @return array<int, VisibleFolder>
     */
    private function byId(array $folders): array {
        $byId = [];
        foreach ($folders as $folder) {
            $byId[$folder->id] = $folder;
        }
        return $byId;
    }

    /**
     * Split a (possibly multiselect) value on the ';#' separator the app uses
     * to join multiple options. Returns a single-element array for plain values.
     *
     * @return string[]
     */
    private function splitMultiselectTokens(string $value): array {
        if (strpos($value, ';#') === false) {
            return [$value];
        }
        return array_values(array_filter(
            array_map('trim', explode(';#', $value)),
            static fn(string $t): bool => $t !== ''
        ));
    }

    /**
     * SQL expression that wraps m.field_value in leading/trailing ';#' so a
     * single delimiter-bounded LIKE ('%;#token;#%') matches a token anywhere in
     * the stored set — including the first and last positions, which a bare
     * "%;#token;#%" against the unwrapped value would miss. Uses the DB's
     * concat function for portability across MySQL/Postgres/SQLite.
     */
    private function delimitedFieldValueExpr(IQueryBuilder $qb) {
        // Returns an IQueryFunction (not a plain string); like() accepts it and
        // it renders to CONCAT(';#', m.field_value, ';#') per dialect.
        return $qb->func()->concat(
            $qb->createNamedParameter(';#'),
            $qb->func()->concat('m.field_value', $qb->createNamedParameter(';#'))
        );
    }
}
