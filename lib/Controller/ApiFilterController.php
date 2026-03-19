<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\FilterService;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Files\IRootFolder;

class ApiFilterController extends OCSController {

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
     * @NoCSRFRequired
     * @CORS
     */
    public function getDirectoryMetadata(int $groupfolderId): DataResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
            }
            if (!$this->fieldService->hasAccessToGroupfolder($user->getUID(), $groupfolderId)) {
                return new DataResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN);
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
                return new DataResponse(['error' => 'No valid file IDs provided'], Http::STATUS_BAD_REQUEST);
            }

            if (count($fileIds) > 200) {
                return new DataResponse(['error' => 'Maximum 200 file IDs per request'], Http::STATUS_BAD_REQUEST);
            }

            $accessibleFileIds = $fileIds;
            if (count($fileIds) <= 10) {
                $accessibleFileIds = $this->filterAccessibleFileIds($fileIds);
                if (empty($accessibleFileIds)) {
                    return new DataResponse([], Http::STATUS_OK);
                }
            }

            // Optional: only fetch specific fields (reduces query size for large datasets)
            $fieldNamesParam = $this->request->getParam('field_names');
            $fieldNames = [];
            if (is_string($fieldNamesParam) && !empty($fieldNamesParam)) {
                $fieldNames = array_filter(array_map('trim', explode(',', $fieldNamesParam)));
            }

            $metadata = $this->filterService->getDirectoryMetadata($accessibleFileIds, $groupfolderId, $fieldNames);

            // Add per-file permissions for inline editing
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            foreach ($metadata as $fileId => &$fileMeta) {
                $nodes = $userFolder->getById((int)$fileId);
                if (!empty($nodes)) {
                    $fileMeta['_permissions'] = $nodes[0]->getPermissions();
                } else {
                    $fileMeta['_permissions'] = 1; // read-only
                }
            }
            unset($fileMeta);

            return new DataResponse($metadata, Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get sorted and filtered file IDs for a groupfolder.
     * Returns an ordered array of file IDs based on server-side SQL sort/filter.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @CORS
     */
    public function getSortedFileIds(int $groupfolderId): DataResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
            }
            if (!$this->fieldService->hasAccessToGroupfolder($user->getUID(), $groupfolderId)) {
                return new DataResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN);
            }

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

            // Determine which fields are multiselect for ;# matching
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
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @CORS
     */
    public function getAllFilterValues(int $groupfolderId): DataResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
            }
            if (!$this->fieldService->hasAccessToGroupfolder($user->getUID(), $groupfolderId)) {
                return new DataResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN);
            }

            $fieldNames = $this->request->getParam('field_names');
            $fieldNamesArray = [];
            if (!empty($fieldNames)) {
                $fieldNamesArray = array_filter(array_map('trim', explode(',', $fieldNames)));
            }

            // For select/multiselect fields, use field_options from config instead of DB GROUP BY
            $fields = $this->fieldService->getAssignedFieldsWithDataForGroupfolder($groupfolderId);
            $optionFields = []; // field_name => [option values] for select/multiselect
            $dbFieldNames = []; // field names that need DB lookup

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

            // Only query DB for fields that don't have predefined options
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
