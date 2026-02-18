<?php

declare(strict_types=1);

namespace OCA\MetaVox\AppInfo;

use OCA\MetaVox\Listener\CacheCleanupListener;
use OCA\MetaVox\Listener\FileCopyListener;
use OCA\MetaVox\Listener\RegisterFlowChecksListener;
use OCA\MetaVox\Search\MetadataSearchProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Cache\CacheEntryRemovedEvent;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\WorkflowEngine\Events\RegisterChecksEvent;

class Application extends App implements IBootstrap {
    public const APP_ID = 'metavox';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // Register search provider
        $context->registerSearchProvider(MetadataSearchProvider::class);

        // Register event listeners for file copy
        $context->registerEventListener(NodeCopiedEvent::class, FileCopyListener::class);
        $context->registerEventListener(NodeCreatedEvent::class, FileCopyListener::class);

        // Clean up metadata when files are removed from filecache (trash emptied, etc.)
        $context->registerEventListener(CacheEntryRemovedEvent::class, CacheCleanupListener::class);

        // Register Flow (Workflow) checks for metadata-based conditions
        $context->registerEventListener(RegisterChecksEvent::class, RegisterFlowChecksListener::class);
    }

    public function boot(IBootContext $context): void {
        // Skip request-dependent logic in CLI mode (occ commands, cron, etc.)
        if (\OC::$CLI) {
            return;
        }

        // Load icon CSS globally to fix sidebar icon scaling
        \OCP\Util::addStyle('metavox', 'icon');

        // Load Files app integration only when needed
        $request = \OC::$server->getRequest();
        $requestUri = $request->getRequestUri();

        // Also check pathInfo for reverse proxy setups with subpaths
        // getPathInfo() returns the Nextcloud-specific path without proxy prefix
        $pathInfo = $request->getPathInfo() ?? '';

        // Check URL patterns for Files app using PHP 8 str_contains
        // Check both requestUri (direct access) and pathInfo (behind reverse proxy)
        $isFilesApp = (
            str_contains($requestUri, '/apps/files') ||
            str_contains($requestUri, '/index.php/apps/files') ||
            str_contains($pathInfo, '/apps/files') ||
            (($_GET['app'] ?? '') === 'files') ||
            (($_POST['app'] ?? '') === 'files')
        );

        // Only load scripts when in Files app
        if ($isFilesApp) {
            \OCP\Util::addScript('metavox', 'filesplugin');
            \OCP\Util::addStyle('metavox', 'files');
        }
    }
}