<?php

declare(strict_types=1);

namespace OCA\MetaVox\SetupCheck;

use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

class TeamFoldersCheck implements ISetupCheck {

    public function __construct(
        private readonly IAppManager $apps,
        private readonly IL10N $l,
    ) {
    }

    public function getCategory(): string {
        return 'system';
    }

    public function getName(): string {
        // TRANSLATORS Name of a check in the admin overview "Setup checks"; MetaVox is the app name, do not translate it
        return $this->l->t('MetaVox Team folders');
    }

    public function run(): SetupResult {
        if (!$this->apps->isInstalled('groupfolders')) {
            // TRANSLATORS Shown in the admin overview "Setup checks"; "Team Folders" is the name of a Nextcloud app
            return SetupResult::error($this->l->t('The Team Folders app is off. MetaVox adds metadata to Team folders and does nothing without it.'));
        }
        // TRANSLATORS Shown in the admin overview "Setup checks"; "Team Folders" is the name of a Nextcloud app, %s its version number, e.g. "Team Folders 20.1.0 is on."
        return SetupResult::success($this->l->t('Team Folders %s is on.', [$this->apps->getAppVersion('groupfolders')]));
    }
}
