<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Exception\MetadataAccessException;
use OCA\MetaVox\Service\GroupfolderResolver;
use OCA\MetaVox\Service\GroupfolderRootService;
use OCA\MetaVox\Service\FileAccessService;
use OCA\MetaVox\Service\TeamFolderAccessService;
use OCA\MetaVox\Service\TeamFolderAclService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountPoint;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class FileAccessServiceTest extends TestCase {

    private const TEAM_FOLDER = 9;

    /** @var array<int, array{node: File, groupfolderId: ?int}> */
    private array $nodes = [];
    private bool $isAdmin = false;
    private ?int $filecacheGroupfolderId = null;

    /** Team folder rights are the other service's; only whether the user is an admin matters here. */
    private function teamFolders(\Closure $isAdmin): TeamFolderAccessService {
        $teamFolders = $this->createMock(TeamFolderAccessService::class);
        $teamFolders->method('isAdmin')->willReturnCallback($isAdmin);
        return $teamFolders;
    }

    private function service(): FileAccessService {
        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('getById')->willReturnCallback(fn () => array_column($this->nodes, 'node'));
        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->method('getUserFolder')->willReturn($userFolder);

        $resolver = $this->createMock(GroupfolderResolver::class);
        $resolver->method('getGroupfolderId')->willReturnCallback(function ($node) {
            foreach ($this->nodes as $entry) {
                if ($entry['node'] === $node) {
                    return $entry['groupfolderId'];
                }
            }
            return null;
        });

        $roots = $this->createMock(GroupfolderRootService::class);
        $roots->method('getGroupfolderIdForFile')->willReturnCallback(fn () => $this->filecacheGroupfolderId);

        return new FileAccessService($rootFolder, $resolver, $roots, $this->createMock(TeamFolderAclService::class), $this->teamFolders(fn () => $this->isAdmin), $this->createMock(IDBConnection::class));
    }

    private function addNode(?int $groupfolderId, bool $updateable): void {
        $node = $this->createMock(File::class);
        $node->method('isUpdateable')->willReturn($updateable);
        $this->nodes[] = ['node' => $node, 'groupfolderId' => $groupfolderId];
    }

    private function assertDenied(int $status, callable $call): void {
        try {
            $call();
            $this->fail('Expected MetadataAccessException');
        } catch (MetadataAccessException $e) {
            $this->assertSame($status, $e->getStatus());
        }
    }

    public function testMemberWithWriteAccessMayChange(): void {
        $this->addNode(self::TEAM_FOLDER, true);

        $this->assertSame(self::TEAM_FOLDER, $this->service()->requireWritableFile('alice', 42));
    }

    public function testReadOnlyMemberMayReadButNotChange(): void {
        // e.g. a read-only group or an ACL rule without write
        $this->addNode(self::TEAM_FOLDER, false);
        $service = $this->service();

        $this->assertSame(self::TEAM_FOLDER, $service->requireReadableFile('alice', 42));
        $this->assertDenied(403, fn () => $service->requireWritableFile('alice', 42));
    }

    public function testTeamFolderNodeDecidesWhenAlsoShared(): void {
        // A writable share of the file sorts first, but the team folder node
        // (read-only here) is what counts.
        $this->addNode(null, true);
        $this->addNode(self::TEAM_FOLDER, false);

        $access = $this->service()->getFileAccess('alice', 42);

        $this->assertSame(self::TEAM_FOLDER, $access->groupfolderId);
        $this->assertFalse($access->canWrite);
    }

    public function testShareOnlyGivesNoAccess(): void {
        $this->addNode(null, true);

        $this->assertDenied(404, fn () => $this->service()->requireReadableFile('bob', 42));
    }

    public function testInvisibleFileIsNotFound(): void {
        $this->assertDenied(404, fn () => $this->service()->requireReadableFile('bob', 42));
    }

    public function testWrongTeamFolderIsRejected(): void {
        $this->addNode(self::TEAM_FOLDER, true);

        $this->assertDenied(400, fn () => $this->service()->requireWritableFile('alice', 42, 3));
    }

    public function testAdminMayChangeDespiteReadOnlyMembership(): void {
        $this->isAdmin = true;
        $this->addNode(self::TEAM_FOLDER, false);

        $this->assertSame(self::TEAM_FOLDER, $this->service()->requireWritableFile('admin', 42));
    }

    public function testAdminWithoutMembershipMayChangeFilesInATeamFolder(): void {
        $this->isAdmin = true;
        $this->filecacheGroupfolderId = self::TEAM_FOLDER;

        $access = $this->service()->getFileAccess('admin', 42);

        $this->assertSame(self::TEAM_FOLDER, $access->groupfolderId);
        $this->assertTrue($access->canWrite);
        $this->assertNull($access->node);
    }

    public function testAdminGetsNoAccessOutsideTeamFolders(): void {
        $this->isAdmin = true;

        $this->assertDenied(404, fn () => $this->service()->requireReadableFile('admin', 42));
    }

    public function testFilterWritableFilesSplitsTheBatch(): void {
        $this->addNode(self::TEAM_FOLDER, true);
        $service = $this->service();
        $service->getFileAccess('alice', 1);
        $this->nodes = [];
        $this->addNode(self::TEAM_FOLDER, false);
        $service->getFileAccess('alice', 2);

        $result = $service->filterWritableFiles('alice', [1, 2]);

        $this->assertSame([1 => self::TEAM_FOLDER], $result['allowed']);
        $this->assertSame([2], array_keys($result['denied']));
        $this->assertSame(403, $result['denied'][2]->getStatus());
    }

    /** Files 1 (writable), 2 (read-only) and 3 (not visible) in one service. */
    private function serviceWithThreeFiles(): FileAccessService {
        $service = $this->service();
        foreach ([1 => true, 2 => false] as $fileId => $updateable) {
            $this->nodes = [];
            $this->addNode(self::TEAM_FOLDER, $updateable);
            $service->getFileAccess('alice', $fileId);
        }
        $this->nodes = [];
        $service->getFileAccess('alice', 3);
        return $service;
    }

    public function testAuthorizeBatchChecksEveryItem(): void {
        $items = [
            ['file_id' => 1, 'metadata' => ['status' => 'Draft']],
            ['file_id' => 1, 'groupfolder_id' => 99],
            ['file_id' => 2],
            ['metadata' => []],
        ];

        $result = $this->serviceWithThreeFiles()->authorizeBatch('alice', $items, true);

        $this->assertSame([['file_id' => 1, 'metadata' => ['status' => 'Draft'], 'groupfolder_id' => self::TEAM_FOLDER]], $result['allowed']);
        $this->assertSame([1, 2, null], array_column($result['denied'], 'file_id'));
        $this->assertSame('Missing file_id', $result['denied'][2]['error']);
    }

    public function testAuthorizeBatchForReadingAllowsReadOnlyFiles(): void {
        $result = $this->serviceWithThreeFiles()->authorizeBatch('alice', [['file_id' => 2]], false);

        $this->assertSame([['file_id' => 2, 'groupfolder_id' => self::TEAM_FOLDER]], $result['allowed']);
        $this->assertSame([], $result['denied']);
    }

    /** $expectLookup: whether the service may still ask the root folder. */
    private function primingService(bool $expectLookup, ?int $groupfolderId = self::TEAM_FOLDER): FileAccessService {
        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('getById')->willReturn([]);
        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->expects($expectLookup ? $this->once() : $this->never())
            ->method('getUserFolder')->willReturn($userFolder);

        $resolver = $this->createMock(GroupfolderResolver::class);
        $resolver->method('getGroupfolderId')->willReturn($groupfolderId);

        return new FileAccessService($rootFolder, $resolver, $this->createMock(GroupfolderRootService::class), $this->createMock(TeamFolderAclService::class), $this->teamFolders(fn () => false), $this->createMock(IDBConnection::class));
    }

    private function listedNode(string $path, string $mountPoint, bool $updateable): File {
        $mount = $this->createMock(IMountPoint::class);
        $mount->method('getMountPoint')->willReturn($mountPoint);
        $node = $this->createMock(File::class);
        $node->method('getId')->willReturn(42);
        $node->method('getPath')->willReturn($path);
        $node->method('getMountPoint')->willReturn($mount);
        $node->method('isUpdateable')->willReturn($updateable);
        return $node;
    }

    public function testPrimedNodeSparesTheLookup(): void {
        $service = $this->primingService(false);
        $primed = $service->primeFromNode('alice', $this->listedNode('/alice/files/HR/a.pdf', '/alice/files/HR/', true));

        $access = $service->getFileAccess('alice', 42);

        $this->assertSame($primed, $access);
        $this->assertSame(self::TEAM_FOLDER, $access->groupfolderId);
        $this->assertTrue($access->canWrite);
    }

    public function testMountRootIsNotPrimed(): void {
        $service = $this->primingService(true);
        $primed = $service->primeFromNode('alice', $this->listedNode('/alice/files/HR', '/alice/files/HR/', false));

        $this->assertNull($primed);
        $service->getFileAccess('alice', 42);
    }

    public function testNodeOutsideTeamFolderIsNotPrimed(): void {
        $service = $this->primingService(true, null);

        $this->assertNull($service->primeFromNode('alice', $this->listedNode('/alice/files/Shared/a.pdf', '/alice/files/Shared/', true)));
        $service->getFileAccess('alice', 42);
    }

    public function testPrimeReturnsTheCachedVerdict(): void {
        $service = $this->primingService(false);
        $first = $service->primeFromNode('alice', $this->listedNode('/alice/files/HR/a.pdf', '/alice/files/HR/', true));

        $second = $service->primeFromNode('alice', $this->listedNode('/alice/files/HR/a.pdf', '/alice/files/HR/', false));

        $this->assertSame($first, $second);
    }

    public function testPrimeReturnsNullOnError(): void {
        $node = $this->createMock(File::class);
        $node->method('getId')->willReturn(42);
        $node->method('getMountPoint')->willThrowException(new \RuntimeException());

        $this->assertNull($this->primingService(false)->primeFromNode('alice', $node));
    }

    public function testIsMountRoot(): void {
        $this->assertTrue(FileAccessService::isMountRoot($this->listedNode('/alice/files/HR', '/alice/files/HR/', true)));
        $this->assertFalse(FileAccessService::isMountRoot($this->listedNode('/alice/files/HR/a.pdf', '/alice/files/HR/', true)));
    }

    /**
     * Team folder 9 ("HR") holds files 1-4. $acl: null when groupfolders can't
     * tell. $forbidden: null when it can't list forbidden paths, so the search
     * decides; $searchFinds is what that search returns.
     */
    private function scopeService(?bool $acl, ?array $forbidden = null, array $searchFinds = [], bool $admin = false, bool $member = true): FileAccessService {
        $teamFolderAcl = $this->createMock(TeamFolderAclService::class);
        $teamFolderAcl->expects($admin ? $this->never() : $this->any())->method('isEnabled')->willReturn($acl);
        $teamFolderAcl->method('forbiddenPaths')->willReturn($forbidden);
        $teamFolderAcl->method('available')->willReturn(true);
        $hr = $this->createMock(Folder::class);
        $hr->method('search')->with('')->willReturn(array_map(function (int $id) {
            $node = $this->createMock(File::class);
            $node->method('getId')->willReturn($id);
            return $node;
        }, $searchFinds));
        $teamFolders = $this->teamFolders(fn () => $admin);
        $teamFolders->method('teamFolderRoot')->willReturn($member ? $hr : null);
        $roots = $this->createMock(GroupfolderRootService::class);
        $roots->method('getFileIds')->willReturn([1, 2, 3, 4]);

        return new FileAccessService($this->createMock(IRootFolder::class), $this->createMock(GroupfolderResolver::class), $roots, $teamFolderAcl, $teamFolders, $this->createMock(IDBConnection::class));
    }

    /** The scope as SQL, to see what it keeps out. */
    private function scopeSql(FileAccessService $service, ?array &$params = null): ?string {
        $params = [];
        $types = [];
        return $service->readableScope('alice', self::TEAM_FOLDER)->sql('file_id', $params, $types);
    }

    public function testWithoutAclEverythingIsReadable(): void {
        $this->assertTrue($this->scopeService(acl: false)->readableScope('alice', self::TEAM_FOLDER)->isEverything());
    }

    public function testAdminsReadEverythingWithoutAskingTheAcl(): void {
        $this->assertTrue($this->scopeService(acl: true, forbidden: [['storage' => 8, 'path' => 'files/x']], admin: true)
            ->readableScope('root', self::TEAM_FOLDER)->isEverything());
    }

    public function testTheAclForbiddenPathsAreLeftOut(): void {
        $sql = $this->scopeSql($this->scopeService(acl: true, forbidden: [['storage' => 8, 'path' => 'files/secret']]), $params);

        $this->assertStringContainsString('NOT IN (SELECT fileid', (string)$sql);
        $this->assertSame([8, 'files/secret', 'files/secret/%'], $params);
    }

    public function testAnUnknownAclStateCountsAsAcl(): void {
        $sql = $this->scopeSql($this->scopeService(acl: null, forbidden: [['storage' => 8, 'path' => 'files/secret']]));

        $this->assertNotNull($sql);
    }

    public function testWithoutForbiddenPathsNextcloudsSearchDecides(): void {
        $sql = $this->scopeSql($this->scopeService(acl: true, forbidden: null, searchFinds: [1, 2]), $params);

        $this->assertSame('(file_id NOT IN (?, ?))', $sql);
        $this->assertSame([3, 4], $params);
    }

    /** @return array<int, ?string> folder id => the scope as SQL ('all' when nothing is left out, null when unknown) */
    private function visible(FileAccessService $service, array $folders): array {
        $out = [];
        foreach ($service->visibleFolders('alice', $folders) as $folder) {
            $params = [];
            $types = [];
            $out[$folder->id] = $folder->scope === null ? null : ($folder->scope->sql('fileid', $params, $types) ?? 'all') . ' ' . json_encode($params);
        }
        return $out;
    }

    public function testASearchSeesAFolderWithoutAclWhole(): void {
        $folders = $this->visible($this->scopeService(acl: false), [['id' => self::TEAM_FOLDER, 'mount_point' => '/HR/']]);

        $this->assertSame([self::TEAM_FOLDER => 'all []'], $folders);
    }

    public function testASearchLeavesOutWhatTheAclForbids(): void {
        $folders = $this->visible($this->scopeService(acl: true, forbidden: [['storage' => 8, 'path' => 'files/secret']]), [['id' => self::TEAM_FOLDER, 'mount_point' => 'HR']]);

        $this->assertStringContainsString('[8,"files\/secret","files\/secret\/%"]', (string)$folders[self::TEAM_FOLDER]);
    }

    /** Unlike readableScope: a search result has to open, for an admin as well. */
    public function testAnAdminsSearchAlsoLeavesOutWhatTheAclForbids(): void {
        $service = $this->scopeService(acl: true, forbidden: [['storage' => 8, 'path' => 'files/secret']], admin: true);

        $this->assertNotSame('all []', $this->visible($service, [['id' => self::TEAM_FOLDER, 'mount_point' => 'HR', 'acl' => true]])[self::TEAM_FOLDER]);
    }

    public function testWhereGroupfoldersCantTellEachFileIsLookedUp(): void {
        $folders = $this->visible($this->scopeService(acl: true, forbidden: null), [['id' => self::TEAM_FOLDER, 'mount_point' => 'HR']]);

        $this->assertSame([self::TEAM_FOLDER => null], $folders);
    }

    public function testTheFolderListsAclSettingsGoAlongAndTheRootsComeAtOnce(): void {
        $teamFolderAcl = $this->createMock(TeamFolderAclService::class);
        $teamFolderAcl->expects($this->never())->method('isEnabled');
        $teamFolderAcl->expects($this->once())->method('forbiddenPaths')->with('alice', 9, false)->willReturn([]);
        $teamFolderAcl->method('available')->willReturn(true);
        $roots = $this->createMock(GroupfolderRootService::class);
        $roots->expects($this->once())->method('primeRoots')->with([9, 3]);
        $service = new FileAccessService($this->createMock(IRootFolder::class), $this->createMock(GroupfolderResolver::class), $roots,
            $teamFolderAcl, $this->teamFolders(fn () => false), $this->createMock(IDBConnection::class));

        $service->visibleFolders('alice', [
            ['id' => 9, 'mount_point' => 'HR', 'acl' => true, 'acl_default_no_permission' => false],
            ['id' => 3, 'mount_point' => 'IT', 'acl' => false],
        ]);
    }

    /** "No permission by default": only allowed paths show, which groupfolders can't list. */
    public function testWithNoPermissionByDefaultEachFileIsLookedUp(): void {
        $teamFolderAcl = $this->createMock(TeamFolderAclService::class);
        $teamFolderAcl->method('available')->willReturn(true);
        $teamFolderAcl->expects($this->once())->method('forbiddenPaths')->with('alice', 9, true)->willReturn(null);
        $service = new FileAccessService($this->createMock(IRootFolder::class), $this->createMock(GroupfolderResolver::class), $this->createMock(GroupfolderRootService::class),
            $teamFolderAcl, $this->teamFolders(fn () => false), $this->createMock(IDBConnection::class));

        $folders = $service->visibleFolders('alice', [['id' => 9, 'mount_point' => 'HR', 'acl' => true, 'acl_default_no_permission' => true]]);

        $this->assertNull($folders[0]->scope);
    }

    /** MetaVox's own list of team folders still names them, but none of their files opens. */
    public function testWithoutGroupfoldersASearchHasNoTeamFolders(): void {
        $teamFolderAcl = $this->createMock(TeamFolderAclService::class);
        $teamFolderAcl->method('available')->willReturn(false);
        $service = new FileAccessService($this->createMock(IRootFolder::class), $this->createMock(GroupfolderResolver::class), $this->createMock(GroupfolderRootService::class),
            $teamFolderAcl, $this->teamFolders(fn () => true), $this->createMock(IDBConnection::class));

        $this->assertSame([], $service->visibleFolders('root', [['id' => 9, 'mount_point' => 'HR', 'acl' => false]]));
    }

    public function testTheFolderListsAclFlagSavesAskingGroupfolders(): void {
        $teamFolderAcl = $this->createMock(TeamFolderAclService::class);
        $teamFolderAcl->expects($this->never())->method('isEnabled');
        $teamFolderAcl->expects($this->never())->method('forbiddenPaths');
        $teamFolderAcl->method('available')->willReturn(true);
        $service = new FileAccessService($this->createMock(IRootFolder::class), $this->createMock(GroupfolderResolver::class), $this->createMock(GroupfolderRootService::class),
            $teamFolderAcl, $this->teamFolders(fn () => false), $this->createMock(IDBConnection::class));

        $folders = $service->visibleFolders('alice', [['id' => '9', 'mount_point' => '/HR', 'acl' => false], ['id' => 0, 'mount_point' => 'kapot']]);

        $this->assertCount(1, $folders);
        $this->assertSame(9, $folders[0]->id);
        $this->assertSame('HR', $folders[0]->mountPoint);
        $this->assertTrue($folders[0]->scope->isEverything());
    }

    public function testAUserWithoutTheTeamFolderReadsNothingThere(): void {
        $this->scopeSql($this->scopeService(acl: true, forbidden: null, searchFinds: [1, 2], member: false), $params);

        $this->assertSame([1, 2, 3, 4], $params);
    }
}
