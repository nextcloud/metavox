<?php
declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCA\MetaVox\Exception\RequestException;
use OCA\MetaVox\L10n\Translatable;
use OCP\IDBConnection;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Service for external API field operations.
 *
 * This is the facade between ApiFieldController (OCS) and FieldService.
 * All external API metadata operations go through this service, shielding
 * the external API from internal FieldService implementation changes.
 */
class ApiFieldService {
    private IDBConnection $db;
    private FieldService $fieldService;
    private LoggerInterface $logger;
    private IRequest $request;
    private MetadataVersions $versions;

    public function __construct(
        IDBConnection $db,
        FieldService $fieldService,
        LoggerInterface $logger,
        IRequest $request,
        MetadataVersions $versions
    ) {
        $this->db = $db;
        $this->fieldService = $fieldService;
        $this->logger = $logger;
        $this->request = $request;
        $this->versions = $versions;
    }

    /**
     * A file the batch could not handle: a fixed text and code for the caller,
     * and the request id that finds the details in the Nextcloud log.
     */
    private function failure(string $reason): array {
        return [
            'error' => $reason === 'delete_failed' ? 'Could not delete the metadata of this file' : 'Could not save the metadata of this file',
            'reason' => $reason,
            'requestId' => $this->request->getId(),
        ];
    }

    // ========================================
    // Single-file metadata operations
    // ========================================

    /**
     * Get metadata for a file within a specific groupfolder (API-safe).
     */
    public function getGroupfolderFileMetadata(int $groupfolderId, int $fileId): array {
        return $this->fieldService->getGroupfolderFileMetadata($groupfolderId, $fileId);
    }

    /**
     * Save metadata for a file within a specific groupfolder (API-safe). Each
     * value is checked against its field (see FieldValue); a multi-select may
     * come as a list.
     *
     * @throws ApiValidationException a value a field does not take, by field name
     */
    public function saveGroupfolderFileMetadata(int $groupfolderId, int $fileId, array $metadata): void {
        $this->fieldService->saveValues($groupfolderId, [$fileId => $metadata]);
    }

    /**
     * Get metadata for multiple files (API-safe, max 100).
     *
     * @deprecated Folder-unaware — joins metavox_file_gf_meta by field_name only,
     * so a file with rows in several (or deleted) group folders returns them all
     * mixed together with no groupfolder_id to tell them apart (issue #98).
     * Use getBulkFileMetadataScoped() with a per-file groupfolder map instead.
     */
    public function getBulkFileMetadata(array $fileIds): array {
        return $this->fieldService->getBulkFileMetadata($fileIds);
    }

    /**
     * Bulk metadata, each file scoped to the ONE team folder it physically
     * lives in (resolved by the controller from the user's mount). Returns the
     * same shape as the per-file GET — { fileId: [ {field..., value}, ... ] } —
     * so every file carries only its own folder's values, with no cross-folder
     * mixing (issue #98).
     *
     * @param array<int, int> $fileGroupfolders fileId => groupfolderId
     * @return array<int, array>
     */
    public function getBulkFileMetadataScoped(array $fileGroupfolders): array {
        $out = [];
        foreach ($fileGroupfolders as $fileId => $groupfolderId) {
            $out[$fileId] = $this->fieldService->getGroupfolderFileMetadata((int)$groupfolderId, (int)$fileId);
        }
        return $out;
    }

    // ========================================
    // Batch operations
    // ========================================

