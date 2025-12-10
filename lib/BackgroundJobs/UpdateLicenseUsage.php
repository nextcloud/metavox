<?php
declare(strict_types=1);

namespace OCA\MetaVox\BackgroundJobs;

use OCA\MetaVox\Service\LicenseService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Background job to update license usage statistics
 * Runs every hour to report current usage to license server
 */
class UpdateLicenseUsage extends TimedJob {
    private LicenseService $licenseService;
    private IConfig $config;
    private IUserManager $userManager;
    private LoggerInterface $logger;

    public function __construct(
        ITimeFactory $time,
        LicenseService $licenseService,
        IConfig $config,
        IUserManager $userManager,
        LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->licenseService = $licenseService;
        $this->config = $config;
        $this->userManager = $userManager;
        $this->logger = $logger;

        // Run every hour
        $this->setInterval(3600);
    }

    protected function run($argument): void {
        // Only run if license is configured
        if (!$this->licenseService->isConfigured()) {
            return;
        }

        try {
            // Count team folders
            $teamFoldersCount = $this->countTeamFolders();

            // Count users - countUsers() returns an array, we need the total
            $usersCountArray = $this->userManager->countUsers();
            $usersCount = is_array($usersCountArray) ? array_sum($usersCountArray) : (int)$usersCountArray;

            // Update usage on license server
            $this->licenseService->updateUsage($teamFoldersCount, $usersCount);

            $this->logger->info('License usage updated', [
                'teamFolders' => $teamFoldersCount,
                'users' => $usersCount
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update license usage: ' . $e->getMessage(), [
                'exception' => $e
            ]);
        }
    }

    private function countTeamFolders(): int {
        try {
            // Check if groupfolders app is installed
            $groupfoldersEnabled = \OC::$server->getAppManager()->isEnabledForUser('groupfolders');

            if (!$groupfoldersEnabled) {
                return 0;
            }

            // Count only groupfolders that have MetaVox fields assigned
            // This is what the license limits - not total folders, but folders using MetaVox
            $db = \OC::$server->getDatabaseConnection();
            $qb = $db->getQueryBuilder();

            $qb->selectAlias($qb->createFunction('COUNT(DISTINCT groupfolder_id)'), 'count')
                ->from('metavox_gf_assigns');

            $result = $qb->execute();
            $row = $result->fetch();
            $result->closeCursor();

            return (int)($row['count'] ?? 0);
        } catch (\Exception $e) {
            $this->logger->warning('Could not count team folders with fields: ' . $e->getMessage());
            return 0;
        }
    }
}
