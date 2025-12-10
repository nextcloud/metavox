<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IGroupManager;

class PermissionController extends Controller {

    private PermissionService $permissionService;
    private IUserSession $userSession;
    private IGroupManager $groupManager;

    public function __construct(
        string $appName,
        IRequest $request,
        PermissionService $permissionService,
        IUserSession $userSession,
        IGroupManager $groupManager
    ) {
        parent::__construct($appName, $request);
        $this->permissionService = $permissionService;
        $this->userSession = $userSession;
        $this->groupManager = $groupManager;
    }

    /**
     * Check if current user is admin
     */
    private function isAdmin(): bool {
        $user = $this->userSession->getUser();
        if (!$user) {
            return false;
        }
        return $this->groupManager->isAdmin($user->getUID());
    }

    /**
     * Get all groups (admin only)
     * @NoCSRFRequired
     */
    public function getGroups(): JSONResponse {
        if (!$this->isAdmin()) {
            return new JSONResponse(['error' => 'Unauthorized'], 403);
        }

        try {
            $groups = $this->groupManager->search('');
            $groupList = [];
            
            foreach ($groups as $group) {
                $groupList[] = [
                    'id' => $group->getGID(),
                    'displayname' => $group->getDisplayName(),
                ];
            }
            
            return new JSONResponse($groupList);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get all permissions (admin only)
     * @NoCSRFRequired
     */
    public function getAllPermissions(): JSONResponse {
        if (!$this->isAdmin()) {
            return new JSONResponse(['error' => 'Unauthorized'], 403);
        }

        try {
            $permissions = $this->permissionService->getAllPermissions();
            return new JSONResponse($permissions);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get current user's permissions
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getMyPermissions(): JSONResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'Not logged in'], 401);
        }

        try {
            $permissions = $this->permissionService->getUserPermissions($user->getUID());
            return new JSONResponse($permissions);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Check if current user has specific permission
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function checkPermission(
        string $permissionType,
        ?int $groupfolderId = null,
        ?string $fieldScope = null
    ): JSONResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['hasPermission' => false], 401);
        }

        try {
            $hasPermission = $this->permissionService->hasPermission(
                $user->getUID(),
                $permissionType,
                $groupfolderId,
                $fieldScope
            );
            return new JSONResponse(['hasPermission' => $hasPermission]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Grant permission to user (admin only)
     */
    public function grantUserPermission(): JSONResponse {
        if (!$this->isAdmin()) {
            return new JSONResponse(['error' => 'Unauthorized'], 403);
        }

        $userId = $this->request->getParam('user_id');
        $permissionType = $this->request->getParam('permission_type');
        $groupfolderId = $this->request->getParam('groupfolder_id');
        $fieldScope = $this->request->getParam('field_scope');

        if (!$userId || !$permissionType) {
            return new JSONResponse(['error' => 'Missing required parameters'], 400);
        }

        try {
            $success = $this->permissionService->grantUserPermission(
                $userId,
                $permissionType,
                $groupfolderId ? (int)$groupfolderId : null,
                $fieldScope ?: null
            );

            if ($success) {
                return new JSONResponse(['success' => true]);
            } else {
                return new JSONResponse(['error' => 'Failed to grant permission'], 500);
            }
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Grant permission to group (admin only)
     */
    public function grantGroupPermission(): JSONResponse {
        if (!$this->isAdmin()) {
            return new JSONResponse(['error' => 'Unauthorized'], 403);
        }

        $groupId = $this->request->getParam('group_id');
        $permissionType = $this->request->getParam('permission_type');
        $groupfolderId = $this->request->getParam('groupfolder_id');
        $fieldScope = $this->request->getParam('field_scope');

        if (!$groupId || !$permissionType) {
            return new JSONResponse(['error' => 'Missing required parameters'], 400);
        }

        try {
            $success = $this->permissionService->grantGroupPermission(
                $groupId,
                $permissionType,
                $groupfolderId ? (int)$groupfolderId : null,
                $fieldScope ?: null
            );

            if ($success) {
                return new JSONResponse(['success' => true]);
            } else {
                return new JSONResponse(['error' => 'Failed to grant permission'], 500);
            }
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Revoke user permission (admin only)
     */
    public function revokeUserPermission(int $permissionId): JSONResponse {
        if (!$this->isAdmin()) {
            return new JSONResponse(['error' => 'Unauthorized'], 403);
        }

        // For now, we'll use the simpler approach of deleting by parameters
        $userId = $this->request->getParam('user_id');
        $permissionType = $this->request->getParam('permission_type');
        $groupfolderId = $this->request->getParam('groupfolder_id');
        $fieldScope = $this->request->getParam('field_scope');

        if (!$userId || !$permissionType) {
            return new JSONResponse(['error' => 'Missing required parameters'], 400);
        }

        try {
            $success = $this->permissionService->revokeUserPermission(
                $userId,
                $permissionType,
                $groupfolderId ? (int)$groupfolderId : null,
                $fieldScope ?: null
            );

            if ($success) {
                return new JSONResponse(['success' => true]);
            } else {
                return new JSONResponse(['error' => 'Failed to revoke permission'], 500);
            }
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Revoke group permission (admin only)
     */
    public function revokeGroupPermission(int $permissionId): JSONResponse {
        if (!$this->isAdmin()) {
            return new JSONResponse(['error' => 'Unauthorized'], 403);
        }

        $groupId = $this->request->getParam('group_id');
        $permissionType = $this->request->getParam('permission_type');
        $groupfolderId = $this->request->getParam('groupfolder_id');
        $fieldScope = $this->request->getParam('field_scope');

        if (!$groupId || !$permissionType) {
            return new JSONResponse(['error' => 'Missing required parameters'], 400);
        }

        try {
            $success = $this->permissionService->revokeGroupPermission(
                $groupId,
                $permissionType,
                $groupfolderId ? (int)$groupfolderId : null,
                $fieldScope ?: null
            );

            if ($success) {
                return new JSONResponse(['success' => true]);
            } else {
                return new JSONResponse(['error' => 'Failed to revoke permission'], 500);
            }
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }
}