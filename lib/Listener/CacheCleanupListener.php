<?php

declare(strict_types=1);

namespace OCA\MetaVox\Listener;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Cache\CacheEntryRemovedEvent;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Listens for filecache entry removal and cleans up associated metadata.
 * This fires when files are truly removed from the cache (e.g. trash emptied),
 * not when they are merely moved to the trashbin.
 *
 * @template-implements IEventListener<CacheEntryRemovedEvent>
 */
class CacheCleanupListener implements IEventListener {

    public function __construct(
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof CacheEntryRemovedEvent)) {
            return;
        }

        $fileId = $event->getFileId();

        try {
            $deleted = 0;

            $qb = $this->db->getQueryBuilder();
            $qb->delete('metavox_file_gf_meta')
               ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
            $deleted += $qb->executeStatement();

            $qb = $this->db->getQueryBuilder();
            $qb->delete('metavox_search_index')
               ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
            $deleted += $qb->executeStatement();

            if ($deleted > 0) {
                $this->logger->debug('MetaVox: Cleaned up {count} entries for removed file {fileId}', [
                    'count' => $deleted,
                    'fileId' => $fileId,
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: Error cleaning metadata for file {fileId}', [
                'fileId' => $fileId,
                'exception' => $e,
            ]);
        }
    }
}
