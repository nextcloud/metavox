<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;

class FieldService {

    private IDBConnection $db;
    private IGroupManager $groupManager;
    private IUserManager $userManager;

    // Request-scope cache for fields
    private ?array $fieldsCache = null;
    private array $fieldsByScopeCache = [];

    public function __construct(IDBConnection $db, IGroupManager $groupManager, IUserManager $userManager) {
        $this->db = $db;
        $this->groupManager = $groupManager;
        $this->userManager = $userManager;
    }

public function getAllFields(): array {
    // Return from cache if available
    if ($this->fieldsCache !== null) {
        return $this->fieldsCache;
    }

    // Only groupfolder fields are supported now
    $qb = $this->db->getQueryBuilder();
    $qb->select('*')
       ->from('metavox_gf_fields')
       ->orderBy('sort_order', 'ASC');

    $result = $qb->executeQuery();
    $fields = [];
    while ($row = $result->fetch()) {
        $fields[] = [
            'id' => (int)$row['id'],
            'field_name' => $row['field_name'],
            'field_label' => $row['field_label'],
            'field_type' => $row['field_type'],
            'field_description' => $row['field_description'] ?? '',
            'field_options' => $row['field_options'] ? json_decode($row['field_options'], true) : [],
            'is_required' => (bool)$row['is_required'],
            'sort_order' => (int)$row['sort_order'],
            'scope' => 'groupfolder',
            'applies_to_groupfolder' => isset($row['applies_to_groupfolder']) ? (int)$row['applies_to_groupfolder'] : 0,
        ];
    }
    $result->closeCursor();

    // Cache the results for this request
    $this->fieldsCache = $fields;

    return $fields;
}

    // Get single field by ID (for edit modal)
public function getFieldById(int $id): ?array {
    try {
        // Only groupfolder fields are supported now
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('metavox_gf_fields')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if ($row) {
            return [
                'id' => (int)$row['id'],
                'field_name' => $row['field_name'],
                'field_label' => $row['field_label'],
                'field_type' => $row['field_type'],
                'field_description' => $row['field_description'] ?? '',
                'field_options' => $row['field_options'] ? json_decode($row['field_options'], true) : [],
                'is_required' => (bool)$row['is_required'],
                'sort_order' => (int)$row['sort_order'],
                'scope' => 'groupfolder',
                'applies_to_groupfolder' => isset($row['applies_to_groupfolder']) ? (int)$row['applies_to_groupfolder'] : 0,
            ];
        }

        return null;

    } catch (\Exception $e) {
        return null;
    }
}

public function getFieldsByScope(string $scope = 'global'): array {
    // Return from cache if available
    if (isset($this->fieldsByScopeCache[$scope])) {
        return $this->fieldsByScopeCache[$scope];
    }

    // Only groupfolder fields are supported now
    $qb = $this->db->getQueryBuilder();
    $qb->select('*')
       ->from('metavox_gf_fields')
       ->orderBy('sort_order', 'ASC');

    $result = $qb->executeQuery();
    $fields = [];
    while ($row = $result->fetch()) {
        $fieldData = [
            'id' => (int)$row['id'],
            'field_name' => $row['field_name'],
            'field_label' => $row['field_label'],
            'field_type' => $row['field_type'],
            'field_description' => $row['field_description'] ?? '',
            'field_options' => $row['field_options'] ? json_decode($row['field_options'], true) : [],
            'is_required' => (bool)$row['is_required'],
            'sort_order' => (int)$row['sort_order'],
            'scope' => 'groupfolder',
            'applies_to_groupfolder' => isset($row['applies_to_groupfolder']) ? (int)$row['applies_to_groupfolder'] : 0,
        ];

        $fields[] = $fieldData;
    }
    $result->closeCursor();

    // Cache the results for this request
    $this->fieldsByScopeCache[$scope] = $fields;

    return $fields;
}

    public function getAllFieldsForGroupfolderConfig(): array {
        return $this->getFieldsByScope('groupfolder');
    }

    /**
     * Clear field cache - called after create/update/delete operations
     */
    private function clearFieldCache(): void {
        $this->fieldsCache = null;
        $this->fieldsByScopeCache = [];
    }

