<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\GroupFolderAccessor;
use PHPUnit\Framework\TestCase;

class GroupFolderAccessorTest extends TestCase {

    public function testReadsGroupfolders19UserFolders(): void {
        // FolderManager::getFoldersForUser() on groupfolders 19 (NC 31)
        $folder = ['folder_id' => 7, 'mount_point' => 'HR', 'permissions' => 31, 'quota' => -3, 'acl' => true];

        $this->assertSame(7, GroupFolderAccessor::get($folder, 'id'));
        $this->assertSame('HR', GroupFolderAccessor::get($folder, 'mountPoint'));
    }

    public function testReadsGroupfolders19AllFolders(): void {
        // FolderManager::getAllFolders() on groupfolders 19 (NC 31)
        $folder = ['id' => 7, 'mount_point' => 'HR', 'groups' => ['staff' => 31], 'quota' => -3, 'size' => 0, 'acl' => false];

        $this->assertSame(7, GroupFolderAccessor::get($folder, 'id'));
        $this->assertSame('HR', GroupFolderAccessor::get($folder, 'mountPoint'));
        $this->assertSame(['staff' => 31], GroupFolderAccessor::get($folder, 'groups', []));
    }

    public function testReadsGroupfolders20Objects(): void {
        // FolderDefinition-like object on groupfolders 20+ (NC 32+)
        $folder = new class {
            public int $id = 7;
            public string $mountPoint = 'HR';
            public int $quota = -3;
            public bool $acl = true;
        };

        $this->assertSame(7, GroupFolderAccessor::get($folder, 'id'));
        $this->assertSame('HR', GroupFolderAccessor::get($folder, 'mountPoint'));
        $this->assertTrue(GroupFolderAccessor::get($folder, 'acl'));
    }

    public function testMissingKeyReturnsDefault(): void {
        $this->assertSame([], GroupFolderAccessor::get(['folder_id' => 7], 'groups', []));
        $this->assertNull(GroupFolderAccessor::get(['quota' => -3], 'mountPoint'));
        $this->assertSame('x', GroupFolderAccessor::get('not a folder', 'id', 'x'));
    }

    public function testOtherKeysAreReadAsIs(): void {
        $folder = ['folder_id' => 7, 'mount_point' => 'HR', 'quota' => 1024, 'acl' => false];

        $this->assertSame(1024, GroupFolderAccessor::get($folder, 'quota'));
        $this->assertFalse(GroupFolderAccessor::get($folder, 'acl'));
    }
}
