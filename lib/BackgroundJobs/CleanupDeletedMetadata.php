<?php

declare(strict_types=1);

namespace OCA\MetaVox\BackgroundJobs;

use OCA\MetaVox\Service\MetadataVersions;
use OCA\MetaVox\Service\SearchWordsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Periodic cleanup of orphaned metadata entries. Runs once per day.
 *
 * Removes metadata and search words of files that no longer exist in filecache.
 * Real-time cleanup on file deletion is handled by CacheCleanupListener.
 */
class CleanupDeletedMetadata extends TimedJob {

    public function __construct(
        ITimeFactory $time,
        private readonly IDBConnection $db,
        private readonly SearchWordsService $searchWords,
        private readonly MetadataVersions $versions,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(86400);
    }

    protected function run(mixed $argument): void {
        try {
            $totalCleaned = $this->cleanupDeletedFiles();

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

    private function deleteMetadataForFiles(array $fileIds): int {
        if (empty($fileIds)) {
            return 0;
        }

        $this->searchWords->removeFiles($fileIds);
        $qb = $this->db->getQueryBuilder();
        $qb->delete('metavox_file_gf_meta')
           ->where($qb->expr()->in('file_id', $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)));

        $deleted = $qb->executeStatement();
        if ($deleted > 0) {
            $this->versions->bumpAll();
        }
        return $deleted;
    }
}
