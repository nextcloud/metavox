<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCA\MetaVox\L10n\Translatable;
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

    // Who may manage a team folder. File metadata follows NC file permissions.
    const PERM_MANAGE_FIELDS = 'manage_fields';
    // Whose grant it is.
    public const HOLDER_USER = 'user';
    public const HOLDER_GROUP = 'group';

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
    public function hasPermission(string $userId, string $permissionType, ?int $groupfolderId = null): bool {
        // In-request cache to avoid repeated DB queries for the same check
        $cacheKey = $userId . ':' . $permissionType . ':' . ($groupfolderId ?? 'null');
        if (isset($this->permissionCache[$cacheKey])) {
            return $this->permissionCache[$cacheKey];
        }

        // Admin users always have all permissions
        if ($this->groupManager->isAdmin($userId)) {
            $this->permissionCache[$cacheKey] = true;
            return true;
        }

        // Check user-specific permissions
        if ($this->checkUserPermission($userId, $permissionType, $groupfolderId)) {
            $this->permissionCache[$cacheKey] = true;
            return true;
        }

        // Check group-based permissions (single query for all groups)
        $userGroups = $this->groupManager->getUserGroupIds(
            $this->userManager->get($userId)
        );

        if (!empty($userGroups)) {
            if ($this->checkGroupPermissions($userGroups, $permissionType, $groupfolderId)) {
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
    private function checkUserPermission(string $userId, string $permissionType, ?int $groupfolderId): bool {
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

        $result = $qb->executeQuery();
        $exists = $result->fetchOne();
        $result->closeCursor();

        return (bool)$exists;
    }

    /**
     * Check group-based permission for multiple groups in a single query
     */
    private function checkGroupPermissions(array $groupIds, string $permissionType, ?int $groupfolderId): bool {
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

        $result = $qb->executeQuery();
        $exists = $result->fetchOne();
        $result->closeCursor();

        return (bool)$exists;
    }

    /**
     * Grant the permission to an account, for one team folder or, with none,
     * for all. Errors are thrown.
     *
     * @throws ApiValidationException for an account or team folder that does not exist
     */
    public function grantUserPermission(string $userId, string $permissionType, ?int $groupfolderId = null): void {
        $refused = $this->refusedHolder('user_id', $this->userManager->userExists($userId), $groupfolderId);
        if ($refused !== []) {
            throw new ApiValidationException($refused);
        }
        if (!$this->checkUserPermission($userId, $permissionType, $groupfolderId)) {
            $this->insertGrant('user_id', $userId, $permissionType, $groupfolderId);
        }
    }

    /**
     * Grant the permission to a group, for one team folder or, with none, for
     * all. Errors are thrown.
     *
     * @throws ApiValidationException for a group or team folder that does not exist
     */
    public function grantGroupPermission(string $groupId, string $permissionType, ?int $groupfolderId = null): void {
        $refused = $this->refusedHolder('group_id', $this->groupManager->groupExists($groupId), $groupfolderId);
        if ($refused !== []) {
            throw new ApiValidationException($refused);
        }
        if (!$this->checkGroupPermissions([$groupId], $permissionType, $groupfolderId)) {
            $this->insertGrant('group_id', $groupId, $permissionType, $groupfolderId);
        }
    }

    /** @return array<string, string> what does not exist, by parameter */
    private function refusedHolder(string $holderKey, bool $holderExists, ?int $groupfolderId): array {
        $refused = [];
        if (!$holderExists) {
            $refused[$holderKey] = $holderKey === 'user_id'
                ? Translatable::t('Choose an existing account')
                // TRANSLATORS Error when the manage permission is given to a group that does not exist
                : Translatable::t('Choose an existing group');
        }
        if ($groupfolderId !== null && !$this->teamFolderExists($groupfolderId)) {
            // TRANSLATORS Error when the manage permission is given for a Team folder that does not exist
            $refused['groupfolder_id'] = Translatable::t('Choose an existing Team folder');
        }
        return $refused;
    }

    private function teamFolderExists(int $groupfolderId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('folder_id')
           ->from('group_folders')
           ->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $exists = $result->fetchOne() !== false;
        $result->closeCursor();
        return $exists;
    }

    private function insertGrant(string $holderKey, string $holder, string $permissionType, ?int $groupfolderId): void {
        $now = date('Y-m-d H:i:s');
        $qb = $this->db->getQueryBuilder();
        $qb->insert('metavox_permissions')
           ->values([
               $holderKey => $qb->createNamedParameter($holder),
               'permission_type' => $qb->createNamedParameter($permissionType),
               'groupfolder_id' => $groupfolderId !== null
                   ? $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)
                   : $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
               'created_at' => $qb->createNamedParameter($now),
               'updated_at' => $qb->createNamedParameter($now),
           ]);
        $qb->executeStatement();
        // A grant changes what the cached checks answered.
        $this->permissionCache = [];
    }

    /**
     * Revoke one grant by its id. $holder says whose it must be (user or
     * group), so a user grant can't be removed through the group route.
     */
    public function revokePermission(int $id, string $holder): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('metavox_permissions')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->isNotNull($holder === self::HOLDER_GROUP ? 'group_id' : 'user_id'));
        return $qb->executeStatement() > 0;
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
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ];
        }
        $result->closeCursor();

        return $permissions;
    }

}