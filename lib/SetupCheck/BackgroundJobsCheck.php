<?php

declare(strict_types=1);

namespace OCA\MetaVox\SetupCheck;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/** The search index and the default values are filled by background jobs. */
class BackgroundJobsCheck implements ISetupCheck {

    public const LATE = 3600;

    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly ITimeFactory $time,
        private readonly IL10N $l,
    ) {
    }

    public function getCategory(): string {
        return 'system';
    }

    public function getName(): string {
        // TRANSLATORS Name of a check in the admin overview "Setup checks"; MetaVox is the app name, do not translate it
        return $this->l->t('MetaVox background jobs');
    }

    public function run(): SetupResult {
        $mode = $this->appConfig->getValueString('core', 'backgroundjobs_mode', 'ajax');
        if ($mode !== 'cron') {
            // TRANSLATORS Shown in the admin overview "Setup checks"; %s is the current background job mode, "AJAX" or "Webcron"; "system cron" is the scheduler of the server
            return SetupResult::warning($this->l->t('Background jobs run via %s. Use the system cron, so the search index and default values keep up.',
                [$mode === 'webcron' ? 'Webcron' : 'AJAX']));
        }
        if ($this->time->getTime() - $this->appConfig->getValueInt('core', 'lastcron', 0) > self::LATE) {
            // TRANSLATORS Shown in the admin overview "Setup checks" when the background jobs have not run for over an hour
            return SetupResult::warning($this->l->t('Background jobs last ran more than an hour ago. The search index and default values wait for them.'));
        }
        // TRANSLATORS Shown in the admin overview "Setup checks"; "system cron" is the scheduler of the server
        return SetupResult::success($this->l->t('Background jobs run via the system cron.'));
    }
}
