<?php
declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICacheFactory;

class SearchIndexService {

    private IDBConnection $db;
    private ICacheFactory $cacheFactory;

    public function __construct(IDBConnection $db, ICacheFactory $cacheFactory) {
        $this->db = $db;
        $this->cacheFactory = $cacheFactory;
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
            error_log('MetaVox search error: ' . $e->getMessage());
            return [];
        }
    }

    public function searchByFieldValue(string $fieldName, string $fieldValue, string $userId): array {
        try {
            $qb = $this->db->getQueryBuilder();
            // CHANGED: Remove user_id filter, permission check happens in SearchProvider
            // Convert to lowercase to match stored lowercase data
            $qb->select('si.file_id', 'si.field_data', 'f.name', 'f.path')
               ->from('metavox_search_index', 'si')
               ->innerJoin('si', 'filecache', 'f', 'si.file_id = f.fileid')
               ->where($qb->expr()->like(
                   'si.field_data',
                   $qb->createNamedParameter('%"' . strtolower($this->db->escapeLikeParameter($fieldName)) . '":"' . strtolower($this->db->escapeLikeParameter($fieldValue)) . '%')
               ))
               ->setMaxResults(100)  // Increased limit since we filter later
               ->orderBy('f.mtime', 'DESC');

            $result = $qb->execute();
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
            
        } catch (\Exception $e) {
            error_log('MetaVox field search error: ' . $e->getMessage());
            return [];
        }
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
            $testQb->execute()->closeCursor();
            $useFulltext = true;
        } catch (\Exception $e) {
            // FULLTEXT index not available, fall back to LIKE
            error_log('MetaVox: FULLTEXT not available, using LIKE search: ' . $e->getMessage());
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

    $result = $qb->execute();
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

        $result = $qb->execute();
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
        
        $result = $qb->execute();
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
            $qb->execute();
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

            $searchContent = [];
            $fieldData = [];
            
            foreach ($metadata as $field) {
                if ($field['value']) {
                    $searchContent[] = $field['value'];
                    $fieldData[$field['field_name']] = $field['value'];
                }
            }

            $this->upsertSearchIndex($fileId, $fileInfo['user_id'], $fileInfo['storage_id'], 
                                   implode(' ', $searchContent), json_encode($fieldData));

        } catch (\Exception $e) {
            error_log('MetaVox index update error: ' . $e->getMessage());
        }
    }

private function getFileMetadata(int $fileId): array {
    $metadata = [];

    // Only groupfolder file metadata supported now
    $qb = $this->db->getQueryBuilder();
    $qb->select('field_name', 'field_value')
       ->from('metavox_file_gf_meta')
       ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)));

    $result = $qb->execute();
    while ($row = $result->fetch()) {
        if (!empty(trim($row['field_value']))) {
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

        $result = $qb->execute();
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

        $result = $qb->execute();
        $storageString = $result->fetchColumn();
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

    // Store search content in lowercase for case-insensitive search
    $normalizedSearchContent = strtolower($searchContent);

        // Check if exists
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('metavox_search_index')
           ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $result = $qb->execute();
        $existingId = $result->fetchColumn();
        $result->closeCursor();

if ($existingId) {
        $qb = $this->db->getQueryBuilder();
        $qb->update('metavox_search_index')
           ->set('search_content', $qb->createNamedParameter($normalizedSearchContent))
           ->set('field_data', $qb->createNamedParameter($fieldData))
           ->set('updated_at', $qb->createNamedParameter(date('Y-m-d H:i:s')))
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($existingId)));
        $qb->execute();
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
        $qb->execute();
    }


        // Clear cache for this user
        $cache = $this->cacheFactory->createDistributed('metavox_search');
        $cache->clear("metavox_search_{$userId}_");
    }

    private function deleteFileFromIndex(int $fileId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('metavox_search_index')
           ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)));
        $qb->execute();
    }
}