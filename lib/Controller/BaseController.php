<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Exception\RequestException;
use OCA\MetaVox\Service\FieldService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

abstract class BaseController extends Controller {

    use ReadsParams;

    protected IUserSession $userSession;
    protected FieldService $fieldService;

    public function __construct(
        string $appName,
        IRequest $request,
        IUserSession $userSession,
        FieldService $fieldService
    ) {
        parent::__construct($appName, $request);
        $this->userSession = $userSession;
        $this->fieldService = $fieldService;
    }

    /** The logged-in user; the access rule already made sure there is one. */
    protected function requireUser(): IUser {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw RequestException::notLoggedIn();
        }
        return $user;
    }
}
