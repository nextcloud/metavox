<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCA\MetaVox\AppInfo\Application;
use OCA\MetaVox\BackgroundJobs\ApplyDefaultsJob;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;

/**
 * Where the search for files without their defaults stands, per team folder:
 * the highest file id looked at (newer files are above it), and the sweep
 * through the older files that runs daily and after a change of defaults.
 * Kept in lazy app config, so it isn't loaded with every request.
 */
class DefaultsDiscovery {

    private const KEY = 'defaults_discovery_gf_';
    private const OLD_KEY = 'defaults_cursor_gf_';

    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly IJobList $jobList,
    ) {
    }

    /** @return array{high: int, sweep: ?int, swept: int} */
    public function state(int $groupfolderId, int $now): array {
        $state = json_decode($this->appConfig->getValueString(Application::APP_ID, self::KEY . $groupfolderId, '', true), true);
        if (!\is_array($state)) {
            // A first look goes through every file anyway: that is the sweep.
            return ['high' => 0, 'sweep' => null, 'swept' => $now];
        }
        return ['high' => (int)($state['high'] ?? 0), 'sweep' => isset($state['sweep']) ? (int)$state['sweep'] : null, 'swept' => (int)($state['swept'] ?? 0)];
    }

    /** @param array{high: int, sweep: ?int, swept: int} $state */
    public function save(int $groupfolderId, array $state): void {
        $this->appConfig->setValueString(Application::APP_ID, self::KEY . $groupfolderId, json_encode($state), true);
    }

    /** Look through all files of the team folder again, e.g. after its defaults changed. */
    public function startSweep(int $groupfolderId, int $now): void {
        $state = $this->state($groupfolderId, $now);
        // Not looked at yet: the first look goes through every file anyway.
        if ($state['high'] > 0) {
            $state['sweep'] = 0;
            $this->save($groupfolderId, $state);
        }
    }

    /** Still filling in: a sweep under way, or defaults waiting in the job queue. */
    public function isBusy(int $groupfolderId, int $now): bool {
        if ($this->state($groupfolderId, $now)['sweep'] !== null) {
            return true;
        }
        foreach ($this->jobList->getJobsIterator(ApplyDefaultsJob::class, null, 0) as $job) {
            if ((int)($job->getArgument()['groupfolderId'] ?? 0) === $groupfolderId) {
                return true;
            }
        }
        return false;
    }

    /** The old cursors were loaded with every request; they go once. */
    public function forgetOldCursors(): void {
        foreach ($this->appConfig->getKeys(Application::APP_ID) as $key) {
            if (str_starts_with($key, self::OLD_KEY)) {
                $this->appConfig->deleteKey(Application::APP_ID, $key);
            }
        }
    }
}
