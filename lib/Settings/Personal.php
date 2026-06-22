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

        // Show the page if the user can manage fields on ANY team folder
        // (folder-specific grant counts, not just a global one).
        return $this->permissionService->hasManageFieldsOnAnyFolder($userId);
    }

    public function getForm() {
        // Don't show form if user doesn't have permission
        if (!$this->shouldShow()) {
            // Return null to hide this settings page
            return null;
        }

        $user = $this->userSession->getUser();

        // Has manage_fields somewhere (folder-specific or global) — drives the UI.
        $hasPermission = $this->permissionService->hasManageFieldsOnAnyFolder($user->getUID());

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