    // Universal update method - only groupfolder fields supported now
public function updateField(int $id, array $fieldData): bool {
    try {
        // Verify field exists in metavox_gf_fields
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('metavox_gf_fields')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $exists = $result->fetchColumn();
        $result->closeCursor();

        if (!$exists) {
            return false;
        }

        // Process field_options
        $fieldOptions = '';
        if (isset($fieldData['field_options'])) {
            if (is_array($fieldData['field_options'])) {
                $fieldOptions = implode("\n", $fieldData['field_options']);
            } else {
                $fieldOptions = $fieldData['field_options'];
            }
        }

        $qb = $this->db->getQueryBuilder();
        $qb->update('metavox_gf_fields')
           ->set('field_name', $qb->createNamedParameter($fieldData['field_name']))
           ->set('field_label', $qb->createNamedParameter($fieldData['field_label']))
           ->set('field_type', $qb->createNamedParameter($fieldData['field_type']))
           ->set('field_description', $qb->createNamedParameter($fieldData['field_description'] ?? ''))
           ->set('field_options', $qb->createNamedParameter(json_encode(array_filter(explode("\n", $fieldOptions)))))
           ->set('is_required', $qb->createNamedParameter($fieldData['is_required'] ? 1 : 0, IQueryBuilder::PARAM_INT))
           ->set('sort_order', $qb->createNamedParameter($fieldData['sort_order'] ?? 0, IQueryBuilder::PARAM_INT))
           ->set('updated_at', $qb->createNamedParameter(date('Y-m-d H:i:s')))
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        // Add applies_to_groupfolder if it exists
        if (isset($fieldData['applies_to_groupfolder'])) {
            try {
                $qb->set('applies_to_groupfolder', $qb->createNamedParameter($fieldData['applies_to_groupfolder'], IQueryBuilder::PARAM_INT));
            } catch (\Exception $e) {
                // Column might not exist
            }
        }

        $result = $qb->executeStatement() > 0;

        // Clear cache after successful update
        if ($result) {
            $this->clearFieldCache();
        }

        return $result;

    } catch (\Exception $e) {
        return false;
    }
}
public function createField(array $fieldData): int {
    // Only groupfolder fields are supported now
    return $this->createGroupfolderField($fieldData);
}

    /**
     * Create groupfolder field in separate table
     */
private function createGroupfolderField(array $fieldData): int {
    try {
        error_log('Metavox createGroupfolderField called with: ' . json_encode($fieldData));
        
        // CHECK FOR DUPLICATE FIELD NAME
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('metavox_gf_fields')
           ->where($qb->expr()->eq('field_name', $qb->createNamedParameter($fieldData['field_name'])));

        $result = $qb->executeQuery();
        $existingId = $result->fetchOne();
        $result->closeCursor();
        
        if ($existingId) {
            throw new \Exception('A groupfolder field with the name "' . $fieldData['field_name'] . '" already exists. Please choose a different name.');
        }
        
        // Check if applies_to_groupfolder column exists
        $useAppliesToGroupfolder = true;
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('applies_to_groupfolder')
               ->from('metavox_gf_fields')
               ->setMaxResults(1);
            $qb->executeQuery()->closeCursor();
        } catch (\Exception $e) {
            error_log('Metavox: applies_to_groupfolder column does not exist yet, skipping...');
            $useAppliesToGroupfolder = false;
        }
        
        $qb = $this->db->getQueryBuilder();
        $values = [
            'field_name' => $qb->createNamedParameter($fieldData['field_name']),
            'field_label' => $qb->createNamedParameter($fieldData['field_label']),
            'field_type' => $qb->createNamedParameter($fieldData['field_type']),
            'field_description' => $qb->createNamedParameter($fieldData['field_description'] ?? ''), // ← ADD THIS LINE
            'field_options' => $qb->createNamedParameter(json_encode($fieldData['field_options'] ?? [])),
            'is_required' => $qb->createNamedParameter($fieldData['is_required'] ? 1 : 0, IQueryBuilder::PARAM_INT),
            'sort_order' => $qb->createNamedParameter($fieldData['sort_order'] ?? 0, IQueryBuilder::PARAM_INT),
            'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
            'updated_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
        ];
        
        // Only add applies_to_groupfolder if column exists
        if ($useAppliesToGroupfolder) {
            $values['applies_to_groupfolder'] = $qb->createNamedParameter($fieldData['applies_to_groupfolder'] ?? 0, IQueryBuilder::PARAM_INT);
        }
        
        $qb->insert('metavox_gf_fields')
           ->values($values);

        $result = $qb->executeStatement();
        $insertId = (int) $this->db->lastInsertId('metavox_gf_fields');

        // Clear cache after successful create
        $this->clearFieldCache();

        error_log('Metavox createGroupfolderField success: ' . $insertId);
        return $insertId;
        
    } catch (\Exception $e) {
        error_log('Metavox createGroupfolderField error: ' . $e->getMessage());
        error_log('Metavox createGroupfolderField error trace: ' . $e->getTraceAsString());
        throw $e;
    }
}

