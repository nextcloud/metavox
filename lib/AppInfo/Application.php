<?php

namespace OCA\MetaVox\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
    public const APP_ID = 'metavox';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
        
        // Log dat de app geïnitialiseerd wordt
        error_log("=== META VOX APP CONSTRUCTOR CALLED ===");
    }
public function register(IRegistrationContext $context): void {
    error_log("=== META VOX REGISTER METHOD CALLED ===");
    
    // Register search provider
    $context->registerSearchProvider(\OCA\MetaVox\Search\MetadataSearchProvider::class);
    
    // Register background job for search index
    $context->registerService('UpdateSearchIndexJob', function() {
        return new \OCA\MetaVox\BackgroundJobs\UpdateSearchIndex();
    });

        // Register background job for CleanupDeletedMetadata
    $context->registerService('CleanupDeletedMetadata', function() {
        return new \OCA\MetaVox\BackgroundJobs\CleanupDeletedMetadata();
    });

    // Register background job for License Usage Update
    $context->registerService('UpdateLicenseUsage', function() {
        $licenseService = new \OCA\MetaVox\Service\LicenseService(
            \OC::$server->getConfig(),
            \OC::$server->get(\OCP\Http\Client\IClientService::class),
            \OC::$server->getDatabaseConnection(),
            \OC::$server->get(\Psr\Log\LoggerInterface::class)
        );

        return new \OCA\MetaVox\BackgroundJobs\UpdateLicenseUsage(
            \OC::$server->get(\OCP\AppFramework\Utility\ITimeFactory::class),
            $licenseService,
            \OC::$server->getConfig(),
            \OC::$server->getUserManager(),
            \OC::$server->get(\Psr\Log\LoggerInterface::class)
        );
    });

    // Registreer events voor copy
    $context->registerEventListener(
        \OCP\Files\Events\Node\NodeCopiedEvent::class,
        \OCA\MetaVox\Listener\FileCopyListener::class
    );
    
    $context->registerEventListener(
        \OCP\Files\Events\Node\NodeCreatedEvent::class,
        \OCA\MetaVox\Listener\FileCopyListener::class
    );
    
    // NIEUW: Registreer delete listener
    $context->registerEventListener(
        \OCP\Files\Events\Node\NodeDeletedEvent::class,
        \OCA\MetaVox\Listener\FileDeleteListener::class
    );
    
    error_log("=== META VOX EVENTS REGISTERED ===");
}

    public function boot(IBootContext $context): void {
        error_log("=== META VOX BOOT METHOD CALLED ===");

        // Load icon CSS globally to fix sidebar icon scaling
        \OCP\Util::addStyle('metavox', 'icon');

        // Register background jobs with Nextcloud's job list
        $this->registerBackgroundJobs();

        // Load Files app integration only when needed
        $request = \OC::$server->getRequest();
        $requestUri = $request->getRequestUri();

        // Check URL patterns for Files app
        $isFilesApp = (
            strpos($requestUri, '/apps/files') !== false ||
            strpos($requestUri, '/index.php/apps/files') !== false ||
            (isset($_GET['app']) && $_GET['app'] === 'files') ||
            (isset($_POST['app']) && $_POST['app'] === 'files')
        );

        // Only load scripts when in Files app
        if ($isFilesApp) {
            // Load Vue-based files plugin
            \OCP\Util::addScript('metavox', 'filesplugin');
            // Load filter plugin
            \OCP\Util::addScript('metavox', 'files-filter');
            \OCP\Util::addStyle('metavox', 'files');
        }
    }

    private function registerBackgroundJobs(): void {
        $jobList = \OC::$server->getJobList();

        // Register UpdateLicenseUsage job if not already registered
        if (!$jobList->has(\OCA\MetaVox\BackgroundJobs\UpdateLicenseUsage::class, null)) {
            $jobList->add(\OCA\MetaVox\BackgroundJobs\UpdateLicenseUsage::class);
            error_log("=== META VOX: Registered UpdateLicenseUsage background job ===");
        }

        // Register UpdateSearchIndex job if not already registered
        if (!$jobList->has(\OCA\MetaVox\BackgroundJobs\UpdateSearchIndex::class, null)) {
            $jobList->add(\OCA\MetaVox\BackgroundJobs\UpdateSearchIndex::class);
            error_log("=== META VOX: Registered UpdateSearchIndex background job ===");
        }

        // Register CleanupDeletedMetadata job if not already registered
        if (!$jobList->has(\OCA\MetaVox\BackgroundJobs\CleanupDeletedMetadata::class, null)) {
            $jobList->add(\OCA\MetaVox\BackgroundJobs\CleanupDeletedMetadata::class);
            error_log("=== META VOX: Registered CleanupDeletedMetadata background job ===");
        }
    }
}