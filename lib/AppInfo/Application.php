<?php

declare(strict_types=1);

namespace OCA\MetaVox\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
    public const APP_ID = 'metavox';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // Register services, event listeners, etc.
    }

    public function boot(IBootContext $context): void {
        $context->injectFn(function() {
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
                \OCP\Util::addScript('metavox', 'files-plugin1');
                \OCP\Util::addStyle('metavox', 'files');
            }
        });
    }
}