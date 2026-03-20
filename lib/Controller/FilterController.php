<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\FilterService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Files\IRootFolder;

class FilterController extends Controller {

    private FieldService $fieldService;
    private FilterService $filterService;
    private IUserSession $userSession;
    private IRootFolder $rootFolder;

    public function __construct(
        string $appName,
        IRequest $request,
        FieldService $fieldService,
        FilterService $filterService,
        IUserSession $userSession,
        IRootFolder $rootFolder
    ) {
        parent::__construct($appName, $request);
        $this->fieldService = $fieldService;
        $this->filterService = $filterService;
        $this->userSession = $userSession;
        $this->rootFolder = $rootFolder;
    }

    /**
     * Get metadata for a batch of files in a groupfolder.
     * Optimized for file list column rendering.
     *
     * @NoAdminRequired
     */
    public function getDirectoryMetadata(int $groupfolderId): JSONResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
            }
            if (!$this->fieldService->hasAccessToGroupfolder($user->getUID(), $groupfolderId)) {
                return new JSONResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN);
            }

            $fileIdsParam = $this->request->getParam('file_ids');
            $fileIds = [];

            if (is_array($fileIdsParam) && !empty($fileIdsParam)) {
                $fileIds = $fileIdsParam;
            } elseif (is_string($fileIdsParam) && !empty($fileIdsParam)) {
                $fileIds = explode(',', $fileIdsParam);
            }

            $fileIds = array_map('intval', array_filter($fileIds, fn($id) => is_numeric($id) && intval($id) > 0));
            $fileIds = array_unique($fileIds);

            if (empty($fileIds)) {
                return new JSONResponse(['error' => 'No valid file IDs provided'], Http::STATUS_BAD_REQUEST);
            }

            if (count($fileIds) > 200) {
                return new JSONResponse(['error' => 'Maximum 200 file IDs per request'], Http::STATUS_BAD_REQUEST);
            }

            // Groupfolder access is already verified above, so we trust file IDs
            // belong to this groupfolder. For small sets, do per-file checks.
            $accessibleFileIds = $fileIds;
            if (count($fileIds) <= 10) {
                $accessibleFileIds = $this->filterAccessibleFileIds($fileIds);
                if (empty($accessibleFileIds)) {
                    return new JSONResponse([], Http::STATUS_OK);
                }
            }

            $metadata = $this->filterService->getDirectoryMetadata($accessibleFileIds, $groupfolderId);
            return new JSONResponse($metadata, Http::STATUS_OK);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get distinct filter values for all fields in one request.
     * Returns { field_name: [value1, value2, ...], ... }
     *
     * @NoAdminRequired
     */
    public function getAllFilterValues(int $groupfolderId): JSONResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
            }
            if (!$this->fieldService->hasAccessToGroupfolder($user->getUID(), $groupfolderId)) {
                return new JSONResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN);
            }

            $fieldNames = $this->request->getParam('field_names');
            $fieldNamesArray = [];
            if (!empty($fieldNames)) {
                $fieldNamesArray = array_filter(array_map('trim', explode(',', $fieldNames)));
            }

            // Scope to specific file IDs (current directory) if provided
            $fileIdsParam = $this->request->getParam('file_ids');
            $fileIds = [];
            if (is_array($fileIdsParam) && !empty($fileIdsParam)) {
                $fileIds = array_map('intval', array_filter($fileIdsParam, fn($id) => is_numeric($id) && intval($id) > 0));
            } elseif (is_string($fileIdsParam) && !empty($fileIdsParam)) {
                $fileIds = array_map('intval', array_filter(explode(',', $fileIdsParam), fn($id) => is_numeric($id) && intval($id) > 0));
            }

            $values = $this->filterService->getAllDistinctFieldValues($groupfolderId, $fieldNamesArray, $fileIds);
            $response = new JSONResponse($values, Http::STATUS_OK);
            $response->addHeader('Cache-Control', 'private, max-age=60');
            return $response;
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Check if user has access to multiple files, returns array of accessible file IDs.
     */
    private function filterAccessibleFileIds(array $fileIds): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            return [];
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            $accessibleIds = [];
            foreach ($fileIds as $fileId) {
                $nodes = $userFolder->getById($fileId);
                if (!empty($nodes)) {
                    $accessibleIds[] = $fileId;
                }
            }
            return $accessibleIds;
        } catch (\Exception $e) {
            return [];
        }
    }
}
