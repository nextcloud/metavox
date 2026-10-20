<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Access\Access;
use OCA\MetaVox\Access\RequireAccess;
use OCA\MetaVox\Service\AccountService;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\FilterService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class FilterController extends BaseController {

    private FilterService $filterService;
    private AccountService $accounts;

    public function __construct(
        string $appName,
        IRequest $request,
        FieldService $fieldService,
        FilterService $filterService,
        IUserSession $userSession,
        AccountService $accounts,
    ) {
        parent::__construct($appName, $request, $userSession, $fieldService);
        $this->filterService = $filterService;
        $this->accounts = $accounts;
    }

    /**
     * Get metadata for a batch of files in a groupfolder.
     * Optimized for file list column rendering.
     */
    #[NoAdminRequired]
    #[RequireAccess(Access::FileList, folder: 'groupfolderId')]
    public function getDirectoryMetadata(int $groupfolderId): JSONResponse {
        $user = $this->requireUser();

        $fileIds = $this->getIdListParam('file_ids', required: true, max: 200);

        // Optional: only fetch specific fields
        $fieldNames = $this->getStringListParam('field_names');

        // Same per-file access and permissions as the OCS API.
        $metadata = $this->filterService->getDirectoryMetadataForUser($user->getUID(), $groupfolderId, $fileIds, $fieldNames);

        // The grid shows people by name, as the PROPFIND brings them.
        $accountFields = $this->fieldService->accountFieldNames();
        if ($accountFields !== []) {
            foreach ($metadata as &$values) {
                $names = $this->accounts->namesInValues($values, $accountFields, $user->getUID());
                if ($names !== []) {
                    $values['_names'] = $names;
                }
            }
            unset($values);
        }
        return new JSONResponse($metadata, Http::STATUS_OK);
    }
}
