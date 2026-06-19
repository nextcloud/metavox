<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Resolution and backlink logic for the "File Link" field type.
 *
 * A `filelink` value holds one OR MORE file references. Each reference is
 * "<fileid>:<path>"; multiple references are joined with the app's ';#'
 * delimiter (e.g. "12:/a.pdf;#34:/b.docx"). A single reference is just a
 * one-element list, so old single-value data keeps working unchanged. The
 * fileid is the canonical reference — it survives renames and moves; the
 * cached path is only there so old code keeps showing a name and so search has
 * text to match. See issue #73.
 *
 * Three jobs live here:
 *  - resolve a picked path to a fileid on save (so we store the stable id),
 *  - resolve fileid(s) back to the CURRENT name/path for display (live), and
 *  - find which files reference a given file ("Referenced by" backlinks).
 *
 * Path<->fileid for groupfolder files is the tricky part: a groupfolder's
 * files live on a dedicated storage whose id ends in "/__groupfolders/<id>/",
 * and filecache.path is just "files/..." — the "__groupfolders" segment is in
 * the STORAGE id, not the path. We reuse the exact storage-suffix match proven
 * in {@see DefaultsService}.
 *
 * The backlink query builder (buildBacklinkSql) is separated from execution,
 * and the value parsing/matching helpers are pure static functions, so the
 * correctness-critical logic can be unit-tested without a database.
 */
class FileReferenceService {

    /** The delimiter the app uses to join multiple values. */
    public const MULTI_DELIM = ';#';

    /** Field types whose values are "<fileid>:<path>" references. */
    public const FILELINK_TYPES = ['filelink'];

    /** @var array<int, int[]> request-scoped cache of gfId => storage numeric ids */
    private array $storageIdsCache = [];

    public function __construct(
        private readonly IDBConnection $db,
        private readonly IRootFolder $rootFolder,
        private readonly LoggerInterface $logger,
    ) {
    }

    /* ---------------------------------------------------------------------
     * Value parsing / formatting (pure)
     * ------------------------------------------------------------------- */

    /**
     * Parse a single "<fileid>:<path>" token. Splits on the FIRST colon only;
     * the prefix counts as a fileid only when it is all digits, otherwise the
     * whole token is treated as a legacy bare path (fileId null).
     *
     * @return array{fileId: ?int, path: string}
     */
    public static function parseToken(string $token): array {
        $token = trim($token);
        $colon = strpos($token, ':');
        if ($colon !== false) {
            $prefix = substr($token, 0, $colon);
            if ($prefix !== '' && ctype_digit($prefix)) {
                return ['fileId' => (int)$prefix, 'path' => substr($token, $colon + 1)];
            }
        }
        return ['fileId' => null, 'path' => $token];
    }

    /**
     * Parse a stored value (single token or ';#'-joined multi) into tokens.
     *
     * @return array<int, array{fileId: ?int, path: string}>
     */
    public static function parseValue(string $value): array {
        if ($value === '') {
            return [];
        }
        $raw = strpos($value, self::MULTI_DELIM) === false
            ? [$value]
            : explode(self::MULTI_DELIM, $value);

        $tokens = [];
        foreach ($raw as $part) {
            if (trim($part) === '') {
                continue;
            }
            $tokens[] = self::parseToken($part);
        }
        return $tokens;
    }

    /** Format one reference as "<fileid>:<path>". */
    public static function formatToken(int $fileId, string $path): string {
        return $fileId . ':' . $path;
    }

    /** True when a value's first token already carries a numeric fileid. */
    public static function isMigrated(string $value): bool {
        return (bool)preg_match('/^\d+:/', ltrim($value));
    }

