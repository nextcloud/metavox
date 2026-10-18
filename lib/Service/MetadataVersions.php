<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\ICache;
use OCP\ICacheFactory;

/**
 * A stamp per team folder that changes with every change of its metadata.
 * Caches of values put it in their key, so they are fresh right after a
 * change. A random stamp, not a counter: one the cache lost can't come back.
 */
class MetadataVersions {

    private const ALL = 'all';

    private ICache $cache;

    public function __construct(ICacheFactory $cacheFactory) {
        $this->cache = $cacheFactory->createDistributed('metavox_versions');
    }

    public function get(int $groupfolderId): string {
        return $this->stamp(self::ALL) . '.' . $this->stamp((string)$groupfolderId);
    }

    public function bump(int $groupfolderId): void {
        $this->cache->set((string)$groupfolderId, self::newStamp());
    }

    /** Every team folder at once, for a change that isn't tied to one. */
    public function bumpAll(): void {
        $this->cache->set(self::ALL, self::newStamp());
    }

    private function stamp(string $key): string {
        $stamp = $this->cache->get($key);
        if (!\is_string($stamp)) {
            $stamp = self::newStamp();
            $this->cache->set($key, $stamp);
        }
        return $stamp;
    }

    private static function newStamp(): string {
        return bin2hex(random_bytes(6));
    }
}
