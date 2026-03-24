<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\ICacheFactory;
use OCP\ICache;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\DB\QueryBuilder\IQueryBuilder;

class PushService {

    private IDBConnection $db;
    private IGroupManager $groupManager;
    private PresenceService $presenceService;
    private ICache $cache;
    private $notifyQueue = null;

    /**
     * Batched metadata changes within the current request.
     * Flushed in destructor to reduce push fan-out.
     * @var array<int, array<int, array{fieldName: ?string, value: ?string}>>
     *      groupfolderId => [fileId => {fieldName, value}]
     */
    private array $pendingChanges = [];
    private bool $shutdownRegistered = false;

    public function __construct(
        IDBConnection $db,
        IGroupManager $groupManager,
        PresenceService $presenceService,
        ICacheFactory $cacheFactory
    ) {
        $this->db = $db;
        $this->groupManager = $groupManager;
        $this->presenceService = $presenceService;
        $this->cache = $cacheFactory->createDistributed('metavox_push');

        try {
            $this->notifyQueue = \OC::$server->get(\OCA\NotifyPush\Queue\IQueue::class);
        } catch (\Exception $e) {
            // notify_push not installed
        }
    }

    /**
     * Queue a metadata changed event. Batched and sent at end of request.
     * Multiple saves to the same file in one request = 1 push event.
     */
    public function metadataChanged(int $groupfolderId, int $fileId, ?string $fieldName = null, ?string $value = null): void {
        $this->pendingChanges[$groupfolderId][$fileId] = [
            'fieldName' => $fieldName,
            'value' => $value,
        ];

        // Register shutdown handler once to flush all pending changes
        if (!$this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            register_shutdown_function([$this, 'flushPendingChanges']);
        }
    }

    /**
     * Flush all pending metadata changes as batched push events.
     * Called automatically at end of request.
     */
    public function flushPendingChanges(): void {
        if (empty($this->pendingChanges)) return;

        foreach ($this->pendingChanges as $groupfolderId => $files) {
            if (count($files) === 1) {
                // Single file change — send with field data for direct cache update
                $fileId = array_key_first($files);
                $change = $files[$fileId];
                $payload = [
                    'gfId' => $groupfolderId,
                    'fileId' => $fileId,
                ];
                if ($change['fieldName'] !== null) {
                    $payload['fieldName'] = $change['fieldName'];
                    $payload['value'] = $change['value'];
                }
                $this->broadcast($groupfolderId, 'metavox_metadata_changed', $payload);
            } else {
                // Multiple files changed — send batch event (frontend refetches)
                $fileIds = array_map('intval', array_keys($files));
                $this->broadcast($groupfolderId, 'metavox_metadata_changed', [
                    'gfId' => $groupfolderId,
                    'fileIds' => $fileIds,
                ]);
            }
        }

        $this->pendingChanges = [];
    }

    /**
     * Push lock event to active viewers (immediate, not batched).
     */
    public function cellLocked(int $groupfolderId, int $fileId, string $fieldName, string $userId): void {
        $this->broadcast($groupfolderId, 'metavox_cell_locked', [
            'gfId' => $groupfolderId,
            'fileId' => $fileId,
            'fieldName' => $fieldName,
            'userId' => $userId,
        ]);
    }

    /**
     * Push unlock event to active viewers (immediate, not batched).
     */
    public function cellUnlocked(int $groupfolderId, int $fileId, string $fieldName): void {
        $this->broadcast($groupfolderId, 'metavox_cell_unlocked', [
            'gfId' => $groupfolderId,
            'fileId' => $fileId,
            'fieldName' => $fieldName,
        ]);
    }

    /**
     * Broadcast a push event to active viewers of a groupfolder.
     * Falls back to all groupfolder members if no presence data exists.
     */
    private function broadcast(int $groupfolderId, string $message, array $body = []): void {
        if (!$this->notifyQueue) return;
        try {
            $activeViewers = $this->presenceService->getActiveViewers($groupfolderId);

            // Fallback with caching: if no presence data, use cached member list
            $userIds = !empty($activeViewers) ? $activeViewers : $this->getCachedGroupfolderUserIds($groupfolderId);

            foreach ($userIds as $userId) {
                $this->notifyQueue->push('notify_custom', [
                    'user' => $userId,
                    'message' => $message,
                    'body' => $body ?: null,
                ]);
            }
        } catch (\Exception $e) {
            // Silently fail — push is best-effort
        }
    }

    /**
     * Get all user IDs for a groupfolder with 5-minute cache.
     */
    private function getCachedGroupfolderUserIds(int $groupfolderId): array {
        $cacheKey = "gf_{$groupfolderId}_members";
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) return $decoded;
        }

        $userIds = $this->getGroupfolderUserIds($groupfolderId);
        $this->cache->set($cacheKey, json_encode($userIds), 300);
        return $userIds;
    }

    /**
     * Get all user IDs that have access to a groupfolder.
     */
    private function getGroupfolderUserIds(int $groupfolderId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('group_id')
           ->from('group_folders_groups')
           ->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $groupIds = [];
        while ($row = $result->fetch()) {
            $groupIds[] = $row['group_id'];
        }
        $result->closeCursor();

        $userIds = [];
        foreach ($groupIds as $groupId) {
            $group = $this->groupManager->get($groupId);
            if ($group) {
                foreach ($group->getUsers() as $user) {
                    $userIds[$user->getUID()] = true;
                }
            }
        }
        return array_keys($userIds);
    }
}
