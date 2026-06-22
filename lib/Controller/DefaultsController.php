<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\AppInfo\Application;
use OCA\MetaVox\BackgroundJobs\DiscoverMissingDefaultsJob;
use OCA\MetaVox\Service\DefaultsService;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\FileReferenceService;
use OCA\MetaVox\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Web UI endpoints for per-folder default values:
 *  - setDefault: configure a field's default for a folder
 *  - trigger:    reset the discovery cursor and force a discovery run, so an
 *                admin need not wait for the 5-minute cron after changing a
 *                default. Does NOT process inline — it queues work and returns
 *                immediately, so a 1M-file folder cannot time out the request.
 *  - status:     cheap "is the backfill still running?" signal for the UI poll.
 *
 * All endpoints require manage_fields permission on the groupfolder.
 */
class DefaultsController extends BaseController {

    public function __construct(
        string $appName,
        IRequest $request,
        FieldService $fieldService,
        PermissionService $permissionService,
        IUserSession $userSession,
        IRootFolder $rootFolder,
        private readonly DefaultsService $defaultsService,
        private readonly FileReferenceService $fileReferenceService,
        private readonly IJobList $jobList,
        private readonly IConfig $config,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request, $userSession, $permissionService, $fieldService, $rootFolder);
    }

    /**
     * Get the configured defaults for a folder, keyed by field id, so the
     * management UI can prefill its inputs.
     */
    #[NoAdminRequired]
    public function getDefaults(int $groupfolderId): JSONResponse {
        $user = $this->requireUser();
        if ($user instanceof JSONResponse) {
            return $user;
        }
        if ($denied = $this->requireManagePermission($user->getUID(), $groupfolderId)) {
            return $denied;
        }

        return new JSONResponse(['defaults' => $this->defaultsService->getFolderDefaultsByFieldId($groupfolderId)]);
    }

    /**
     * Set or clear the default value for a field in a groupfolder.
     * Body: { fieldId: int, value: string|null }
     */
    #[NoAdminRequired]
    public function setDefault(int $groupfolderId): JSONResponse {
        $user = $this->requireUser();
        if ($user instanceof JSONResponse) {
            return $user;
        }
        if ($denied = $this->requireManagePermission($user->getUID(), $groupfolderId)) {
            return $denied;
        }

        $fieldId = (int)$this->request->getParam('fieldId', 0);
        $value = $this->request->getParam('value', null);
        if ($value !== null) {
            $value = (string)$value;
        }

        if ($fieldId <= 0) {
            return new JSONResponse(['error' => 'fieldId is required'], Http::STATUS_BAD_REQUEST);
        }

        // For file-link defaults the picker yields a bare path; resolve it to the
        // canonical "<fileid>:path" form (and dedup) before validating/storing,
        // exactly like a normal file-link value save.
        if ($value !== null && $value !== '') {
            $field = $this->fieldService->getFieldById($fieldId);
            if ($field !== null && ($field['field_type'] ?? '') === 'filelink') {
                $resolved = [];
                foreach (FileReferenceService::parseValue($value) as $token) {
                    if ($token['fileId'] === null) {
                        $id = $this->fileReferenceService->resolvePathToFileId($token['path'], $user->getUID());
                        if ($id !== null) {
                            $token['fileId'] = $id;
                        }
                    }
                    $resolved[] = $token;
                }
                $value = FileReferenceService::joinTokens(FileReferenceService::dedupeTokens($resolved));
            }
        }

        // Validate the default against the field type (date format, option
        // membership, numeric, user exists, file-link resolvable, …).
        $validationError = $this->fieldService->validateDefaultValue($fieldId, $value);
        if ($validationError !== null) {
            return new JSONResponse(['error' => $validationError], Http::STATUS_BAD_REQUEST);
        }

        $ok = $this->defaultsService->setFieldDefault($groupfolderId, $fieldId, $value);
        if (!$ok) {
            return new JSONResponse(
                ['error' => 'Field is not an assigned file field for this folder'],
                Http::STATUS_BAD_REQUEST
            );
        }

        return new JSONResponse(['success' => true]);
    }

    /**
     * Reset the discovery cursor for this folder and queue a discovery run, so
     * newly configured defaults are applied without waiting for the cron tick.
     */
    #[NoAdminRequired]
    public function trigger(int $groupfolderId): JSONResponse {
        $user = $this->requireUser();
        if ($user instanceof JSONResponse) {
            return $user;
        }
        if ($denied = $this->requireManagePermission($user->getUID(), $groupfolderId)) {
            return $denied;
        }

        // Reset this folder's cursor so the next discovery run rescans from the
        // top and picks up files that the changed defaults now apply to.
        $this->config->setAppValue(Application::APP_ID, 'defaults_cursor_gf_' . $groupfolderId, '0');

        // Ensure a discovery run is queued. The TimedJob is registered, but
        // adding it to the job list nudges it to run on the next cron tick
        // rather than waiting out its full interval.
        if (!$this->jobList->has(DiscoverMissingDefaultsJob::class, null)) {
            $this->jobList->add(DiscoverMissingDefaultsJob::class, null);
        }

        return new JSONResponse(['success' => true, 'queued' => true]);
    }

    /**
     * Report whether the folder still has files awaiting defaults. Cheap: it
     * probes for a single missing file rather than counting the whole folder.
     */
    #[NoAdminRequired]
    public function status(int $groupfolderId): JSONResponse {
        $user = $this->requireUser();
        if ($user instanceof JSONResponse) {
            return $user;
        }
        if ($denied = $this->requireManagePermission($user->getUID(), $groupfolderId)) {
            return $denied;
        }

        try {
            $pending = $this->defaultsService->hasMissingDefaults($groupfolderId);
            return new JSONResponse([
                'state' => $pending ? 'running' : 'idle',
                'pending' => $pending,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: defaults status failed', ['exception' => $e, 'groupfolderId' => $groupfolderId]);
            return new JSONResponse(['error' => 'status check failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
