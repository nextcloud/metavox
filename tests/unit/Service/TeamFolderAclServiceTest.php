<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\GroupfolderRootService;
use OCA\MetaVox\Service\TeamFolderAclService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TeamFolderAclServiceTest extends TestCase {

    private function service(?IUser $user): TeamFolderAclService {
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturn($user);
        $roots = $this->createMock(GroupfolderRootService::class);
        // Asking the roots means asking groupfolders for the paths.
        $roots->expects($this->never())->method('getRoots');
        return new TeamFolderAclService($users, $roots, $this->createMock(LoggerInterface::class));
    }

    /** "No permission by default": only allowed paths show, so there is no list of forbidden ones. */
    public function testNoPermissionByDefaultHasNoForbiddenPaths(): void {
        $this->assertNull($this->service($this->createMock(IUser::class))->forbiddenPaths('alice', 9, true));
    }

    public function testAnUnknownUserHasNone(): void {
        $this->assertNull($this->service(null)->forbiddenPaths('ghost', 9, false));
    }
}
