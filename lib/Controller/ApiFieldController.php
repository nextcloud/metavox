<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Access\Access;
use OCA\MetaVox\Access\AccessContext;
use OCA\MetaVox\Access\RequireAccess;
use OCA\MetaVox\Exception\MetadataAccessException;
use OCA\MetaVox\Exception\RequestException;
use OCA\MetaVox\Service\ApiFieldService;
use OCA\MetaVox\Service\ApiValidationException;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\FileAccessService;
use OCA\MetaVox\Service\TeamFolderAccessService;
use OCA\MetaVox\Service\ViewColumnFormat;
use OCA\MetaVox\L10n\Translatable;
use OCA\MetaVox\Validation\FieldDefinition;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

class ApiFieldController extends BaseOCSController {

    private ApiFieldService $apiFieldService;
    private AccessContext $accessContext;
    private TeamFolderAccessService $teamFolders;

    public function __construct(
        string $appName,
        IRequest $request,
        FieldService $fieldService,
        ApiFieldService $apiFieldService,
        IUserSession $userSession,
        FileAccessService $fileAccess,
        TeamFolderAccessService $teamFolders,
        AccessContext $accessContext
    ) {
        parent::__construct($appName, $request, $userSession, $fieldService, $fileAccess);
        $this->apiFieldService = $apiFieldService;
        $this->accessContext = $accessContext;
        $this->teamFolders = $teamFolders;
    }

    /** The file's real team folder, as the access rule found it; never a guess (#98). */
    private function checkedGroupfolder(): int {
        return $this->accessContext->getGroupfolderId() ?? throw MetadataAccessException::notFound();
    }

    // ========================================
    // Field Definition CRUD (Admin only via NC middleware)
    // ========================================

    /**
     * Create groupfolder field (Admin only)
     */
    #[CORS]
    #[NoCSRFRequired]
    #[RequireAccess(Access::NcAdmin)]
    public function createGroupfolderField(): DataResponse {
        $id = $this->fieldService->createField($this->sentParams(FieldDefinition::PARAMS));
        return new DataResponse(['id' => $id, 'success' => true], Http::STATUS_CREATED);
    }

    /**
     * Change a field definition: only what is sent changes (Admin only)
     */
    #[CORS]
    #[NoCSRFRequired]
    #[RequireAccess(Access::NcAdmin)]
    public function updateGroupfolderField(int $id): DataResponse {
        if (!$this->fieldService->updateField($id, $this->sentParams(FieldDefinition::PARAMS))) {
            throw RequestException::notFound(Translatable::t('Field not found'));
        }
        return new DataResponse(['success' => true], Http::STATUS_OK);
    }

    /**
     * Delete a groupfolder field definition (Admin only)
     */
    #[CORS]
    #[NoCSRFRequired]
    #[RequireAccess(Access::NcAdmin)]
    public function deleteGroupfolderField(int $id): DataResponse {
        if (!$this->fieldService->deleteField($id)) {
            throw RequestException::notFound(Translatable::t('Field not found'));
        }
        return new DataResponse(['success' => true], Http::STATUS_OK);
    }

