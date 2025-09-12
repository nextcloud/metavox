<?php

declare(strict_types=1);

namespace OCA\MetaVox\BackgroundJob;

use OCA\MetaVox\Service\RetentionService;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use OCP\IUserSession;
use OCP\IUserManager;
use OCP\IGroupManager;

/**
 * 🔄 Retention Background Job - COMPLETELY FIXED VERSION
 * Runs every minute to check and process expired files according to retention policies
 * 
 * Simple job that calls RetentionService->processRetentionActions() automatically
 * 
 * Features:
 * ✅ Automatic minute execution (for debugging - change to 3600 for production)
 * ✅ Calls existing RetentionService
 * ✅ Comprehensive logging (FIXED: LoggerInterface)
 * ✅ Error handling and recovery
 * ✅ Admin context for file operations
 * ✅ No constructor dependencies (FIXED)
 */
class RetentionBackgroundJob extends TimedJob {

    public function __construct(?ITimeFactory $timeFactory = null) {
        parent::__construct($timeFactory ?? \OC::$server->get(ITimeFactory::class));
        
        // ⏰ DEBUGGING: Run every minute (60 seconds) instead of every hour
        // Change back to 3600 (1 hour) for production!
        $this->setInterval(60);
    }

    /**
     * 🎯 Main execution method - called by Nextcloud's cron system
     */
    protected function run($argument): void {
        $logger = null;
        
        try {
            $startTime = time();
            $logger = $this->getLogger();
            $logger->info('MetaVox Retention: Starting automatic retention background job');
            
            // 🎭 Setup admin context for file operations
            $this->setupAdminContext();
            
            // 🔄 Process expired files using RetentionService
            $retentionService = $this->getRetentionService();
            $result = $retentionService->processRetentionActions(false); // NOT dry run
            
            // 📊 Log summary
            $processingTime = time() - $startTime;
            $this->logJobSummary($result, $processingTime);
            
            // 🚨 Handle notifications if there were errors
            if (($result['total_errors'] ?? 0) > 0) {
                $this->handleErrors($result);
            }
            
            $logger->info('MetaVox Retention: Background job completed successfully', [
                'processed' => $result['total_processed'] ?? 0,
                'errors' => $result['total_errors'] ?? 0,
                'duration' => $processingTime . 's'
            ]);
            
        } catch (\Exception $e) {
            if ($logger === null) {
                $logger = $this->getLogger();
            }
            
            $logger->error('MetaVox Retention: Critical error in background job: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // 🚨 Send critical error notification
            $this->notifyAdminsOfCriticalError($e);
        } finally {
            // 🧹 Clean up admin context
            try {
                $this->cleanupAdminContext();
            } catch (\Exception $cleanupError) {
                if ($logger !== null) {
                    $logger->warning('MetaVox Retention: Error during cleanup: ' . $cleanupError->getMessage());
                }
            }
        }
    }

    /**
     * 🔧 Get dependencies from DI container (lazy loading)
     */
    private function getRetentionService(): RetentionService {
        return \OC::$server->get(RetentionService::class);
    }

    private function getLogger(): LoggerInterface {
        return \OC::$server->get(LoggerInterface::class);
    }

    private function getUserSession(): IUserSession {
        return \OC::$server->getUserSession();
    }

    private function getUserManager(): IUserManager {
        return \OC::$server->getUserManager();
    }

    private function getGroupManager(): IGroupManager {
        return \OC::$server->getGroupManager();
    }

    /**
     * 🎭 Setup admin context for file operations
     * Background jobs need proper user context to access files
     */
    private function setupAdminContext(): void {
        try {
            // Find first admin user for file operations
            $adminUser = $this->findAdminUser();
            
            if ($adminUser) {
                // Set user session for RetentionService
                $this->getUserSession()->setUser($adminUser);
                $this->getLogger()->debug('MetaVox Retention: Admin context set for user: ' . $adminUser->getUID());
            } else {
                $this->getLogger()->warning('MetaVox Retention: No admin user found for background job context');
            }
            
        } catch (\Exception $e) {
            $this->getLogger()->error('MetaVox Retention: Error setting up admin context: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 🧹 Clean up admin context after processing
     */
    private function cleanupAdminContext(): void {
        try {
            // Clear user session 
            $this->getUserSession()->setUser(null);
            $this->getLogger()->debug('MetaVox Retention: Admin context cleaned up');
        } catch (\Exception $e) {
            $this->getLogger()->warning('MetaVox Retention: Error cleaning up admin context: ' . $e->getMessage());
        }
    }

    /**
     * 🔍 Find an admin user for file operations (FIXED: Use IGroupManager)
     */
    private function findAdminUser(): ?\OCP\IUser {
        try {
            // Get all users and find first admin
            $userManager = $this->getUserManager();
            $groupManager = $this->getGroupManager();
            $userBackends = $userManager->getBackends();
            
            foreach ($userBackends as $backend) {
                $userIds = $backend->getUsers('', 10); // Get first 10 users
                
                foreach ($userIds as $userId) {
                    $user = $userManager->get($userId);
                    
                    // FIXED: Use GroupManager to check if user is admin
                    if ($user && $groupManager->isAdmin($userId)) {
                        $this->getLogger()->debug('MetaVox Retention: Found admin user for background job: ' . $userId);
                        return $user;
                    }
                }
            }
            
            $this->getLogger()->warning('MetaVox Retention: No admin users found for background job');
            return null;
            
        } catch (\Exception $e) {
            $this->getLogger()->error('MetaVox Retention: Error finding admin user: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 📊 Log comprehensive job summary
     */
    private function logJobSummary(array $result, int $processingTime): void {
        $summary = [
            'job_type' => 'retention_background_processing',
            'execution_time' => $processingTime,
            'total_processed' => $result['total_processed'] ?? 0,
            'total_errors' => $result['total_errors'] ?? 0,
            'success_rate' => $this->calculateSuccessRate($result),
            'timestamp' => date('Y-m-d H:i:s'),
            'memory_usage' => $this->formatBytes(memory_get_peak_usage(true))
        ];
        
        $this->getLogger()->info('MetaVox Retention: Background job summary', $summary);
        
        // Log individual processed files (if any)
        if (!empty($result['processed'])) {
            foreach ($result['processed'] as $processed) {
                $this->getLogger()->debug('MetaVox Retention: Processed file', [
                    'file_id' => $processed['file_id'] ?? 'unknown',
                    'action' => $processed['action'] ?? 'unknown',
                    'success' => $processed['success'] ?? false,
                    'message' => $processed['message'] ?? 'No message'
                ]);
            }
        }
    }

    /**
     * 🚨 Handle processing errors
     */
    private function handleErrors(array $result): void {
        $errors = $result['errors'] ?? [];
        
        foreach ($errors as $error) {
            $this->getLogger()->warning('MetaVox Retention: Processing error in background job', [
                'file_id' => $error['file_id'] ?? 'unknown',
                'error' => $error['error'] ?? 'Unknown error',
                'retention_data' => $error['retention'] ?? []
            ]);
        }
        
        // If too many errors, notify admins
        if (count($errors) > 5) {
            $this->getLogger()->error('MetaVox Retention: High error rate in background job', [
                'error_count' => count($errors),
                'total_processed' => $result['total_processed'] ?? 0,
                'error_rate' => count($errors) / max(1, ($result['total_processed'] ?? 0) + count($errors))
            ]);
        }
    }

    /**
     * 🚨 Notify admins of critical errors
     */
    private function notifyAdminsOfCriticalError(\Exception $e): void {
        try {
            // Create error summary for admins
            $errorSummary = [
                'job' => 'MetaVox Retention Background Job',
                'error' => $e->getMessage(),
                'time' => date('Y-m-d H:i:s'),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ];
            
            $this->getLogger()->critical('MetaVox Retention: CRITICAL - Background job failed completely', $errorSummary);
            
            // You could extend this to send actual notifications to admins
            // For example, using Nextcloud's notification system
            
        } catch (\Exception $notificationError) {
            // Last resort - log to error log
            error_log('MetaVox Retention: Failed to send critical error notification: ' . $notificationError->getMessage());
        }
    }

    /**
     * 📈 Calculate success rate percentage
     */
    private function calculateSuccessRate(array $result): float {
        $processed = $result['total_processed'] ?? 0;
        $errors = $result['total_errors'] ?? 0;
        $total = $processed + $errors;
        
        if ($total === 0) {
            return 100.0; // No items to process = 100% success
        }
        
        return round(($processed / $total) * 100, 2);
    }

    /**
     * 💾 Format bytes for human-readable memory usage
     */
    private function formatBytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}