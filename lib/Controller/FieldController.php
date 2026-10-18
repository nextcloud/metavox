<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Access\Access;
use OCA\MetaVox\Access\RequireAccess;
use OCA\MetaVox\Exception\MetadataAccessException;
use OCA\MetaVox\Exception\RequestException;
use OCA\MetaVox\Service\ApiValidationException;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\FileReferenceService;
use OCA\MetaVox\Service\LockService;
use OCA\MetaVox\Service\FileAccessService;
use OCA\MetaVox\Service\PushService;
use OCA\MetaVox\L10n\Translatable;
use OCA\MetaVox\Validation\FieldDefinition;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

class FieldController extends BaseController {

    private ISearch $collaboratorSearch;
    private IGroupManager $groupManager;
    private IRootFolder $rootFolder;
    private LockService $lockService;
    private PushService $pushService;
    private FileReferenceService $fileReferenceService;
    private FileAccessService $fileAccess;
    private LoggerInterface $logger;
    private IL10N $l;

    public function __construct(
        string $appName,
        IRequest $request,
        FieldService $fieldService,
        IUserSession $userSession,
        ISearch $collaboratorSearch,
        IGroupManager $groupManager,
        IRootFolder $rootFolder,
        LockService $lockService,
        PushService $pushService,
        FileReferenceService $fileReferenceService,
        FileAccessService $fileAccess,
        LoggerInterface $logger,
        IL10N $l
    ) {
        parent::__construct($appName, $request, $userSession, $fieldService);
        $this->rootFolder = $rootFolder;
        $this->collaboratorSearch = $collaboratorSearch;
        $this->groupManager = $groupManager;
        $this->lockService = $lockService;
        $this->pushService = $pushService;
        $this->fileReferenceService = $fileReferenceService;
        $this->fileAccess = $fileAccess;
        $this->logger = $logger;
        $this->l = $l;
    }

    /**
     * Search users for the user picker: ?search=, at most 25 results.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[RequireAccess(Access::LoggedIn, reason: 'user search for the user picker: whom you may find, as when sharing')]
    public function getUsers(): JSONResponse {
        $user = $this->requireUser();
        $search = $this->textParam('search') ?? '';
        $limit = 25;

        // Nextcloud's own sharee search, so the share settings apply: user
        // enumeration, only your own groups, phone book, full match.
        [$result] = $this->collaboratorSearch->search($search, [IShare::TYPE_USER], false, $limit, 0);

        // It leaves out the one searching; picking yourself is fine.
        $found = [];
        if ($search === '' || str_contains(mb_strtolower($user->getUID() . ' ' . $user->getDisplayName()), mb_strtolower($search))) {
            $found[$user->getUID()] = $user->getDisplayName();
        }
        foreach (array_merge($result['exact']['users'] ?? [], $result['users'] ?? []) as $item) {
            $uid = (string)($item['value']['shareWith'] ?? '');
            if ($uid !== '' && !isset($found[$uid])) {
                $found[$uid] = (string)($item['label'] ?? $uid);
            }
        }

        $users = [];
        foreach (array_slice($found, 0, $limit, true) as $uid => $name) {
            $users[] = ['id' => (string)$uid, 'displayname' => $name];
        }
        return new JSONResponse($users);
    }

    /**
     * Change a field definition: only what is sent changes. Admins only, like
     * the admin page.
     */
    #[RequireAccess(Access::NcAdmin)]
    public function updateGroupfolderField(int $id): JSONResponse {
        if (!$this->fieldService->updateField($id, $this->sentParams(FieldDefinition::PARAMS))) {
            throw RequestException::notFound(Translatable::t('Field not found'));
        }
        return new JSONResponse(['success' => true]);
    }

    #[RequireAccess(Access::NcAdmin)]
    public function deleteGroupfolderField(int $id): JSONResponse {
        if (!$this->fieldService->deleteField($id)) {
            throw RequestException::notFound(Translatable::t('Field not found'));
        }
        return new JSONResponse(['success' => true]);
    }

