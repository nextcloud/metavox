<?php
declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Service for API field operations
 * Handles batch operations and complex field logic
 */
class ApiFieldService {
    private IDBConnection $db;
    private FieldService $fieldService;
    private LoggerInterface $logger;

    public function __construct(
        IDBConnection $db,
        FieldService $fieldService,
        LoggerInterface $logger
    ) {
        $this->db = $db;
        $this->fieldService = $fieldService;
        $this->logger = $logger;
    }

    /**
     * Batch update file metadata for multiple files
     *
     * @param array $updates Array of updates: [['file_id' => 123, 'groupfolder_id' => 1, 'metadata' => ['field_name' => 'value', ...]], ...]
     * @return array Results with success/error per file
     */
    public function batchUpdateFileMetadata(array $updates): array {
        $results = [];

        // Get groupfolder file fields from metavox_gf_fields table
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('metavox_gf_fields')
           ->orderBy('sort_order', 'ASC');

        $result = $qb->executeQuery();
        $fieldMap = [];

        while ($row = $result->fetch()) {
            // Use lowercase for case-insensitive matching
            $fieldMap[strtolower($row['field_name'])] = (int)$row['id'];
        }
        $result->closeCursor();

        // Debug logging
        $this->logger->info('ApiFieldService batch update - Available fields: ' . json_encode(array_keys($fieldMap)));

        $this->db->beginTransaction();

        try {
            foreach ($updates as $update) {
                $fileId = $update['file_id'] ?? null;
                $groupfolderId = $update['groupfolder_id'] ?? null;
                $metadata = $update['metadata'] ?? [];

                if (!$fileId) {
                    $results[] = [
                        'file_id' => null,
                        'success' => false,
                        'error' => 'Missing file_id'
                    ];
                    continue;
                }

                if (!$groupfolderId) {
                    $results[] = [
                        'file_id' => $fileId,
                        'success' => false,
                        'error' => 'Missing groupfolder_id'
                    ];
                    continue;
                }

                try {
                    $fieldsUpdated = 0;
                    $this->logger->info('ApiFieldService - Processing file_id: ' . $fileId . ', groupfolder_id: ' . $groupfolderId . ', metadata fields: ' . json_encode(array_keys($metadata)));

                    foreach ($metadata as $fieldName => $value) {
                        // Use lowercase for case-insensitive matching
                        $fieldNameLower = strtolower($fieldName);

                        if (isset($fieldMap[$fieldNameLower])) {
                            $this->logger->info('ApiFieldService - Saving field: ' . $fieldName . ' with ID: ' . $fieldMap[$fieldNameLower]);
                            $this->fieldService->saveGroupfolderFileFieldValue(
                                (int)$groupfolderId,
                                (int)$fileId,
                                $fieldMap[$fieldNameLower],
                                (string)$value
                            );
                            $fieldsUpdated++;
                        } else {
                            $this->logger->warning('ApiFieldService - Field not found in map: ' . $fieldName);
                        }
                    }

                    $results[] = [
                        'file_id' => $fileId,
                        'success' => true,
                        'fields_updated' => $fieldsUpdated
                    ];
                } catch (\Exception $e) {
                    $results[] = [
                        'file_id' => $fileId,
                        'success' => false,
                        'error' => $e->getMessage()
                    ];
                }
            }

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('Batch update failed: ' . $e->getMessage());
            throw $e;
        }

        return $results;
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
                        'error' => 'Missing file_id'
                    ];
                    continue;
                }

                if (!$groupfolderId) {
                    $results[] = [
                        'file_id' => $fileId,
                        'success' => false,
                        'error' => 'Missing groupfolder_id'
                    ];
                    continue;
                }

                try {
                    if ($fieldNames === null) {
                        // Delete all metadata for this file in this groupfolder
                        $qb = $this->db->getQueryBuilder();
                        $qb->delete('metavox_file_gf_meta')
                            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter((int)$fileId)))
                            ->andWhere($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter((int)$groupfolderId)))
                            ->executeStatement();

                        $results[] = [
                            'file_id' => $fileId,
                            'groupfolder_id' => $groupfolderId,
                            'success' => true,
                            'message' => 'All metadata deleted'
                        ];
                    } else {
                        // Delete specific fields (case-insensitive) - single query with OR conditions
                        $fieldNamesLower = array_map('strtolower', $fieldNames);

                        // Build quoted field names for SQL IN clause
                        $quotedFieldNames = array_map(function($name) {
                            return $this->db->quote($name);
                        }, $fieldNamesLower);

                        // Single delete query with field names in WHERE clause
                        $sql = sprintf(
                            'DELETE FROM *PREFIX*metavox_file_gf_meta
                             WHERE file_id = %d
                             AND groupfolder_id = %d
                             AND LOWER(field_name) IN (%s)',
                            (int)$fileId,
                            (int)$groupfolderId,
                            implode(', ', $quotedFieldNames)
                        );

                        $this->db->executeStatement($sql);

                        $results[] = [
                            'file_id' => $fileId,
                            'groupfolder_id' => $groupfolderId,
                            'success' => true,
                            'fields_deleted' => count($fieldNames)
                        ];
                    }
                } catch (\Exception $e) {
                    $results[] = [
                        'file_id' => $fileId,
                        'groupfolder_id' => $groupfolderId,
                        'success' => false,
                        'error' => $e->getMessage()
                    ];
                }
            }

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('Batch delete failed: ' . $e->getMessage());
            throw $e;
        }

        return $results;
    }

    /**
     * Batch copy metadata from one file to multiple files
     *
     * @param int $sourceFileId Source file ID
     * @param array $targetFileIds Target file IDs
     * @param array|null $fieldNames Optional: specific fields to copy (null = copy all)
     * @return array Results
     */
    public function batchCopyFileMetadata(int $sourceFileId, array $targetFileIds, ?array $fieldNames = null): array {
        $results = [];

        // Get source metadata
        $sourceMetadata = $this->fieldService->getFieldMetadata($sourceFileId);

        if (empty($sourceMetadata)) {
            return [
                'success' => false,
                'error' => 'Source file has no metadata'
            ];
        }

        // Filter by field names if specified
        if ($fieldNames !== null) {
            $sourceMetadata = array_filter($sourceMetadata, function($field) use ($fieldNames) {
                return in_array($field['field_name'], $fieldNames);
            });
        }

        // Build updates array
        $updates = [];
        foreach ($targetFileIds as $targetFileId) {
            $metadata = [];
            foreach ($sourceMetadata as $field) {
                $metadata[$field['field_name']] = $field['field_value'];
            }

            $updates[] = [
                'file_id' => $targetFileId,
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

            // Total fields
            $qb->select($qb->createFunction('COUNT(*)'))
                ->from('metavox_fields');
            $totalFields = (int)$qb->executeQuery()->fetchOne();

            // Total field values
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(*)'))
                ->from('metavox_field_values');
            $totalValues = (int)$qb->executeQuery()->fetchOne();

            // Files with metadata
            $qb = $this->db->getQueryBuilder();
            $qb->selectAlias($qb->createFunction('COUNT(DISTINCT file_id)'), 'count')
                ->from('metavox_field_values');
            $filesWithMetadata = (int)$qb->executeQuery()->fetchOne();

            // Fields by type
            $qb = $this->db->getQueryBuilder();
            $qb->select('field_type', $qb->createFunction('COUNT(*) as count'))
                ->from('metavox_fields')
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
