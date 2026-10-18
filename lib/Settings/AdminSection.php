<?php

declare(strict_types=1);

namespace OCA\MetaVox\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
    public function __construct(
        private readonly IL10N $l,
        private readonly IURLGenerator $urlGenerator
    ) {
    }

    public function getID(): string {
        return 'metavox';
    }

    public function getName(): string {
        // TRANSLATORS Name of the section in the administration settings menu; MetaVox is the app name, do not translate it
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