<?php

declare(strict_types=1);

namespace OCA\MetaVox\SetupCheck;

use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/** Changes reach the grids of others through Client Push. */
class LiveUpdatesCheck implements ISetupCheck {

    public function __construct(
        private readonly IAppManager $apps,
        private readonly IAppConfig $appConfig,
        private readonly IL10N $l,
    ) {
    }

    public function getCategory(): string {
        return 'system';
    }

    public function getName(): string {
        // TRANSLATORS Name of a check in the admin overview "Setup checks"; MetaVox is the app name, do not translate it
        return $this->l->t('MetaVox live updates');
    }

    public function run(): SetupResult {
        if (!$this->apps->isInstalled('notify_push') || $this->appConfig->getValueString('notify_push', 'base_endpoint', '') === '') {
            // TRANSLATORS Shown in the admin overview "Setup checks"; "Client Push" is the name of a Nextcloud app and notify_push its technical id, keep both as is
            return SetupResult::info($this->l->t('The Client Push app (notify_push) is not set up. Changes by others then appear after a refresh.'));
        }
        // TRANSLATORS Shown in the admin overview "Setup checks"; "Client Push" is the name of a Nextcloud app
        return SetupResult::success($this->l->t('Changes by others appear right away, through Client Push.'));
    }
}