public function deleteField(int $id): bool {
    $this->db->beginTransaction();

    try {
        // Only groupfolder fields supported now
        $qb = $this->db->getQueryBuilder();
        $qb->select('field_name')
           ->from('metavox_gf_fields')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $fieldName = $result->fetchColumn();
        $result->closeCursor();

        if (!$fieldName) {
            $this->db->rollBack();
            return false;
        }

        // 1. Delete groupfolder metadata values
        $qb = $this->db->getQueryBuilder();
        $qb->delete('metavox_gf_metadata')
           ->where($qb->expr()->eq('field_name', $qb->createNamedParameter($fieldName)));
        $qb->executeStatement();

        // 2. Delete file-in-groupfolder metadata
        $qb = $this->db->getQueryBuilder();
        $qb->delete('metavox_file_gf_meta')
           ->where($qb->expr()->eq('field_name', $qb->createNamedParameter($fieldName)));
        $qb->executeStatement();

        // 3. Delete groupfolder field assignments
        $qb = $this->db->getQueryBuilder();
        $qb->delete('metavox_gf_assigns')
           ->where($qb->expr()->eq('field_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();

        // 4. Delete the groupfolder field itself
        $qb = $this->db->getQueryBuilder();
        $qb->delete('metavox_gf_fields')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $deleted = $qb->executeStatement();

        if ($deleted === 0) {
            throw new \Exception('Field deletion failed - no rows affected');
        }

        // Commit transaction - all or nothing
        $this->db->commit();

        // Clear cache after successful delete
        $this->clearFieldCache();

        return true;

    } catch (\Exception $e) {
        // Rollback on any error
        $this->db->rollBack();
        return false;
    }
}
public function getFieldMetadata(int $fileId): array {
        // Only groupfolder file metadata is supported now
        // This returns metadata from metavox_file_gf_meta joined with metavox_gf_fields
        $qb = $this->db->getQueryBuilder();
        $qb->select('f.id', 'f.field_name', 'f.field_label', 'f.field_type', 'f.field_options', 'f.is_required', 'v.field_value as value')
           ->from('metavox_gf_fields', 'f')
           ->leftJoin('f', 'metavox_file_gf_meta', 'v', 'f.field_name = v.field_name AND v.file_id = :file_id')
           ->setParameter('file_id', $fileId)
           ->orderBy('f.sort_order', 'ASC');

        $result = $qb->executeQuery();
        $metadata = [];
        while ($row = $result->fetch()) {
            $metadata[] = [
                'id' => (int)$row['id'],
                'field_name' => $row['field_name'],
                'field_label' => $row['field_label'],
                'field_type' => $row['field_type'],
                'field_options' => $row['field_options'] ? json_decode($row['field_options'], true) : [],
                'is_required' => (bool)$row['is_required'],
                'value' => $row['value'],
            ];
        }
        $result->closeCursor();

        return $metadata;
    }

public function saveFieldValue(int $fileId, int $fieldId, string $value): bool {
    try {
        // Get field name from metavox_gf_fields
        $qb = $this->db->getQueryBuilder();
        $qb->select('field_name')
           ->from('metavox_gf_fields')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($fieldId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $fieldName = $result->fetchColumn();
        $result->closeCursor();

        if (!$fieldName) {
            return false;
        }

        $platform = $this->db->getDatabasePlatform();
        $now = date('Y-m-d H:i:s');

        if ($platform instanceof \Doctrine\DBAL\Platforms\MySqlPlatform) {
            $sql = "INSERT INTO *PREFIX*metavox_file_gf_meta
                    (file_id, groupfolder_id, field_name, field_value, created_at, updated_at)
                    VALUES (?, 0, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                    field_value = VALUES(field_value),
                    updated_at = VALUES(updated_at)";
        } else {
            $sql = "INSERT INTO *PREFIX*metavox_file_gf_meta
                    (file_id, groupfolder_id, field_name, field_value, created_at, updated_at)
                    VALUES (?, 0, ?, ?, ?, ?)
                    ON CONFLICT (file_id, groupfolder_id, field_name)
                    DO UPDATE SET
                    field_value = EXCLUDED.field_value,
                    updated_at = EXCLUDED.updated_at";
        }

        $this->db->executeStatement($sql, [
            $fileId,
            $fieldName,
            $value,
            $now,
            $now
        ]);

        $this->queueSearchIndexUpdate($fileId);
        return true;

    } catch (\Exception $e) {
        return false;
    }
}

    // Groupfolder functionality
public function getGroupfolders(string $userId): array {
    try {
        // Get user object
        $user = $this->userManager->get($userId);
        if (!$user) {
            error_log('MetaVox: User not found: ' . $userId);
            return [];
        }

        // Get user's groups
        $userGroups = $this->groupManager->getUserGroupIds($user);
        error_log('MetaVox: User ' . $userId . ' is in groups: ' . json_encode($userGroups));

        // Get all groupfolders
        $qb = $this->db->getQueryBuilder();
        $qb->select('folder_id', 'mount_point')
           ->from('group_folders')
           ->orderBy('folder_id');

        $result = $qb->executeQuery();
        $folders = [];

        while ($row = $result->fetch()) {
            $folderId = (int)($row['folder_id']);
            $mountPoint = $row['mount_point'] ?? 'Team Folder ' . $folderId;

            // Get applicable groups for this folder from group_folders_groups table
            $qb2 = $this->db->getQueryBuilder();
            $qb2->select('group_id')
                ->from('group_folders_groups')
                ->where($qb2->expr()->eq('folder_id', $qb2->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)));

            $result2 = $qb2->executeQuery();
            $folderGroups = [];

            while ($groupRow = $result2->fetch()) {
                $folderGroups[] = $groupRow['group_id'];
            }
            $result2->closeCursor();

            // Check if user has access (user is in at least one of the folder's groups)
            $hasAccess = false;
            foreach ($userGroups as $userGroup) {
                if (in_array($userGroup, $folderGroups)) {
                    $hasAccess = true;
                    break;
                }
            }

            // Only add folder if user has access
            if ($hasAccess) {
                $folders[] = [
                    'id' => $folderId,
                    'mount_point' => $mountPoint,
                    'groups' => $folderGroups,
                    'quota' => -3,
                    'size' => 0,
                    'acl' => false,
                ];
            }
        }
        $result->closeCursor();

        error_log('MetaVox: User has access to ' . count($folders) . ' groupfolders');
        return $folders;

    } catch (\Exception $e) {
        error_log('MetaVox getGroupfolders error: ' . $e->getMessage());
        error_log('MetaVox getGroupfolders trace: ' . $e->getTraceAsString());

        // If even the basic query fails, return an empty array
        return [];
    }
}
public function getGroupfolderMetadata(int $groupfolderId): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('f.id', 'f.field_name', 'f.field_label', 'f.field_type', 'f.field_options', 'f.is_required', 'f.applies_to_groupfolder', 'v.field_value as value')
               ->from('metavox_gf_fields', 'f')
               ->innerJoin('f', 'metavox_gf_assigns', 'gf', 'f.id = gf.field_id AND gf.groupfolder_id = :groupfolder_id')
               ->leftJoin('f', 'metavox_gf_metadata', 'v', 'f.field_name = v.field_name AND v.groupfolder_id = :groupfolder_id')
               ->setParameter('groupfolder_id', $groupfolderId)
               ->orderBy('f.sort_order', 'ASC');

            $result = $qb->executeQuery();
            $metadata = [];
            while ($row = $result->fetch()) {
                $metadata[] = [
                    'id' => (int)$row['id'],
                    'field_name' => $row['field_name'],
                    'field_label' => $row['field_label'],
                    'field_type' => $row['field_type'],
                    'field_options' => $row['field_options'] ? json_decode($row['field_options'], true) : [],
                    'is_required' => (bool)$row['is_required'],
                    'applies_to_groupfolder' => (int)($row['applies_to_groupfolder'] ?? 0),
                    'value' => $row['value'],
                ];
            }
            $result->closeCursor();

            error_log('Metavox getGroupfolderMetadata: Found ' . count($metadata) . ' fields for groupfolder ' . $groupfolderId);
            return $metadata;
            
        } catch (\Exception $e) {
            error_log('Metavox getGroupfolderMetadata error: ' . $e->getMessage());
            error_log('Metavox getGroupfolderMetadata error trace: ' . $e->getTraceAsString());
            return [];
        }
    }

    public function getAssignedFieldsForGroupfolder(int $groupfolderId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('field_id')
           ->from('metavox_gf_assigns')
           ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $fieldIds = [];
        while ($row = $result->fetch()) {
            $fieldIds[] = (int)$row['field_id'];
        }
        $result->closeCursor();

        return $fieldIds;
    }

    /**
 * Get full field data for assigned fields in a groupfolder
 */
public function getAssignedFieldsWithDataForGroupfolder(int $groupfolderId): array {
    try {
        // Get assigned field IDs
        $qb = $this->db->getQueryBuilder();
        $qb->select('field_id')
           ->from('metavox_gf_assigns')
           ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $fieldIds = [];
        while ($row = $result->fetch()) {
            $fieldIds[] = (int)$row['field_id'];
        }
        $result->closeCursor();

        if (empty($fieldIds)) {
            return [];
        }

        // Get full field data for these IDs
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('metavox_gf_fields')
           ->where($qb->expr()->in('id', $qb->createNamedParameter($fieldIds, IQueryBuilder::PARAM_INT_ARRAY)))
           ->orderBy('sort_order', 'ASC');

        $result = $qb->executeQuery();
        $fields = [];
        while ($row = $result->fetch()) {
            $fields[] = [
                'id' => (int)$row['id'],
                'field_name' => $row['field_name'],
                'field_label' => $row['field_label'],
                'field_type' => $row['field_type'],
                'field_description' => $row['field_description'] ?? '',
                'field_options' => $row['field_options'] ? json_decode($row['field_options'], true) : [],
                'is_required' => (bool)$row['is_required'],
                'sort_order' => (int)$row['sort_order'],
                'scope' => 'groupfolder',
                'applies_to_groupfolder' => isset($row['applies_to_groupfolder']) ? (int)$row['applies_to_groupfolder'] : 0,
            ];
        }
        $result->closeCursor();

        return $fields;
        
    } catch (\Exception $e) {
        error_log('FieldService getAssignedFieldsWithDataForGroupfolder error: ' . $e->getMessage());
        return [];
    }
}

public function setGroupfolderFields(int $groupfolderId, array $fieldIds): bool {
    $this->db->beginTransaction();
    try {
        // Delete all existing assignments for this groupfolder
        $qb = $this->db->getQueryBuilder();
        $qb->delete('metavox_gf_assigns')
           ->where($qb->expr()->eq('groupfolder_id',
                   $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();

        // Batch insert all new assignments - use chunks of 100 to avoid query size limits
        if (!empty($fieldIds)) {
            $timestamp = date('Y-m-d H:i:s');
            $chunks = array_chunk($fieldIds, 100);

            foreach ($chunks as $chunk) {
                // Build batch insert with raw SQL for better performance
                $valueSets = [];
                foreach ($chunk as $fieldId) {
                    $valueSets[] = sprintf('(%d, %d, %s)',
                        (int)$groupfolderId,
                        (int)$fieldId,
                        $this->db->quote($timestamp)
                    );
                }

                $sql = sprintf(
                    'INSERT INTO *PREFIX*metavox_gf_assigns (groupfolder_id, field_id, created_at) VALUES %s',
                    implode(', ', $valueSets)
                );

                $this->db->executeStatement($sql);
            }
        }

        $this->db->commit();
        return true;
    } catch (\Exception $e) {
        $this->db->rollBack();
        error_log('MetaVox setGroupfolderFields error: ' . $e->getMessage());
        return false;
    }
}
public function getGroupfolderFileMetadata(int $groupfolderId, int $fileId): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('f.id', 'f.field_name', 'f.field_label', 'f.field_type', 'f.field_options', 'f.is_required', 'f.applies_to_groupfolder', 'v.field_value as value')
               ->from('metavox_gf_fields', 'f')
               ->innerJoin('f', 'metavox_gf_assigns', 'gf', 'f.id = gf.field_id AND gf.groupfolder_id = :groupfolder_id')
               ->leftJoin('f', 'metavox_file_gf_meta', 'v', 'f.field_name = v.field_name AND v.groupfolder_id = :groupfolder_id AND v.file_id = :file_id')
               ->setParameter('groupfolder_id', $groupfolderId)
               ->setParameter('file_id', $fileId)
               ->orderBy('f.sort_order', 'ASC');

            $result = $qb->executeQuery();
            $metadata = [];
            while ($row = $result->fetch()) {
                $metadata[] = [
                    'id' => (int)$row['id'],
                    'field_name' => $row['field_name'],
                    'field_label' => $row['field_label'],
                    'field_type' => $row['field_type'],
                    'field_options' => $row['field_options'] ? json_decode($row['field_options'], true) : [],
                    'is_required' => (bool)$row['is_required'],
                    'applies_to_groupfolder' => (int)($row['applies_to_groupfolder'] ?? 0),
                    'value' => $row['value'],
                ];
            }
            $result->closeCursor();

            error_log('Metavox getGroupfolderFileMetadata: Found ' . count($metadata) . ' fields for file ' . $fileId . ' in groupfolder ' . $groupfolderId);
            return $metadata;
            
        } catch (\Exception $e) {
            error_log('Metavox getGroupfolderFileMetadata error: ' . $e->getMessage());
            error_log('Metavox getGroupfolderFileMetadata error trace: ' . $e->getTraceAsString());
            return [];
        }
    }

public function saveGroupfolderFieldValue(int $groupfolderId, int $fieldId, string $value): bool {
    try {
        // Get field name
        $qb = $this->db->getQueryBuilder();
        $qb->select('field_name')
           ->from('metavox_gf_fields')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($fieldId, IQueryBuilder::PARAM_INT)));
        
        $result = $qb->executeQuery();
        $fieldName = $result->fetchColumn();
        $result->closeCursor();
        
        if (!$fieldName) {
            return false;
        }

        $platform = $this->db->getDatabasePlatform();
        
        if ($platform instanceof \Doctrine\DBAL\Platforms\MySqlPlatform) {
            $sql = "INSERT INTO *PREFIX*metavox_gf_metadata 
                    (groupfolder_id, field_name, field_value, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    field_value = VALUES(field_value), 
                    updated_at = VALUES(updated_at)";
        } else {
            $sql = "INSERT INTO *PREFIX*metavox_gf_metadata 
                    (groupfolder_id, field_name, field_value, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?)
                    ON CONFLICT (groupfolder_id, field_name) 
                    DO UPDATE SET 
                    field_value = EXCLUDED.field_value, 
                    updated_at = EXCLUDED.updated_at";
        }
        
        $now = date('Y-m-d H:i:s');
        $this->db->executeStatement($sql, [
            $groupfolderId, 
            $fieldName, 
            $value, 
            $now,
            $now
        ]);
        
        return true;
        
    } catch (\Exception $e) {
        error_log('MetaVox saveGroupfolderFieldValue error: ' . $e->getMessage());
        return false;
    }
}


