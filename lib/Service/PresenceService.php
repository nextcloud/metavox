<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\ICacheFactory;
use OCP\ICache;

class PresenceService {

    private const TTL = 1800; // 30 minutes
    private ICache $cache;

    public function __construct(ICacheFactory $cacheFactory) {
        $this->cache = $cacheFactory->createDistributed('metavox_presence');
    }

    /**
     * Register a user's presence in a groupfolder.
     * Stored as a JSON map of userId => timestamp in a single cache key per groupfolder.
     * Entries older than 30 minutes are pruned on each update.
     */
    public function register(int $groupfolderId, string $userId): void {
        try {
            $key = "gf_{$groupfolderId}";
            $raw = $this->cache->get($key);
            $presence = $raw ? json_decode($raw, true) : [];
            if (!is_array($presence)) $presence = [];

            $now = time();
            $presence = array_filter($presence, fn($ts) => ($now - $ts) < self::TTL);
            $presence[$userId] = $now;

            $this->cache->set($key, json_encode($presence), self::TTL * 2);
        } catch (\Exception $e) { /* ignore */ }
    }

    /**
     * Remove a user's presence from a groupfolder (explicit leave).
     */
    public function remove(int $groupfolderId, string $userId): void {
        try {
            $key = "gf_{$groupfolderId}";
            $raw = $this->cache->get($key);
            $presence = $raw ? json_decode($raw, true) : [];
            if (!is_array($presence)) return;

            unset($presence[$userId]);
            $this->cache->set($key, json_encode($presence), self::TTL * 2);
        } catch (\Exception $e) { /* ignore */ }
    }

    /**
     * Get user IDs with active presence in a groupfolder.
     *
     * @return array Array of userIds with active presence
     */
    public function getActiveViewers(int $groupfolderId): array {
        try {
            $key = "gf_{$groupfolderId}";
            $raw = $this->cache->get($key);
            $presence = $raw ? json_decode($raw, true) : [];
            if (!is_array($presence)) return [];

            $now = time();
            return array_keys(array_filter($presence, fn($ts) => ($now - $ts) < self::TTL));
        } catch (\Exception $e) {
            return [];
        }
    }
}
