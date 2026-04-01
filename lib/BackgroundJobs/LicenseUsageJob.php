<?php

declare(strict_types=1);

namespace OCA\MetaVox\BackgroundJobs;

use OCA\MetaVox\Service\LicenseService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Background job that validates the license and reports usage to the license server.
 * Runs every 24 hours with instance-specific jitter to spread load.
 */
class LicenseUsageJob extends TimedJob {
	private const INTERVAL_HOURS = 24;

	public function __construct(
		ITimeFactory $time,
		private LicenseService $licenseService,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);

		// Base interval: 24 hours
		$intervalSeconds = self::INTERVAL_HOURS * 3600;

		// Add stable jitter (0-60 min) based on instance ID to spread requests
		$instanceId = $this->config->getSystemValueString('instanceid', 'default');
		$jitterMinutes = abs(crc32($instanceId . 'license')) % 60;
		$this->setInterval($intervalSeconds + ($jitterMinutes * 60));
	}

	protected function run(mixed $argument): void {
		$licenseKey = $this->licenseService->getLicenseKey();
		if (empty($licenseKey)) {
			return; // No license configured, nothing to sync
		}

		try {
			// Validate license
			$validation = $this->licenseService->validateLicense();
			if (!($validation['valid'] ?? false)) {
				$this->logger->warning('LicenseUsageJob: License validation failed', [
					'reason' => $validation['reason'] ?? 'unknown',
				]);
			}

			// Report usage regardless of validation result
			$this->licenseService->updateUsage();

			$this->config->setAppValue('metavox', 'license_last_sync', (string)time());
		} catch (\Exception $e) {
			$this->logger->error('LicenseUsageJob: Failed to sync license usage', [
				'error' => $e->getMessage(),
			]);
		}
	}
}
