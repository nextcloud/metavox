<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Access\Access;
use OCA\MetaVox\Access\RequireAccess;
use OCA\MetaVox\Exception\RequestException;
use OCA\MetaVox\L10n\Translatable;
use OCA\MetaVox\Service\LockService;
use OCA\MetaVox\Service\PushService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class LockController extends Controller {

    use ReadsParams;

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
     */
    #[NoAdminRequired]
    #[RequireAccess(Access::CanUpdate, folder: 'groupfolderId', file: 'fileId')]
    public function lockCell(int $groupfolderId, int $fileId): JSONResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw RequestException::notLoggedIn();
        }
        $fieldName = $this->textParam('field_name', required: true);
        $userId = $user->getUID();
        $acquired = $this->lockService->lock($groupfolderId, $fileId, $fieldName, $userId);
        if (!$acquired) {
            // TRANSLATORS Error when someone opens a grid cell that another person is editing right now
            throw RequestException::conflict(Translatable::t('Someone else is editing this value'), [
                'locked' => true,
                'lockedBy' => $this->lockService->getLock($groupfolderId, $fileId, $fieldName),
            ]);
        }
        $this->pushService->cellLocked($groupfolderId, $fileId, $fieldName, $userId);
        return new JSONResponse(['locked' => false]);
    }

    /**
     * Unlock a cell after editing.
     */
    #[NoAdminRequired]
    #[RequireAccess(Access::CanRead, folder: 'groupfolderId', file: 'fileId')]
    public function unlockCell(int $groupfolderId, int $fileId): JSONResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw RequestException::notLoggedIn();
        }
        $fieldName = $this->textParam('field_name', required: true);
        // Announce only when the cell is free now, never over someone else's lock.
        if ($this->lockService->unlock($groupfolderId, $fileId, $fieldName, $user->getUID())) {
            $this->pushService->cellUnlocked($groupfolderId, $fileId, $fieldName);
        }
        return new JSONResponse(['success' => true]);
    }
}
