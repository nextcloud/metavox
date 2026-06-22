<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\GroupfolderResolver;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the pure path-matching logic of GroupfolderResolver — no DB needed.
 * Mirrors the original FileCopyListener behaviour we extracted: mount-point
 * match with an __groupfolders/{id}/ storage fallback.
 */
class GroupfolderResolverTest extends TestCase {

    private GroupfolderResolver $resolver;

    protected function setUp(): void {
        $this->resolver = new GroupfolderResolver(
            $this->createMock(IDBConnection::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testMatchesByMountPoint(): void {
        $mountPoints = [
            ['id' => 7, 'mount_point' => 'Projects'],
            ['id' => 9, 'mount_point' => 'HR'],
        ];
        $this->assertSame(
            9,
            $this->resolver->resolveFromPath('/admin/files/HR/contract.pdf', $mountPoints)
        );
    }

    public function testFirstMatchingMountPointWins(): void {
        $mountPoints = [
            ['id' => 1, 'mount_point' => 'Projects'],
        ];
        $this->assertSame(
            1,
            $this->resolver->resolveFromPath('/u/files/Projects/sub/x.docx', $mountPoints)
        );
    }

    public function testFallsBackToInternalGroupfoldersPattern(): void {
        // No mount point matches, but the internal storage path encodes the id.
        $this->assertSame(
            42,
            $this->resolver->resolveFromPath('/__groupfolders/42/report.pdf', [])
        );
    }

    public function testReturnsNullWhenNotInAnyGroupfolder(): void {
        $mountPoints = [
            ['id' => 7, 'mount_point' => 'Projects'],
        ];
        $this->assertNull(
            $this->resolver->resolveFromPath('/admin/files/Personal/note.txt', $mountPoints)
        );
    }

    public function testEmptyMountPointIsIgnored(): void {
        // A blank mount point must never match every path.
        $mountPoints = [
            ['id' => 5, 'mount_point' => ''],
        ];
        $this->assertNull(
            $this->resolver->resolveFromPath('/admin/files/whatever.txt', $mountPoints)
        );
    }
}