    /** Several fields at once; one still in use stays and is listed as skipped. */
    #[RequireAccess(Access::NcAdmin)]
    public function bulkDeleteFields(): JSONResponse {
        $this->requireUser();

        $ids = $this->getIdListParam('ids', required: true);
        return new JSONResponse(['success' => true] + $this->fieldService->deleteFields($ids));
    }

    #[NoAdminRequired]
    #[RequireAccess(Access::LoggedIn, reason: 'only the caller\'s own team folders; admins see all')]
    public function getGroupfolders(): JSONResponse {
        $user = $this->requireUser();

        $userId = $user->getUID();
        // Admins get all folders; scope=member gives only their own (Files app).
        $adminMode = $this->groupManager->isAdmin($userId) && $this->request->getParam('scope') !== 'member';
        $groupfolders = $this->fieldService->getGroupfolders($userId, $adminMode);
        return new JSONResponse($groupfolders);
    }

    #[NoAdminRequired]
    #[RequireAccess(Access::TeamFolderMember, folder: 'groupfolderId')]
    public function getGroupfolderMetadata(int $groupfolderId): JSONResponse {
        $user = $this->requireUser();

        $metadata = $this->fieldService->getGroupfolderMetadata($groupfolderId);
        return new JSONResponse($this->fileReferenceService->withNames($metadata, $user->getUID()));
    }

    #[NoAdminRequired]
    #[RequireAccess(Access::CanUpdateTeamFolder, folder: 'groupfolderId')]
    public function saveGroupfolderMetadata(int $groupfolderId): JSONResponse {
        $user = $this->requireUser();

        $this->fieldService->saveGroupfolderValues($groupfolderId, $this->mapParam('metadata'));
        return new JSONResponse(['success' => true]);
    }

    #[RequireAccess(Access::NcAdmin)]
    public function getGroupfolderFields(): JSONResponse {
        $fields = $this->fieldService->getFieldsByScope('groupfolder');
        return new JSONResponse($fields);
    }

    #[RequireAccess(Access::NcAdmin)]
    public function createGroupfolderField(): JSONResponse {
        $id = $this->fieldService->createField($this->sentParams(FieldDefinition::PARAMS));
        return new JSONResponse(['id' => $id, 'success' => true], Http::STATUS_CREATED);
    }

    #[NoAdminRequired]
    #[RequireAccess(Access::TeamFolderMember, folder: 'groupfolderId')]
    public function getGroupfolderAssignedFields(int $groupfolderId): JSONResponse {
        $user = $this->requireUser();

        $fields = $this->fieldService->getAssignedFieldsForGroupfolder($groupfolderId);
        return new JSONResponse($fields);
    }

    #[NoAdminRequired]
    #[RequireAccess(Access::MetaVoxManager, folder: 'groupfolderId')]
    public function setGroupfolderFields(int $groupfolderId): JSONResponse {
        $user = $this->requireUser();

        // Left out is no "none": a misspelled key would unassign every field.
        $this->requireParam('field_ids');
        $this->fieldService->setGroupfolderFields($groupfolderId, $this->getIdListParam('field_ids'));
        return new JSONResponse(['success' => true]);
    }

    /**
     * File-level fields assigned to a team folder: the grid's columns.
     */
    #[NoAdminRequired]
    #[RequireAccess(Access::TeamFolderMember, folder: 'groupfolderId')]
    public function getGroupfolderFileFields(int $groupfolderId): JSONResponse {
        $user = $this->requireUser();

        $fields = $this->fieldService->getAssignedFileFieldsForGroupfolder($groupfolderId);
        $response = new JSONResponse($fields);
        $response->addHeader('Cache-Control', 'private, max-age=600');
        return $response;
    }

    /**
     * Everything the sidebar shows for a file, in one answer: the team folder
     * the server finds it in, whether the user may change it (the rule every
     * save checks), its fields and the team folder's own fields.
     */
    #[NoAdminRequired]
    #[RequireAccess(Access::CanRead, file: 'fileId')]
    public function getFileMetadata(int $fileId): JSONResponse {
        $userId = $this->requireUser()->getUID();
        // Checked by the gate already, so this comes from the request's cache.
        $access = $this->fileAccess->getFileAccess($userId, $fileId);

        $fields = $this->fieldService->getGroupfolderFileMetadata($access->groupfolderId, $fileId);
        return new JSONResponse([
            'groupfolder_id' => $access->groupfolderId,
            'editable' => $access->canWrite,
            'fields' => $this->fileReferenceService->withNames($fields, $userId),
            'team_folder_fields' => $this->fileReferenceService->withNames($this->fieldService->getGroupfolderMetadata($access->groupfolderId), $userId),
        ]);
    }

