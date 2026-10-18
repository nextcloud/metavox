<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCA\MetaVox\Access\ReadableScope;
use OCP\Constants;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\ICacheFactory;
use OCP\ICache;

class FilterService {

    private IDBConnection $db;
    private ICache $cache;
    private MetadataVersions $versions;
    private FileAccessService $fileAccess;
    private MetadataValueReader $reader;

    public function __construct(IDBConnection $db, ICacheFactory $cacheFactory, MetadataVersions $versions, FileAccessService $fileAccess, MetadataValueReader $reader) {
        $this->db = $db;
        $this->cache = $cacheFactory->createDistributed('metavox_filter');
        $this->versions = $versions;
        $this->fileAccess = $fileAccess;
        $this->reader = $reader;
    }

    /**
     * Only files the user can read in this team folder, each with NC
     * `_permissions` bits (READ, plus UPDATE if they may edit).
     *
     * @param int[] $fileIds
     * @param string[] $fieldNames optional: only these fields
     * @return array<int, array<string, mixed>> fileId => [fieldName => value, '_permissions' => int]
     */
    public function getDirectoryMetadataForUser(string $userId, int $groupfolderId, array $fileIds, array $fieldNames = []): array {
        $this->fileAccess->prime($userId, $fileIds);
        $permissions = [];
        foreach ($fileIds as $fileId) {
            $access = $this->fileAccess->getFileAccess($userId, (int)$fileId);
            if ($access->groupfolderId === $groupfolderId) {
                $permissions[(int)$fileId] = Constants::PERMISSION_READ
                    | ($access->canWrite ? Constants::PERMISSION_UPDATE : 0);
            }
        }
        if (empty($permissions)) {
            return [];
        }

        $metadata = $this->getDirectoryMetadata(array_keys($permissions), $groupfolderId, $fieldNames);
        foreach ($metadata as $fileId => &$fileMeta) {
            $fileMeta['_permissions'] = $permissions[$fileId] ?? Constants::PERMISSION_READ;
        }
        unset($fileMeta);

        return $metadata;
    }

    /**
     * Full values straight from the database. Every requested file is in the
     * result, also without values. Database errors are thrown.
     *
     * @param int[] $fileIds
     * @param string[] $fieldNames optional: only these fields
     * @return array<int, array<string, ?string>> fileId => [fieldName => value]
     */
    private function getDirectoryMetadata(array $fileIds, int $groupfolderId, array $fieldNames = []): array {
        $values = $this->reader->readValues($groupfolderId, $fileIds);
        $wanted = empty($fieldNames) ? null : array_flip($fieldNames);

        $metadata = [];
        foreach ($fileIds as $fileId) {
            $row = $values[(int)$fileId] ?? [];
            $metadata[(int)$fileId] = $wanted === null ? $row : array_intersect_key($row, $wanted);
        }
        return $metadata;
    }

