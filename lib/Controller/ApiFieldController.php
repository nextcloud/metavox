<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\ApiFieldService;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

class ApiFieldController extends OCSController {

    private FieldService $fieldService;
    private ApiFieldService $apiFieldService;
    private IUserSession $userSession;

    public function __construct(
        string $appName,
        IRequest $request,
        FieldService $fieldService,
        ApiFieldService $apiFieldService,
        IUserSession $userSession
    ) {
        parent::__construct($appName, $request);
        $this->fieldService = $fieldService;
        $this->apiFieldService = $apiFieldService;
        $this->userSession = $userSession;
    }

    /**
     * Update existing field (Admin only)
     *
     * @NoCSRFRequired
     * @CORS
     */
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
     *
     * @NoCSRFRequired
     * @CORS
     */
    public function deleteField(int $id): DataResponse {
        try {
            $success = $this->fieldService->deleteField($id);
            return new DataResponse(['success' => $success], Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
/**
 * Get file metadata for multiple files
 * 
 * @NoAdminRequired
 * @NoCSRFRequired
 * @CORS
 */
public function getBulkFileMetadata(): DataResponse {
    try {
        $fileIds = [];
        
        // Accept file_ids[] array notation: ?file_ids[]=123&file_ids[]=456
        $fileIdsParam = $this->request->getParam('file_ids');
        
        if (is_array($fileIdsParam) && !empty($fileIdsParam)) {
            $fileIds = $fileIdsParam;
        }
        // Accept comma-separated string: ?file_ids=123,456,789
        else if (is_string($fileIdsParam) && !empty($fileIdsParam)) {
            $fileIds = explode(',', $fileIdsParam);
        }
        
        // Clean and validate file IDs
        $fileIds = array_map('intval', array_filter($fileIds, function($id) {
            return is_numeric($id) && intval($id) > 0;
        }));
        
        // Remove duplicates
        $fileIds = array_unique($fileIds);
        
        if (empty($fileIds)) {
            return new DataResponse([
                'error' => 'No valid file IDs provided. Use ?file_ids[]=123&file_ids[]=456 or ?file_ids=123,456,789'
            ], Http::STATUS_BAD_REQUEST);
        }
        
        if (count($fileIds) > 100) {
            return new DataResponse([
                'error' => 'Maximum 100 file IDs per request allowed',
                'provided' => count($fileIds),
                'maximum' => 100
            ], Http::STATUS_BAD_REQUEST);
        }
        
        $metadata = $this->fieldService->getBulkFileMetadata($fileIds);
        return new DataResponse($metadata, Http::STATUS_OK);
        
    } catch (\Exception $e) {
        error_log('ApiFieldController getBulkFileMetadata error: ' . $e->getMessage());
        return new DataResponse([
            'error' => $e->getMessage()
        ], Http::STATUS_INTERNAL_SERVER_ERROR);
    }
}
    /**
     * Get file metadata
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @CORS
     */
    public function getFileMetadata(int $fileId): DataResponse {
        try {
            $metadata = $this->fieldService->getFieldMetadata($fileId);
            return new DataResponse($metadata, Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Save file metadata
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @CORS
     */
    public function saveFileMetadata(int $fileId): DataResponse {
        try {
            $metadata = $this->request->getParam('metadata', []);
            
            $fields = $this->fieldService->getAllFields();
            $fieldMap = [];
            foreach ($fields as $field) {
                $fieldMap[$field['field_name']] = $field['id'];
            }
            
            foreach ($metadata as $fieldName => $value) {
                if (isset($fieldMap[$fieldName])) {
                    $this->fieldService->saveFieldValue($fileId, $fieldMap[$fieldName], (string)$value);
                }
            }

            return new DataResponse(['success' => true], Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get all groupfolders
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @CORS
     */
    public function getGroupfolders(): DataResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
            }
            $groupfolders = $this->fieldService->getGroupfolders($user->getUID());
            return new DataResponse($groupfolders, Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get groupfolder metadata
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @CORS
     */
    public function getGroupfolderMetadata(int $groupfolderId): DataResponse {
        try {
            $metadata = $this->fieldService->getGroupfolderMetadata($groupfolderId);
            return new DataResponse($metadata, Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Save groupfolder metadata
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @CORS
     */
    public function saveGroupfolderMetadata(int $groupfolderId): DataResponse {
        try {
            $metadata = $this->request->getParam('metadata', []);
            
            $fields = $this->fieldService->getFieldsByScope('groupfolder');
            $fieldMap = [];
            foreach ($fields as $field) {
                $fieldMap[$field['field_name']] = $field['id'];
            }
            
            foreach ($metadata as $fieldName => $value) {
                if (isset($fieldMap[$fieldName])) {
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
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @CORS
     */
public function getGroupfolderFields(): DataResponse {
    try {
        $fields = $this->fieldService->getFieldsByScope('groupfolder');
        
        // Zorg ervoor dat het een proper indexed array is
        $result = array_values($fields);
        
        return new DataResponse($result, Http::STATUS_OK);
    } catch (\Exception $e) {
        return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
    }
}

    /**
     * Create groupfolder field (Admin only)
     *
     * @NoCSRFRequired
     * @CORS
     */
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
     * Get groupfolder file metadata
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @CORS
     */
    public function getGroupfolderFileMetadata(int $groupfolderId, int $fileId): DataResponse {
        try {
            $metadata = $this->fieldService->getGroupfolderFileMetadata($groupfolderId, $fileId);
            return new DataResponse($metadata, Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Save groupfolder file metadata
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @CORS
     */
    public function saveGroupfolderFileMetadata(int $groupfolderId, int $fileId): DataResponse {
        try {
            $metadata = $this->request->getParam('metadata', []);
            
            $fields = $this->fieldService->getFieldsByScope('groupfolder');
            $fieldMap = [];
            foreach ($fields as $field) {
                $fieldMap[$field['field_name']] = $field['id'];
            }
            
            foreach ($metadata as $fieldName => $value) {
                if (isset($fieldMap[$fieldName])) {
                    $this->fieldService->saveGroupfolderFileFieldValue($groupfolderId, $fileId, $fieldMap[$fieldName], (string)$value);
                }
            }

            return new DataResponse(['success' => true], Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get assigned fields for groupfolder
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @CORS
     */
/**
 * Get assigned fields for groupfolder
 * 
 * @NoAdminRequired
 * @NoCSRFRequired
 * @CORS
 */
public function getGroupfolderAssignedFields(int $groupfolderId): DataResponse {
    try {
        // Use the new method that returns full field data
        $fields = $this->fieldService->getAssignedFieldsWithDataForGroupfolder($groupfolderId);
        
        return new DataResponse($fields, Http::STATUS_OK);
    } catch (\Exception $e) {
        error_log('ApiFieldController ERROR: ' . $e->getMessage());
        return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
    }
}

    /**
     * Set groupfolder fields (Admin only)
     *
     * @NoCSRFRequired
     * @CORS
     */
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
     *
     * @NoCSRFRequired
     * @CORS
     */
    public function saveFieldOverride(int $groupfolderId): DataResponse {
        try {
            $fieldName = $this->request->getParam('field_name');
            $appliesToGroupfolder = (int) $this->request->getParam('applies_to_groupfolder', 0);
            
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
     * Get field overrides for specific groupfolder
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @CORS
     */
    public function getFieldOverrides(int $groupfolderId): DataResponse {
        try {
            $overrides = $this->fieldService->getGroupfolderFieldOverrides($groupfolderId);
            return new DataResponse($overrides, Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Batch update file metadata for multiple files
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @CORS
     */
    public function batchUpdateFileMetadata(): DataResponse {
        try {
            $updates = $this->request->getParam('updates', []);
            
            if (empty($updates) || !is_array($updates)) {
                return new DataResponse([
                    'error' => 'updates array is required'
                ], Http::STATUS_BAD_REQUEST);
            }
            
            $results = $this->apiFieldService->batchUpdateFileMetadata($updates);
            
            $successCount = count(array_filter($results, fn($r) => $r['success']));
            
            return new DataResponse([
                'success' => true,
                'results' => $results,
                'total' => count($results),
                'successful' => $successCount,
                'failed' => count($results) - $successCount
            ], Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse([
                'error' => $e->getMessage()
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Batch delete file metadata
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @CORS
     */
    public function batchDeleteFileMetadata(): DataResponse {
        try {
            $deletes = $this->request->getParam('deletes', []);

            if (empty($deletes) || !is_array($deletes)) {
                return new DataResponse([
                    'error' => 'deletes array is required with format: [{"file_id": 123, "groupfolder_id": 1, "field_names": ["field1"] or null}]'
                ], Http::STATUS_BAD_REQUEST);
            }

            $results = $this->apiFieldService->batchDeleteFileMetadata($deletes);

            $successCount = count(array_filter($results, fn($r) => $r['success']));

            return new DataResponse([
                'success' => true,
                'results' => $results,
                'total' => count($results),
                'successful' => $successCount,
                'failed' => count($results) - $successCount
            ], Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse([
                'error' => $e->getMessage()
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Batch copy metadata from one file to multiple files
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @CORS
     */
    public function batchCopyFileMetadata(): DataResponse {
        try {
            $sourceFileId = (int)$this->request->getParam('source_file_id');
            $targetFileIds = $this->request->getParam('target_file_ids', []);
            $fieldNames = $this->request->getParam('field_names', null);
            
            if (!$sourceFileId) {
                return new DataResponse([
                    'error' => 'source_file_id is required'
                ], Http::STATUS_BAD_REQUEST);
            }
            
            if (empty($targetFileIds) || !is_array($targetFileIds)) {
                return new DataResponse([
                    'error' => 'target_file_ids array is required'
                ], Http::STATUS_BAD_REQUEST);
            }
            
            $result = $this->apiFieldService->batchCopyFileMetadata(
                $sourceFileId,
                $targetFileIds,
                $fieldNames
            );
            
            return new DataResponse($result, Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse([
                'error' => $e->getMessage()
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get metadata statistics (Admin only)
     *
     * @NoCSRFRequired
     * @CORS
     */
    public function getMetadataStatistics(): DataResponse {
        try {
            $stats = $this->apiFieldService->getMetadataStatistics();
            return new DataResponse($stats, Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse([
                'error' => $e->getMessage()
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
