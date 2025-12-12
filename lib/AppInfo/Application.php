<?php

declare(strict_types=1);

namespace OCA\MetaVox\AppInfo;

use OCA\MetaVox\BackgroundJobs\CleanupDeletedMetadata;
use OCA\MetaVox\BackgroundJobs\UpdateSearchIndex;
use OCA\MetaVox\Listener\FileCopyListener;
use OCA\MetaVox\Listener\FileDeleteListener;
use OCA\MetaVox\Search\MetadataSearchProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;

class Application extends App implements IBootstrap {
    public const APP_ID = 'metavox';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // Register search provider
        $context->registerSearchProvider(MetadataSearchProvider::class);

        // Register background jobs
        $context->registerService('UpdateSearchIndexJob', static fn() => new UpdateSearchIndex());
        $context->registerService('CleanupDeletedMetadata', static fn() => new CleanupDeletedMetadata());

        // Register event listeners for file copy
        $context->registerEventListener(NodeCopiedEvent::class, FileCopyListener::class);
        $context->registerEventListener(NodeCreatedEvent::class, FileCopyListener::class);

        // Register delete listener
        $context->registerEventListener(NodeDeletedEvent::class, FileDeleteListener::class);
    }

    public function boot(IBootContext $context): void {
        // Load icon CSS globally to fix sidebar icon scaling
        \OCP\Util::addStyle('metavox', 'icon');

        // Register background jobs with Nextcloud's job list
        $this->registerBackgroundJobs();

        // Load Files app integration only when needed
        $request = \OC::$server->getRequest();
        $requestUri = $request->getRequestUri();

        // Check URL patterns for Files app using PHP 8 str_contains
        $isFilesApp = (
            str_contains($requestUri, '/apps/files') ||
            str_contains($requestUri, '/index.php/apps/files') ||
            (($_GET['app'] ?? '') === 'files') ||
            (($_POST['app'] ?? '') === 'files')
        );

        // Only load scripts when in Files app
        if ($isFilesApp) {
            \OCP\Util::addScript('metavox', 'filesplugin');
            \OCP\Util::addStyle('metavox', 'files');
        }
    }

    private function registerBackgroundJobs(): void {
        $jobList = \OC::$server->getJobList();

        // Register background jobs if not already registered
        if (!$jobList->has(UpdateSearchIndex::class, null)) {
            $jobList->add(UpdateSearchIndex::class);
        }

        if (!$jobList->has(CleanupDeletedMetadata::class, null)) {
            $jobList->add(CleanupDeletedMetadata::class);
        }
    }
}