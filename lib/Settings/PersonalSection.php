<?php

declare(strict_types=1);

namespace OCA\MetaVox\Settings;

use OCA\MetaVox\Service\TeamFolderAccessService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Settings\IIconSection;

class PersonalSection implements IIconSection {

    public function __construct(
        private readonly IL10N $l,
        private readonly IURLGenerator $urlGenerator,
        private readonly TeamFolderAccessService $teamFolders,
        private readonly IUserSession $userSession,
    ) {
    }

    /** The same rule as the page: admins and managers of at least one team folder. */
    private function shouldShow(): bool {
        $user = $this->userSession->getUser();
        return $user !== null && $this->teamFolders->managesAnyFolder($user->getUID());
    }

    public function getID(): string {
        // Don't show section if user has no permission
        if (!$this->shouldShow()) {
            return 'metavox-personal-hidden';
        }
        
        return 'metavox-personal';
    }

    public function getName(): string {
        // TRANSLATORS Name of the section in the personal settings menu; MetaVox is the app name, do not translate it
        return $this->l->t('MetaVox');
    }

    public function getPriority(): int {
        return 80;
    }

    public function getIcon(): string {
        // The dark glyph, as core's sections use: the settings list inverts it in a dark theme.
        return $this->urlGenerator->imagePath('metavox', 'app-dark.svg');
    }
}