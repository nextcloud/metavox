<?php

declare(strict_types=1);

namespace OCA\MetaVox\Settings;

use OCA\MetaVox\Service\PermissionService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\IUserSession;
use OCP\IGroupManager;

class Personal implements ISettings {

    private PermissionService $permissionService;
    private IUserSession $userSession;
    private IGroupManager $groupManager;

    public function __construct(
        PermissionService $permissionService,
        IUserSession $userSession,
        IGroupManager $groupManager
    ) {
        $this->permissionService = $permissionService;
        $this->userSession = $userSession;
        $this->groupManager = $groupManager;
    }

    /**
     * Check if user should see this settings page
     * Only show if user has manage_fields permission or is admin
     */
    private function shouldShow(): bool {
        $user = $this->userSession->getUser();
        
        if (!$user) {
            return false;
        }

        $userId = $user->getUID();

        // Admins always see it
        if ($this->groupManager->isAdmin($userId)) {
            return true;
        }

        // Check if user has manage_fields permission
        return $this->permissionService->hasPermission(
            $userId,
            PermissionService::PERM_MANAGE_FIELDS
        );
    }

    public function getForm() {
        // Don't show form if user doesn't have permission
        if (!$this->shouldShow()) {
            // Return null to hide this settings page
            return null;
        }

        $user = $this->userSession->getUser();

        // Check if user has manage_fields permission
        $hasPermission = $this->permissionService->hasPermission(
            $user->getUID(),
            PermissionService::PERM_MANAGE_FIELDS
        );

        // Load JavaScript and CSS
        \OCP\Util::addScript('metavox', 'user');
        \OCP\Util::addStyle('metavox', 'user');

        return new TemplateResponse('metavox', 'personal', [
            'userId' => $user->getUID(),
            'displayName' => $user->getDisplayName(),
            'hasPermission' => $hasPermission,
        ]);
    }

    public function getSection() {
        return 'metavox-personal';
    }

    public function getPriority() {
        return 50;
    }
}