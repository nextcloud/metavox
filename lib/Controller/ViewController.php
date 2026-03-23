<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\PermissionService;
use OCA\MetaVox\Service\PresenceService;
use OCA\MetaVox\Service\UserFieldService;
use OCA\MetaVox\Service\ViewService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;

class ViewController extends BaseController {

    private PresenceService $presenceService;
    private UserFieldService $userFieldService;
    private ViewService $viewService;

    public function __construct(
        string $appName,
        IRequest $request,
        FieldService $fieldService,
        PermissionService $permissionService,
        PresenceService $presenceService,
        UserFieldService $userFieldService,
        ViewService $viewService,
        IUserSession $userSession,
        IRootFolder $rootFolder
    ) {
        parent::__construct($appName, $request, $userSession, $permissionService, $fieldService, $rootFolder);
        $this->presenceService = $presenceService;
        $this->userFieldService = $userFieldService;
        $this->viewService = $viewService;
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
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create a new view for a groupfolder
     */
    public function createView(int $gfId): JSONResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof JSONResponse) return $user;
            if ($deny = $this->requireManagePermission($user->getUID(), $gfId)) return $deny;

            $name = $this->request->getParam('name');
            if (empty($name)) {
                return new JSONResponse(['error' => 'View name is required'], Http::STATUS_BAD_REQUEST);
            }

            $isDefault = (bool)$this->request->getParam('is_default', false);
            $columns   = $this->request->getParam('columns', []);
            $filters   = $this->request->getParam('filters', []);
            $sortField = $this->request->getParam('sort_field');
            $sortOrder = $this->request->getParam('sort_order');

            $view = $this->viewService->createView($gfId, $name, $isDefault, $columns, $filters, $sortField, $sortOrder);
            return new JSONResponse($view, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update an existing view
     */
    public function updateView(int $gfId, int $viewId): JSONResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof JSONResponse) return $user;
            if ($deny = $this->requireManagePermission($user->getUID(), $gfId)) return $deny;

            $name = $this->request->getParam('name');
            if (empty($name)) {
                return new JSONResponse(['error' => 'View name is required'], Http::STATUS_BAD_REQUEST);
            }

            $existing = $this->viewService->getView($viewId, $gfId);
            if ($existing === null) {
                return new JSONResponse(['error' => 'View not found'], Http::STATUS_NOT_FOUND);
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
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Reorder views for a groupfolder
     */
    public function reorderViews(int $gfId): JSONResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof JSONResponse) return $user;
            if ($deny = $this->requireManagePermission($user->getUID(), $gfId)) return $deny;

            $viewIds = $this->request->getParam('view_ids', []);
            if (empty($viewIds) || !is_array($viewIds)) {
                return new JSONResponse(['error' => 'view_ids array is required'], Http::STATUS_BAD_REQUEST);
            }

            $this->viewService->reorderViews($gfId, $viewIds);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete a view
     */
    public function deleteView(int $gfId, int $viewId): JSONResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof JSONResponse) return $user;
            if ($deny = $this->requireManagePermission($user->getUID(), $gfId)) return $deny;

            $existing = $this->viewService->getView($viewId, $gfId);
            if ($existing === null) {
                return new JSONResponse(['error' => 'View not found'], Http::STATUS_NOT_FOUND);
            }

            $this->viewService->deleteView($viewId, $gfId);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Single-call initialization for the files plugin.
     * Returns groupfolders + fields + views + filter values in one response.
     */
    #[NoAdminRequired]
    public function init(): JSONResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof JSONResponse) return $user;

            $userId = $user->getUID();
            $dir = $this->request->getParam('dir', '');

            $groupfolders = $this->userFieldService->getAccessibleGroupfolders($userId);

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
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
