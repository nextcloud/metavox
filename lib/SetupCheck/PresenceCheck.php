<?php

declare(strict_types=1);

namespace OCA\MetaVox\SetupCheck;

use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/** Presence is kept alive by the heartbeat of the User status app. */
class PresenceCheck implements ISetupCheck {

    public function __construct(
        private readonly IAppManager $apps,
        private readonly IL10N $l,
    ) {
    }

    public function getCategory(): string {
        return 'system';
    }

    public function getName(): string {
        // TRANSLATORS Name of a check in the admin overview "Setup checks" about showing who has a Team folder open; MetaVox is the app name, do not translate it
        return $this->l->t('MetaVox presence');
    }

    public function run(): SetupResult {
        if (!$this->apps->isInstalled('user_status')) {
            // TRANSLATORS Shown in the admin overview "Setup checks"; "User status" is the name of a Nextcloud app
            return SetupResult::info($this->l->t('The User status app is off. Who has a Team folder open then shows for up to 12 hours.'));
        }
        // TRANSLATORS Shown in the admin overview "Setup checks"; "User status" is the name of a Nextcloud app
        return SetupResult::success($this->l->t('Who has a Team folder open follows the open browsers, through the User status app.'));
    }
}
