<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Access\Access;
use OCA\MetaVox\Access\RequireAccess;
use OCA\MetaVox\Service\LicenseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class LicenseController extends Controller {

	use ReadsParams;

	public function __construct(
		string $appName,
		IRequest $request,
		private LicenseService $licenseService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoCSRFRequired]
	#[RequireAccess(Access::NcAdmin)]
	public function getStats(): DataResponse {
		return new DataResponse([
			'success' => true,
			'stats' => $this->licenseService->getStats(),
		]);
	}

	#[RequireAccess(Access::NcAdmin)]
	public function saveSettings(): DataResponse {
		$licenseKey = $this->textParam('licenseKey');
		if ($licenseKey !== null) {
			$this->licenseService->setLicenseKey($licenseKey);
		}

		return new DataResponse(['success' => true, 'message' => 'License settings saved']);
	}

	#[RequireAccess(Access::NcAdmin)]
	public function validate(): DataResponse {
		return new DataResponse(['success' => true, 'validation' => $this->licenseService->validateLicense()]);
	}

	#[RequireAccess(Access::NcAdmin)]
	public function updateUsage(): DataResponse {
		return new DataResponse(['success' => true, 'result' => $this->licenseService->updateUsage()]);
	}
}
