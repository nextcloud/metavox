<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCA\MetaVox\L10n\Translatable;
use OCA\MetaVox\Validation\FieldDefinition;
use OCA\MetaVox\Validation\FieldValue;
use OCA\MetaVox\Validation\InvalidValue;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\ICacheFactory;
use OCP\ICache;
use Psr\Log\LoggerInterface;

class FieldService {

    /** Types whose value is a ';#' list of items; the grid's filter has the same set (FilterSortCore.js). */
    public const LIST_TYPES = ['multiselect', 'multi_select', ...FileReferenceService::FILELINK_TYPES];

    /** Rows per upsert or IN list when many values are saved at once. */
    private const SAVE_CHUNK = 500;

    /** Cache key, plus the field id: the team folders a field is assigned to. */
    private const FIELD_FOLDERS_KEY = 'field_folders_';

    /** Whether a field of this type holds a ';#' list. */
    public static function holdsList(string $fieldType): bool {
        return \in_array($fieldType, self::LIST_TYPES, true);
    }

    /** The field type by field name; null for a field that doesn't exist. */
    public function fieldType(string $fieldName): ?string {
        $field = $this->fieldByName($fieldName);
        return $field === null ? null : (string)$field['field_type'];
    }

    /**
     * Which of these team folders have the field: a search needs to look in no
     * other. From the cache, else one query; changing assignments clears it.
     *
     * @param list<int|string> $groupfolderIds
     * @return list<int>
     */
    public function groupfoldersWithField(string $fieldName, array $groupfolderIds): array {
        $field = $this->fieldByName($fieldName);
        if ($field === null || $groupfolderIds === []) {
            return [];
        }
        $assigned = $this->foldersOfField((int)$field['id']);
        return array_values(array_filter(array_map('intval', $groupfolderIds), fn (int $id) => isset($assigned[$id])));
    }

