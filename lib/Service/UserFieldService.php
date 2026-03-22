<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use OCP\IUserManager;

class UserFieldService {

    private IDBConnection $db;
    private IUserManager $userManager;
    private IGroupManager $groupManager;
    private LoggerInterface $logger;

    public function __construct(
        IDBConnection $db,
        IUserManager $userManager,
        IGroupManager $groupManager,
        LoggerInterface $logger
    ) {
        $this->db = $db;
        $this->userManager = $userManager;
        $this->groupManager = $groupManager;
        $this->logger = $logger;
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
            return [];
        }

        // Use the groupfolders app's FolderManager — handles both groups and circles/teams
        try {
            $folderManager = \OC::$server->get(\OCA\GroupFolders\Folder\FolderManager::class);
            $gfFolders = $folderManager->getFoldersForUser($user);

            $folders = [];
            foreach ($gfFolders as $gfFolder) {
                $folders[] = [
                    'id' => $gfFolder->id,
                    'mount_point' => $gfFolder->mountPoint,
                    'groups' => [],
                    'quota' => $gfFolder->quota,
                    'size' => 0,
                    'acl' => $gfFolder->acl,
                ];
            }

            return $folders;
        } catch (\Exception $e) {
            // FolderManager not available, fall back to direct DB query
        }

        // Fallback: single JOIN query (does not support circles/teams)
        $userGroups = $this->groupManager->getUserGroupIds($user);

        if (empty($userGroups)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('f.*', 'g.group_id')
           ->from('group_folders', 'f')
           ->innerJoin('f', 'group_folders_groups', 'g',
               $qb->expr()->eq('f.folder_id', 'g.folder_id'))
           ->where($qb->expr()->in('g.group_id', $qb->createNamedParameter($userGroups, IQueryBuilder::PARAM_STR_ARRAY)));

        $result = $qb->executeQuery();
        $foldersMap = [];

        while ($row = $result->fetch()) {
            $folderId = (int)($row['folder_id'] ?? $row['id'] ?? 0);
            if (!isset($foldersMap[$folderId])) {
                $foldersMap[$folderId] = [
                    'id' => $folderId,
                    'mount_point' => $row['mount_point'] ?? 'Unknown',
                    'groups' => [],
                    'quota' => (int)($row['quota'] ?? -3),
                    'size' => (int)($row['size'] ?? 0),
                    'acl' => (bool)($row['acl'] ?? false),
                ];
            }
            $foldersMap[$folderId]['groups'][] = $row['group_id'];
        }
        $result->closeCursor();

        return array_values($foldersMap);

    } catch (\Exception $e) {
        $this->logger->error('MetaVox: getAccessibleGroupfolders failed', ['exception' => $e, 'userId' => $userId]);
        return [];
    }
}

/**
 * Check if a user has access to a specific groupfolder.
 */
public function hasAccessToGroupfolder(string $userId, int $groupfolderId): bool {
    if ($this->groupManager->isAdmin($userId)) {
        return true;
    }

    $folders = $this->getAccessibleGroupfolders($userId);
    foreach ($folders as $folder) {
        if ((int)$folder['id'] === $groupfolderId) {
            return true;
        }
    }
    return false;
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

            $result = $qb->executeQuery();
            $fieldIds = [];
            while ($row = $result->fetch()) {
                $fieldIds[] = (int)$row['field_id'];
            }
            $result->closeCursor();

            return $fieldIds;
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: getGroupfolderFields failed', ['exception' => $e, 'groupfolderId' => $groupfolderId]);
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

            $result = $qb->executeQuery();
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
            $this->logger->error('MetaVox: getGroupfolderMetadata failed', ['exception' => $e, 'groupfolderId' => $groupfolderId]);
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
            
            $result = $qb->executeQuery();
            $fieldMap = [];
            while ($row = $result->fetch()) {
                $fieldMap[$row['field_name']] = (int)$row['id'];
            }
            $result->closeCursor();

            $platform = $this->db->getDatabasePlatform();
            $now = date('Y-m-d H:i:s');

            foreach ($metadata as $fieldName => $value) {
                if (!isset($fieldMap[$fieldName])) {
                    continue;
                }

                // UPSERT: single query instead of SELECT + INSERT/UPDATE
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

                $this->db->executeStatement($sql, [
                    $groupfolderId,
                    $fieldName,
                    (string)$value,
                    $now,
                    $now,
                ]);
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: saveGroupfolderMetadata failed', ['exception' => $e, 'groupfolderId' => $groupfolderId]);
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
            $qb->executeStatement();

            // Insert new assignments
            foreach ($fieldIds as $fieldId) {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('metavox_gf_assigns')
                   ->values([
                       'groupfolder_id' => $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT),
                       'field_id' => $qb->createNamedParameter($fieldId, IQueryBuilder::PARAM_INT),
                       'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
                   ]);
                $qb->executeStatement();
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: setGroupfolderFields failed', ['exception' => $e, 'groupfolderId' => $groupfolderId]);
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
            $this->logger->error('MetaVox: getAllGroupfolderFields failed', ['exception' => $e]);
            return [];
        }
    }
}