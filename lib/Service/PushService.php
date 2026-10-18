<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

class PushService {

    private PresenceService $presenceService;
    private $notifyQueue = null;

    /**
     * Files changed in this request, sent as one push per team folder.
     * @var array<int, array<int, true>> groupfolderId => [fileId => true]
     */
    private array $pendingChanges = [];
    private bool $shutdownRegistered = false;

    public function __construct(PresenceService $presenceService) {
        $this->presenceService = $presenceService;

        try {
            $this->notifyQueue = \OCP\Server::get(\OCA\NotifyPush\Queue\IQueue::class);
        } catch (\Throwable $e) {
            // notify_push not installed: push is best effort
        }
    }

    /**
     * Queue a metadata changed event. Batched and sent at end of request.
     * Multiple saves to the same file in one request = 1 push event.
     */
    /**
     * A file's metadata changed. Only ids go out: every member gets the event,
     * so values are fetched through routes that check the file for the reader.
     */
    public function metadataChanged(int $groupfolderId, int $fileId): void {
        $this->pendingChanges[$groupfolderId][$fileId] = true;

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
            $this->broadcast($groupfolderId, 'metavox_metadata_changed', [
                'gfId' => $groupfolderId,
                'fileIds' => array_map('intval', array_keys($files)),
            ]);
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
     * Send a push event to the users who have the grid of this team folder
     * open. Nobody there, no push: whoever opens it later loads fresh values.
     */
    private function broadcast(int $groupfolderId, string $message, array $body = []): void {
        if (!$this->notifyQueue) return;
        try {
            foreach ($this->presenceService->getActiveViewers($groupfolderId) as $userId) {
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
}