    /**
     * Batch update file metadata for multiple files
     *
     * @param array $updates Array of updates: [['file_id' => 123, 'groupfolder_id' => 1, 'metadata' => ['field_name' => 'value', ...]], ...]
     * @return array Results with success/error per file
     */
    public function batchUpdateFileMetadata(array $updates): array {
        // Field names match regardless of case.
        $names = [];
        foreach ($this->fieldService->getAllFields() as $field) {
            $names[strtolower((string)$field['field_name'])] = (string)$field['field_name'];
        }

        // Per team folder all values at once; later items for the same file win.
        $results = [];
        $values = [];
        $items = [];
        foreach ($updates as $i => $update) {
            $fileId = $update['file_id'] ?? null;
            $groupfolderId = $update['groupfolder_id'] ?? null;
            if (!$fileId) {
                $results[$i] = ['file_id' => null, 'success' => false, 'error' => 'Missing file_id', 'reason' => 'invalid_id'];
                continue;
            }
            if (!$groupfolderId) {
                $results[$i] = ['file_id' => $fileId, 'success' => false, 'error' => 'Missing groupfolder_id', 'reason' => 'invalid_id'];
                continue;
            }

            $metadata = $update['metadata'] ?? [];
            if (!is_array($metadata) || ($metadata !== [] && array_is_list($metadata))) {
                $results[$i] = ['file_id' => $fileId, 'success' => false, 'fields_updated' => 0,
                    'error' => 'Validation failed', 'reason' => 'invalid_input', 'fields' => ['metadata' => ViewColumnFormat::MSG_INVALID]];
                continue;
            }
            $known = [];
            $unknown = [];
            foreach ($metadata as $fieldName => $value) {
                $name = $names[strtolower((string)$fieldName)] ?? null;
                if ($name === null) {
                    $unknown[] = (string)$fieldName;
                } else {
                    $known[$name] = $value;
                }
            }
            // An item with a value its field does not take is refused; the others are saved.
            $checked = $this->fieldService->refusedValues((int)$groupfolderId, [(int)$fileId => $known]);
            $unused = $checked['unused'][(int)$fileId] ?? [];
            $refused = $checked['refused'][(int)$fileId] ?? [];
            if ($refused !== []) {
                $results[$i] = ['file_id' => $fileId, 'success' => false, 'fields_updated' => 0,
                    'error' => 'Validation failed', 'reason' => 'invalid_input', 'fields' => $refused]
                    + ($unknown === [] ? [] : ['unknown_fields' => $unknown]);
                continue;
            }
            $known = array_diff_key($known, array_flip($unused));
            $values[(int)$groupfolderId][(int)$fileId] = $known + ($values[(int)$groupfolderId][(int)$fileId] ?? []);
            $results[$i] = ['file_id' => $fileId, 'success' => true, 'fields_updated' => \count($known)]
                + ($unknown === [] ? [] : ['unknown_fields' => $unknown])
                + ($unused === [] ? [] : ['unused_fields' => $unused]);
            $items[(int)$groupfolderId][$i] = array_keys($known);
        }

        $this->db->beginTransaction();
        try {
            foreach ($values as $groupfolderId => $byFile) {
                // A file the database refuses leaves the other files saved.
                try {
                    $failed = $this->fieldService->saveValuesPerFile($groupfolderId, $byFile);
                } catch (\Exception $e) {
                    $this->logger->error('MetaVox: batch update failed for a team folder', ['exception' => $e, 'groupfolderId' => $groupfolderId]);
                    $failed = array_keys($byFile);
                }
                foreach ($items[$groupfolderId] as $i => $fieldNames) {
                    if (!\in_array((int)$results[$i]['file_id'], $failed, true)) {
                        continue;
                    }
                    $results[$i] = ['file_id' => $results[$i]['file_id'], 'success' => false, 'fields_updated' => 0]
                        + $this->failure('save_failed')
                        + ($fieldNames === [] ? [] : ['failed_fields' => $fieldNames])
                        + array_intersect_key($results[$i], ['unknown_fields' => true]);
                }
            }
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('MetaVox: batch update failed', ['exception' => $e]);
            throw $e;
        }
        // Again after the commit: a read in between could have cached the old values.
        foreach (array_keys($values) as $groupfolderId) {
            $this->versions->bump($groupfolderId);
        }

        ksort($results);
        return array_values($results);
    }

    /**
     * Batch delete file metadata for multiple files
     *
     * @param array $deletes Array of deletes: [['file_id' => 123, 'groupfolder_id' => 1, 'field_names' => ['field1', 'field2'] or null], ...]
     * @return array Results with success/error per file
     */
    public function batchDeleteFileMetadata(array $deletes): array {
        $results = [];

        $this->db->beginTransaction();

        try {
            foreach ($deletes as $delete) {
                $fileId = $delete['file_id'] ?? null;
                $groupfolderId = $delete['groupfolder_id'] ?? null;
                $fieldNames = $delete['field_names'] ?? null;

                if (!$fileId) {
                    $results[] = [
                        'file_id' => null,
                        'success' => false,
                        'error' => 'Missing file_id',
                        'reason' => 'invalid_id'
                    ];
                    continue;
                }

                if (!$groupfolderId) {
                    $results[] = [
                        'file_id' => $fileId,
                        'success' => false,
                        'error' => 'Missing groupfolder_id',
                        'reason' => 'invalid_id'
                    ];
                    continue;
                }

                if ($fieldNames !== null && (!is_array($fieldNames) || !array_is_list($fieldNames) || array_filter($fieldNames, fn ($name) => !is_string($name)) !== [])) {
                    $results[] = ['file_id' => $fileId, 'groupfolder_id' => $groupfolderId, 'success' => false,
                        'error' => 'Validation failed', 'reason' => 'invalid_input', 'fields' => ['field_names' => ViewColumnFormat::MSG_INVALID]];
                    continue;
                }

                // A required field keeps its value: deleting all leaves it, naming it is refused.
                $required = $this->fieldService->requiredFieldNames();
                if ($fieldNames !== null) {
                    $named = array_values(array_filter($required, fn (string $name): bool => in_array(strtolower($name), array_map('strtolower', $fieldNames), true)));
                    if ($named !== []) {
                        $results[] = ['file_id' => $fileId, 'groupfolder_id' => $groupfolderId, 'success' => false,
                            'error' => 'Validation failed', 'reason' => 'invalid_input',
                            'fields' => array_fill_keys($named, Translatable::t('This field is required'))];
                        continue;
                    }
                }

                try {
                    if ($fieldNames === null) {
                        if (!$this->fieldService->clearGroupfolderFileMetadata((int)$groupfolderId, (int)$fileId, true)) {
                            throw new \RuntimeException('Could not delete the metadata');
                        }

                        $results[] = [
                            'file_id' => $fileId,
                            'groupfolder_id' => $groupfolderId,
                            'success' => true,
                            'message' => 'All metadata deleted'
                        ] + ($required === [] ? [] : ['kept_required_fields' => $required]);
                    } else {
                        $this->fieldService->clearGroupfolderFileFields((int)$groupfolderId, (int)$fileId, $fieldNames);

                        $results[] = [
                            'file_id' => $fileId,
                            'groupfolder_id' => $groupfolderId,
                            'success' => true,
                            'fields_deleted' => count($fieldNames)
                        ];
                    }
                } catch (\Exception $e) {
                    $this->logger->error('MetaVox: batch delete failed for a file', ['exception' => $e, 'fileId' => $fileId]);
                    $results[] = ['file_id' => $fileId, 'groupfolder_id' => $groupfolderId, 'success' => false] + $this->failure('delete_failed');
                }
            }

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('MetaVox: batch delete failed', ['exception' => $e]);
            throw $e;
        }
        // Again after the commit: a read in between could have cached the old values.
        foreach (array_unique(array_filter(array_map(fn ($delete) => (int)($delete['groupfolder_id'] ?? 0), $deletes))) as $groupfolderId) {
            $this->versions->bump($groupfolderId);
        }

        return $results;
    }

