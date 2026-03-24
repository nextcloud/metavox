<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\ICacheFactory;
use OCP\ICache;

class PresenceService {

    private const TTL = 1800; // 30 minutes
    private const REGISTER_COOLDOWN = 300; // 5 minutes — skip JSON parsing if recently registered
    private ICache $cache;

    public function __construct(ICacheFactory $cacheFactory) {
        $this->cache = $cacheFactory->createDistributed('metavox_presence');
    }

    /**
     * Register a user's presence in a groupfolder.
     *
     * Uses a per-user cooldown key to avoid expensive JSON parsing on every request.
     * The heavy JSON blob update only happens once per 5 minutes per user.
     */
    public function register(int $groupfolderId, string $userId): void {
        try {
            // Fast path: skip if this user registered recently (1 cheap Redis GET)
            $cooldownKey = "gf_{$groupfolderId}_pr_{$userId}";
            if ($this->cache->get($cooldownKey) !== null) {
                return;
            }

            // Mark as recently registered (5 min cooldown)
            $this->cache->set($cooldownKey, '1', self::REGISTER_COOLDOWN);

            // Update the shared presence map (only 1x per 5 min per user)
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
            // Clear cooldown so next visit re-registers
            $this->cache->remove("gf_{$groupfolderId}_pr_{$userId}");

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
