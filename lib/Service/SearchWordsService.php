<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCA\MetaVox\BackgroundJobs\BuildSearchWords;
use OCP\BackgroundJob\IJobList;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;

/**
 * The search index of metadata values: one row per word, field, file and team
 * folder for free-text search, and one per whole value (each item of a list)
 * for the field filter. A search looks words up by their first letters and
 * values by their key, so it costs what it finds, not what the team folder
 * holds.
 */
class SearchWordsService {

    public const TABLE = 'metavox_search_words';

    /** In word_start, a row of a whole value: no word starts with it. */
    public const VALUE_MARK = '=';
    /** While the fill job builds the index from what is stored: that fill's generation. */
    private const FILLING = 'search_words_filling';

    /** Found through field search and the filters instead. */
    public const SKIPPED_TYPES = ['date', 'number', 'checkbox', 'filelink'];

    /** Looked up exactly: PostgreSQL can't use an index for LIKE 'abc%'. */
    public const START_LENGTH = 3;
    public const MAX_WORD_LENGTH = 64;
    public const MAX_WORDS_PER_VALUE = 100;
    public const MAX_SEARCH_WORDS = 5;
    /** Rows a search reads per word at most. */
    public const CANDIDATES = 2000;

    private const INSERT_ROW_CHUNK = 500;
    private const QUERY_IN_CHUNK = 500;

    /** @var array<string, array{id: int, type: string}>|null by field name */
    private ?array $fields = null;
    /** Whether the fields were read again for a name not known. */
    private bool $fieldsReloaded = false;

    public function __construct(
        private readonly IDBConnection $db,
        private readonly IJobList $jobList,
        private readonly IAppConfig $appConfig,
    ) {
    }

    /**
     * Lower case, letters and digits only, each word once.
     *
     * @return list<string>
     */
    public static function split(string $value): array {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $unique = [];
        foreach ($words as $word) {
            $unique[mb_substr($word, 0, self::MAX_WORD_LENGTH)] = true;
            if (\count($unique) >= self::MAX_WORDS_PER_VALUE) {
                break;
            }
        }
        // Digit-only words come back as int keys.
        return array_map('strval', array_keys($unique));
    }

    public static function start(string $word): string {
        return mb_substr($word, 0, self::START_LENGTH);
    }

    /**
     * The values a filter finds a file by: each item of a list, other values
     * whole. File links are ids, nothing to filter on.
     *
     * @return list<string>
     */
    public static function valueItems(string $fieldType, string $value): array {
        if (\in_array($fieldType, FileReferenceService::FILELINK_TYPES, true)) {
            return [];
        }
        if (!FieldService::holdsList($fieldType)) {
            return trim($value) === '' ? [] : [$value];
        }
        return array_values(array_unique(array_filter(array_map('trim', explode(';#', $value)), fn (string $item) => $item !== '')));
    }

    /** The key of a whole value in the index: per field, any length. */
    public static function valueKey(int $fieldId, string $value): string {
        return md5($fieldId . ':' . $value);
    }

