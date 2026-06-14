<?php

declare(strict_types=1);

namespace OCA\MetaVox\BackgroundJobs;

use OCA\MetaVox\AppInfo\Application;
use OCA\MetaVox\Service\DefaultsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Source of truth for the default-value system. Runs every 5 minutes, finds
 * files in default-bearing groupfolders that are missing one or more defaults,
 * and queues ApplyDefaultsJob batches to fill them.
 *
 * The listener fast-path is an optimisation layered on top; this job guarantees
 * convergence regardless of whether the listener fired, skipped (bulk upload),
 * or failed.
 *
 * Scale strategy:
 *  - Keyset pagination (a per-folder cursor stored in app config) instead of
 *    OFFSET, so cost per page stays constant across millions of rows.
 *  - DISCOVERY_LIMIT caps files examined per run so a single cron tick is
 *    bounded even for a 1M-file folder; the cursor advances so the next tick
 *    continues where this one stopped.
 *  - Backpressure: if the ApplyDefaultsJob queue is already deep, skip this
 *    tick so discovery can never enqueue faster than workers drain.
 *  - When a folder's scan returns nothing new, its cursor resets to 0 so newly
 *    uploaded files are cheaply re-discovered from the top next time.
 */
class DiscoverMissingDefaultsJob extends TimedJob {

    /** 5 minute cadence — max latency from upload to default applied. */
    public const DISCOVERY_INTERVAL = 300;

    /** Max files examined per groupfolder per run. */
    public const DISCOVERY_LIMIT = 10000;

    /** Files per queued ApplyDefaultsJob — within the job argument size limit. */
    public const JOB_BATCH_SIZE = 250;

    /**
     * Skip a tick if this many ApplyDefaultsJobs are already queued. Prevents
     * the job_list table from growing faster than it is drained on a huge
     * backfill.
     */
    public const MAX_PENDING_JOBS = 200;

    private const CURSOR_CONFIG_PREFIX = 'defaults_cursor_gf_';

    public function __construct(
        ITimeFactory $time,
        private readonly DefaultsService $defaultsService,
        private readonly IJobList $jobList,
        private readonly IConfig $config,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(self::DISCOVERY_INTERVAL);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run(mixed $argument): void {
        // Backpressure: do not pile more work on an already-deep queue.
        if ($this->pendingApplyJobs() >= self::MAX_PENDING_JOBS) {
            $this->logger->debug('MetaVox: discovery skipped — ApplyDefaultsJob queue is deep');
            return;
        }

        $groupfolderIds = $this->defaultsService->getGroupfoldersWithDefaults();
        if (empty($groupfolderIds)) {
            return;
        }

        foreach ($groupfolderIds as $groupfolderId) {
            try {
                $this->discoverFolder($groupfolderId);
            } catch (\Exception $e) {
                $this->logger->error('MetaVox: discovery failed for groupfolder', [
                    'groupfolderId' => $groupfolderId,
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * Scan one groupfolder from its stored cursor, queueing batches until the
     * per-run limit is hit or no more missing files are found.
     */
    private function discoverFolder(int $groupfolderId): void {
        $defaults = $this->defaultsService->getFolderDefaults($groupfolderId);
        if (empty($defaults)) {
            return;
        }
        $fieldNames = array_keys($defaults);

        $cursor = $this->getCursor($groupfolderId);
        $examined = 0;
        $queued = 0;

        while ($examined < self::DISCOVERY_LIMIT) {
            $remaining = self::DISCOVERY_LIMIT - $examined;
            $pageSize = min(self::JOB_BATCH_SIZE, $remaining);

            $fileIds = $this->defaultsService->findFilesMissingDefaults(
                $groupfolderId,
                $fieldNames,
                $pageSize,
                $cursor
            );

            if (empty($fileIds)) {
                // Folder fully scanned for now — reset so new uploads are picked
                // up from the top on the next run.
                $this->resetCursor($groupfolderId);
                return;
            }

            $this->jobList->add(ApplyDefaultsJob::class, [
                'groupfolderId' => $groupfolderId,
                'fileIds' => $fileIds,
            ]);
            $queued++;

            // Advance the keyset cursor past the highest file id in this batch.
            $cursor = max($fileIds);
            $examined += \count($fileIds);

            // Persist the cursor every batch so a mid-run crash resumes cleanly.
            $this->setCursor($groupfolderId, $cursor);
        }

        $this->logger->debug('MetaVox: discovery paused at run limit', [
            'groupfolderId' => $groupfolderId,
            'queuedBatches' => $queued,
            'cursor' => $cursor,
        ]);
    }

    /**
     * Number of ApplyDefaultsJob entries currently queued.
     *
     * NC's IJobList::countByClass() takes NO argument and returns a list of
     * [class, count] pairs for ALL job classes — not a count for one class.
     * We must scan it for our class. (Passing a class arg / treating the result
     * as an int silently makes the backpressure check always true, which
     * disables discovery entirely.)
     */
    private function pendingApplyJobs(): int {
        foreach ($this->jobList->countByClass() as $entry) {
            if (($entry['class'] ?? null) === ApplyDefaultsJob::class) {
                return (int)($entry['count'] ?? 0);
            }
        }
        return 0;
    }

    private function getCursor(int $groupfolderId): int {
        return (int)$this->config->getAppValue(
            Application::APP_ID,
            self::CURSOR_CONFIG_PREFIX . $groupfolderId,
            '0'
        );
    }

    private function setCursor(int $groupfolderId, int $cursor): void {
        $this->config->setAppValue(
            Application::APP_ID,
            self::CURSOR_CONFIG_PREFIX . $groupfolderId,
            (string)$cursor
        );
    }

    private function resetCursor(int $groupfolderId): void {
        $this->config->setAppValue(
            Application::APP_ID,
            self::CURSOR_CONFIG_PREFIX . $groupfolderId,
            '0'
        );
    }
}
