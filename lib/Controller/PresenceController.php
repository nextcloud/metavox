<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Access\Access;
use OCA\MetaVox\Access\RequireAccess;
use OCA\MetaVox\Exception\RequestException;
use OCA\MetaVox\Service\PresenceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class PresenceController extends Controller {

    use ReadsParams;

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
    #[RequireAccess(Access::LoggedIn, reason: 'removes only the caller\'s own presence')]
    public function leave(): JSONResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw RequestException::notLoggedIn();
        }
        $gfId = $this->intParam('gf_id', min: 1);
        if ($gfId !== null) {
            $this->presenceService->remove($gfId, $user->getUID());
        }
        return new JSONResponse(['success' => true]);
    }
}
