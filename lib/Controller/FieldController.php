<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\LockService;
use OCA\MetaVox\Service\PermissionService;
use OCA\MetaVox\Service\PushService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class FieldController extends BaseController {

    private IUserManager $userManager;
    private IGroupManager $groupManager;
    private LockService $lockService;
    private PushService $pushService;
    private LoggerInterface $logger;

    public function __construct(
        string $appName,
        IRequest $request,
        FieldService $fieldService,
        PermissionService $permissionService,
        IUserSession $userSession,
        IUserManager $userManager,
        IGroupManager $groupManager,
        IRootFolder $rootFolder,
        LockService $lockService,
        PushService $pushService,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request, $userSession, $permissionService, $fieldService, $rootFolder);
        $this->userManager = $userManager;
        $this->groupManager = $groupManager;
        $this->lockService = $lockService;
        $this->pushService = $pushService;
        $this->logger = $logger;
    }

    /**
     * Search users for user/group picker field.
     * Accepts ?search= parameter, returns max 25 results.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getUsers(): JSONResponse {
        try {
            $search = $this->request->getParam('search', '');
            $limit = 25;

            $users = $this->userManager->search($search, $limit);
            $userList = [];

            foreach ($users as $user) {
                $userList[] = [
                    'id' => $user->getUID(),
                    'displayname' => $user->getDisplayName(),
                ];
            }

            return new JSONResponse($userList);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update a field definition
     */
    #[NoAdminRequired]
    public function updateField(int $id): JSONResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof JSONResponse) return $user;
            if ($deny = $this->requireManagePermission($user->getUID())) return $deny;

            $fieldName = $this->request->getParam('field_name');
            $fieldLabel = $this->request->getParam('field_label');
            $fieldType = $this->request->getParam('field_type');
            $fieldDescription = $this->request->getParam('field_description', '');
            $fieldOptions = $this->request->getParam('field_options', '');
            $isRequired = $this->request->getParam('is_required', false);
            $sortOrder = $this->request->getParam('sort_order', 0);
            $appliesToGroupfolder = $this->request->getParam('applies_to_groupfolder');

            if (empty($fieldName) || empty($fieldLabel) || empty($fieldType)) {
                return new JSONResponse(['error' => 'Field name, label, and type are required'], Http::STATUS_BAD_REQUEST);
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
                return new JSONResponse(['success' => true, 'message' => 'Field updated successfully']);
            } else {
                return new JSONResponse(['error' => 'Failed to update field'], Http::STATUS_INTERNAL_SERVER_ERROR);
            }
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: updateField error', ['exception' => $e]);
            return new JSONResponse(['error' => 'Internal server error: ' . $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    public function deleteField(int $id): JSONResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof JSONResponse) return $user;
            if ($deny = $this->requireManagePermission($user->getUID())) return $deny;

            $success = $this->fieldService->deleteField($id);
            return new JSONResponse(['success' => $success]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    public function getGroupfolders(): JSONResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof JSONResponse) return $user;

            $userId = $user->getUID();
            $isAdmin = $this->groupManager->isAdmin($userId);
            $groupfolders = $this->fieldService->getGroupfolders($userId, $isAdmin);
            return new JSONResponse($groupfolders);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    public function getGroupfolderMetadata(int $groupfolderId): JSONResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof JSONResponse) return $user;
            if ($deny = $this->requireGroupfolderAccess($user->getUID(), $groupfolderId)) return $deny;

            $metadata = $this->fieldService->getGroupfolderMetadata($groupfolderId);
            return new JSONResponse($metadata);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    public function saveGroupfolderMetadata(int $groupfolderId): JSONResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof JSONResponse) return $user;
            if ($deny = $this->requireGroupfolderAccess($user->getUID(), $groupfolderId)) return $deny;

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

            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage(), 'success' => false], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    public function getGroupfolderFields(): JSONResponse {
        try {
            $fields = $this->fieldService->getFieldsByScope('groupfolder');
            return new JSONResponse($fields);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    public function createGroupfolderField(): JSONResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof JSONResponse) return $user;
            if ($deny = $this->requireManagePermission($user->getUID())) return $deny;

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

            $id = $this->fieldService->createField($fieldData);
            return new JSONResponse(['id' => $id, 'success' => true], Http::STATUS_CREATED);
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: createGroupfolderField error', ['exception' => $e]);
            return new JSONResponse(['error' => $e->getMessage(), 'success' => false], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update groupfolder field (alias for backward compatibility)
     */
    #[NoAdminRequired]
    public function updateGroupfolderField(int $id): JSONResponse {
        return $this->updateField($id);
    }

    /**
     * Delete groupfolder field (alias for backward compatibility)
     */
    #[NoAdminRequired]
    public function deleteGroupfolderField(int $id): JSONResponse {
        return $this->deleteField($id);
    }

    #[NoAdminRequired]
    public function getGroupfolderAssignedFields(int $groupfolderId): JSONResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof JSONResponse) return $user;
            if ($deny = $this->requireGroupfolderAccess($user->getUID(), $groupfolderId)) return $deny;

            $fields = $this->fieldService->getAssignedFieldsForGroupfolder($groupfolderId);
            return new JSONResponse($fields);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    public function setGroupfolderFields(int $groupfolderId): JSONResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof JSONResponse) return $user;
            if ($deny = $this->requireGroupfolderAccess($user->getUID(), $groupfolderId)) return $deny;
            if ($deny = $this->requireManagePermission($user->getUID(), $groupfolderId)) return $deny;

            $fieldIds = $this->request->getParam('field_ids', []);
            $success = $this->fieldService->setGroupfolderFields($groupfolderId, $fieldIds);
            return new JSONResponse(['success' => $success]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    public function getGroupfolderFileMetadata(int $groupfolderId, int $fileId): JSONResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof JSONResponse) return $user;
            if ($deny = $this->requireGroupfolderAccess($user->getUID(), $groupfolderId)) return $deny;

            $metadata = $this->fieldService->getGroupfolderFileMetadata($groupfolderId, $fileId);
            return new JSONResponse($metadata);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    public function saveGroupfolderFileMetadata(int $groupfolderId, int $fileId): JSONResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof JSONResponse) return $user;
            if ($deny = $this->requireGroupfolderAccess($user->getUID(), $groupfolderId)) return $deny;

            $metadata = $this->request->getParam('metadata', []);

            $fields = $this->fieldService->getFieldsByScope('groupfolder');
            $fieldMap = [];
            foreach ($fields as $field) {
                $fieldMap[$field['field_name']] = $field['id'];
            }

            foreach ($metadata as $fieldName => $value) {
                if (isset($fieldMap[$fieldName])) {
                    $this->fieldService->saveGroupfolderFileFieldValue($groupfolderId, $fileId, $fieldMap[$fieldName], (string)$value, $fieldName);
                }
            }

            // Combined save+unlock: release lock and push event in one request
            $unlock = $this->request->getParam('unlock');
            $unlockField = $this->request->getParam('unlock_field');
            if ($unlock && $unlockField) {
                $this->lockService->unlock($groupfolderId, $fileId, $unlockField, $user->getUID());
                $this->pushService->cellUnlocked($groupfolderId, $fileId, $unlockField);
            }

            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Bulk update metadata for multiple files
     */
    #[NoAdminRequired]
    public function saveBulkFileMetadata(): JSONResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof JSONResponse) return $user;

            $userId = $user->getUID();
            $fileIds = $this->request->getParam('fileIds', []);
            $metadata = $this->request->getParam('metadata', []);
            $mergeStrategy = $this->request->getParam('mergeStrategy', 'overwrite');

            if (empty($fileIds)) {
                return new JSONResponse(['error' => 'No file IDs provided'], Http::STATUS_BAD_REQUEST);
            }
            if (empty($metadata)) {
                return new JSONResponse(['error' => 'No metadata provided'], Http::STATUS_BAD_REQUEST);
            }

            $userFolder = $this->rootFolder->getUserFolder($userId);
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
                    $nodes = $userFolder->getById((int)$fileId);
                    if (empty($nodes)) {
                        $errors[] = "File $fileId: Access denied";
                        $errorCount++;
                        continue;
                    }

                    $node = $nodes[0];
                    $path = $node->getPath();

                    $groupfolderId = $this->detectGroupfolderFromPath($path, $userId);
                    if (!$groupfolderId) {
                        $errors[] = "File $fileId: Not in a groupfolder";
                        $errorCount++;
                        continue;
                    }

                    $existingMetadataMap = [];
                    if ($mergeStrategy === 'fill-empty') {
                        $existingFields = $this->fieldService->getGroupfolderFileMetadata($groupfolderId, (int)$fileId);
                        foreach ($existingFields as $field) {
                            if (isset($field['field_name'])) {
                                $existingMetadataMap[$field['field_name']] = $field['value'] ?? '';
                            }
                        }
                    }

                    foreach ($metadata as $fieldName => $value) {
                        if (!isset($fieldMap[$fieldName])) {
                            continue;
                        }
                        if ($mergeStrategy === 'fill-empty') {
                            $existingValue = $existingMetadataMap[$fieldName] ?? '';
                            if (!empty($existingValue)) {
                                continue;
                            }
                        }
                        $this->fieldService->saveGroupfolderFileFieldValue(
                            $groupfolderId, (int)$fileId, $fieldMap[$fieldName], (string)$value, $fieldName
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
            $this->logger->error('MetaVox: saveBulkFileMetadata error', ['exception' => $e]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Clear all metadata for multiple files
     */
    #[NoAdminRequired]
    public function clearFileMetadata(): JSONResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof JSONResponse) return $user;

            $userId = $user->getUID();
            $fileIds = $this->request->getParam('fileIds', []);
            if (empty($fileIds)) {
                return new JSONResponse(['error' => 'No files specified'], Http::STATUS_BAD_REQUEST);
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

                    $groupfolderId = $this->detectGroupfolderFromPath($nodes[0]->getPath(), $userId);
                    if (!$groupfolderId) {
                        $errorCount++;
                        continue;
                    }

                    $this->fieldService->clearGroupfolderFileMetadata($groupfolderId, (int)$fileId);
                    $successCount++;
                } catch (\Exception $e) {
                    $this->logger->error('MetaVox: clearFileMetadata error for file', ['fileId' => $fileId, 'exception' => $e]);
                    $errorCount++;
                }
            }

            return new JSONResponse([
                'status' => 'success',
                'successCount' => $successCount,
                'errorCount' => $errorCount,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: clearFileMetadata error', ['exception' => $e]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Export metadata for multiple files
     */
    #[NoAdminRequired]
    public function exportFileMetadata(): JSONResponse {
        try {
            $user = $this->requireUser();
            if ($user instanceof JSONResponse) return $user;

            $userId = $user->getUID();
            $fileIds = $this->request->getParam('fileIds', []);
            if (empty($fileIds)) {
                return new JSONResponse(['error' => 'No files specified'], Http::STATUS_BAD_REQUEST);
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
                    $displayPath = str_replace($userFolder->getPath(), '', $path);

                    $groupfolderId = $this->detectGroupfolderFromPath($path, $userId);
                    if (!$groupfolderId) {
                        $result[] = ['fileId' => $fileId, 'path' => $displayPath, 'name' => $name, 'metadata' => []];
                        continue;
                    }

                    $metadataFields = $this->fieldService->getGroupfolderFileMetadata($groupfolderId, (int)$fileId);
                    $metadataMap = [];
                    foreach ($metadataFields as $field) {
                        if (isset($field['field_name'])) {
                            $metadataMap[$field['field_name']] = $field['value'] ?? '';
                        }
                    }

                    $result[] = ['fileId' => $fileId, 'path' => $displayPath, 'name' => $name, 'metadata' => $metadataMap];
                } catch (\Exception $e) {
                    $this->logger->error('MetaVox: exportFileMetadata error for file', ['fileId' => $fileId, 'exception' => $e]);
                }
            }

            return new JSONResponse($result);
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: exportFileMetadata error', ['exception' => $e]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Detect groupfolder ID from file path
     */
    private function detectGroupfolderFromPath(string $path, string $userId): ?int {
        try {
            if (preg_match('/\/__groupfolders\/(\d+)/', $path, $matches)) {
                return (int)$matches[1];
            }

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
