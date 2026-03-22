<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\ICacheFactory;
use OCP\ICache;

class LockService {

    private const LOCK_TTL = 30; // 30 seconds
    private ICache $cache;

    public function __construct(ICacheFactory $cacheFactory) {
        $this->cache = $cacheFactory->createDistributed('metavox_locks');
    }

    /**
     * Lock a cell for editing. Returns true if lock acquired, false if already locked by another user.
     */
    public function lock(int $groupfolderId, int $fileId, string $fieldName, string $userId): bool {
        $lockKey = "gf_{$groupfolderId}:{$fileId}:{$fieldName}";
        $existing = $this->cache->get($lockKey);
        if ($existing && $existing !== $userId) {
            return false; // Already locked by another user
        }
        $this->cache->set($lockKey, $userId, self::LOCK_TTL);
        return true;
    }

    /**
     * Unlock a cell after editing. Only removes if locked by this user.
     */
    public function unlock(int $groupfolderId, int $fileId, string $fieldName, string $userId): void {
        $lockKey = "gf_{$groupfolderId}:{$fileId}:{$fieldName}";
        $existing = $this->cache->get($lockKey);
        if ($existing === $userId) {
            $this->cache->remove($lockKey);
        }
    }

    /**
     * Check if a cell is locked.
     *
     * @return string|null The userId holding the lock, or null if unlocked.
     */
    public function getLock(int $groupfolderId, int $fileId, string $fieldName): ?string {
        $lockKey = "gf_{$groupfolderId}:{$fileId}:{$fieldName}";
        $value = $this->cache->get($lockKey);
        return $value ?: null;
    }
}
