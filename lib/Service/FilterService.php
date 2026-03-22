<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\ICacheFactory;
use OCP\ICache;

class FilterService {

    private IDBConnection $db;
    private ICache $cache;
    private MetaVoxCacheService $cacheService;

    public function __construct(IDBConnection $db, ICacheFactory $cacheFactory, MetaVoxCacheService $cacheService) {
        $this->db = $db;
        $this->cache = $cacheFactory->createDistributed('metavox_filter');
        $this->cacheService = $cacheService;
    }

    /**
     * Get bulk metadata for a list of file IDs in a groupfolder.
     * Used by the file list columns to populate metadata cells.
     *
     * @param array $fileIds
     * @param int $groupfolderId
     * @return array<int, array<string, string>> fileId => [fieldName => value]
     */
    public function getDirectoryMetadata(array $fileIds, int $groupfolderId, array $fieldNames = []): array {
        if (empty($fileIds)) {
            return [];
        }

        // Read-through cache: check cache first, only query DB for uncached files.
        // Write-through in saveGroupfolderFileFieldValue ensures cache is always fresh.
        $metadataByFile = [];
        $uncachedIds = [];

        foreach ($fileIds as $fileId) {
            $cached = $this->cacheService->getFileMetadata($groupfolderId, $fileId);
            if ($cached !== null) {
                $metadataByFile[$fileId] = $cached;
            } else {
                $uncachedIds[] = $fileId;
                $metadataByFile[$fileId] = [];
            }
        }

        if (empty($uncachedIds)) {
            return $metadataByFile;
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('file_id', 'field_name', 'field_value')
               ->from('metavox_file_gf_meta')
               ->where($qb->expr()->in('file_id', $qb->createNamedParameter($uncachedIds, IQueryBuilder::PARAM_INT_ARRAY)))
               ->andWhere($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)));

            if (!empty($fieldNames)) {
                $qb->andWhere($qb->expr()->in('field_name', $qb->createNamedParameter($fieldNames, IQueryBuilder::PARAM_STR_ARRAY)));
            }

            $result = $qb->executeQuery();

            while ($row = $result->fetch()) {
                $fileId = (int)$row['file_id'];
                $metadataByFile[$fileId][$row['field_name']] = $row['field_value'];
            }
            $result->closeCursor();

            // Cache results (write-through keeps these fresh on save)
            foreach ($uncachedIds as $fileId) {
                $this->cacheService->setFileMetadata($groupfolderId, $fileId, $metadataByFile[$fileId] ?? []);
            }

            return $metadataByFile;
        } catch (\Exception $e) {
            error_log('MetaVox FilterService getDirectoryMetadata error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get sorted and filtered file IDs for a groupfolder.
     * Uses SQL self-joins on the EAV table for efficient server-side sort/filter.
     *
     * @param int $groupfolderId
     * @param string|null $sortField Field name to sort by
     * @param string $sortOrder 'asc' or 'desc'
     * @param array $filters ['field_name' => ['val1','val2'], ...] — AND between fields, OR within
     * @param string $sortFieldType 'text', 'number', 'date', 'checkbox'
     * @param array $multiselectFields Field names that use ;# delimiter
     * @return array ['file_ids' => int[], 'total' => int]
     */
    public function getSortedFilteredFileIds(
        int $groupfolderId,
        ?string $sortField,
        string $sortOrder,
        array $filters,
        string $sortFieldType = 'text',
        array $multiselectFields = []
    ): array {
        $cacheKey = 'gf_' . $groupfolderId . '_sorted_' . md5(json_encode([
            $sortField, $sortOrder, $filters, $sortFieldType,
        ]));
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
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
                // Push NULL/empty to bottom regardless of sort direction
                $sql .= " ORDER BY CASE WHEN m_sort.field_value IS NULL OR m_sort.field_value = '' THEN 1 ELSE 0 END";

                if ($sortFieldType === 'number') {
                    $sql .= ", CAST(m_sort.field_value AS DECIMAL(20,6)) {$dir}";
                } else {
                    $sql .= ", m_sort.field_value {$dir}";
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
        } catch (\Exception $e) {
            error_log('MetaVox FilterService getSortedFilteredFileIds error: ' . $e->getMessage());
            return ['file_ids' => [], 'total' => 0];
        }
    }

    /**
     * Get distinct field values for ALL fields in a groupfolder in one query.
     * Returns: { field_name: [value1, value2, ...], ... }
     *
     * @param int $groupfolderId
     * @param array $fieldNames Optional list of field names to filter on
     * @return array<string, array<string>>
     */
    public function getAllDistinctFieldValues(int $groupfolderId, array $fieldNames = [], array $fileIds = []): array {
        $cacheKey = "gf_{$groupfolderId}_fv_all";
        if (!empty($fieldNames)) {
            $cacheKey .= '_' . md5(implode(',', $fieldNames));
        }
        if (!empty($fileIds)) {
            sort($fileIds);
            $cacheKey .= '_f' . md5(implode(',', $fileIds));
        }
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('field_name', 'field_value')
               ->selectAlias($qb->createFunction('1'), 'n')
               ->from('metavox_file_gf_meta')
               ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)))
               ->andWhere($qb->expr()->neq('field_value', $qb->createNamedParameter('')))
               ->andWhere($qb->expr()->isNotNull('field_value'))
               ->groupBy('field_name', 'field_value')
               ->orderBy('field_name', 'ASC')
               ->addOrderBy('field_value', 'ASC');

            if (!empty($fieldNames)) {
                $qb->andWhere($qb->expr()->in('field_name', $qb->createNamedParameter($fieldNames, IQueryBuilder::PARAM_STR_ARRAY)));
            }

            if (!empty($fileIds)) {
                $qb->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)));
            }

            $result = $qb->executeQuery();
            $values = [];
            while ($row = $result->fetch()) {
                $values[$row['field_name']][] = $row['field_value'];
            }
            $result->closeCursor();

            $this->cache->set($cacheKey, $values, 300);
            return $values;
        } catch (\Exception $e) {
            error_log('MetaVox FilterService getAllDistinctFieldValues error: ' . $e->getMessage());
            return [];
        }
    }
}