/**
 * Get file metadata for multiple files at once
 * @param array $fileIds Array of file IDs
 * @return array 2D array with file_id as key and metadata as value
 */
public function getBulkFileMetadata(array $fileIds): array {
    if (empty($fileIds)) {
        return [];
    }

    try {
        // Only groupfolder file metadata supported now
        $qb = $this->db->getQueryBuilder();
        $qb->select('f.id', 'f.field_name', 'f.field_label', 'f.field_type',
                    'f.field_options', 'f.is_required', 'f.field_description', 'v.field_value as value', 'v.file_id')
           ->from('metavox_gf_fields', 'f')
           ->leftJoin('f', 'metavox_file_gf_meta', 'v',
                     'f.field_name = v.field_name AND v.file_id IN (:file_ids)')
           ->setParameter('file_ids', $fileIds, IQueryBuilder::PARAM_INT_ARRAY)
           ->orderBy('v.file_id', 'ASC')
           ->addOrderBy('f.sort_order', 'ASC');

        $result = $qb->executeQuery();
        $metadataByFile = [];

        // Initialize all file IDs with empty arrays
        foreach ($fileIds as $fileId) {
            $metadataByFile[$fileId] = [];
        }

        // Get all fields first to ensure every file gets all fields
        $allFields = [];
        while ($row = $result->fetch()) {
            if (!isset($allFields[$row['id']])) {
                $allFields[$row['id']] = [
                    'id' => (int)$row['id'],
                    'field_name' => $row['field_name'],
                    'field_label' => $row['field_label'],
                    'field_type' => $row['field_type'],
                    'field_description' => $row['field_description'] ?? '',
                    'field_options' => $row['field_options'] ? json_decode($row['field_options'], true) : [],
                    'is_required' => (bool)$row['is_required'],
                ];
            }

            if ($row['file_id'] !== null) {
                $fileId = (int)$row['file_id'];
                $metadataByFile[$fileId][] = array_merge($allFields[$row['id']], [
                    'value' => $row['value']
                ]);
            }
        }
        $result->closeCursor();

        // Add fields with null values for files that don't have metadata yet
        foreach ($fileIds as $fileId) {
            if (empty($metadataByFile[$fileId])) {
                foreach ($allFields as $field) {
                    $metadataByFile[$fileId][] = array_merge($field, ['value' => null]);
                }
            }
        }

        return $metadataByFile;

    } catch (\Exception $e) {
        return [];
    }
}

