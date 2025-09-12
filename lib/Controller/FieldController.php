<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Service\FieldService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class FieldController extends Controller {

    private FieldService $fieldService;

    public function __construct(string $appName, IRequest $request, FieldService $fieldService) {
        parent::__construct($appName, $request);
        $this->fieldService = $fieldService;
    }

    /**
     * @NoAdminRequired
     */
    public function getFields(): JSONResponse {
        try {
            $fields = $this->fieldService->getAllFields();
            return new JSONResponse($fields);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * 🆕 NEW: Get single field by ID (for edit modal)
     * @NoAdminRequired
     */
    public function getField(int $id): JSONResponse {
        try {
            error_log('MetaVox FieldController::getField called with ID: ' . $id);
            
            $field = $this->fieldService->getFieldById($id);
            
            if (!$field) {
                return new JSONResponse(['error' => 'Field not found'], 404);
            }
            
            error_log('MetaVox FieldController::getField success: ' . json_encode($field));
            return new JSONResponse($field);
            
        } catch (\Exception $e) {
            error_log('MetaVox FieldController::getField error: ' . $e->getMessage());
            return new JSONResponse(['error' => 'Internal server error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function createField(): JSONResponse {
        try {
            $fieldData = [
                'field_name' => $this->request->getParam('field_name'),
                'field_label' => $this->request->getParam('field_label'),
                'field_type' => $this->request->getParam('field_type', 'text'),
                'field_options' => $this->request->getParam('field_options', []),
                'is_required' => $this->request->getParam('is_required', false),
                'sort_order' => $this->request->getParam('sort_order', 0),
                'scope' => 'global', // Default scope voor normale velden
            ];

            $id = $this->fieldService->createField($fieldData);
            return new JSONResponse(['id' => $id]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * 🆕 UPDATED: Enhanced updateField method for edit functionality
     * @NoAdminRequired
     */
    public function updateField(int $id): JSONResponse {
        try {
            error_log('MetaVox FieldController::updateField called with ID: ' . $id);
            
            // Get all input parameters
            $fieldName = $this->request->getParam('field_name');
            $fieldLabel = $this->request->getParam('field_label');
            $fieldType = $this->request->getParam('field_type');
            $fieldOptions = $this->request->getParam('field_options', '');
            $isRequired = $this->request->getParam('is_required', false);
            $sortOrder = $this->request->getParam('sort_order', 0);
            $appliesToGroupfolder = $this->request->getParam('applies_to_groupfolder');
            
            // Validate required fields
            if (empty($fieldName) || empty($fieldLabel) || empty($fieldType)) {
                return new JSONResponse(['error' => 'Field name, label, and type are required'], 400);
            }
            
            // Prepare field data
            $fieldData = [
                'field_name' => trim($fieldName),
                'field_label' => trim($fieldLabel),
                'field_type' => $fieldType,
                'field_options' => $fieldOptions,
                'is_required' => (bool)$isRequired,
                'sort_order' => (int)$sortOrder,
            ];
            
            // Add applies_to_groupfolder if provided
            if ($appliesToGroupfolder !== null) {
                $fieldData['applies_to_groupfolder'] = (int)$appliesToGroupfolder;
            }
            
            error_log('MetaVox FieldController::updateField data: ' . json_encode($fieldData));
            
            // Update the field
            $success = $this->fieldService->updateField($id, $fieldData);
            
            if ($success) {
                error_log('MetaVox FieldController::updateField success');
                return new JSONResponse(['success' => true, 'message' => 'Field updated successfully']);
            } else {
                error_log('MetaVox FieldController::updateField failed');
                return new JSONResponse(['error' => 'Failed to update field'], 500);
            }
            
        } catch (\Exception $e) {
            error_log('MetaVox FieldController::updateField error: ' . $e->getMessage());
            error_log('MetaVox FieldController::updateField error trace: ' . $e->getTraceAsString());
            return new JSONResponse(['error' => 'Internal server error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function deleteField(int $id): JSONResponse {
        try {
            $success = $this->fieldService->deleteField($id);
            return new JSONResponse(['success' => $success]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function getFileMetadata(int $fileId): JSONResponse {
        try {
            $metadata = $this->fieldService->getFieldMetadata($fileId);
            return new JSONResponse($metadata);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function saveFileMetadata(int $fileId): JSONResponse {
        try {
            $metadata = $this->request->getParam('metadata', []);
            
            // Get only global fields to map field names to IDs
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

            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function getGroupfolders(): JSONResponse {
        try {
            $groupfolders = $this->fieldService->getGroupfolders();
            return new JSONResponse($groupfolders);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function getGroupfolderMetadata(int $groupfolderId): JSONResponse {
        try {
            $metadata = $this->fieldService->getGroupfolderMetadata($groupfolderId);
            return new JSONResponse($metadata);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired  
     */
    public function saveGroupfolderMetadata(int $groupfolderId): JSONResponse {
        try {
            $metadata = $this->request->getParam('metadata', []);
            
            error_log('TesterMeta saveGroupfolderMetadata: groupfolder=' . $groupfolderId . ', metadata=' . json_encode($metadata));
            
            $fields = $this->fieldService->getFieldsByScope('groupfolder');
            $fieldMap = [];
            foreach ($fields as $field) {
                $fieldMap[$field['field_name']] = $field['id'];
            }
            
            error_log('TesterMeta saveGroupfolderMetadata: Found ' . count($fields) . ' groupfolder fields');
            
            foreach ($metadata as $fieldName => $value) {
                if (isset($fieldMap[$fieldName])) {
                    $result = $this->fieldService->saveGroupfolderFieldValue($groupfolderId, $fieldMap[$fieldName], (string)$value);
                    error_log('TesterMeta saveGroupfolderMetadata: Saved field ' . $fieldName . ', result: ' . ($result ? 'success' : 'failed'));
                } else {
                    error_log('TesterMeta saveGroupfolderMetadata: Field not found in map: ' . $fieldName);
                }
            }

            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            error_log('TesterMeta saveGroupfolderMetadata error: ' . $e->getMessage());
            error_log('TesterMeta saveGroupfolderMetadata error trace: ' . $e->getTraceAsString());
            return new JSONResponse(['error' => $e->getMessage(), 'success' => false], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function getGroupfolderFields(): JSONResponse {
        try {
            $fields = $this->fieldService->getFieldsByScope('groupfolder');
            return new JSONResponse($fields);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function createGroupfolderField(): JSONResponse {
        try {
            $fieldData = [
                'field_name' => $this->request->getParam('field_name'),
                'field_label' => $this->request->getParam('field_label'),
                'field_type' => $this->request->getParam('field_type', 'text'),
                'field_options' => $this->request->getParam('field_options', []),
                'is_required' => $this->request->getParam('is_required', false),
                'sort_order' => $this->request->getParam('sort_order', 0),
                'scope' => 'groupfolder', // Markeer als groupfolder veld
                'applies_to_groupfolder' => $this->request->getParam('applies_to_groupfolder', false), // 🆕 NIEUWE PARAMETER
            ];

            $id = $this->fieldService->createField($fieldData);
            return new JSONResponse(['id' => $id, 'success' => true]);
        } catch (\Exception $e) {
            error_log('TesterMeta createGroupfolderField error: ' . $e->getMessage());
            return new JSONResponse(['error' => $e->getMessage(), 'success' => false], 500);
        }
    }

    /**
     * 🆕 NEW: Update groupfolder field (alias for updateField for backward compatibility)
     * @NoAdminRequired
     */
    public function updateGroupfolderField(int $id): JSONResponse {
        return $this->updateField($id);
    }

    /**
     * @NoAdminRequired
     */
    public function getGroupfolderAssignedFields(int $groupfolderId): JSONResponse {
        try {
            $fields = $this->fieldService->getAssignedFieldsForGroupfolder($groupfolderId);
            return new JSONResponse($fields);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function setGroupfolderFields(int $groupfolderId): JSONResponse {
        try {
            $fieldIds = $this->request->getParam('field_ids', []);
            $success = $this->fieldService->setGroupfolderFields($groupfolderId, $fieldIds);
            return new JSONResponse(['success' => $success]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function getGroupfolderFileMetadata(int $groupfolderId, int $fileId): JSONResponse {
        try {
            $metadata = $this->fieldService->getGroupfolderFileMetadata($groupfolderId, $fileId);
            return new JSONResponse($metadata);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function saveGroupfolderFileMetadata(int $groupfolderId, int $fileId): JSONResponse {
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

            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * 🆕 NIEUWE FUNCTIE - Save field override for specific groupfolder
     * 
     * @NoAdminRequired
     * @param int $groupfolderId
     * @return JSONResponse
     */
    public function saveFieldOverride(int $groupfolderId): JSONResponse {
        try {
            $fieldName = $this->request->getParam('field_name');
            $appliesToGroupfolder = (int) $this->request->getParam('applies_to_groupfolder', 0);
            
            // Validatie
            if (empty($fieldName)) {
                return new JSONResponse(['success' => false, 'message' => 'Field name is required'], 400);
            }
            
            error_log('TesterMeta saveFieldOverride: groupfolder=' . $groupfolderId . ', field=' . $fieldName . ', applies=' . $appliesToGroupfolder);
            
            $success = $this->fieldService->saveGroupfolderFieldOverride($groupfolderId, $fieldName, $appliesToGroupfolder);
            
            if ($success) {
                error_log('TesterMeta saveFieldOverride: SUCCESS');
                return new JSONResponse(['success' => true]);
            } else {
                error_log('TesterMeta saveFieldOverride: FAILED');
                return new JSONResponse(['success' => false, 'message' => 'Failed to save field override'], 500);
            }
            
        } catch (\Exception $e) {
            error_log('TesterMeta saveFieldOverride ERROR: ' . $e->getMessage());
            return new JSONResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 🆕 NIEUWE FUNCTIE - Get field overrides for specific groupfolder
     * 
     * @NoAdminRequired
     * @param int $groupfolderId
     * @return JSONResponse
     */
    public function getFieldOverrides(int $groupfolderId): JSONResponse {
        try {
            $overrides = $this->fieldService->getGroupfolderFieldOverrides($groupfolderId);
            return new JSONResponse($overrides);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }
}