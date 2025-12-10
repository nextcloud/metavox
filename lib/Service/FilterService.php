<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\IDBConnection;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

class FilterService {

    private IDBConnection $db;
    private IRootFolder $rootFolder;
    private FieldService $fieldService;
    private LoggerInterface $logger;

    public function __construct(
        IDBConnection $db,
        IRootFolder $rootFolder,
        FieldService $fieldService,
        LoggerInterface $logger
    ) {
        $this->db = $db;
        $this->rootFolder = $rootFolder;
        $this->fieldService = $fieldService;
        $this->logger = $logger;
    }

    /**
     * Filter files in a groupfolder based on metadata criteria
     *
     * @param int $groupfolderId The groupfolder ID
     * @param array $filters Array of filter criteria
     * @param string $userId Current user ID
     * @param string $path Optional path within the groupfolder
     * @return array Array with 'files' and 'debug' keys
     */
    public function filterFilesByMetadata(int $groupfolderId, array $filters, string $userId, string $path = '/'): array {
        $debug = [
            'groupfolder_id' => $groupfolderId,
            'filters' => $filters,
            'user_id' => $userId,
            'path' => $path,
        ];

        if (empty($filters)) {
            $debug['message'] = 'No filters provided';
            return ['files' => [], 'debug' => $debug];
        }

        $this->logger->info('FilterService: Filtering files in groupfolder ' . $groupfolderId . ' with filters: ' . json_encode($filters));

        // Get all files with metadata in this groupfolder
        $qb = $this->db->getQueryBuilder();

        // Start with base query - get all files that have metadata in this groupfolder
        $qb->selectDistinct('fm.file_id')
            ->from('metavox_file_gf_meta', 'fm')
            ->where($qb->expr()->eq('fm.groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)));

        // Build WHERE conditions for each filter
        $filterConditions = [];
        $debug['filter_conditions_built'] = [];

        foreach ($filters as $filter) {
            $fieldName = $filter['field_name'] ?? null;
            $operator = $filter['operator'] ?? 'equals';
            $value = $filter['value'] ?? null;

            if (!$fieldName) {
                continue;
            }

            // Create alias for this filter's join
            $alias = 'fm_' . md5($fieldName);

            // Use INNER JOIN because we need files that have ALL specified metadata fields
            // LEFT JOIN would return NULLs which get filtered out by WHERE conditions anyway
            $qb->innerJoin(
                'fm',
                'metavox_file_gf_meta',
                $alias,
                $qb->expr()->andX(
                    $qb->expr()->eq('fm.file_id', $alias . '.file_id'),
                    $qb->expr()->eq($alias . '.groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)),
                    $qb->expr()->eq($alias . '.field_name', $qb->createNamedParameter($fieldName, IQueryBuilder::PARAM_STR))
                )
            );

            // Build condition based on operator
            $condition = $this->buildFilterCondition($qb, $alias . '.field_value', $operator, $value, $filter['field_type'] ?? 'text');

            if ($condition) {
                $filterConditions[] = $condition;
                $debug['filter_conditions_built'][] = [
                    'field_name' => $fieldName,
                    'operator' => $operator,
                    'value' => $value,
                    'field_type' => $filter['field_type'] ?? 'text',
                    'alias' => $alias,
                ];
            }
        }

        // Combine all filter conditions with AND
        if (!empty($filterConditions)) {
            $qb->andWhere($qb->expr()->andX(...$filterConditions));
        }

        // Capture SQL query and parameters for debug
        $debug['sql'] = $qb->getSQL();
        $debug['parameters'] = $qb->getParameters();

        // Debug: Log the SQL query
        $this->logger->info('FilterService: SQL Query: ' . $debug['sql']);
        $this->logger->info('FilterService: Parameters: ' . json_encode($debug['parameters']));

        $result = $qb->executeQuery();
        $fileIds = [];
        while ($row = $result->fetch()) {
            $fileIds[] = (int)$row['file_id'];
        }
        $result->closeCursor();

        $debug['file_ids_found'] = $fileIds;
        $debug['file_count'] = count($fileIds);

        $this->logger->info('FilterService: Found ' . count($fileIds) . ' matching file IDs: ' . json_encode($fileIds));

        // If no files match, return empty array with debug info
        if (empty($fileIds)) {
            $debug['message'] = 'No files match the filter criteria';
            return ['files' => [], 'debug' => $debug];
        }

        // Get file details and metadata for matched files
        $files = $this->getFileDetailsWithMetadata($groupfolderId, $fileIds, $userId);

        return ['files' => $files, 'debug' => $debug];
    }

    /**
     * Build filter condition based on operator and field type
     * @return string|ICompositeExpression|null
     */
    private function buildFilterCondition(IQueryBuilder $qb, string $column, string $operator, $value, string $fieldType) {
        switch ($operator) {
            case 'equals':
                if ($fieldType === 'checkbox') {
                    // For checkboxes: checked = '1', unchecked = '' (empty string in DB)
                    // Handle boolean values from frontend
                    if ($value === true || $value === '1' || $value === 1) {
                        return $qb->expr()->eq($column, $qb->createNamedParameter('1', IQueryBuilder::PARAM_STR));
                    } else {
                        // Unchecked: match empty string OR 0
                        return $qb->expr()->orX(
                            $qb->expr()->eq($column, $qb->createNamedParameter('', IQueryBuilder::PARAM_STR)),
                            $qb->expr()->eq($column, $qb->createNamedParameter('0', IQueryBuilder::PARAM_STR))
                        );
                    }
                }
                return $qb->expr()->eq($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_STR));

            case 'not_equals':
                return $qb->expr()->neq($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_STR));

            case 'contains':
                return $qb->expr()->like($column, $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($value) . '%', IQueryBuilder::PARAM_STR));

            case 'not_contains':
                return $qb->expr()->notLike($column, $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($value) . '%', IQueryBuilder::PARAM_STR));

            case 'starts_with':
                return $qb->expr()->like($column, $qb->createNamedParameter($this->db->escapeLikeParameter($value) . '%', IQueryBuilder::PARAM_STR));

            case 'ends_with':
                return $qb->expr()->like($column, $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($value), IQueryBuilder::PARAM_STR));

