<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Service\ApiFieldService;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class ApiFieldController extends BaseOCSController {

    private ApiFieldService $apiFieldService;
    private LoggerInterface $logger;

    public function __construct(
        string $appName,
        IRequest $request,
        FieldService $fieldService,
        ApiFieldService $apiFieldService,
        PermissionService $permissionService,
        IUserSession $userSession,
        IRootFolder $rootFolder,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request, $userSession, $permissionService, $fieldService, $rootFolder);
        $this->apiFieldService = $apiFieldService;
        $this->logger = $logger;
    }

    // ========================================
    // Field Definition CRUD (Admin only via NC middleware)
    // ========================================

    /**
     * Update existing field (Admin only)
     */
    #[CORS]
    #[NoCSRFRequired]
    public function updateField(int $id): DataResponse {
        try {
            $fieldName = $this->request->getParam('field_name');
            $fieldLabel = $this->request->getParam('field_label');
            $fieldType = $this->request->getParam('field_type');
            $fieldDescription = $this->request->getParam('field_description', '');
            $fieldOptions = $this->request->getParam('field_options', '');
            $isRequired = $this->request->getParam('is_required', false);
            $sortOrder = $this->request->getParam('sort_order', 0);
            $appliesToGroupfolder = $this->request->getParam('applies_to_groupfolder');

            if (empty($fieldName) || empty($fieldLabel) || empty($fieldType)) {
                return new DataResponse(['error' => 'Field name, label, and type are required'], Http::STATUS_BAD_REQUEST);
            }

            // Strip any prefix before validating the base name
            $baseName = trim($fieldName);
            $baseName = preg_replace('/^(file_gf_|gf_)/', '', $baseName);
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $baseName)) {
                return new DataResponse(['error' => 'Field name may only contain lowercase letters, numbers and underscores, and must start with a letter'], Http::STATUS_BAD_REQUEST);
            }

            $fieldData = [
                'field_name' => trim($fieldName),
                'field_label' => trim($fieldLabel),
                'field_type' => $fieldType,
                'field_description' => trim($fieldDescription),
                'field_options' => $fieldOptions,
                'is_required' => (bool)$isRequired,
                'sort_order' => (int)$sortOrder,
            ];

            if ($appliesToGroupfolder !== null) {
                $fieldData['applies_to_groupfolder'] = (int)$appliesToGroupfolder;
            }

            $success = $this->fieldService->updateField($id, $fieldData);

            if ($success) {
                return new DataResponse(['success' => true, 'message' => 'Field updated successfully'], Http::STATUS_OK);
            } else {
                return new DataResponse(['error' => 'Failed to update field', 'success' => false], Http::STATUS_INTERNAL_SERVER_ERROR);
            }
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Internal server error: ' . $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete field (Admin only)
     */
    #[CORS]
    #[NoCSRFRequired]
    public function deleteField(int $id): DataResponse {
        try {
            $success = $this->fieldService->deleteField($id);
            return new DataResponse(['success' => $success], Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create groupfolder field (Admin only)
     */
    #[CORS]
    #[NoCSRFRequired]
    public function createGroupfolderField(): DataResponse {
        try {
            $fieldData = [
                'field_name' => $this->request->getParam('field_name'),
                'field_label' => $this->request->getParam('field_label'),
                'field_type' => $this->request->getParam('field_type', 'text'),
                'field_description' => $this->request->getParam('field_description', ''),
                'field_options' => $this->request->getParam('field_options', []),
                'is_required' => $this->request->getParam('is_required', false),
                'sort_order' => $this->request->getParam('sort_order', 0),
                'scope' => 'groupfolder',
                'applies_to_groupfolder' => $this->request->getParam('applies_to_groupfolder', false),
            ];

            if (empty($fieldData['field_name']) || empty($fieldData['field_label'])) {
                return new DataResponse(['error' => 'Field name and label are required'], Http::STATUS_BAD_REQUEST);
            }

            $id = $this->fieldService->createField($fieldData);
            return new DataResponse(['id' => $id, 'success' => true], Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage(), 'success' => false], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update a groupfolder field definition (Admin only)
     */
    #[CORS]
    #[NoCSRFRequired]
    public function updateGroupfolderField(int $id): DataResponse {
        try {
            $fieldData = [
                'field_name'             => $this->request->getParam('field_name'),
                'field_label'            => $this->request->getParam('field_label'),
                'field_type'             => $this->request->getParam('field_type', 'text'),
                'field_description'      => $this->request->getParam('field_description', ''),
                'field_options'          => $this->request->getParam('field_options', []),
                'is_required'            => $this->request->getParam('is_required', false),
                'sort_order'             => $this->request->getParam('sort_order', 0),
                'applies_to_groupfolder' => $this->request->getParam('applies_to_groupfolder', false),
            ];

            if (empty($fieldData['field_name']) || empty($fieldData['field_label'])) {
                return new DataResponse(['error' => 'field_name and field_label are required'], Http::STATUS_BAD_REQUEST);
            }

            $success = $this->fieldService->updateField($id, $fieldData);
            if (!$success) {
                return new DataResponse(['error' => 'Field not found or update failed'], Http::STATUS_NOT_FOUND);
            }
            return new DataResponse(['success' => true], Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete a groupfolder field definition (Admin only)
     */
    #[CORS]
    #[NoCSRFRequired]
    public function deleteGroupfolderField(int $id): DataResponse {
        try {
            $success = $this->fieldService->deleteField($id);
            if (!$success) {
                return new DataResponse(['error' => 'Field not found or delete failed'], Http::STATUS_NOT_FOUND);
            }
            return new DataResponse(['success' => true], Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Set groupfolder fields (Admin only)
     */
    #[CORS]
    #[NoCSRFRequired]
    public function setGroupfolderFields(int $groupfolderId): DataResponse {
        try {
            $fieldIds = $this->request->getParam('field_ids', []);
            $success = $this->fieldService->setGroupfolderFields($groupfolderId, $fieldIds);
            return new DataResponse(['success' => $success], Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Save field override for specific groupfolder (Admin only)
     */
    #[CORS]
    #[NoCSRFRequired]
    public function saveFieldOverride(int $groupfolderId): DataResponse {
        try {
            $fieldName = $this->request->getParam('field_name');
            $appliesToGroupfolder = (int)$this->request->getParam('applies_to_groupfolder', 0);

            if (empty($fieldName)) {
                return new DataResponse(['success' => false, 'message' => 'Field name is required'], Http::STATUS_BAD_REQUEST);
            }

            $success = $this->fieldService->saveGroupfolderFieldOverride($groupfolderId, $fieldName, $appliesToGroupfolder);

            if ($success) {
                return new DataResponse(['success' => true], Http::STATUS_OK);
            } else {
                return new DataResponse(['success' => false, 'message' => 'Failed to save field override'], Http::STATUS_INTERNAL_SERVER_ERROR);
            }
        } catch (\Exception $e) {
            return new DataResponse(['success' => false, 'message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get metadata statistics (Admin only)
     */
    #[CORS]
    #[NoCSRFRequired]
    public function getMetadataStatistics(): DataResponse {
        try {
            $stats = $this->apiFieldService->getMetadataStatistics();
            return new DataResponse($stats, Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ========================================
    // User-accessible endpoints (NoAdminRequired)
    // ========================================

    /**
     * Get file metadata for multiple files
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getBulkFileMetadata(): DataResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof DataResponse) return $user;

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
            if (count($fileIds) > 100) {
                return new DataResponse(['error' => 'Maximum 100 file IDs per request'], Http::STATUS_BAD_REQUEST);
            }

            $accessibleFileIds = $this->filterAccessibleFileIds($fileIds, $user->getUID());
            if (empty($accessibleFileIds)) {
                return new DataResponse(['error' => 'No accessible files found'], Http::STATUS_FORBIDDEN);
            }

            $metadata = $this->apiFieldService->getBulkFileMetadata($accessibleFileIds);
            return new DataResponse($metadata, Http::STATUS_OK);
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: getBulkFileMetadata error', ['exception' => $e]);
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get file metadata
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getFileMetadata(int $fileId): DataResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof DataResponse) return $user;

            if (!$this->canUserAccessFile($fileId, $user->getUID())) {
                return new DataResponse(['error' => 'Access denied to file'], Http::STATUS_FORBIDDEN);
            }

            $metadata = $this->apiFieldService->getFileMetadata($fileId);
            return new DataResponse($metadata, Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Save file metadata
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function saveFileMetadata(int $fileId): DataResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof DataResponse) return $user;

            if (!$this->canUserAccessFile($fileId, $user->getUID())) {
                return new DataResponse(['error' => 'Access denied to file'], Http::STATUS_FORBIDDEN);
            }

            $metadata = $this->request->getParam('metadata', []);
            $this->apiFieldService->saveFileMetadata($fileId, $metadata);

            return new DataResponse(['success' => true], Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get all groupfolders
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getGroupfolders(): DataResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof DataResponse) return $user;

            $groupfolders = $this->fieldService->getGroupfolders($user->getUID());
            return new DataResponse($groupfolders, Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get groupfolder metadata
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getGroupfolderMetadata(int $groupfolderId): DataResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof DataResponse) return $user;
            if ($deny = $this->requireGroupfolderAccess($user->getUID(), $groupfolderId)) return $deny;

            $metadata = $this->fieldService->getGroupfolderMetadata($groupfolderId);
            return new DataResponse($metadata, Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Save groupfolder metadata
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function saveGroupfolderMetadata(int $groupfolderId): DataResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof DataResponse) return $user;
            if ($deny = $this->requireGroupfolderAccess($user->getUID(), $groupfolderId)) return $deny;

            $metadata = $this->request->getParam('metadata', []);

            $fields = $this->fieldService->getFieldsByScope('groupfolder');
            $fieldMap = [];
            foreach ($fields as $field) {
                $fieldMap[$field['field_name']] = $field['id'];
            }

            foreach ($metadata as $fieldName => $value) {
                if (isset($fieldMap[$fieldName])) {
                    if (is_array($value)) {
                        $value = implode(';#', $value);
                    }
                    $this->fieldService->saveGroupfolderFieldValue($groupfolderId, $fieldMap[$fieldName], (string)$value);
                }
            }

            return new DataResponse(['success' => true], Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage(), 'success' => false], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get groupfolder fields
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getGroupfolderFields(): DataResponse {
        try {
            $fields = $this->fieldService->getFieldsByScope('groupfolder');
            return new DataResponse(array_values($fields), Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get groupfolder file metadata
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getGroupfolderFileMetadata(int $groupfolderId, int $fileId): DataResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof DataResponse) return $user;

            if (!$this->canUserAccessFile($fileId, $user->getUID())) {
                return new DataResponse(['error' => 'Access denied to file'], Http::STATUS_FORBIDDEN);
            }

            $metadata = $this->apiFieldService->getGroupfolderFileMetadata($groupfolderId, $fileId);
            return new DataResponse($metadata, Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Save groupfolder file metadata
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function saveGroupfolderFileMetadata(int $groupfolderId, int $fileId): DataResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof DataResponse) return $user;

            if (!$this->canUserAccessFile($fileId, $user->getUID())) {
                return new DataResponse(['error' => 'Access denied to file'], Http::STATUS_FORBIDDEN);
            }

            $metadata = $this->request->getParam('metadata', []);
            $this->apiFieldService->saveGroupfolderFileMetadata($groupfolderId, $fileId, $metadata);

            return new DataResponse(['success' => true], Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get assigned fields for groupfolder
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getGroupfolderAssignedFields(int $groupfolderId): DataResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof DataResponse) return $user;
            if ($deny = $this->requireGroupfolderAccess($user->getUID(), $groupfolderId)) return $deny;

            $fields = $this->fieldService->getAssignedFieldsWithDataForGroupfolder($groupfolderId);
            return new DataResponse($fields, Http::STATUS_OK);
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: getGroupfolderAssignedFields error', ['exception' => $e]);
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get field overrides for specific groupfolder
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getFieldOverrides(int $groupfolderId): DataResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof DataResponse) return $user;
            if ($deny = $this->requireGroupfolderAccess($user->getUID(), $groupfolderId)) return $deny;

            $overrides = $this->fieldService->getGroupfolderFieldOverrides($groupfolderId);
            return new DataResponse($overrides, Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get all file-level fields assigned to a groupfolder
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getGroupfolderFileFields(int $groupfolderId): DataResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof DataResponse) return $user;
            if ($deny = $this->requireGroupfolderAccess($user->getUID(), $groupfolderId)) return $deny;

            $fields = $this->fieldService->getAssignedFileFieldsForGroupfolder($groupfolderId);
            $response = new DataResponse($fields, Http::STATUS_OK);
            $response->addHeader('Cache-Control', 'private, max-age=600');
            return $response;
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ========================================
    // Batch Operations (NoAdminRequired)
    // ========================================

    /**
     * Batch update file metadata for multiple files
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function batchUpdateFileMetadata(): DataResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof DataResponse) return $user;

            $updates = $this->request->getParam('updates', []);
            if (empty($updates) || !is_array($updates)) {
                return new DataResponse(['error' => 'updates array is required'], Http::STATUS_BAD_REQUEST);
            }

            $userId = $user->getUID();
            $filteredUpdates = [];
            foreach ($updates as $update) {
                if (isset($update['file_id']) && $this->canUserAccessFile((int)$update['file_id'], $userId)) {
                    $filteredUpdates[] = $update;
                }
            }

            if (empty($filteredUpdates)) {
                return new DataResponse(['error' => 'No accessible files in update request'], Http::STATUS_FORBIDDEN);
            }

            $results = $this->apiFieldService->batchUpdateFileMetadata($filteredUpdates);
            $successCount = count(array_filter($results, fn($r) => $r['success']));

            return new DataResponse([
                'success' => true,
                'results' => $results,
                'total' => count($results),
                'successful' => $successCount,
                'failed' => count($results) - $successCount
            ], Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Batch delete file metadata
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function batchDeleteFileMetadata(): DataResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof DataResponse) return $user;

            $deletes = $this->request->getParam('deletes', []);
            if (empty($deletes) || !is_array($deletes)) {
                return new DataResponse(['error' => 'deletes array is required'], Http::STATUS_BAD_REQUEST);
            }

            $userId = $user->getUID();
            $filteredDeletes = [];
            foreach ($deletes as $delete) {
                if (isset($delete['file_id']) && $this->canUserAccessFile((int)$delete['file_id'], $userId)) {
                    $filteredDeletes[] = $delete;
                }
            }

            if (empty($filteredDeletes)) {
                return new DataResponse(['error' => 'No accessible files in delete request'], Http::STATUS_FORBIDDEN);
            }

            $results = $this->apiFieldService->batchDeleteFileMetadata($filteredDeletes);
            $successCount = count(array_filter($results, fn($r) => $r['success']));

            return new DataResponse([
                'success' => true,
                'results' => $results,
                'total' => count($results),
                'successful' => $successCount,
                'failed' => count($results) - $successCount
            ], Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Batch copy metadata from one file to multiple files
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function batchCopyFileMetadata(): DataResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof DataResponse) return $user;

            $userId = $user->getUID();
            $sourceFileId = (int)$this->request->getParam('source_file_id');
            $targetFileIds = $this->request->getParam('target_file_ids', []);
            $fieldNames = $this->request->getParam('field_names', null);

            if (!$sourceFileId) {
                return new DataResponse(['error' => 'source_file_id is required'], Http::STATUS_BAD_REQUEST);
            }
            if (!$this->canUserAccessFile($sourceFileId, $userId)) {
                return new DataResponse(['error' => 'Access denied to source file'], Http::STATUS_FORBIDDEN);
            }
            if (empty($targetFileIds) || !is_array($targetFileIds)) {
                return new DataResponse(['error' => 'target_file_ids array is required'], Http::STATUS_BAD_REQUEST);
            }

            $accessibleTargetIds = $this->filterAccessibleFileIds(array_map('intval', $targetFileIds), $userId);
            if (empty($accessibleTargetIds)) {
                return new DataResponse(['error' => 'No accessible target files'], Http::STATUS_FORBIDDEN);
            }

            $result = $this->apiFieldService->batchCopyFileMetadata($sourceFileId, $accessibleTargetIds, $fieldNames);
            return new DataResponse($result, Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