    /**
     * Get sorted and filtered file IDs for a groupfolder.
     * Uses SQL self-joins on the EAV table for efficient server-side sort/filter.
     *
     * @param int $groupfolderId
     * @param ReadableScope $scope only files the caller may read
     * @param string|null $sortField Field name to sort by
     * @param string $sortOrder 'asc' or 'desc'
     * @param array $filters ['field_name' => ['val1','val2'], ...] — AND between fields, OR within
     * @param string $sortFieldType 'text', 'number', 'date', 'checkbox'
     * @param array $multiselectFields Field names that use ;# delimiter
     * @return array ['file_ids' => int[], 'total' => int]
     */
    public function getSortedFilteredFileIds(
        int $groupfolderId,
        ReadableScope $scope,
        ?string $sortField,
        string $sortOrder,
        array $filters,
        string $sortFieldType = 'text',
        array $multiselectFields = []
    ): array {
        $cacheKey = 'gf_' . $groupfolderId . '_sorted_' . md5(json_encode([
            $sortField, $sortOrder, $filters, $sortFieldType, $scope->key(), $this->versions->get($groupfolderId),
        ]));
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Use *PREFIX* which IDBConnection::prepare() resolves automatically
        $table = '*PREFIX*metavox_file_gf_meta';

        // Build the SQL manually for the self-join pattern
        $sql = "SELECT base.file_id";
        if ($sortField) {
            $sql .= ", MAX(m_sort.field_value) AS sort_value";
        }
        $sql .= " FROM {$table} base";

        $params = [];
        $types = [];

        // Filter joins: one INNER JOIN per filter field
        $joinIdx = 0;
        foreach ($filters as $fieldName => $values) {
            if (empty($values)) continue;
            $alias = "f{$joinIdx}";

            // Separate "empty" filter (value '0') from real values
            $hasEmptyFilter = in_array('0', $values, true);
            $realValues = array_filter($values, fn($v) => $v !== '0');

            if ($hasEmptyFilter && empty($realValues)) {
                // Only matching empty/missing values — use LEFT JOIN + IS NULL
                $sql .= " LEFT JOIN {$table} {$alias}"
                      . " ON {$alias}.file_id = base.file_id"
                      . " AND {$alias}.groupfolder_id = ?"
                      . " AND {$alias}.field_name = ?";
                $params[] = $groupfolderId;
                $types[] = \PDO::PARAM_INT;
                $params[] = $fieldName;
                $types[] = \PDO::PARAM_STR;
                // WHERE clause added below
            } else {
                $isMultiselect = in_array($fieldName, $multiselectFields);

                if ($isMultiselect && !empty($realValues)) {
                    // Multiselect: match with LIKE patterns for ;# delimiter
                    $conditions = [];
                    foreach ($realValues as $val) {
                        $conditions[] = "{$alias}.field_value = ?";
                        $params[] = $val;
                        $types[] = \PDO::PARAM_STR;
                        $conditions[] = "{$alias}.field_value LIKE ?";
                        $params[] = $val . ';#%';
                        $types[] = \PDO::PARAM_STR;
                        $conditions[] = "{$alias}.field_value LIKE ?";
                        $params[] = '%;#' . $val;
                        $types[] = \PDO::PARAM_STR;
                        $conditions[] = "{$alias}.field_value LIKE ?";
                        $params[] = '%;#' . $val . ';#%';
                        $types[] = \PDO::PARAM_STR;
                    }
                    $orClause = '(' . implode(' OR ', $conditions) . ')';

                    if ($hasEmptyFilter) {
                        $sql .= " LEFT JOIN {$table} {$alias}"
                              . " ON {$alias}.file_id = base.file_id"
                              . " AND {$alias}.groupfolder_id = ?"
                              . " AND {$alias}.field_name = ?"
                              . " AND {$orClause}";
                    } else {
                        $sql .= " INNER JOIN {$table} {$alias}"
                              . " ON {$alias}.file_id = base.file_id"
                              . " AND {$alias}.groupfolder_id = ?"
                              . " AND {$alias}.field_name = ?"
                              . " AND {$orClause}";
                    }
                    $params[] = $groupfolderId;
                    $types[] = \PDO::PARAM_INT;
                    $params[] = $fieldName;
                    $types[] = \PDO::PARAM_STR;
                } else {
                    // Standard field: exact IN match
                    $placeholders = implode(',', array_fill(0, count($realValues), '?'));

                    if ($hasEmptyFilter) {
                        $sql .= " LEFT JOIN {$table} {$alias}"
                              . " ON {$alias}.file_id = base.file_id"
                              . " AND {$alias}.groupfolder_id = ?"
                              . " AND {$alias}.field_name = ?";
                        $params[] = $groupfolderId;
                        $types[] = \PDO::PARAM_INT;
                        $params[] = $fieldName;
                        $types[] = \PDO::PARAM_STR;
                    } else {
                        $sql .= " INNER JOIN {$table} {$alias}"
                              . " ON {$alias}.file_id = base.file_id"
                              . " AND {$alias}.groupfolder_id = ?"
                              . " AND {$alias}.field_name = ?"
                              . " AND {$alias}.field_value IN ({$placeholders})";
                        $params[] = $groupfolderId;
                        $types[] = \PDO::PARAM_INT;
                        $params[] = $fieldName;
                        $types[] = \PDO::PARAM_STR;
                        foreach ($realValues as $val) {
                            $params[] = $val;
                            $types[] = \PDO::PARAM_STR;
                        }
                    }
                }
            }

            $joinIdx++;
        }

        // Sort join (LEFT JOIN so files without sort field appear at bottom)
        if ($sortField) {
            $sql .= " LEFT JOIN {$table} m_sort"
                  . " ON m_sort.file_id = base.file_id"
                  . " AND m_sort.groupfolder_id = ?"
                  . " AND m_sort.field_name = ?";
            $params[] = $groupfolderId;
            $types[] = \PDO::PARAM_INT;
            $params[] = $sortField;
            $types[] = \PDO::PARAM_STR;
        }

        // WHERE clause
        $sql .= " WHERE base.groupfolder_id = ?";
        $params[] = $groupfolderId;
        $types[] = \PDO::PARAM_INT;
        $readable = $scope->sql('base.file_id', $params, $types);
        if ($readable !== null) {
            $sql .= " AND {$readable}";
        }

        // Add WHERE conditions for empty-filter LEFT JOINs
        $joinIdx = 0;
        foreach ($filters as $fieldName => $values) {
            if (empty($values)) { $joinIdx++; continue; }
            $hasEmptyFilter = in_array('0', $values, true);
            $realValues = array_filter($values, fn($v) => $v !== '0');
            $alias = "f{$joinIdx}";

            if ($hasEmptyFilter && empty($realValues)) {
                // Only empty: match NULL or empty
                $sql .= " AND ({$alias}.field_value IS NULL OR {$alias}.field_value = '' OR {$alias}.field_value = '0' OR {$alias}.field_value = 'false')";
            } elseif ($hasEmptyFilter && !empty($realValues)) {
                // Both empty and real values: match value OR NULL
                $sql .= " AND ({$alias}.field_value IS NOT NULL OR {$alias}.file_id IS NULL)";
            }

            $joinIdx++;
        }

        // GROUP BY to deduplicate file IDs (base table has multiple rows per file)
        $sql .= " GROUP BY base.file_id";

        // ORDER BY
        if ($sortField) {
            $dir = strtoupper($sortOrder) === 'DESC' ? 'DESC' : 'ASC';
            // The rows are grouped per file, so order on the aggregate
            // (PostgreSQL refuses the bare column). Empty values go last.
            $sortValue = 'MAX(m_sort.field_value)';
            $sql .= " ORDER BY CASE WHEN {$sortValue} IS NULL OR {$sortValue} = '' THEN 1 ELSE 0 END";

            if ($sortFieldType === 'number') {
                // PostgreSQL can't cast '' to a number.
                $sql .= ", CAST(NULLIF({$sortValue}, '') AS DECIMAL(20,6)) {$dir}";
            } else {
                $sql .= ", {$sortValue} {$dir}";
            }
        } else {
            $sql .= " ORDER BY base.file_id ASC";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $fileIds = [];
        while ($row = $stmt->fetch()) {
            $fileIds[] = (int)$row['file_id'];
        }
        $stmt->closeCursor();

        $result = ['file_ids' => $fileIds, 'total' => count($fileIds)];
        $this->cache->set($cacheKey, $result, 30);
        return $result;
    }

    /**
     * The different values per field in a team folder, of files the caller may
     * read and that still exist, sorted. Cached until the team folder's
     * metadata changes.
     *
     * @param string[] $fieldNames empty: all fields
     * @param int[] $fileIds empty: all files
     * @param int|null $maxRows at most this many (field, value) rows
     * @return array<string, list<string>> field name => values
     */
    public function distinctValues(int $groupfolderId, ReadableScope $scope, array $fieldNames = [], array $fileIds = [], ?int $maxRows = null): array {
        sort($fileIds);
        $cacheKey = "gf_{$groupfolderId}_dv_" . md5(json_encode([
            $scope->key(), $fieldNames, $fileIds, $maxRows, $this->versions->get($groupfolderId),
        ]));
        $cached = $this->cache->get($cacheKey);
        if (\is_array($cached)) {
            return $cached;
        }

        $qb = $this->db->getQueryBuilder();
        // Only values of files that still exist: the rest can't be found.
        $qb->selectDistinct(['m.field_name', 'm.field_value'])
           ->from('metavox_file_gf_meta', 'm')
           ->innerJoin('m', 'filecache', 'f', $qb->expr()->eq('m.file_id', 'f.fileid'))
           ->where($qb->expr()->eq('m.groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->isNotNull('m.field_value'))
           ->andWhere($qb->expr()->neq('m.field_value', $qb->createNamedParameter('')))
           ->orderBy('m.field_name', 'ASC')
           ->addOrderBy('m.field_value', 'ASC');
        if ($fieldNames !== []) {
            $qb->andWhere($qb->expr()->in('m.field_name', $qb->createNamedParameter(array_values($fieldNames), IQueryBuilder::PARAM_STR_ARRAY)));
        }
        if ($fileIds !== []) {
            $qb->andWhere($qb->expr()->in('m.file_id', $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)));
        }
        if ($maxRows !== null) {
            $qb->setMaxResults($maxRows);
        }
        $scope->restrict($qb, 'm.file_id');

        $result = $qb->executeQuery();
        $values = [];
        while ($row = $result->fetch()) {
            $values[$row['field_name']][] = $row['field_value'];
        }
        $result->closeCursor();

        $this->cache->set($cacheKey, $values, 300);
        return $values;
    }

    /**
     * Values to pick from for one field, as the search filter offers them:
     * each item of a list field apart, at most $limit. Other fields keep
     * their value whole, as the search matches it whole.
     *
     * @return list<string>
     */
    public function suggestedValues(string $fieldName, int $groupfolderId, ReadableScope $scope, int $limit = 50, bool $list = true): array {
        // A little more than asked: one multiple choice value holds several.
        $values = [];
        foreach ($this->distinctValues($groupfolderId, $scope, [$fieldName], [], $limit * 4)[$fieldName] ?? [] as $value) {
            foreach ($list ? explode(';#', (string)$value) : [(string)$value] as $choice) {
                $choice = trim($choice);
                if ($choice !== '') {
                    $values[$choice] = true;
                }
            }
        }
        $list = \array_slice(array_map('strval', array_keys($values)), 0, $limit);
        sort($list, SORT_NATURAL | SORT_FLAG_CASE);
        return $list;
    }
}
