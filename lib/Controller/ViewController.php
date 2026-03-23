<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\PermissionService;
use OCA\MetaVox\Service\PresenceService;
use OCA\MetaVox\Service\UserFieldService;
use OCA\MetaVox\Service\ViewService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class ViewController extends Controller {

    private FieldService $fieldService;
    private PresenceService $presenceService;
    private UserFieldService $userFieldService;
    private ViewService $viewService;
    private IUserSession $userSession;
    private PermissionService $permissionService;

    public function __construct(
        string $appName,
        IRequest $request,
        FieldService $fieldService,
        PresenceService $presenceService,
        UserFieldService $userFieldService,
        ViewService $viewService,
        IUserSession $userSession,
        PermissionService $permissionService
    ) {
        parent::__construct($appName, $request);
        $this->fieldService = $fieldService;
        $this->presenceService = $presenceService;
        $this->userFieldService = $userFieldService;
        $this->viewService = $viewService;
        $this->userSession = $userSession;
        $this->permissionService = $permissionService;
    }

    /**
     * List all views for a groupfolder
     */
    #[NoAdminRequired]
    public function listViews(int $gfId): JSONResponse {
        try {
            $user = $this->userSession->getUser();
            $canManage = $user
                ? $this->permissionService->hasPermission($user->getUID(), PermissionService::PERM_MANAGE_FIELDS, $gfId)
                : false;

            $views = $this->viewService->getViewsForGroupfolder($gfId);
            $response = new JSONResponse(['views' => $views, 'can_manage' => $canManage]);
            $response->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
            return $response;
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Create a new view for a groupfolder

     */
    public function createView(int $gfId): JSONResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'User not authenticated'], 401);
            }

            if (!$this->permissionService->hasPermission($user->getUID(), PermissionService::PERM_MANAGE_FIELDS, $gfId)) {
                return new JSONResponse(['error' => 'Manage fields permission required'], 403);
            }

            $name = $this->request->getParam('name');
            if (empty($name)) {
                return new JSONResponse(['error' => 'View name is required'], 400);
            }

            $isDefault = (bool)$this->request->getParam('is_default', false);
            $columns   = $this->request->getParam('columns', []);
            $filters   = $this->request->getParam('filters', []);
            $sortField = $this->request->getParam('sort_field');
            $sortOrder = $this->request->getParam('sort_order');

            $view = $this->viewService->createView($gfId, $name, $isDefault, $columns, $filters, $sortField, $sortOrder);
            return new JSONResponse($view, 201);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update an existing view

     */
    public function updateView(int $gfId, int $viewId): JSONResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'User not authenticated'], 401);
            }

            if (!$this->permissionService->hasPermission($user->getUID(), PermissionService::PERM_MANAGE_FIELDS, $gfId)) {
                return new JSONResponse(['error' => 'Manage fields permission required'], 403);
            }

            $name = $this->request->getParam('name');
            if (empty($name)) {
                return new JSONResponse(['error' => 'View name is required'], 400);
            }

            $existing = $this->viewService->getView($viewId, $gfId);
            if ($existing === null) {
                return new JSONResponse(['error' => 'View not found'], 404);
            }

            $isDefault = (bool)$this->request->getParam('is_default', false);
            $columns   = $this->request->getParam('columns', []);
            $filters   = $this->request->getParam('filters', []);
            $sortField = $this->request->getParam('sort_field');
            $sortOrder = $this->request->getParam('sort_order');
            $position  = $this->request->getParam('position');
            $position  = $position !== null ? (int)$position : null;

            $view = $this->viewService->updateView($viewId, $gfId, $name, $isDefault, $columns, $filters, $sortField, $sortOrder, $position);
            return new JSONResponse($view);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Reorder views for a groupfolder
     *
     */
    public function reorderViews(int $gfId): JSONResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'User not authenticated'], 401);
            }

            if (!$this->permissionService->hasPermission($user->getUID(), PermissionService::PERM_MANAGE_FIELDS, $gfId)) {
                return new JSONResponse(['error' => 'Manage fields permission required'], 403);
            }

            $viewIds = $this->request->getParam('view_ids', []);
            if (empty($viewIds) || !is_array($viewIds)) {
                return new JSONResponse(['error' => 'view_ids array is required'], 400);
            }

            $this->viewService->reorderViews($gfId, $viewIds);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete a view

     */
    public function deleteView(int $gfId, int $viewId): JSONResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'User not authenticated'], 401);
            }

            if (!$this->permissionService->hasPermission($user->getUID(), PermissionService::PERM_MANAGE_FIELDS, $gfId)) {
                return new JSONResponse(['error' => 'Manage fields permission required'], 403);
            }

            $existing = $this->viewService->getView($viewId, $gfId);
            if ($existing === null) {
                return new JSONResponse(['error' => 'View not found'], 404);
            }

            $this->viewService->deleteView($viewId, $gfId);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Single-call initialization for the files plugin.
     * Returns groupfolders + fields + views + filter values in one response.
     */
    #[NoAdminRequired]
    public function init(): JSONResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'Not authenticated'], 401);
            }

            $userId = $user->getUID();
            $dir = $this->request->getParam('dir', '');

            $groupfolders = $this->userFieldService->getAccessibleGroupfolders($userId);

            // Debug: log when groupfolders are empty
            if (empty($groupfolders)) {
                error_log("MetaVox init: 0 groupfolders for user={$userId} dir={$dir}");
            }

            // Detect groupfolder from dir path
            $groupfolderId = null;
            $path = ltrim($dir, '/');
            foreach ($groupfolders as $gf) {
                $mp = $gf['mount_point'] ?? '';
                if ($mp !== '' && ($path === $mp || str_starts_with($path, $mp . '/'))) {
                    $groupfolderId = (int)$gf['id'];
                    break;
                }
            }

            // Register presence for the detected groupfolder
            if ($groupfolderId !== null) {
                $this->presenceService->register($groupfolderId, $userId);
            }

            $result = [
                'groupfolders' => $groupfolders,
                'groupfolder_id' => $groupfolderId,
                'fields' => [],
                'views' => [],
                'can_manage' => false,
            ];

            if ($groupfolderId !== null) {
                $result['fields'] = $this->fieldService->getAssignedFileFieldsForGroupfolder($groupfolderId);
                $result['views'] = $this->viewService->getViewsForGroupfolder($groupfolderId);
                $result['can_manage'] = $this->permissionService->hasPermission(
                    $userId, PermissionService::PERM_MANAGE_FIELDS, $groupfolderId
                );
            }

            $response = new JSONResponse($result);
            $response->addHeader('Cache-Control', 'private, max-age=30');
            return $response;
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }
}
