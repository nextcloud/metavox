<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Access\Access;
use OCA\MetaVox\Access\RequireAccess;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\FileReferenceService;
use OCA\MetaVox\Service\FileAccessService;
use OCA\MetaVox\Service\TeamFolderAccessService;
use OCA\MetaVox\Service\FilterService;
use OCA\MetaVox\Service\UserFieldService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class UserFieldController extends BaseController {

    private UserFieldService $userFieldService;
    private FilterService $filterService;
    private FileAccessService $fileAccess;
    private TeamFolderAccessService $teamFolders;
    private FileReferenceService $fileReferences;

    public function __construct(
        string $appName,
        IRequest $request,
        UserFieldService $userFieldService,
        FieldService $fieldService,
        FilterService $filterService,
        IUserSession $userSession,
        FileAccessService $fileAccess,
        TeamFolderAccessService $teamFolders,
        FileReferenceService $fileReferences
    ) {
        parent::__construct($appName, $request, $userSession, $fieldService);
        $this->userFieldService = $userFieldService;
        $this->filterService = $filterService;
        $this->fileAccess = $fileAccess;
        $this->teamFolders = $teamFolders;
        $this->fileReferences = $fileReferences;
    }

    /**
     * Get groupfolders accessible by current user
     */
    #[NoAdminRequired]
    #[RequireAccess(Access::LoggedIn, reason: 'only the team folders the caller may manage')]
    public function getAccessibleGroupfolders(): JSONResponse {
        $user = $this->requireUser();

        $userId = $user->getUID();
        $groupfolders = $this->userFieldService->getAccessibleGroupfolders($userId);

        // Annotate each folder with whether this user may manage its fields,
        // and return only the manageable ones — the Personal settings page
        // is for configuring fields/defaults, not browsing every membership.
        $manageable = [];
        foreach ($groupfolders as $folder) {
            $gfId = (int)($folder['id'] ?? 0);
            if ($gfId <= 0) {
                continue;
            }
            if ($this->teamFolders->canManageGroupfolder($userId, $gfId)) {
                $folder['can_manage'] = true;
                $manageable[] = $folder;
            }
        }
        return new JSONResponse($manageable);
    }

    /**
     * Get assigned fields for a groupfolder
     */
    #[NoAdminRequired]
    #[RequireAccess(Access::TeamFolderMember, folder: 'groupfolderId')]
    public function getGroupfolderFields(int $groupfolderId): JSONResponse {
        $user = $this->requireUser();

        $fields = $this->userFieldService->getGroupfolderFields($groupfolderId);
        return new JSONResponse($fields);
    }

    /**
     * Distinct values stored for a field within a groupfolder.
     *
     * Feeds the unified-search filter picker so the user can pick an existing
     * value instead of typing one. Per-folder access gated like the sibling
     * read endpoints; the field name comes as a query param.
     */
    #[NoAdminRequired]
    #[RequireAccess(Access::TeamFolderMember, folder: 'groupfolderId')]
    public function getFieldValues(int $groupfolderId): JSONResponse {
        $user = $this->requireUser();

        $fieldName = $this->textParam('field') ?? '';
        if ($fieldName === '') {
            return new JSONResponse(['values' => []]);
        }

        $type = $this->fieldService->fieldType($fieldName);
        if ($type === null) {
            return new JSONResponse(['values' => []]);
        }
        $values = $this->filterService->suggestedValues($fieldName, $groupfolderId, $this->fileAccess->readableScope($user->getUID(), $groupfolderId),
            50, FieldService::holdsList($type));
        return new JSONResponse(['values' => $values]);
    }

    /**
     * Get all available groupfolder fields (for configuration)
     */
    #[NoAdminRequired]
    #[RequireAccess(Access::LoggedIn, reason: 'only the fields of the caller\'s team folders; all of them for admins and managers')]
    public function getAllGroupfolderFields(): JSONResponse {
        $userId = $this->requireUser()->getUID();

        // The search picker's "all my team folders": the fields of the team
        // folders the user is in, admins and managers too, as its folder list.
        if ($this->request->getParam('scope') === 'member') {
            $folderIds = array_map(fn (array $folder) => (int)$folder['id'], $this->fieldService->getGroupfolders($userId));
            return new JSONResponse($this->fieldService->getCatalogue($folderIds));
        }
        return new JSONResponse($this->fieldService->getCatalogue($this->teamFolders->catalogueFolders($userId)));
    }

    /**
     * Get metadata for a groupfolder
     */
    #[NoAdminRequired]
    #[RequireAccess(Access::TeamFolderMember, folder: 'groupfolderId')]
    public function getGroupfolderMetadata(int $groupfolderId): JSONResponse {
        $user = $this->requireUser();

        $metadata = $this->userFieldService->getGroupfolderMetadata($groupfolderId);
        return new JSONResponse($this->fileReferences->withNames($metadata, $user->getUID()));
    }

    /**
     * Set which fields are assigned to a groupfolder
     */
    #[NoAdminRequired]
    #[RequireAccess(Access::MetaVoxManager, folder: 'groupfolderId')]
    public function setGroupfolderFields(int $groupfolderId): JSONResponse {
        $user = $this->requireUser();

        // Left out is no "none": a misspelled key would unassign every field.
        $this->requireParam('field_ids');
        $this->fieldService->setGroupfolderFields($groupfolderId, $this->getIdListParam('field_ids'));

        return new JSONResponse(['success' => true]);
    }
}
