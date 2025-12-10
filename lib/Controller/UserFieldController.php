<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Service\UserFieldService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class UserFieldController extends Controller {

    private UserFieldService $userFieldService;
    private IUserSession $userSession;

    public function __construct(
        string $appName,
        IRequest $request,
        UserFieldService $userFieldService,
        IUserSession $userSession
    ) {
        parent::__construct($appName, $request);
        $this->userFieldService = $userFieldService;
        $this->userSession = $userSession;
    }

    /**
     * Get groupfolders accessible by current user
     * 
     * @NoAdminRequired
     */
    public function getAccessibleGroupfolders(): JSONResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'User not authenticated'], 401);
            }

            $userId = $user->getUID();
            $groupfolders = $this->userFieldService->getAccessibleGroupfolders($userId);
            
            return new JSONResponse($groupfolders);
        } catch (\Exception $e) {
            error_log('MetaVox getAccessibleGroupfolders error: ' . $e->getMessage());
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get assigned fields for a groupfolder
     * 
     * @NoAdminRequired
     */
    public function getGroupfolderFields(int $groupfolderId): JSONResponse {
        try {
            $fields = $this->userFieldService->getGroupfolderFields($groupfolderId);
            return new JSONResponse($fields);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get all available groupfolder fields (for configuration)
     * 
     * @NoAdminRequired
     */
    public function getAllGroupfolderFields(): JSONResponse {
        try {
            $fields = $this->userFieldService->getAllGroupfolderFields();
            return new JSONResponse($fields);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get metadata for a groupfolder
     * 
     * @NoAdminRequired
     */
    public function getGroupfolderMetadata(int $groupfolderId): JSONResponse {
        try {
            $metadata = $this->userFieldService->getGroupfolderMetadata($groupfolderId);
            return new JSONResponse($metadata);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Save metadata for a groupfolder
     * 
     * @NoAdminRequired
     */
    public function saveGroupfolderMetadata(int $groupfolderId): JSONResponse {
        try {
            $metadata = $this->request->getParam('metadata', []);
            
            $success = $this->userFieldService->saveGroupfolderMetadata($groupfolderId, $metadata);
            
            return new JSONResponse(['success' => $success]);
        } catch (\Exception $e) {
            error_log('MetaVox saveGroupfolderMetadata error: ' . $e->getMessage());
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Set which fields are assigned to a groupfolder
     * 
     * @NoAdminRequired
     */
    public function setGroupfolderFields(int $groupfolderId): JSONResponse {
        try {
            $fieldIds = $this->request->getParam('field_ids', []);
            
            $success = $this->userFieldService->setGroupfolderFields($groupfolderId, $fieldIds);
            
            return new JSONResponse(['success' => $success]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }
}