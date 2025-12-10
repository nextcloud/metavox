<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;

class UserFieldService {

    private IDBConnection $db;
    private IUserManager $userManager;
    private IGroupManager $groupManager;

    public function __construct(
        IDBConnection $db,
        IUserManager $userManager,
        IGroupManager $groupManager
    ) {
        $this->db = $db;
        $this->userManager = $userManager;
        $this->groupManager = $groupManager;
    }

    /**
     * Get groupfolders accessible by current user
     */
/**
 * Get groupfolders accessible by current user
 */
public function getAccessibleGroupfolders(string $userId): array {
    try {
        $user = $this->userManager->get($userId);
        if (!$user) {
            error_log('MetaVox: User not found: ' . $userId);
            return [];
        }
        
        // Check if user is admin - check both admin group and isAdmin()
        $isInAdminGroup = $this->groupManager->isInGroup($userId, 'admin');
        $isAdmin = $this->groupManager->isAdmin($userId);
        
        // Get user's groups
        $userGroups = $this->groupManager->getUserGroupIds($user);
        error_log('MetaVox: User ' . $userId . ' is in groups: ' . json_encode($userGroups));
        error_log('MetaVox: User isInAdminGroup: ' . ($isInAdminGroup ? 'yes' : 'no') . ', isAdmin: ' . ($isAdmin ? 'yes' : 'no'));
        
        // Get all groupfolders with their group access
        $qb = $this->db->getQueryBuilder();
        $qb->select('f.*')
           ->from('group_folders', 'f');

        $result = $qb->execute();
        $folders = [];
        
        while ($row = $result->fetch()) {
            $folderId = (int)($row['folder_id'] ?? $row['id'] ?? 0);
            $mountPoint = $row['mount_point'] ?? 'Unknown';
            
            // Get applicable groups for this folder from group_folders_groups table
            $qb2 = $this->db->getQueryBuilder();
            $qb2->select('group_id', 'permissions')
                ->from('group_folders_groups')
                ->where($qb2->expr()->eq('folder_id', $qb2->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)));
            
            $result2 = $qb2->execute();
            $folderGroups = [];
            
            while ($groupRow = $result2->fetch()) {
                $groupId = $groupRow['group_id'];
                $folderGroups[] = $groupId;
            }
            $result2->closeCursor();
            
            error_log('MetaVox: Groupfolder ' . $folderId . ' (' . $mountPoint . ') has groups: ' . json_encode($folderGroups));
            
            // Check if user has access (user is in at least one of the folder's groups)
            $hasAccess = false;
            foreach ($userGroups as $userGroup) {
                if (in_array($userGroup, $folderGroups)) {
                    $hasAccess = true;
                    error_log('MetaVox: User has access to groupfolder ' . $folderId . ' via group: ' . $userGroup);
                    break;
                }
            }
            
            if ($hasAccess) {
                $folders[] = [
                    'id' => $folderId,
                    'mount_point' => $mountPoint,
                    'groups' => $folderGroups,
                    'quota' => (int)($row['quota'] ?? -3),
                    'size' => (int)($row['size'] ?? 0),
                    'acl' => (bool)($row['acl'] ?? false),
                ];
            } else {
                error_log('MetaVox: User does NOT have access to groupfolder ' . $folderId . ' (not in any of the folder groups: ' . json_encode($folderGroups) . ')');
            }
        }
        $result->closeCursor();

        error_log('MetaVox: User has access to ' . count($folders) . ' groupfolders (out of total checked)');
        return $folders;
        
    } catch (\Exception $e) {
        error_log('MetaVox getAccessibleGroupfolders error: ' . $e->getMessage());
        error_log('MetaVox getAccessibleGroupfolders trace: ' . $e->getTraceAsString());
        return [];
    }
}

    /**
     * Get fields available for a specific groupfolder
     */
    public function getGroupfolderFields(int $groupfolderId): array {
        try {
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
        } catch (\Exception $e) {
            error_log('MetaVox getGroupfolderFields error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get metadata for a groupfolder
     */
    public function getGroupfolderMetadata(int $groupfolderId): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('f.id', 'f.field_name', 'f.field_label', 'f.field_type', 'f.field_description', 'f.field_options', 'f.is_required', 'f.applies_to_groupfolder', 'v.field_value as value')
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
                    'field_description' => $row['field_description'] ?? '',
                    'field_options' => $row['field_options'] ? json_decode($row['field_options'], true) : [],
                    'is_required' => (bool)$row['is_required'],
                    'applies_to_groupfolder' => (int)($row['applies_to_groupfolder'] ?? 0),
                    'value' => $row['value'],
                ];
            }
            $result->closeCursor();

            return $metadata;
            
        } catch (\Exception $e) {
            error_log('MetaVox getGroupfolderMetadata error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Save groupfolder metadata value
     */
    public function saveGroupfolderMetadata(int $groupfolderId, array $metadata): bool {
        try {
            // Get field name to ID mapping
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'field_name')
               ->from('metavox_gf_fields');
            
            $result = $qb->execute();
            $fieldMap = [];
            while ($row = $result->fetch()) {
                $fieldMap[$row['field_name']] = (int)$row['id'];
            }
            $result->closeCursor();

            foreach ($metadata as $fieldName => $value) {
                if (!isset($fieldMap[$fieldName])) {
                    continue;
                }

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
                    // Update
                    $qb = $this->db->getQueryBuilder();
                    $qb->update('metavox_gf_metadata')
                       ->set('field_value', $qb->createNamedParameter((string)$value))
                       ->set('updated_at', $qb->createNamedParameter(date('Y-m-d H:i:s')))
                       ->where($qb->expr()->eq('id', $qb->createNamedParameter($existingId, IQueryBuilder::PARAM_INT)));
                    $qb->execute();
                } else {
                    // Insert
                    $qb = $this->db->getQueryBuilder();
                    $qb->insert('metavox_gf_metadata')
                       ->values([
                           'groupfolder_id' => $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT),
                           'field_name' => $qb->createNamedParameter($fieldName),
                           'field_value' => $qb->createNamedParameter((string)$value),
                           'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
                           'updated_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
                       ]);
                    $qb->execute();
                }
            }

            return true;
        } catch (\Exception $e) {
            error_log('MetaVox saveGroupfolderMetadata error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Set which fields are assigned to a groupfolder
     */
    public function setGroupfolderFields(int $groupfolderId, array $fieldIds): bool {
        try {
            // Delete existing assignments
            $qb = $this->db->getQueryBuilder();
            $qb->delete('metavox_gf_assigns')
               ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)));
            $qb->execute();

            // Insert new assignments
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
        } catch (\Exception $e) {
            error_log('MetaVox setGroupfolderFields error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all groupfolder fields (for field configuration UI)
     */
    public function getAllGroupfolderFields(): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('metavox_gf_fields')
               ->orderBy('sort_order', 'ASC');

            $result = $qb->execute();
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
            error_log('MetaVox getAllGroupfolderFields error: ' . $e->getMessage());
            return [];
        }
    }
}