<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCA\MetaVox\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Support\Subscription\IRegistry;
use Psr\Log\LoggerInterface;

class LicenseService {
	private const FREE_TEAM_FOLDER_LIMIT = 20;
	/**
	 * Above this many users the interface suggests a support subscription.
	 *
	 * Not a limit and not enforced anywhere -- the app behaves identically on
	 * either side of it. It marks where paid subscriptions begin in the price
	 * list, so below it there is genuinely nothing to suggest.
	 */
	private const SUPPORT_NUDGE_USER_THRESHOLD = 100;
	// No entries-per-folder limit — only folder count is limited in free tier
	private const LICENSE_SERVER_URL = 'https://licenses.voxcloud.nl';

	public function __construct(
		private IClientService $httpClient,
		private IConfig $config,
		private IDBConnection $db,
		private IUserManager $userManager,
		private LoggerInterface $logger,
		private ?IRegistry $subscriptionRegistry = null,
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

	/**
	 * SHA-256 of the instance URL, so the licence server never sees the URL
	 * itself.
	 *
	 * The URL is hashed as a full URL (scheme + host) so licence data lines up
	 * across apps.
	 *
	 * The source must be request-context-independent: the daily cron job and an
	 * admin web request both compute this hash, and if they disagreed the server
	 * would see two instances for one customer and freeze the seat count. We
	 * therefore use overwrite.cli.url when set, otherwise trusted_domains[0]
	 * promoted to a full URL — both are identical from cron and web.
	 */
	public function getInstanceUrlHash(): string {
		return hash('sha256', $this->normalizedInstanceUrl());
	}

	/**
	 * Request-independent instance URL, lower-cased and without a trailing
	 * slash. overwrite.cli.url wins; otherwise trusted_domains[0] is promoted
	 * to https:// so it is a full URL rather than a bare hostname.
	 */
	private function normalizedInstanceUrl(): string {
		$url = $this->config->getSystemValue('overwrite.cli.url', '');
		if (empty($url)) {
			$domain = $this->config->getSystemValue('trusted_domains', ['localhost'])[0] ?? 'localhost';
			// Promote a bare hostname to a full URL; leave an already-qualified
			// value (someone put a scheme in trusted_domains) untouched.
			$url = preg_match('#^https?://#i', $domain) ? $domain : 'https://' . $domain;
		}
		return strtolower(rtrim($url, '/'));
	}

	/**
	 * The hash this app used to send before the change above, so the server can
	 * recognise the instance across it instead of treating it as a second one —
	 * which would be refused, freezing the seat count at its pre-update value.
	 *
	 * Returns '' when overwrite.cli.url is set (the hash never changed for those
	 * instances) or when the legacy hash equals the current one (nothing to
	 * migrate). Otherwise it keeps returning the legacy hash: we have no local
	 * signal that the server has adopted the new hash, so we keep sending it —
	 * the server is idempotent and ignores it once adopted.
	 */
	public function getPreviousInstanceUrlHash(): string {
		if (!empty($this->config->getSystemValue('overwrite.cli.url', ''))) {
			return '';
		}

		$legacy = $this->config->getSystemValue('trusted_domains', ['localhost'])[0] ?? 'localhost';
		$hash = hash('sha256', strtolower(rtrim($legacy, '/')));

		return $hash === $this->getInstanceUrlHash() ? '' : $hash;
	}

	/**
	 * Includes previousInstanceUrlHash while the legacy hash differs from the
	 * current one, so the server can adopt the new hash. The field is omitted
	 * for instances whose hash never changed (overwrite.cli.url set).
	 */
	private function hashMigrationPayload(): array {
		$previous = $this->getPreviousInstanceUrlHash();

		return $previous === '' ? [] : ['previousInstanceUrlHash' => $previous];
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
				] + $this->hashMigrationPayload(),
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
					'disabledUsers' => $this->countDisabledUsers(),
					// Tells the server how the count was taken, so readings from
					// releases that counted unreliably stay out of the averages
					// a contract is measured against.
					'countMethod' => self::COUNT_METHOD,
				] + $this->hashMigrationPayload(),
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

			return [
				'isFree' => true,
				'teamFolderLimit' => self::FREE_TEAM_FOLDER_LIMIT,
				'teamFoldersUsed' => $stats['teamFoldersWithFields'],
				'teamFoldersExceeded' => $exceededFolders,
				'exceeded' => $exceededFolders,
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
			'supportNudgeUserThreshold' => self::SUPPORT_NUDGE_USER_THRESHOLD,
			'hasValidSubscription' => $this->hasValidSubscription(),
			'hasExtendedSupport' => $this->hasExtendedSupport(),
			'hasLicense' => $hasLicense,
			'licenseValid' => $licenseValid,
			'licenseInfo' => $licenseInfo,
			'licenseKeyMasked' => $maskedKey,
			'limits' => $limits,
			'freeTeamFolderLimit' => self::FREE_TEAM_FOLDER_LIMIT,
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

			$totalUsers = $this->countAllUsers();

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
	/**
	 * How the user count is taken, reported alongside it so the licence server
	 * can keep readings from releases that counted unreliably out of the
	 * averages a contract is measured against.
	 */
	public const COUNT_METHOD = 'callForAllUsers';

	/**
	 * Total named users.
	 *
	 * callForAllUsers covers every backend, so LDAP and SSO users are included.
	 * Counting rows in oc_users misses them entirely: those accounts often
	 * exist only in the backend, which understated exactly the large customers
	 * a subscription is priced on ("per named user").
	 */
	private function countAllUsers(): int {
		$count = 0;
		$this->userManager->callForAllUsers(function () use (&$count) {
			$count++;
		});
		return $count;
	}

	/**
	 * Users that exist but are disabled. They count towards the named-user
	 * total, because disabling is how an account is retired without deleting
	 * its data — the seat is still occupied.
	 */
	private function countDisabledUsers(): int {
		try {
			$count = 0;
			$this->userManager->callForAllUsers(function ($user) use (&$count) {
				if (!$user->isEnabled()) {
					$count++;
				}
			});
			return $count;
		} catch (\Throwable $e) {
			$this->logger->warning('LicenseService: Failed to count disabled users', [
				'error' => $e->getMessage()
			]);
			return 0;
		}
	}


	/**
	 * Whether the host Nextcloud has a valid Enterprise subscription.
	 *
	 * Asks IRegistry rather than OCP\Util::hasExtendedSupport(), which answers a
	 * different question: that helper reports the paid Extended Support add-on, so
	 * an ordinary Enterprise customer without it answers false and looks like
	 * Community. It also falls back to the `extendedSupport` system config value
	 * when the registry is missing, which an admin can set by hand.
	 *
	 * Mirrors TelemetryService, so the settings page and the report sent to the
	 * licence server cannot disagree about the same instance.
	 */
	private function hasValidSubscription(): bool {
		try {
			return $this->subscriptionRegistry?->delegateHasValidSubscription() ?? false;
		} catch (\Throwable $e) {
			$this->logger->debug('LicenseService: delegateHasValidSubscription() check failed', [
				'error' => $e->getMessage()
			]);
		}
		return false;
	}

	/**
	 * Whether that subscription also carries the Extended Support add-on. A strict
	 * subset of hasValidSubscription(), reported separately so the two signals stay
	 * distinguishable.
	 */
	private function hasExtendedSupport(): bool {
		try {
			return $this->subscriptionRegistry?->delegateHasExtendedSupport() ?? false;
		} catch (\Throwable $e) {
			$this->logger->debug('LicenseService: delegateHasExtendedSupport() check failed', [
				'error' => $e->getMessage()
			]);
		}
		return false;
	}

}
