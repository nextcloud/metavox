<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\PermissionService;
use OCA\MetaVox\Service\UserFieldService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class UserFieldController extends BaseController {

    private UserFieldService $userFieldService;
    private LoggerInterface $logger;

    public function __construct(
        string $appName,
        IRequest $request,
        UserFieldService $userFieldService,
        FieldService $fieldService,
        PermissionService $permissionService,
        IUserSession $userSession,
        IRootFolder $rootFolder,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request, $userSession, $permissionService, $fieldService, $rootFolder);
        $this->userFieldService = $userFieldService;
        $this->logger = $logger;
    }

    /**
     * Get groupfolders accessible by current user
     */
    #[NoAdminRequired]
    public function getAccessibleGroupfolders(): JSONResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof JSONResponse) return $user;

            $groupfolders = $this->userFieldService->getAccessibleGroupfolders($user->getUID());
            return new JSONResponse($groupfolders);
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: getAccessibleGroupfolders error', ['exception' => $e]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get assigned fields for a groupfolder
     */
    #[NoAdminRequired]
    public function getGroupfolderFields(int $groupfolderId): JSONResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof JSONResponse) return $user;
            if ($deny = $this->requireGroupfolderAccess($user->getUID(), $groupfolderId)) return $deny;

            $fields = $this->userFieldService->getGroupfolderFields($groupfolderId);
            return new JSONResponse($fields);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get all available groupfolder fields (for configuration)
     */
    #[NoAdminRequired]
    public function getAllGroupfolderFields(): JSONResponse {
        try {
            $fields = $this->userFieldService->getAllGroupfolderFields();
            return new JSONResponse($fields);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get metadata for a groupfolder
     */
    #[NoAdminRequired]
    public function getGroupfolderMetadata(int $groupfolderId): JSONResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof JSONResponse) return $user;
            if ($deny = $this->requireGroupfolderAccess($user->getUID(), $groupfolderId)) return $deny;

            $metadata = $this->userFieldService->getGroupfolderMetadata($groupfolderId);
            return new JSONResponse($metadata);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Save metadata for a groupfolder
     */
    #[NoAdminRequired]
    public function saveGroupfolderMetadata(int $groupfolderId): JSONResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof JSONResponse) return $user;
            if ($deny = $this->requireGroupfolderAccess($user->getUID(), $groupfolderId)) return $deny;

            $metadata = $this->request->getParam('metadata', []);
            $success = $this->userFieldService->saveGroupfolderMetadata($groupfolderId, $metadata);

            return new JSONResponse(['success' => $success]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Set which fields are assigned to a groupfolder
     */
    #[NoAdminRequired]
    public function setGroupfolderFields(int $groupfolderId): JSONResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof JSONResponse) return $user;
            if ($deny = $this->requireGroupfolderAccess($user->getUID(), $groupfolderId)) return $deny;
            if ($deny = $this->requireManagePermission($user->getUID(), $groupfolderId)) return $deny;

            $fieldIds = $this->request->getParam('field_ids', []);
            $success = $this->userFieldService->setGroupfolderFields($groupfolderId, $fieldIds);

            return new JSONResponse(['success' => $success]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
