<?php
declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Access\Access;
use OCA\MetaVox\Access\RequireAccess;
use OCA\MetaVox\Service\TelemetryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

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

    use ReadsParams;
    public function __construct(
        string $appName,
        IRequest $request,
        private TelemetryService $telemetryService,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Get telemetry status
     *
     * Admin only - no @NoAdminRequired annotation.
     *
     * @return DataResponse
     */
    #[NoCSRFRequired]
    #[RequireAccess(Access::NcAdmin)]
    public function getStatus(): DataResponse {
        return new DataResponse([
            'success' => true,
            ...$this->telemetryService->getStatus(),
        ]);
    }

    /**
     * Get statistics for admin panel display
     *
     * Admin only - no @NoAdminRequired annotation.
     *
     * @return DataResponse
     */
    #[NoCSRFRequired]
    #[RequireAccess(Access::NcAdmin)]
    public function getStats(): DataResponse {
        return new DataResponse([
            'success' => true,
            'stats' => $this->telemetryService->getStats(),
        ]);
    }

    /**
     * Send telemetry to license server
     *
     * Admin only - no @NoAdminRequired annotation.
     *
     * @return DataResponse
     */
    #[RequireAccess(Access::NcAdmin)]
    public function sendTelemetry(): DataResponse {
        $result = $this->telemetryService->sendReportWithDetails();

        return new DataResponse([
            'success' => $result['success'],
            'reason' => $result['reason'] ?? null,
            'message' => $result['message'] ?? null,
        ]);
    }

    /**
     * Save telemetry settings
     *
     * Admin only - no @NoAdminRequired annotation.
     *
     * @return DataResponse
     */
    #[RequireAccess(Access::NcAdmin)]
    public function saveSettings(): DataResponse {
        if ($this->request->getParam('enabled') !== null) {
            $this->telemetryService->setEnabled($this->boolParam('enabled', false));
        }
        return new DataResponse(['success' => true]);
    }
}
