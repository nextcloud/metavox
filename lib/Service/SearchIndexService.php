<?php
declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

class SearchIndexService {

    /** Files per upsert chunk in the bulk index path. */
    private const INDEX_UPSERT_CHUNK = 250;

    /** File ids per IN(...) clause when batch-fetching, to stay under param limits. */
    private const QUERY_IN_CHUNK = 500;

    private IDBConnection $db;
    private ICacheFactory $cacheFactory;
    private LoggerInterface $logger;

    public function __construct(IDBConnection $db, ICacheFactory $cacheFactory, LoggerInterface $logger) {
        $this->db = $db;
        $this->cacheFactory = $cacheFactory;
        $this->logger = $logger;
    }

    public function searchFilesByMetadata(string $searchTerm, string $userId): array {
        $cacheKey = "metavox_search_{$userId}_" . md5($searchTerm);
        $cache = $this->cacheFactory->createDistributed('metavox_search');
        
        // Try cache first
        $cached = $cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $results = $this->performMetadataSearch($searchTerm, $userId);
            
            // Cache results for 5 minutes
            $cache->set($cacheKey, $results, 300);
            
            return $results;
            
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: search failed', ['exception' => $e, 'searchTerm' => $searchTerm]);
            return [];
        }
    }

    /**
     * Find files where a specific metadata field has an exact value.
     *
     * Filters against the authoritative metavox_file_gf_meta table (not a JSON
     * substring on the search index), so "status:open" no longer also matches
     * "open-but-stale" and a value cannot collide across fields. When
     * $groupfolderId is given, results are scoped to that groupfolder — the
     * same field name can carry different values per folder, and this also
     * prevents leaking matches across folders the caller did not intend.
     *
     * Permission is still enforced per result by the provider
     * (verifyFileAccess); this scoping narrows the candidate set up front.
     *
     * @return array<array{id: int, name: string, path: string, metadata: array}>
     */
    public function searchByFieldValue(string $fieldName, string $fieldValue, string $userId, ?int $groupfolderId = null): array {
        // $userId is kept for signature stability; access control is enforced by
        // the caller (MetadataSearchProvider: permission gate + verifyFileAccess).
        unset($userId);
        try {
            $qb = $this->db->getQueryBuilder();
            // f.mtime is in the select list because Postgres requires ORDER BY
            // expressions to appear in SELECT when DISTINCT is used. (file_id is
            // already unique per (file, field) row here, so DISTINCT mainly
            // guards against duplicate index rows.)
            $qb->selectDistinct('m.file_id')
               ->addSelect('f.name', 'f.path', 'f.mtime')
               ->from('metavox_file_gf_meta', 'm')
               ->innerJoin('m', 'filecache', 'f', 'm.file_id = f.fileid')
               ->where($qb->expr()->eq('m.field_name', $qb->createNamedParameter($fieldName)))
               ->setMaxResults(100)
               ->orderBy('f.mtime', 'DESC');

            // A file matches each requested token if its stored value either
            // equals the token exactly (single-value field) OR contains it as a
            // ';#'-delimited element (multiselect field). Multiple tokens are
            // AND-ed: the file must satisfy every selected option.
            $tokens = $this->splitMultiselectTokens($fieldValue);
            foreach ($tokens as $token) {
                $qb->andWhere($qb->expr()->orX(
                    $qb->expr()->eq('m.field_value', $qb->createNamedParameter($token)),
                    $qb->expr()->like(
                        $this->delimitedFieldValueExpr($qb),
                        $qb->createNamedParameter(
                            '%;#' . $this->db->escapeLikeParameter($token) . ';#%'
                        )
                    )
                ));
            }

            if ($groupfolderId !== null) {
                $qb->andWhere($qb->expr()->eq('m.groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)));
            }

            $result = $qb->executeQuery();
            $files = [];
            while ($row = $result->fetch()) {
                $files[] = [
                    'id' => (int)$row['file_id'],
                    'name' => $row['name'],
                    'path' => $row['path'],
                    'metadata' => [$fieldName => $fieldValue],
                ];
            }
            $result->closeCursor();

            return $files;

        } catch (\Exception $e) {
            $this->logger->error('MetaVox: field search failed', ['exception' => $e, 'fieldName' => $fieldName]);
            return [];
        }
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

    private function performMetadataSearch(string $searchTerm, string $userId): array {
        $qb = $this->db->getQueryBuilder();
        
        // Use search index for performance
        if ($this->hasSearchIndex()) {
            return $this->searchFromIndex($searchTerm, $userId);
        }
        
        // Fallback to direct metadata search
        return $this->searchFromMetadata($searchTerm, $userId);
    }

private function searchFromIndex(string $searchTerm, string $userId): array {
    $qb = $this->db->getQueryBuilder();
    
    // Check if FULLTEXT index exists and database supports it reliably
    $useFulltext = false;
    if ($this->db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySqlPlatform) {
        try {
            // Test if FULLTEXT index works
            $testQb = $this->db->getQueryBuilder();
            $testQb->select('1')
                   ->from('metavox_search_index')
                   ->where('MATCH(search_content) AGAINST (:test IN BOOLEAN MODE)')
                   ->setParameter('test', 'test')
                   ->setMaxResults(1);
            $testQb->executeQuery()->closeCursor();
            $useFulltext = true;
        } catch (\Exception $e) {
            // FULLTEXT index not available, fall back to LIKE
            $this->logger->debug('MetaVox: FULLTEXT not available, using LIKE search', ['exception' => $e]);
        }
    }
    
    if ($useFulltext) {
        // MySQL with working FULLTEXT
        $qb->select('si.file_id', 'si.field_data', 'f.name', 'f.path')
           ->from('metavox_search_index', 'si')
           ->innerJoin('si', 'filecache', 'f', 'si.file_id = f.fileid')
           ->where('MATCH(si.search_content) AGAINST (:search IN BOOLEAN MODE)')
           ->setParameter('search', "+{$searchTerm}*")
           ->setMaxResults(50)
           ->orderBy('f.mtime', 'DESC');
    } else {
        // Fallback LIKE search for all databases
        $qb->select('si.file_id', 'si.field_data', 'f.name', 'f.path')
           ->from('metavox_search_index', 'si')
           ->innerJoin('si', 'filecache', 'f', 'si.file_id = f.fileid')
           ->where($qb->expr()->like('si.search_content', $qb->createNamedParameter('%' . strtolower($this->db->escapeLikeParameter($searchTerm)) . '%')))
           ->setMaxResults(50)
           ->orderBy('f.mtime', 'DESC');
    }

    $result = $qb->executeQuery();
    $files = [];
    
    while ($row = $result->fetch()) {
        $files[] = [
            'id' => (int)$row['file_id'],
            'name' => $row['name'],
            'path' => $row['path'],
            'metadata' => json_decode($row['field_data'] ?: '{}', true)
        ];
    }
    $result->closeCursor();

    return $files;
}

    private function searchFromMetadata(string $searchTerm, string $userId): array {
        // Only groupfolder file metadata supported now
        $qb = $this->db->getQueryBuilder();
        $qb->select('f.fileid', 'f.name', 'f.path', 'meta.field_name', 'meta.field_value as value')
           ->from('filecache', 'f')
           ->innerJoin('f', 'metavox_file_gf_meta', 'meta', 'f.fileid = meta.file_id')
           ->where($qb->expr()->like('meta.field_value', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($searchTerm) . '%')))
           ->andWhere($qb->expr()->in('f.storage', $qb->createParameter('storages')))
           ->setParameter('storages', $this->getUserStorageIds($userId), IQueryBuilder::PARAM_INT_ARRAY)
           ->setMaxResults(50)
           ->orderBy('f.mtime', 'DESC');

        $result = $qb->executeQuery();
        $files = [];

        while ($row = $result->fetch()) {
            $fileId = $row['fileid'];
            if (!isset($files[$fileId])) {
                $files[$fileId] = [
                    'id' => $fileId,
                    'name' => $row['name'],
                    'path' => $row['path'],
                    'metadata' => []
                ];
            }
            $files[$fileId]['metadata'][$row['field_name']] = $row['value'];
        }
        $result->closeCursor();

        return array_values($files);
    }

    private function getUserStorageIds(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('DISTINCT s.numeric_id')
           ->from('storages', 's')
           ->leftJoin('s', 'mounts', 'm', 's.numeric_id = m.storage_id')
           ->where($qb->expr()->orX(
               $qb->expr()->eq('s.id', $qb->createNamedParameter("home::{$userId}")),
               $qb->expr()->eq('m.user_id', $qb->createNamedParameter($userId))
           ));
        
        $result = $qb->executeQuery();
        $storageIds = [];
        while ($row = $result->fetch()) {
            $storageIds[] = (int)$row['numeric_id'];
        }
        $result->closeCursor();
        
        return $storageIds ?: [0];
    }

    private function hasSearchIndex(): bool {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(*)'))
               ->from('metavox_search_index')
               ->setMaxResults(1);
            $qb->executeQuery();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function updateFileIndex(int $fileId): void {
        try {
            // Get file metadata
            $metadata = $this->getFileMetadata($fileId);

            if (empty($metadata)) {
                $this->deleteFileFromIndex($fileId);
                return;
            }

            // Get file info
            $fileInfo = $this->getFileInfo($fileId);
            if (!$fileInfo) {
                return;
            }

            $payload = $this->buildIndexPayload($metadata);

            $this->upsertSearchIndex($fileId, $fileInfo['user_id'], $fileInfo['storage_id'],
                                   $payload['search_content'], $payload['field_data']);

        } catch (\Exception $e) {
            $this->logger->error('MetaVox: index update failed', ['exception' => $e, 'fileId' => $fileId]);
        }
    }

    /**
     * Build the search-index payload (search_content + field_data) for a file's
     * metadata. Single source of truth for index *content*, shared by the
     * per-file path (updateFileIndex) and the bulk path (bulkUpdateFileIndex)
     * so the two can never diverge in what they index. Pure — no I/O.
     *
     * @param array<array{field_name: string, value: mixed}> $metadata
     * @return array{search_content: string, field_data: string}
     */
    public function buildIndexPayload(array $metadata): array {
        $searchContent = [];
        $fieldData = [];

        foreach ($metadata as $field) {
            // Keep '0' / 'false' etc.: only skip null and empty string. Using a
            // plain truthiness test here would drop legitimate zero values.
            if ($field['value'] !== null && (string)$field['value'] !== '') {
                $searchContent[] = $field['value'];
                $fieldData[$field['field_name']] = $field['value'];
            }
        }

        return [
            'search_content' => implode(' ', $searchContent),
            'field_data' => json_encode($fieldData),
        ];
    }

    /**
     * Bulk-update the search index for many files in a single set-based pass.
     *
     * Used by the defaults backfill: the per-file updateFileIndex does 3+ round
     * trips per file (metadata, file info, upsert) which does not scale to
     * millions of files. This fetches metadata and file info for the whole set
     * in two queries, builds payloads via the shared buildIndexPayload, and
     * upserts in chunks with a single cache clear at the end.
     *
     * Files with no metadata are skipped here (not deleted) — the backfill only
     * ever adds rows, so a file reaching this method always has at least the
     * just-written defaults.
     *
     * @param int[] $fileIds
     */
    public function bulkUpdateFileIndex(array $fileIds): void {
        if (empty($fileIds)) {
            return;
        }

        try {
            $metadataByFile = $this->getMetadataForFiles($fileIds);
            $infoByFile = $this->getFileInfoForFiles($fileIds);

            // Assemble all index rows first, then write them set-based. Each row
            // is [fileId, userId, storageId, searchContent, fieldData].
            $rows = [];
            $touchedUsers = [];
            foreach ($fileIds as $fileId) {
                $metadata = $metadataByFile[$fileId] ?? [];
                $info = $infoByFile[$fileId] ?? null;
                if (empty($metadata) || $info === null || !$info['user_id']) {
                    continue;
                }
                $payload = $this->buildIndexPayload($metadata);
                $rows[] = [
                    (int)$fileId,
                    (string)$info['user_id'],
                    (int)$info['storage_id'],
                    strtolower($payload['search_content']),
                    $payload['field_data'],
                ];
                $touchedUsers[$info['user_id']] = true;
            }

            // One multi-row upsert per chunk instead of SELECT+write per file.
            foreach (array_chunk($rows, self::INDEX_UPSERT_CHUNK) as $chunk) {
                $this->bulkUpsertSearchIndex($chunk);
            }

            // Single cache clear per affected user instead of per file.
            $cache = $this->cacheFactory->createDistributed('metavox_search');
            foreach (array_keys($touchedUsers) as $userId) {
                $cache->clear("metavox_search_{$userId}_");
            }
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: bulk index update failed', [
                'exception' => $e,
                'count' => \count($fileIds),
            ]);
        }
    }

private function getFileMetadata(int $fileId): array {
    $metadata = [];

    // Only groupfolder file metadata supported now
    $qb = $this->db->getQueryBuilder();
    $qb->select('field_name', 'field_value')
       ->from('metavox_file_gf_meta')
       ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)));

    $result = $qb->executeQuery();
    while ($row = $result->fetch()) {
        // Skip only null/empty — keep '0' (empty(trim('0')) is true and would
        // wrongly drop unchecked-checkbox / numeric-zero values).
        if ($row['field_value'] !== null && (string)$row['field_value'] !== '') {
            $metadata[] = [
                'field_name' => $row['field_name'],
                'value' => $row['field_value']
            ];
        }
    }
    $result->closeCursor();

    return $metadata;
}

    private function getFileInfo(int $fileId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('f.storage', 'm.user_id')
           ->from('filecache', 'f')
           ->leftJoin('f', 'mounts', 'm', 'f.storage = m.storage_id')
           ->where($qb->expr()->eq('f.fileid', $qb->createNamedParameter($fileId)));

        $result = $qb->executeQuery();
        $info = $result->fetch();
        $result->closeCursor();

        if (!$info) {
            return null;
        }

        return [
            'storage_id' => (int)$info['storage'],
            'user_id' => $info['user_id'] ?: $this->getUserFromStorage((int)$info['storage'])
        ];
    }

    private function getUserFromStorage(int $storageId): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('storages')
           ->where($qb->expr()->eq('numeric_id', $qb->createNamedParameter($storageId)));

        $result = $qb->executeQuery();
        $storageString = $result->fetchOne();
        $result->closeCursor();

        if ($storageString && preg_match('/^home::(.+)$/', $storageString, $matches)) {
            return $matches[1];
        }

        return null;
    }

