<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Service\LicenseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class LicenseController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private LicenseService $licenseService,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
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

	#[NoCSRFRequired]
	public function getStats(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['success' => false, 'message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
		}

		try {
			return new DataResponse([
				'success' => true,
				'stats' => $this->licenseService->getStats(),
			]);
		} catch (\Exception $e) {
			return new DataResponse([
				'success' => false,
				'message' => 'Failed to get license stats',
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	public function saveSettings(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['success' => false, 'message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
		}

		$licenseKey = $this->request->getParam('licenseKey');
		if ($licenseKey !== null) {
			$this->licenseService->setLicenseKey((string)$licenseKey);
		}

		return new DataResponse(['success' => true, 'message' => 'License settings saved']);
	}

	public function validate(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['success' => false, 'message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
		}

		try {
			$result = $this->licenseService->validateLicense();
			return new DataResponse(['success' => true, 'validation' => $result]);
		} catch (\Exception $e) {
			return new DataResponse([
				'success' => false,
				'message' => 'License validation failed',
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	public function updateUsage(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['success' => false, 'message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
		}

		try {
			$result = $this->licenseService->updateUsage();
			return new DataResponse(['success' => true, 'result' => $result]);
		} catch (\Exception $e) {
			return new DataResponse([
				'success' => false,
				'message' => 'Usage update failed',
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
