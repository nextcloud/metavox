<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\ICacheFactory;
use OCP\ICache;

class MetaVoxCacheService {

    private ICache $cache;

    public function __construct(ICacheFactory $cacheFactory) {
        $this->cache = $cacheFactory->createDistributed('metavox_metadata');
    }

    /**
     * Returns cached metadata for a single file, or null if not cached.
     */
    public function getFileMetadata(int $groupfolderId, int $fileId): ?array {
        $cacheKey = "gf_{$groupfolderId}_fm_{$fileId}";
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            $decoded = json_decode($cached, true);
            return is_array($decoded) ? $decoded : [];
        }
        return null;
    }

    /**
     * Sets the full metadata array for a file (1 hour TTL).
     */
    public function setFileMetadata(int $groupfolderId, int $fileId, array $metadata): void {
        $cacheKey = "gf_{$groupfolderId}_fm_{$fileId}";
        $this->cache->set($cacheKey, json_encode($metadata), 3600);
    }

    /**
     * Write-through: updates a single field in the cached metadata.
     * Called after a successful DB write to keep the cache in sync.
     */
    public function updateFileField(int $groupfolderId, int $fileId, string $fieldName, string $value): void {
        $cacheKey = "gf_{$groupfolderId}_fm_{$fileId}";
        $cached = $this->cache->get($cacheKey);
        $meta = $cached ? json_decode($cached, true) : [];
        if (!is_array($meta)) $meta = [];
        $meta[$fieldName] = $value;
        $this->cache->set($cacheKey, json_encode($meta), 3600);
    }

    /**
     * Removes cached metadata for a file.
     */
    public function invalidateFile(int $groupfolderId, int $fileId): void {
        $this->cache->remove("gf_{$groupfolderId}_fm_{$fileId}");
    }
}
