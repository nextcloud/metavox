<?php

declare(strict_types=1);

namespace OCA\MetaVox\Access;

/**
 * A team folder as the user sees it in Files: where it is mounted and what its
 * ACL hides there. A null scope: groupfolders can't tell, so each file is
 * looked up on its own. Built by FileAccessService::visibleFolders().
 */
final class VisibleFolder {

    public function __construct(
        public readonly int $id,
        public readonly string $mountPoint,
        public readonly ?ReadableScope $scope,
    ) {
    }
}
