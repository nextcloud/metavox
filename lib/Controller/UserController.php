<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;

class UserController extends Controller {

    private PermissionService $permissionService;
    private IUserSession $userSession;

    public function __construct(
        string $appName,
        IRequest $request,
        PermissionService $permissionService,
        IUserSession $userSession
    ) {
        parent::__construct($appName, $request);
        $this->permissionService = $permissionService;
        $this->userSession = $userSession;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): TemplateResponse {
        $user = $this->userSession->getUser();
        
        if (!$user) {
            return new TemplateResponse('metavox', 'error', [
                'message' => 'Not logged in'
            ]);
        }

        // Get user's permissions
        $permissions = $this->permissionService->getUserPermissions($user->getUID());

        // Load JavaScript and CSS
        \OCP\Util::addScript('metavox', 'user');
        \OCP\Util::addStyle('metavox', 'user');

        return new TemplateResponse('metavox', 'user', [
            'userId' => $user->getUID(),
            'displayName' => $user->getDisplayName(),
            'permissions' => $permissions,
        ]);
    }
}