    /** @return array<int, true> the team folders the field is assigned to; cached, cleared when that changes */
    public function foldersOfField(int $fieldId): array {
        $key = self::FIELD_FOLDERS_KEY . $fieldId;
        $cached = $this->cache->get($key);
        if (\is_array($cached)) {
            return $cached;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('groupfolder_id')
           ->from('metavox_gf_assigns')
           ->where($qb->expr()->eq('field_id', $qb->createNamedParameter($fieldId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $folders = [];
        while (($id = $result->fetchOne()) !== false) {
            $folders[(int)$id] = true;
        }
        $result->closeCursor();
        $this->cache->set($key, $folders, 600);
        return $folders;
    }

    /** The field definition by field name; null for a field that doesn't exist. */
    public function fieldByName(string $fieldName): ?array {
        foreach ($this->getAllFields() as $field) {
            if ($field['field_name'] === $fieldName) {
                return $field;
            }
        }
        return null;
    }

    private IDBConnection $db;
    private IGroupManager $groupManager;
    private IUserManager $userManager;
    private SearchWordsService $searchWords;
    private ViewService $viewService;
    private PushService $pushService;
    private LoggerInterface $logger;
    private FileReferenceService $fileReferences;
    private MetadataVersions $versions;
    private FieldValue $fieldValues;
    private ICache $cache;

    // Request-scope cache for fields
    private ?array $fieldsCache = null;
    /** @var array<int, array<int, array<string, true>>> team folder => [team folder fields?] => fields it uses */
    private array $usedFields = [];
    private array $fieldsByScopeCache = [];

    // Request-scope cache for groupfolders (avoids repeated DB/API calls within same request)
    private array $groupfoldersCache = [];
    private array $folderAccessCache = [];

    public function __construct(IDBConnection $db, IGroupManager $groupManager, IUserManager $userManager, SearchWordsService $searchWords, ViewService $viewService, PushService $pushService, ICacheFactory $cacheFactory, LoggerInterface $logger, FileReferenceService $fileReferences, MetadataVersions $versions, FieldValue $fieldValues) {
        $this->db = $db;
        $this->groupManager = $groupManager;
        $this->userManager = $userManager;
        $this->searchWords = $searchWords;
        $this->viewService = $viewService;
        $this->pushService = $pushService;
        $this->logger = $logger;
        $this->fileReferences = $fileReferences;
        $this->versions = $versions;
        $this->fieldValues = $fieldValues;
        // Prefer distributed cache (Redis) for cross-process presence & locking.
        // Falls back to local cache (APCu) if distributed is not configured.
        $this->cache = $cacheFactory->createDistributed('metavox')
            ?? $cacheFactory->createLocal('metavox');
    }

/**
 * Parse a field_options TEXT blob.
 *
 * Returns:
 *  - Associative array for date fields, e.g. ['includeTime' => true]
 *  - List of strings for select/multiselect fields, e.g. ['Yes', 'No']
 *  - Empty array if NULL/empty/invalid
 *
 * Call sites gate on field_type before treating the return as a list.
 */
private function parseFieldOptions(?string $raw): array {
    if (empty($raw)) {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return $decoded;
    }
    // json_decode returns a string for JSON strings, or null for invalid JSON
    // In both cases, try splitting on newlines
    $str = is_string($decoded) ? $decoded : $raw;
    return array_values(array_filter(explode("\n", $str), fn($v) => $v !== ''));
}

public function getAllFields(): array {
    // L1: request-scope cache
    if ($this->fieldsCache !== null) {
        return $this->fieldsCache;
    }

    // L2: distributed cache
    $cached = $this->cache->get('fields_all');
    if ($cached !== null) {
        $this->fieldsCache = $cached;
        return $cached;
    }

    // Only groupfolder fields are supported now
    $qb = $this->db->getQueryBuilder();
    $qb->select('*')
       ->from('metavox_gf_fields')
       ->orderBy('sort_order', 'ASC')
       ->addOrderBy('id', 'ASC');

    $result = $qb->executeQuery();
    $fields = [];
    while ($row = $result->fetch()) {
        $fields[] = [
            'id' => (int)$row['id'],
            'field_name' => $row['field_name'],
            'field_label' => $row['field_label'],
            'field_type' => $row['field_type'],
            'field_description' => $row['field_description'] ?? '',
            'field_options' => $this->parseFieldOptions($row['field_options'] ?? null),
            'is_required' => FieldValue::isRequired($row),
            'sort_order' => (int)$row['sort_order'],
            'scope' => 'groupfolder',
            'applies_to_groupfolder' => isset($row['applies_to_groupfolder']) ? (int)$row['applies_to_groupfolder'] : 0,
        ];
    }
    $result->closeCursor();

    $this->cache->set('fields_all', $fields, 600);
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
                'field_options' => $this->parseFieldOptions($row['field_options'] ?? null),
                'is_required' => FieldValue::isRequired($row),
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
       ->orderBy('sort_order', 'ASC')
       ->addOrderBy('id', 'ASC');

    $result = $qb->executeQuery();
    $fields = [];
    while ($row = $result->fetch()) {
        $fieldData = [
            'id' => (int)$row['id'],
            'field_name' => $row['field_name'],
            'field_label' => $row['field_label'],
            'field_type' => $row['field_type'],
            'field_description' => $row['field_description'] ?? '',
            'field_options' => $this->parseFieldOptions($row['field_options'] ?? null),
            'is_required' => FieldValue::isRequired($row),
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

    /**
     * The field definitions a user may see: all of them (null), or only the
     * fields assigned to these team folders.
     *
     * @param int[]|null $groupfolderIds
     */
    public function getCatalogue(?array $groupfolderIds): array {
        $fields = $this->getAllFields();
        if ($groupfolderIds === null) {
            return $fields;
        }
        if ($groupfolderIds === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('field_id')
           ->from('metavox_gf_assigns')
           ->where($qb->expr()->in('groupfolder_id', $qb->createNamedParameter(array_map('intval', $groupfolderIds), IQueryBuilder::PARAM_INT_ARRAY)));
        $result = $qb->executeQuery();
        $assigned = [];
        while (($id = $result->fetchOne()) !== false) {
            $assigned[(int)$id] = true;
        }
        $result->closeCursor();
        return array_values(array_filter($fields, fn (array $field) => isset($assigned[$field['id']])));
    }

    /**
     * Clear field cache - called after create/update/delete operations
     */
    private function clearFieldCache(): void {
        $this->fieldsCache = null;
        $this->fieldsByScopeCache = [];
        $this->cache->remove('fields_all');
    }

    // Universal update method - only groupfolder fields supported now
/**
 * Whether the field is assigned to a team folder that still exists. The admin
 * page calls such a field "in use" and locks its name, required flag and
 * deletion; the API follows the same rules.
 */
public function isFieldInUse(int $id): bool {
    $qb = $this->db->getQueryBuilder();
    $qb->select('a.field_id')
       ->from('metavox_gf_assigns', 'a')
       ->innerJoin('a', 'group_folders', 'g', $qb->expr()->eq('g.folder_id', 'a.groupfolder_id'))
       ->where($qb->expr()->eq('a.field_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
       ->setMaxResults(1);
    $result = $qb->executeQuery();
    $inUse = $result->fetchOne() !== false;
    $result->closeCursor();
    return $inUse;
}

/**
 * Changes a field: only what was sent, checked as FieldDefinition says. False
 * when there is no such field; a refused change throws, a database error too.
 *
 * @param array<string, mixed> $sent the parameters the request carries
 * @throws ApiValidationException
 */
public function updateField(int $id, array $sent): bool {
    $qb = $this->db->getQueryBuilder();
    $qb->select('*')
       ->from('metavox_gf_fields')
       ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
    $result = $qb->executeQuery();
    $current = $result->fetch();
    $result->closeCursor();
    if (!$current) {
        return false;
    }

    $field = FieldDefinition::stored($sent, $current);

    // Values hang on the name and are stored in the form of the type: a field
    // in use keeps both. Its required flag may change: files without a value
    // then show as missing one.
    $renamed = $field['field_name'] !== (string)$current['field_name'];
    $retyped = $field['field_type'] !== (FieldDefinition::type($current['field_type']) ?? (string)$current['field_type']);
    if (($renamed || $retyped) && $this->isFieldInUse($id)) {
        throw new ApiValidationException($renamed
            // TRANSLATORS Error message under the "Internal name" box of a metadata field that already has values
            ? ['field_name' => Translatable::t('Internal name cannot be changed when field is in use')]
            // TRANSLATORS Error message under the type of a metadata field that is already used in a Team folder
            : ['field_type' => Translatable::t('Field type cannot be changed when field is in use')]);
    }
    if ($renamed && $this->fieldNameTaken($field['field_name'])) {
        throw new ApiValidationException(['field_name' => Translatable::t('A field with this name already exists')]);
    }

    $qb = $this->db->getQueryBuilder();
    $qb->update('metavox_gf_fields')
       ->set('field_name', $qb->createNamedParameter($field['field_name']))
       ->set('field_label', $qb->createNamedParameter($field['field_label']))
       ->set('field_type', $qb->createNamedParameter($field['field_type']))
       ->set('field_description', $qb->createNamedParameter($field['field_description']))
       ->set('field_options', $qb->createNamedParameter($field['field_options']))
       ->set('is_required', $qb->createNamedParameter($field['is_required'] ? 1 : 0, IQueryBuilder::PARAM_INT))
       ->set('sort_order', $qb->createNamedParameter($field['sort_order'], IQueryBuilder::PARAM_INT))
       ->set('applies_to_groupfolder', $qb->createNamedParameter($field['applies_to_groupfolder'], IQueryBuilder::PARAM_INT))
       ->set('updated_at', $qb->createNamedParameter(date('Y-m-d H:i:s')))
       ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
    $qb->executeStatement();
    $this->clearFieldCache();
    return true;
}

/**
 * Creates a field from what was sent, checked as FieldDefinition says.
 *
 * @param array<string, mixed> $sent the parameters the request carries
 * @return int the new field's id
 * @throws ApiValidationException
 */
public function createField(array $sent): int {
    $field = FieldDefinition::stored($sent);
    if ($this->fieldNameTaken($field['field_name'])) {
        throw new ApiValidationException(['field_name' => Translatable::t('A field with this name already exists')]);
    }

    $now = date('Y-m-d H:i:s');
    $qb = $this->db->getQueryBuilder();
    $qb->insert('metavox_gf_fields')
       ->values([
           'field_name' => $qb->createNamedParameter($field['field_name']),
           'field_label' => $qb->createNamedParameter($field['field_label']),
           'field_type' => $qb->createNamedParameter($field['field_type']),
           'field_description' => $qb->createNamedParameter($field['field_description']),
           'field_options' => $qb->createNamedParameter($field['field_options']),
           'is_required' => $qb->createNamedParameter($field['is_required'] ? 1 : 0, IQueryBuilder::PARAM_INT),
           'sort_order' => $qb->createNamedParameter($field['sort_order'], IQueryBuilder::PARAM_INT),
           'applies_to_groupfolder' => $qb->createNamedParameter($field['applies_to_groupfolder'], IQueryBuilder::PARAM_INT),
           'created_at' => $qb->createNamedParameter($now),
           'updated_at' => $qb->createNamedParameter($now),
       ]);
    $qb->executeStatement();
    $id = $qb->getLastInsertId();
    $this->clearFieldCache();
    return $id;
}

private function fieldNameTaken(string $fieldName): bool {
    $qb = $this->db->getQueryBuilder();
    $qb->select('id')
       ->from('metavox_gf_fields')
       ->where($qb->expr()->eq('field_name', $qb->createNamedParameter($fieldName)));
    $result = $qb->executeQuery();
    $taken = $result->fetchOne() !== false;
    $result->closeCursor();
    return $taken;
}

/**
 * Deletes several fields. One still in use stays, as deleteField refuses it.
 *
 * @param array<mixed> $ids
 * @return array{deleted: list<int>, skipped: list<array{id: int, reason: string}>}
 */
public function deleteFields(array $ids): array {
    $deleted = [];
    $skipped = [];
    foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
        try {
            if ($this->deleteField($id)) {
                $deleted[] = $id;
            } else {
                $skipped[] = ['id' => $id, 'reason' => 'not_deleted'];
            }
        } catch (ApiValidationException) {
            $skipped[] = ['id' => $id, 'reason' => 'in_use'];
        }
    }
    return ['deleted' => $deleted, 'skipped' => $skipped];
}

public function deleteField(int $id): bool {
    // Deleting a field in use would wipe its values in every team folder.
    if ($this->isFieldInUse($id)) {
        // TRANSLATORS Error message shown when an administrator deletes a metadata field that still has values
        throw new ApiValidationException(['field' => Translatable::t('Cannot delete (in use)')]);
    }
    $this->db->beginTransaction();

    try {
        // Only groupfolder fields supported now
        $qb = $this->db->getQueryBuilder();
        $qb->select('field_name')
           ->from('metavox_gf_fields')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $fieldName = $result->fetchOne();
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

        // 2. Delete file-in-groupfolder metadata, and its search words first
        $this->searchWords->removeField($id, (string)$fieldName);
        $qb = $this->db->getQueryBuilder();
        $qb->delete('metavox_file_gf_meta')
           ->where($qb->expr()->eq('field_name', $qb->createNamedParameter($fieldName)));
        $qb->executeStatement();

        // 3. Delete groupfolder field assignments
        $qb = $this->db->getQueryBuilder();
        $qb->delete('metavox_gf_assigns')
           ->where($qb->expr()->eq('field_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
        $this->cache->remove(self::FIELD_FOLDERS_KEY . $id);
        $this->usedFields = [];

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
        $this->versions->bumpAll();

        return true;

    } catch (\Exception $e) {
        // All or nothing; the error is the middleware's to answer.
        $this->db->rollBack();
        throw $e;
    }
}
    // Groupfolder functionality

public function getGroupfolders(string $userId, bool $adminMode = false): array {
    // Request-scope cache
    $cacheKey = $userId . ($adminMode ? '_admin' : '');
    if (isset($this->groupfoldersCache[$cacheKey])) {
        return $this->groupfoldersCache[$cacheKey];
    }

    try {
        $user = $this->userManager->get($userId);
        if (!$user) {
            return [];
        }

        // Use the groupfolders app's FolderManager — handles both groups and circles/teams
        try {
            $folderManager = \OCP\Server::get(\OCA\GroupFolders\Folder\FolderManager::class);

            if ($adminMode) {
                $gfFolders = $folderManager->getAllFolders();
                $folders = [];
                foreach ($gfFolders as $gfFolder) {
                    $groups = GroupFolderAccessor::get($gfFolder, 'groups', []);
                    $folders[] = [
                        'id' => GroupFolderAccessor::get($gfFolder, 'id'),
                        'mount_point' => GroupFolderAccessor::get($gfFolder, 'mountPoint'),
                        'groups' => is_array($groups) ? array_keys($groups) : [],
                        'quota' => GroupFolderAccessor::get($gfFolder, 'quota'),
                        'size' => 0,
                        'acl' => GroupFolderAccessor::get($gfFolder, 'acl'),
                    ];
                }
                $this->groupfoldersCache[$cacheKey] = $folders;
                return $folders;
            }

            $gfFolders = $folderManager->getFoldersForUser($user);
            $folders = [];
            foreach ($gfFolders as $gfFolder) {
                $folders[] = [
                    'id' => GroupFolderAccessor::get($gfFolder, 'id'),
                    'mount_point' => GroupFolderAccessor::get($gfFolder, 'mountPoint'),
                    'groups' => [],
                    'quota' => GroupFolderAccessor::get($gfFolder, 'quota'),
                    'size' => 0,
                    'acl' => GroupFolderAccessor::get($gfFolder, 'acl'),
                ];
            }
            $this->groupfoldersCache[$cacheKey] = $folders;
            return $folders;
        } catch (\Exception $e) {
            // FolderManager not available, fall back to direct DB query
        }

        // Fallback: single JOINed query (no N+1)
        $userGroups = $adminMode ? null : $this->groupManager->getUserGroupIds($user);

        $qb = $this->db->getQueryBuilder();
        $qb->select('gf.folder_id', 'gf.mount_point', 'gfg.group_id')
           ->from('group_folders', 'gf')
           ->leftJoin('gf', 'group_folders_groups', 'gfg', $qb->expr()->eq('gf.folder_id', 'gfg.folder_id'))
           ->orderBy('gf.folder_id');

        $result = $qb->executeQuery();
        $foldersMap = [];

        while ($row = $result->fetch()) {
            $folderId = (int)($row['folder_id']);
            if (!isset($foldersMap[$folderId])) {
                $foldersMap[$folderId] = [
                    'id' => $folderId,
                    'mount_point' => $row['mount_point'] ?? 'Team Folder ' . $folderId,
                    'groups' => [],
                    'quota' => -3,
                    'size' => 0,
                    'acl' => false,
                ];
            }
            if (!empty($row['group_id'])) {
                $foldersMap[$folderId]['groups'][] = $row['group_id'];
            }
        }
        $result->closeCursor();

        if ($adminMode) {
            $folders = array_values($foldersMap);
        } else {
            $userGroupSet = array_flip($userGroups);
            $folders = [];
            foreach ($foldersMap as $folder) {
                foreach ($folder['groups'] as $group) {
                    if (isset($userGroupSet[$group])) {
                        $folders[] = $folder;
                        break;
                    }
                }
            }
        }

        $this->groupfoldersCache[$cacheKey] = $folders;
        return $folders;

    } catch (\Exception $e) {
        $this->logger->error('MetaVox: getGroupfolders failed', ['exception' => $e]);
        return [];
    }
}

/**
 * Check if a user has access to a specific groupfolder (via group or circle/team).
 * Admins always have access.
 */
public function hasAccessToGroupfolder(string $userId, int $groupfolderId): bool {
    $cacheKey = $userId . '_' . $groupfolderId;
    if (isset($this->folderAccessCache[$cacheKey])) {
        return $this->folderAccessCache[$cacheKey];
    }

    if ($this->groupManager->isAdmin($userId)) {
        $this->folderAccessCache[$cacheKey] = true;
        return true;
    }

    $folders = $this->getGroupfolders($userId);
    foreach ($folders as $folder) {
        if ((int)$folder['id'] === $groupfolderId) {
            $this->folderAccessCache[$cacheKey] = true;
            return true;
        }
    }
    $this->folderAccessCache[$cacheKey] = false;
    return false;
}

public function getGroupfolderMetadata(int $groupfolderId): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('f.id', 'f.field_name', 'f.field_label', 'f.field_type', 'f.field_options', 'f.is_required', 'f.field_description', 'f.applies_to_groupfolder', 'v.field_value as value')
               ->from('metavox_gf_fields', 'f')
               ->innerJoin('f', 'metavox_gf_assigns', 'gf', 'f.id = gf.field_id AND gf.groupfolder_id = :groupfolder_id')
               ->leftJoin('f', 'metavox_gf_metadata', 'v', 'f.field_name = v.field_name AND v.groupfolder_id = :groupfolder_id')
               ->setParameter('groupfolder_id', $groupfolderId)
               ->orderBy('f.sort_order', 'ASC')
               ->addOrderBy('f.id', 'ASC');

            $result = $qb->executeQuery();
            $metadata = [];
            while ($row = $result->fetch()) {
                $metadata[] = [
                    'id' => (int)$row['id'],
                    'field_name' => $row['field_name'],
                    'field_label' => $row['field_label'],
                    'field_type' => $row['field_type'],
                    'field_options' => $this->parseFieldOptions($row['field_options'] ?? null),
                    'is_required' => FieldValue::isRequired($row),
                    'field_description' => $row['field_description'] ?? '',
                    'applies_to_groupfolder' => (int)($row['applies_to_groupfolder'] ?? 0),
                    'value' => $row['value'],
                ];
            }
            $result->closeCursor();

            $this->logger->debug('MetaVox: getGroupfolderMetadata found fields', ['count' => count($metadata), 'groupfolderId' => $groupfolderId]);
            return $metadata;
            
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: getGroupfolderMetadata failed', ['exception' => $e, 'groupfolderId' => $groupfolderId]);
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
           ->orderBy('sort_order', 'ASC')
       ->addOrderBy('id', 'ASC');

        $result = $qb->executeQuery();
        $fields = [];
        while ($row = $result->fetch()) {
            $fields[] = [
                'id' => (int)$row['id'],
                'field_name' => $row['field_name'],
                'field_label' => $row['field_label'],
                'field_type' => $row['field_type'],
                'field_description' => $row['field_description'] ?? '',
                'field_options' => $this->parseFieldOptions($row['field_options'] ?? null),
                'is_required' => FieldValue::isRequired($row),
                'sort_order' => (int)$row['sort_order'],
                'scope' => 'groupfolder',
                'applies_to_groupfolder' => isset($row['applies_to_groupfolder']) ? (int)$row['applies_to_groupfolder'] : 0,
            ];
        }
        $result->closeCursor();

        return $fields;
        
    } catch (\Exception $e) {
        $this->logger->error('MetaVox: getAssignedFieldsWithDataForGroupfolder failed', ['exception' => $e, 'groupfolderId' => $groupfolderId]);
        return [];
    }
}

/**
 * The fields of a team folder: exactly these ids.
 *
 * @throws ApiValidationException for an id of no field
 */
public function setGroupfolderFields(int $groupfolderId, array $fieldIds): void {
    $fieldIds = array_values(array_unique(array_map('intval', $fieldIds)));
    if (array_diff($fieldIds, array_column($this->getAllFields(), 'id')) !== []) {
        // TRANSLATORS Error when fields are assigned to a Team folder and one of them no longer exists
        throw new ApiValidationException(['field_ids' => Translatable::t('Choose existing fields')]);
    }
    $this->db->beginTransaction();
    try {
        // Only remove and add what changed: the default value lives on the
        // assignment row, so a field that stays assigned keeps its default.
        $qb = $this->db->getQueryBuilder();
        $qb->select('field_id')
           ->from('metavox_gf_assigns')
           ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $current = [];
        while (($id = $result->fetchOne()) !== false) {
            $current[] = (int)$id;
        }
        $result->closeCursor();

        $removed = array_values(array_diff($current, $fieldIds));
        foreach (array_chunk($removed, 100) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('metavox_gf_assigns')
               ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)))
               ->andWhere($qb->expr()->in('field_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
            $qb->executeStatement();
        }

        // Batch insert the new assignments - use chunks of 100 to avoid query size limits
        $added = array_values(array_diff($fieldIds, $current));
        if (!empty($added)) {
            $timestamp = date('Y-m-d H:i:s');
            $chunks = array_chunk($added, 100);

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
        foreach ([...$removed, ...$added] as $fieldId) {
            $this->cache->remove(self::FIELD_FOLDERS_KEY . $fieldId);
            $this->usedFields = [];
        }

        // Cascade: prune views — remove references to fields no longer assigned
        $activeFieldNames = [];
        if (!empty($fieldIds)) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('field_name')
               ->from('metavox_gf_fields')
               ->where($qb->expr()->in('id', $qb->createNamedParameter($fieldIds, IQueryBuilder::PARAM_INT_ARRAY)));
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $activeFieldNames[] = $row['field_name'];
            }
            $result->closeCursor();
        }
        $this->viewService->pruneViewsForGroupfolder($groupfolderId, $fieldIds, $activeFieldNames);
    } catch (\Exception $e) {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
        throw $e;
    }
}
public function getGroupfolderFileMetadata(int $groupfolderId, int $fileId): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('f.id', 'f.field_name', 'f.field_label', 'f.field_type', 'f.field_options', 'f.is_required', 'f.field_description', 'f.applies_to_groupfolder', 'v.field_value as value')
               ->from('metavox_gf_fields', 'f')
               ->innerJoin('f', 'metavox_gf_assigns', 'gf', 'f.id = gf.field_id AND gf.groupfolder_id = :groupfolder_id')
               ->leftJoin('f', 'metavox_file_gf_meta', 'v', 'f.field_name = v.field_name AND v.groupfolder_id = :groupfolder_id AND v.file_id = :file_id')
               ->setParameter('groupfolder_id', $groupfolderId)
               ->setParameter('file_id', $fileId)
               ->orderBy('f.sort_order', 'ASC')
               ->addOrderBy('f.id', 'ASC');

            $result = $qb->executeQuery();
            $metadata = [];
            while ($row = $result->fetch()) {
                $metadata[] = [
                    'id' => (int)$row['id'],
                    'field_name' => $row['field_name'],
                    'field_label' => $row['field_label'],
                    'field_type' => $row['field_type'],
                    'field_options' => $this->parseFieldOptions($row['field_options'] ?? null),
                    'is_required' => FieldValue::isRequired($row),
                    'field_description' => $row['field_description'] ?? '',
                    'applies_to_groupfolder' => (int)($row['applies_to_groupfolder'] ?? 0),
                    'value' => $row['value'],
                ];
            }
            $result->closeCursor();

            $this->logger->debug('MetaVox: getGroupfolderFileMetadata found fields', ['count' => count($metadata), 'fileId' => $fileId, 'groupfolderId' => $groupfolderId]);
            return $metadata;
            
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: getGroupfolderFileMetadata failed', ['exception' => $e, 'fileId' => $fileId, 'groupfolderId' => $groupfolderId]);
            return [];
        }
    }


private function upsertGroupfolderValue(int $groupfolderId, string $fieldName, string $value): void {
    $sql = 'INSERT INTO *PREFIX*metavox_gf_metadata (groupfolder_id, field_name, field_value, created_at, updated_at) VALUES (?, ?, ?, ?, ?) '
        . ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_MYSQL
            ? 'ON DUPLICATE KEY UPDATE field_value = VALUES(field_value), updated_at = VALUES(updated_at)'
            : 'ON CONFLICT (groupfolder_id, field_name) DO UPDATE SET field_value = EXCLUDED.field_value, updated_at = EXCLUDED.updated_at');
    $now = date('Y-m-d H:i:s');
    $this->db->executeStatement($sql, [$groupfolderId, $fieldName, $value, $now, $now]);
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
           ->addOrderBy('f.sort_order', 'ASC')
           ->addOrderBy('f.id', 'ASC');

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
                    'field_options' => $this->parseFieldOptions($row['field_options'] ?? null),
                    'is_required' => FieldValue::isRequired($row),
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


/**
 * The fields this team folder uses: its file fields, or with $folderLevel its
 * team folder fields. From the cache of foldersOfField, which changing an
 * assignment clears, and once per request.
 *
 * @return array<string, true> field name => true
 */
private function usedFieldNames(int $groupfolderId, bool $folderLevel): array {
    if (isset($this->usedFields[$groupfolderId][(int)$folderLevel])) {
        return $this->usedFields[$groupfolderId][(int)$folderLevel];
    }
    $used = [];
    foreach ($this->getAllFields() as $field) {
        if (((int)($field['applies_to_groupfolder'] ?? 0) === 1) === $folderLevel
            && isset($this->foldersOfField((int)$field['id'])[$groupfolderId])) {
            $used[(string)$field['field_name']] = true;
        }
    }
    return $this->usedFields[$groupfolderId][(int)$folderLevel] = $used;
}

/**
 * Saves values of the team folder itself, each checked as in saveValues: when
 * one is refused nothing is saved. A field that is no team folder field of
 * this folder is left out.
 *
 * @param array<string, mixed> $values field name => value
 * @return int how many values were saved
 * @throws ApiValidationException a value a field does not take, by field name
 */
public function saveGroupfolderValues(int $groupfolderId, array $values): int {
    $fields = [];
    foreach ($this->getAllFields() as $field) {
        $fields[(string)$field['field_name']] = $field;
    }
    $used = $this->usedFieldNames($groupfolderId, true);

    $stored = [];
    $refused = [];
    $current = null;
    foreach ($values as $fieldName => $value) {
        $fieldName = (string)$fieldName;
        if (!isset($used[$fieldName], $fields[$fieldName])) {
            continue;
        }
        try {
            $checked = $this->fieldValues->stored($fields[$fieldName], $value);
            if (in_array($fields[$fieldName]['field_type'] ?? '', FileReferenceService::FILELINK_TYPES, true)) {
                $current ??= $this->getGroupfolderMetadataValues($groupfolderId);
            }
            $stored[$fieldName] = $this->storedValue($fields[$fieldName], $checked, $groupfolderId, null, $current[$fieldName] ?? '');
            FieldValue::requireFilled($fields[$fieldName], $stored[$fieldName]);
        } catch (InvalidValue $e) {
            unset($stored[$fieldName]);
            $refused[$fieldName] = [$e->getMessage(), $value];
        }
    }
    if ($refused !== []) {
        $current = $this->getGroupfolderMetadataValues($groupfolderId);
        $left = [];
        foreach ($refused as $fieldName => [$message, $value]) {
            $now = $current[$fieldName] ?? '';
            if (!(is_string($value) && $value === $now) && !($value === null && $now === '')) {
                $left[$fieldName] = $message;
            }
        }
        if ($left !== []) {
            throw new ApiValidationException($left);
        }
    }
    foreach ($stored as $fieldName => $value) {
        $this->upsertGroupfolderValue($groupfolderId, $fieldName, $value);
    }
    return \count($stored);
}

/** @return array<string, string> the stored values of the team folder itself */
private function getGroupfolderMetadataValues(int $groupfolderId): array {
    $qb = $this->db->getQueryBuilder();
    $qb->select('field_name', 'field_value')
       ->from('metavox_gf_metadata')
       ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)));
    $result = $qb->executeQuery();
    $values = [];
    while ($row = $result->fetch()) {
        $values[(string)$row['field_name']] = (string)$row['field_value'];
    }
    $result->closeCursor();
    return $values;
}

/**
 * Saves the values of many files in one team folder: one upsert and one search
 * word update per block, so a bulk edit or a copied folder costs a few queries
 * instead of a few per value. Each value is checked first (see checkedValues);
 * when one is refused nothing is saved. With $onlyEmpty a value that is already
 * filled in stays.
 *
 * @param array<int, array<string, mixed>> $values file id => [field name => value]
 * @param bool $asStored values copied from another file, already in their stored form
 * @return int how many values were saved
 * @throws ApiValidationException a value a field does not take, by field name
 */
public function saveValues(int $groupfolderId, array $values, bool $onlyEmpty = false, bool $asStored = false): int {
    $checked = $this->checkedValues($groupfolderId, $values, $onlyEmpty, $asStored);
    if ($checked['refused'] !== []) {
        throw new ApiValidationException(array_merge(...array_values($checked['refused'])));
    }
    $rows = $checked['rows'];
    $words = $checked['words'];
    if ($rows === []) {
        return 0;
    }

    $isMysql = $this->db->getDatabaseProvider() === IDBConnection::PLATFORM_MYSQL;
    $now = date('Y-m-d H:i:s');
    // The values and their search index: all of it or none. The rows stay
    // locked until the index has them, so of two saves at once the last one
    // is what the filter finds. Inside a caller's transaction that one does it.
    $own = !$this->db->inTransaction();
    if ($own) {
        $this->db->beginTransaction();
    }
    try {
        foreach (array_chunk($rows, self::SAVE_CHUNK) as $chunk) {
            $params = [];
            foreach ($chunk as $row) {
                array_push($params, ...$row);
                array_push($params, $now, $now);
            }
            $this->db->executeStatement($this->upsertSql(\count($chunk), $isMysql), $params);
        }
        $this->searchWords->replaceValues($groupfolderId, $words);
        if ($own) {
            $this->db->commit();
        }
    } catch (\Exception $e) {
        if ($own) {
            $this->db->rollBack();
        }
        throw $e;
    }

    $this->versions->bump($groupfolderId);
    $savedFiles = array_values(array_unique(array_column($rows, 0)));
    foreach ($savedFiles as $fileId) {
        $this->pushService->metadataChanged($groupfolderId, $fileId);
    }
    return \count($rows);
}

/**
 * saveValues for many files; when the database refuses them together, file by
 * file, so one file it cannot store does not keep the others from being saved.
 * A value a field does not take is still refused for the lot.
 *
 * @param array<int, array<string, mixed>> $values file id => [field name => value]
 * @return int[] the files that could not be saved
 * @throws ApiValidationException
 */
public function saveValuesPerFile(int $groupfolderId, array $values, bool $onlyEmpty = false): array {
    try {
        $this->inSavepoint(fn () => $this->saveValues($groupfolderId, $values, $onlyEmpty));
        return [];
    } catch (ApiValidationException $e) {
        throw $e;
    } catch (\Exception $e) {
        $this->logger->error('MetaVox: saving together failed, trying file by file', ['groupfolderId' => $groupfolderId, 'exception' => $e]);
    }
    $failed = [];
    foreach ($values as $fileId => $fileValues) {
        try {
            $this->inSavepoint(fn () => $this->saveValues($groupfolderId, [$fileId => $fileValues], $onlyEmpty));
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: could not save a file\'s values', ['groupfolderId' => $groupfolderId, 'fileId' => $fileId, 'exception' => $e]);
            $failed[] = (int)$fileId;
        }
    }
    return $failed;
}

/** One try that leaves the transaction usable when it fails (a savepoint inside one). */
private function inSavepoint(callable $save): void {
    $this->db->beginTransaction();
    try {
        $save();
        $this->db->commit();
    } catch (\Exception $e) {
        $this->db->rollBack();
        throw $e;
    }
}

/**
 * What saveValues would refuse, per file and field, and the fields this team
 * folder does not use, which it leaves out. For callers that save many files
 * and answer per file.
 *
 * @param array<int, array<string, mixed>> $values file id => [field name => value]
 * @return array{refused: array<int, array<string, string>>, unused: array<int, list<string>>}
 */
public function refusedValues(int $groupfolderId, array $values): array {
    $checked = $this->checkedValues($groupfolderId, $values, false, false);
    return ['refused' => $checked['refused'], 'unused' => $checked['unused']];
}

/**
 * The rows to save, each value in its stored form. A value is refused when its
 * field does not take it or when it would empty a required field; one that is
 * stored like that already stays as it is, so an old value never blocks a save.
 * A field this team folder does not use is left out, like an unknown one.
 *
 * @param array<int, array<string, mixed>> $values file id => [field name => value]
 * @return array{rows: list<array{0: int, 1: int, 2: string, 3: string}>, words: array<int, array<int, string>>, refused: array<int, array<string, string>>, unused: array<int, list<string>>}
 */
private function checkedValues(int $groupfolderId, array $values, bool $onlyEmpty, bool $asStored): array {
    $fields = [];
    foreach ($this->getAllFields() as $field) {
        $fields[$field['field_name']] = $field;
    }
    $used = $asStored ? null : $this->usedFieldNames($groupfolderId, false);
    $filled = $onlyEmpty ? $this->filledValues($groupfolderId, array_keys($values)) : [];
    $links = $asStored ? [] : $this->currentFileLinks($groupfolderId, $values, $fields);

    $rows = [];
    $words = [];
    $refused = [];
    $unused = [];
    foreach ($values as $fileId => $fileValues) {
        foreach ($fileValues as $fieldName => $value) {
            $fieldName = (string)$fieldName;
            $field = $fields[$fieldName] ?? null;
            if ($field === null || isset($filled[(int)$fileId][$fieldName])) {
                continue;
            }
            if ($used !== null && !isset($used[$fieldName])) {
                $unused[(int)$fileId][] = $fieldName;
                continue;
            }
            try {
                $stored = $asStored ? (string)$value : $this->fieldValues->stored($field, $value);
                $stored = $this->storedValue($field, $stored, $groupfolderId, (int)$fileId, $links[(int)$fileId][$fieldName] ?? '', !$asStored);
                if (!$asStored) {
                    FieldValue::requireFilled($field, $stored);
                }
            } catch (InvalidValue $e) {
                $refused[(int)$fileId][$fieldName] = [$e->getMessage(), $value];
                continue;
            }
            $rows[] = [(int)$fileId, $groupfolderId, $fieldName, $stored];
            $words[(int)$field['id']][(int)$fileId] = $stored;
        }
    }
    return ['rows' => $rows, 'words' => $words, 'refused' => $this->withoutUnchanged($groupfolderId, $refused), 'unused' => $unused];
}

/**
 * Refused values without the ones stored like that already: those stay as they
 * are. Looked up only when something was refused.
 *
 * @param array<int, array<string, array{0: string, 1: mixed}>> $refused file id => [field name => [message, value]]
 * @return array<int, array<string, string>> file id => [field name => message]
 */
private function withoutUnchanged(int $groupfolderId, array $refused): array {
    if ($refused === []) {
        return [];
    }
    $current = $this->storedValues($groupfolderId, array_keys($refused));
    $left = [];
    foreach ($refused as $fileId => $byField) {
        foreach ($byField as $fieldName => [$message, $value]) {
            $now = $current[$fileId][$fieldName] ?? '';
            if (!(is_string($value) && $value === $now) && !($value === null && $now === '')) {
                $left[$fileId][$fieldName] = $message;
            }
        }
    }
    return $left;
}

/**
 * The stored values of these files.
 *
 * @param int[] $fileIds
 * @return array<int, array<string, string>>
 */
private function storedValues(int $groupfolderId, array $fileIds): array {
    $stored = [];
    foreach (array_chunk(array_values(array_unique(array_map('intval', $fileIds))), self::SAVE_CHUNK) as $chunk) {
        $qb = $this->db->getQueryBuilder();
        $qb->select('file_id', 'field_name', 'field_value')
           ->from('metavox_file_gf_meta')
           ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
        $result = $qb->executeQuery();
        while ($row = $result->fetch()) {
            $stored[(int)$row['file_id']][(string)$row['field_name']] = (string)$row['field_value'];
        }
        $result->closeCursor();
    }
    return $stored;
}

public function upsertSql(int $rows, bool $isMysql): string {
    $sql = 'INSERT INTO *PREFIX*metavox_file_gf_meta (file_id, groupfolder_id, field_name, field_value, created_at, updated_at) VALUES '
        . implode(', ', array_fill(0, $rows, '(?, ?, ?, ?, ?, ?)'));
    return $isMysql
        ? $sql . ' ON DUPLICATE KEY UPDATE field_value = VALUES(field_value), updated_at = VALUES(updated_at)'
        : $sql . ' ON CONFLICT (file_id, groupfolder_id, field_name) DO UPDATE SET field_value = EXCLUDED.field_value, updated_at = EXCLUDED.updated_at';
}

/**
 * The fields that already have a value, per file.
 *
 * @param int[] $fileIds
 * @return array<int, array<string, true>>
 */
private function filledValues(int $groupfolderId, array $fileIds): array {
    $filled = [];
    foreach (array_chunk(array_values(array_unique(array_map('intval', $fileIds))), self::SAVE_CHUNK) as $chunk) {
        $qb = $this->db->getQueryBuilder();
        $qb->select('file_id', 'field_name')
           ->from('metavox_file_gf_meta')
           ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
           ->andWhere($qb->expr()->isNotNull('field_value'))
           ->andWhere($qb->expr()->neq('field_value', $qb->createNamedParameter('')));
        $result = $qb->executeQuery();
        while ($row = $result->fetch()) {
            $filled[(int)$row['file_id']][(string)$row['field_name']] = true;
        }
        $result->closeCursor();
    }
    return $filled;
}

/**
 * $fileId: the file the value belongs to, which a file link may not point at.
 * $current: its stored value, whose links stay; $asUser false for a copy.
 */
private function storedValue(array $field, string $value, int $groupfolderId, ?int $fileId = null, string $current = '', bool $asUser = true): string {
    return in_array($field['field_type'] ?? '', FileReferenceService::FILELINK_TYPES, true)
        ? $this->fileReferences->normalize($value, $groupfolderId, $fileId, $current, $asUser)
        : $value;
}

/**
 * The stored values of the file-link fields being saved, per file: one query,
 * none when no file link is saved.
 *
 * @param array<int, array<string, mixed>> $values file id => [field name => value]
 * @param array<string, array> $fields field name => field
 * @return array<int, array<string, string>>
 */
private function currentFileLinks(int $groupfolderId, array $values, array $fields): array {
    $names = [];
    foreach ($values as $fileValues) {
        foreach (array_keys($fileValues) as $fieldName) {
            if (in_array($fields[$fieldName]['field_type'] ?? '', FileReferenceService::FILELINK_TYPES, true)) {
                $names[(string)$fieldName] = true;
            }
        }
    }
    if ($names === []) {
        return [];
    }
    $links = [];
    foreach (array_chunk(array_map('intval', array_keys($values)), self::SAVE_CHUNK) as $chunk) {
        $qb = $this->db->getQueryBuilder();
        $qb->select('file_id', 'field_name', 'field_value')
           ->from('metavox_file_gf_meta')
           ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
           ->andWhere($qb->expr()->in('field_name', $qb->createNamedParameter(array_keys($names), IQueryBuilder::PARAM_STR_ARRAY)));
        $result = $qb->executeQuery();
        while ($row = $result->fetch()) {
            $links[(int)$row['file_id']][(string)$row['field_name']] = (string)$row['field_value'];
        }
        $result->closeCursor();
    }
    return $links;
}

/**
 * The value is saved even when its search words are not.
 *
 * @param int[] $fileIds
 */
private function updateWords(callable $update, array $fileIds): void {
    try {
        $update();
    } catch (\Exception $e) {
        $this->logger->error('MetaVox: failed to update search words', ['exception' => $e, 'fileIds' => \array_slice($fileIds, 0, 20)]);
        $this->rebuildSearchIndex();
    }
}

/** The index no longer matches: build it again; the filter reads the metadata meanwhile. */
private function rebuildSearchIndex(): void {
    try {
        $this->searchWords->rebuildAll();
    } catch (\Exception $e) {
        $this->logger->error('MetaVox: could not start rebuilding the search index', ['exception' => $e]);
    }
}

/** Clear all metadata for a file in a groupfolder; with $keepRequired the required fields keep theirs. */
public function clearGroupfolderFileMetadata(int $groupfolderId, int $fileId, bool $keepRequired = false): bool {
    try {
        $this->clearFilesMetadata($groupfolderId, [$fileId], $keepRequired);
        return true;
    } catch (\Exception $e) {
        $this->logger->error('MetaVox: clearGroupfolderFileMetadata failed', ['exception' => $e, 'fileId' => $fileId, 'groupfolderId' => $groupfolderId]);
        return false;
    }
}

/**
 * Clear all metadata of these files in a team folder. Errors are thrown.
 * With $keepRequired the required fields keep their values: clearing all is
 * no way around them.
 *
 * @param int[] $fileIds
 */
public function clearFilesMetadata(int $groupfolderId, array $fileIds, bool $keepRequired = false): void {
    $fileIds = array_values(array_unique(array_map('intval', $fileIds)));
    $kept = $keepRequired ? $this->requiredFieldNames() : [];
    foreach (array_chunk($fileIds, self::SAVE_CHUNK) as $chunk) {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('metavox_file_gf_meta')
           ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
        if ($kept !== []) {
            $qb->andWhere($qb->expr()->notIn('field_name', $qb->createNamedParameter($kept, IQueryBuilder::PARAM_STR_ARRAY)));
        }
        $qb->executeStatement();
    }
    $this->versions->bump($groupfolderId);
    $this->updateWords($kept === []
        ? fn () => $this->searchWords->removeFiles($fileIds, $groupfolderId)
        : fn () => $this->searchWords->reindexFiles($fileIds), $fileIds);
}

/** @return list<string> the names of the required fields */
public function requiredFieldNames(): array {
    return array_values(array_map(fn (array $field): string => (string)$field['field_name'],
        array_filter($this->getAllFields(), fn (array $field): bool => FieldValue::isRequired($field))));
}

/** Field names match regardless of case, as the API always did. */
public function clearGroupfolderFileFields(int $groupfolderId, int $fileId, array $fieldNames): void {
    $qb = $this->db->getQueryBuilder();
    $qb->delete('metavox_file_gf_meta')
       ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)))
       ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
       ->andWhere($qb->expr()->in($qb->func()->lower('field_name'),
           $qb->createNamedParameter(array_values(array_map('strtolower', $fieldNames)), IQueryBuilder::PARAM_STR_ARRAY)));
    $qb->executeStatement();

    $this->versions->bump($groupfolderId);
    $this->updateWords(fn() => $this->searchWords->reindexFiles([$fileId]), [$fileId]);
}

/**
 * Get all file-level fields assigned to a groupfolder.
 * Used by the view editor to populate the available columns list.
 * Returns only fields with applies_to_groupfolder = 0 (file-level fields).
 */
public function getAssignedFileFieldsForGroupfolder(int $groupfolderId): array {
    $allAssigned = $this->getAssignedFieldsWithDataForGroupfolder($groupfolderId);
    return array_values(array_filter($allAssigned, fn($f) => ($f['applies_to_groupfolder'] ?? 0) === 0));
}

/**
 * Get metadata for multiple files within a groupfolder.
 * Returns ALL metadata for the requested files — column filtering is handled by the frontend based on the active view.
 *
 * @param array $fileIds Array of file IDs
 * @param int $groupfolderId Groupfolder ID
 * @return array Keyed by file_id => [field_name => field_value, ...]
 */
}