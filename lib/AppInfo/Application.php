<?php

declare(strict_types=1);

namespace OCA\MetaVox\AppInfo;

use OCA\MetaVox\BackgroundJob\RetentionBackgroundJob;
use OCA\MetaVox\Service\RetentionService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ILogger;
use OCP\IUserSession;
use OCP\IUserManager;
use OCP\BackgroundJob\IJobList;

/**
 * 🎯 MetaVox Application - WITH Background Job Registration
 */
class Application extends App implements IBootstrap {

    public const APP_ID = 'metavox';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        // Existing registrations...
        
        // 🔄 Register RetentionService
        $context->registerService(RetentionService::class, function($c) {
            return new RetentionService(
                $c->get(\OCP\IDBConnection::class),
                $c->get(IUserSession::class),
                $c->get(\OCP\Files\IRootFolder::class)
            );
        });

        // 🆕 Register Retention Background Job
        $context->registerService(RetentionBackgroundJob::class, function($c) {
            return new RetentionBackgroundJob(
                $c->get(ITimeFactory::class),
                $c->get(RetentionService::class),
                $c->get(ILogger::class),
                $c->get(IUserSession::class),
                $c->get(IUserManager::class)
            );
        });
    }

    public function boot(IBootContext $context): void {
        $container = $context->getAppContainer();
        
        // 📁 MIGRATED FROM app.php: Load files plugin globally
        \OCP\Util::addScript('metavox', 'files-plugin1');
        
        // 🎨 MIGRATED FROM app.php: Add CSS for files plugin
        \OCP\Util::addStyle('metavox', 'files');
        
        // 🔄 Register the background job with Nextcloud's job system (FIXED - NO CONSTRUCTOR DEPENDENCIES)
        try {
            $jobList = $container->get(IJobList::class);
            
            // Check if background job class exists before registering
            if (class_exists(RetentionBackgroundJob::class)) {
                // Add the job if it's not already registered
                if (!$jobList->has(RetentionBackgroundJob::class, null)) {
                    $jobList->add(RetentionBackgroundJob::class);
                    error_log('MetaVox: Retention background job registered successfully (FIXED CONSTRUCTOR)');
                } else {
                    error_log('MetaVox: Retention background job already registered');
                }
            } else {
                error_log('MetaVox: RetentionBackgroundJob class not found - skipping registration');
                error_log('MetaVox: Make sure the file exists at: lib/BackgroundJob/RetentionBackgroundJob.php');
            }
        } catch (\Exception $e) {
            error_log('MetaVox: Error registering background job: ' . $e->getMessage());
        }
    }
}