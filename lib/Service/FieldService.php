<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class FieldService {

    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function getAllFields(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('metavox_fields')
           ->where($qb->expr()->orX(
               $qb->expr()->isNull('scope'),
               $qb->expr()->eq('scope', $qb->createNamedParameter('global'))
           ))
           ->orderBy('sort_order', 'ASC');

        $result = $qb->execute();
        $fields = [];
        while ($row = $result->fetch()) {
            $fields[] = [
                'id' => (int)$row['id'],
                'field_name' => $row['field_name'],
                'field_label' => $row['field_label'],
                'field_type' => $row['field_type'],
                'field_options' => $row['field_options'] ? json_decode($row['field_options'], true) : [],
                'is_required' => (bool)$row['is_required'],
                'sort_order' => (int)$row['sort_order'],
                'scope' => $row['scope'] ?? 'global',
            ];
        }
        $result->closeCursor();

        return $fields;
    }

    // Get single field by ID (for edit modal)
    public function getFieldById(int $id): ?array {
        try {
            error_log('MetaVox getFieldById called with ID: ' . $id);
            
            // Try metavox_fields first (global fields)
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('metavox_fields')
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
            
            $result = $qb->execute();
            $row = $result->fetch();
            $result->closeCursor();
            
            if ($row) {
                error_log('MetaVox getFieldById: Found global field');
                return [
                    'id' => (int)$row['id'],
                    'field_name' => $row['field_name'],
                    'field_label' => $row['field_label'],
                    'field_type' => $row['field_type'],
                    'field_options' => $row['field_options'] ? json_decode($row['field_options'], true) : [],
                    'is_required' => (bool)$row['is_required'],
                    'sort_order' => (int)$row['sort_order'],
                    'scope' => $row['scope'] ?? 'global',
                ];
            }
            
            // Try metavox_gf_fields (groupfolder fields)
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('metavox_gf_fields')
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
            
            $result = $qb->execute();
            $row = $result->fetch();
            $result->closeCursor();
            
            if ($row) {
                error_log('MetaVox getFieldById: Found groupfolder field');
                return [
                    'id' => (int)$row['id'],
                    'field_name' => $row['field_name'],
                    'field_label' => $row['field_label'],
                    'field_type' => $row['field_type'],
                    'field_options' => $row['field_options'] ? json_decode($row['field_options'], true) : [],
                    'is_required' => (bool)$row['is_required'],
                    'sort_order' => (int)$row['sort_order'],
                    'scope' => 'groupfolder',
                    'applies_to_groupfolder' => isset($row['applies_to_groupfolder']) ? (int)$row['applies_to_groupfolder'] : 0,
                ];
            }
            
            error_log('MetaVox getFieldById: Field not found with ID: ' . $id);
            return null;
            
        } catch (\Exception $e) {
            error_log('MetaVox getFieldById error: ' . $e->getMessage());
            return null;
        }
    }

    public function getFieldsByScope(string $scope = 'global'): array {
        $qb = $this->db->getQueryBuilder();
        
        // Check which table to use based on scope
        $tableName = $scope === 'groupfolder' ? 'metavox_gf_fields' : 'metavox_fields';
        
        $qb->select('*')
           ->from($tableName)
           ->orderBy('sort_order', 'ASC');
           
        // Only add scope filter for metavox_fields table
        if ($tableName === 'metavox_fields') {
            $qb->where($qb->expr()->eq('scope', $qb->createNamedParameter($scope)));
        }

        $result = $qb->execute();
        $fields = [];
        while ($row = $result->fetch()) {
            $fieldData = [
                'id' => (int)$row['id'],
                'field_name' => $row['field_name'],
                'field_label' => $row['field_label'],
                'field_type' => $row['field_type'],
                'field_options' => $row['field_options'] ? json_decode($row['field_options'], true) : [],
                'is_required' => (bool)$row['is_required'],
                'sort_order' => (int)$row['sort_order'],
                'scope' => $scope,
            ];
            
            // Add applies_to_groupfolder if it exists in the row
            if (isset($row['applies_to_groupfolder'])) {
                $fieldData['applies_to_groupfolder'] = (int)$row['applies_to_groupfolder'];
            } else {
                $fieldData['applies_to_groupfolder'] = 0; // Default value
            }
            
            $fields[] = $fieldData;
        }
        $result->closeCursor();

        return $fields;
    }

    public function getAllFieldsForGroupfolderConfig(): array {
        return $this->getFieldsByScope('groupfolder');
    }

    // Universal update method that handles both table types
    public function updateField(int $id, array $fieldData): bool {
        try {
            error_log('MetaVox updateField called with ID: ' . $id . ', data: ' . json_encode($fieldData));
            
            // First determine which table this field is in
            $isGroupfolderField = false;
            
            // Check metavox_fields first
            $qb = $this->db->getQueryBuilder();
            $qb->select('id')
               ->from('metavox_fields')
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
            
            $result = $qb->execute();
            $exists = $result->fetchColumn();
            $result->closeCursor();
            
            if (!$exists) {
                // Check metavox_gf_fields
                $qb = $this->db->getQueryBuilder();
                $qb->select('id')
                   ->from('metavox_gf_fields')
                   ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
                
                $result = $qb->execute();
                $exists = $result->fetchColumn();
                $result->closeCursor();
                
                if ($exists) {
                    $isGroupfolderField = true;
                } else {
                    error_log('MetaVox updateField: Field not found with ID: ' . $id);
                    return false;
                }
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
            
            // Update the appropriate table
            $tableName = $isGroupfolderField ? 'metavox_gf_fields' : 'metavox_fields';
            
            $qb = $this->db->getQueryBuilder();
            $qb->update($tableName)
               ->set('field_name', $qb->createNamedParameter($fieldData['field_name']))
               ->set('field_label', $qb->createNamedParameter($fieldData['field_label']))
               ->set('field_type', $qb->createNamedParameter($fieldData['field_type']))
               ->set('field_options', $qb->createNamedParameter(json_encode(array_filter(explode("\n", $fieldOptions)))))
               ->set('is_required', $qb->createNamedParameter($fieldData['is_required'] ? 1 : 0, IQueryBuilder::PARAM_INT))
               ->set('sort_order', $qb->createNamedParameter($fieldData['sort_order'] ?? 0, IQueryBuilder::PARAM_INT))
               ->set('updated_at', $qb->createNamedParameter(date('Y-m-d H:i:s')))
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
            
            // Add applies_to_groupfolder for groupfolder fields if it exists
            if ($isGroupfolderField && isset($fieldData['applies_to_groupfolder'])) {
                try {
                    $qb->set('applies_to_groupfolder', $qb->createNamedParameter($fieldData['applies_to_groupfolder'], IQueryBuilder::PARAM_INT));
                } catch (\Exception $e) {
                    error_log('MetaVox updateField: applies_to_groupfolder column does not exist, skipping...');
                }
            }

            $result = $qb->execute() > 0;
            error_log('MetaVox updateField: Update result: ' . ($result ? 'success' : 'failed') . ' (table: ' . $tableName . ')');
            
            return $result;
            
        } catch (\Exception $e) {
            error_log('MetaVox updateField error: ' . $e->getMessage());
            error_log('MetaVox updateField error trace: ' . $e->getTraceAsString());
            return false;
        }
    }
public function createField(array $fieldData): int {
        try {
            error_log('Metavox createField called with: ' . json_encode($fieldData));
            
            $scope = $fieldData['scope'] ?? 'global';
            
            if ($scope === 'groupfolder') {
                // Use metavox_gf_fields table for groupfolder fields
                return $this->createGroupfolderField($fieldData);
            } else {
                // Use metavox_fields table for global fields
                $qb = $this->db->getQueryBuilder();
                $qb->insert('metavox_fields')
                   ->values([
                       'field_name' => $qb->createNamedParameter($fieldData['field_name']),
                       'field_label' => $qb->createNamedParameter($fieldData['field_label']),
                       'field_type' => $qb->createNamedParameter($fieldData['field_type']),
                       'field_options' => $qb->createNamedParameter(json_encode($fieldData['field_options'] ?? [])),
                       'is_required' => $qb->createNamedParameter($fieldData['is_required'] ? 1 : 0, IQueryBuilder::PARAM_INT),
                       'sort_order' => $qb->createNamedParameter($fieldData['sort_order'] ?? 0, IQueryBuilder::PARAM_INT),
                       'scope' => $qb->createNamedParameter($scope),
                       'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
                       'updated_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
                   ]);

                $result = $qb->execute();
                $insertId = (int) $this->db->lastInsertId('metavox_fields');
                
                error_log('Metavox createField (global) success: ' . $insertId);
                return $insertId;
            }
            
        } catch (\Exception $e) {
            error_log('Metavox createField error: ' . $e->getMessage());
            error_log('Metavox createField error trace: ' . $e->getTraceAsString());
            throw $e;
        }
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
            
            $result = $qb->execute();
            $existingId = $result->fetchColumn();
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
                $qb->execute()->closeCursor();
            } catch (\Exception $e) {
                error_log('Metavox: applies_to_groupfolder column does not exist yet, skipping...');
                $useAppliesToGroupfolder = false;
            }
            
            $qb = $this->db->getQueryBuilder();
            $values = [
                'field_name' => $qb->createNamedParameter($fieldData['field_name']),
                'field_label' => $qb->createNamedParameter($fieldData['field_label']),
                'field_type' => $qb->createNamedParameter($fieldData['field_type']),
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

            $result = $qb->execute();
            $insertId = (int) $this->db->lastInsertId('metavox_gf_fields');
            
            error_log('Metavox createGroupfolderField success: ' . $insertId);
            return $insertId;
            
        } catch (\Exception $e) {
            error_log('Metavox createGroupfolderField error: ' . $e->getMessage());
            error_log('Metavox createGroupfolderField error trace: ' . $e->getTraceAsString());
            throw $e;
        }
    }

    public function deleteField(int $id): bool {
        try {
            error_log('Metavox deleteField called with ID: ' . $id);
            
            // First determine which table this field is in by trying both
            $fieldName = null;
            $isGroupfolderField = false;
            
            // Try metavox_fields first
            $qb = $this->db->getQueryBuilder();
            $qb->select('field_name')
               ->from('metavox_fields')
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
            
            $result = $qb->execute();
            $fieldName = $result->fetchColumn();
            $result->closeCursor();
            
            if (!$fieldName) {
                // Try metavox_gf_fields
                $qb = $this->db->getQueryBuilder();
                $qb->select('field_name')
                   ->from('metavox_gf_fields')
                   ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
                
                $result = $qb->execute();
                $fieldName = $result->fetchColumn();
                $result->closeCursor();
                $isGroupfolderField = true;
            }
            
            if (!$fieldName) {
                error_log('Metavox deleteField: Field not found with ID: ' . $id);
                return false;
            }
            
            error_log('Metavox deleteField: Deleting field: ' . $fieldName . ' (groupfolder: ' . ($isGroupfolderField ? 'yes' : 'no') . ')');
            
            if ($isGroupfolderField) {
                // Delete groupfolder field and related data
                
                // 1. Delete groupfolder metadata values (using field_name)
                $qb = $this->db->getQueryBuilder();
                $qb->delete('metavox_gf_metadata')
                   ->where($qb->expr()->eq('field_name', $qb->createNamedParameter($fieldName)));
                $deleted1 = $qb->execute();
                error_log('Metavox deleteField: Deleted ' . $deleted1 . ' groupfolder metadata records');

                // 2. Delete file-in-groupfolder metadata (using field_name)
                $qb = $this->db->getQueryBuilder();
                $qb->delete('metavox_file_gf_meta')
                   ->where($qb->expr()->eq('field_name', $qb->createNamedParameter($fieldName)));
                $deleted2 = $qb->execute();
                error_log('Metavox deleteField: Deleted ' . $deleted2 . ' file-gf metadata records');

                // 3. Delete groupfolder field assignments
                $qb = $this->db->getQueryBuilder();
                $qb->delete('metavox_gf_assigns')
                   ->where($qb->expr()->eq('field_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
                $deleted3 = $qb->execute();
                error_log('Metavox deleteField: Deleted ' . $deleted3 . ' groupfolder assignments');

                // 4. Delete field overrides
                $qb = $this->db->getQueryBuilder();
                $qb->delete('metavox_gf_overrides')
                   ->where($qb->expr()->eq('field_name', $qb->createNamedParameter($fieldName)));
                $deleted4 = $qb->execute();
                error_log('Metavox deleteField: Deleted ' . $deleted4 . ' field override records');

                // 5. Delete the groupfolder field itself
                $qb = $this->db->getQueryBuilder();
                $qb->delete('metavox_gf_fields')
                   ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
                
                $deleted = $qb->execute();
                error_log('Metavox deleteField: Groupfolder field deletion result: ' . $deleted);
                
                return $deleted > 0;
                
            } else {
                // Delete global field and related data
                
                // 1. Delete file metadata values
                $qb = $this->db->getQueryBuilder();
                $qb->delete('metavox_metadata')
                   ->where($qb->expr()->eq('field_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
                $deleted1 = $qb->execute();
                error_log('Metavox deleteField: Deleted ' . $deleted1 . ' file metadata records');

                // 2. Delete the global field itself
                $qb = $this->db->getQueryBuilder();
                $qb->delete('metavox_fields')
                   ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
                
                $deleted = $qb->execute();
                error_log('Metavox deleteField: Global field deletion result: ' . $deleted);
                
                return $deleted > 0;
            }
            
        } catch (\Exception $e) {
            error_log('Metavox deleteField error: ' . $e->getMessage());
            error_log('Metavox deleteField error trace: ' . $e->getTraceAsString());
            return false;
        }
    }
public function getFieldMetadata(int $fileId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('f.id', 'f.field_name', 'f.field_label', 'f.field_type', 'f.field_options', 'f.is_required', 'v.value')
           ->from('metavox_fields', 'f')
           ->leftJoin('f', 'metavox_metadata', 'v', 'f.id = v.field_id AND v.file_id = :file_id')
           ->where($qb->expr()->orX(
               $qb->expr()->isNull('f.scope'),
               $qb->expr()->eq('f.scope', $qb->createNamedParameter('global'))
           ))
           ->setParameter('file_id', $fileId)
           ->orderBy('f.sort_order', 'ASC');

        $result = $qb->execute();
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
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('metavox_metadata')
           ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->eq('field_id', $qb->createNamedParameter($fieldId, IQueryBuilder::PARAM_INT)));

        $result = $qb->execute();
        $existingId = $result->fetchColumn();
        $result->closeCursor();

        if ($existingId) {
            $qb = $this->db->getQueryBuilder();
            $qb->update('metavox_metadata')
               ->set('value', $qb->createNamedParameter($value))
               ->set('updated_at', $qb->createNamedParameter(date('Y-m-d H:i:s')))
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($existingId, IQueryBuilder::PARAM_INT)));
        } else {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('metavox_metadata')
               ->values([
                   'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
                   'field_id' => $qb->createNamedParameter($fieldId, IQueryBuilder::PARAM_INT),
                   'value' => $qb->createNamedParameter($value),
                   'updated_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
               ]);
        }

        return $qb->execute() > 0;
    }

    // Groupfolder functionality
    public function getGroupfolders(): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('group_folders');

            $result = $qb->execute();
            $folders = [];
            while ($row = $result->fetch()) {
                $folders[] = [
                    'id' => (int)($row['folder_id'] ?? $row['id'] ?? 0),
                    'mount_point' => $row['mount_point'] ?? 'Unknown',
                    'groups' => isset($row['groups']) ? json_decode($row['groups'], true) : [],
                    'quota' => (int)($row['quota'] ?? -3),
                    'size' => (int)($row['size'] ?? 0),
                    'acl' => (bool)($row['acl'] ?? false),
                ];
            }
            $result->closeCursor();

            return $folders;
        } catch (\Exception $e) {
            error_log('Metavox getGroupfolders error: ' . $e->getMessage());
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

            $result = $qb->execute();
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

    public function saveGroupfolderFieldValue(int $groupfolderId, int $fieldId, string $value): bool {
        try {
            // Get field name first from the correct table
            $qb = $this->db->getQueryBuilder();
            $qb->select('field_name')
               ->from('metavox_gf_fields')
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($fieldId, IQueryBuilder::PARAM_INT)));
            
            $result = $qb->execute();
            $fieldName = $result->fetchColumn();
            $result->closeCursor();
            
            if (!$fieldName) {
                error_log('Metavox saveGroupfolderFieldValue: Field not found with ID: ' . $fieldId);
                return false;
            }

            error_log('Metavox saveGroupfolderFieldValue: Saving field ' . $fieldName . ' for groupfolder ' . $groupfolderId . ' with value: ' . $value);

            // Check if record exists
            $qb = $this->db->getQueryBuilder();
            $qb->select('id')
               ->from('metavox_gf_metadata')
               ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)))
               ->andWhere($qb->expr()->eq('field_name', $qb->createNamedParameter($fieldName)));

            $result = $qb->execute();
            $existingId = $result->fetchColumn();
            $result->closeCursor();

            if ($existingId) {
                $qb = $this->db->getQueryBuilder();
                $qb->update('metavox_gf_metadata')
                   ->set('field_value', $qb->createNamedParameter($value))
                   ->set('updated_at', $qb->createNamedParameter(date('Y-m-d H:i:s')))
                   ->where($qb->expr()->eq('id', $qb->createNamedParameter($existingId, IQueryBuilder::PARAM_INT)));
                
                $result = $qb->execute() > 0;
                error_log('Metavox saveGroupfolderFieldValue: Updated existing record, result: ' . ($result ? 'success' : 'failed'));
                return $result;
            } else {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('metavox_gf_metadata')
                   ->values([
                       'groupfolder_id' => $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT),
                       'field_name' => $qb->createNamedParameter($fieldName),
                       'field_value' => $qb->createNamedParameter($value),
                       'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
                       'updated_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
                   ]);
                
                $result = $qb->execute() > 0;
                error_log('Metavox saveGroupfolderFieldValue: Created new record, result: ' . ($result ? 'success' : 'failed'));
                return $result;
            }
        } catch (\Exception $e) {
            error_log('Metavox saveGroupfolderFieldValue error: ' . $e->getMessage());
            error_log('Metavox saveGroupfolderFieldValue error trace: ' . $e->getTraceAsString());
            return false;
        }
    }

    public function getAssignedFieldsForGroupfolder(int $groupfolderId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('field_id')
           ->from('metavox_gf_assigns')
           ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)));

        $result = $qb->execute();
        $fieldIds = [];
        while ($row = $result->fetch()) {
            $fieldIds[] = (int)$row['field_id'];
        }
        $result->closeCursor();

        return $fieldIds;
    }

    public function setGroupfolderFields(int $groupfolderId, array $fieldIds): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('metavox_gf_assigns')
           ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)));
        $qb->execute();

        foreach ($fieldIds as $fieldId) {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('metavox_gf_assigns')
               ->values([
                   'groupfolder_id' => $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT),
                   'field_id' => $qb->createNamedParameter($fieldId, IQueryBuilder::PARAM_INT),
                   'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
               ]);
            $qb->execute();
        }

        return true;
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

            $result = $qb->execute();
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

    public function saveGroupfolderFileFieldValue(int $groupfolderId, int $fileId, int $fieldId, string $value): bool {
        try {
            // Get field name first from the correct table
            $qb = $this->db->getQueryBuilder();
            $qb->select('field_name')
               ->from('metavox_gf_fields')
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($fieldId, IQueryBuilder::PARAM_INT)));
            
            $result = $qb->execute();
            $fieldName = $result->fetchColumn();
            $result->closeCursor();
            
            if (!$fieldName) {
                error_log('Metavox saveGroupfolderFileFieldValue: Field not found with ID: ' . $fieldId);
                return false;
            }

            error_log('Metavox saveGroupfolderFileFieldValue: Saving field ' . $fieldName . ' for file ' . $fileId . ' in groupfolder ' . $groupfolderId . ' with value: ' . $value);

            // Check if record exists
            $qb = $this->db->getQueryBuilder();
            $qb->select('id')
               ->from('metavox_file_gf_meta')
               ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)))
               ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
               ->andWhere($qb->expr()->eq('field_name', $qb->createNamedParameter($fieldName)));

            $result = $qb->execute();
            $existingId = $result->fetchColumn();
            $result->closeCursor();

            if ($existingId) {
                $qb = $this->db->getQueryBuilder();
                $qb->update('metavox_file_gf_meta')
                   ->set('field_value', $qb->createNamedParameter($value))
                   ->set('updated_at', $qb->createNamedParameter(date('Y-m-d H:i:s')))
                   ->where($qb->expr()->eq('id', $qb->createNamedParameter($existingId, IQueryBuilder::PARAM_INT)));
                
                $result = $qb->execute() > 0;
                error_log('Metavox saveGroupfolderFileFieldValue: Updated existing record, result: ' . ($result ? 'success' : 'failed'));
                return $result;
            } else {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('metavox_file_gf_meta')
                   ->values([
                       'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
                       'groupfolder_id' => $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT),
                       'field_name' => $qb->createNamedParameter($fieldName),
                       'field_value' => $qb->createNamedParameter($value),
                       'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
                       'updated_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
                   ]);
                
                $result = $qb->execute() > 0;
                error_log('Metavox saveGroupfolderFileFieldValue: Created new record, result: ' . ($result ? 'success' : 'failed'));
                return $result;
            }
        } catch (\Exception $e) {
            error_log('Metavox saveGroupfolderFileFieldValue error: ' . $e->getMessage());
            error_log('Metavox saveGroupfolderFileFieldValue error trace: ' . $e->getTraceAsString());
            return false;
        }
    }
