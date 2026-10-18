<?php

declare(strict_types=1);

namespace OCA\MetaVox\Settings;

use OCA\MetaVox\Service\TeamFolderAccessService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\IUserSession;

class Personal implements ISettings {

    public function __construct(
        private readonly TeamFolderAccessService $teamFolders,
        private readonly IUserSession $userSession,
    ) {
    }

    /** The same rule as the section: admins and managers of at least one team folder. */
    private function shouldShow(): bool {
        $user = $this->userSession->getUser();
        return $user !== null && $this->teamFolders->managesAnyFolder($user->getUID());
    }

    public function getForm() {
        // Don't show form if user doesn't have permission
        if (!$this->shouldShow()) {
            // Return null to hide this settings page
            return null;
        }

        $user = $this->userSession->getUser();

        // Only shown to managers, so the manager part applies.
        $hasPermission = true;

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