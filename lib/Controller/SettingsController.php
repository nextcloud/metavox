<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Access\Access;
use OCA\MetaVox\Access\RequireAccess;
use OCA\MetaVox\Service\AiAutofillService;
use OCA\MetaVox\Service\ApiValidationException;
use OCA\MetaVox\Service\SearchSettings;
use OCA\MetaVox\Validation\InvalidValue;
use OCA\MetaVox\Validation\LinkSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class SettingsController extends Controller {

    use ReadsParams;

    public function __construct(
        string $appName,
        IRequest $request,
        private AiAutofillService $aiService,
        private SearchSettings $search,
        private LinkSettings $links,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Get app settings
     */
    #[NoCSRFRequired]
    #[RequireAccess(Access::NcAdmin)]
    public function get(): DataResponse {
        return new DataResponse([
            'success' => true,
            'settings' => [
                'ai_enabled' => $this->aiService->isEnabledByAdmin(),
                LinkSettings::WEB_ONLY => $this->links->webOnly(),
                LinkSettings::BLOCKED => $this->links->blockedDomains(),
                LinkSettings::ALLOWED => $this->links->allowedDomains(),
            ] + $this->search->all(),
        ]);
    }

    /**
     * Save app settings: only those sent. All are read first, so one that is
     * refused saves none.
     */
    #[RequireAccess(Access::NcAdmin)]
    public function save(): DataResponse {
        $switches = [];
        foreach (['ai_enabled', LinkSettings::WEB_ONLY, SearchSettings::FILTER, SearchSettings::FOLDERS] as $key) {
            if ($this->request->getParam($key) !== null) {
                $switches[$key] = $this->boolParam($key, false);
            }
        }
        $domains = [];
        $refused = [];
        foreach ([LinkSettings::BLOCKED, LinkSettings::ALLOWED] as $key) {
            if ($this->request->getParam($key) === null) {
                continue;
            }
            try {
                $domains[$key] = LinkSettings::domainList($this->request->getParam($key));
            } catch (InvalidValue $e) {
                $refused[$key] = $e->getMessage();
            }
        }
        if ($refused !== []) {
            throw new ApiValidationException($refused);
        }

        if (isset($switches['ai_enabled'])) {
            $this->aiService->setEnabled($switches['ai_enabled']);
        }
        if (isset($switches[LinkSettings::WEB_ONLY])) {
            $this->links->setWebOnly($switches[LinkSettings::WEB_ONLY]);
        }
        foreach ($domains as $key => $list) {
            $this->links->setDomains($key, $list);
        }
        foreach ([SearchSettings::FILTER, SearchSettings::FOLDERS] as $key) {
            if (isset($switches[$key])) {
                $this->search->set($key, $switches[$key]);
            }
        }

        return new DataResponse(['success' => true]);
    }
}
