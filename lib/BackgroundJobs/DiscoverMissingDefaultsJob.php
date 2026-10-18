<?php

declare(strict_types=1);

namespace OCA\MetaVox\BackgroundJobs;

use OCA\MetaVox\Service\DefaultsDiscovery;
use OCA\MetaVox\Service\DefaultsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Source of truth for the default-value system. Every 5 minutes it finds files
 * in default-bearing team folders that miss one or more defaults, and queues
 * ApplyDefaultsJob batches to fill them. The upload listener is a fast path on
 * top; this job makes sure everything gets there.
 *
 * Bounded work, also with a million files or on PostgreSQL:
 *  - Per team folder a high-water mark: new files get higher ids, so a tick
 *    only looks above it, and a team folder that is done costs next to nothing.
 *  - Files below the mark (moved in, or after a change of defaults) are found by
 *    a sweep: daily, or started by a change. It walks file id ranges, so each
 *    query reads at most RANGE rows of the file cache.
 *  - At most MAX_PENDING_JOBS in the queue, counted while queueing, and a time
 *    budget per tick; team folders take turns at going first.
 */
class DiscoverMissingDefaultsJob extends TimedJob {

    /** 5 minute cadence — max latency from upload to default applied. */
    public const DISCOVERY_INTERVAL = 300;

    /** Files per queued ApplyDefaultsJob — within the job argument size limit. */
    public const JOB_BATCH_SIZE = 250;

    /** At most this many ApplyDefaultsJobs queued, so discovery never runs ahead of the workers. */
    public const MAX_PENDING_JOBS = 200;

    /** File ids per query. */
    public const RANGE = 10000;

    /** Seconds between sweeps through all files of a team folder. */
    public const SWEEP_INTERVAL = 86400;

    /** Seconds of work per tick: from the system cron, and inside a page request (AJAX cron). */
    public const BUDGET = 10;
    public const BUDGET_IN_REQUEST = 2;

    private int $until = 0;

    public function __construct(
        ITimeFactory $time,
        private readonly DefaultsService $defaultsService,
        private readonly DefaultsDiscovery $discovery,
        private readonly IJobList $jobList,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(self::DISCOVERY_INTERVAL);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run(mixed $argument): void {
        $this->discovery->forgetOldCursors();

        $room = self::MAX_PENDING_JOBS - $this->pendingApplyJobs();
        if ($room <= 0) {
            $this->logger->debug('MetaVox: discovery skipped — ApplyDefaultsJob queue is full');
            return;
        }

        $groupfolderIds = $this->defaultsService->getGroupfoldersWithDefaults();
        if (empty($groupfolderIds)) {
            return;
        }

        $now = $this->time->getTime();
        $this->until = $now + (PHP_SAPI === 'cli' ? self::BUDGET : self::BUDGET_IN_REQUEST);
        $maxFileId = $this->defaultsService->maxFileId();
        foreach ($this->inTurn($groupfolderIds, $now) as $groupfolderId) {
            if ($room <= 0 || $this->time->getTime() >= $this->until) {
                break;
            }
            try {
                $room = $this->discoverFolder($groupfolderId, $maxFileId, $room, $now);
            } catch (\Exception $e) {
                $this->logger->error('MetaVox: discovery failed for groupfolder', [
                    'groupfolderId' => $groupfolderId,
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * The team folders, each tick starting one further, so none always waits
     * behind the others when the queue or the time runs out.
     *
     * @param int[] $groupfolderIds
     * @return int[]
     */
    private function inTurn(array $groupfolderIds, int $now): array {
        sort($groupfolderIds);
        $offset = intdiv($now, self::DISCOVERY_INTERVAL) % \count($groupfolderIds);
        return [...\array_slice($groupfolderIds, $offset), ...\array_slice($groupfolderIds, 0, $offset)];
    }

    /** @return int the room left in the queue */
    private function discoverFolder(int $groupfolderId, int $maxFileId, int $room, int $now): int {
        $fieldNames = array_keys($this->defaultsService->getFolderDefaults($groupfolderId));
        if ($fieldNames === []) {
            return $room;
        }

        $state = $this->discovery->state($groupfolderId, $now);
        if ($state['sweep'] === null && $now - $state['swept'] >= self::SWEEP_INTERVAL) {
            $state['sweep'] = 0;
        }

        // New files first, then the sweep through the older ones.
        $state['high'] = $this->scan($groupfolderId, $fieldNames, $state['high'], $maxFileId, $room);
        if ($state['sweep'] !== null && $room > 0) {
            $state['sweep'] = $this->scan($groupfolderId, $fieldNames, $state['sweep'], $state['high'], $room);
            if ($state['sweep'] >= $state['high']) {
                $state['sweep'] = null;
                $state['swept'] = $now;
            }
        }
        $this->discovery->save($groupfolderId, $state);
        return $room;
    }

    /**
     * Queue the files missing defaults with ids in (from, to], range by range,
     * while there is room and time.
     *
     * @param string[] $fieldNames
     * @return int how far it got
     */
    private function scan(int $groupfolderId, array $fieldNames, int $from, int $to, int &$room): int {
        $position = $from;
        while ($position < $to && $room > 0 && $this->time->getTime() < $this->until) {
            $upTo = min($position + self::RANGE, $to);
            $fileIds = $this->defaultsService->findFilesMissingDefaults($groupfolderId, $fieldNames, self::JOB_BATCH_SIZE, $position, $upTo);
            if ($fileIds !== []) {
                $this->jobList->add(ApplyDefaultsJob::class, ['groupfolderId' => $groupfolderId, 'fileIds' => $fileIds]);
                $room--;
            }
            // A full batch: more may follow in this range.
            $position = \count($fileIds) === self::JOB_BATCH_SIZE ? max($fileIds) : $upTo;
        }
        return $position;
    }

    /**
     * Number of ApplyDefaultsJob entries currently queued.
     *
     * NC's IJobList::countByClass() takes NO argument and returns a list of
     * [class, count] pairs for ALL job classes — not a count for one class.
     */
    private function pendingApplyJobs(): int {
        foreach ($this->jobList->countByClass() as $entry) {
            if (($entry['class'] ?? null) === ApplyDefaultsJob::class) {
                return (int)($entry['count'] ?? 0);
            }
        }
        return 0;
    }
}
