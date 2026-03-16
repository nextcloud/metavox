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

    public function __construct(IDBConnection $db, ICacheFactory $cacheFactory) {
        $this->db = $db;
        $this->cache = $cacheFactory->createDistributed('metavox_filter');
    }

    /**
     * Get bulk metadata for a list of file IDs in a groupfolder.
     * Used by the file list columns to populate metadata cells.
     *
     * @param array $fileIds
     * @param int $groupfolderId
     * @return array<int, array<string, string>> fileId => [fieldName => value]
     */
    public function getDirectoryMetadata(array $fileIds, int $groupfolderId): array {
        if (empty($fileIds)) {
            return [];
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('file_id', 'field_name', 'field_value')
               ->from('metavox_file_gf_meta')
               ->where($qb->expr()->in('file_id', $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)))
               ->andWhere($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)));

            $result = $qb->executeQuery();
            $metadataByFile = [];

            foreach ($fileIds as $fileId) {
                $metadataByFile[$fileId] = [];
            }

            while ($row = $result->fetch()) {
                $fileId = (int)$row['file_id'];
                $metadataByFile[$fileId][$row['field_name']] = $row['field_value'];
            }
            $result->closeCursor();

            return $metadataByFile;
        } catch (\Exception $e) {
            error_log('MetaVox FilterService getDirectoryMetadata error: ' . $e->getMessage());
            return [];
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
    public function getAllDistinctFieldValues(int $groupfolderId, array $fieldNames = []): array {
        $cacheKey = "gf_{$groupfolderId}_fv_all";
        if (!empty($fieldNames)) {
            $cacheKey .= '_' . md5(implode(',', $fieldNames));
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
