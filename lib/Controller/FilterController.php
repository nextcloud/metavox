<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\FilterService;
use OCA\MetaVox\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;

class FilterController extends BaseController {

    private FilterService $filterService;

    public function __construct(
        string $appName,
        IRequest $request,
        FieldService $fieldService,
        FilterService $filterService,
        PermissionService $permissionService,
        IUserSession $userSession,
        IRootFolder $rootFolder
    ) {
        parent::__construct($appName, $request, $userSession, $permissionService, $fieldService, $rootFolder);
        $this->filterService = $filterService;
    }

    /**
     * Get metadata for a batch of files in a groupfolder.
     * Optimized for file list column rendering.
     */
    #[NoAdminRequired]
    public function getDirectoryMetadata(int $groupfolderId): JSONResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof JSONResponse) return $user;
            if ($deny = $this->requireGroupfolderAccess($user->getUID(), $groupfolderId)) return $deny;

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

            // Groupfolder access is verified above. Per-file ACL checks are skipped
            // for the internal controller (Files app context) to avoid N getById() calls.
            // The external API controller (ApiFilterController) still does per-file checks.
            $metadata = $this->filterService->getDirectoryMetadata($fileIds, $groupfolderId);
            $response = new JSONResponse($metadata, Http::STATUS_OK);
            $response->addHeader('Cache-Control', 'private, max-age=30');
            return $response;
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get distinct filter values for all fields in one request.
     * Returns { field_name: [value1, value2, ...], ... }
     */
    #[NoAdminRequired]
    public function getAllFilterValues(int $groupfolderId): JSONResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof JSONResponse) return $user;
            if ($deny = $this->requireGroupfolderAccess($user->getUID(), $groupfolderId)) return $deny;

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
}