    /**
     * Files whose value of one field holds every word of the term, at the
     * start of a word. Newest first.
     *
     * Bounded at any size: each word reads at most CANDIDATES + 1 rows. The
     * rarest word gives the candidates and the others are checked against
     * them. When every word is that common, the hits come from the rarest
     * word's CANDIDATES rows, and 'cut' says that files may be missing.
     *
     * @param int[] $groupfolderIds
     * @param ?callable(int, int): bool $assigned whether a field (second) counts in a team folder (first)
     * @return array{hits: list<array{file_id: int, groupfolder_id: int}>, cut: bool}
     */
    public function search(string $term, array $groupfolderIds, int $limit, ?callable $assigned = null): array {
        $words = \array_slice(self::split($term), 0, self::MAX_SEARCH_WORDS);
        if ($words === [] || $groupfolderIds === []) {
            return ['hits' => [], 'cut' => false];
        }
        $folders = array_values(array_unique(array_map('intval', $groupfolderIds)));

        $found = [];
        foreach ($words as $word) {
            [$rows, $cut] = $this->matches($word, $folders, null, $assigned);
            if ($rows === []) {
                return ['hits' => [], 'cut' => $cut];
            }
            $found[] = [$word, $rows, $cut ? PHP_INT_MAX : \count($rows)];
        }
        usort($found, static fn(array $a, array $b): int => $a[2] <=> $b[2]);
        // Even the rarest word was cut off: the others are checked against part of it.
        $cut = $found[0][2] === PHP_INT_MAX;

        [, $candidates] = array_shift($found);
        foreach ($found as [$word, $rows, $count]) {
            if ($count === PHP_INT_MAX) {
                // Cut off: look this word up for the candidates only.
                $files = array_values(array_unique(array_map(static fn(array $c): int => $c[0], $candidates)));
                [$rows] = $this->matches($word, $folders, $files, $assigned);
            }
            $candidates = array_intersect_key($candidates, $rows);
            if ($candidates === []) {
                return ['hits' => [], 'cut' => $cut];
            }
        }

        $newest = [];
        foreach ($candidates as [$fileId, $groupfolderId]) {
            $newest[$fileId] = $groupfolderId;
        }
        krsort($newest);
        $hits = [];
        foreach (\array_slice($newest, 0, $limit, true) as $fileId => $groupfolderId) {
            $hits[] = ['file_id' => (int)$fileId, 'groupfolder_id' => $groupfolderId];
        }
        return ['hits' => $hits, 'cut' => $cut];
    }

