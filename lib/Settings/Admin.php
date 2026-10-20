<?php

declare(strict_types=1);

namespace OCA\MetaVox\Settings;

use OCA\MetaVox\Service\AccountService;
use OCA\MetaVox\Service\FieldService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;

class Admin implements ISettings {
    public function __construct(
        private readonly FieldService $fieldService,
        private readonly AccountService $accounts,
        private readonly IInitialState $initialState,
    ) {
    }

    public function getForm(): TemplateResponse {
        \OCP\Util::addScript('metavox', 'admin');
        // A People field offers teams only when the Teams app is on.
        $this->initialState->provideInitialState('teams-available', $this->accounts->teamsAvailable());

        return new TemplateResponse('metavox', 'admin', [
            'fields' => $this->fieldService->getAllFields(),
        ]);
    }

    public function getSection(): string {
        return 'metavox';
    }

    public function getPriority(): int {
        return 50;
    }
}
