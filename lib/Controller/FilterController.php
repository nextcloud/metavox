<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Service\FilterService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class FilterController extends Controller {

    private FilterService $filterService;
    private IUserSession $userSession;

    public function __construct(
        string $appName,
        IRequest $request,
        FilterService $filterService,
        IUserSession $userSession
    ) {
        parent::__construct($appName, $request);
        $this->filterService = $filterService;
        $this->userSession = $userSession;
    }

    /**
     * Filter files in a groupfolder by metadata criteria
     *
     * @NoAdminRequired
     * @param int $groupfolderId
     * @return JSONResponse
     */
    public function filterFiles(int $groupfolderId): JSONResponse {
        try {
            $filters = $this->request->getParam('filters', []);
            $path = $this->request->getParam('path', '/');

            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'User not authenticated'], 401);
            }

            $userId = $user->getUID();

            // filterFilesByMetadata now returns ['files' => [...], 'debug' => [...]]
            $result = $this->filterService->filterFilesByMetadata($groupfolderId, $filters, $userId, $path);

            return new JSONResponse([
                'success' => true,
                'files' => $result['files'],
                'count' => count($result['files']),
                'debug' => $result['debug'], // Include debug info for browser console
            ]);
        } catch (\Exception $e) {
            error_log('MetaVox FilterController error: ' . $e->getMessage());
            error_log('MetaVox FilterController trace: ' . $e->getTraceAsString());
            return new JSONResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 500);
        }
    }

    /**
     * Get available filter fields for a groupfolder
     *
     * @NoAdminRequired
     * @param int $groupfolderId
     * @return JSONResponse
     */
    public function getFilterFields(int $groupfolderId): JSONResponse {
        try {
            $fields = $this->filterService->getAvailableFilterFields($groupfolderId);

            return new JSONResponse([
                'success' => true,
                'fields' => $fields,
            ]);
        } catch (\Exception $e) {
            error_log('MetaVox FilterController getFilterFields error: ' . $e->getMessage());
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }
}
