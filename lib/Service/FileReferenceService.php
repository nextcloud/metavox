<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolution and backlink logic for the "File Link" field type.
 *
 * A `filelink` value holds one OR MORE file references, joined with the app's
 * ';#' delimiter. We store each reference as "<fileid>:" (e.g. "12:;#34:").
 * Older values also carried a path after the colon, the path as the saving
 * user saw it; we no longer keep it, because it would show other users the
 * name of a file they may not see. Names come live from Nextcloud instead.
 * A token without an id is a path we could not resolve and stays as text.
 * See issue #73.
 *
 * Jobs that live here:
 *  - turn a saved value into its stored form (normalize),
 *  - resolve fileid(s) to the CURRENT name/path for display (live), and
 *  - find which files reference a given file ("Referenced by" backlinks).
 *
 * Where a team folder's files live in filecache comes from
 * {@see GroupfolderRootService}.
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

    /**
     * Hard cap on backlink candidate rows scanned/returned. Bounds both the DB
     * work and the PHP-side fetchAll/verify even if the folder scope is wide.
     */
    public const BACKLINK_SCAN_LIMIT = 500;

    public function __construct(
        private readonly IDBConnection $db,
        private readonly IRootFolder $rootFolder,
        private readonly LoggerInterface $logger,
        private readonly GroupfolderRootService $groupfolderRoots,
        private readonly IUserSession $userSession,
        // FileAccessService needs FieldService, which needs this: fetched when a link is checked.
        private readonly ContainerInterface $container,
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

    /** The same value without paths: every token that has an id becomes "<id>:". */
    public static function withoutPaths(string $value): string {
        return self::joinTokens(array_map(
            static fn(array $t): array => $t['fileId'] !== null ? ['fileId' => $t['fileId'], 'path' => ''] : $t,
            self::parseValue($value)
        ));
    }

    /* ---------------------------------------------------------------------
     * Stored form
     * ------------------------------------------------------------------- */

    /**
     * The form in which a file-link value is stored. A picked path becomes an
     * id (as the current user sees it), links outside this team folder,
     * duplicates and a link to the file itself are dropped, and no path is kept. A path we can't resolve is
     * kept as text: dropping it blanked people's fields (github#95).
     *
     * An id sent as it is must be a file the user may open there, ACL included:
     * one they could not have picked is left out like one outside the folder, so
     * leaving it out tells nothing about what is there. A link already in
     * $current stays, as someone who may open it put it there. A copy
     * ($asUser false) and a save without a user only look at the team folder.
     */
    public function normalize(string $value, int $groupfolderId, ?int $selfFileId = null, string $current = '', bool $asUser = true): string {
        $userId = $this->userSession->getUser()?->getUID();
        $tokens = [];
        $picked = [];
        foreach (self::parseValue($value) as $token) {
            if ($token['fileId'] === null && $userId !== null) {
                $token['fileId'] = $this->resolvePathToFileId($token['path'], $userId);
                // Found among the user's own files: theirs to link.
                if ($token['fileId'] !== null) {
                    $picked[$token['fileId']] = true;
                }
            }
            $tokens[] = $token['fileId'] !== null ? ['fileId' => $token['fileId'], 'path' => ''] : $token;
        }
        $tokens = self::dedupeTokens($tokens);

        $ids = array_values(array_filter(array_column($tokens, 'fileId'), static fn($id): bool => $id !== null));
        $allowed = $ids === [] ? [] : array_flip($this->filesInGroupfolder($ids, $groupfolderId));
        if ($asUser && $userId !== null && $allowed !== []) {
            $already = array_column(self::parseValue($current), 'fileId');
            $unchecked = array_values(array_filter(array_keys($allowed), static fn(int $id): bool => !isset($picked[$id]) && !in_array($id, $already, true)));
            if ($unchecked !== []) {
                $allowed = array_diff_key($allowed, array_flip(array_diff($unchecked, $this->linkableFiles($userId, $unchecked, $groupfolderId))));
            }
        }
        return self::joinTokens(array_values(array_filter(
            $tokens,
            // In the team folder, and not the file itself: a link to the file you
            // are looking at says nothing and reads as a duplicate.
            static fn(array $t): bool => $t['fileId'] === null || (isset($allowed[$t['fileId']]) && $t['fileId'] !== $selfFileId)
        )));
    }

    /**
     * The files of these the user may open in this team folder, by the rule for
     * its metadata: ACL included, admins all of them.
     *
     * @param int[] $fileIds
     * @return int[]
     */
    public function linkableFiles(string $userId, array $fileIds, int $groupfolderId): array {
        $fileAccess = $this->container->get(FileAccessService::class);
        $fileAccess->prime($userId, $fileIds);
        return array_values(array_filter($fileIds, function (int $fileId) use ($fileAccess, $userId, $groupfolderId): bool {
            $access = $fileAccess->getFileAccess($userId, $fileId);
            return $access->canRead() && $access->groupfolderId === $groupfolderId;
        }));
    }

    /**
     * Add `resolved` to every file-link field: the linked files as this user
     * sees them now, or a placeholder for one that is gone or hidden to them.
     *
     * @param array<int, array<string, mixed>> $metadata rows with field_type and value
     * @return array<int, array<string, mixed>>
     */
    public function withNames(array $metadata, string $userId): array {
        $fileIds = [];
        foreach ($metadata as $field) {
            if (!in_array($field['field_type'] ?? '', self::FILELINK_TYPES, true)) {
                continue;
            }
            foreach (self::parseValue((string)($field['value'] ?? '')) as $token) {
                if ($token['fileId'] !== null) {
                    $fileIds[] = $token['fileId'];
                }
            }
        }
        if (empty($fileIds)) {
            return $metadata;
        }
        $resolved = $this->resolveMany($fileIds, $userId);

        foreach ($metadata as &$field) {
            if (!in_array($field['field_type'] ?? '', self::FILELINK_TYPES, true)) {
                continue;
            }
            $items = [];
            foreach (self::parseValue((string)($field['value'] ?? '')) as $token) {
                $info = $token['fileId'] !== null ? ($resolved[$token['fileId']] ?? null) : null;
                $items[] = $info === null
                    ? ['fileId' => null, 'name' => null, 'path' => null, 'exists' => false]
                    : ['fileId' => $token['fileId'], 'name' => $info['name'], 'path' => $info['path'], 'exists' => true];
            }
            $field['resolved'] = $items;
        }
        unset($field);

        return $metadata;
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
            // warning, not debug: an unresolvable path means the value is stored
            // without a fileid, so it cannot follow renames or feed backlinks.
            // At debug level this stayed invisible while github#95 blanked
            // people's field values.
            $this->logger->warning('MetaVox: could not resolve file-link path to id', [
                'path' => $path, 'user' => $userId, 'exception' => $e,
            ]);
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
     * Resolve a groupfolder-relative path to a fileid without a user context
     * (used by the migration).
     *
     * @param string $relPath path relative to the groupfolder root, e.g. "Docs/a.pdf"
     */
    public function resolvePathToFileIdInGroupfolder(string $relPath, int $groupfolderId): ?int {
        foreach ($this->groupfolderRoots->getRoots($groupfolderId) as $root) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('fileid')
               ->from('filecache')
               ->where($qb->expr()->eq('storage', $qb->createNamedParameter($root['storage'], IQueryBuilder::PARAM_INT)))
               ->andWhere($qb->expr()->eq('path', $qb->createNamedParameter($root['path'] . '/' . ltrim($relPath, '/'))))
               ->setMaxResults(1);

            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();

            if ($row) {
                return (int)$row['fileid'];
            }
        }
        return null;
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
    public function getBacklinks(int $targetFileId, string $userId, ?int $groupfolderId = null): array {
        if ($targetFileId <= 0) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $this->buildBacklinkSql($qb, $targetFileId, $groupfolderId);

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
     *
     * When $groupfolderId is given, the query is scoped to that folder. This is
     * essential at scale: without it the value matching is a leading-wildcard
     * LIKE with no usable index → a full scan of metavox_file_gf_meta (100M+
     * rows). The groupfolder_id predicate engages the (groupfolder_id, ...)
     * composite index, and a result cap bounds the candidate set either way.
     */
    public function buildBacklinkSql(IQueryBuilder $qb, int $targetFileId, ?int $groupfolderId = null): IQueryBuilder {
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
           ))
           ->setMaxResults(self::BACKLINK_SCAN_LIMIT);

        if ($groupfolderId !== null) {
            $qb->andWhere($qb->expr()->eq('m.groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)));
        }
        return $qb;
    }

    /* ---------------------------------------------------------------------
     * Internals
     * ------------------------------------------------------------------- */

    /**
     * The ids that live inside the given team folder. A link target outside it
     * would point at a file other folder members can't see.
     *
     * @param int[] $fileIds
     * @return int[]
     */
    public function filesInGroupfolder(array $fileIds, int $groupfolderId): array {
        $fileIds = array_values(array_unique(array_filter($fileIds, static fn($id): bool => $id > 0)));
        $roots = $fileIds === [] ? [] : $this->groupfolderRoots->getRoots($groupfolderId);
        $found = [];
        foreach (array_chunk($fileIds, 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $inFolder = $this->groupfolderRoots->buildInRootsCondition($qb, $roots);
            if ($inFolder === null) {
                return [];
            }
            $qb->select('fileid')
               ->from('filecache')
               ->where($qb->expr()->in('fileid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
               ->andWhere($inFolder);
            $result = $qb->executeQuery();
            while (($id = $result->fetchOne()) !== false) {
                $found[] = (int)$id;
            }
            $result->closeCursor();
        }
        return $found;
    }
}