    /**
     * True when $value references $targetFileId as one of its tokens. This is
     * the exact, PHP-side check that confirms a candidate row returned by the
     * (necessarily fuzzy) backlink LIKE really points at the target — so e.g.
     * "48" never counts as a match for a value containing "482:".
     */
    public static function valueReferencesFileId(string $value, int $targetFileId): bool {
        foreach (self::parseValue($value) as $token) {
            if ($token['fileId'] === $targetFileId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Drop duplicate references from a token list (first occurrence wins).
     * The same file linked twice makes no sense. Dedup is keyed on the fileid;
     * tokens without a resolved id are deduped on their path text so unresolved
     * bare paths don't pile up either.
     *
     * @param array<int, array{fileId: ?int, path: string}> $tokens
     * @return array<int, array{fileId: ?int, path: string}>
     */
    public static function dedupeTokens(array $tokens): array {
        $out = [];
        $seenIds = [];
        $seenPaths = [];
        foreach ($tokens as $token) {
            if ($token['fileId'] !== null) {
                if (isset($seenIds[$token['fileId']])) {
                    continue;
                }
                $seenIds[$token['fileId']] = true;
            } else {
                if (isset($seenPaths[$token['path']])) {
                    continue;
                }
                $seenPaths[$token['path']] = true;
            }
            $out[] = $token;
        }
        return $out;
    }

    /** Format a token list back to a stored value ("id:path" joined with ';#'). */
    public static function joinTokens(array $tokens): string {
        return implode(self::MULTI_DELIM, array_map(
            static fn(array $t): string => $t['fileId'] !== null
                ? self::formatToken($t['fileId'], $t['path'])
                : $t['path'],
            $tokens
        ));
    }

    /* ---------------------------------------------------------------------
     * Resolution
     * ------------------------------------------------------------------- */

    /**
     * Resolve a user-relative path to a fileid, in the context of a user.
     *
     * The Nextcloud file picker returns a path relative to the user's home
     * (e.g. "/Projects/spec.pdf" where Projects is a mounted groupfolder).
     * getUserFolder()->get() walks the real mount tree, so this works for
     * groupfolder and personal files alike without any storage arithmetic.
     *
     * @return int|null fileid, or null if the path can't be resolved
     */
    public function resolvePathToFileId(string $path, string $userId): ?int {
        if ($path === '') {
            return null;
        }
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $node = $userFolder->get($path);
            return $node->getId();
        } catch (\Throwable $e) {
            $this->logger->debug('MetaVox: could not resolve file-link path to id', [
                'path' => $path, 'user' => $userId, 'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * Resolve a fileid to its CURRENT name + user-relative path for display.
     * Reflects renames/moves live. Returns null when the file no longer exists
     * (stale reference) or the user can't see it.
     *
     * @return array{fileId: int, name: string, path: string}|null
     */
    public function resolveFileIdToInfo(int $fileId, string $userId): ?array {
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $nodes = $userFolder->getById($fileId);
            $node = $nodes[0] ?? null;
            if ($node === null) {
                return null;
            }
            return [
                'fileId' => $fileId,
                'name' => $node->getName(),
                'path' => $userFolder->getRelativePath($node->getPath()) ?? $node->getName(),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Batch-resolve several fileids to name/path maps for one user. One pass
     * over getById so the grid prefetch and sidebar load avoid N round-trips.
     *
     * @param int[] $fileIds
     * @return array<int, array{fileId: int, name: string, path: string}> keyed by fileId
     */
    public function resolveMany(array $fileIds, string $userId): array {
        $fileIds = array_values(array_unique(array_filter($fileIds, static fn($id) => $id > 0)));
        if (empty($fileIds)) {
            return [];
        }
        $out = [];
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
        } catch (\Throwable $e) {
            return [];
        }
        foreach ($fileIds as $id) {
            try {
                $nodes = $userFolder->getById($id);
                $node = $nodes[0] ?? null;
                if ($node === null) {
                    continue;
                }
                $out[$id] = [
                    'fileId' => $id,
                    'name' => $node->getName(),
                    'path' => $userFolder->getRelativePath($node->getPath()) ?? $node->getName(),
                ];
            } catch (\Throwable $e) {
                // skip unresolvable id
            }
        }
        return $out;
    }

    /**
     * Resolve a groupfolder-relative path to a fileid WITHOUT a user context
     * (used by the migration). Matches the groupfolder's storage by suffix and
     * the file by its "files/..." path, mirroring DefaultsService.
     *
     * @param string $relPath path relative to the groupfolder root, e.g. "Docs/a.pdf"
     */
    public function resolvePathToFileIdInGroupfolder(string $relPath, int $groupfolderId): ?int {
        $storageIds = $this->getGroupfolderStorageIds($groupfolderId);
        if (empty($storageIds)) {
            return null;
        }
        $cachePath = 'files/' . ltrim($relPath, '/');

        $qb = $this->db->getQueryBuilder();
        $qb->select('fileid')
           ->from('filecache')
           ->where($qb->expr()->in('storage', $qb->createNamedParameter($storageIds, IQueryBuilder::PARAM_INT_ARRAY)))
           ->andWhere($qb->expr()->eq('path', $qb->createNamedParameter($cachePath)))
           ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return $row ? (int)$row['fileid'] : null;
    }

    /* ---------------------------------------------------------------------
     * Backlinks
     * ------------------------------------------------------------------- */

    /**
     * Find every file whose file-link metadata references $targetFileId.
     *
     * Matching is done with a delimiter-and-colon-bounded LIKE so "48" can't
     * match "482:" — the trailing ':' terminates the id — and then every
     * candidate row is re-parsed in PHP to confirm an exact fileid match. The
     * referring files are resolved to their current name/path for $userId.
     *
     * @return array<int, array{
     *     referringFileId: int, referringFileName: string,
     *     referringFilePath: string, groupfolderId: int,
     *     fieldName: string, fieldLabel: string
     * }>
     */
    public function getBacklinks(int $targetFileId, string $userId): array {
        if ($targetFileId <= 0) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $this->buildBacklinkSql($qb, $targetFileId);

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        // PHP-side exact verification: confirm the target id is really one of
        // the value's tokens (belt-and-suspenders over the LIKE).
        $confirmed = [];
        $referringIds = [];
        foreach ($rows as $row) {
            $value = (string)($row['field_value'] ?? '');
            if (self::valueReferencesFileId($value, $targetFileId)) {
                $referringId = (int)$row['file_id'];
                $confirmed[] = [
                    'referringFileId' => $referringId,
                    'groupfolderId' => (int)$row['groupfolder_id'],
                    'fieldName' => (string)$row['field_name'],
                    'fieldLabel' => (string)($row['field_label'] ?? $row['field_name']),
                ];
                $referringIds[] = $referringId;
            }
        }

        if (empty($confirmed)) {
            return [];
        }

        // Resolve referring files to current name/path; drop those the user
        // can't see (cross-groupfolder references they have no access to).
        $resolved = $this->resolveMany($referringIds, $userId);
        $out = [];
        foreach ($confirmed as $entry) {
            $info = $resolved[$entry['referringFileId']] ?? null;
            if ($info === null) {
                continue;
            }
            $out[] = [
                'referringFileId' => $entry['referringFileId'],
                'referringFileName' => $info['name'],
                'referringFilePath' => $info['path'],
                'groupfolderId' => $entry['groupfolderId'],
                'fieldName' => $entry['fieldName'],
                'fieldLabel' => $entry['fieldLabel'],
            ];
        }
        return $out;
    }

    /**
     * Build the backlink candidate query (pure — no execution). Selects value
     * rows of file-link-typed fields whose value contains the target fileid as
     * a ':'-terminated token, joined to the field definition for the label.
     *
     * Exposed for unit testing the SQL shape across DB dialects.
     */
    public function buildBacklinkSql(IQueryBuilder $qb, int $targetFileId): IQueryBuilder {
        $idPattern = $this->db->escapeLikeParameter((string)$targetFileId);

        $qb->select('m.file_id', 'm.groupfolder_id', 'm.field_name', 'm.field_value', 'f.field_label')
           ->from('metavox_file_gf_meta', 'm')
           ->innerJoin('m', 'metavox_gf_fields', 'f', $qb->expr()->eq('m.field_name', 'f.field_name'))
           ->where($qb->expr()->in('f.field_type', $qb->createNamedParameter(self::FILELINK_TYPES, IQueryBuilder::PARAM_STR_ARRAY)))
           ->andWhere($qb->expr()->orX(
               // first / only token: "<id>:..."
               $qb->expr()->like('m.field_value', $qb->createNamedParameter($idPattern . ':%')),
               // any later token: "...;#<id>:..."
               $qb->expr()->like('m.field_value', $qb->createNamedParameter('%' . self::MULTI_DELIM . $idPattern . ':%'))
           ));
        return $qb;
    }

    /* ---------------------------------------------------------------------
     * Internals
     * ------------------------------------------------------------------- */

    /**
     * Resolve the storage numeric_id(s) backing a groupfolder. The storage id
     * ends in "/__groupfolders/<gfId>/"; the data-dir prefix varies per install
     * so we match on the suffix. Cached per request. Mirrors DefaultsService.
     *
     * @return int[]
     */
    private function getGroupfolderStorageIds(int $groupfolderId): array {
        if (isset($this->storageIdsCache[$groupfolderId])) {
            return $this->storageIdsCache[$groupfolderId];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('numeric_id')
           ->from('storages')
           ->where($qb->expr()->like('id', $qb->createNamedParameter('%/__groupfolders/' . $groupfolderId . '/')));

        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $ids[] = (int)$row['numeric_id'];
        }
        $result->closeCursor();

        $this->storageIdsCache[$groupfolderId] = $ids;
        return $ids;
    }
}