    /**
     * List the files that reference this file through a File Link field
     * ("Referenced by" / backlinks). See issue #73.
     */
    #[NoAdminRequired]
    #[RequireAccess(Access::CanRead, folder: 'groupfolderId', file: 'fileId')]
    public function getFileBacklinks(int $groupfolderId, int $fileId): JSONResponse {
        $user = $this->requireUser();

        // Scoping to the team folder also lets the index do the work.
        $backlinks = $this->fileReferenceService->getBacklinks($fileId, $user->getUID(), $groupfolderId);
        return new JSONResponse($backlinks);
    }

    #[NoAdminRequired]
    #[RequireAccess(Access::CanUpdate, file: 'fileId')]
    public function saveFileMetadata(int $fileId): JSONResponse {
        $userId = $this->requireUser()->getUID();
        $groupfolderId = $this->fileAccess->getFileAccess($userId, $fileId)->groupfolderId;

        // Read before saving: a malformed one refuses the request, not half of it.
        $values = $this->mapParam('metadata');
        $unlock = $this->boolParam('unlock', false);
        $unlockField = $this->textParam('unlock_field');
        $this->fieldService->saveValues($groupfolderId, [$fileId => $values]);

        // Combined save+unlock: release lock and push event in one request
        if ($unlock && $unlockField
            && $this->lockService->unlock($groupfolderId, $fileId, $unlockField, $userId)) {
            $this->pushService->cellUnlocked($groupfolderId, $fileId, $unlockField);
        }

        return new JSONResponse(['success' => true]);
    }

    /**
     * Bulk update metadata for multiple files
     */
    #[NoAdminRequired]
    #[RequireAccess(Access::FileList)]
    public function saveBulkFileMetadata(): JSONResponse {
        $user = $this->requireUser();

        $userId = $user->getUID();
        $fileIds = $this->getIdListParam('fileIds');
        $metadata = $this->mapParam('metadata');
        $mergeStrategy = $this->choiceParam('mergeStrategy', ['overwrite', 'fill-empty'], 'overwrite');

        if (empty($fileIds)) {
            // TRANSLATORS Error message shown to the user when no files were selected; IDs are the numbers that identify the files
            throw RequestException::invalid(Translatable::t('No file IDs provided'));
        }
        if (empty($metadata)) {
            // TRANSLATORS Error message shown to the user when metadata is saved for several files without any value filled in
            throw RequestException::invalid(Translatable::t('No metadata provided'));
        }

        $values = $metadata;
        $this->fileAccess->prime($userId, $fileIds);
        $errors = [];
        $byFolder = [];
        foreach ($fileIds as $fileId) {
            try {
                $byFolder[$this->fileAccess->requireWritableFile($userId, $fileId)][$fileId] = $values;
            } catch (MetadataAccessException $e) {
                $errors[] = "File $fileId: " . $e->getMessage();
            }
        }

        // A value a field does not take is refused before anything is saved.
        $refused = [];
        foreach ($byFolder as $groupfolderId => $byFile) {
            $refused += array_merge([], ...array_values($this->fieldService->refusedValues($groupfolderId, $byFile)['refused']));
        }
        if ($refused !== []) {
            throw new ApiValidationException($refused);
        }

        $successCount = 0;
        foreach ($byFolder as $groupfolderId => $byFile) {
            try {
                // "fill-empty" leaves what is filled in, a stored '0' too. A file the
                // database refuses leaves the other files saved.
                $failed = $this->fieldService->saveValuesPerFile($groupfolderId, $byFile, $mergeStrategy === 'fill-empty');
            } catch (\Exception $e) {
                $this->logger->error('MetaVox: bulk save failed', ['groupfolderId' => $groupfolderId, 'exception' => $e]);
                $failed = array_keys($byFile);
            }
            $successCount += \count($byFile) - \count($failed);
            foreach ($failed as $fileId) {
                $errors[] = "File $fileId: Internal server error";
            }
        }
        $errorCount = \count($errors);

        return new JSONResponse([
            'success' => $errorCount === 0,
            'successCount' => $successCount,
            'errorCount' => $errorCount,
            'errors' => $errors,
        ]);
    }

