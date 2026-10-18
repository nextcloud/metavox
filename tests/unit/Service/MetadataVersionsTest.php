<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\MetadataVersions;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

class MetadataVersionsTest extends TestCase {

    /** what the distributed cache holds */
    private array $stored = [];

    public function testTheStampStaysUntilAChange(): void {
        $versions = $this->versions();

        $this->assertSame($versions->get(2), $versions->get(2));
    }

    public function testAChangeTouchesOnlyItsTeamFolder(): void {
        $versions = $this->versions();
        [$two, $three] = [$versions->get(2), $versions->get(3)];

        $versions->bump(2);

        $this->assertNotSame($two, $versions->get(2));
        $this->assertSame($three, $versions->get(3));
    }

    public function testAChangeOfAllTouchesEveryTeamFolder(): void {
        $versions = $this->versions();
        [$two, $three] = [$versions->get(2), $versions->get(3)];

        $versions->bumpAll();

        $this->assertNotSame($two, $versions->get(2));
        $this->assertNotSame($three, $versions->get(3));
    }

    public function testAStampTheCacheLostDoesNotComeBack(): void {
        $versions = $this->versions();
        $before = $versions->get(2);

        $this->stored = [];

        $this->assertNotSame($before, $versions->get(2));
    }

    private function versions(): MetadataVersions {
        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturnCallback(fn (string $key) => $this->stored[$key] ?? null);
        $cache->method('set')->willReturnCallback(function (string $key, $value): bool {
            $this->stored[$key] = $value;
            return true;
        });
        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('createDistributed')->with('metavox_versions')->willReturn($cache);
        return new MetadataVersions($factory);
    }
}
