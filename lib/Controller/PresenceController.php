<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Service\PresenceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class PresenceController extends Controller {

    private PresenceService $presenceService;
    private IUserSession $userSession;

    public function __construct(
        string $appName,
        IRequest $request,
        PresenceService $presenceService,
        IUserSession $userSession
    ) {
        parent::__construct($appName, $request);
        $this->presenceService = $presenceService;
        $this->userSession = $userSession;
    }

    /**
     * Remove presence when user leaves (tab close via sendBeacon).
     */
    #[NoAdminRequired]
    public function leave(): JSONResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }
        $gfId = $this->request->getParam('gf_id');
        if ($gfId) {
            $this->presenceService->remove((int)$gfId, $user->getUID());
        }
        return new JSONResponse(['success' => true]);
    }
}
