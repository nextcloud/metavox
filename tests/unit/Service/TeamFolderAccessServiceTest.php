<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\GroupfolderResolver;
use OCA\MetaVox\Service\PermissionService;
use OCA\MetaVox\Service\TeamFolderAccessService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

class TeamFolderAccessServiceTest extends TestCase {

    private const TEAM_FOLDER = 9;

    public function testMembershipAndAdminAskTheServices(): void {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturnCallback(fn ($uid) => $uid === 'root');
        $fieldService = $this->createMock(FieldService::class);
        $fieldService->method('hasAccessToGroupfolder')->willReturnCallback(fn ($uid, $gf) => $uid === 'member' && $gf === self::TEAM_FOLDER);
        $service = new TeamFolderAccessService(
            $this->createMock(IRootFolder::class),
            $groupManager,
            $this->createMock(GroupfolderResolver::class),
            $this->createMock(PermissionService::class),
            $fieldService,
        );

        $this->assertTrue($service->isAdmin('root'));
        $this->assertFalse($service->isAdmin('member'));
        $this->assertTrue($service->isMember('member', self::TEAM_FOLDER));
        $this->assertFalse($service->isMember('member', 10));
    }

    /**
     * The user has team folder 7 as "Other" (writable) and team folder 9 as
     * "HR". $root: what the user's "HR" is — 'writable', 'read-only',
     * 'missing', or 'personal' (a writable folder that is not the team folder).
     * $folders: false when the user has no team folders at all.
     */
    private function service(string $root, bool $admin = false, bool $folders = true): TeamFolderAccessService {
        $fieldService = $this->createMock(FieldService::class);
        $fieldService->method('hasAccessToGroupfolder')->willReturnCallback(fn ($uid) => $uid !== 'outsider');
        $fieldService->method('getGroupfolders')->willReturn($folders ? [
            ['id' => 7, 'mount_point' => 'Other'],
            ['id' => self::TEAM_FOLDER, 'mount_point' => 'HR'],
        ] : []);
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturnCallback(fn ($uid) => $uid !== 'member');
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn($admin);

        $other = $this->createMock(Folder::class);
        $other->method('isUpdateable')->willReturn(true);
        $hr = $this->createMock(Folder::class);
        $hr->method('isUpdateable')->willReturn($root !== 'read-only');
        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('get')->willReturnCallback(fn ($path) => match (true) {
            $path === 'Other' => $other,
            $path === 'HR' && $root !== 'missing' => $hr,
            default => throw new NotFoundException(),
        });
        $rootFolder = $this->createMock(IRootFolder::class);
        // Admins never need the lookup.
        $rootFolder->expects($admin ? $this->never() : $this->any())->method('getUserFolder')->willReturn($userFolder);
        $resolver = $this->createMock(GroupfolderResolver::class);
        $resolver->method('getGroupfolderId')->willReturnCallback(fn ($node) => match (true) {
            $node === $other => 7,
            $root === 'personal' => null,
            default => self::TEAM_FOLDER,
        });

        return new TeamFolderAccessService($rootFolder, $groupManager, $resolver, $permissions, $fieldService);
    }

    public function testManageRequiresMembershipAndManagePermission(): void {
        $service = $this->service('writable');

        $this->assertTrue($service->canManageGroupfolder('manager', self::TEAM_FOLDER));
        $this->assertFalse($service->canManageGroupfolder('member', self::TEAM_FOLDER));
        $this->assertFalse($service->canManageGroupfolder('outsider', self::TEAM_FOLDER));
    }

    public function testUpdatingTheTeamFolderNeedsWriteAccessNotTheGrant(): void {
        $this->assertTrue($this->service('writable')->canUpdateTeamFolder('member', self::TEAM_FOLDER));
        $this->assertFalse($this->service('read-only')->canUpdateTeamFolder('member', self::TEAM_FOLDER));
        $this->assertFalse($this->service('writable')->canUpdateTeamFolder('outsider', self::TEAM_FOLDER));
        $this->assertFalse($this->service('missing')->canUpdateTeamFolder('member', self::TEAM_FOLDER));
        $this->assertTrue($this->service('read-only', admin: true)->canUpdateTeamFolder('member', self::TEAM_FOLDER));
    }

    public function testManageRequiresWriteAccessOnTheTeamFolderRoot(): void {
        $this->assertFalse($this->service('read-only')->canManageGroupfolder('manager', self::TEAM_FOLDER));
    }

    public function testManageIsRefusedWhenTheRootIsNotFound(): void {
        $this->assertFalse($this->service('missing')->canManageGroupfolder('manager', self::TEAM_FOLDER));
    }

    public function testAPersonalFolderWithTheSameNameDoesNotCount(): void {
        $this->assertFalse($this->service('personal')->canManageGroupfolder('manager', self::TEAM_FOLDER));
    }

    public function testManageIsRefusedForATeamFolderTheUserDoesNotHave(): void {
        $this->assertFalse($this->service('writable')->canManageGroupfolder('manager', 10));
    }

    public function testAdminMayManageWithoutLookingAtTheRoot(): void {
        $this->assertTrue($this->service('read-only', admin: true)->canManageGroupfolder('manager', self::TEAM_FOLDER));
    }

    public function testManagingOneFolderIsEnoughForTheManagerSettings(): void {
        $this->assertTrue($this->service('writable')->managesAnyFolder('manager'));
        $this->assertFalse($this->service('writable')->managesAnyFolder('member'));
        $this->assertTrue($this->service('read-only', admin: true, folders: false)->managesAnyFolder('root'));
    }

    public function testManagersAndAdminsSeeTheWholeCatalogue(): void {
        $this->assertNull($this->service('writable')->catalogueFolders('manager'));
        $this->assertNull($this->service('read-only', admin: true)->catalogueFolders('root'));
    }

    public function testAnAdminWithoutTeamFoldersStillSeesTheWholeCatalogue(): void {
        $this->assertNull($this->service('missing', admin: true, folders: false)->catalogueFolders('root'));
    }

    public function testOthersSeeTheFieldsOfTheirOwnTeamFolders(): void {
        $this->assertSame([7, self::TEAM_FOLDER], $this->service('writable')->catalogueFolders('member'));
    }

    public function testTheTeamFolderRootAsTheUserSeesIt(): void {
        $this->assertNotNull($this->service('writable')->teamFolderRoot('member', self::TEAM_FOLDER));
        $this->assertNull($this->service('personal')->teamFolderRoot('member', self::TEAM_FOLDER));
        $this->assertNull($this->service('writable')->teamFolderRoot('member', 10));
    }
}
