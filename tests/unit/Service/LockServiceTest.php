<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\LockService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

/** unlock() may announce a free cell, but never over someone else's lock. */
class LockServiceTest extends TestCase {

    private function service(?string $lockHolder, bool $expectRemove): LockService {
        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturn($lockHolder);
        $cache->expects($expectRemove ? $this->once() : $this->never())->method('remove');

        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('createDistributed')->willReturn($cache);
        return new LockService($factory);
    }

    public function testReleasesOwnLock(): void {
        $this->assertTrue($this->service('alice', true)->unlock(9, 42, 'status', 'alice'));
    }

    public function testExpiredLockCountsAsFree(): void {
        $this->assertTrue($this->service(null, false)->unlock(9, 42, 'status', 'alice'));
    }

    public function testKeepsAndReportsSomeoneElsesLock(): void {
        $this->assertFalse($this->service('bob', false)->unlock(9, 42, 'status', 'alice'));
    }
}
