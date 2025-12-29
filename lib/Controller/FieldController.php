<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Service\FieldService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IUserManager;
use OCP\Files\IRootFolder;

class FieldController extends Controller {

    private FieldService $fieldService;
    private IUserSession $userSession;
    private IUserManager $userManager;
    private IRootFolder $rootFolder;

    public function __construct(
        string $appName,
        IRequest $request,
        FieldService $fieldService,
        IUserSession $userSession,
        IUserManager $userManager,
        IRootFolder $rootFolder
    ) {
        parent::__construct($appName, $request);
        $this->fieldService = $fieldService;
        $this->userSession = $userSession;
        $this->userManager = $userManager;
        $this->rootFolder = $rootFolder;
    }

    /**
     * Get all users for user/group picker field
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getUsers(): JSONResponse {
        try {
            $users = $this->userManager->search('');
            $userList = [];

            foreach ($users as $user) {
                $userList[] = [
                    'id' => $user->getUID(),
                    'displayname' => $user->getDisplayName(),
                ];
            }

            return new JSONResponse($userList);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * updateField method for edit functionality
     * @NoAdminRequired
     */
    public function updateField(int $id): JSONResponse {
    try {
        error_log('MetaVox FieldController::updateField called with ID: ' . $id);
        
        // Get all input parameters
        $fieldName = $this->request->getParam('field_name');
        $fieldLabel = $this->request->getParam('field_label');
        $fieldType = $this->request->getParam('field_type');
        $fieldDescription = $this->request->getParam('field_description', ''); // ← ADD THIS LINE
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
            'field_description' => trim($fieldDescription), // ← ADD THIS LINE
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
    public function getGroupfolders(): JSONResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'User not authenticated'], 401);
            }

            $userId = $user->getUID();
            $groupfolders = $this->fieldService->getGroupfolders($userId);
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
            'field_description' => $this->request->getParam('field_description', ''), // ← ADD THIS LINE
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
     * Update groupfolder field (alias for updateField for backward compatibility)
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
     * Bulk update metadata for multiple files
     * @NoAdminRequired
     */
    public function saveBulkFileMetadata(): JSONResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'User not authenticated'], 401);
            }

            $fileIds = $this->request->getParam('fileIds', []);
            $metadata = $this->request->getParam('metadata', []);
            $mergeStrategy = $this->request->getParam('mergeStrategy', 'overwrite');

            if (empty($fileIds)) {
                return new JSONResponse(['error' => 'No file IDs provided'], 400);
            }

            if (empty($metadata)) {
                return new JSONResponse(['error' => 'No metadata provided'], 400);
            }

            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            $fields = $this->fieldService->getFieldsByScope('groupfolder');
            $fieldMap = [];
            foreach ($fields as $field) {
                $fieldMap[$field['field_name']] = $field['id'];
            }

            $successCount = 0;
            $errorCount = 0;
            $errors = [];

            foreach ($fileIds as $fileId) {
                try {
                    // Verify user has access to this file
                    $nodes = $userFolder->getById((int)$fileId);
                    if (empty($nodes)) {
                        $errors[] = "File $fileId: Access denied";
                        $errorCount++;
                        continue;
                    }

                    $node = $nodes[0];
                    $path = $node->getPath();

                    // Detect groupfolder from path
                    $groupfolderId = $this->detectGroupfolderFromPath($path, $user->getUID());
                    if (!$groupfolderId) {
                        $errors[] = "File $fileId: Not in a groupfolder";
                        $errorCount++;
                        continue;
                    }

                    // Get existing metadata if merge strategy is fill-empty
                    $existingMetadataMap = [];
                    if ($mergeStrategy === 'fill-empty') {
                        // getGroupfolderFileMetadata returns array of field objects with 'field_name' and 'value'
                        $existingFields = $this->fieldService->getGroupfolderFileMetadata($groupfolderId, (int)$fileId);
                        foreach ($existingFields as $field) {
                            if (isset($field['field_name'])) {
                                $existingMetadataMap[$field['field_name']] = $field['value'] ?? '';
                            }
                        }
                    }

                    // Save metadata for this file
                    foreach ($metadata as $fieldName => $value) {
                        if (!isset($fieldMap[$fieldName])) {
                            continue;
                        }

                        // Skip if fill-empty and field already has value
                        if ($mergeStrategy === 'fill-empty') {
                            $existingValue = $existingMetadataMap[$fieldName] ?? '';
                            if (!empty($existingValue)) {
                                continue;
                            }
                        }

                        $this->fieldService->saveGroupfolderFileFieldValue(
                            $groupfolderId,
                            (int)$fileId,
                            $fieldMap[$fieldName],
                            (string)$value
                        );
                    }

                    $successCount++;
                } catch (\Exception $e) {
                    $errors[] = "File $fileId: " . $e->getMessage();
                    $errorCount++;
                }
            }

            return new JSONResponse([
                'success' => $errorCount === 0,
                'successCount' => $successCount,
                'errorCount' => $errorCount,
                'errors' => $errors,
            ]);
        } catch (\Exception $e) {
            error_log('MetaVox saveBulkFileMetadata error: ' . $e->getMessage());
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Clear all metadata for multiple files
     * @NoAdminRequired
     */
    public function clearFileMetadata(): JSONResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'Not authenticated'], 401);
            }
            $userId = $user->getUID();

            $fileIds = $this->request->getParam('fileIds', []);
            if (empty($fileIds)) {
                return new JSONResponse(['error' => 'No files specified'], 400);
            }

            $userFolder = $this->rootFolder->getUserFolder($userId);
            $successCount = 0;
            $errorCount = 0;

            foreach ($fileIds as $fileId) {
                try {
                    $nodes = $userFolder->getById((int)$fileId);
                    if (empty($nodes)) {
                        $errorCount++;
                        continue;
                    }

                    $node = $nodes[0];
                    $path = $node->getPath();

                    // Detect groupfolder
                    $groupfolderId = $this->detectGroupfolderFromPath($path, $userId);
                    if (!$groupfolderId) {
                        $errorCount++;
                        continue;
                    }

                    // Clear metadata for this file
                    $this->fieldService->clearGroupfolderFileMetadata($groupfolderId, (int)$fileId);
                    $successCount++;
                } catch (\Exception $e) {
                    error_log('MetaVox clearFileMetadata error for file ' . $fileId . ': ' . $e->getMessage());
                    $errorCount++;
                }
            }

            return new JSONResponse([
                'status' => 'success',
                'successCount' => $successCount,
                'errorCount' => $errorCount,
            ]);
        } catch (\Exception $e) {
            error_log('MetaVox clearFileMetadata error: ' . $e->getMessage());
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Export metadata for multiple files
     * @NoAdminRequired
     */
    public function exportFileMetadata(): JSONResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'Not authenticated'], 401);
            }
            $userId = $user->getUID();

            $fileIds = $this->request->getParam('fileIds', []);
            if (empty($fileIds)) {
                return new JSONResponse(['error' => 'No files specified'], 400);
            }

            $userFolder = $this->rootFolder->getUserFolder($userId);
            $result = [];

            foreach ($fileIds as $fileId) {
                try {
                    $nodes = $userFolder->getById((int)$fileId);
                    if (empty($nodes)) {
                        continue;
                    }

                    $node = $nodes[0];
                    $path = $node->getPath();
                    $name = $node->getName();

                    // Remove user folder prefix from path for display
                    $displayPath = str_replace($userFolder->getPath(), '', $path);

                    // Detect groupfolder
                    $groupfolderId = $this->detectGroupfolderFromPath($path, $userId);
                    if (!$groupfolderId) {
                        $result[] = [
                            'fileId' => $fileId,
                            'path' => $displayPath,
                            'name' => $name,
                            'metadata' => [],
                        ];
                        continue;
                    }

                    // Get metadata for this file
                    $metadataFields = $this->fieldService->getGroupfolderFileMetadata($groupfolderId, (int)$fileId);

                    // Convert to key-value map
                    $metadataMap = [];
                    foreach ($metadataFields as $field) {
                        if (isset($field['field_name'])) {
                            $metadataMap[$field['field_name']] = $field['value'] ?? '';
                        }
                    }

                    $result[] = [
                        'fileId' => $fileId,
                        'path' => $displayPath,
                        'name' => $name,
                        'metadata' => $metadataMap,
                    ];
                } catch (\Exception $e) {
                    error_log('MetaVox exportFileMetadata error for file ' . $fileId . ': ' . $e->getMessage());
                }
            }

            return new JSONResponse($result);
        } catch (\Exception $e) {
            error_log('MetaVox exportFileMetadata error: ' . $e->getMessage());
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Detect groupfolder ID from file path
     */
    private function detectGroupfolderFromPath(string $path, string $userId): ?int {
        try {
            // Check for __groupfolders path pattern
            if (preg_match('/\/__groupfolders\/(\d+)/', $path, $matches)) {
                return (int)$matches[1];
            }

            // Get groupfolders and check mount points
            $groupfolders = $this->fieldService->getGroupfolders($userId);
            foreach ($groupfolders as $gf) {
                $mountPoint = $gf['mount_point'] ?? '';
                if (!empty($mountPoint) && strpos($path, "/$mountPoint/") !== false) {
                    return (int)$gf['id'];
                }
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
}