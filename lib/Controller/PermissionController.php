<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Access\Access;
use OCA\MetaVox\Access\RequireAccess;
use OCA\MetaVox\Exception\RequestException;
use OCA\MetaVox\L10n\Translatable;
use OCA\MetaVox\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IGroupManager;

class PermissionController extends Controller {

    use ReadsParams;

    private PermissionService $permissionService;
    private IGroupManager $groupManager;

    public function __construct(
        string $appName,
        IRequest $request,
        PermissionService $permissionService,
        IGroupManager $groupManager
    ) {
        parent::__construct($appName, $request);
        $this->permissionService = $permissionService;
        $this->groupManager = $groupManager;
    }

    /**
     * Get all groups (admin only)
     */
    #[NoCSRFRequired]
    #[RequireAccess(Access::NcAdmin)]
    public function getGroups(): JSONResponse {
        $groups = $this->groupManager->search('');
        $groupList = [];

        foreach ($groups as $group) {
            $groupList[] = [
                'id' => $group->getGID(),
                'displayname' => $group->getDisplayName(),
            ];
        }

        return new JSONResponse($groupList);
    }

    /**
     * Get all permissions (admin only)
     */
    #[NoCSRFRequired]
    #[RequireAccess(Access::NcAdmin)]
    public function getAllPermissions(): JSONResponse {
        $permissions = $this->permissionService->getAllPermissions();
        return new JSONResponse($permissions);
    }

    /**
     * Grant the manage permission to an account (admin only). Without a team
     * folder it holds for all of them.
     */
    #[RequireAccess(Access::NcAdmin)]
    public function grantUserPermission(): JSONResponse {
        $userId = $this->textParam('user_id', required: true);
        $this->permissionService->grantUserPermission($userId, $this->permissionTypeParam(), $this->groupfolderParam());
        return new JSONResponse(['success' => true]);
    }

    /**
     * Grant the manage permission to a group (admin only). Without a team
     * folder it holds for all of them.
     */
    #[RequireAccess(Access::NcAdmin)]
    public function grantGroupPermission(): JSONResponse {
        $groupId = $this->textParam('group_id', required: true);
        $this->permissionService->grantGroupPermission($groupId, $this->permissionTypeParam(), $this->groupfolderParam());
        return new JSONResponse(['success' => true]);
    }

    /** The one permission MetaVox has. */
    private function permissionTypeParam(): string {
        $this->requireParam('permission_type');
        return $this->choiceParam('permission_type', [PermissionService::PERM_MANAGE_FIELDS]);
    }

    /** Left out: all team folders. 0, "" or "abc" is no team folder, and no "all" either. */
    private function groupfolderParam(): ?int {
        return $this->intParam('groupfolder_id', min: 1);
    }

    /**
     * Revoke a user's grant by its id (admin only).
     */
    #[RequireAccess(Access::NcAdmin)]
    public function revokeUserPermission(int $permissionId): JSONResponse {
        return $this->revoke($permissionId, PermissionService::HOLDER_USER);
    }

    /**
     * Revoke a group's grant by its id (admin only).
     */
    #[RequireAccess(Access::NcAdmin)]
    public function revokeGroupPermission(int $permissionId): JSONResponse {
        return $this->revoke($permissionId, PermissionService::HOLDER_GROUP);
    }

    private function revoke(int $permissionId, string $holder): JSONResponse {
        if (!$this->permissionService->revokePermission($permissionId, $holder)) {
            // TRANSLATORS Error when an admin removes a permission that no longer exists
            throw RequestException::notFound(Translatable::t('Permission not found'));
        }
        return new JSONResponse(['success' => true]);
    }
}