    /**
     * Where a word occurs, by team folder, file and field. Without files: the
     * newest first, read backwards along the primary key so the database
     * stops at CANDIDATES + 1; with files: only those.
     *
     * @param int[] $folders
     * @param int[]|null $fileIds
     * @param ?callable(int, int): bool $assigned see search()
     * @return array{0: array<string, array{0: int, 1: int}>, 1: bool} the matches, and whether they were cut off
     */
    private function matches(string $word, array $folders, ?array $fileIds = null, ?callable $assigned = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('groupfolder_id', 'file_id', 'field_id')
           ->from(self::TABLE)
           ->where($qb->expr()->in('groupfolder_id', $qb->createNamedParameter($folders, IQueryBuilder::PARAM_INT_ARRAY)))
           ->andWhere($qb->expr()->eq('word_start', $qb->createNamedParameter(self::start($word))))
           ->andWhere($qb->expr()->like('word', $qb->createNamedParameter($this->db->escapeLikeParameter($word) . '%')));
        if ($fileIds === null) {
            // No word_start: it is fixed by the WHERE anyway, and its literal
            // has the connection's collation, not the column's. MariaDB then
            // can't read the order from the key and sorts every match first.
            $qb->orderBy('groupfolder_id', 'DESC')
               ->addOrderBy('word', 'DESC')
               ->addOrderBy('file_id', 'DESC')
               ->setMaxResults(self::CANDIDATES + 1);
        } else {
            $qb->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)));
        }

        $result = $qb->executeQuery();
        $rows = [];
        $read = 0;
        while ($row = $result->fetch()) {
            $read++;
            $groupfolderId = (int)$row['groupfolder_id'];
            $fileId = (int)$row['file_id'];
            if ($fileIds === null && $read > self::CANDIDATES) {
                break;
            }
            $fieldId = (int)$row['field_id'];
            // A field taken off the team folder keeps its words, unseen.
            if ($assigned !== null && !$assigned($groupfolderId, $fieldId)) {
                continue;
            }
            $rows[$groupfolderId . ':' . $fileId . ':' . $fieldId] = [$fileId, $groupfolderId];
        }
        $result->closeCursor();
        return [$rows, $fileIds === null && $read > self::CANDIDATES];
    }

    /**
     * Values were saved; the words of each file and field follow its value.
     *
     * @param array<int, array<int, string>> $values field id => [file id => value]
     */
    public function replaceValues(int $groupfolderId, array $values): void {
        $rows = [];
        foreach ($values as $fieldId => $byFile) {
            foreach (array_chunk($this->ids(array_keys($byFile)), self::QUERY_IN_CHUNK) as $chunk) {
                $this->deleteWords($chunk, $groupfolderId, [(int)$fieldId]);
            }
            $field = $this->fieldById((int)$fieldId);
            if ($field === null) {
                continue;
            }
            foreach ($byFile as $fileId => $value) {
                array_push($rows, ...self::rows($groupfolderId, $field, (int)$fileId, (string)$value));
            }
        }
        $this->insertRows($rows);
    }

    /**
     * Build the words of these files again from their stored values, of all
     * fields or only of the named ones.
     *
     * @param int[] $fileIds
     * @param string[]|null $fieldNames
     */
    public function reindexFiles(array $fileIds, ?array $fieldNames = null): void {
        $fieldIds = null;
        if ($fieldNames !== null) {
            $fieldIds = [];
            foreach ($fieldNames as $name) {
                if (isset($this->fields()[$name])) {
                    $fieldIds[] = $this->fields()[$name]['id'];
                }
            }
            if ($fieldIds === []) {
                return;
            }
        }

        foreach (array_chunk($this->ids($fileIds), self::QUERY_IN_CHUNK) as $chunk) {
            $this->deleteWords($chunk, null, $fieldIds);
            $this->insertStored($chunk, $fieldNames);
        }
    }

    /**
     * The words of every file in (after, upTo] from what is stored; words of
     * files in that range without metadata go. The fill job walks the file
     * ids with this, so it never deletes much at once.
     *
     * @param int[] $fileIds the files in the range that have metadata
     */
    public function reindexRange(int $after, ?int $upTo, array $fileIds): void {
        // The fill runs for minutes: fields made meanwhile count too.
        $this->fields = null;
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
           ->where($qb->expr()->gt('file_id', $qb->createNamedParameter($after, IQueryBuilder::PARAM_INT)));
        if ($upTo !== null) {
            $qb->andWhere($qb->expr()->lte('file_id', $qb->createNamedParameter($upTo, IQueryBuilder::PARAM_INT)));
        }
        $qb->executeStatement();

        foreach (array_chunk($this->ids($fileIds), self::QUERY_IN_CHUNK) as $chunk) {
            $this->insertStored($chunk, null);
        }
    }

    /**
     * @param int[] $fileIds at most QUERY_IN_CHUNK
     * @param string[]|null $fieldNames
     */
    private function insertStored(array $fileIds, ?array $fieldNames): void {
        $qb = $this->db->getQueryBuilder();
        $qb->select('file_id', 'groupfolder_id', 'field_name', 'field_value')
           ->from('metavox_file_gf_meta')
           ->where($qb->expr()->in('file_id', $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)));
        if ($fieldNames !== null) {
            $qb->andWhere($qb->expr()->in('field_name', $qb->createNamedParameter(array_values($fieldNames), IQueryBuilder::PARAM_STR_ARRAY)));
        }
        $result = $qb->executeQuery();
        $rows = [];
        while ($row = $result->fetch()) {
            $field = $this->fieldByName((string)$row['field_name']);
            if ($field === null || $row['field_value'] === null) {
                continue;
            }
            array_push($rows, ...self::rows((int)$row['groupfolder_id'], $field, (int)$row['file_id'], (string)$row['field_value']));
        }
        $result->closeCursor();
        $this->insertRows($rows);
    }

    /**
     * @param int[] $fileIds
     */
    public function removeFiles(array $fileIds, ?int $groupfolderId = null): void {
        foreach (array_chunk($this->ids($fileIds), self::QUERY_IN_CHUNK) as $chunk) {
            $this->deleteWords($chunk, $groupfolderId, null);
        }
    }

    /**
     * Call before the field's values are deleted: they say which files have
     * words of it, and those are deleted through the file index.
     */
    public function removeField(int $fieldId, string $fieldName): void {
        $after = 0;
        do {
            $qb = $this->db->getQueryBuilder();
            $qb->select('file_id')
               ->from('metavox_file_gf_meta')
               ->where($qb->expr()->eq('field_name', $qb->createNamedParameter($fieldName)))
               ->andWhere($qb->expr()->gt('file_id', $qb->createNamedParameter($after, IQueryBuilder::PARAM_INT)))
               ->orderBy('file_id', 'ASC')
               ->setMaxResults(self::QUERY_IN_CHUNK);
            $result = $qb->executeQuery();
            $fileIds = [];
            while (($id = $result->fetchOne()) !== false) {
                $fileIds[] = (int)$id;
            }
            $result->closeCursor();

            if ($fileIds !== []) {
                $this->deleteWords($this->ids($fileIds), null, [$fieldId]);
                $after = max($fileIds);
            }
        } while (\count($fileIds) === self::QUERY_IN_CHUNK);
    }

    /**
     * Build all words again in the background, as after the update and a
     * backup restore; search keeps working meanwhile. A fill that is still
     * running starts over.
     */
    public function rebuildAll(): void {
        $this->jobList->remove(BuildSearchWords::class);

        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->createFunction('COUNT(DISTINCT file_id)'), 'files')
           ->from('metavox_file_gf_meta');
        $result = $qb->executeQuery();
        $total = (int)$result->fetchOne();
        $result->closeCursor();

        // Also with nothing left: the old words must go. Values saved from now on
        // are indexed right away, so with nothing stored the index is complete.
        // A fill still running from before sees another generation and stops.
        $generation = bin2hex(random_bytes(6));
        $this->appConfig->deleteKey('metavox', self::FILLING);
        if ($total > 0) {
            $this->appConfig->setValueString('metavox', self::FILLING, $generation);
        }
        $this->jobList->add(BuildSearchWords::class, ['after' => 0, 'done' => 0, 'total' => $total, 'generation' => $generation]);
    }

    /** Whether a fill of this generation is still the one to finish (null: from before generations). */
    public function isCurrentFill(?string $generation): bool {
        $current = $this->filling();
        return $generation === null || $current === '' || $current === $generation;
    }

    /** The fill of this generation is done: the index holds every stored value. */
    public function fillDone(?string $generation): void {
        if ($generation === null || $this->filling() === $generation) {
            $this->appConfig->deleteKey('metavox', self::FILLING);
        }
    }

    /** Whether the index holds every stored value, so the field filter can use it. */
    public function isComplete(): bool {
        return $this->filling() === '';
    }

    private function filling(): string {
        return $this->appConfig->getValueString('metavox', self::FILLING);
    }

    /**
     * How far the fill job is, while it runs.
     *
     * @return array{done: int, total: int}|null
     */
    public function fillProgress(): ?array {
        foreach ($this->jobList->getJobsIterator(BuildSearchWords::class, 1, 0) as $job) {
            $argument = $job->getArgument();
            $done = (int)($argument['done'] ?? 0);
            return ['done' => $done, 'total' => max($done, (int)($argument['total'] ?? 0))];
        }
        return null;
    }

    /**
     * The next files with metadata, in id order.
     *
     * @return list<int>
     */
    public function filesAfter(int $fileId, int $limit): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('file_id')
           ->from('metavox_file_gf_meta')
           ->where($qb->expr()->gt('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
           ->orderBy('file_id', 'ASC')
           ->setMaxResults($limit);
        $result = $qb->executeQuery();
        $ids = [];
        while (($id = $result->fetchOne()) !== false) {
            $ids[] = (int)$id;
        }
        $result->closeCursor();
        return $ids;
    }

    public static function isSearched(string $fieldType): bool {
        return !\in_array($fieldType, self::SKIPPED_TYPES, true);
    }

    /**
     * The index rows of one value: its words, and the value itself (each item
     * of a list) under VALUE_MARK.
     *
     * @param array{id: int, type: string} $field
     * @return list<array{0: int, 1: int, 2: int, 3: string, 4: string}> groupfolder, field, file, word or key, start
     */
    private static function rows(int $groupfolderId, array $field, int $fileId, string $value): array {
        $rows = [];
        if (self::isSearched($field['type'])) {
            foreach (self::split($value) as $word) {
                $rows[] = [$groupfolderId, $field['id'], $fileId, $word, self::start($word)];
            }
        }
        foreach (self::valueItems($field['type'], $value) as $item) {
            $rows[] = [$groupfolderId, $field['id'], $fileId, self::valueKey($field['id'], $item), self::VALUE_MARK];
        }
        return $rows;
    }

    /**
     * Multi-row insert that skips words already there: the fill job and a
     * save can write the same word at once.
     */
    public function buildInsertSql(int $rowCount, bool $isMysql): string {
        $placeholders = implode(', ', array_fill(0, $rowCount, '(?, ?, ?, ?, ?)'));
        $head = 'INSERT INTO *PREFIX*' . self::TABLE . ' (groupfolder_id, field_id, file_id, word, word_start) VALUES ' . $placeholders;
        return $isMysql
            ? $head . ' ON DUPLICATE KEY UPDATE file_id = file_id'
            : $head . ' ON CONFLICT DO NOTHING';
    }

    /**
     * @param list<array{0: int, 1: int, 2: int, 3: string, 4: string}> $rows groupfolder, field, file, word or key, start
     */
    private function insertRows(array $rows): void {
        $isMysql = $this->db->getDatabaseProvider() === IDBConnection::PLATFORM_MYSQL;
        foreach (array_chunk($rows, self::INSERT_ROW_CHUNK) as $chunk) {
            $params = [];
            foreach ($chunk as $row) {
                array_push($params, ...$row);
            }
            $this->db->executeStatement($this->buildInsertSql(\count($chunk), $isMysql), $params);
        }
    }

    /**
     * @param int[] $fileIds at most QUERY_IN_CHUNK
     * @param int[]|null $fieldIds
     */
    private function deleteWords(array $fileIds, ?int $groupfolderId, ?array $fieldIds): void {
        if ($fileIds === []) {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
           ->where($qb->expr()->in('file_id', $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)));
        if ($fieldIds !== null) {
            $qb->andWhere($qb->expr()->in('field_id', $qb->createNamedParameter($fieldIds, IQueryBuilder::PARAM_INT_ARRAY)));
        }
        if ($groupfolderId !== null) {
            $qb->andWhere($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)));
        }
        $qb->executeStatement();
    }

    /** @return array{id: int, type: string}|null */
    private function fieldByName(string $name): ?array {
        // A field made earlier in this request: read the fields again, once.
        if (!isset($this->fields()[$name]) && !$this->fieldsReloaded) {
            $this->fields = null;
            $this->fieldsReloaded = true;
        }
        return $this->fields()[$name] ?? null;
    }

    /** @return array{id: int, type: string}|null */
    private function fieldById(int $fieldId): ?array {
        foreach ([false, true] as $reload) {
            if ($reload) {
                // A field made earlier in this request.
                $this->fields = null;
            }
            foreach ($this->fields() as $field) {
                if ($field['id'] === $fieldId) {
                    return $field;
                }
            }
        }
        return null;
    }

    /** @return array<string, array{id: int, type: string}> */
    private function fields(): array {
        if ($this->fields === null) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'field_name', 'field_type')->from('metavox_gf_fields');
            $result = $qb->executeQuery();
            $this->fields = [];
            while ($row = $result->fetch()) {
                $this->fields[(string)$row['field_name']] = ['id' => (int)$row['id'], 'type' => (string)$row['field_type']];
            }
            $result->closeCursor();
        }
        return $this->fields;
    }

    /**
     * @param array<int|string> $ids
     * @return list<int>
     */
    private function ids(array $ids): array {
        return array_values(array_unique(array_map('intval', $ids)));
    }
}
