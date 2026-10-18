<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

require_once __DIR__ . '/../../stubs/GroupMountPoint.php';

use OCA\GroupFolders\Mount\GroupMountPoint;
use OCA\MetaVox\Service\GroupfolderResolver;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Node;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GroupfolderResolverTest extends TestCase {

    private GroupfolderResolver $resolver;

    protected function setUp(): void {
        $this->resolver = new GroupfolderResolver($this->createMock(LoggerInterface::class));
    }

    private function node(IMountPoint $mount, string $path): Node {
        $node = $this->createMock(Node::class);
        $node->method('getMountPoint')->willReturn($mount);
        $node->method('getPath')->willReturn($path);
        return $node;
    }

    private function groupMount(int $folderId): GroupMountPoint {
        $mount = $this->createMock(GroupMountPoint::class);
        $mount->method('getFolderId')->willReturn($folderId);
        return $mount;
    }

    public function testUsesFolderIdOfTeamFolderMount(): void {
        $node = $this->node($this->groupMount(9), '/admin/files/HR/contract.pdf');

        $this->assertSame(9, $this->resolver->getGroupfolderId($node));
    }

    public function testSubfolderNamedLikeAnotherTeamFolderKeepsItsOwnFolder(): void {
        // "HR" (id 9) contains a subfolder "Projects", while "Projects" is also
        // the name of team folder 7. The mount decides, not the path.
        $node = $this->node($this->groupMount(9), '/admin/files/HR/Projects/plan.docx');

        $this->assertSame(9, $this->resolver->getGroupfolderId($node));
    }

    public function testReturnsNullOutsideTeamFolders(): void {
        // Personal files and received shares are not on a team folder mount.
        $node = $this->node($this->createMock(IMountPoint::class), '/admin/files/Projects/note.txt');

        $this->assertNull($this->resolver->getGroupfolderId($node));
    }

    public function testFallsBackToOldLayoutRootPath(): void {
        $node = $this->node($this->createMock(IMountPoint::class), '/__groupfolders/42/report.pdf');

        $this->assertSame(42, $this->resolver->getGroupfolderId($node));
    }

    public function testRootPathFallbackOnlyMatchesAtTheStart(): void {
        $this->assertNull(GroupfolderResolver::resolveFromRootPath('/admin/files/__groupfolders/42/x.pdf'));
        $this->assertSame(42, GroupfolderResolver::resolveFromRootPath('/__groupfolders/42/sub/x.pdf'));
    }

    public function testReturnsNullWhenTheMountCannotBeRead(): void {
        $node = $this->createMock(Node::class);
        $node->method('getMountPoint')->willThrowException(new \RuntimeException('no mount'));

        $this->assertNull($this->resolver->getGroupfolderId($node));
    }
}
