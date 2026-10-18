<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Access\Access;
use OCA\MetaVox\Access\RequireAccess;
use OCA\MetaVox\Exception\RequestException;
use OCA\MetaVox\L10n\Translatable;
use OCA\MetaVox\Service\ApiValidationException;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\TeamFolderAccessService;
use OCA\MetaVox\Service\ViewColumnFormat;
use OCA\MetaVox\Service\ViewService;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

class ApiViewController extends OCSController {

    use ReadsParams;
    use ReadsViewParams;

    private FieldService $fieldService;
    private ViewService $viewService;
    private IUserSession $userSession;
    private TeamFolderAccessService $teamFolders;

    public function __construct(
        string $appName,
        IRequest $request,
        FieldService $fieldService,
        ViewService $viewService,
        IUserSession $userSession,
        TeamFolderAccessService $teamFolders
    ) {
        parent::__construct($appName, $request);
        $this->fieldService = $fieldService;
        $this->viewService = $viewService;
        $this->userSession = $userSession;
        $this->teamFolders = $teamFolders;
    }

    /**
     * List views for a groupfolder.
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[RequireAccess(Access::TeamFolderMember, folder: 'groupfolderId')]
    public function listViews(int $groupfolderId): DataResponse {
        $user = $this->userSession->getUser();
        $canManage = $user !== null && $this->teamFolders->canUpdateTeamFolder($user->getUID(), $groupfolderId);

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
    }

    /**
     * Create a view for a groupfolder.
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[RequireAccess(Access::CanUpdateTeamFolder, folder: 'groupfolderId')]
    public function createView(int $groupfolderId): DataResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw RequestException::notLoggedIn();
        }

        $p = $this->viewParams();

        $view = $this->viewService->createView(
            $groupfolderId,
            $p['name'],
            $p['is_default'],
            $p['columns'],
            $p['filters'],
            $p['sort_field'],
            $p['sort_order']
        );
        return new DataResponse($view, Http::STATUS_CREATED);
    }

    /**
     * Update a view.
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[RequireAccess(Access::CanUpdateTeamFolder, folder: 'groupfolderId')]
    public function updateView(int $groupfolderId, int $viewId): DataResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw RequestException::notLoggedIn();
        }

        $p = $this->viewParams();
        $position = $this->positionParam();

        $existing = $this->viewService->getView($viewId, $groupfolderId);
        if ($existing === null) {
            throw RequestException::notFound(Translatable::t('View not found'));
        }

        $view = $this->viewService->updateView(
            $viewId,
            $groupfolderId,
            $p['name'],
            $p['is_default'],
            $p['columns'],
            $p['filters'],
            $p['sort_field'],
            $p['sort_order'],
            (array)($existing['columns'] ?? []),
            $position
        );
        return new DataResponse($view, Http::STATUS_OK);
    }

    /**
     * Reorder views for a groupfolder.
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[RequireAccess(Access::CanUpdateTeamFolder, folder: 'groupfolderId')]
    public function reorderViews(int $groupfolderId): DataResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw RequestException::notLoggedIn();
        }

        $viewIds = $this->getIdListParam('view_ids');
        if ($viewIds === []) {
            throw new ApiValidationException(['view_ids' => ViewColumnFormat::MSG_INVALID]);
        }

        $this->viewService->reorderViews($groupfolderId, $viewIds);
        return new DataResponse(['success' => true], Http::STATUS_OK);
    }

    /**
     * Delete a view.
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[RequireAccess(Access::CanUpdateTeamFolder, folder: 'groupfolderId')]
    public function deleteView(int $groupfolderId, int $viewId): DataResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw RequestException::notLoggedIn();
        }

        $existing = $this->viewService->getView($viewId, $groupfolderId);
        if ($existing === null) {
            throw RequestException::notFound(Translatable::t('View not found'));
        }

        $this->viewService->deleteView($viewId, $groupfolderId);
        return new DataResponse(['success' => true], Http::STATUS_OK);
    }
}
