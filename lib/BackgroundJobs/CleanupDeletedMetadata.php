<?php

declare(strict_types=1);

namespace OCA\MetaVox\BackgroundJobs;

use OCP\BackgroundJob\QueuedJob;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;

class CleanupDeletedMetadata extends QueuedJob {

    public function run($arguments) {
        $db = \OC::$server->getDatabaseConnection();

        $nodeId = $arguments['node_id'] ?? null;
        $nodePath = $arguments['node_path'] ?? null;
        $nodeType = $arguments['node_type'] ?? 'unknown';
        $groupfolderId = $arguments['groupfolder_id'] ?? null;
        $timestamp = $arguments['timestamp'] ?? time();

        $logMessage = "=== METAVOX BACKGROUND CLEANUP ===\n";
        $logMessage .= "Time: " . date('Y-m-d H:i:s') . "\n";
        $logMessage .= "Node ID: " . ($nodeId ?? 'none') . "\n";
        $logMessage .= "Node Path: " . ($nodePath ?? 'none') . "\n";
        $logMessage .= "Node Type: " . $nodeType . "\n";
        $logMessage .= "Groupfolder ID: " . ($groupfolderId ?? 'none') . "\n";
        $logMessage .= "Triggered at: " . date('Y-m-d H:i:s', $timestamp) . "\n";

        try {
            $totalCleaned = 0;

            // Step 1: Check if the specific node still exists in filecache
            if ($nodeId) {
                $nodeExists = $this->checkNodeExists($db, $nodeId);
                $logMessage .= "Node still exists in filecache: " . ($nodeExists ? 'YES' : 'NO') . "\n";
                
                if (!$nodeExists) {
                    // Node is really deleted, clean up its metadata
                    $nodeCleaned = $this->cleanupNodeMetadata($db, $nodeId, $groupfolderId);
                    $totalCleaned += $nodeCleaned;
                    $logMessage .= "Cleaned metadata for deleted node: $nodeCleaned\n";
                } else {
                    $logMessage .= "Node still exists (moved to trash?), keeping metadata intact\n";
                }
            }

            // Step 2: General orphaned cleanup (regardless of specific node)
            $orphanedGlobal = $this->cleanupOrphanedGlobalMetadata($db);
            $totalCleaned += $orphanedGlobal;
            $logMessage .= "Cleaned orphaned global metadata: $orphanedGlobal\n";

            $orphanedSearch = $this->cleanupOrphanedSearchEntries($db);
            $totalCleaned += $orphanedSearch;
            $logMessage .= "Cleaned orphaned search entries: $orphanedSearch\n";

            $orphanedGroupfolder = $this->cleanupOrphanedGroupfolderMetadata($db);
            $totalCleaned += $orphanedGroupfolder;
            $logMessage .= "Cleaned orphaned groupfolder metadata: $orphanedGroupfolder\n";

            // Step 3: If it was a folder and we have path info, look for child nodes
            if ($nodeType === 'folder' && $nodePath && !$nodeExists) {
                $childrenCleaned = $this->cleanupFolderChildren($db, $nodePath);
                $totalCleaned += $childrenCleaned;
                $logMessage .= "Cleaned folder children metadata: $childrenCleaned\n";
            }

            $logMessage .= "Total entries cleaned: $totalCleaned\n";
            $logMessage .= "=============================\n";

            // Log to file
            file_put_contents('/var/www/nextcloud/data/metavox_delete.log', $logMessage, FILE_APPEND | LOCK_EX);
            
            error_log("MetaVox Cleanup Job: Cleaned $totalCleaned metadata entries for node $nodeId");

        } catch (\Exception $e) {
            $errorMsg = "METAVOX CLEANUP JOB ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            file_put_contents('/var/www/nextcloud/data/metavox_delete.log', $errorMsg, FILE_APPEND | LOCK_EX);
            error_log("MetaVox Cleanup Job Error: " . $e->getMessage());
        }
    }

    /**
     * Check if a node still exists in filecache
     */
    private function checkNodeExists(IDBConnection $db, int $nodeId): bool {
        try {
            $qb = $db->getQueryBuilder();
            $qb->select('fileid')
               ->from('filecache')
               ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($nodeId, IQueryBuilder::PARAM_INT)))
               ->setMaxResults(1);

            $result = $qb->execute();
            $exists = $result->fetchColumn() !== false;
            $result->closeCursor();