    /**
     * Set groupfolder fields
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[RequireAccess(Access::MetaVoxManager, folder: 'groupfolderId')]
    public function setGroupfolderFields(int $groupfolderId): DataResponse {
        // Left out is no "none": a misspelled key would unassign every field.
        $this->requireParam('field_ids');
        $this->fieldService->setGroupfolderFields($groupfolderId, $this->getIdListParam('field_ids'));
        return new DataResponse(['success' => true], Http::STATUS_OK);
    }

    /**
     * Get metadata statistics (Admin only)
     */
    #[CORS]
    #[NoCSRFRequired]
    #[RequireAccess(Access::NcAdmin)]
    public function getMetadataStatistics(): DataResponse {
        $stats = $this->apiFieldService->getMetadataStatistics();
        return new DataResponse($stats, Http::STATUS_OK);
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
    #[RequireAccess(Access::FileList)]
    public function getBulkFileMetadata(): DataResponse {
        $user = $this->requireUser();

        $fileIds = $this->getIdListParam('file_ids', required: true, max: 100);

        // Each file only returns values from its own team folder (#98).
        $fileGroupfolders = [];
        $this->fileAccess->prime($user->getUID(), $fileIds);
        foreach ($fileIds as $fileId) {
            $access = $this->fileAccess->getFileAccess($user->getUID(), $fileId);
            if ($access->canRead()) {
                $fileGroupfolders[$fileId] = $access->groupfolderId;
            }
        }
        if (empty($fileGroupfolders)) {
            throw self::nothingWritable(['not_found']);
        }

        $metadata = $this->apiFieldService->getBulkFileMetadataScoped($fileGroupfolders);
        return new DataResponse($metadata, Http::STATUS_OK);
    }

    /**
     * Get file metadata
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[RequireAccess(Access::CanRead, file: 'fileId')]
    public function getFileMetadata(int $fileId): DataResponse {
        $groupfolderId = $this->checkedGroupfolder();

        $metadata = $this->apiFieldService->getGroupfolderFileMetadata($groupfolderId, $fileId);
        return new DataResponse($metadata, Http::STATUS_OK);
    }

    /**
     * Save file metadata
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[RequireAccess(Access::CanUpdate, file: 'fileId')]
    public function saveFileMetadata(int $fileId): DataResponse {
        $groupfolderId = $this->checkedGroupfolder();

        $this->apiFieldService->saveGroupfolderFileMetadata($groupfolderId, $fileId, $this->mapParam('metadata'));

        return new DataResponse(['success' => true], Http::STATUS_OK);
    }

    /**
     * Get all groupfolders
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[RequireAccess(Access::LoggedIn, reason: 'only the caller\'s own team folders; admins see all')]
    public function getGroupfolders(): DataResponse {
        $user = $this->requireUser();

        // Same as web: admins get all folders, scope=member only their own.
        $adminMode = $this->teamFolders->isAdmin($user->getUID()) && $this->request->getParam('scope') !== 'member';
        $groupfolders = $this->fieldService->getGroupfolders($user->getUID(), $adminMode);
        return new DataResponse($groupfolders, Http::STATUS_OK);
    }

    /**
     * Get groupfolder metadata
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[RequireAccess(Access::TeamFolderMember, folder: 'groupfolderId')]
    public function getGroupfolderMetadata(int $groupfolderId): DataResponse {
        $user = $this->requireUser();

        $metadata = $this->fieldService->getGroupfolderMetadata($groupfolderId);
        return new DataResponse($metadata, Http::STATUS_OK);
    }

    /**
     * Save groupfolder metadata
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[RequireAccess(Access::CanUpdateTeamFolder, folder: 'groupfolderId')]
    public function saveGroupfolderMetadata(int $groupfolderId): DataResponse {
        $user = $this->requireUser();

        $this->fieldService->saveGroupfolderValues($groupfolderId, $this->mapParam('metadata'));
        return new DataResponse(['success' => true], Http::STATUS_OK);
    }

    /**
     * Get groupfolder fields
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[RequireAccess(Access::LoggedIn, reason: 'only the fields of the caller\'s team folders; all of them for admins and managers')]
    public function getGroupfolderFields(): DataResponse {
        $user = $this->requireUser();

        $fields = $this->fieldService->getCatalogue($this->teamFolders->catalogueFolders($user->getUID()));
        return new DataResponse($fields, Http::STATUS_OK);
    }

    /**
     * Get groupfolder file metadata
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[RequireAccess(Access::CanRead, folder: 'groupfolderId', file: 'fileId')]
    public function getGroupfolderFileMetadata(int $groupfolderId, int $fileId): DataResponse {
        $user = $this->requireUser();

        // The file must be in this team folder (#98).

        $metadata = $this->apiFieldService->getGroupfolderFileMetadata($groupfolderId, $fileId);
        return new DataResponse($metadata, Http::STATUS_OK);
    }

    /**
     * Save groupfolder file metadata
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[RequireAccess(Access::CanUpdate, folder: 'groupfolderId', file: 'fileId')]
    public function saveGroupfolderFileMetadata(int $groupfolderId, int $fileId): DataResponse {
        $user = $this->requireUser();

        // The file must be in this team folder (#98).

        $this->apiFieldService->saveGroupfolderFileMetadata($groupfolderId, $fileId, $this->mapParam('metadata'));

        return new DataResponse(['success' => true], Http::STATUS_OK);
    }

    /**
     * Get assigned fields for groupfolder
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[RequireAccess(Access::TeamFolderMember, folder: 'groupfolderId')]
    public function getGroupfolderAssignedFields(int $groupfolderId): DataResponse {
        $user = $this->requireUser();

        $fields = $this->fieldService->getAssignedFieldsWithDataForGroupfolder($groupfolderId);
        return new DataResponse($fields, Http::STATUS_OK);
    }

    /**
     * Get all file-level fields assigned to a groupfolder
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[RequireAccess(Access::TeamFolderMember, folder: 'groupfolderId')]
    public function getGroupfolderFileFields(int $groupfolderId): DataResponse {
        $user = $this->requireUser();

        $fields = $this->fieldService->getAssignedFileFieldsForGroupfolder($groupfolderId);
        $response = new DataResponse($fields, Http::STATUS_OK);
        $response->addHeader('Cache-Control', 'private, max-age=600');
        return $response;
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
    #[RequireAccess(Access::FileList)]
    public function batchUpdateFileMetadata(): DataResponse {
        $user = $this->requireUser();

        $updates = $this->batchItems('updates');

        // Refused items are reported back, not silently dropped.
        $authorized = $this->authorizeBatchWrites($updates, $user->getUID());
        if (empty($authorized['allowed'])) {
            throw self::nothingWritable(array_column($authorized['denied'], 'reason'), ['results' => $authorized['denied']]);
        }

        $results = array_merge(
            $this->apiFieldService->batchUpdateFileMetadata($authorized['allowed']),
            $authorized['denied']
        );
        $successCount = count(array_filter($results, fn($r) => $r['success']));

        return new DataResponse([
            'success' => true,
            'results' => $results,
            'total' => count($results),
            'successful' => $successCount,
            'failed' => count($results) - $successCount
        ], Http::STATUS_OK);
    }

    /**
     * Batch delete file metadata
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[RequireAccess(Access::FileList)]
    public function batchDeleteFileMetadata(): DataResponse {
        $user = $this->requireUser();

        $deletes = $this->batchItems('deletes');

        $authorized = $this->authorizeBatchWrites($deletes, $user->getUID());
        if (empty($authorized['allowed'])) {
            throw self::nothingWritable(array_column($authorized['denied'], 'reason'), ['results' => $authorized['denied']]);
        }

        $results = array_merge(
            $this->apiFieldService->batchDeleteFileMetadata($authorized['allowed']),
            $authorized['denied']
        );
        $successCount = count(array_filter($results, fn($r) => $r['success']));

        return new DataResponse([
            'success' => true,
            'results' => $results,
            'total' => count($results),
            'successful' => $successCount,
            'failed' => count($results) - $successCount
        ], Http::STATUS_OK);
    }

    /**
     * Batch copy metadata from one file to multiple files
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[RequireAccess(Access::FileList)]
    public function batchCopyFileMetadata(): DataResponse {
        $user = $this->requireUser();

        $userId = $user->getUID();
        $sourceFileId = $this->intParam('source_file_id', required: true, min: 1);
        $targetFileIds = $this->getIdListParam('target_file_ids', required: true);
        $fieldNames = $this->request->getParam('field_names') === null ? null : $this->getStringListParam('field_names');

        // Reading the source is enough (#98).
        $sourceGroupfolderId = $this->fileAccess->requireReadableFile($userId, $sourceFileId);

        // Only copy into targets the user may change.
        $targets = $this->fileAccess->filterWritableFiles($userId, $targetFileIds);
        if (empty($targets['allowed'])) {
            throw self::nothingWritable(array_map(fn ($e) => $e->getReason(), $targets['denied']));
        }

        $result = $this->apiFieldService->batchCopyFileMetadata($sourceGroupfolderId, $sourceFileId, $targets['allowed'], $fieldNames);
        return new DataResponse($result, Http::STATUS_OK);
    }

    /**
     * The items of a batch: a list of objects.
     *
     * @throws ApiValidationException for anything else
     */
    private function batchItems(string $name): array {
        $items = $this->listParam($name, required: true);
        foreach ($items as $item) {
            if (!is_array($item) || ($item !== [] && array_is_list($item))) {
                throw new ApiValidationException([$name => ViewColumnFormat::MSG_INVALID]);
            }
        }
        return $items;
    }

    /**
     * None of the files may be changed: 404 when the caller can't see any of
     * them, 403 when they can see some.
     *
     * @param string[] $reasons the reason each file was refused
     */
    private static function nothingWritable(array $reasons, array $data = []): RequestException {
        return array_diff($reasons, ['not_found']) === []
            // TRANSLATORS Error when none of the files a request names exists or can be seen by the user
            ? RequestException::notFound(Translatable::t('None of these files can be found'), $data)
            // TRANSLATORS Error when the user may not change any of the files a request names
            : RequestException::readOnly(Translatable::t('None of these files can be changed'), $data);
    }
}
