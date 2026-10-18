<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Exception\RequestException;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\FileAccessService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

abstract class BaseOCSController extends OCSController {

    use ReadsParams;

    protected IUserSession $userSession;
    protected FieldService $fieldService;
    protected FileAccessService $fileAccess;

    public function __construct(
        string $appName,
        IRequest $request,
        IUserSession $userSession,
        FieldService $fieldService,
        FileAccessService $fileAccess
    ) {
        parent::__construct($appName, $request);
        $this->userSession = $userSession;
        $this->fieldService = $fieldService;
        $this->fileAccess = $fileAccess;
    }

    /** The logged-in user; the access rule already made sure there is one. */
    protected function requireUser(): IUser {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw RequestException::notLoggedIn();
        }
        return $user;
    }

    /**
     * Allowed items get the file's real team folder; refused ones come back as
     * batch results.
     *
     * @param array<int, mixed> $items
     * @return array{allowed: list<array<string, mixed>>, denied: list<array{file_id: ?int, success: false, error: string}>}
     */
    protected function authorizeBatchWrites(array $items, string $userId): array {
        return $this->fileAccess->authorizeBatch($userId, $items, true);
    }
}
