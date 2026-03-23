<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\FilterService;
use OCA\MetaVox\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;

class ApiFilterController extends BaseOCSController {

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
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getDirectoryMetadata(int $groupfolderId): DataResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof DataResponse) return $user;
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
                return new DataResponse(['error' => 'No valid file IDs provided'], Http::STATUS_BAD_REQUEST);
            }

            if (count($fileIds) > 200) {
                return new DataResponse(['error' => 'Maximum 200 file IDs per request'], Http::STATUS_BAD_REQUEST);
            }

            // Single pass: verify access + collect permissions (avoids double getById loop)
            $result = $this->filterAccessibleFileIdsWithPermissions($fileIds, $user->getUID());
            $accessibleFileIds = $result['accessible'];
            $filePermissions = $result['permissions'];

            if (empty($accessibleFileIds)) {
                return new DataResponse([], Http::STATUS_OK);
            }

            // Optional: only fetch specific fields
            $fieldNamesParam = $this->request->getParam('field_names');
            $fieldNames = [];
            if (is_string($fieldNamesParam) && !empty($fieldNamesParam)) {
                $fieldNames = array_filter(array_map('trim', explode(',', $fieldNamesParam)));
            }

            $metadata = $this->filterService->getDirectoryMetadata($accessibleFileIds, $groupfolderId, $fieldNames);

            // Attach pre-collected permissions (no extra getById calls)
            foreach ($metadata as $fileId => &$fileMeta) {
                $fileMeta['_permissions'] = $filePermissions[$fileId] ?? 1;
            }
            unset($fileMeta);

            return new DataResponse($metadata, Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get sorted and filtered file IDs for a groupfolder.
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getSortedFileIds(int $groupfolderId): DataResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof DataResponse) return $user;
            if ($deny = $this->requireGroupfolderAccess($user->getUID(), $groupfolderId)) return $deny;

            $sortField = $this->request->getParam('sort_field');
            $sortOrder = $this->request->getParam('sort_order', 'asc');
            $sortFieldType = $this->request->getParam('sort_field_type', 'text');
            $filtersJson = $this->request->getParam('filters');

            $filters = [];
            if (!empty($filtersJson)) {
                $decoded = json_decode($filtersJson, true);
                if (is_array($decoded)) {
                    $filters = $decoded;
                }
            }

            if (empty($sortField) && empty($filters)) {
                return new DataResponse(['error' => 'No sort_field or filters provided'], Http::STATUS_BAD_REQUEST);
            }

            $fields = $this->fieldService->getAssignedFieldsWithDataForGroupfolder($groupfolderId);
            $multiselectFields = [];
            foreach ($fields as $field) {
                $type = $field['field_type'] ?? '';
                if (in_array($type, ['multiselect', 'multi_select'])) {
                    $multiselectFields[] = $field['field_name'];
                }
            }

            $result = $this->filterService->getSortedFilteredFileIds(
                $groupfolderId,
                $sortField ?: null,
                $sortOrder,
                $filters,
                $sortFieldType,
                $multiselectFields
            );

            return new DataResponse($result, Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get distinct filter values for all fields in one request.
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getAllFilterValues(int $groupfolderId): DataResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof DataResponse) return $user;
            if ($deny = $this->requireGroupfolderAccess($user->getUID(), $groupfolderId)) return $deny;

            $fieldNames = $this->request->getParam('field_names');
            $fieldNamesArray = [];
            if (!empty($fieldNames)) {
                $fieldNamesArray = array_filter(array_map('trim', explode(',', $fieldNames)));
            }

            // For select/multiselect fields, use field_options from config instead of DB GROUP BY
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
                $dbValues = $this->filterService->getAllDistinctFieldValues($groupfolderId, $dbFieldNames);
            }

            $values = array_merge($optionFields, $dbValues);
            $response = new DataResponse($values, Http::STATUS_OK);
            $response->addHeader('Cache-Control', 'private, max-age=300');
            return $response;
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get distinct filter values scoped to specific file IDs (current directory).
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getScopedFilterValues(int $groupfolderId): DataResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof DataResponse) return $user;
            if ($deny = $this->requireGroupfolderAccess($user->getUID(), $groupfolderId)) return $deny;

            $fileIdsParam = $this->request->getParam('file_ids');
            $fileIds = [];
            if (is_array($fileIdsParam) && !empty($fileIdsParam)) {
                $fileIds = array_map('intval', array_filter($fileIdsParam, fn($id) => is_numeric($id) && intval($id) > 0));
            }

            $values = $this->filterService->getAllDistinctFieldValues($groupfolderId, [], $fileIds);
            return new DataResponse($values, Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
