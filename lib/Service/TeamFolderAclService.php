<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * The advanced permissions (ACL) of a team folder, read through groupfolders'
 * own classes, the same way its search leaves out what a user can't read.
 */
class TeamFolderAclService {

    public function __construct(
        private readonly IUserManager $userManager,
        private readonly GroupfolderRootService $roots,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Whether the groupfolders app is there: without it no team folder file opens. */
    public function available(): bool {
        return class_exists(\OCA\GroupFolders\Folder\FolderManager::class);
    }

    /** Null when the team folder can't be read: then treat it as having an ACL. */
    public function isEnabled(int $groupfolderId): ?bool {
        $folder = $this->folder($groupfolderId);
        return $folder === null ? null : (bool)GroupFolderAccessor::get($folder, 'acl', false);
    }

    /**
     * The filecache paths in the team folder the user can't read, everything
     * below them included. Null when groupfolders can't tell: before version 20,
     * and with "no permission by default", where only allowed paths show. A
     * caller that knows that setting from its folder list saves the lookup.
     *
     * @return list<array{storage: int, path: string}>|null
     */
    public function forbiddenPaths(string $userId, int $groupfolderId, ?bool $defaultNoPermission = null): ?array {
        $user = $this->userManager->get($userId);
        if ($user === null) {
            return null;
        }
        if ($defaultNoPermission === null) {
            $folder = $this->folder($groupfolderId);
            if ($folder === null) {
                return null;
            }
            $defaultNoPermission = (bool)GroupFolderAccessor::get($folder, 'aclDefaultNoPermission', false);
        }
        if ($defaultNoPermission) {
            return null;
        }
        try {
            $acl = \OCP\Server::get(\OCA\GroupFolders\ACL\ACLManagerFactory::class)->getACLManager($user);
            if (!method_exists($acl, 'getForbiddenPaths')) {
                return null;
            }
            $forbidden = [];
            foreach ($this->roots->getRoots($groupfolderId) as $root) {
                foreach ($acl->getForbiddenPaths($root['storage'], $root['path']) as $path) {
                    $forbidden[] = ['storage' => $root['storage'], 'path' => $path];
                }
            }
            return $forbidden;
        } catch (\Throwable $e) {
            $this->logger->warning('MetaVox: team folder ACL could not be read', ['exception' => $e, 'groupfolderId' => $groupfolderId]);
            return null;
        }
    }

    /** @return array|object|null groupfolders' folder: an array before version 20, an object after */
    private function folder(int $groupfolderId): array|object|null {
        try {
            return \OCP\Server::get(\OCA\GroupFolders\Folder\FolderManager::class)->getFolder($groupfolderId);
        } catch (\Throwable $e) {
            $this->logger->warning('MetaVox: team folder could not be read', ['exception' => $e, 'groupfolderId' => $groupfolderId]);
            return null;
        }
    }
}
