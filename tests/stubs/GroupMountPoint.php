<?php

declare(strict_types=1);

namespace OCA\GroupFolders\Mount;

use OCP\Files\Mount\IMountPoint;

/** Stand-in for the groupfolders mount class, which isn't installed in tests. */
if (!class_exists(GroupMountPoint::class)) {
    abstract class GroupMountPoint implements IMountPoint {
        abstract public function getFolderId(): int;
    }
}
