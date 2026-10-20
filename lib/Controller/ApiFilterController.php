<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Access\Access;
use OCA\MetaVox\Access\RequireAccess;
use OCA\MetaVox\Exception\RequestException;
use OCA\MetaVox\L10n\Translatable;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\FilterService;
use OCA\MetaVox\Service\FileAccessService;
use OCA\MetaVox\Service\ApiValidationException;
use OCA\MetaVox\Service\ViewColumnFormat;
use OCA\MetaVox\Validation\FieldOptions;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

class ApiFilterController extends BaseOCSController {

    private FilterService $filterService;

    public function __construct(
        string $appName,
        IRequest $request,
        FieldService $fieldService,
        FilterService $filterService,
        IUserSession $userSession,
        FileAccessService $fileAccess
    ) {
        parent::__construct($appName, $request, $userSession, $fieldService, $fileAccess);
        $this->filterService = $filterService;
    }

    /**
     * Get metadata for a batch of files in a groupfolder.
     * Optimized for file list column rendering.
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[RequireAccess(Access::FileList, folder: 'groupfolderId')]
    public function getDirectoryMetadata(int $groupfolderId): DataResponse {
        $user = $this->requireUser();

        $fileIds = $this->getIdListParam('file_ids', required: true, max: 200);

        // Optional: only fetch specific fields
        $fieldNames = $this->getStringListParam('field_names');

        // Same per-file access and permissions as the Files app route.
        $metadata = $this->filterService->getDirectoryMetadataForUser($user->getUID(), $groupfolderId, $fileIds, $fieldNames);
        return new DataResponse($metadata, Http::STATUS_OK);
    }

    /**
     * Get sorted and filtered file IDs for a groupfolder.
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[RequireAccess(Access::TeamFolderMember, folder: 'groupfolderId')]
    public function getSortedFileIds(int $groupfolderId): DataResponse {
        $user = $this->requireUser();

        $sortField = $this->textParam('sort_field');
        $sortOrder = $this->choiceParam('sort_order', ['asc', 'desc'], 'asc');
        $sortFieldType = $this->textParam('sort_field_type', max: 50) ?? 'text';
        $filters = self::parseFilters($this->request->getParam('filters'));
        if ($filters === null) {
            throw new ApiValidationException(['filters' => ViewColumnFormat::MSG_INVALID]);
        }

        if (empty($sortField) && empty($filters)) {
            // TRANSLATORS Error from the API when a request to sort or filter files sends neither; keep sort_field and filters as they are
            throw RequestException::invalid(Translatable::t('Send a sort_field, filters or both'));
        }

        $fields = $this->fieldService->getAssignedFieldsWithDataForGroupfolder($groupfolderId);
        // A list matches on any of its items: a multi-select, people, file links.
        $multiselectFields = [];
        foreach ($fields as $field) {
            if (FieldService::holdsList((string)($field['field_type'] ?? ''))) {
                $multiselectFields[] = $field['field_name'];
            }
        }

        $result = $this->filterService->getSortedFilteredFileIds(
            $groupfolderId,
            $this->fileAccess->readableScope($user->getUID(), $groupfolderId),
            $sortField ?: null,
            $sortOrder,
            $filters,
            $sortFieldType,
            $multiselectFields
        );

        return new DataResponse($result, Http::STATUS_OK);
    }

    /**
     * Get distinct filter values for all fields in one request.
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[RequireAccess(Access::TeamFolderMember, folder: 'groupfolderId')]
    public function getAllFilterValues(int $groupfolderId): DataResponse {
        $user = $this->requireUser();

        $fieldNamesArray = $this->getStringListParam('field_names');

        // For select/multiselect fields, use field_options from config instead of DB GROUP BY
        $fields = $this->fieldService->getAssignedFieldsWithDataForGroupfolder($groupfolderId);
        $optionFields = [];
        $dbFieldNames = [];

        foreach ($fields as $field) {
            $name = $field['field_name'] ?? '';
            if (empty($name)) continue;
            if (!empty($fieldNamesArray) && !in_array($name, $fieldNamesArray)) continue;

            $type = $field['field_type'] ?? '';
            if (in_array($type, ['select', 'multiselect', 'multi_select', 'dropdown', 'checkbox'])) {
                if ($type === 'checkbox') {
                    $optionFields[$name] = ['1', '0'];
                } else {
                    $options = FieldOptions::values($field['field_options'] ?? []);
                    if ($options !== []) {
                        $optionFields[$name] = $options;
                    } else {
                        $dbFieldNames[] = $name;
                    }
                }
            } else {
                $dbFieldNames[] = $name;
            }
        }

        $dbValues = [];
        if (!empty($dbFieldNames)) {
            $dbValues = $this->filterService->distinctValues($groupfolderId, $this->fileAccess->readableScope($user->getUID(), $groupfolderId), $dbFieldNames);
        }

        return new DataResponse(array_merge($optionFields, $dbValues), Http::STATUS_OK);
    }

    /**
     * Get distinct filter values scoped to specific file IDs (current directory).
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[RequireAccess(Access::TeamFolderMember, folder: 'groupfolderId')]
    public function getScopedFilterValues(int $groupfolderId): DataResponse {
        $user = $this->requireUser();

        // None is the whole team folder; a few at a time, as the other lists.
        $fileIds = $this->getIdListParam('file_ids', max: 200);

        $values = $this->filterService->distinctValues($groupfolderId, $this->fileAccess->readableScope($user->getUID(), $groupfolderId), [], $fileIds);
        return new DataResponse($values, Http::STATUS_OK);
    }

    /**
     * JSON object of field name => list of values; null when malformed.
     *
     * @return array<string, list<scalar>>|null
     */
    public static function parseFilters(mixed $json): ?array {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($decoded)) {
            return null;
        }
        $filters = [];
        foreach ($decoded as $field => $values) {
            if (!is_array($values) || !array_is_list($values)) {
                return null;
            }
            foreach ($values as $value) {
                if (!is_scalar($value)) {
                    return null;
                }
            }
            $filters[(string)$field] = $values;
        }
        return $filters;
    }
}
