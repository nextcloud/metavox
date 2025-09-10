<?php

declare(strict_types=1);

namespace OCA\MetaVox\Settings;

use OCA\MetaVox\Service\FieldService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class Admin implements ISettings {

    private FieldService $fieldService;

    public function __construct(FieldService $fieldService) {
        $this->fieldService = $fieldService;
    }

    public function getForm() {
        // JavaScript en CSS laden
        \OCP\Util::addScript('metavox', 'admin');
        
        $fields = $this->fieldService->getAllFields();
        
        return new TemplateResponse('metavox', 'admin', [
            'fields' => $fields,
        ]);
    }

    public function getSection() {
        return 'metavox';
    }

    public function getPriority() {
        return 50;
    }
}