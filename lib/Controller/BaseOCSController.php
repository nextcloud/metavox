<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\GroupfolderResolver;
use OCA\MetaVox\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

abstract class BaseOCSController extends OCSController {

    protected IUserSession $userSession;
    protected PermissionService $permissionService;
    protected FieldService $fieldService;
    protected IRootFolder $rootFolder;
    protected GroupfolderResolver $groupfolderResolver;

    public function __construct(
        string $appName,
        IRequest $request,
        IUserSession $userSession,
        PermissionService $permissionService,
        FieldService $fieldService,
        IRootFolder $rootFolder,
        GroupfolderResolver $groupfolderResolver
    ) {
        parent::__construct($appName, $request);
        $this->userSession = $userSession;
        $this->permissionService = $permissionService;
        $this->fieldService = $fieldService;
        $this->rootFolder = $rootFolder;
        $this->groupfolderResolver = $groupfolderResolver;
    }

    /**
     * Resolve the groupfolder a file physically lives in, from the USER's mount
     * (the same source of truth the Files UI uses), or null if the user cannot
     * see the file OR it is not inside any team folder.
     *
     * This is user-scoped by design: it reuses exactly the node the access
     * check (canUserAccessFile) resolves, so it can never grant more reach than
     * that check already allows. Callers use it instead of guessing the folder
     * from the metadata table — a file can carry stale rows under folder 0 or
     * deleted folders, so the table cannot answer "which folder is this file in
     * right now" (issue #98).
     */
    protected function resolveUserGroupfolderId(int $fileId, string $userId): ?int {
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $nodes = $userFolder->getById($fileId);
            if (empty($nodes)) {
                return null;
            }
            return $this->groupfolderResolver->getGroupfolderId($nodes[0]);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get the authenticated user or return a 401 response.
     * @return IUser|DataResponse
     */
    protected function requireUser(): IUser|DataResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        return $user;
    }

    /**
     * Verify user has access to a groupfolder or return a 403 response.
     */
    protected function requireGroupfolderAccess(string $userId, int $groupfolderId): ?DataResponse {
        if (!$this->fieldService->hasAccessToGroupfolder($userId, $groupfolderId)) {
            return new DataResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN);
        }
        return null;
    }

    /**
     * Verify user has manage_fields permission or return a 403 response.
     */
    protected function requireManagePermission(string $userId, ?int $groupfolderId = null): ?DataResponse {
        if (!$this->permissionService->hasPermission($userId, PermissionService::PERM_MANAGE_FIELDS, $groupfolderId)) {
            return new DataResponse(['error' => 'Manage fields permission required'], Http::STATUS_FORBIDDEN);
        }
        return null;
    }

    /**
     * Filter file IDs to only those the user can access.
     * Respects ACL restrictions within groupfolders.
     */
    protected function filterAccessibleFileIds(array $fileIds, string $userId): array {
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $accessibleIds = [];
            foreach ($fileIds as $fileId) {
                $nodes = $userFolder->getById($fileId);
                if (!empty($nodes)) {
                    $accessibleIds[] = $fileId;
                }
            }
            return $accessibleIds;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Filter file IDs and collect permissions in one pass.
     * Returns ['accessible' => int[], 'permissions' => [fileId => int]]
     */
    protected function filterAccessibleFileIdsWithPermissions(array $fileIds, string $userId): array {
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $accessible = [];
            $permissions = [];
            foreach ($fileIds as $fileId) {
                $nodes = $userFolder->getById($fileId);
                if (!empty($nodes)) {
                    $accessible[] = $fileId;
                    $permissions[$fileId] = $nodes[0]->getPermissions();
                }
            }
            return ['accessible' => $accessible, 'permissions' => $permissions];
        } catch (\Exception $e) {
            return ['accessible' => [], 'permissions' => []];
        }
    }

    /**
     * For a set of file ids, return [fileId => groupfolderId] for those the
     * user can access AND that physically live in a team folder. Files the user
     * cannot see, or that are not in any team folder, are omitted. One
     * user-scoped getById per file (GroupfolderResolver caches mount points for
     * the request, so the folder resolution itself is pure string matching).
     *
     * @param int[] $fileIds
     * @return array<int, int> fileId => groupfolderId
     */
    protected function resolveAccessibleFileGroupfolders(array $fileIds, string $userId): array {
        $result = [];
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            foreach ($fileIds as $fileId) {
                $nodes = $userFolder->getById($fileId);
                if (empty($nodes)) {
                    continue;
                }
                $gfId = $this->groupfolderResolver->getGroupfolderId($nodes[0]);
                if ($gfId !== null) {
                    $result[$fileId] = $gfId;
                }
            }
        } catch (\Exception $e) {
            return [];
        }
        return $result;
    }

    /**
     * Check if user has access to a specific file.
     */
    protected function canUserAccessFile(int $fileId, string $userId): bool {
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $nodes = $userFolder->getById($fileId);
            return !empty($nodes);
        } catch (\Exception $e) {
            return false;
        }
    }
}