    /**
     * Batch copy metadata from one file to multiple files.
     *
     * @param int $sourceGroupfolderId Team folder the source file lives in
     * @param int $sourceFileId Source file ID
     * @param array<int, int> $targetGroupfolders targetFileId => its own groupfolderId
     * @param array|null $fieldNames Optional: specific fields to copy (null = copy all)
     * @return array Results
     */
    public function batchCopyFileMetadata(int $sourceGroupfolderId, int $sourceFileId, array $targetGroupfolders, ?array $fieldNames = null): array {
        // Read the source values scoped to its own team folder (issue #98).
        $sourceMetadata = $this->fieldService->getGroupfolderFileMetadata($sourceGroupfolderId, $sourceFileId);

        if (empty($sourceMetadata)) {
            // TRANSLATORS Error when metadata is copied from a file whose Team folder has no metadata fields
            throw RequestException::notFound(Translatable::t('This file has no metadata to copy'));
        }

        // Filter by field names if specified
        if ($fieldNames !== null) {
            $sourceMetadata = array_filter($sourceMetadata, function($field) use ($fieldNames) {
                return in_array($field['field_name'], $fieldNames);
            });
        }

        // Build updates array. NOTE: getGroupfolderFileMetadata returns the
        // stored value under the 'value' key (not 'field_value') — reading the
        // wrong key here meant copies silently carried null for every field.
        // Each update carries its own target groupfolder_id, which
        // batchUpdateFileMetadata requires (a missing one made every copy fail).
        $updates = [];
        foreach ($targetGroupfolders as $targetFileId => $targetGroupfolderId) {
            $metadata = [];
            foreach ($sourceMetadata as $field) {
                if (($field['value'] ?? null) !== null && $field['value'] !== '') {
                    $metadata[$field['field_name']] = $field['value'];
                }
            }

            $updates[] = [
                'file_id' => (int)$targetFileId,
                'groupfolder_id' => (int)$targetGroupfolderId,
                'metadata' => $metadata
            ];
        }

        // Use batch update
        $updateResults = $this->batchUpdateFileMetadata($updates);

        return [
            'success' => true,
            'source_file_id' => $sourceFileId,
            'fields_copied' => count($sourceMetadata),
            'target_results' => $updateResults
        ];
    }

    /**
     * Get metadata statistics
     *
     * @return array Statistics about metadata usage
     */
    public function getMetadataStatistics(): array {
        try {
            $qb = $this->db->getQueryBuilder();

            // Total fields (groupfolder fields only)
            $qb->select($qb->createFunction('COUNT(*)'))
                ->from('metavox_gf_fields');
            $totalFields = (int)$qb->executeQuery()->fetchOne();

            // Total field values (groupfolder file metadata)
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(*)'))
                ->from('metavox_file_gf_meta');
            $totalValues = (int)$qb->executeQuery()->fetchOne();

            // Files with metadata
            $qb = $this->db->getQueryBuilder();
            $qb->selectAlias($qb->createFunction('COUNT(DISTINCT file_id)'), 'count')
                ->from('metavox_file_gf_meta');
            $filesWithMetadata = (int)$qb->executeQuery()->fetchOne();

            // Fields by type
            $qb = $this->db->getQueryBuilder();
            $qb->select('field_type', $qb->createFunction('COUNT(*) as count'))
                ->from('metavox_gf_fields')
                ->groupBy('field_type');
            $fieldsByType = $qb->executeQuery()->fetchAll();

            return [
                'total_fields' => $totalFields,
                'total_values' => $totalValues,
                'files_with_metadata' => $filesWithMetadata,
                'fields_by_type' => $fieldsByType
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get metadata statistics: ' . $e->getMessage());
            throw $e;
        }
    }
}
