<?php
declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IGroupManager;
use OCP\Support\Subscription\IRegistry;
use Psr\Log\LoggerInterface;

/**
 * Service for anonymous telemetry data collection and reporting
 * This is an opt-in feature that helps improve MetaVox
 */
class TelemetryService {
    private const APP_ID = 'metavox';
    private const TELEMETRY_URL = 'https://licenses.voxcloud.nl/api/telemetry/metavox/report';

    private IClientService $httpClient;
    private IConfig $config;
    private IDBConnection $db;
    private LoggerInterface $logger;
    private IUserManager $userManager;
    private IGroupManager $groupManager;
    private LicenseService $licenseService;
    private ?IRegistry $subscriptionRegistry;

    public function __construct(
        IClientService $httpClient,
        IConfig $config,
        IDBConnection $db,
        LoggerInterface $logger,
        IUserManager $userManager,
        IGroupManager $groupManager,
        LicenseService $licenseService,
        ?IRegistry $subscriptionRegistry = null
    ) {
        $this->httpClient = $httpClient;
        $this->config = $config;
        $this->db = $db;
        $this->logger = $logger;
        $this->userManager = $userManager;
        $this->groupManager = $groupManager;
        $this->licenseService = $licenseService;
        $this->subscriptionRegistry = $subscriptionRegistry;
    }

    /**
     * Check if telemetry is enabled
     * Default is true (opt-out instead of opt-in)
     */
    public function isEnabled(): bool {
        return $this->config->getAppValue(self::APP_ID, 'telemetry_enabled', 'true') === 'true';
    }

    /**
     * Enable or disable telemetry
     */
    public function setEnabled(bool $enabled): void {
        $this->config->setAppValue(self::APP_ID, 'telemetry_enabled', $enabled ? 'true' : 'false');
        $this->logger->info('TelemetryService: Telemetry ' . ($enabled ? 'enabled' : 'disabled'));
    }

    /**
     * Get the telemetry server URL
     */
    public function getTelemetryUrl(): string {
        return $this->config->getAppValue(
            self::APP_ID,
            'telemetry_url',
            self::TELEMETRY_URL
        );
    }

    /**
     * Send telemetry report to the server
     * @return bool Success status
     */
    public function sendReport(): bool {
        return $this->sendReportWithDetails()['success'];
    }

