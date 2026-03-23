<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

abstract class BaseController extends Controller {

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
     * @return IUser|JSONResponse
     */
    protected function requireUser(): IUser|JSONResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        return $user;
    }

    /**
     * Verify user has access to a groupfolder or return a 403 response.
     */
    protected function requireGroupfolderAccess(string $userId, int $groupfolderId): ?JSONResponse {
        if (!$this->fieldService->hasAccessToGroupfolder($userId, $groupfolderId)) {
            return new JSONResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN);
        }
        return null;
    }

    /**
     * Verify user has manage_fields permission or return a 403 response.
     */
    protected function requireManagePermission(string $userId, ?int $groupfolderId = null): ?JSONResponse {
        if (!$this->permissionService->hasPermission($userId, PermissionService::PERM_MANAGE_FIELDS, $groupfolderId)) {
            return new JSONResponse(['error' => 'Manage fields permission required'], Http::STATUS_FORBIDDEN);
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
