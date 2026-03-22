<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Service\LockService;
use OCA\MetaVox\Service\PushService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class LockController extends Controller {

    private LockService $lockService;
    private PushService $pushService;
    private IUserSession $userSession;

    public function __construct(
        string $appName,
        IRequest $request,
        LockService $lockService,
        PushService $pushService,
        IUserSession $userSession
    ) {
        parent::__construct($appName, $request);
        $this->lockService = $lockService;
        $this->pushService = $pushService;
        $this->userSession = $userSession;
    }

    /**
     * Lock a cell for editing.
     * @NoAdminRequired
     */
    public function lockCell(int $groupfolderId, int $fileId): JSONResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }
        $fieldName = $this->request->getParam('field_name');
        if (empty($fieldName)) {
            return new JSONResponse(['error' => 'field_name required'], 400);
        }
        $userId = $user->getUID();
        $acquired = $this->lockService->lock($groupfolderId, $fileId, $fieldName, $userId);
        if (!$acquired) {
            $lockedBy = $this->lockService->getLock($groupfolderId, $fileId, $fieldName);
            return new JSONResponse(['locked' => true, 'lockedBy' => $lockedBy], 409);
        }
        $this->pushService->cellLocked($groupfolderId, $fileId, $fieldName, $userId);
        return new JSONResponse(['locked' => false]);
    }

    /**
     * Unlock a cell after editing.
     * @NoAdminRequired
     */
    public function unlockCell(int $groupfolderId, int $fileId): JSONResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }
        $fieldName = $this->request->getParam('field_name');
        if (empty($fieldName)) {
            return new JSONResponse(['error' => 'field_name required'], 400);
        }
        $this->lockService->unlock($groupfolderId, $fileId, $fieldName, $user->getUID());
        $this->pushService->cellUnlocked($groupfolderId, $fileId, $fieldName);
        return new JSONResponse(['success' => true]);
    }
}