/**
     * Save field override for specific groupfolder
     */
    public function saveGroupfolderFieldOverride(int $groupfolderId, string $fieldName, int $appliesToGroupfolder): bool {
        try {
            error_log('Metavox saveGroupfolderFieldOverride: groupfolder=' . $groupfolderId . ', field=' . $fieldName . ', applies=' . $appliesToGroupfolder);
            
            // Check if override already exists
            $qb = $this->db->getQueryBuilder();
            $qb->select('id')
               ->from('metavox_gf_overrides')
               ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)))
               ->andWhere($qb->expr()->eq('field_name', $qb->createNamedParameter($fieldName)));

            $result = $qb->execute();
            $existingId = $result->fetchColumn();
            $result->closeCursor();

            if ($existingId) {
                // Update existing override
                $qb = $this->db->getQueryBuilder();
                $qb->update('metavox_gf_overrides')
                   ->set('applies_to_groupfolder', $qb->createNamedParameter($appliesToGroupfolder, IQueryBuilder::PARAM_INT))
                   ->set('updated_at', $qb->createNamedParameter(date('Y-m-d H:i:s')))
                   ->where($qb->expr()->eq('id', $qb->createNamedParameter($existingId, IQueryBuilder::PARAM_INT)));
                
                $result = $qb->execute() > 0;
                error_log('Metavox saveGroupfolderFieldOverride: Updated existing override, result=' . ($result ? 'success' : 'failed'));
                return $result;
            } else {
                // Create new override
                $qb = $this->db->getQueryBuilder();
                $qb->insert('metavox_gf_overrides')
                   ->values([
                       'groupfolder_id' => $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT),
                       'field_name' => $qb->createNamedParameter($fieldName),
                       'applies_to_groupfolder' => $qb->createNamedParameter($appliesToGroupfolder, IQueryBuilder::PARAM_INT),
                       'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
                       'updated_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
                   ]);
                
                $result = $qb->execute() > 0;
                error_log('Metavox saveGroupfolderFieldOverride: Created new override, result=' . ($result ? 'success' : 'failed'));
                return $result;
            }
        } catch (\Exception $e) {
            error_log('Metavox saveGroupfolderFieldOverride ERROR: ' . $e->getMessage());
            error_log('Metavox saveGroupfolderFieldOverride ERROR trace: ' . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * Get field overrides for specific groupfolder
     */
    public function getGroupfolderFieldOverrides(int $groupfolderId): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('metavox_gf_overrides')
               ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)));

            $result = $qb->execute();
            $overrides = [];
            while ($row = $result->fetch()) {
                $overrides[$row['field_name']] = (int)$row['applies_to_groupfolder'];
            }
            $result->closeCursor();

            return $overrides;
        } catch (\Exception $e) {
            error_log('Metavox getGroupfolderFieldOverrides error: ' . $e->getMessage());
            return [];
        }
    }
}