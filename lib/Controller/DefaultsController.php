<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Access\Access;
use OCA\MetaVox\Access\RequireAccess;
use OCA\MetaVox\BackgroundJobs\DiscoverMissingDefaultsJob;
use OCA\MetaVox\Exception\RequestException;
use OCA\MetaVox\L10n\Translatable;
use OCA\MetaVox\Service\DefaultsDiscovery;
use OCA\MetaVox\Service\DefaultsService;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\ApiValidationException;
use OCA\MetaVox\Service\FileReferenceService;
use OCA\MetaVox\Validation\FieldValue;
use OCA\MetaVox\Validation\InvalidValue;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Web UI endpoints for per-folder default values:
 *  - setDefault: configure a field's default for a folder
 *  - trigger:    start a sweep through the folder and force a discovery run, so an
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
        IUserSession $userSession,
        private readonly DefaultsService $defaultsService,
        private readonly FileReferenceService $fileReferenceService,
        private readonly IJobList $jobList,
        private readonly DefaultsDiscovery $discovery,
        private readonly ITimeFactory $time,
        private readonly FieldValue $fieldValues,
    ) {
        parent::__construct($appName, $request, $userSession, $fieldService);
    }

    /**
     * Get the configured defaults for a folder, keyed by field id, so the
     * management UI can prefill its inputs.
     */
    #[NoAdminRequired]
    #[RequireAccess(Access::MetaVoxManager, folder: 'groupfolderId')]
    public function getDefaults(int $groupfolderId): JSONResponse {
        $user = $this->requireUser();

        $defaults = $this->defaultsService->getFolderDefaultsByFieldId($groupfolderId);

        // File-link defaults hold ids only; the names come as the manager sees the files.
        $types = array_column($this->fieldService->getAllFields(), 'field_type', 'id');
        $rows = [];
        foreach ($defaults as $fieldId => $value) {
            $rows[$fieldId] = ['field_type' => $types[$fieldId] ?? '', 'value' => $value];
        }
        $resolved = [];
        foreach ($this->fileReferenceService->withNames($rows, $user->getUID()) as $fieldId => $row) {
            if (isset($row['resolved'])) {
                $resolved[$fieldId] = $row['resolved'];
            }
        }

        return new JSONResponse(['defaults' => $defaults, 'resolved' => (object)$resolved]);
    }

    /**
     * Set or clear the default value for a field in a groupfolder.
     * Body: { fieldId: int, value: string|null }
     */
    #[NoAdminRequired]
    #[RequireAccess(Access::MetaVoxManager, folder: 'groupfolderId')]
    public function setDefault(int $groupfolderId): JSONResponse {
        $user = $this->requireUser();

        $fieldId = $this->intParam('fieldId', required: true, min: 1);
        $field = $this->fieldService->getFieldById($fieldId);
        if ($field === null) {
            // TRANSLATORS Error when a default value is set for a metadata field that no longer exists
            throw RequestException::notFound(Translatable::t('Field not found'));
        }

        // A default takes what the field takes; no value is no default.
        try {
            $value = $this->fieldValues->stored($field, $this->request->getParam('value'));
            if ($value !== '' && in_array($field['field_type'], FileReferenceService::FILELINK_TYPES, true)) {
                $value = $this->defaultFileLinks($value, $groupfolderId, $fieldId);
            }
        } catch (InvalidValue $e) {
            throw new ApiValidationException(['value' => $e->getMessage()]);
        }
        $value = $value === '' ? null : $value;

        if (!$this->defaultsService->setFieldDefault($groupfolderId, $fieldId, $value)) {
            // TRANSLATORS Error when a default value is set for a field that this Team folder does not use for its files
            throw RequestException::invalid(Translatable::t('This Team folder does not use this field for its files'));
        }

        return new JSONResponse(['success' => true]);
    }

    /**
     * A file-link default in its stored form, as a value is: a link outside
     * this team folder, or to a file the user may not open, is left out; one
     * already in the default stays. One that is all left out, or that points at
     * nothing, would be no default at all, so it is refused.
     *
     * @throws InvalidValue
     */
    private function defaultFileLinks(string $value, int $groupfolderId, int $fieldId): string {
        $current = $this->defaultsService->getFolderDefaultsByFieldId($groupfolderId)[$fieldId] ?? '';
        $stored = $this->fileReferenceService->normalize($value, $groupfolderId, null, $current);
        $links = FileReferenceService::parseValue($stored);
        if ($links === [] || in_array(null, array_column($links, 'fileId'), true)) {
            // TRANSLATORS Error when a default value of a file link field points at something that is not a file in this Team folder
            throw new InvalidValue(Translatable::t('Link to a file in this Team folder'));
        }
        return $stored;
    }

    /**
     * Reset the discovery cursor for this folder and queue a discovery run, so
     * newly configured defaults are applied without waiting for the cron tick.
     */
    #[NoAdminRequired]
    #[RequireAccess(Access::MetaVoxManager, folder: 'groupfolderId')]
    public function trigger(int $groupfolderId): JSONResponse {
        $user = $this->requireUser();

        // Look through all files of the folder again: the changed defaults may
        // now apply to files that were done.
        $this->discovery->startSweep($groupfolderId, $this->time->getTime());

        // Ensure a discovery run is queued. The TimedJob is registered, but
        // adding it to the job list nudges it to run on the next cron tick
        // rather than waiting out its full interval.
        if (!$this->jobList->has(DiscoverMissingDefaultsJob::class, null)) {
            $this->jobList->add(DiscoverMissingDefaultsJob::class, null);
        }

        return new JSONResponse(['success' => true, 'queued' => true]);
    }

    /** Whether the folder is still being filled in, from where discovery stands: no scan. */
    #[NoAdminRequired]
    #[RequireAccess(Access::MetaVoxManager, folder: 'groupfolderId')]
    public function status(int $groupfolderId): JSONResponse {
        $user = $this->requireUser();

        $pending = $this->discovery->isBusy($groupfolderId, $this->time->getTime());
        return new JSONResponse([
            'state' => $pending ? 'running' : 'idle',
            'pending' => $pending,
        ]);
    }
}
