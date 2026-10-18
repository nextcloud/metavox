<?php

declare(strict_types=1);

namespace OCA\MetaVox\BackgroundJobs;

use OCA\MetaVox\Service\SearchWordsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Builds the search index (words and whole values) from the stored metadata,
 * walking the file ids.
 * Queued by SearchWordsService::rebuildAll. Each run works for a short while
 * and then waits for the next cron run, so a large instance is never busy for
 * long. The argument holds the progress: the last file id done, how many files
 * are done, and of how many.
 */
class BuildSearchWords extends QueuedJob {

    public const PORTION = 5000;
    /** Seconds of work per run: from the system cron, and inside a page request (AJAX cron). */
    public const BUDGET = 20;
    public const BUDGET_IN_REQUEST = 5;
    /** The next run after this, so in the next cron run. */
    public const PAUSE = 240;

    public function __construct(
        ITimeFactory $time,
        private readonly SearchWordsService $searchWords,
        private readonly IJobList $jobList,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
    }

    /**
     * @param array{after?: int, done?: int, total?: int, generation?: string} $argument
     */
    protected function run(mixed $argument): void {
        $after = (int)($argument['after'] ?? 0);
        $done = (int)($argument['done'] ?? 0);
        $total = (int)($argument['total'] ?? 0);
        $generation = isset($argument['generation']) ? (string)$argument['generation'] : null;
        // A fill started since (a restore, say) does it all again: this one stops.
        if (!$this->searchWords->isCurrentFill($generation)) {
            return;
        }
        $now = $this->time->getTime();
        $until = $now + (PHP_SAPI === 'cli' ? self::BUDGET : self::BUDGET_IN_REQUEST);

        // Cron took this job off the list before running it. The next one goes on
        // first, so a run that dies halfway goes on from its last finished portion.
        $next = $this->scheduleNext(null, $after, $done, $total, $now, $generation);

        try {
            do {
                $fileIds = $this->searchWords->filesAfter($after, self::PORTION);
                if ($fileIds === []) {
                    // Done: words of files after the last one have nothing left.
                    $this->searchWords->reindexRange($after, null, []);
                    $this->jobList->remove(self::class, $next);
                    $this->searchWords->fillDone($generation);
                    return;
                }
                $upTo = max($fileIds);
                $this->searchWords->reindexRange($after, $upTo, $fileIds);
                $after = $upTo;
                $done += \count($fileIds);
                $now = $this->time->getTime();
                $next = $this->scheduleNext($next, $after, $done, $total, $now, $generation);
            } while ($now < $until);
        } catch (\Throwable $e) {
            $this->logger->error('MetaVox: building the search words failed', ['exception' => $e, 'after' => $after]);
        }
    }

    /** Puts the next run on the list with the progress so far, in place of the one before. */
    private function scheduleNext(?array $previous, int $after, int $done, int $total, int $now, ?string $generation): array {
        $argument = ['after' => $after, 'done' => $done, 'total' => max($total, $done)];
        if ($generation !== null) {
            $argument['generation'] = $generation;
        }
        $this->jobList->scheduleAfter(self::class, $now + self::PAUSE, $argument);
        if ($previous !== null) {
            $this->jobList->remove(self::class, $previous);
        }
        return $argument;
    }
}