            case 'greater_than':
                if ($fieldType === 'number' || $fieldType === 'date') {
                    return $qb->expr()->gt($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_STR));
                }
                break;

            case 'less_than':
                if ($fieldType === 'number' || $fieldType === 'date') {
                    return $qb->expr()->lt($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_STR));
                }
                break;

            case 'greater_or_equal':
                if ($fieldType === 'number' || $fieldType === 'date') {
                    return $qb->expr()->gte($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_STR));
                }
                break;

            case 'less_or_equal':
                if ($fieldType === 'number' || $fieldType === 'date') {
                    return $qb->expr()->lte($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_STR));
                }
                break;

            case 'is_empty':
                return $qb->expr()->orX(
                    $qb->expr()->isNull($column),
                    $qb->expr()->eq($column, $qb->createNamedParameter('', IQueryBuilder::PARAM_STR))
                );

            case 'is_not_empty':
                return $qb->expr()->andX(
                    $qb->expr()->isNotNull($column),
                    $qb->expr()->neq($column, $qb->createNamedParameter('', IQueryBuilder::PARAM_STR))
                );

            case 'one_of':
                // For select/multiselect - value should be an array
                if (is_array($value) && !empty($value)) {
                    $conditions = [];
                    foreach ($value as $v) {
                        if ($fieldType === 'multiselect') {
                            // For multiselect, check if value contains the option (;# separated)
                            $conditions[] = $qb->expr()->like($column, $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($v) . '%', IQueryBuilder::PARAM_STR));
                        } else {
                            $conditions[] = $qb->expr()->eq($column, $qb->createNamedParameter($v, IQueryBuilder::PARAM_STR));
                        }
                    }
                    return $qb->expr()->orX(...$conditions);
                }
                break;

            case 'between':
                // For date ranges - value should be array with [min, max]
                if (is_array($value) && count($value) === 2 && ($fieldType === 'date' || $fieldType === 'number')) {
                    return $qb->expr()->andX(
                        $qb->expr()->gte($column, $qb->createNamedParameter($value[0], IQueryBuilder::PARAM_STR)),
                        $qb->expr()->lte($column, $qb->createNamedParameter($value[1], IQueryBuilder::PARAM_STR))
                    );
                }
                break;
        }

        return null;
    }

    /**
     * Get file details with metadata for given file IDs
     */
    private function getFileDetailsWithMetadata(int $groupfolderId, array $fileIds, string $userId): array {
        $files = [];

        foreach ($fileIds as $fileId) {
            try {
                // Get file info from Nextcloud
                $userFolder = $this->rootFolder->getUserFolder($userId);
                $nodes = $userFolder->getById($fileId);

                if (empty($nodes)) {
                    continue;
                }

                $node = $nodes[0];

                // Get metadata for this file
                $metadata = $this->getFileMetadata($groupfolderId, $fileId);

                $files[] = [
                    'id' => $fileId,
                    'name' => $node->getName(),
                    'path' => $node->getPath(),
                    'type' => $node->getType() === \OCP\Files\FileInfo::TYPE_FOLDER ? 'dir' : 'file',
                    'mimetype' => $node->getMimetype(),
                    'size' => $node->getSize(),
                    'mtime' => $node->getMTime(),
                    'permissions' => $node->getPermissions(),
                    'metadata' => $metadata,
                ];
            } catch (NotFoundException $e) {
                $this->logger->warning('FilterService: File not found: ' . $fileId);
                continue;
            }
        }

        return $files;
    }

    /**
     * Get metadata for a specific file in a groupfolder
     */
    private function getFileMetadata(int $groupfolderId, int $fileId): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('field_name', 'field_value')
            ->from('metavox_file_gf_meta')
            ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $metadata = [];

        while ($row = $result->fetch()) {
            $metadata[$row['field_name']] = $row['field_value'];
        }

        $result->closeCursor();

        return $metadata;
    }

    /**
     * Get available fields for filtering in a groupfolder
     * Returns only item metadata fields (excludes team folder metadata)
     */
    public function getAvailableFilterFields(int $groupfolderId): array {
        // Use FieldService to get all metadata (includes both types of fields)
        $allFields = $this->fieldService->getGroupfolderMetadata($groupfolderId);

        $filterFields = [];
        foreach ($allFields as $field) {
            // Only include item metadata fields (applies_to_groupfolder = 0)
            // Exclude team folder metadata (applies_to_groupfolder = 1)
            $appliesToGroupfolder = $field['applies_to_groupfolder'] ?? 0;

            if ($appliesToGroupfolder !== 1) {
                $filterFields[] = [
                    'field_name' => $field['field_name'],
                    'field_label' => $field['field_label'],
                    'field_type' => $field['field_type'],
                    'field_options' => $field['field_options'] ?? [],
                    'applies_to_groupfolder' => $appliesToGroupfolder,
                ];
            }
        }

        $this->logger->info('FilterService: Returned ' . count($filterFields) . ' item metadata fields for filtering (excluded team folder metadata)');

        return $filterFields;
    }
}
