<?php
declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Service\LicenseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class LicenseController extends Controller {
    private LicenseService $licenseService;

    public function __construct(
        string $appName,
        IRequest $request,
        LicenseService $licenseService
    ) {
        parent::__construct($appName, $request);
        $this->licenseService = $licenseService;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getInfo(): JSONResponse {
        $info = $this->licenseService->getLicenseInfo();
        return new JSONResponse($info);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function checkLimit(): JSONResponse {
        $result = $this->licenseService->canCreateTeamFolder();
        return new JSONResponse($result);
    }

    /**
     * Get license configuration (admin only)
     * @NoCSRFRequired
     */
    public function getConfig(): JSONResponse {
        $config = $this->licenseService->getLicenseConfig();
        return new JSONResponse($config);
    }

    /**
     * Save license configuration (admin only)
     */
    public function saveConfig(): JSONResponse {
        $licenseKey = $this->request->getParam('licenseKey', '');
        $licenseServerUrl = $this->request->getParam('licenseServerUrl', '');

        if (empty($licenseKey) || empty($licenseServerUrl)) {
            return new JSONResponse([
                'success' => false,
                'error' => 'License key and server URL are required'
            ], 400);
        }

        $success = $this->licenseService->saveLicenseConfig($licenseKey, $licenseServerUrl);

        if ($success) {
            return new JSONResponse([
                'success' => true,
                'message' => 'License configuration saved successfully'
            ]);
        }

        return new JSONResponse([
            'success' => false,
            'error' => 'Failed to save license configuration'
        ], 500);
    }
}
