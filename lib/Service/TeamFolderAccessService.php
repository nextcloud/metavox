<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;

/**
 * Who may do what with a team folder: membership, and managing its settings
 * (views, assigned fields, defaults, folder metadata). Admins always may.
 * File metadata is FileAccessService.
 */
class TeamFolderAccessService {

    /** @var array<string, bool> keyed by "uid:groupfolderId" */
    private array $rootWriteCache = [];

    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly IGroupManager $groupManager,
        private readonly GroupfolderResolver $groupfolderResolver,
        private readonly PermissionService $permissionService,
        private readonly FieldService $fieldService,
    ) {
    }

    /** Nextcloud caches the user's groups for the request itself. */
    public function isAdmin(string $userId): bool {
        return (bool)$this->groupManager->isAdmin($userId);
    }

    /** Admins and members of the team folder (through a group or a circle). */
    public function isMember(string $userId, int $groupfolderId): bool {
        return $this->fieldService->hasAccessToGroupfolder($userId, $groupfolderId);
    }

    /**
     * Folder-level metadata and settings (views, assigned fields): admins, and
     * members with the manage permission who may also write in the team
     * folder's root. Read-only there means no settings either.
     */
    /** Views and the team folder's own metadata: whoever may change the team folder. */
    public function canUpdateTeamFolder(string $userId, int $groupfolderId): bool {
        return $this->isMember($userId, $groupfolderId) && $this->canWriteRoot($userId, $groupfolderId);
    }

    public function canManageGroupfolder(string $userId, int $groupfolderId): bool {
        return $this->isMember($userId, $groupfolderId)
            && $this->permissionService->hasPermission($userId, PermissionService::PERM_MANAGE_FIELDS, $groupfolderId)
            && $this->canWriteRoot($userId, $groupfolderId);
    }

    /** Admins, and users who may manage at least one team folder: the manager settings are theirs. */
    public function managesAnyFolder(string $userId): bool {
        return $this->isAdmin($userId) || $this->canManageAnyFolder($userId);
    }

    /**
     * The team folders whose fields the user may see in the field catalogue,
     * or null for all: admins and managers assign fields, so they see every
     * one; everyone else only the fields of their own team folders.
     *
     * @return int[]|null
     */
    public function catalogueFolders(string $userId): ?array {
        if ($this->managesAnyFolder($userId)) {
            return null;
        }
        return array_map(fn (array $folder) => (int)$folder['id'], $this->fieldService->getGroupfolders($userId));
    }

    /** The team folder's root as the user sees it; null when the user doesn't have it. */
    public function teamFolderRoot(string $userId, int $groupfolderId): ?Folder {
        foreach ($this->fieldService->getGroupfolders($userId) as $folder) {
            if ((int)$folder['id'] !== $groupfolderId) {
                continue;
            }
            try {
                $root = $this->rootFolder->getUserFolder($userId)->get((string)$folder['mount_point']);
            } catch (\Exception) {
                return null;
            }
            // A personal folder with the same name is not the team folder.
            return $root instanceof Folder && $this->groupfolderResolver->getGroupfolderId($root) === $groupfolderId ? $root : null;
        }
        return null;
    }

    /** Nextcloud's write permission on the team folder root, ACL included. */
    private function canWriteRoot(string $userId, int $groupfolderId): bool {
        if ($this->isAdmin($userId)) {
            return true;
        }
        $key = $userId . ':' . $groupfolderId;
        if (!isset($this->rootWriteCache[$key])) {
            $this->rootWriteCache[$key] = $this->teamFolderRoot($userId, $groupfolderId)?->isUpdateable() ?? false;
        }
        return $this->rootWriteCache[$key];
    }

    /** Whether the user may manage at least one of their team folders. */
    private function canManageAnyFolder(string $userId): bool {
        foreach ($this->fieldService->getGroupfolders($userId) as $folder) {
            if ($this->canManageGroupfolder($userId, (int)$folder['id'])) {
                return true;
            }
        }
        return false;
    }
}
