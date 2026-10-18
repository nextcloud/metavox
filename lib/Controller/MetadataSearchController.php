<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Access\Access;
use OCA\MetaVox\Access\RequireAccess;
use OCA\MetaVox\Exception\RequestException;
use OCA\MetaVox\L10n\Translatable;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\SavedSearchService;
use OCA\MetaVox\Service\SearchSettings;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/** The caller's saved metadata searches, the folders of the search view in Files. */
class MetadataSearchController extends BaseController {

    public function __construct(
        string $appName,
        IRequest $request,
        IUserSession $userSession,
        FieldService $fieldService,
        private readonly SavedSearchService $searches,
        private readonly SearchSettings $settings,
    ) {
        parent::__construct($appName, $request, $userSession, $fieldService);
    }

    #[NoAdminRequired]
    #[RequireAccess(Access::LoggedIn, reason: "only the caller's own saved searches")]
    public function index(): JSONResponse {
        return new JSONResponse(['searches' => $this->searches->all($this->requireUser()->getUID())]);
    }

    #[NoAdminRequired]
    #[RequireAccess(Access::LoggedIn, reason: "saves in the caller's own preferences; what a search finds is checked when it opens")]
    public function create(): JSONResponse {
        // Listing and removing keep working, so nobody is left with searches they can't clean up.
        if (!$this->settings->foldersOn()) {
            // TRANSLATORS Error when someone saves a search as a folder while the administration has turned that off
            throw RequestException::turnedOff(Translatable::t('Saving searches as folders is turned off'));
        }
        $search = $this->searches->add(
            $this->requireUser()->getUID(),
            $this->request->getParam('name'),
            $this->request->getParam('field'),
            $this->request->getParam('value'),
            $this->request->getParam('groupfolderId'),
        );
        return new JSONResponse($search, Http::STATUS_CREATED);
    }

    #[NoAdminRequired]
    #[RequireAccess(Access::LoggedIn, reason: "removes from the caller's own preferences only")]
    public function destroy(int $id): JSONResponse {
        if (!$this->searches->remove($this->requireUser()->getUID(), $id)) {
            // TRANSLATORS Error when a saved search was already removed
            throw RequestException::notFound(Translatable::t('Saved search not found'));
        }
        return new JSONResponse([]);
    }
}
