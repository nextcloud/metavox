<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller {

    public function __construct(string $appName, IRequest $request) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): TemplateResponse {
        // Debug: Controleer of scripts geladen worden
        \OCP\Util::writeLog('testermeta', 'Loading user interface scripts...', \OCP\ILogger::INFO);
        
        // Expliciet laden van user interface scripts
        Util::addScript('testermeta', 'user-interface');
        Util::addStyle('testermeta', 'user-interface');
        
        return new TemplateResponse('testermeta', 'main');
    }
}