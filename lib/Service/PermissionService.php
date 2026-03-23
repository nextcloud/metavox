<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

class PermissionService {

    private IDBConnection $db;
    private IUserManager $userManager;
    private IGroupManager $groupManager;
    private LoggerInterface $logger;

    /** @var array<string, bool> In-request permission cache */
    private array $permissionCache = [];

    // Permission types
    const PERM_VIEW_METADATA = 'view_metadata';
    const PERM_EDIT_METADATA = 'edit_metadata';
    const PERM_MANAGE_FIELDS = 'manage_fields';

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
     * Check if user has a specific permission
     */
    public function hasPermission(
        string $userId,
        string $permissionType,
        ?int $groupfolderId = null,
        ?string $fieldScope = null
    ): bool {
        // In-request cache to avoid repeated DB queries for the same check
        $cacheKey = $userId . ':' . $permissionType . ':' . ($groupfolderId ?? 'null') . ':' . ($fieldScope ?? 'null');
        if (isset($this->permissionCache[$cacheKey])) {
            return $this->permissionCache[$cacheKey];
        }

        // Admin users always have all permissions
        if ($this->groupManager->isAdmin($userId)) {
            $this->permissionCache[$cacheKey] = true;
            return true;
        }

        // Check user-specific permissions
        if ($this->checkUserPermission($userId, $permissionType, $groupfolderId, $fieldScope)) {
            $this->permissionCache[$cacheKey] = true;
            return true;
        }

        // Check group-based permissions (single query for all groups)
        $userGroups = $this->groupManager->getUserGroupIds(
            $this->userManager->get($userId)
        );

        if (!empty($userGroups)) {
            if ($this->checkGroupPermissions($userGroups, $permissionType, $groupfolderId, $fieldScope)) {
                $this->permissionCache[$cacheKey] = true;
                return true;
            }
        }

        $this->permissionCache[$cacheKey] = false;
        return false;
    }

