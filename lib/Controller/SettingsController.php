<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\AppInfo\Application;
use OCA\MetaVox\Service\AiAutofillService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class SettingsController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private AiAutofillService $aiService,
        private IAppConfig $appConfig,
        private IUserSession $userSession,
        private IGroupManager $groupManager
    ) {
        parent::__construct($appName, $request);
    }

    private function isAdmin(): bool {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }
        return $this->groupManager->isAdmin($user->getUID());
    }

    /**
     * Get app settings
     */
    #[NoCSRFRequired]
    public function get(): DataResponse {
        if (!$this->isAdmin()) {
            return new DataResponse(['success' => false, 'message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
        }

        return new DataResponse([
            'success' => true,
            'settings' => [
                'ai_enabled' => $this->aiService->isEnabledByAdmin(),
                'organization_name' => $this->appConfig->getValueString(Application::APP_ID, 'organization_name', ''),
                'contact_email' => $this->appConfig->getValueString(Application::APP_ID, 'contact_email', ''),
            ],
        ]);
    }

    /**
     * Save app settings
     */
    public function save(): DataResponse {
        if (!$this->isAdmin()) {
            return new DataResponse(['success' => false, 'message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
        }

        $aiEnabled = $this->request->getParam('ai_enabled');
        if ($aiEnabled !== null) {
            $this->aiService->setEnabled($aiEnabled === 'true' || $aiEnabled === true || $aiEnabled === '1' || $aiEnabled === 1);
        }

        $organizationName = $this->request->getParam('organization_name');
        if ($organizationName !== null) {
            $this->appConfig->setValueString(Application::APP_ID, 'organization_name', substr((string)$organizationName, 0, 255));
        }

        $contactEmail = $this->request->getParam('contact_email');
        if ($contactEmail !== null) {
            $this->appConfig->setValueString(Application::APP_ID, 'contact_email', substr((string)$contactEmail, 0, 255));
        }

        return new DataResponse([
            'success' => true,
            'message' => 'Settings saved successfully',
        ]);
    }
}
