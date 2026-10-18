<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;
use OCP\IMemcacheTTL;

/**
 * Who has the grid of a team folder open, so pushes go only to them.
 *
 * A user is registered when the Files app lists a folder in the team folder,
 * and kept alive by Nextcloud's own status heartbeat (every 5 minutes while a
 * tab is open). They leave when they switch team folder or close the tab.
 * Without the status app there is no heartbeat, so a registration then lasts
 * a lot longer.
 */
class PresenceService {

    private const TTL = 900; // three missed status heartbeats
    private const TTL_WITHOUT_HEARTBEAT = 43200;
    private const REGISTER_COOLDOWN = 240; // below the heartbeat's 5 minutes, so each one counts
    private const RETRIES = 25;

    private ICache $cache;
    private ?int $ttl = null;

    public function __construct(
        ICacheFactory $cacheFactory,
        private readonly ITimeFactory $time,
        private readonly IAppManager $appManager,
    ) {
        $this->cache = $cacheFactory->createDistributed('metavox_presence');
    }

    /** The user has the grid of this team folder open. */
    public function register(int $groupfolderId, string $userId): void {
        try {
            // Mostly one cheap GET: the maps are only written once per cooldown.
            $cooldownKey = "gf_{$groupfolderId}_pr_{$userId}";
            if ($this->cache->get($cooldownKey) !== null) {
                return;
            }
            $this->cache->set($cooldownKey, '1', self::REGISTER_COOLDOWN);

            $now = $this->time->getTime();
            $ttl = $this->ttl();
            $this->change("gf_{$groupfolderId}", static function (array $present) use ($userId, $now, $ttl): array {
                $present = array_filter($present, static fn($since): bool => $now - (int)$since < $ttl);
                $present[$userId] = $now;
                return $present;
            });
            // The team folders this user has open, for the heartbeat.
            $this->change("u_{$userId}", static function (array $folders) use ($groupfolderId, $now, $ttl): array {
                $folders = array_filter($folders, static fn($since): bool => $now - (int)$since < $ttl);
                $folders[$groupfolderId] = $now;
                return $folders;
            });
        } catch (\Exception $e) { /* presence is best-effort */ }
    }

    /** Nextcloud's status heartbeat: the user still has a tab open. */
    public function keepAlive(string $userId): void {
        try {
            $folders = json_decode((string)$this->cache->get("u_{$userId}"), true);
            foreach (array_keys(is_array($folders) ? $folders : []) as $groupfolderId) {
                $this->register((int)$groupfolderId, $userId);
            }
        } catch (\Exception $e) { /* presence is best-effort */ }
    }

    /** The user left the team folder (switched, or closed the tab). */
    public function remove(int $groupfolderId, string $userId): void {
        try {
            $this->cache->remove("gf_{$groupfolderId}_pr_{$userId}");
            $this->change("gf_{$groupfolderId}", static function (array $present) use ($userId): array {
                unset($present[$userId]);
                return $present;
            });
            $this->change("u_{$userId}", static function (array $folders) use ($groupfolderId): array {
                unset($folders[$groupfolderId]);
                return $folders;
            });
        } catch (\Exception $e) { /* presence is best-effort */ }
    }

    /** @return string[] the users with the grid of this team folder open */
    public function getActiveViewers(int $groupfolderId): array {
        try {
            $present = json_decode((string)$this->cache->get("gf_{$groupfolderId}"), true);
            if (!is_array($present)) {
                return [];
            }
            $now = $this->time->getTime();
            $ttl = $this->ttl();
            return array_map('strval', array_keys(array_filter($present, static fn($since): bool => $now - (int)$since < $ttl)));
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Change a map without losing a change another request makes at the same
     * time: compare-and-swap, and try again when someone else was first.
     */
    private function change(string $key, callable $change): void {
        $keyTtl = $this->ttl() * 2;
        for ($try = 0; $try < self::RETRIES; $try++) {
            $raw = $this->cache->get($key);
            $map = is_string($raw) ? json_decode($raw, true) : null;
            $new = json_encode($change(is_array($map) ? $map : []));
            if (!$this->cache instanceof IMemcache) {
                $this->cache->set($key, $new, $keyTtl);
                return;
            }
            if ($raw === null) {
                if ($this->cache->add($key, $new, $keyTtl)) {
                    return;
                }
            } elseif ($this->cache->cas($key, $raw, $new)) {
                // cas keeps no expiry; give it back, unless someone changed the map since.
                if ($this->cache instanceof IMemcacheTTL) {
                    $this->cache->compareSetTTL($key, $new, $keyTtl);
                }
                return;
            }
            // Someone else was first: wait a moment, so retries don't collide again.
            usleep(random_int(100, 2000));
        }
    }

    private function ttl(): int {
        return $this->ttl ??= $this->appManager->isEnabledForUser('user_status') ? self::TTL : self::TTL_WITHOUT_HEARTBEAT;
    }
}
