<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Access\Access;
use OCA\MetaVox\Access\RequireAccess;
use OCA\MetaVox\Exception\RequestException;
use OCA\MetaVox\Service\ApiValidationException;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\TeamFolderAccessService;
use OCA\MetaVox\Service\PresenceService;
use OCA\MetaVox\Service\UserFieldService;
use OCA\MetaVox\Service\ViewColumnFormat;
use OCA\MetaVox\Service\ViewService;
use OCA\MetaVox\L10n\Translatable;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class ViewController extends BaseController {

    use ReadsViewParams;

    private PresenceService $presenceService;
    private UserFieldService $userFieldService;
    private ViewService $viewService;
    private TeamFolderAccessService $teamFolders;

    public function __construct(
        string $appName,
        IRequest $request,
        FieldService $fieldService,
        PresenceService $presenceService,
        UserFieldService $userFieldService,
        ViewService $viewService,
        IUserSession $userSession,
        TeamFolderAccessService $teamFolders
    ) {
        parent::__construct($appName, $request, $userSession, $fieldService);
        $this->presenceService = $presenceService;
        $this->userFieldService = $userFieldService;
        $this->viewService = $viewService;
        $this->teamFolders = $teamFolders;
    }

    /**
     * List all views for a groupfolder
     */
    #[NoAdminRequired]
    #[RequireAccess(Access::TeamFolderMember, folder: 'gfId')]
    public function listViews(int $gfId): JSONResponse {
        $user = $this->userSession->getUser();
        $canManage = $user !== null && $this->teamFolders->canUpdateTeamFolder($user->getUID(), $gfId);

        $views = $this->viewService->getViewsForGroupfolder($gfId);
        $response = new JSONResponse(['views' => $views, 'can_manage' => $canManage]);
        $response->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
        return $response;
    }

    /**
     * Create a new view for a groupfolder
     */
    #[NoAdminRequired]
    #[RequireAccess(Access::CanUpdateTeamFolder, folder: 'gfId')]
    public function createView(int $gfId): JSONResponse {
        $user = $this->requireUser();
        $p = $this->viewParams();

        $view = $this->viewService->createView($gfId, $p['name'], $p['is_default'], $p['columns'], $p['filters'], $p['sort_field'], $p['sort_order']);
        return new JSONResponse($view, Http::STATUS_CREATED);
    }

    /**
     * Update an existing view
     */
    #[NoAdminRequired]
    #[RequireAccess(Access::CanUpdateTeamFolder, folder: 'gfId')]
    public function updateView(int $gfId, int $viewId): JSONResponse {
        $user = $this->requireUser();
        $p = $this->viewParams();
        $position = $this->positionParam();

        $existing = $this->viewService->getView($viewId, $gfId);
        if ($existing === null) {
            // TRANSLATORS Error message shown to the user when the view no longer exists; a view is a saved combination of columns, filters and sort order
            throw RequestException::notFound(Translatable::t('View not found'));
        }

        $view = $this->viewService->updateView($viewId, $gfId, $p['name'], $p['is_default'], $p['columns'], $p['filters'], $p['sort_field'], $p['sort_order'], (array)($existing['columns'] ?? []), $position);
        return new JSONResponse($view);
    }

    /**
     * Reorder views for a groupfolder
     */
    #[NoAdminRequired]
    #[RequireAccess(Access::CanUpdateTeamFolder, folder: 'gfId')]
    public function reorderViews(int $gfId): JSONResponse {
        $user = $this->requireUser();

        $viewIds = $this->getIdListParam('view_ids');
        if ($viewIds === []) {
            throw new ApiValidationException(['view_ids' => ViewColumnFormat::MSG_INVALID]);
        }

        $this->viewService->reorderViews($gfId, $viewIds);
        return new JSONResponse(['success' => true]);
    }

    /**
     * Delete a view
     */
    #[NoAdminRequired]
    #[RequireAccess(Access::CanUpdateTeamFolder, folder: 'gfId')]
    public function deleteView(int $gfId, int $viewId): JSONResponse {
        $user = $this->requireUser();

        $existing = $this->viewService->getView($viewId, $gfId);
        if ($existing === null) {
            // TRANSLATORS Error message shown to the user when the view no longer exists; a view is a saved combination of columns, filters and sort order
            throw RequestException::notFound(Translatable::t('View not found'));
        }

        $this->viewService->deleteView($viewId, $gfId);
        return new JSONResponse(['success' => true]);
    }

    /**
     * Single-call initialization for the files plugin.
     * Returns groupfolders + fields + views + filter values in one response.
     */
    #[NoAdminRequired]
    #[RequireAccess(Access::LoggedIn, reason: 'data only for a team folder the caller is a member of')]
    public function init(): JSONResponse {
        $user = $this->requireUser();

        $userId = $user->getUID();
        $dir = $this->textParam('dir', max: 4000) ?? '';

        $groupfolders = $this->userFieldService->getAccessibleGroupfolders($userId);

        if (empty($groupfolders)) {
            // No groupfolders accessible for this user — normal for non-team-folder users
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
            $result['can_manage'] = $this->teamFolders->canUpdateTeamFolder($userId, $groupfolderId);
        }

        $response = new JSONResponse($result);
        $response->addHeader('Cache-Control', 'private, max-age=30');
        return $response;
    }
}