public function saveGroupfolderFileFieldValue(int $groupfolderId, int $fileId, int $fieldId, string $value): bool {
    try {
        // Get field name
        $qb = $this->db->getQueryBuilder();
        $qb->select('field_name')
           ->from('metavox_gf_fields')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($fieldId, IQueryBuilder::PARAM_INT)));
        
        $result = $qb->executeQuery();
        $fieldName = $result->fetchColumn();
        $result->closeCursor();
        
        if (!$fieldName) {
            return false;
        }

        $platform = $this->db->getDatabasePlatform();
        
        if ($platform instanceof \Doctrine\DBAL\Platforms\MySqlPlatform) {
            $sql = "INSERT INTO *PREFIX*metavox_file_gf_meta 
                    (file_id, groupfolder_id, field_name, field_value, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    field_value = VALUES(field_value), 
                    updated_at = VALUES(updated_at)";
        } else {
            $sql = "INSERT INTO *PREFIX*metavox_file_gf_meta 
                    (file_id, groupfolder_id, field_name, field_value, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON CONFLICT (file_id, groupfolder_id, field_name) 
                    DO UPDATE SET 
                    field_value = EXCLUDED.field_value, 
                    updated_at = EXCLUDED.updated_at";
        }
        
        $now = date('Y-m-d H:i:s');
        $this->db->executeStatement($sql, [
            $fileId,
            $groupfolderId, 
            $fieldName, 
            $value, 
            $now,
            $now
        ]);
        
        $this->queueSearchIndexUpdate($fileId);
        return true;
        
    } catch (\Exception $e) {
        error_log('MetaVox saveGroupfolderFileFieldValue error: ' . $e->getMessage());
        return false;
    }
}