            return $exists;

        } catch (\Exception $e) {
            error_log("MetaVox Cleanup: Error checking node existence: " . $e->getMessage());
            return true; // Assume it exists to be safe
        }
    }

    /**
     * Clean up metadata for a specific node
     */
    private function cleanupNodeMetadata(IDBConnection $db, int $nodeId, ?int $groupfolderId): int {
        $totalCleaned = 0;

        try {
            // Clean global metadata
            $qb = $db->getQueryBuilder();
            $qb->delete('metavox_metadata')
               ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($nodeId, IQueryBuilder::PARAM_INT)));
            $globalCleaned = $qb->execute();
            $totalCleaned += $globalCleaned;

            // Clean search index
            $qb = $db->getQueryBuilder();
            $qb->delete('metavox_search_index')
               ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($nodeId, IQueryBuilder::PARAM_INT)));
            $searchCleaned = $qb->execute();
            $totalCleaned += $searchCleaned;

            // Clean groupfolder metadata if applicable
            if ($groupfolderId) {
                $qb = $db->getQueryBuilder();
                $qb->delete('metavox_file_gf_meta')
                   ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($nodeId, IQueryBuilder::PARAM_INT)))
                   ->andWhere($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)));
                $gfCleaned = $qb->execute();
                $totalCleaned += $gfCleaned;
            }

            if ($totalCleaned > 0) {
                error_log("MetaVox Cleanup: Cleaned $totalCleaned metadata entries for node $nodeId");
            }

            return $totalCleaned;

        } catch (\Exception $e) {
            error_log("MetaVox Cleanup: Error cleaning node metadata: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Clean up folder children by trying to find related file IDs
     * This is a fallback for cases where child nodes weren't individually processed
     */
    private function cleanupFolderChildren(IDBConnection $db, string $folderPath): int {
        try {
            // This is tricky - we can't safely query filecache for path patterns
            // So we'll just rely on the orphaned cleanup methods
            // which are safer and will catch any truly orphaned entries
            
            error_log("MetaVox Cleanup: Folder children cleanup delegated to orphaned cleanup for path: $folderPath");
            return 0;

        } catch (\Exception $e) {
            error_log("MetaVox Cleanup: Error cleaning folder children: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Clean up orphaned global metadata entries (where file no longer exists)
     */
    private function cleanupOrphanedGlobalMetadata(IDBConnection $db): int {
        try {
            $qb = $db->getQueryBuilder();
            $qb->select('m.file_id')
               ->from('metavox_metadata', 'm')
               ->leftJoin('m', 'filecache', 'fc', 'm.file_id = fc.fileid')
               ->where($qb->expr()->isNull('fc.fileid'))
               ->setMaxResults(100000);

            $result = $qb->execute();
            $orphanedFileIds = [];
            while ($row = $result->fetch()) {
                $orphanedFileIds[] = (int)$row['file_id'];
            }
            $result->closeCursor();

            if (empty($orphanedFileIds)) {
                return 0;
            }

            $qb = $db->getQueryBuilder();
            $qb->delete('metavox_metadata')
               ->where($qb->expr()->in('file_id', $qb->createParameter('file_ids')))
               ->setParameter('file_ids', $orphanedFileIds, IQueryBuilder::PARAM_INT_ARRAY);

            $deleted = $qb->execute();
            return $deleted;

        } catch (\Exception $e) {
            error_log("MetaVox Cleanup: Error cleaning orphaned global metadata: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Clean up orphaned search index entries
     */
    private function cleanupOrphanedSearchEntries(IDBConnection $db): int {
        try {
            $qb = $db->getQueryBuilder();
            $qb->select('si.file_id')
               ->from('metavox_search_index', 'si')
               ->leftJoin('si', 'filecache', 'fc', 'si.file_id = fc.fileid')
               ->where($qb->expr()->isNull('fc.fileid'))
               ->setMaxResults(100000);

            $result = $qb->execute();
            $orphanedFileIds = [];
            while ($row = $result->fetch()) {
                $orphanedFileIds[] = (int)$row['file_id'];
            }
            $result->closeCursor();

            if (empty($orphanedFileIds)) {
                return 0;
            }

            $qb = $db->getQueryBuilder();
            $qb->delete('metavox_search_index')
               ->where($qb->expr()->in('file_id', $qb->createParameter('file_ids')))
               ->setParameter('file_ids', $orphanedFileIds, IQueryBuilder::PARAM_INT_ARRAY);

            $deleted = $qb->execute();
            return $deleted;

        } catch (\Exception $e) {
            error_log("MetaVox Cleanup: Error cleaning orphaned search entries: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Clean up orphaned groupfolder file metadata
     */
    private function cleanupOrphanedGroupfolderMetadata(IDBConnection $db): int {
        try {
            $qb = $db->getQueryBuilder();
            $qb->select('gf.file_id')
               ->from('metavox_file_gf_meta', 'gf')
               ->leftJoin('gf', 'filecache', 'fc', 'gf.file_id = fc.fileid')
               ->where($qb->expr()->isNull('fc.fileid'))
               ->setMaxResults(100000);

            $result = $qb->execute();
            $orphanedFileIds = [];
            while ($row = $result->fetch()) {
                $orphanedFileIds[] = (int)$row['file_id'];
            }
            $result->closeCursor();

            if (empty($orphanedFileIds)) {
                return 0;
            }

            $qb = $db->getQueryBuilder();
            $qb->delete('metavox_file_gf_meta')
               ->where($qb->expr()->in('file_id', $qb->createParameter('file_ids')))
               ->setParameter('file_ids', $orphanedFileIds, IQueryBuilder::PARAM_INT_ARRAY);

            $deleted = $qb->execute();
            return $deleted;

        } catch (\Exception $e) {
            error_log("MetaVox Cleanup: Error cleaning orphaned groupfolder metadata: " . $e->getMessage());
            return 0;
        }
    }
}