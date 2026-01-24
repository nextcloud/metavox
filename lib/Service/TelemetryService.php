<?php
declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IGroupManager;
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

    public function __construct(
        IClientService $httpClient,
        IConfig $config,
        IDBConnection $db,
        LoggerInterface $logger,
        IUserManager $userManager,
        IGroupManager $groupManager
    ) {
        $this->httpClient = $httpClient;
        $this->config = $config;
        $this->db = $db;
        $this->logger = $logger;
        $this->userManager = $userManager;
        $this->groupManager = $groupManager;
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
        if (!$this->isEnabled()) {
            $this->logger->debug('TelemetryService: Telemetry is disabled, skipping report');
            return false;
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

                return true;
            }

            $this->logger->warning('TelemetryService: Report failed', [
                'statusCode' => $statusCode
            ]);
            return false;
        } catch (\Exception $e) {
            $this->logger->warning('TelemetryService: Report failed', [
                'error' => $e->getMessage()
            ]);
            return false;
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
            'metavoxVersion' => $this->getAppVersion(),
            'nextcloudVersion' => $this->getNextcloudVersion(),
            'phpVersion' => PHP_VERSION
        ];
    }

    /**
     * Get hashed instance URL for anonymous identification
     */
    private function getInstanceUrlHash(): string {
        $instanceUrl = $this->config->getSystemValue('overwrite.cli.url', '');
        if (empty($instanceUrl)) {
            $instanceUrl = $this->config->getSystemValue('trusted_domains', ['localhost'])[0] ?? 'localhost';
        }
        return hash('sha256', strtolower(rtrim($instanceUrl, '/')));
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

            // Metadata per groupfolder
            $qb = $this->db->getQueryBuilder();
            $qb->select('groupfolder_id')
               ->selectAlias($qb->func()->count('*'), 'count')
               ->from('metavox_file_gf_meta')
               ->groupBy('groupfolder_id');
            $result = $qb->executeQuery();
            $perGroupfolder = [];
            while ($row = $result->fetch()) {
                $perGroupfolder[(int)$row['groupfolder_id']] = (int)$row['count'];
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

            // Groupfolders with metadata entries
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count($qb->createFunction('DISTINCT groupfolder_id'), 'count'))
               ->from('metavox_file_gf_meta');
            $result = $qb->executeQuery();
            $groupfoldersWithMetadata = (int)$result->fetchOne();
            $result->closeCursor();

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
    private function getUserCount(): int {
        try {
            $count = 0;
            $this->userManager->callForSeenUsers(function ($user) use (&$count) {
                $count++;
            });
            return $count;
        } catch (\Exception $e) {
            $this->logger->warning('TelemetryService: Failed to count users', [
                'error' => $e->getMessage()
            ]);
            return 0;
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
     * Get telemetry status for admin panel
     */
    public function getStatus(): array {
        return [
            'enabled' => $this->isEnabled(),
            'lastReport' => $this->getLastReportTime(),
            'telemetryUrl' => $this->getTelemetryUrl()
        ];
    }
}