    /**
     * Send telemetry report to the server with detailed error info
     * @return array{success: bool, reason?: string, message?: string}
     */
    public function sendReportWithDetails(): array {
        if (!$this->isEnabled()) {
            return ['success' => false, 'reason' => 'disabled'];
        }

        try {
            $data = $this->collectData();

            $client = $this->httpClient->newClient();
            $response = $client->post($this->getTelemetryUrl(), [
                'json' => $data,
                'timeout' => 15,
                'headers' => [
                    'User-Agent' => 'MetaVox/' . $this->getAppVersion(),
                    'Content-Type' => 'application/json'
                ]
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info('TelemetryService: Report sent successfully', [
                    'totalFields' => $data['totalFields'],
                    'totalMetadataEntries' => $data['totalMetadataEntries']
                ]);

                // Store last report time
                $this->config->setAppValue(
                    self::APP_ID,
                    'telemetry_last_report',
                    (string)time()
                );

                return ['success' => true];
            }

            return ['success' => false, 'reason' => 'server_error', 'message' => 'HTTP ' . $statusCode];
        } catch (\Exception $e) {
            $message = $e->getMessage();
            if (method_exists($e, 'getResponse') && $e->getResponse() !== null) {
                $body = (string) $e->getResponse()->getBody();
                $json = json_decode($body, true);
                if (isset($json['error'])) {
                    $message = $json['error'];
                } elseif (!empty($body) && strlen($body) < 200) {
                    $message = $body;
                }
            }
            $this->logger->warning('TelemetryService: Failed to send report: ' . $message);
            return ['success' => false, 'reason' => 'error', 'message' => $message];
        }
    }

    /**
     * Collect telemetry data
     */
    private function collectData(): array {
        $instanceHash = $this->getInstanceUrlHash();
        $fieldStats = $this->getFieldStatistics();
        $metadataStats = $this->getMetadataStatistics();
        $groupfolderStats = $this->getGroupfolderStatistics();

        return [
            'appType' => 'metavox',
            'instanceHash' => $instanceHash,
            'totalFields' => $fieldStats['total'],
            'fieldTypeCounts' => $fieldStats['byType'],
            'totalGroupfolders' => $groupfolderStats['total'],
            'groupfoldersWithFields' => $groupfolderStats['withFields'],
            'groupfoldersWithMetadata' => $groupfolderStats['withMetadata'],
            'totalMetadataEntries' => $metadataStats['total'],
            'metadataEntriesPerGroupfolder' => $metadataStats['perGroupfolder'],
            'filesWithMetadata' => $metadataStats['filesWithMetadata'],
            'totalUsers' => $this->getUserCount(),
            'activeUsers30d' => $this->getActiveUserCount(30),
            'disabledUsers' => $this->getDisabledUserCount(),
            'metavoxVersion' => $this->getAppVersion(),
            'nextcloudVersion' => $this->getNextcloudVersion(),
            'phpVersion' => PHP_VERSION,
            'countryCode' => $this->getCountryCode(),
            'databaseType' => $this->config->getSystemValue('dbtype', 'sqlite'),
            'defaultLanguage' => $this->config->getSystemValue('default_language', 'en'),
            'defaultTimezone' => $this->getDefaultTimezone(),
            'osFamily' => PHP_OS_FAMILY,
            'webServer' => $this->getWebServer(),
            'isDocker' => $this->isDocker(),
            // The Enterprise signal. hasExtendedSupport is the narrower add-on
            // and is kept alongside it: servers that predate hasValidSubscription
            // still read that key, and it stays useful on its own.
            'hasValidSubscription' => $this->hasValidSubscription(),
            'hasExtendedSupport' => $this->hasExtendedSupport(),
            // Sent so the license server can verify the hasExtendedSupport claim —
            // the boolean alone is unauthenticated and could be spoofed by anyone
            // posting to the telemetry endpoint. The server only honors the claim
            // when this key + the instance hash match an active license_usage row.
            // Empty string for community instances (no license) — server treats
            // those as 'never Enterprise' which is correct.
            'licenseKey' => $this->licenseService->getLicenseKey(),
        ];
    }

    /**
     * Whether the host Nextcloud has a valid Enterprise subscription.
     *
     * Asks IRegistry directly rather than going through
     * OCP\Util::hasExtendedSupport(). That helper answers a different question:
     * delegateHasExtendedSupport() reports the paid *Extended Support* add-on,
     * which sits on top of a subscription. An ordinary Enterprise customer
     * without that add-on answers false, so every such instance was counted as
     * Community. Nextcloud core itself never uses hasExtendedSupport() for
     * subscription decisions -- ServerDevNotice, PushService and
     * updatenotification all call delegateHasValidSubscription().
     *
     * It also drops a spoofing hole: Util::hasExtendedSupport() falls back to
     * the `extendedSupport` system config value when the registry is missing,
     * so any admin could set it by hand. IRegistry only answers true when a
     * real ISubscription handler is registered.
     *
     * Returns false on any failure, so Community is never reported as
     * Enterprise.
     */
    private function hasValidSubscription(): bool {
        try {
            return $this->subscriptionRegistry?->delegateHasValidSubscription() ?? false;
        } catch (\Throwable $e) {
            $this->logger->debug('TelemetryService: delegateHasValidSubscription() check failed', [
                'error' => $e->getMessage()
            ]);
        }
        return false;
    }

    /**
     * Whether that subscription also carries the Extended Support add-on.
     *
     * Reported separately so the two signals stay distinguishable: this is a
     * strict subset of hasValidSubscription() and is not a substitute for it.
     */
    private function hasExtendedSupport(): bool {
        try {
            return $this->subscriptionRegistry?->delegateHasExtendedSupport() ?? false;
        } catch (\Throwable $e) {
            $this->logger->debug('TelemetryService: delegateHasExtendedSupport() check failed', [
                'error' => $e->getMessage()
            ]);
        }
        return false;
    }

    /**
     * Get hashed instance URL for anonymous identification.
     * Delegates to LicenseService so the telemetry instanceHash is byte-for-byte
     * identical to license_usage.instance_url_hash — required for the license
     * server's enterprise-claim validation join.
     */
    private function getInstanceUrlHash(): string {
        return $this->licenseService->getInstanceUrlHash();
    }

    /**
     * Get field statistics
     */
    private function getFieldStatistics(): array {
        try {
            // Get total fields and count by type
            $qb = $this->db->getQueryBuilder();
            $qb->select('field_type')
               ->selectAlias($qb->func()->count('id'), 'count')
               ->from('metavox_gf_fields')
               ->groupBy('field_type');

            $result = $qb->executeQuery();
            $byType = [];
            $total = 0;

            while ($row = $result->fetch()) {
                $byType[$row['field_type']] = (int)$row['count'];
                $total += (int)$row['count'];
            }
            $result->closeCursor();

            return [
                'total' => $total,
                'byType' => $byType
            ];
        } catch (\Exception $e) {
            $this->logger->warning('TelemetryService: Failed to get field statistics', [
                'error' => $e->getMessage()
            ]);
            return ['total' => 0, 'byType' => []];
        }
    }

    /**
     * Get metadata entry statistics
     */
    private function getMetadataStatistics(): array {
        try {
            // Total metadata entries for groupfolders
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'count'))
               ->from('metavox_gf_metadata');
            $result = $qb->executeQuery();
            $gfMetaCount = (int)$result->fetchOne();
            $result->closeCursor();

            // Total metadata entries for files
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'count'))
               ->from('metavox_file_gf_meta');
            $result = $qb->executeQuery();
            $fileMetaCount = (int)$result->fetchOne();
            $result->closeCursor();

            // Unique files with metadata
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count($qb->createFunction('DISTINCT file_id'), 'count'))
               ->from('metavox_file_gf_meta');
            $result = $qb->executeQuery();
            $filesWithMeta = (int)$result->fetchOne();
            $result->closeCursor();

            // Metadata per groupfolder (both folder-level and file-level)
            $perGroupfolder = [];

            $qb = $this->db->getQueryBuilder();
            $qb->select('groupfolder_id')
               ->selectAlias($qb->func()->count('*'), 'count')
               ->from('metavox_gf_metadata')
               ->groupBy('groupfolder_id');
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $gfId = (int)$row['groupfolder_id'];
                $perGroupfolder[$gfId] = ($perGroupfolder[$gfId] ?? 0) + (int)$row['count'];
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
                $perGroupfolder[$gfId] = ($perGroupfolder[$gfId] ?? 0) + (int)$row['count'];
            }
            $result->closeCursor();

            return [
                'total' => $gfMetaCount + $fileMetaCount,
                'filesWithMetadata' => $filesWithMeta,
                'perGroupfolder' => $perGroupfolder
            ];
        } catch (\Exception $e) {
            $this->logger->warning('TelemetryService: Failed to get metadata statistics', [
                'error' => $e->getMessage()
            ]);
            return ['total' => 0, 'filesWithMetadata' => 0, 'perGroupfolder' => []];
        }
    }

    /**
     * Get groupfolder statistics
     */
    private function getGroupfolderStatistics(): array {
        try {
            // Total groupfolders in the system
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('folder_id', 'count'))
               ->from('group_folders');
            $result = $qb->executeQuery();
            $totalGroupfolders = (int)$result->fetchOne();
            $result->closeCursor();

            // Groupfolders with MetaVox fields assigned
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count($qb->createFunction('DISTINCT groupfolder_id'), 'count'))
               ->from('metavox_gf_assigns');
            $result = $qb->executeQuery();
            $groupfoldersWithFields = (int)$result->fetchOne();
            $result->closeCursor();

            // Groupfolders with metadata entries (both folder-level and file-level)
            // Count from folder-level metadata
            $qb = $this->db->getQueryBuilder();
            $qb->select('groupfolder_id')
               ->from('metavox_gf_metadata')
               ->groupBy('groupfolder_id');
            $result = $qb->executeQuery();
            $gfWithFolderMeta = [];
            while ($row = $result->fetch()) {
                $gfWithFolderMeta[(int)$row['groupfolder_id']] = true;
            }
            $result->closeCursor();

            // Count from file-level metadata
            $qb = $this->db->getQueryBuilder();
            $qb->select('groupfolder_id')
               ->from('metavox_file_gf_meta')
               ->groupBy('groupfolder_id');
            $result = $qb->executeQuery();
            $gfWithFileMeta = [];
            while ($row = $result->fetch()) {
                $gfWithFileMeta[(int)$row['groupfolder_id']] = true;
            }
            $result->closeCursor();

            // Merge: unique groupfolders with any metadata
            $groupfoldersWithMetadata = count($gfWithFolderMeta + $gfWithFileMeta);

            return [
                'total' => $totalGroupfolders,
                'withFields' => $groupfoldersWithFields,
                'withMetadata' => $groupfoldersWithMetadata
            ];
        } catch (\Exception $e) {
            $this->logger->warning('TelemetryService: Failed to get groupfolder statistics', [
                'error' => $e->getMessage()
            ]);
            return ['total' => 0, 'withFields' => 0, 'withMetadata' => 0];
        }
    }

    /**
     * Get total user count
     */
    /**
     * Accounts that exist but are disabled.
     *
     * They count towards the named-user total, because disabling is how
     * Nextcloud offboards someone while keeping their file ownership. Reported
     * separately so the difference is visible when usage is compared against a
     * contract -- otherwise a customer who has shrunk looks like one who never
     * did.
     *
     * Returns null rather than 0 on failure: the licence server distinguishes
     * "this app does not report the figure" from "measured, nobody disabled",
     * and a swallowed error must not read as the latter.
     */
    private function getDisabledUserCount(): ?int {
        try {
            $count = 0;
            $this->userManager->callForAllUsers(function ($user) use (&$count) {
                if (!$user->isEnabled()) {
                    $count++;
                }
            });
            return $count;
        } catch (\Exception $e) {
            $this->logger->warning('TelemetryService: Failed to count disabled users', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function getUserCount(): int {
        try {
            $count = 0;
            $this->userManager->callForAllUsers(function ($user) use (&$count) {
                $count++;
            });
            return max(1, $count);
        } catch (\Exception $e) {
            $this->logger->warning('TelemetryService: Failed to count users', [
                'error' => $e->getMessage()
            ]);
            return 1;
        }
    }

    /**
     * Get active user count for the last N days
     */
    private function getActiveUserCount(int $days): int {
        try {
            $cutoffTime = time() - ($days * 24 * 60 * 60);
            $count = 0;

            $this->userManager->callForSeenUsers(function ($user) use (&$count, $cutoffTime) {
                $lastLogin = $user->getLastLogin();
                if ($lastLogin >= $cutoffTime) {
                    $count++;
                }
            });

            return $count;
        } catch (\Exception $e) {
            $this->logger->warning('TelemetryService: Failed to count active users', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Get the MetaVox app version
     */
    private function getAppVersion(): string {
        return $this->config->getAppValue(self::APP_ID, 'installed_version', 'unknown');
    }

    /**
     * Get the Nextcloud version
     */
    private function getNextcloudVersion(): string {
        return $this->config->getSystemValue('version', 'unknown');
    }

    /**
     * Get the last report timestamp
     */
    public function getLastReportTime(): ?int {
        $time = $this->config->getAppValue(self::APP_ID, 'telemetry_last_report', '');
        return empty($time) ? null : (int)$time;
    }

    /**
     * Check if a report should be sent (not sent in last 24 hours)
     */
    public function shouldSendReport(): bool {
        if (!$this->isEnabled()) {
            return false;
        }

        $lastReport = $this->getLastReportTime();
        if ($lastReport === null) {
            return true;
        }

        // Send report if more than 24 hours since last report
        return (time() - $lastReport) > (24 * 60 * 60);
    }

    /**
     * Get ISO 3166-1 alpha-2 country code from default_phone_region setting
     * Returns null if not configured — server derives country from timezone
     */
    private function getCountryCode(): ?string {
        $region = $this->config->getSystemValue('default_phone_region', '');
        if (!empty($region) && preg_match('/^[A-Z]{2}$/', strtoupper($region))) {
            return strtoupper($region);
        }
        return null;
    }

    /**
     * Get the default timezone setting
     * Tries Nextcloud config first, then PHP default, falls back to UTC
     */
    private function getDefaultTimezone(): string {
        $tz = $this->config->getSystemValue('default_timezone', '');
        if (!empty($tz) && $tz !== 'UTC') {
            return $tz;
        }
        // Try PHP's configured timezone (from php.ini)
        $phpTz = date_default_timezone_get();
        if (!empty($phpTz) && $phpTz !== 'UTC') {
            return $phpTz;
        }
        return 'UTC';
    }

    /**
     * Detect web server from SERVER_SOFTWARE header
     */
    private function getWebServer(): ?string {
        $software = $_SERVER['SERVER_SOFTWARE'] ?? null;
        if ($software === null) {
            return null;
        }
        if (stripos($software, 'apache') !== false) {
            return 'Apache';
        }
        if (stripos($software, 'nginx') !== false) {
            return 'nginx';
        }
        return explode('/', $software)[0];
    }

    /**
     * Detect if running inside a Docker container
     */
    private function isDocker(): bool {
        if (file_exists('/.dockerenv')) {
            return true;
        }
        if (file_exists('/proc/1/cgroup')) {
            $cgroup = @file_get_contents('/proc/1/cgroup');
            if ($cgroup !== false && str_contains($cgroup, 'docker')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get telemetry status for admin panel
     */
    public function getStatus(): array {
        return [
            'enabled' => $this->isEnabled(),
            'lastReport' => $this->getLastReportTime(),
            'telemetryUrl' => $this->getTelemetryUrl()
        ];
    }

    /**
     * Get statistics for admin panel display
     */
    public function getStats(): array {
        $fieldStats = $this->getFieldStatistics();
        $groupfolderStats = $this->getGroupfolderStatistics();
        $metadataStats = $this->getMetadataStatistics();

        return [
            'totalFields' => $fieldStats['total'],
            'fieldTypeCounts' => $fieldStats['byType'],
            'groupfoldersWithMetadata' => $groupfolderStats['withMetadata'],
            'totalEntries' => $metadataStats['total'],
            'filesWithMetadata' => $metadataStats['filesWithMetadata']
        ];
    }
}