    /**
     * Clear all metadata for multiple files
     */
    #[NoAdminRequired]
    #[RequireAccess(Access::FileList)]
    public function clearFileMetadata(): JSONResponse {
        $user = $this->requireUser();

        $userId = $user->getUID();
        $fileIds = $this->getIdListParam('fileIds');
        if ($fileIds === []) {
            // TRANSLATORS Error message shown to the user when an action on several files is started without any file selected
            throw RequestException::invalid(Translatable::t('No files specified'));
        }

        $files = $this->fileAccess->filterWritableFiles($userId, $fileIds);
        $errorCount = \count($files['denied']);
        $byFolder = [];
        foreach ($files['allowed'] as $fileId => $groupfolderId) {
            $byFolder[$groupfolderId][] = $fileId;
        }

        $successCount = 0;
        foreach ($byFolder as $groupfolderId => $ids) {
            try {
                // Required fields keep their values: clearing all is no way around them.
                $this->fieldService->clearFilesMetadata($groupfolderId, $ids, true);
                $successCount += \count($ids);
            } catch (\Exception $e) {
                $this->logger->error('MetaVox: clearing metadata failed', ['groupfolderId' => $groupfolderId, 'exception' => $e]);
                $errorCount += \count($ids);
            }
        }

        return new JSONResponse([
            'success' => $errorCount === 0,
            'successCount' => $successCount,
            'errorCount' => $errorCount,
        ]);
    }

    /**
     * Export metadata for multiple files
     */
    #[NoAdminRequired]
    #[RequireAccess(Access::FileList)]
    public function exportFileMetadata(): JSONResponse {
        $user = $this->requireUser();

        $userId = $user->getUID();
        $fileIds = $this->getIdListParam('fileIds');
        if ($fileIds === []) {
            // TRANSLATORS Error message shown to the user when an action on several files is started without any file selected
            throw RequestException::invalid(Translatable::t('No files specified'));
        }

        $userFolder = $this->rootFolder->getUserFolder($userId);
        $result = [];

        $this->fileAccess->prime($userId, $fileIds);
        foreach ($fileIds as $fileId) {
            try {
                // Only files the user sees in their team folder.
                $access = $this->fileAccess->getFileAccess($userId, (int)$fileId);
                $node = $access->node;
                if ($node === null) {
                    continue;
                }
                $groupfolderId = $access->groupfolderId;
                $name = $node->getName();
                $displayPath = str_replace($userFolder->getPath(), '', $node->getPath());

                $metadataFields = $this->fileReferenceService->withNames(
                    $this->fieldService->getGroupfolderFileMetadata($groupfolderId, (int)$fileId), $userId
                );
                $metadataMap = [];
                foreach ($metadataFields as $field) {
                    if (isset($field['field_name'])) {
                        $metadataMap[$field['field_name']] = isset($field['resolved']) ? $this->linkNames($field) : ($field['value'] ?? '');
                    }
                }

                $result[] = ['fileId' => $fileId, 'path' => $displayPath, 'name' => $name, 'metadata' => $metadataMap];
            } catch (\Exception $e) {
                $this->logger->error('MetaVox: exportFileMetadata error for file', ['fileId' => $fileId, 'exception' => $e]);
            }
        }

        return new JSONResponse($result);
    }

    /** An export shows linked files by name, as the exporter sees them. */
    private function linkNames(array $field): string {
        $tokens = FileReferenceService::parseValue((string)($field['value'] ?? ''));
        $names = [];
        foreach ($field['resolved'] as $i => $item) {
            // TRANSLATORS Shown instead of a file name when a linked file was deleted or the user cannot see it
            $names[] = $item['name'] ?? ($tokens[$i]['fileId'] === null ? $tokens[$i]['path'] : $this->l->t('Unavailable file'));
        }
        return implode(', ', $names);
    }
}