private function upsertSearchIndex(int $fileId, ?string $userId, int $storageId, string $searchContent, string $fieldData): void {
    if (!$userId) {
        return;
    }

    $this->upsertSearchIndexRow($fileId, $userId, $storageId, $searchContent, $fieldData);

    // Clear cache for this user
    $cache = $this->cacheFactory->createDistributed('metavox_search');
    $cache->clear("metavox_search_{$userId}_");
}

    /**
     * Write a single search-index row (insert or update) without clearing the
     * cache. Shared by the per-file path (upsertSearchIndex, which clears cache
     * once after) and the bulk path (bulkUpdateFileIndex, which clears once per
     * user at the end). Keeps the write logic identical across both.
     */
    private function upsertSearchIndexRow(int $fileId, string $userId, int $storageId, string $searchContent, string $fieldData): void {
        // Store search content in lowercase for case-insensitive search
        $normalizedSearchContent = strtolower($searchContent);

        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('metavox_search_index')
           ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $existingId = $result->fetchOne();
        $result->closeCursor();

        if ($existingId) {
            $qb = $this->db->getQueryBuilder();
            $qb->update('metavox_search_index')
               ->set('search_content', $qb->createNamedParameter($normalizedSearchContent))
               ->set('field_data', $qb->createNamedParameter($fieldData))
               ->set('updated_at', $qb->createNamedParameter(date('Y-m-d H:i:s')))
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($existingId)));
            $qb->executeStatement();
        } else {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('metavox_search_index')
               ->values([
                   'file_id' => $qb->createNamedParameter($fileId),
                   'user_id' => $qb->createNamedParameter($userId),
                   'storage_id' => $qb->createNamedParameter($storageId),
                   'search_content' => $qb->createNamedParameter($normalizedSearchContent),
                   'field_data' => $qb->createNamedParameter($fieldData),
                   'updated_at' => $qb->createNamedParameter(date('Y-m-d H:i:s'))
               ]);
            $qb->executeStatement();
        }
    }

    /**
     * Set-based upsert of many search-index rows in a single statement.
     *
     * Relies on the UNIQUE(file_id, user_id) index (migration 0020) so the
     * dialect-specific ON CONFLICT / ON DUPLICATE KEY UPDATE is atomic and
     * concurrency-safe — no SELECT-then-write race. Replaces ~2 round-trips per
     * file with one statement per chunk.
     *
     * @param array<array{0:int,1:string,2:int,3:string,4:string}> $rows
     *   each row: [fileId, userId, storageId, normalizedSearchContent, fieldData]
     */
    private function bulkUpsertSearchIndex(array $rows): void {
        if (empty($rows)) {
            return;
        }

        $isMysql = $this->db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySqlPlatform;
        $now = date('Y-m-d H:i:s');
        $sql = $this->buildSearchUpsertSql(\count($rows), $isMysql);

        $params = [];
        foreach ($rows as [$fileId, $userId, $storageId, $searchContent, $fieldData]) {
            array_push($params, $fileId, $userId, $storageId, $searchContent, $fieldData, $now);
        }

        $this->db->executeStatement($sql, $params);
    }

    /**
     * Build the dialect-specific multi-row upsert for metavox_search_index.
     * Pure builder so it can be unit-tested for both dialects. On conflict the
     * row is refreshed (DO UPDATE) — unlike defaults, re-indexing should reflect
     * the latest metadata.
     */
    public function buildSearchUpsertSql(int $rowCount, bool $isMysql): string {
        $placeholders = implode(', ', array_fill(0, $rowCount, '(?, ?, ?, ?, ?, ?)'));
        $head = "INSERT INTO *PREFIX*metavox_search_index "
              . "(file_id, user_id, storage_id, search_content, field_data, updated_at) "
              . "VALUES $placeholders ";

        if ($isMysql) {
            return $head . "ON DUPLICATE KEY UPDATE "
                 . "search_content = VALUES(search_content), "
                 . "field_data = VALUES(field_data), "
                 . "updated_at = VALUES(updated_at)";
        }

        return $head . "ON CONFLICT (file_id, user_id) DO UPDATE SET "
             . "search_content = EXCLUDED.search_content, "
             . "field_data = EXCLUDED.field_data, "
             . "updated_at = EXCLUDED.updated_at";
    }

    /**
     * Fetch metadata for many files at once, grouped by file id and shaped
     * exactly like getFileMetadata (empty values skipped).
     *
     * @param int[] $fileIds
     * @return array<int, array<array{field_name: string, value: string}>>
     */
    private function getMetadataForFiles(array $fileIds): array {
        $byFile = [];
        foreach (array_chunk($fileIds, self::QUERY_IN_CHUNK) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('file_id', 'field_name', 'field_value')
               ->from('metavox_file_gf_meta')
               ->where($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));

            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                // Skip only null/empty — keep '0' (see getFileMetadata).
                if ($row['field_value'] !== null && (string)$row['field_value'] !== '') {
                    $byFile[(int)$row['file_id']][] = [
                        'field_name' => $row['field_name'],
                        'value' => $row['field_value'],
                    ];
                }
            }
            $result->closeCursor();
        }
        return $byFile;
    }

    /**
     * Fetch storage/user info for many files at once, shaped like getFileInfo.
     *
     * @param int[] $fileIds
     * @return array<int, array{storage_id: int, user_id: ?string}>
     */
    private function getFileInfoForFiles(array $fileIds): array {
        $byFile = [];
        $storageOwnerCache = [];
        foreach (array_chunk($fileIds, self::QUERY_IN_CHUNK) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('f.fileid', 'f.storage', 'm.user_id')
               ->from('filecache', 'f')
               ->leftJoin('f', 'mounts', 'm', 'f.storage = m.storage_id')
               ->where($qb->expr()->in('f.fileid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));

            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $storageId = (int)$row['storage'];
                $userId = $row['user_id'];
                if (!$userId) {
                    // Resolve home:: storage owner once per storage.
                    if (!array_key_exists($storageId, $storageOwnerCache)) {
                        $storageOwnerCache[$storageId] = $this->getUserFromStorage($storageId);
                    }
                    $userId = $storageOwnerCache[$storageId];
                }
                $byFile[(int)$row['fileid']] = [
                    'storage_id' => $storageId,
                    'user_id' => $userId,
                ];
            }
            $result->closeCursor();
        }
        return $byFile;
    }

    private function deleteFileFromIndex(int $fileId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('metavox_search_index')
           ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)));
        $qb->executeStatement();
    }
}