    /**
     * Check user-specific permission
     */
    private function checkUserPermission(
        string $userId,
        string $permissionType,
        ?int $groupfolderId,
        ?string $fieldScope
    ): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('metavox_permissions')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('permission_type', $qb->createNamedParameter($permissionType)));

        // Check for specific groupfolder or global permission
        if ($groupfolderId !== null) {
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)),
                $qb->expr()->isNull('groupfolder_id')
            ));
        } else {
            $qb->andWhere($qb->expr()->isNull('groupfolder_id'));
        }

        // Check field scope if specified
        if ($fieldScope !== null) {
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->eq('field_scope', $qb->createNamedParameter($fieldScope)),
                $qb->expr()->isNull('field_scope')
            ));
        }

        $result = $qb->executeQuery();
        $exists = $result->fetchOne();
        $result->closeCursor();

        return (bool)$exists;
    }

    /**
     * Check group-based permission for a single group
     */
    private function checkGroupPermission(
        string $groupId,
        string $permissionType,
        ?int $groupfolderId,
        ?string $fieldScope
    ): bool {
        return $this->checkGroupPermissions([$groupId], $permissionType, $groupfolderId, $fieldScope);
    }

    /**
     * Check group-based permission for multiple groups in a single query
     */
    private function checkGroupPermissions(
        array $groupIds,
        string $permissionType,
        ?int $groupfolderId,
        ?string $fieldScope
    ): bool {
        if (empty($groupIds)) {
            return false;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('metavox_permissions')
           ->where($qb->expr()->in('group_id', $qb->createNamedParameter($groupIds, IQueryBuilder::PARAM_STR_ARRAY)))
           ->andWhere($qb->expr()->eq('permission_type', $qb->createNamedParameter($permissionType)))
           ->setMaxResults(1);

        if ($groupfolderId !== null) {
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)),
                $qb->expr()->isNull('groupfolder_id')
            ));
        } else {
            $qb->andWhere($qb->expr()->isNull('groupfolder_id'));
        }

        if ($fieldScope !== null) {
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->eq('field_scope', $qb->createNamedParameter($fieldScope)),
                $qb->expr()->isNull('field_scope')
            ));
        }

        $result = $qb->executeQuery();
        $exists = $result->fetchOne();
        $result->closeCursor();

        return (bool)$exists;
    }

    /**
     * Grant permission to user
     */
    public function grantUserPermission(
        string $userId,
        string $permissionType,
        ?int $groupfolderId = null,
        ?string $fieldScope = null
    ): bool {
        try {
            // Check if permission already exists
            if ($this->checkUserPermission($userId, $permissionType, $groupfolderId, $fieldScope)) {
                return true; // Already exists
            }

            $qb = $this->db->getQueryBuilder();
            $qb->insert('metavox_permissions')
               ->values([
                   'user_id' => $qb->createNamedParameter($userId),
                   'permission_type' => $qb->createNamedParameter($permissionType),
                   'groupfolder_id' => $groupfolderId !== null 
                       ? $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)
                       : $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
                   'field_scope' => $fieldScope !== null
                       ? $qb->createNamedParameter($fieldScope)
                       : $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
                   'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
                   'updated_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
               ]);

            return $qb->executeStatement() > 0;
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: grantUserPermission failed', ['exception' => $e, 'userId' => $userId, 'permissionType' => $permissionType]);
            return false;
        }
    }

    /**
     * Grant permission to group
     */
    public function grantGroupPermission(
        string $groupId,
        string $permissionType,
        ?int $groupfolderId = null,
        ?string $fieldScope = null
    ): bool {
        try {
            // Check if permission already exists
            if ($this->checkGroupPermission($groupId, $permissionType, $groupfolderId, $fieldScope)) {
                return true; // Already exists
            }

            $qb = $this->db->getQueryBuilder();
            $qb->insert('metavox_permissions')
               ->values([
                   'group_id' => $qb->createNamedParameter($groupId),
                   'permission_type' => $qb->createNamedParameter($permissionType),
                   'groupfolder_id' => $groupfolderId !== null 
                       ? $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)
                       : $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
                   'field_scope' => $fieldScope !== null
                       ? $qb->createNamedParameter($fieldScope)
                       : $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
                   'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
                   'updated_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
               ]);

            return $qb->executeStatement() > 0;
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: grantGroupPermission failed', ['exception' => $e, 'groupId' => $groupId, 'permissionType' => $permissionType]);
            return false;
        }
    }

    /**
     * Revoke user permission
     */
    public function revokeUserPermission(
        string $userId,
        string $permissionType,
        ?int $groupfolderId = null,
        ?string $fieldScope = null
    ): bool {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('metavox_permissions')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->eq('permission_type', $qb->createNamedParameter($permissionType)));

            if ($groupfolderId !== null) {
                $qb->andWhere($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)));
            } else {
                $qb->andWhere($qb->expr()->isNull('groupfolder_id'));
            }

            if ($fieldScope !== null) {
                $qb->andWhere($qb->expr()->eq('field_scope', $qb->createNamedParameter($fieldScope)));
            } else {
                $qb->andWhere($qb->expr()->isNull('field_scope'));
            }

            return $qb->executeStatement() > 0;
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: revokeUserPermission failed', ['exception' => $e, 'userId' => $userId, 'permissionType' => $permissionType]);
            return false;
        }
    }

    /**
     * Revoke group permission
     */
    public function revokeGroupPermission(
        string $groupId,
        string $permissionType,
        ?int $groupfolderId = null,
        ?string $fieldScope = null
    ): bool {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('metavox_permissions')
               ->where($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)))
               ->andWhere($qb->expr()->eq('permission_type', $qb->createNamedParameter($permissionType)));

            if ($groupfolderId !== null) {
                $qb->andWhere($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)));
            } else {
                $qb->andWhere($qb->expr()->isNull('groupfolder_id'));
            }

            if ($fieldScope !== null) {
                $qb->andWhere($qb->expr()->eq('field_scope', $qb->createNamedParameter($fieldScope)));
            } else {
                $qb->andWhere($qb->expr()->isNull('field_scope'));
            }

            return $qb->executeStatement() > 0;
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: revokeGroupPermission failed', ['exception' => $e, 'groupId' => $groupId, 'permissionType' => $permissionType]);
            return false;
        }
    }

    /**
     * Get all permissions (for admin interface)
     */
    public function getAllPermissions(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('metavox_permissions')
           ->orderBy('created_at', 'DESC');

        $result = $qb->executeQuery();
        $permissions = [];
        
        while ($row = $result->fetch()) {
            $permissions[] = [
                'id' => (int)$row['id'],
                'user_id' => $row['user_id'],
                'group_id' => $row['group_id'],
                'permission_type' => $row['permission_type'],
                'groupfolder_id' => $row['groupfolder_id'] ? (int)$row['groupfolder_id'] : null,
                'field_scope' => $row['field_scope'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ];
        }
        $result->closeCursor();

        return $permissions;
    }

    /**
     * Get user's permissions
     */
    public function getUserPermissions(string $userId): array {
        // Get direct user permissions
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('metavox_permissions')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->orderBy('created_at', 'DESC');

        $result = $qb->executeQuery();
        $permissions = [];
        
        while ($row = $result->fetch()) {
            $permissions[] = [
                'id' => (int)$row['id'],
                'source' => 'user',
                'permission_type' => $row['permission_type'],
                'groupfolder_id' => $row['groupfolder_id'] ? (int)$row['groupfolder_id'] : null,
                'field_scope' => $row['field_scope'],
            ];
        }
        $result->closeCursor();

        // Get group permissions (single query for all groups)
        $userGroups = $this->groupManager->getUserGroupIds(
            $this->userManager->get($userId)
        );

        if (!empty($userGroups)) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('metavox_permissions')
               ->where($qb->expr()->in('group_id', $qb->createNamedParameter($userGroups, IQueryBuilder::PARAM_STR_ARRAY)));

            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $permissions[] = [
                    'id' => (int)$row['id'],
                    'source' => 'group',
                    'group_id' => $row['group_id'],
                    'permission_type' => $row['permission_type'],
                    'groupfolder_id' => $row['groupfolder_id'] ? (int)$row['groupfolder_id'] : null,
                    'field_scope' => $row['field_scope'],
                ];
            }
            $result->closeCursor();
        }

        return $permissions;
    }
}