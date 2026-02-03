<?php
declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Service\TelemetryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * API Controller for MetaVox Telemetry Management
 *
 * Provides endpoints for:
 * - Getting telemetry status
 * - Sending telemetry data to license server
 * - Managing telemetry settings
 *
 * All endpoints are admin-only (no @NoAdminRequired)
 */
class TelemetryController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private TelemetryService $telemetryService,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Check if current user is admin
     */
    private function isAdmin(): bool {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }
        return $this->groupManager->isAdmin($user->getUID());
    }

    /**
     * Get telemetry status
     *
     * Admin only - no @NoAdminRequired annotation.
     *
     * @NoCSRFRequired
     *
     * @return DataResponse
     */
    public function getStatus(): DataResponse {
        if (!$this->isAdmin()) {
            return new DataResponse([
                'success' => false,
                'message' => 'Admin privileges required'
            ], Http::STATUS_FORBIDDEN);
        }

        try {
            $status = $this->telemetryService->getStatus();

            return new DataResponse([
                'success' => true,
                ...$status
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get telemetry status', [
                'error' => $e->getMessage()
            ]);
            return new DataResponse([
                'success' => false,
                'message' => 'Failed to retrieve telemetry status'
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get statistics for admin panel display
     *
     * Admin only - no @NoAdminRequired annotation.
     *
     * @NoCSRFRequired
     *
     * @return DataResponse
     */
    public function getStats(): DataResponse {
        if (!$this->isAdmin()) {
            return new DataResponse([
                'success' => false,
                'message' => 'Admin privileges required'
            ], Http::STATUS_FORBIDDEN);
        }

        try {
            $stats = $this->telemetryService->getStats();

            return new DataResponse([
                'success' => true,
                'stats' => $stats
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get statistics', [
                'error' => $e->getMessage()
            ]);
            return new DataResponse([
                'success' => false,
                'message' => 'Failed to retrieve statistics'
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Send telemetry to license server
     *
     * Admin only - no @NoAdminRequired annotation.
     *
     * @return DataResponse
     */
    public function sendTelemetry(): DataResponse {
        if (!$this->isAdmin()) {
            return new DataResponse([
                'success' => false,
                'message' => 'Admin privileges required'
            ], Http::STATUS_FORBIDDEN);
        }

        try {
            $success = $this->telemetryService->sendReport();

            if ($success) {
                return new DataResponse([
                    'success' => true,
                    'message' => 'Telemetry sent successfully'
                ]);
            } else {
                return new DataResponse([
                    'success' => false,
                    'message' => 'Telemetry is disabled or failed to send'
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to send telemetry', [
                'error' => $e->getMessage()
            ]);
            return new DataResponse([
                'success' => false,
                'message' => 'Failed to send telemetry'
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Save telemetry settings
     *
     * Admin only - no @NoAdminRequired annotation.
     *
     * @return DataResponse
     */
    public function saveSettings(): DataResponse {
        if (!$this->isAdmin()) {
            return new DataResponse([
                'success' => false,
                'message' => 'Admin privileges required'
            ], Http::STATUS_FORBIDDEN);
        }

        try {
            $enabled = $this->request->getParam('enabled');

            if ($enabled !== null) {
                $this->telemetryService->setEnabled($enabled === 'true' || $enabled === true || $enabled === '1' || $enabled === 1);
            }

            return new DataResponse([
                'success' => true,
                'message' => 'Telemetry settings saved successfully'
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to save telemetry settings', [
                'error' => $e->getMessage()
            ]);
            return new DataResponse([
                'success' => false,
                'message' => 'Failed to save telemetry settings'
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
