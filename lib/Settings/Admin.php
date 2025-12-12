<?php

declare(strict_types=1);

namespace OCA\MetaVox\Settings;

use OCA\MetaVox\Service\FieldService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class Admin implements ISettings {
    public function __construct(
        private readonly FieldService $fieldService
    ) {
    }

    public function getForm(): TemplateResponse {
        \OCP\Util::addScript('metavox', 'admin');

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