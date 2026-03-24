<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\DB\QueryBuilder\IQueryBuilder;

class PushService {

    private IDBConnection $db;
    private IGroupManager $groupManager;
    private PresenceService $presenceService;
    private $notifyQueue = null;

    public function __construct(
        IDBConnection $db,
        IGroupManager $groupManager,
        PresenceService $presenceService
    ) {
        $this->db = $db;
        $this->groupManager = $groupManager;
        $this->presenceService = $presenceService;

        // Optional: notify_push for real-time metadata sync
        try {
            $this->notifyQueue = \OC::$server->get(\OCA\NotifyPush\Queue\IQueue::class);
        } catch (\Exception $e) {
            // notify_push not installed — real-time sync disabled
        }
    }

    /**
     * Push metadata changed event to active viewers.
     */
    public function metadataChanged(int $groupfolderId, int $fileId, ?string $fieldName = null, ?string $value = null): void {
        $payload = [
            'gfId' => $groupfolderId,
            'fileId' => $fileId,
        ];
        if ($fieldName !== null) {
            $payload['fieldName'] = $fieldName;
            $payload['value'] = $value;
        }
        $this->broadcast($groupfolderId, 'metavox_metadata_changed', $payload);
    }

    /**
     * Push lock event to active viewers.
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
     * Push unlock event to active viewers.
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

            // Fallback: if no presence data, push to all members
            $userIds = !empty($activeViewers) ? $activeViewers : $this->getGroupfolderUserIds($groupfolderId);

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
