<?php

declare(strict_types=1);

namespace OCA\MetaVox\Settings;

use OCA\MetaVox\Service\PermissionService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\IGroupManager;
use OCP\Settings\IIconSection;

class PersonalSection implements IIconSection {

    private IL10N $l;
    private IURLGenerator $urlGenerator;
    private PermissionService $permissionService;
    private IUserSession $userSession;
    private IGroupManager $groupManager;

    public function __construct(
        IL10N $l, 
        IURLGenerator $urlGenerator,
        PermissionService $permissionService,
        IUserSession $userSession,
        IGroupManager $groupManager
    ) {
        $this->l = $l;
        $this->urlGenerator = $urlGenerator;
        $this->permissionService = $permissionService;
        $this->userSession = $userSession;
        $this->groupManager = $groupManager;
    }

    /**
     * Check if this section should be visible
     * Only show if user has permission or is admin
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

    public function getID(): string {
        // Don't show section if user has no permission
        if (!$this->shouldShow()) {
            return 'metavox-personal-hidden';
        }
        
        return 'metavox-personal';
    }

    public function getName(): string {
        return $this->l->t('MetaVox');
    }

    public function getPriority(): int {
        return 80;
    }

    public function getIcon(): string {
        return $this->urlGenerator->imagePath('metavox', 'app.svg');
    }
}