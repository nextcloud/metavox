<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\PermissionService;
use OCA\MetaVox\Service\ViewService;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

class ApiViewController extends OCSController {

    private FieldService $fieldService;
    private ViewService $viewService;
    private PermissionService $permissionService;
    private IUserSession $userSession;

    public function __construct(
        string $appName,
        IRequest $request,
        FieldService $fieldService,
        ViewService $viewService,
        PermissionService $permissionService,
        IUserSession $userSession
    ) {
        parent::__construct($appName, $request);
        $this->fieldService = $fieldService;
        $this->viewService = $viewService;
        $this->permissionService = $permissionService;
        $this->userSession = $userSession;
    }

    /**
     * List views for a groupfolder.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @CORS
     */
    public function listViews(int $groupfolderId): DataResponse {
        try {
            $user = $this->userSession->getUser();
            $canManage = $user
                ? $this->permissionService->hasPermission($user->getUID(), PermissionService::PERM_MANAGE_FIELDS, $groupfolderId)
                : false;

            $views = $this->viewService->getViewsForGroupfolder($groupfolderId);

            // Enrich view columns with full field data
            $allFields = $this->fieldService->getAllFields();
            $fieldMap = [];
            foreach ($allFields as $field) {
                $fieldMap[$field['id']] = $field;
            }
            foreach ($views as &$view) {
                foreach ($view['columns'] as &$col) {
                    $fieldId = (int)($col['field_id'] ?? 0);
                    if (isset($fieldMap[$fieldId])) {
                        $f = $fieldMap[$fieldId];
                        $col['field_name']    = $f['field_name'];
                        $col['field_label']   = $f['field_label'] ?? $f['field_name'];
                        $col['field_type']    = $f['field_type'];
                        $col['field_options'] = $f['field_options'] ?? [];
                    }
                }
                unset($col);
            }
            unset($view);

            $response = new DataResponse(['views' => $views, 'can_manage' => $canManage], Http::STATUS_OK);
            $response->addHeader('Cache-Control', 'private, max-age=600');
            return $response;
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create a view for a groupfolder.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @CORS
     */
    public function createView(int $groupfolderId): DataResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
            }
            if (!$this->permissionService->hasPermission($user->getUID(), PermissionService::PERM_MANAGE_FIELDS, $groupfolderId)) {
                return new DataResponse(['error' => 'Manage fields permission required'], Http::STATUS_FORBIDDEN);
            }

            $name = $this->request->getParam('name');
            if (empty($name)) {
                return new DataResponse(['error' => 'name is required'], Http::STATUS_BAD_REQUEST);
            }

            $view = $this->viewService->createView(
                $groupfolderId,
                $name,
                (bool)$this->request->getParam('is_default', false),
                $this->request->getParam('columns', []),
                $this->request->getParam('filters', []),
                $this->request->getParam('sort_field'),
                $this->request->getParam('sort_order')
            );
            return new DataResponse($view, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update a view.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @CORS
     */
    public function updateView(int $groupfolderId, int $viewId): DataResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
            }
            if (!$this->permissionService->hasPermission($user->getUID(), PermissionService::PERM_MANAGE_FIELDS, $groupfolderId)) {
                return new DataResponse(['error' => 'Manage fields permission required'], Http::STATUS_FORBIDDEN);
            }

            $name = $this->request->getParam('name');
            if (empty($name)) {
                return new DataResponse(['error' => 'name is required'], Http::STATUS_BAD_REQUEST);
            }

            $existing = $this->viewService->getView($viewId, $groupfolderId);
            if ($existing === null) {
                return new DataResponse(['error' => 'View not found'], Http::STATUS_NOT_FOUND);
            }

            $position = $this->request->getParam('position');
            $position = $position !== null ? (int)$position : null;

            $view = $this->viewService->updateView(
                $viewId,
                $groupfolderId,
                $name,
                (bool)$this->request->getParam('is_default', false),
                $this->request->getParam('columns', []),
                $this->request->getParam('filters', []),
                $this->request->getParam('sort_field'),
                $this->request->getParam('sort_order'),
                $position
            );
            return new DataResponse($view, Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Reorder views for a groupfolder.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @CORS
     */
    public function reorderViews(int $groupfolderId): DataResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
            }
            if (!$this->permissionService->hasPermission($user->getUID(), PermissionService::PERM_MANAGE_FIELDS, $groupfolderId)) {
                return new DataResponse(['error' => 'Manage fields permission required'], Http::STATUS_FORBIDDEN);
            }

            $viewIds = $this->request->getParam('view_ids', []);
            if (empty($viewIds) || !is_array($viewIds)) {
                return new DataResponse(['error' => 'view_ids array is required'], Http::STATUS_BAD_REQUEST);
            }

            $this->viewService->reorderViews($groupfolderId, $viewIds);
            return new DataResponse(['success' => true], Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete a view.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @CORS
     */
    public function deleteView(int $groupfolderId, int $viewId): DataResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
            }
            if (!$this->permissionService->hasPermission($user->getUID(), PermissionService::PERM_MANAGE_FIELDS, $groupfolderId)) {
                return new DataResponse(['error' => 'Manage fields permission required'], Http::STATUS_FORBIDDEN);
            }

            $existing = $this->viewService->getView($viewId, $groupfolderId);
            if ($existing === null) {
                return new DataResponse(['error' => 'View not found'], Http::STATUS_NOT_FOUND);
            }

            $this->viewService->deleteView($viewId, $groupfolderId);
            return new DataResponse(['success' => true], Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
