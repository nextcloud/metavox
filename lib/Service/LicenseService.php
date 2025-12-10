<?php
declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\IConfig;
use OCP\Http\Client\IClientService;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class LicenseService {
    private IConfig $config;
    private IClientService $clientService;
    private IDBConnection $db;
    private LoggerInterface $logger;
    private ?string $licenseKey = null;
    private ?string $licenseServerUrl = null;
    private ?string $instanceUrl = null;

    public function __construct(
        IConfig $config,
        IClientService $clientService,
        IDBConnection $db,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->clientService = $clientService;
        $this->db = $db;
        $this->logger = $logger;

        // Load license configuration from database
        $this->loadLicenseConfig();

        // Get instance URL - try multiple sources
        $this->instanceUrl = $this->getInstanceUrl();
    }

    /**
     * Get the instance URL from various config sources
     */
    private function getInstanceUrl(): string {
        // Try overwrite.cli.url first
        $url = $this->config->getSystemValue('overwrite.cli.url', '');

        // If not set or is localhost, try overwritehost
        if (empty($url) || strpos($url, 'localhost') !== false || strpos($url, '127.0.0.1') !== false) {
            $host = $this->config->getSystemValue('overwritehost', '');
            $protocol = $this->config->getSystemValue('overwriteprotocol', 'https');

            if (!empty($host)) {
                $url = $protocol . '://' . $host;
            }
        }

        // If still not valid, try to get from request
        if (empty($url) || strpos($url, 'localhost') !== false || strpos($url, '127.0.0.1') !== false) {
            // Check if we're in a web request
            if (isset($_SERVER['HTTP_HOST'])) {
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
                $url = $protocol . '://' . $_SERVER['HTTP_HOST'];
            }
        }

        // Fallback to a placeholder that can be recognized
        if (empty($url) || strpos($url, 'localhost') !== false || strpos($url, '127.0.0.1') !== false) {
            $url = 'https://nextcloud-instance.example.com';
        }

        return rtrim($url, '/');
    }

    private function loadLicenseConfig(): void {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('license_key', 'license_server_url')
                ->from('metavox_license_settings')
                ->setMaxResults(1);

            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();

            if ($row) {
                $this->licenseKey = $row['license_key'];
                $this->licenseServerUrl = $row['license_server_url'];
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to load license config from database: ' . $e->getMessage());
        }
    }

    /**
     * Check if license is configured
     */
    public function isConfigured(): bool {
        return !empty($this->licenseKey) && !empty($this->licenseServerUrl);
    }

    /**
     * Validate license with the license server
     */
    public function validateLicense(): array {
        if (!$this->isConfigured()) {
            return [
                'valid' => false,
                'reason' => 'License not configured',
                'configured' => false
            ];
        }

        try {
            $client = $this->clientService->newClient();
            $response = $client->post($this->licenseServerUrl . '/api/licenses/validate', [
                'json' => [
                    'licenseKey' => $this->licenseKey,
                    'instanceUrl' => $this->instanceUrl
                ],
                'timeout' => 10
            ]);

            $data = json_decode($response->getBody(), true);

            if ($data['valid']) {
                return [
                    'valid' => true,
                    'configured' => true,
                    'license' => $data['license'] ?? [],
                    'usage' => $data['usage'] ?? null,
                    'limits' => $data['limits'] ?? []
                ];
            }

            return [
                'valid' => false,
                'configured' => true,
                'reason' => $data['reason'] ?? 'Unknown error'
            ];
        } catch (\Exception $e) {
            $this->logger->error('License validation failed: ' . $e->getMessage(), [
                'exception' => $e
            ]);

            return [
                'valid' => false,
                'configured' => true,
                'reason' => 'Failed to connect to license server: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check if creating a new team folder is allowed
     */
    public function canCreateTeamFolder(): array {
        $currentCount = $this->countTeamFolders();

        if (!$this->isConfigured()) {
            // No license configured - use default limit of 5 folders
            $allowed = $currentCount < 5;
            return [
                'allowed' => $allowed,
                'reason' => $allowed ? 'Within default limit' : 'Default limit of 5 team folders reached. Please configure a license to increase this limit.'
            ];
        }

        try {
            $client = $this->clientService->newClient();
            $response = $client->post($this->licenseServerUrl . '/api/licenses/check-limit', [
                'json' => [
                    'licenseKey' => $this->licenseKey,
                    'instanceUrl' => $this->instanceUrl
                ],
                'timeout' => 10
            ]);

            return json_decode($response->getBody(), true);
        } catch (\Exception $e) {
            $this->logger->error('License limit check failed: ' . $e->getMessage(), [
                'exception' => $e
            ]);

            // On error, allow creation but log the error
            return [
                'allowed' => true,
                'reason' => 'License server unreachable, allowing by default'
            ];
        }
    }

    /**
     * Update usage statistics
     */
    public function updateUsage(int $currentTeamFolders, int $currentUsers = 0): bool {
        if (!$this->isConfigured()) {
            return false;
        }

        try {
            $client = $this->clientService->newClient();
            $response = $client->post($this->licenseServerUrl . '/api/licenses/usage', [
                'json' => [
                    'licenseKey' => $this->licenseKey,
                    'instanceUrl' => $this->instanceUrl,
                    'instanceName' => $this->config->getSystemValue('instanceid', 'Nextcloud'),
                    'currentTeamFolders' => $currentTeamFolders,
                    'currentUsers' => $currentUsers
                ],
                'timeout' => 10
            ]);

            $data = json_decode($response->getBody(), true);

            if (isset($data['limits']['teamFoldersExceeded']) && $data['limits']['teamFoldersExceeded']) {
                $this->logger->warning('Team folder limit exceeded', [
                    'current' => $currentTeamFolders,
                    'max' => $data['limits']['maxTeamFolders'] ?? 'unknown'
                ]);
            }

            return $data['success'] ?? false;
        } catch (\Exception $e) {
            $this->logger->error('Failed to update license usage: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            return false;
        }
    }

    /**
     * Get current license info
     */
    public function getLicenseInfo(): ?array {
        $validation = $this->validateLicense();

        // Get current team folder count from database
        $currentTeamFolders = $this->countTeamFolders();

        if ($validation['valid']) {
            $maxTeamFolders = $validation['license']['maxTeamFolders'] ?? 0;

            return [
                'configured' => true,
                'valid' => true,
                'licenseType' => $validation['license']['licenseType'] ?? 'unknown',
                'maxTeamFolders' => $maxTeamFolders,
                'maxUsers' => $validation['license']['maxUsers'] ?? null,
                'validUntil' => $validation['license']['validUntil'] ?? null,
                'isTrial' => $validation['license']['isTrial'] ?? false,
                'currentTeamFolders' => $currentTeamFolders,
                'currentUsers' => $validation['usage']['current_users'] ?? 0,
                'limitsExceeded' => [
                    'teamFolders' => $currentTeamFolders >= $maxTeamFolders,
                    'users' => $validation['limits']['usersExceeded'] ?? false
                ]
            ];
        }

        // No valid license - return default limit of 5 folders
        return [
            'configured' => $validation['configured'] ?? false,
            'valid' => false,
            'reason' => $validation['reason'] ?? 'No license configured',
            'maxTeamFolders' => 5,
            'currentTeamFolders' => $currentTeamFolders,
            'limitsExceeded' => [
                'teamFolders' => $currentTeamFolders >= 5
            ]
        ];
    }

    /**
     * Count team folders with metadata fields assigned
     */
    private function countTeamFolders(): int {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->selectAlias($qb->createFunction('COUNT(DISTINCT groupfolder_id)'), 'count')
                ->from('metavox_gf_assigns');

            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();

            return (int)($row['count'] ?? 0);
        } catch (\Exception $e) {
            $this->logger->error('Failed to count team folders: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get license configuration (for admin settings)
     */
    public function getLicenseConfig(): array {
        return [
            'licenseKey' => $this->licenseKey,
            'licenseServerUrl' => $this->licenseServerUrl,
            'instanceUrl' => $this->instanceUrl,
            'configured' => $this->isConfigured()
        ];
    }

    /**
     * Save license configuration
     */
    public function saveLicenseConfig(string $licenseKey, string $licenseServerUrl): bool {
        try {
            $qb = $this->db->getQueryBuilder();

            // Check if settings exist
            $qb->select('id')
                ->from('metavox_license_settings')
                ->setMaxResults(1);

            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();

            $now = new \DateTime();

            if ($row) {
                // Update existing
                $qb = $this->db->getQueryBuilder();
                $qb->update('metavox_license_settings')
                    ->set('license_key', $qb->createNamedParameter($licenseKey))
                    ->set('license_server_url', $qb->createNamedParameter($licenseServerUrl))
                    ->set('updated_at', $qb->createNamedParameter($now, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_DATE))
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($row['id'])));

                $qb->executeStatement();
            } else {
                // Insert new
                $qb = $this->db->getQueryBuilder();
                $qb->insert('metavox_license_settings')
                    ->values([
                        'license_key' => $qb->createNamedParameter($licenseKey),
                        'license_server_url' => $qb->createNamedParameter($licenseServerUrl),
                        'updated_at' => $qb->createNamedParameter($now, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_DATE)
                    ]);

                $qb->executeStatement();
            }

            // Reload config
            $this->licenseKey = $licenseKey;
            $this->licenseServerUrl = $licenseServerUrl;

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to save license config: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            return false;
        }
    }
}
