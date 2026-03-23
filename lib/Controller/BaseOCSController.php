<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Service\FieldService;
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

    public function __construct(
        string $appName,
        IRequest $request,
        IUserSession $userSession,
        PermissionService $permissionService,
        FieldService $fieldService,
        IRootFolder $rootFolder
    ) {
        parent::__construct($appName, $request);
        $this->userSession = $userSession;
        $this->permissionService = $permissionService;
        $this->fieldService = $fieldService;
        $this->rootFolder = $rootFolder;
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
