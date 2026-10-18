<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\PresenceService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IMemcache;
use OCP\IMemcacheTTL;
use PHPUnit\Framework\TestCase;

/** Who has the grid of a team folder open: registered by a listing, kept alive by Nextcloud's heartbeat. */
class PresenceServiceTest extends TestCase {

    private object $cache;
    private int $now = 1_000_000;

    private function service(bool $statusApp = true): PresenceService {
        // An in-memory memcache with expiry, add and compare-and-swap like Redis.
        $this->cache ??= new class($this->now) implements IMemcache, IMemcacheTTL {
            public array $data = [];
            public array $expires = [];
            public int $writes = 0;
            public ?\Closure $beforeCas = null;

            public function __construct(public int $now) {
            }

            public function get($key) {
                if (isset($this->expires[$key]) && $this->expires[$key] <= $this->now) {
                    unset($this->data[$key], $this->expires[$key]);
                }
                return $this->data[$key] ?? null;
            }

            public function set($key, $value, $ttl = 0) {
                $this->writes++;
                $this->data[$key] = $value;
                $ttl > 0 ? $this->expires[$key] = $this->now + $ttl : $this->expires[$key] = null;
                return true;
            }

            public function hasKey($key) {
                return $this->get($key) !== null;
            }

            public function remove($key) {
                unset($this->data[$key], $this->expires[$key]);
                return true;
            }

            public function clear($prefix = '') {
                $this->data = [];
                return true;
            }

            public static function isAvailable(): bool {
                return true;
            }

            public function add($key, $value, $ttl = 0) {
                return $this->get($key) === null && $this->set($key, $value, $ttl);
            }

            public function inc($key, $step = 1) {
                return false;
            }

            public function dec($key, $step = 1) {
                return false;
            }

            public function cas($key, $old, $new) {
                if ($this->beforeCas !== null) {
                    ($this->beforeCas)($this, $key);
                }
                if ($this->get($key) !== $old) {
                    return false;
                }
                $this->writes++;
                $this->data[$key] = $new;
                unset($this->expires[$key]); // like Redis: cas drops the expiry
                return true;
            }

            public function cad($key, $old) {
                return false;
            }

            public function ncad(string $key, mixed $old): bool {
                return false;
            }

            public function setTTL(string $key, int $ttl) {
                $this->expires[$key] = $this->now + $ttl;
            }

            public function getTTL(string $key): int|false {
                return isset($this->expires[$key]) ? $this->expires[$key] - $this->now : false;
            }

            public function compareSetTTL(string $key, $value, int $ttl): bool {
                if ($this->get($key) !== $value) {
                    return false;
                }
                $this->expires[$key] = $this->now + $ttl;
                return true;
            }
        };
        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('createDistributed')->willReturn($this->cache);
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn(): int => $this->now);
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->with('user_status')->willReturn($statusApp);
        return new PresenceService($factory, $time, $apps);
    }

    private function later(int $seconds): void {
        $this->now += $seconds;
        $this->cache->now = $this->now;
    }

    public function testAListingMakesYouPresent(): void {
        $presence = $this->service();
        $presence->register(5, 'alice');
        $presence->register(5, 'bob');

        $this->assertSame(['alice', 'bob'], $presence->getActiveViewers(5));
        $this->assertSame([], $presence->getActiveViewers(6));
    }

    public function testWithinTheCooldownNothingIsWritten(): void {
        $presence = $this->service();
        $presence->register(5, 'alice');
        $writes = $this->cache->writes;
        $presence->register(5, 'alice');

        $this->assertSame($writes, $this->cache->writes);
    }

    public function testNextcloudsHeartbeatKeepsYouPresentInTheFolderYouHaveOpen(): void {
        $presence = $this->service();
        $presence->register(5, 'alice');
        for ($i = 0; $i < 6; $i++) {
            $this->later(300);
            $presence->keepAlive('alice');
        }

        $this->assertSame(['alice'], $presence->getActiveViewers(5));
        $this->assertSame([], $presence->getActiveViewers(6));
    }

    public function testWithoutHeartbeatYouAreGoneAfterFifteenMinutes(): void {
        $presence = $this->service();
        $presence->register(5, 'alice');
        $this->later(899);
        $this->assertSame(['alice'], $presence->getActiveViewers(5));
        $this->later(2);

        $this->assertSame([], $presence->getActiveViewers(5));
    }

    public function testWithoutTheStatusAppAPresenceLastsTwelveHours(): void {
        $presence = $this->service(false);
        $presence->register(5, 'alice');
        $this->later(11 * 3600);

        $this->assertSame(['alice'], $presence->getActiveViewers(5));
    }

    public function testLeavingEndsItAndTheHeartbeatDoesNotBringItBack(): void {
        $presence = $this->service();
        $presence->register(5, 'alice');
        $presence->register(6, 'alice');
        $presence->remove(5, 'alice');
        $this->later(300);
        $presence->keepAlive('alice');

        $this->assertSame([], $presence->getActiveViewers(5));
        $this->assertSame(['alice'], $presence->getActiveViewers(6));
    }

    public function testAChangeAtTheSameTimeIsNotLost(): void {
        $presence = $this->service();
        $presence->register(5, 'alice');
        // Another request registers bob between our read and our write, once.
        $this->cache->beforeCas = function ($cache, string $key): void {
            if ($key === 'gf_5' && !str_contains((string)$cache->data[$key], 'bob')) {
                $cache->data[$key] = json_encode(json_decode($cache->data[$key], true) + ['bob' => $this->now]);
            }
        };
        $presence->register(5, 'carol');

        $this->assertEqualsCanonicalizing(['alice', 'bob', 'carol'], $presence->getActiveViewers(5));
    }

    public function testTheMapKeepsAnExpiryAfterCompareAndSwap(): void {
        $presence = $this->service();
        $presence->register(5, 'alice');
        $presence->register(5, 'bob');

        $this->assertSame(1800, $this->cache->getTTL('gf_5'));
    }

    public function testANumericUserIdStaysAString(): void {
        $presence = $this->service();
        $presence->register(5, '123');

        $this->assertSame(['123'], $presence->getActiveViewers(5));
    }
}
