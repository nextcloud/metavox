<?php

declare(strict_types=1);

namespace OCA\MetaVox\BackgroundJobs;

use OCA\MetaVox\Service\DefaultsService;
use OCA\MetaVox\Service\SearchIndexService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Processing tier of the default-value system: writes the configured defaults
 * for one batch of files in a groupfolder.
 *
 * Queued by DiscoverMissingDefaultsJob (and the occ command) with an argument
 * of {groupfolderId, fileIds[]} where fileIds holds at most
 * DiscoverMissingDefaultsJob::JOB_BATCH_SIZE ids — well within the job
 * argument size limit.
 *
 * Idempotent by design: bulkInsertDefaults uses DO NOTHING, so re-running a
 * batch (e.g. after a crash, or overlapping with the listener fast-path) never
 * duplicates or overwrites values. On failure we log and return without
 * rethrowing — discovery will find the same files again next run and retry,
 * avoiding a retry storm against the job queue.
 *
 * Deliberately silent on the live-collaboration layer: no push events, no
 * per-file cache writes. The search index IS updated, but in bulk via
 * SearchIndexService::bulkUpdateFileIndex.
 */
class ApplyDefaultsJob extends QueuedJob {

    public function __construct(
        ITimeFactory $time,
        private readonly DefaultsService $defaultsService,
        private readonly SearchIndexService $searchIndexService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
    }

    /**
     * @param array{groupfolderId?: int|null, fileIds?: int[]|null} $argument
     */
    protected function run(mixed $argument): void {
        $groupfolderId = isset($argument['groupfolderId']) ? (int)$argument['groupfolderId'] : 0;
        $fileIds = $argument['fileIds'] ?? [];

        if ($groupfolderId <= 0 || !is_array($fileIds) || empty($fileIds)) {
            $this->logger->warning('MetaVox: ApplyDefaultsJob called with invalid argument', [
                'groupfolderId' => $groupfolderId,
                'fileCount' => is_array($fileIds) ? \count($fileIds) : 0,
            ]);
            return;
        }

        $fileIds = array_values(array_map('intval', $fileIds));

        try {
            $defaults = $this->defaultsService->getFolderDefaults($groupfolderId);
            if (empty($defaults)) {
                // Defaults may have been cleared between discovery and processing.
                return;
            }

            $written = $this->defaultsService->bulkInsertDefaults($groupfolderId, $fileIds, $defaults);

            // Re-index in bulk so the freshly defaulted files become searchable.
            // Failure here is non-fatal: search lags slightly but the metadata
            // is already correct, and the per-file edit path will reconcile it.
            $this->searchIndexService->bulkUpdateFileIndex($fileIds);

            $this->logger->debug('MetaVox: ApplyDefaultsJob wrote defaults', [
                'groupfolderId' => $groupfolderId,
                'fileCount' => \count($fileIds),
                'rowsWritten' => $written,
            ]);
        } catch (\Exception $e) {
            // Do not rethrow: discovery is the source of truth and will retry.
            $this->logger->error('MetaVox: ApplyDefaultsJob failed', [
                'groupfolderId' => $groupfolderId,
                'fileCount' => \count($fileIds),
                'exception' => $e,
            ]);
        }
    }
}
