<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCA\MetaVox\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class LicenseService {
	private const FREE_TEAM_FOLDER_LIMIT = 5;
	private const FREE_ENTRIES_PER_FOLDER_LIMIT = 500;
	private const LICENSE_SERVER_URL = 'https://licenses.voxcloud.nl';

	public function __construct(
		private IClientService $httpClient,
		private IConfig $config,
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	// --- License key management ---

	public function getLicenseKey(): string {
		return $this->config->getAppValue(Application::APP_ID, 'license_key', '');
	}

	public function setLicenseKey(string $key): void {
		$this->config->setAppValue(Application::APP_ID, 'license_key', trim($key));
		// Clear cached validation when key changes
		$this->config->deleteAppValue(Application::APP_ID, 'license_valid');
		$this->config->deleteAppValue(Application::APP_ID, 'license_info');
		$this->config->deleteAppValue(Application::APP_ID, 'license_limits');
	}

	public function getLicenseServerUrl(): string {
		return $this->config->getAppValue(Application::APP_ID, 'license_server_url', self::LICENSE_SERVER_URL);
	}

	public function getInstanceUrlHash(): string {
		$instanceUrl = $this->config->getSystemValue('overwrite.cli.url', '');
		if (empty($instanceUrl)) {
			$instanceUrl = $this->config->getSystemValue('trusted_domains', ['localhost'])[0] ?? 'localhost';
		}
		return hash('sha256', strtolower(rtrim($instanceUrl, '/')));
	}

	// --- License validation ---

	public function validateLicense(): array {
		$licenseKey = $this->getLicenseKey();
		if (empty($licenseKey)) {
			return ['valid' => false, 'reason' => 'No license key configured', 'isFree' => true];
		}

		try {
			$client = $this->httpClient->newClient();
			$response = $client->post($this->getLicenseServerUrl() . '/api/licenses/validate', [
				'json' => [
					'licenseKey' => $licenseKey,
					'instanceUrlHash' => $this->getInstanceUrlHash(),
					'appType' => 'metavox',
				],
				'timeout' => 10,
				'headers' => [
					'User-Agent' => 'MetaVox/' . $this->getAppVersion(),
				],
			]);

			$data = json_decode($response->getBody(), true);

			if ($data['valid'] ?? false) {
				$this->config->setAppValue(Application::APP_ID, 'license_valid', 'true');
				$this->config->setAppValue(Application::APP_ID, 'license_info', json_encode($data));
				$this->config->setAppValue(Application::APP_ID, 'license_last_check', (string)time());
				return $data;
			}

			$this->config->setAppValue(Application::APP_ID, 'license_valid', 'false');
			return $data;
		} catch (\Exception $e) {
			$this->logger->warning('LicenseService: Failed to validate license', [
				'error' => $e->getMessage(),
			]);

			// Fallback to cached validation
			$cachedValid = $this->config->getAppValue(Application::APP_ID, 'license_valid', '');
			if ($cachedValid === 'true') {
				$cachedInfo = json_decode(
					$this->config->getAppValue(Application::APP_ID, 'license_info', '{}'),
					true
				);
				return array_merge($cachedInfo, ['valid' => true, 'cached' => true]);
			}

			return ['valid' => false, 'reason' => 'Could not connect to license server', 'cached' => false];
		}
	}

	// --- Usage reporting ---

	public function updateUsage(): array {
		$licenseKey = $this->getLicenseKey();
		if (empty($licenseKey)) {
			return ['success' => false, 'reason' => 'No license key configured'];
		}

		try {
			$stats = $this->getUsageStats();
			$client = $this->httpClient->newClient();
			$response = $client->post($this->getLicenseServerUrl() . '/api/licenses/usage', [
				'json' => [
					'licenseKey' => $licenseKey,
					'instanceUrlHash' => $this->getInstanceUrlHash(),
					'instanceName' => $this->config->getAppValue(Application::APP_ID, 'organization_name', ''),
					'appType' => 'metavox',
					'currentTeamFolders' => $stats['teamFoldersWithFields'],
					'totalMetadataEntries' => $stats['totalEntries'],
					'currentUsers' => $stats['totalUsers'],
				],
				'timeout' => 15,
				'headers' => [
					'User-Agent' => 'MetaVox/' . $this->getAppVersion(),
				],
			]);

			$data = json_decode($response->getBody(), true);

			if (isset($data['limits'])) {
				$this->config->setAppValue(Application::APP_ID, 'license_limits', json_encode($data['limits']));
			}

			return $data;
		} catch (\Exception $e) {
			$this->logger->warning('LicenseService: Failed to update usage', [
				'error' => $e->getMessage(),
			]);
			return ['success' => false, 'reason' => 'Could not connect to license server'];
		}
	}

	// --- Limit checking ---

	public function checkLimits(): array {
		$stats = $this->getUsageStats();
		$licenseKey = $this->getLicenseKey();

		if (empty($licenseKey)) {
			// Free tier: check against hardcoded limits
			$exceededFolders = $stats['teamFoldersWithFields'] > self::FREE_TEAM_FOLDER_LIMIT;

			$exceededEntries = false;
			foreach ($stats['entriesPerFolder'] as $folderId => $count) {
				if ($count > self::FREE_ENTRIES_PER_FOLDER_LIMIT) {
					$exceededEntries = true;
					break;
				}
			}

			return [
				'isFree' => true,
				'teamFolderLimit' => self::FREE_TEAM_FOLDER_LIMIT,
				'entriesPerFolderLimit' => self::FREE_ENTRIES_PER_FOLDER_LIMIT,
				'teamFoldersUsed' => $stats['teamFoldersWithFields'],
				'teamFoldersExceeded' => $exceededFolders,
				'entriesExceeded' => $exceededEntries,
				'exceeded' => $exceededFolders || $exceededEntries,
			];
		}

		// Licensed: use cached limits from server or validate
		$cachedLimits = json_decode(
			$this->config->getAppValue(Application::APP_ID, 'license_limits', '{}'),
			true
		);

		return [
			'isFree' => false,
			'teamFolderLimit' => $cachedLimits['maxTeamFolders'] ?? null,
			'entriesPerFolderLimit' => $cachedLimits['maxEntriesPerFolder'] ?? null,
			'teamFoldersUsed' => $stats['teamFoldersWithFields'],
			'teamFoldersExceeded' => false,
			'entriesExceeded' => false,
			'exceeded' => false,
		];
	}

	// --- Statistics for admin UI ---

	public function getStats(): array {
		$stats = $this->getUsageStats();
		$limits = $this->checkLimits();
		$licenseKey = $this->getLicenseKey();
		$hasLicense = !empty($licenseKey);

		$licenseValid = false;
		$licenseInfo = [];
		if ($hasLicense) {
			$cachedValid = $this->config->getAppValue(Application::APP_ID, 'license_valid', '');
			$licenseValid = $cachedValid === 'true';
			$licenseInfo = json_decode(
				$this->config->getAppValue(Application::APP_ID, 'license_info', '{}'),
				true
			);
		}

		// Mask license key for frontend display
		$maskedKey = '';
		if ($hasLicense) {
			$key = $this->getLicenseKey();
			if (strlen($key) > 8) {
				$maskedKey = substr($key, 0, 4) . '-••••-••••-' . substr($key, -4);
			} else {
				$maskedKey = '••••••••';
			}
		}

		return [
			'teamFoldersWithFields' => $stats['teamFoldersWithFields'],
			'totalEntries' => $stats['totalEntries'],
			'entriesPerFolder' => $stats['entriesPerFolder'],
			'totalUsers' => $stats['totalUsers'],
			'hasLicense' => $hasLicense,
			'licenseValid' => $licenseValid,
			'licenseInfo' => $licenseInfo,
			'licenseKeyMasked' => $maskedKey,
			'limits' => $limits,
			'freeTeamFolderLimit' => self::FREE_TEAM_FOLDER_LIMIT,
			'freeEntriesPerFolderLimit' => self::FREE_ENTRIES_PER_FOLDER_LIMIT,
		];
	}

	// --- Internal counting ---

	private function getUsageStats(): array {
		try {
			// Team folders with fields assigned
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count($qb->createFunction('DISTINCT groupfolder_id'), 'count'))
				->from('metavox_gf_assigns');
			$result = $qb->executeQuery();
			$teamFoldersWithFields = (int)$result->fetchOne();
			$result->closeCursor();

			// Total metadata entries (folder-level + file-level)
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('*', 'count'))
				->from('metavox_gf_metadata');
			$result = $qb->executeQuery();
			$folderEntries = (int)$result->fetchOne();
			$result->closeCursor();

			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('*', 'count'))
				->from('metavox_file_gf_meta');
			$result = $qb->executeQuery();
			$fileEntries = (int)$result->fetchOne();
			$result->closeCursor();

			$totalEntries = $folderEntries + $fileEntries;

			// Entries per groupfolder (both levels combined)
			$entriesPerFolder = [];

			$qb = $this->db->getQueryBuilder();
			$qb->select('groupfolder_id')
				->selectAlias($qb->func()->count('*'), 'count')
				->from('metavox_gf_metadata')
				->groupBy('groupfolder_id');
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$gfId = (int)$row['groupfolder_id'];
				$entriesPerFolder[$gfId] = ($entriesPerFolder[$gfId] ?? 0) + (int)$row['count'];
			}
			$result->closeCursor();

			$qb = $this->db->getQueryBuilder();
			$qb->select('groupfolder_id')
				->selectAlias($qb->func()->count('*'), 'count')
				->from('metavox_file_gf_meta')
				->groupBy('groupfolder_id');
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$gfId = (int)$row['groupfolder_id'];
				$entriesPerFolder[$gfId] = ($entriesPerFolder[$gfId] ?? 0) + (int)$row['count'];
			}
			$result->closeCursor();

			// Total users
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('uid', 'count'))
				->from('users');
			$result = $qb->executeQuery();
			$totalUsers = (int)$result->fetchOne();
			$result->closeCursor();

			return [
				'teamFoldersWithFields' => $teamFoldersWithFields,
				'totalEntries' => $totalEntries,
				'entriesPerFolder' => $entriesPerFolder,
				'totalUsers' => $totalUsers,
			];
		} catch (\Exception $e) {
			$this->logger->warning('LicenseService: Failed to get usage stats', [
				'error' => $e->getMessage(),
			]);
			return [
				'teamFoldersWithFields' => 0,
				'totalEntries' => 0,
				'entriesPerFolder' => [],
				'totalUsers' => 0,
			];
		}
	}

	private function getAppVersion(): string {
		return $this->config->getAppValue(Application::APP_ID, 'installed_version', '0.0.0');
	}
}
