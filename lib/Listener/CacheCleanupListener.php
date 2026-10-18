<?php

declare(strict_types=1);

namespace OCA\MetaVox\Listener;

use OCA\MetaVox\Service\MetadataVersions;
use OCA\MetaVox\Service\SearchWordsService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Cache\CacheEntryRemovedEvent;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Listens for filecache entry removal and cleans up associated metadata.
 * Fires when a file really leaves the cache (trash emptied, not moved to the
 * trashbin) and on a move to another storage, e.g. between team folders.
 *
 * Nextcloud sends this for every removed entry, previews and personal files
 * too: only those in team folder storage are kept, and cleaned together at
 * the end of the request instead of one by one.
 *
 * @template-implements IEventListener<CacheEntryRemovedEvent>
 */
class CacheCleanupListener implements IEventListener {

    private const CHUNK = 500;
    /** Seconds the team folder storages are remembered: new team folders are rare. */
    private const STORAGES_TTL = 300;

    /** @var array<int, array{int, string}> removed file id => [storage, path] */
    private array $removed = [];
    /** @var array<int, true>|null */
    private ?array $teamFolderStorages = null;
    private bool $flushRegistered = false;

    public function __construct(
        private readonly IDBConnection $db,
        private readonly SearchWordsService $searchWords,
        private readonly MetadataVersions $versions,
        private readonly ICacheFactory $cacheFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof CacheEntryRemovedEvent)) {
            return;
        }

        try {
            if (!$this->inTeamFolderStorage($event->getStorageId(), $event->getPath())) {
                return;
            }
            $this->removed[$event->getFileId()] = [$event->getStorageId(), $event->getPath()];
            if (!$this->flushRegistered) {
                $this->flushRegistered = true;
                register_shutdown_function([$this, 'flush']);
            }
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: Error noting removed file {fileId}', [
                'fileId' => $event->getFileId(),
                'exception' => $e,
            ]);
        }
    }

    /** Clean the metadata of the files removed in this request. */
    public function flush(): void {
        $removed = $this->removed;
        $this->removed = [];
        foreach (array_chunk($removed, self::CHUNK, true) as $chunk) {
            try {
                $fileIds = $this->genuine($chunk);
                if ($fileIds === []) {
                    continue;
                }
                $qb = $this->db->getQueryBuilder();
                $qb->delete('metavox_file_gf_meta')
                   ->where($qb->expr()->in('file_id', $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)));
                $deleted = $qb->executeStatement();
                $this->searchWords->removeFiles($fileIds);
                if ($deleted > 0) {
                    // Which team folder isn't known here.
                    $this->versions->bumpAll();
                    $this->logger->debug('MetaVox: Cleaned up {count} entries of {files} removed files', [
                        'count' => $deleted,
                        'files' => \count($fileIds),
                    ]);
                }
            } catch (\Exception $e) {
                // CleanupDeletedMetadata catches these later.
                $this->logger->error('MetaVox: Error cleaning metadata of removed files', ['exception' => $e]);
            }
        }
    }

    /**
     * Team folder files live in a team folder's own storage, or with the old
     * layout under __groupfolders/ in the root storage (trash and versions too).
     */
    private function inTeamFolderStorage(int $storageId, string $path): bool {
        return str_starts_with($path, '__groupfolders/') || isset($this->teamFolderStorages()[$storageId]);
    }

    /** @return array<int, true> */
    private function teamFolderStorages(): array {
        if ($this->teamFolderStorages !== null) {
            return $this->teamFolderStorages;
        }
        $cache = $this->cacheFactory->createDistributed('metavox');
        $ids = $cache->get('team_folder_storages');
        if (!\is_array($ids)) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('numeric_id')
               ->from('storages')
               ->where($qb->expr()->like('id', $qb->createNamedParameter('%/__groupfolders/%')));
            $result = $qb->executeQuery();
            $ids = [];
            while (($id = $result->fetchOne()) !== false) {
                $ids[] = (int)$id;
            }
            $result->closeCursor();
            $cache->set('team_folder_storages', $ids, self::STORAGES_TTL);
        }
        return $this->teamFolderStorages = array_fill_keys($ids, true);
    }

    /**
     * NC 34 and 35.0.0 send chunk indexes (0, 1, 2…) instead of file ids for
     * a removed folder's children. Only trust an id if it's gone or moved to
     * another storage; CleanupDeletedMetadata catches the rest later.
     *
     * @param array<int, array{int, string}> $removed
     * @return int[]
     */
    private function genuine(array $removed): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('fileid', 'storage', 'name')
           ->from('filecache')
           ->where($qb->expr()->in('fileid', $qb->createNamedParameter(array_keys($removed), IQueryBuilder::PARAM_INT_ARRAY)));
        $result = $qb->executeQuery();
        $still = [];
        while ($row = $result->fetch()) {
            $still[(int)$row['fileid']] = $row;
        }
        $result->closeCursor();

        $genuine = [];
        foreach ($removed as $fileId => [$storageId, $path]) {
            $row = $still[$fileId] ?? null;
            if ($row === null || ((int)$row['storage'] !== $storageId && $row['name'] === basename($path))) {
                $genuine[] = $fileId;
            }
        }
        return $genuine;
    }
}
