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
     *
     * For select/multiselect/dropdown/checkbox fields the options come from the
     * field configuration (so combined multiselect values like "Doris;#Wieke"
     * don't leak through as if they were a single option). Free-text fields
     * fall back to DB DISTINCT.
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

            $fields = $this->fieldService->getAssignedFieldsWithDataForGroupfolder($groupfolderId);
            $optionFields = [];
            $dbFieldNames = [];
            foreach ($fields as $field) {
                $name = $field['field_name'] ?? '';
                if (empty($name)) continue;
                if (!empty($fieldNamesArray) && !in_array($name, $fieldNamesArray)) continue;

                $type = $field['field_type'] ?? '';
                if (in_array($type, ['select', 'multiselect', 'multi_select', 'dropdown', 'checkbox'])) {
                    if ($type === 'checkbox') {
                        $optionFields[$name] = ['1', '0'];
                    } else {
                        $options = $field['field_options'] ?? [];
                        if (is_array($options) && !empty($options)) {
                            $optionFields[$name] = array_values($options);
                        } else {
                            $dbFieldNames[] = $name;
                        }
                    }
                } else {
                    $dbFieldNames[] = $name;
                }
            }

            $dbValues = [];
            if (!empty($dbFieldNames)) {
                $dbValues = $this->filterService->getAllDistinctFieldValues($groupfolderId, $dbFieldNames, $fileIds);
            }

            $values = array_merge($optionFields, $dbValues);
            $response = new JSONResponse($values, Http::STATUS_OK);
            $response->addHeader('Cache-Control', 'private, max-age=60');
            return $response;
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