private function queueSearchIndexUpdate(int $fileId): void {
    try {
        \OC::$server->getJobList()->add(
            \OCA\MetaVox\BackgroundJobs\UpdateSearchIndex::class,
            ['file_id' => $fileId]
        );
        error_log('Metavox: Queued search index update for file ID: ' . $fileId);
    } catch (\Exception $e) {
        error_log('MetaVox: Failed to queue search index update: ' . $e->getMessage());
    }
}

/**
 * Clear all metadata for a file in a groupfolder
 */
public function clearGroupfolderFileMetadata(int $groupfolderId, int $fileId): bool {
    try {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('metavox_file_gf_meta')
           ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();

        // Also clear from search index
        $this->clearSearchIndex($fileId);

        return true;
    } catch (\Exception $e) {
        error_log('MetaVox clearGroupfolderFileMetadata error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Clear search index for a file
 */
private function clearSearchIndex(int $fileId): void {
    try {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('metavox_search_index')
           ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    } catch (\Exception $e) {
        error_log('MetaVox clearSearchIndex error: ' . $e->getMessage());
    }
}

/**
 * Get groupfolder ID by file ID from metadata records
 * Used by Flow checks to auto-detect groupfolder context
 */
public function getGroupfolderIdByFileId(int $fileId): ?int {
    try {
        $qb = $this->db->getQueryBuilder();
        $qb->select('groupfolder_id')
           ->from('metavox_file_gf_meta')
           ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
           ->setMaxResults(1);

        $result = $qb->executeQuery();
        $groupfolderId = $result->fetchOne();
        $result->closeCursor();

        if ($groupfolderId !== false && $groupfolderId !== null) {
            return (int)$groupfolderId;
        }

        return null;
    } catch (\Exception $e) {
        error_log('MetaVox getGroupfolderIdByFileId error: ' . $e->getMessage());
        return null;
    }
}
}