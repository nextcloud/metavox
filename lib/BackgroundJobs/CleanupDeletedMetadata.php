<?php

declare(strict_types=1);

namespace OCA\MetaVox\BackgroundJobs;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Periodic cleanup of orphaned metadata entries. Runs once per day.
 *
 * Handles two cases:
 * 1. Deleted files — metadata where file_id no longer exists in filecache
 * 2. Moved files — metadata where the file still exists but is no longer in its assigned groupfolder
 *
 * Real-time cleanup on file deletion is handled by CacheCleanupListener.
 */
class CleanupDeletedMetadata extends TimedJob {

    public function __construct(
        ITimeFactory $time,
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(86400);
    }

    protected function run(mixed $argument): void {
        try {
            $totalCleaned = 0;
            $totalCleaned += $this->cleanupDeletedFiles();
            $totalCleaned += $this->cleanupMovedFiles();
            $totalCleaned += $this->cleanupOrphanedSearchEntries();

            if ($totalCleaned > 0) {
                $this->logger->info('MetaVox cleanup: removed {count} orphaned entries', [
                    'count' => $totalCleaned,
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('MetaVox cleanup error', ['exception' => $e]);
        }
    }

    /**
     * Remove metadata for files that no longer exist in filecache.
     */
    private function cleanupDeletedFiles(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('gf.file_id')
           ->from('metavox_file_gf_meta', 'gf')
           ->leftJoin('gf', 'filecache', 'fc', $qb->expr()->eq('gf.file_id', 'fc.fileid'))
           ->where($qb->expr()->isNull('fc.fileid'))
           ->groupBy('gf.file_id')
           ->setMaxResults(10000);

        $result = $qb->executeQuery();
        $orphanedIds = [];
        while ($row = $result->fetch()) {
            $orphanedIds[] = (int)$row['file_id'];
        }
        $result->closeCursor();

        return $this->deleteMetadataForFiles($orphanedIds);
    }

    /**
     * Remove metadata for files that still exist but are no longer in their assigned groupfolder.
     */
    private function cleanupMovedFiles(): int {
        $groupfolders = $this->loadGroupfolders();
        if (empty($groupfolders)) {
            return 0;
        }

        // Get metadata entries joined with their filecache path
        $qb = $this->db->getQueryBuilder();
        $qb->select('gf.file_id', 'gf.groupfolder_id', 'fc.path')
           ->from('metavox_file_gf_meta', 'gf')
           ->innerJoin('gf', 'filecache', 'fc', $qb->expr()->eq('gf.file_id', 'fc.fileid'))
           ->groupBy('gf.file_id', 'gf.groupfolder_id', 'fc.path')
           ->setMaxResults(10000);

        $result = $qb->executeQuery();
        $movedFileIds = [];
        while ($row = $result->fetch()) {
            $gfId = (int)$row['groupfolder_id'];
            $path = $row['path'];

            // Groupfolder was deleted entirely
            if (!isset($groupfolders[$gfId])) {
                $movedFileIds[] = (int)$row['file_id'];
                continue;
            }

            // Check if file path still belongs to this groupfolder
            // Filecache stores paths as: __groupfolders/{id}/...
            if (!str_contains($path, '__groupfolders/' . $gfId . '/')) {
                $movedFileIds[] = (int)$row['file_id'];
            }
        }
        $result->closeCursor();

        return $this->deleteMetadataForFiles($movedFileIds);
    }

    /**
     * Remove orphaned search index entries.
     */
    private function cleanupOrphanedSearchEntries(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('si.file_id')
           ->from('metavox_search_index', 'si')
           ->leftJoin('si', 'filecache', 'fc', $qb->expr()->eq('si.file_id', 'fc.fileid'))
           ->where($qb->expr()->isNull('fc.fileid'))
           ->setMaxResults(10000);

        $result = $qb->executeQuery();
        $orphanedIds = [];
        while ($row = $result->fetch()) {
            $orphanedIds[] = (int)$row['file_id'];
        }
        $result->closeCursor();

        if (empty($orphanedIds)) {
            return 0;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->delete('metavox_search_index')
           ->where($qb->expr()->in('file_id', $qb->createNamedParameter($orphanedIds, IQueryBuilder::PARAM_INT_ARRAY)));

        return $qb->executeStatement();
    }

    private function loadGroupfolders(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('folder_id', 'mount_point')
           ->from('group_folders');

        $result = $qb->executeQuery();
        $groupfolders = [];
        while ($row = $result->fetch()) {
            $groupfolders[(int)$row['folder_id']] = $row['mount_point'];
        }
        $result->closeCursor();

        return $groupfolders;
    }

    private function deleteMetadataForFiles(array $fileIds): int {
        if (empty($fileIds)) {
            return 0;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->delete('metavox_file_gf_meta')
           ->where($qb->expr()->in('file_id', $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)));

        return $qb->executeStatement();
    }
}
