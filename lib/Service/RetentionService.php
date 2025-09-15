<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;


/**
 * Retention Service - Enhanced for One Policy → Multiple Groupfolders
 * UPDATED: Support one retention policy applied to multiple groupfolders
 */
class RetentionService {

    private IDBConnection $db;
    private IUserSession $userSession;
    private IRootFolder $rootFolder;

    public function __construct(IDBConnection $db, IUserSession $userSession, IRootFolder $rootFolder) {
        $this->db = $db;
        $this->userSession = $userSession;
        $this->rootFolder = $rootFolder;
    }

    // ========================================
    // 🔒 ADMIN POLICY MANAGEMENT
    // ========================================

public function getAllPolicies(): array {
    try {
        $qb = $this->db->getQueryBuilder();
        $qb->select('rp.*')
           ->from('metavox_ret_policies', 'rp')
           ->orderBy('rp.created_at', 'DESC');

        $result = $qb->execute();
        $policies = [];
        while ($row = $result->fetch()) {
            $policy = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'description' => $row['description'],
                'is_active' => (bool)$row['is_active'],
                'default_action' => $row['default_action'],
                'default_target_path' => $row['default_target_path'],
                'notify_before_days' => (int)$row['notify_before_days'],
                'auto_process' => (bool)$row['auto_process'],
                'allowed_retention_periods' => json_decode($row['allowed_retention_periods'] ?? '[]', true),
                'require_justification' => (bool)$row['require_justification'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ];
            
            // Get assigned groupfolders for this policy
            try {
                $policy['assigned_groupfolders'] = $this->getGroupfoldersForPolicy($policy['id']);
            } catch (\Exception $e) {
                error_log('MetaVox Retention: Error getting groupfolders for policy ' . $policy['id'] . ': ' . $e->getMessage());
                $policy['assigned_groupfolders'] = [];
            }
            
            $policies[] = $policy;
        }
        $result->closeCursor();

        return $policies;
        
    } catch (\Exception $e) {
        error_log('MetaVox Retention: Error in getAllPolicies: ' . $e->getMessage());
        return [];
    }
}

    /**
     * 🆕 NEW: Get all groupfolders assigned to a policy
     */
    public function getGroupfoldersForPolicy(int $policyId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('pg.groupfolder_id', 'gf.mount_point')
           ->from('metavox_policy_groupfolders', 'pg')
           ->leftJoin('pg', 'group_folders', 'gf', 'pg.groupfolder_id = gf.folder_id')
           ->where($qb->expr()->eq('pg.policy_id', $qb->createNamedParameter($policyId, IQueryBuilder::PARAM_INT)));

        $result = $qb->execute();
        $groupfolders = [];
        while ($row = $result->fetch()) {
            $groupfolders[] = [
                'id' => (int)$row['groupfolder_id'],
                'mount_point' => $row['mount_point']
            ];
        }
        $result->closeCursor();

        return $groupfolders;
    }


   public function getPolicyById(int $id): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('metavox_ret_policies')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $result = $qb->execute();
        $row = $result->fetch();
        $result->closeCursor();

        if (!$row) {
            return null;
        }

        $policy = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'description' => $row['description'],
            'is_active' => (bool)$row['is_active'],
            'default_action' => $row['default_action'],
            'default_target_path' => $row['default_target_path'],
            'notify_before_days' => (int)$row['notify_before_days'],
            'auto_process' => (bool)$row['auto_process'],
            'allowed_retention_periods' => json_decode($row['allowed_retention_periods'] ?? '[]', true),
            'require_justification' => (bool)$row['require_justification'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
        
        // Get assigned groupfolders
        $policy['assigned_groupfolders'] = $this->getGroupfoldersForPolicy($policy['id']);
        
        return $policy;
    }

    /**
     * 🆕 ENHANCED: Create policy without direct groupfolder assignment
     */
public function createPolicy(array $policyData): int {
    try {
        error_log('MetaVox Retention: Creating policy: ' . json_encode($policyData));
        
        // Validate required fields
        if (empty($policyData['name'])) {
            throw new \Exception('Policy name is required');
        }

        $qb = $this->db->getQueryBuilder();
        $qb->insert('metavox_ret_policies')
           ->values([
               'name' => $qb->createNamedParameter($policyData['name']),
               'description' => $qb->createNamedParameter($policyData['description'] ?? ''),
               'groupfolder_id' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL), // FIXED: Always NULL now
               'is_active' => $qb->createNamedParameter($policyData['is_active'] ? 1 : 0, IQueryBuilder::PARAM_INT),
               'default_action' => $qb->createNamedParameter($policyData['default_action'] ?? 'move'),
               'default_target_path' => $qb->createNamedParameter($policyData['default_target_path'] ?? ''),
               'notify_before_days' => $qb->createNamedParameter($policyData['notify_before_days'] ?? 7, IQueryBuilder::PARAM_INT),
               'auto_process' => $qb->createNamedParameter($policyData['auto_process'] ? 1 : 0, IQueryBuilder::PARAM_INT),
               'allowed_retention_periods' => $qb->createNamedParameter(json_encode($policyData['allowed_retention_periods'] ?? [])),
               'require_justification' => $qb->createNamedParameter($policyData['require_justification'] ? 1 : 0, IQueryBuilder::PARAM_INT),
               'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
               'updated_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
           ]);

        $qb->execute();
        $insertId = (int) $this->db->lastInsertId('metavox_ret_policies');
        
        // Assign groupfolders if provided
        if (!empty($policyData['groupfolder_ids']) && is_array($policyData['groupfolder_ids'])) {
            $this->assignGroupfoldersToPolicy($insertId, $policyData['groupfolder_ids']);
        }
        
        error_log('MetaVox Retention: Policy created with ID: ' . $insertId);
        return $insertId;
        
    } catch (\Exception $e) {
        error_log('MetaVox Retention: Error creating policy: ' . $e->getMessage());
        throw $e;
    }
}

        /**
     * 🆕 NEW: Assign multiple groupfolders to a policy
     */
    public function assignGroupfoldersToPolicy(int $policyId, array $groupfolderIds): bool {
        try {
            // First, remove existing assignments
            $qb = $this->db->getQueryBuilder();
            $qb->delete('metavox_policy_groupfolders')
               ->where($qb->expr()->eq('policy_id', $qb->createNamedParameter($policyId, IQueryBuilder::PARAM_INT)));
            $qb->execute();

            // Then add new assignments
            foreach ($groupfolderIds as $groupfolderId) {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('metavox_policy_groupfolders')
                   ->values([
                       'policy_id' => $qb->createNamedParameter($policyId, IQueryBuilder::PARAM_INT),
                       'groupfolder_id' => $qb->createNamedParameter((int)$groupfolderId, IQueryBuilder::PARAM_INT),
                       'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
                   ]);
                $qb->execute();
            }

            error_log('MetaVox Retention: Assigned ' . count($groupfolderIds) . ' groupfolders to policy ' . $policyId);
            return true;
            
        } catch (\Exception $e) {
            error_log('MetaVox Retention: Error assigning groupfolders to policy: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 🆕 ENHANCED: Update policy (groupfolders handled separately)
     */
    public function updatePolicy(int $id, array $policyData): bool {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->update('metavox_ret_policies')
               ->set('name', $qb->createNamedParameter($policyData['name']))
               ->set('description', $qb->createNamedParameter($policyData['description'] ?? ''))
               ->set('is_active', $qb->createNamedParameter($policyData['is_active'] ? 1 : 0, IQueryBuilder::PARAM_INT))
               ->set('default_action', $qb->createNamedParameter($policyData['default_action'] ?? 'move'))
               ->set('default_target_path', $qb->createNamedParameter($policyData['default_target_path'] ?? ''))
               ->set('notify_before_days', $qb->createNamedParameter($policyData['notify_before_days'] ?? 7, IQueryBuilder::PARAM_INT))
               ->set('auto_process', $qb->createNamedParameter($policyData['auto_process'] ? 1 : 0, IQueryBuilder::PARAM_INT))
               ->set('allowed_retention_periods', $qb->createNamedParameter(json_encode($policyData['allowed_retention_periods'] ?? [])))
               ->set('require_justification', $qb->createNamedParameter($policyData['require_justification'] ? 1 : 0, IQueryBuilder::PARAM_INT))
               ->set('updated_at', $qb->createNamedParameter(date('Y-m-d H:i:s')))
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

            $success = $qb->execute() > 0;
            
            // Update groupfolder assignments if provided
            if (isset($policyData['groupfolder_ids']) && is_array($policyData['groupfolder_ids'])) {
                $this->assignGroupfoldersToPolicy($id, $policyData['groupfolder_ids']);
            }
            
            return $success;
        } catch (\Exception $e) {
            error_log('MetaVox Retention: Error updating policy: ' . $e->getMessage());
            throw $e;
        }
    }
        /**
     * 🆕 NEW: Get policies available for a specific groupfolder
     */
public function getPoliciesForGroupfolder(int $groupfolderId): array {
    $qb = $this->db->getQueryBuilder();
    $qb->select('rp.*')
       ->from('metavox_ret_policies', 'rp')
       ->innerJoin('rp', 'metavox_policy_groupfolders', 'pg', 'rp.id = pg.policy_id')
       ->where($qb->expr()->eq('pg.groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)))
       ->andWhere($qb->expr()->eq('rp.is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
       ->orderBy('rp.name', 'ASC');

    $result = $qb->execute();
    $policies = [];
        while ($row = $result->fetch()) {
            $policies[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'description' => $row['description'],
                'default_action' => $row['default_action'],
                'default_target_path' => $row['default_target_path'],
                'notify_before_days' => (int)$row['notify_before_days'],
                'is_active' => (bool)$row['is_active'], 
                'allowed_retention_periods' => json_decode($row['allowed_retention_periods'] ?? '[]', true),
                'require_justification' => (bool)$row['require_justification'],
            ];
        error_log('MetaVox: Policy loaded - ID: ' . $policies['id'] . ', Active: ' . ($policies['is_active'] ? 'YES' : 'NO'));
        
        $policies[] = $policies;
    }
    $result->closeCursor();

    error_log('MetaVox: Total policies found for groupfolder ' . $groupfolderId . ': ' . count($policies));
    return $policies;
}

   /**
     * 🆕 ENHANCED: Find policy for file (now supports multiple policies per groupfolder)
     */
    public function findPolicyForFile(int $fileId): ?array {
        try {
            // Get file info from Nextcloud Files API
            $user = $this->userSession->getUser();
            if (!$user) {
                return null;
            }

            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            $files = $userFolder->getById($fileId);
            
            if (empty($files)) {
                return null;
            }

            $file = $files[0];
            $path = $file->getPath();
            
            // Extract groupfolder from path
            $pathParts = explode('/', $path);
            if (count($pathParts) >= 4 && $pathParts[2] === 'files') {
                $groupfolderName = $pathParts[3];
                
                // Get policies for this groupfolder
                $policies = $this->getPoliciesForGroupfolderByName($groupfolderName);
                
                // If multiple policies, return first active one (you can add more logic here)
                return $policies[0] ?? null;
            }

            return null;
        } catch (\Exception $e) {
            error_log('MetaVox Retention: Error finding policy for file: ' . $e->getMessage());
            return null;
        }
    }

        /**
     * 🆕 NEW: Get policies for groupfolder by mount point name
     */
    private function getPoliciesForGroupfolderByName(string $mountPoint): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('rp.*')
           ->from('metavox_ret_policies', 'rp')
           ->innerJoin('rp', 'metavox_policy_groupfolders', 'pg', 'rp.id = pg.policy_id')
           ->innerJoin('pg', 'group_folders', 'gf', 'pg.groupfolder_id = gf.folder_id')
           ->where($qb->expr()->eq('gf.mount_point', $qb->createNamedParameter($mountPoint)))
           ->andWhere($qb->expr()->eq('rp.is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
           ->orderBy('rp.name', 'ASC');

        $result = $qb->execute();
        $policies = [];
        while ($row = $result->fetch()) {
            $policies[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'description' => $row['description'],
                'default_action' => $row['default_action'],
                'default_target_path' => $row['default_target_path'],
                'notify_before_days' => (int)$row['notify_before_days'],
                'allowed_retention_periods' => json_decode($row['allowed_retention_periods'] ?? '[]', true),
                'require_justification' => (bool)$row['require_justification'],
            ];
        }
        $result->closeCursor();

        return $policies;
    }

    /**
     * 🆕 NEW: Find best matching policy based on path and file type filters
     */
    private function findBestMatchingPolicy(array $policies, string $filePath, string $fileName): ?array {
        error_log('MetaVox Retention: Checking ' . count($policies) . ' policies for best match');
        
        foreach ($policies as $policy) {
            $policyName = $policy['name'];
            $pathFilter = $policy['applies_to_path'] ?? '';
            $typeFilter = $policy['file_type_filter'] ?? '';
            
            error_log("MetaVox Retention: Checking policy '{$policyName}' (Path: '{$pathFilter}', Type: '{$typeFilter}')");
            
            // Check path filter
            $pathMatches = $this->checkPathFilter($filePath, $pathFilter);
            error_log("MetaVox Retention: Path filter result: " . ($pathMatches ? 'MATCH' : 'NO MATCH'));
            
            // Check file type filter
            $typeMatches = $this->checkFileTypeFilter($fileName, $typeFilter);
            error_log("MetaVox Retention: Type filter result: " . ($typeMatches ? 'MATCH' : 'NO MATCH'));
            
            // Policy matches if both filters pass (or if filters are empty)
            if ($pathMatches && $typeMatches) {
                error_log("MetaVox Retention: SELECTED policy '{$policyName}' - both filters match!");
                return $policy;
            }
        }
        
        error_log('MetaVox Retention: No specific policy matched, using fallback');
        
        // If no specific policy matched, return the first (highest priority) policy
        return $policies[0] ?? null;
    }

    /**
     * 🆕 NEW: Check if file path matches path filter
     */
    private function checkPathFilter(string $filePath, string $pathFilter): bool {
        // Empty filter means "applies to all paths"
        if (empty($pathFilter)) {
            return true;
        }
        
        // Convert to relative path within groupfolder
        $pathParts = explode('/', $filePath);
        if (count($pathParts) >= 5 && $pathParts[2] === 'files') {
            // Remove /username/files/groupfolder/ part
            $relativePath = '/' . implode('/', array_slice($pathParts, 4));
        } else {
            $relativePath = $filePath;
        }
        
        // Check if the relative path starts with the filter path
        $pathFilter = '/' . ltrim($pathFilter, '/');
        $matches = strpos($relativePath, $pathFilter) === 0;
        
        error_log("MetaVox Retention: Path check - File: '{$relativePath}', Filter: '{$pathFilter}', Match: " . ($matches ? 'YES' : 'NO'));
        
        return $matches;
    }

    /**
     * 🆕 NEW: Check if file name matches file type filter
     */
    private function checkFileTypeFilter(string $fileName, string $typeFilter): bool {
        // Empty filter means "applies to all file types"
        if (empty($typeFilter)) {
            return true;
        }
        
        // Parse file extensions from filter (e.g., "*.pdf,*.docx,*.txt")
        $allowedExtensions = array_map('trim', explode(',', $typeFilter));
        
        // Get file extension
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        foreach ($allowedExtensions as $pattern) {
            $pattern = strtolower(trim($pattern));
            
            // Handle wildcard patterns like *.pdf
            if (strpos($pattern, '*.') === 0) {
                $extensionPattern = substr($pattern, 2); // Remove *.
                if ($fileExtension === $extensionPattern) {
                    error_log("MetaVox Retention: File type match - Extension '{$fileExtension}' matches pattern '{$pattern}'");
                    return true;
                }
            }
            // Handle direct extension matches like pdf
            elseif ($pattern === $fileExtension) {
                error_log("MetaVox Retention: File type match - Extension '{$fileExtension}' matches '{$pattern}'");
                return true;
            }
        }
        
        error_log("MetaVox Retention: File type NO match - Extension '{$fileExtension}' not in filter '{$typeFilter}'");
        return false;
    }

    public function removeFileRetention(int $fileId): bool {
        try {
            error_log('MetaVox Retention: Removing retention for file: ' . $fileId);
            
            $qb = $this->db->getQueryBuilder();
            $qb->delete('metavox_file_retention')
               ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

            $deletedRows = $qb->execute();
            
            if ($deletedRows > 0) {
                error_log('MetaVox Retention: Successfully removed retention for file: ' . $fileId);
                return true;
            } else {
                error_log('MetaVox Retention: No retention found for file: ' . $fileId);
                return false;
            }
            
        } catch (\Exception $e) {
            error_log('MetaVox Retention: Error removing file retention: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Delete policy and all related data
     */
    public function deletePolicy(int $id): bool {
        try {
            // Delete policy-groupfolder assignments
            $qb = $this->db->getQueryBuilder();
            $qb->delete('metavox_policy_groupfolders')
               ->where($qb->expr()->eq('policy_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
            $qb->execute();

            // Delete file retention records for this policy
            $qb = $this->db->getQueryBuilder();
            $qb->delete('metavox_file_retention')
               ->where($qb->expr()->eq('policy_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
            $qb->execute();

            // Delete processing logs
            $qb = $this->db->getQueryBuilder();
            $qb->delete('metavox_ret_logs')
               ->where($qb->expr()->eq('policy_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
            $qb->execute();

            // Delete the policy
            $qb = $this->db->getQueryBuilder();
            $qb->delete('metavox_ret_policies')
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

            return $qb->execute() > 0;
        } catch (\Exception $e) {
            error_log('MetaVox Retention: Error deleting policy: ' . $e->getMessage());
            throw $e;
        }
    }

    public function togglePolicyStatus(int $id, bool $isActive): bool {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->update('metavox_ret_policies')
               ->set('is_active', $qb->createNamedParameter($isActive ? 1 : 0, IQueryBuilder::PARAM_INT))
               ->set('updated_at', $qb->createNamedParameter(date('Y-m-d H:i:s')))
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

            return $qb->execute() > 0;
        } catch (\Exception $e) {
            error_log('MetaVox Retention: Error toggling policy status: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Backward compatibility: Get first policy for groupfolder
     */
    public function getPolicyForGroupfolder(int $groupfolderId): ?array {
        $policies = $this->getPoliciesForGroupfolder($groupfolderId);
        return $policies[0] ?? null;
    }

        /**
     * 🆕 NEW: Get all groupfolders that DON'T have any retention policy
     */
    public function getGroupfoldersWithoutPolicy(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('gf.folder_id as id', 'gf.mount_point')
           ->from('group_folders', 'gf')
           ->leftJoin('gf', 'metavox_policy_groupfolders', 'pg', 'gf.folder_id = pg.groupfolder_id')
           ->where($qb->expr()->isNull('pg.groupfolder_id'))
           ->orderBy('gf.mount_point', 'ASC');

        $result = $qb->execute();
        $groupfolders = [];
        while ($row = $result->fetch()) {
            $groupfolders[] = [
                'id' => (int)$row['id'],
                'mount_point' => $row['mount_point']
            ];
        }
        $result->closeCursor();

        return $groupfolders;
    }

    /**
     * 🔒 FIXED: Check retention inheritance for multiple paths efficiently
     * Returns which paths have retention and should block child retention
     */
    public function checkRetentionBatch(array $paths, int $groupfolderId): array {
        try {
            error_log('MetaVox Retention: Batch checking paths: ' . json_encode($paths));
            
            $result = [
                'has_parent_retention' => false,
                'parent_retention_path' => null,
                'parent_retention_info' => null,
                'blocked_paths' => [],
                'allowed_paths' => [],
                'current_item_has_retention' => false,
                'current_item_retention_info' => null,
                'debug_info' => []
            ];
            
            if (empty($paths)) {
                return $result;
            }
            
            // Get all file IDs for the given paths within the groupfolder
            $pathToFileId = $this->getFileIdsForPaths($paths, $groupfolderId);
            $result['debug_info']['path_to_file_id'] = $pathToFileId;
            
            if (empty($pathToFileId)) {
                $result['allowed_paths'] = $paths;
                return $result;
            }
            
            // Get all retention records for these file IDs in one query
            $fileIds = array_values($pathToFileId);
            $retentionRecords = $this->getRetentionRecordsForFiles($fileIds);
            $result['debug_info']['retention_records'] = $retentionRecords;
            
            // Build lookup table: fileId => retentionInfo
            $fileRetentionLookup = [];
            foreach ($retentionRecords as $retention) {
                $fileRetentionLookup[$retention['file_id']] = $retention;
            }
            $result['debug_info']['file_retention_lookup'] = $fileRetentionLookup;
            
            // 🔧 FIXED: Build path-to-retention lookup for easier checking
            $pathRetentionLookup = [];
            foreach ($pathToFileId as $path => $fileId) {
                if (isset($fileRetentionLookup[$fileId])) {
                    $pathRetentionLookup[$path] = $fileRetentionLookup[$fileId];
                }
            }
            $result['debug_info']['path_retention_lookup'] = $pathRetentionLookup;
            
            // Sort paths by depth (shortest first, so parent folders come before children)
            $sortedPaths = $this->sortPathsByDepth($paths);
            $result['debug_info']['sorted_paths'] = $sortedPaths;
            
            // 🆕 NEW: Check if the FIRST (most specific) path has retention
            // This represents the current item being viewed
            $currentItemPath = $sortedPaths[count($sortedPaths) - 1]; // Most specific path
            if (isset($pathRetentionLookup[$currentItemPath])) {
                error_log("MetaVox Retention: Current item {$currentItemPath} HAS retention policy");
                $result['current_item_has_retention'] = true;
                $result['current_item_retention_info'] = $pathRetentionLookup[$currentItemPath];
            }
            
            // 🔧 ENHANCED: Check each path for parent retention
            foreach ($sortedPaths as $currentPath) {
                $isBlocked = false;
                $parentRetentionPath = null;
                $parentRetentionInfo = null;
                
                error_log("MetaVox Retention: Checking path: {$currentPath}");
                
                // Get all parent paths of current path (from deepest to shallowest)
                $parentPaths = $this->getParentPaths($currentPath);
                $result['debug_info']['parent_paths_for_' . str_replace('/', '_', $currentPath)] = $parentPaths;
                
                // Check if ANY parent path has retention
                foreach ($parentPaths as $parentPath) {
                    error_log("MetaVox Retention: Checking parent path: {$parentPath}");
                    
                    // Check if this parent path has retention
                    if (isset($pathRetentionLookup[$parentPath])) {
                        error_log("MetaVox Retention: Found parent retention! Parent: {$parentPath}, Child: {$currentPath}");
                        
                        $isBlocked = true;
                        $parentRetentionPath = $parentPath;
                        $parentRetentionInfo = $pathRetentionLookup[$parentPath];
                        break; // Found blocking parent, stop checking
                    }
                }
                
                if ($isBlocked) {
                    error_log("MetaVox Retention: Path {$currentPath} is BLOCKED by parent {$parentRetentionPath}");
                    
                    $result['has_parent_retention'] = true;
                    $result['parent_retention_path'] = $parentRetentionPath;
                    $result['parent_retention_info'] = $parentRetentionInfo;
                    $result['blocked_paths'][] = $currentPath;
                } else {
                    error_log("MetaVox Retention: Path {$currentPath} is ALLOWED");
                    $result['allowed_paths'][] = $currentPath;
                }
            }
            
            // 🆕 NEW: Special logic for current item with retention
            if ($result['current_item_has_retention']) {
                error_log('MetaVox Retention: Current item has retention - checking if it should block children');
                
                // If we're viewing an item that has retention, and it's not blocked by a parent,
                // then children of this item should be blocked from having their own retention
                if (!$result['has_parent_retention']) {
                    error_log('MetaVox Retention: Current item retention should block child retention');
                    
                    // Mark this as having "parent" retention from the perspective of children
                    $result['has_parent_retention'] = true;
                    $result['parent_retention_path'] = $currentItemPath;
                    $result['parent_retention_info'] = $result['current_item_retention_info'];
                    $result['blocks_child_retention'] = true;
                }
            }
            
            error_log('MetaVox Retention: Final batch check result: ' . json_encode([
                'has_parent_retention' => $result['has_parent_retention'],
                'current_item_has_retention' => $result['current_item_has_retention'],
                'blocked_count' => count($result['blocked_paths']),
                'allowed_count' => count($result['allowed_paths']),
                'parent_retention_path' => $result['parent_retention_path'],
                'blocks_child_retention' => $result['blocks_child_retention'] ?? false
            ]));
            
            return $result;
            
        } catch (\Exception $e) {
            error_log('MetaVox Retention: Error in checkRetentionBatch: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 🔧 FIXED: Get all parent paths for a given path (from closest to furthest)
     */
    private function getParentPaths(string $path): array {
        $path = trim($path, '/');
        
        if (empty($path)) {
            return []; // Root has no parents
        }
        
        $parts = explode('/', $path);
        $parentPaths = [];
        
        // Build parent paths from immediate parent to root
        // For /testfolder/test/file.txt -> [/testfolder/test, /testfolder]
        for ($i = count($parts) - 1; $i > 0; $i--) {
            $parentPath = '/' . implode('/', array_slice($parts, 0, $i));
            $parentPaths[] = $parentPath;
        }
        
        error_log("MetaVox Retention: Parent paths for '{$path}': " . json_encode($parentPaths));
        return $parentPaths;
    }

    /**
     * 🔧 FIXED: Convert a full path to groupfolder-relative path
     */
    private function convertToGroupfolderPath(string $fullPath, string $mountPoint): ?string {
        // Clean paths
        $fullPath = trim($fullPath, '/');
        $mountPoint = trim($mountPoint, '/');
        
        // If fullPath starts with mount point, extract the relative part
        if (strpos($fullPath, $mountPoint . '/') === 0) {
            return substr($fullPath, strlen($mountPoint . '/'));
        } elseif ($fullPath === $mountPoint) {
            return ''; // Root of groupfolder
        }
        
        // For direct groupfolder paths like /testfolder -> return as-is
        if (strpos($fullPath, '/') === false || strpos($fullPath, $mountPoint) !== false) {
            return $fullPath;
        }
        
        return $fullPath; // Return as-is if no clear mount point match
    }

    // ========================================
    // 🎯 USER RETENTION MANAGEMENT
    // ========================================

   public function getFileRetention(int $fileId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('fr.*', 'rp.name as policy_name', 'rp.default_action', 'rp.default_target_path')
           ->from('metavox_file_retention', 'fr')
           ->leftJoin('fr', 'metavox_ret_policies', 'rp', 'fr.policy_id = rp.id')
           ->where($qb->expr()->eq('fr.file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

        $result = $qb->execute();
        $row = $result->fetch();
        $result->closeCursor();

        if (!$row) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'file_id' => (int)$row['file_id'],
            'policy_id' => (int)$row['policy_id'],
            'policy_name' => $row['policy_name'],
            'retention_period' => (int)$row['retention_period'],
            'retention_unit' => $row['retention_unit'],
            'expire_date' => $row['expire_date'],
            'action' => $row['action'] ?? $row['default_action'],
            'target_path' => $row['target_path'] ?? $row['default_target_path'],
            'justification' => $row['justification'],
            'notify_before_days' => (int)$row['notify_before_days'],
            'status' => $row['status'],
            'created_by' => $row['created_by'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    public function setFileRetention(int $fileId, array $retentionData): bool {
        try {
            error_log('MetaVox Retention: Setting file retention: ' . json_encode(['fileId' => $fileId, 'data' => $retentionData]));

            // Find policy for this file
            $policy = $this->findPolicyForFile($fileId);
            if (!$policy) {
                throw new \Exception('No retention policy found for this file. Contact your administrator.');
            }

            // Validate retention settings against policy
            $this->validateRetentionAgainstPolicy($retentionData, $policy);

            // Calculate expire date
            $expireDate = $this->calculateExpireDate(
                $retentionData['retention_period'],
                $retentionData['retention_unit'] ?? 'years'
            );

            $userId = $this->userSession->getUser()->getUID();

            // Check if retention already exists
            $qb = $this->db->getQueryBuilder();
            $qb->select('id')
               ->from('metavox_file_retention')
               ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

            $result = $qb->execute();
            $existingId = $result->fetchColumn();
            $result->closeCursor();

            if ($existingId) {
                // Update existing retention
                $qb = $this->db->getQueryBuilder();
                $qb->update('metavox_file_retention')
                   ->set('policy_id', $qb->createNamedParameter($policy['id'], IQueryBuilder::PARAM_INT))
                   ->set('retention_period', $qb->createNamedParameter($retentionData['retention_period'], IQueryBuilder::PARAM_INT))
                   ->set('retention_unit', $qb->createNamedParameter($retentionData['retention_unit'] ?? 'years'))
                   ->set('expire_date', $qb->createNamedParameter($expireDate))
                   ->set('action', $qb->createNamedParameter($retentionData['action'] ?? $policy['default_action']))
                   ->set('target_path', $qb->createNamedParameter($retentionData['target_path'] ?? $policy['default_target_path']))
                   ->set('justification', $qb->createNamedParameter($retentionData['justification'] ?? ''))
                   ->set('notify_before_days', $qb->createNamedParameter($retentionData['notify_before_days'] ?? $policy['notify_before_days'], IQueryBuilder::PARAM_INT))
                   ->set('status', $qb->createNamedParameter('active'))
                   ->set('updated_at', $qb->createNamedParameter(date('Y-m-d H:i:s')))
                   ->where($qb->expr()->eq('id', $qb->createNamedParameter($existingId, IQueryBuilder::PARAM_INT)));

                return $qb->execute() > 0;
            } else {
                $qb->insert('metavox_file_retention')
                   ->values([
                       'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
                       'policy_id' => $qb->createNamedParameter($policy['id'], IQueryBuilder::PARAM_INT),
                       'retention_period' => $qb->createNamedParameter($retentionData['retention_period'], IQueryBuilder::PARAM_INT),
                       'retention_unit' => $qb->createNamedParameter($retentionData['retention_unit'] ?? 'years'),
                       'expire_date' => $qb->createNamedParameter($expireDate),
                       'action' => $qb->createNamedParameter($retentionData['action'] ?? $policy['default_action']),
                       'target_path' => $qb->createNamedParameter($retentionData['target_path'] ?? $policy['default_target_path']),
                       'justification' => $qb->createNamedParameter($retentionData['justification'] ?? ''),
                       'notify_before_days' => $qb->createNamedParameter($retentionData['notify_before_days'] ?? $policy['notify_before_days'], IQueryBuilder::PARAM_INT),
                       'status' => $qb->createNamedParameter('active'),
                       'created_by' => $qb->createNamedParameter($userId),
                       'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
                       'updated_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
                   ]);

                return $qb->execute() > 0;
            }

        } catch (\Exception $e) {
            error_log('MetaVox Retention: Error setting file retention: ' . $e->getMessage());
            throw $e;
        }
    }

    public function getUserRetentionOverview(): array {
        $userId = $this->userSession->getUser()->getUID();
        
        $qb = $this->db->getQueryBuilder();
        $qb->select('fr.*', 'rp.name as policy_name')
           ->from('metavox_file_retention', 'fr')
           ->leftJoin('fr', 'metavox_ret_policies', 'rp', 'fr.policy_id = rp.id')
           ->where($qb->expr()->eq('fr.created_by', $qb->createNamedParameter($userId)))
           ->orderBy('fr.expire_date', 'ASC');

        $result = $qb->execute();
        $retentions = [];
        while ($row = $result->fetch()) {
            $retentions[] = [
                'id' => (int)$row['id'],
                'file_id' => (int)$row['file_id'],
                'policy_name' => $row['policy_name'],
                'retention_period' => (int)$row['retention_period'],
                'retention_unit' => $row['retention_unit'],
                'expire_date' => $row['expire_date'],
                'action' => $row['action'],
                'target_path' => $row['target_path'],
                'status' => $row['status'],
                'created_at' => $row['created_at'],
            ];
        }
        $result->closeCursor();

        return $retentions;
    }

    // ========================================
    // 📊 PROCESSING & MONITORING
    // ========================================

    public function processRetentionActions(bool $dryRun = false): array {
        try {
            error_log('MetaVox Retention: Processing retention actions (dry run: ' . ($dryRun ? 'yes' : 'no') . ')');

            $processed = [];
            $errors = [];
            
            // Get all files that have expired
            $expiredFiles = $this->getExpiredFiles();
            
            foreach ($expiredFiles as $retention) {
                try {
                    $result = $this->processExpiredFile($retention, $dryRun);
                    $processed[] = $result;
                } catch (\Exception $e) {
                    $error = [
                        'file_id' => $retention['file_id'],
                        'error' => $e->getMessage(),
                        'retention' => $retention
                    ];
                    $errors[] = $error;
                    error_log('MetaVox Retention: Error processing file ' . $retention['file_id'] . ': ' . $e->getMessage());
                }
            }

            $summary = [
                'total_processed' => count($processed),
                'total_errors' => count($errors),
                'dry_run' => $dryRun,
                'processed' => $processed,
                'errors' => $errors,
                'timestamp' => date('Y-m-d H:i:s')
            ];

            error_log('MetaVox Retention: Processing complete: ' . json_encode($summary));
            return $summary;

        } catch (\Exception $e) {
            error_log('MetaVox Retention: Error in processRetentionActions: ' . $e->getMessage());
            throw $e;
        }
    }

    public function getUpcomingActions(int $days = 30): array {
        $futureDate = date('Y-m-d', strtotime("+{$days} days"));
        
        $qb = $this->db->getQueryBuilder();
        $qb->select('fr.*', 'rp.name as policy_name')
           ->from('metavox_file_retention', 'fr')
           ->leftJoin('fr', 'metavox_ret_policies', 'rp', 'fr.policy_id = rp.id')
           ->where($qb->expr()->lte('fr.expire_date', $qb->createNamedParameter($futureDate)))
           ->andWhere($qb->expr()->eq('fr.status', $qb->createNamedParameter('active')))
           ->orderBy('fr.expire_date', 'ASC');

        $result = $qb->execute();
        $upcoming = [];
        while ($row = $result->fetch()) {
            $daysUntilExpiry = (strtotime($row['expire_date']) - time()) / (60 * 60 * 24);
            
            $upcoming[] = [
                'file_id' => (int)$row['file_id'],
                'policy_name' => $row['policy_name'],
                'expire_date' => $row['expire_date'],
                'days_until_expiry' => ceil($daysUntilExpiry),
                'action' => $row['action'],
                'target_path' => $row['target_path'],
                'created_by' => $row['created_by'],
                'justification' => $row['justification'],
            ];
        }
        $result->closeCursor();

        return $upcoming;
    }

    public function getProcessingLogs(int $limit = 100): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('metavox_ret_logs')
           ->orderBy('created_at', 'DESC')
           ->setMaxResults($limit);

        $result = $qb->execute();
        $logs = [];
        while ($row = $result->fetch()) {
            $logs[] = [
                'id' => (int)$row['id'],
                'file_id' => (int)$row['file_id'],
                'policy_id' => (int)$row['policy_id'],
                'action' => $row['action'],
                'status' => $row['status'],
                'message' => $row['message'],
                'file_path' => $row['file_path'],
                'target_path' => $row['target_path'],
                'created_at' => $row['created_at'],
            ];
        }
        $result->closeCursor();

        return $logs;
    }

public function getRetentionStatistics(): array {
    $stats = [];

    try {
        // Total policies
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) as count'))
           ->from('metavox_ret_policies');
        $result = $qb->execute();
        $stats['total_policies'] = (int)$result->fetchColumn();
        $result->closeCursor();

        // Active policies
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) as count'))
           ->from('metavox_ret_policies')
           ->where($qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
        $result = $qb->execute();
        $stats['active_policies'] = (int)$result->fetchColumn();
        $result->closeCursor();

        // Groupfolders with policies (using junction table)
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(DISTINCT groupfolder_id) as count'))
               ->from('metavox_policy_groupfolders');
            $result = $qb->execute();
            $stats['groupfolders_with_policies'] = (int)$result->fetchColumn();
            $result->closeCursor();
        } catch (\Exception $e) {
            error_log('MetaVox Retention: Error getting groupfolders with policies: ' . $e->getMessage());
            $stats['groupfolders_with_policies'] = 0;
        }

        // Groupfolders without policies
        try {
            $totalGroupfolders = $this->getTotalGroupfoldersCount();
            $stats['groupfolders_without_policies'] = $totalGroupfolders - $stats['groupfolders_with_policies'];
        } catch (\Exception $e) {
            error_log('MetaVox Retention: Error calculating groupfolders without policies: ' . $e->getMessage());
            $stats['groupfolders_without_policies'] = 0;
        }

        // Files with retention
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(*) as count'))
               ->from('metavox_file_retention')
               ->where($qb->expr()->eq('status', $qb->createNamedParameter('active')));
            $result = $qb->execute();
            $stats['files_with_retention'] = (int)$result->fetchColumn();
            $result->closeCursor();
        } catch (\Exception $e) {
            error_log('MetaVox Retention: Error getting files with retention: ' . $e->getMessage());
            $stats['files_with_retention'] = 0;
        }

        // Expired files
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(*) as count'))
               ->from('metavox_file_retention')
               ->where($qb->expr()->lte('expire_date', $qb->createNamedParameter(date('Y-m-d'))))
               ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('active')));
            $result = $qb->execute();
            $stats['expired_files'] = (int)$result->fetchColumn();
            $result->closeCursor();
        } catch (\Exception $e) {
            error_log('MetaVox Retention: Error getting expired files: ' . $e->getMessage());
            $stats['expired_files'] = 0;
        }

        // Actions this month  
        try {
            $firstOfMonth = date('Y-m-01');
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(*) as count'))
               ->from('metavox_ret_logs')
               ->where($qb->expr()->gte('created_at', $qb->createNamedParameter($firstOfMonth)));
            $result = $qb->execute();
            $stats['actions_this_month'] = (int)$result->fetchColumn();
            $result->closeCursor();
        } catch (\Exception $e) {
            error_log('MetaVox Retention: Error getting actions this month: ' . $e->getMessage());
            $stats['actions_this_month'] = 0;
        }

    } catch (\Exception $e) {
        error_log('MetaVox Retention: Error in getRetentionStatistics: ' . $e->getMessage());
        // Return default stats if there's an error
        $stats = [
            'total_policies' => 0,
            'active_policies' => 0,
            'groupfolders_with_policies' => 0,
            'groupfolders_without_policies' => 0,
            'files_with_retention' => 0,
            'expired_files' => 0,
            'actions_this_month' => 0
        ];
    }

    return $stats;
}

    private function getTotalGroupfoldersCount(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) as count'))
           ->from('group_folders');
        $result = $qb->execute();
        $count = (int)$result->fetchColumn();
        $result->closeCursor();
        return $count;
    }

    // ========================================
    // 🔧 HELPER FUNCTIONS
    // ========================================



    private function validateRetentionAgainstPolicy(array $retentionData, array $policy): void {
        $allowedPeriods = $policy['allowed_retention_periods'] ?? [];
        if (!empty($allowedPeriods)) {
            $requestedPeriod = $retentionData['retention_period'] . ' ' . ($retentionData['retention_unit'] ?? 'years');
            if (!in_array($requestedPeriod, $allowedPeriods)) {
                throw new \Exception('Retention period "' . $requestedPeriod . '" is not allowed by the policy. Allowed periods: ' . implode(', ', $allowedPeriods));
            }
        }

        if ($policy['require_justification'] && empty($retentionData['justification'])) {
            throw new \Exception('Justification is required by the retention policy.');
        }
    }


    private function calculateExpireDate(int $period, string $unit): string {
        $interval = '';
        switch ($unit) {
            case 'days':
                $interval = "+{$period} days";
                break;
            case 'weeks':
                $interval = "+{$period} weeks";
                break;
            case 'months':
                $interval = "+{$period} months";
                break;
            case 'years':
            default:
                $interval = "+{$period} years";
                break;
        }

        return date('Y-m-d', strtotime($interval));
    }

    public function validateRetentionSettings(int $fileId, int $retentionPeriod, string $retentionUnit): array {
        try {
            $policy = $this->findPolicyForFile($fileId);
            if (!$policy) {
                return ['valid' => false, 'message' => 'No retention policy found for this file'];
            }

            $allowedPeriods = $policy['allowed_retention_periods'] ?? [];
            if (!empty($allowedPeriods)) {
                $requestedPeriod = $retentionPeriod . ' ' . $retentionUnit;
                if (!in_array($requestedPeriod, $allowedPeriods)) {
                    return [
                        'valid' => false, 
                        'message' => 'Retention period not allowed',
                        'allowed_periods' => $allowedPeriods
                    ];
                }
            }

            return ['valid' => true, 'policy' => $policy];
        } catch (\Exception $e) {
            return ['valid' => false, 'message' => $e->getMessage()];
        }
    }

    // 🔧 FIXED: Explicit nullable parameter declaration
    public function previewRetentionDate(int $retentionPeriod, string $retentionUnit, ?string $startDate = null): array {
        $startDate = $startDate ?: date('Y-m-d');
        $expireDate = $this->calculateExpireDate($retentionPeriod, $retentionUnit);
        
        $daysUntilExpiry = (strtotime($expireDate) - strtotime($startDate)) / (60 * 60 * 24);
        
        return [
            'start_date' => $startDate,
            'expire_date' => $expireDate,
            'days_until_expiry' => (int)$daysUntilExpiry,
            'human_readable' => $retentionPeriod . ' ' . $retentionUnit . ' from ' . $startDate
        ];
    }

    private function getExpiredFiles(): array {
        $today = date('Y-m-d');
        
        $qb = $this->db->getQueryBuilder();
        $qb->select('fr.*', 'rp.auto_process')
           ->from('metavox_file_retention', 'fr')
           ->leftJoin('fr', 'metavox_ret_policies', 'rp', 'fr.policy_id = rp.id')
           ->where($qb->expr()->lte('fr.expire_date', $qb->createNamedParameter($today)))
           ->andWhere($qb->expr()->eq('fr.status', $qb->createNamedParameter('active')))
           ->andWhere($qb->expr()->eq('rp.auto_process', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));

        $result = $qb->execute();
        $expired = [];
        while ($row = $result->fetch()) {
            $expired[] = [
                'id' => (int)$row['id'],
                'file_id' => (int)$row['file_id'],
                'policy_id' => (int)$row['policy_id'],
                'expire_date' => $row['expire_date'],
                'action' => $row['action'],
                'target_path' => $row['target_path'],
                'created_by' => $row['created_by'],
            ];
        }
        $result->closeCursor();

        return $expired;
    }

    private function processExpiredFile(array $retention, bool $dryRun): array {
        $fileId = $retention['file_id'];
        $action = $retention['action'];
        $targetPath = $retention['target_path'];
        
        $result = [
            'file_id' => $fileId,
            'action' => $action,
            'target_path' => $targetPath,
            'dry_run' => $dryRun,
            'success' => false,
            'message' => '',
            'original_path' => '',
            'final_path' => ''
        ];

        try {
            // Get original file path for logging
            $user = $this->userSession->getUser();
            if ($user) {
                $userFolder = $this->rootFolder->getUserFolder($user->getUID());
                $files = $userFolder->getById($fileId);
                if (!empty($files)) {
                    $result['original_path'] = $files[0]->getPath();
                }
            }

            if ($dryRun) {
                $result['success'] = true;
                $result['message'] = 'Dry run: Would ' . $action . ' file to ' . $targetPath;
                $result['final_path'] = $targetPath;
            } else {
                switch ($action) {
                    case 'move':
                        $moveResult = $this->moveFile($fileId, $targetPath);
                        $result['success'] = $moveResult['success'];
                        $result['message'] = $moveResult['message'];
                        $result['final_path'] = $moveResult['final_path'] ?? $targetPath;
                        break;
                    case 'delete':
                        $deleteResult = $this->deleteFile($fileId);
                        $result['success'] = $deleteResult['success'];
                        $result['message'] = $deleteResult['message'];
                        $result['final_path'] = '[DELETED]';
                        break;
                    case 'archive':
                        $archiveResult = $this->archiveFile($fileId, $targetPath);
                        $result['success'] = $archiveResult['success'];
                        $result['message'] = $archiveResult['message'];
                        $result['final_path'] = $archiveResult['final_path'] ?? $targetPath;
                        break;
                    default:
                        $result['message'] = 'Unknown action: ' . $action;
                        break;
                }

                if ($result['success']) {
                    // Update retention status
                    $this->updateRetentionStatus($retention['id'], 'processed');
                }
            }

            // Log the action
            $this->logRetentionAction($retention, $result);

        } catch (\Exception $e) {
            $result['message'] = 'Error: ' . $e->getMessage();
            error_log('MetaVox Retention: Error processing file ' . $fileId . ': ' . $e->getMessage());
            error_log('MetaVox Retention: Stack trace: ' . $e->getTraceAsString());
        }

        return $result;
    }

    // ========================================
    // 🔧 FIXED FILE OPERATIONS WITH PATH HANDLING
    // ========================================

    private function moveFile(int $fileId, string $targetPath): array {
        $result = ['success' => false, 'message' => '', 'final_path' => ''];
        
        try {
            error_log('MetaVox Retention: Starting moveFile - FileID: ' . $fileId . ', TargetPath: ' . $targetPath);
            
            $user = $this->userSession->getUser();
            if (!$user) {
                $result['message'] = 'No user session available';
                return $result;
            }

            // Get the file/folder
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            $files = $userFolder->getById($fileId);
            
            if (empty($files)) {
                $result['message'] = 'File/folder not found with ID: ' . $fileId;
                return $result;
            }

            $node = $files[0];
            $originalPath = $node->getPath();
            $nodeName = $node->getName();
            
            error_log('MetaVox Retention: Found node: ' . $originalPath . ' (Name: ' . $nodeName . ')');
            
            // 🔧 FIXED: Proper target path resolution
            $resolvedTarget = $this->resolveTargetPath($userFolder, $targetPath, $nodeName);
            $targetDir = dirname($resolvedTarget);
            
            error_log('MetaVox Retention: Resolved target: ' . $resolvedTarget);
            error_log('MetaVox Retention: Target directory: ' . $targetDir);
            
            // Create target directory if needed
            $this->ensureDirectoryExists($userFolder, $targetDir);
            
            // Get unique filename if target exists
            $finalTargetPath = $this->getUniqueTargetPath($userFolder, $resolvedTarget);
            $result['final_path'] = $this->getUserVisiblePath($finalTargetPath, $user->getUID());
            
            error_log('MetaVox Retention: Final target path: ' . $finalTargetPath);
            
            // Get target directory node
            $targetDirNode = $userFolder->get($this->getRelativePath($targetDir));
            $finalNodeName = basename($finalTargetPath);
            
            if ($node instanceof File) {
                // 📄 MOVE FILE
                error_log('MetaVox Retention: Moving file: ' . $nodeName);
                
                // Create new file in target location
                $newFile = $targetDirNode->newFile($finalNodeName);
                
                // Copy content from the File object
                $newFile->putContent($node->getContent());
                
                // Verify the copy was successful
                if ($newFile->getSize() === $node->getSize()) {
                    // Delete original file
                    $node->delete();
                    $result['success'] = true;
                    $result['message'] = 'File successfully moved from ' . $originalPath . ' to ' . $result['final_path'];
                    error_log('MetaVox Retention: ' . $result['message']);
                } else {
                    // Clean up failed copy
                    $newFile->delete();
                    $result['message'] = 'File copy verification failed - size mismatch';
                    error_log('MetaVox Retention: ' . $result['message']);
                }
                
            } elseif ($node instanceof Folder) {
                // 📁 MOVE FOLDER
                error_log('MetaVox Retention: Moving folder: ' . $nodeName);
                
                // Copy the entire folder to target location
                $success = $this->copyFolderRecursively($node, $targetDirNode, $finalNodeName);
                
                if ($success) {
                    // Delete original folder
                    $node->delete();
                    $result['success'] = true;
                    $result['message'] = 'Folder successfully moved from ' . $originalPath . ' to ' . $result['final_path'];
                    error_log('MetaVox Retention: ' . $result['message']);
                } else {
                    $result['message'] = 'Folder move failed during copy operation';
                    error_log('MetaVox Retention: ' . $result['message']);
                }
                
            } else {
                $result['message'] = 'Unknown node type for: ' . $nodeName;
                error_log('MetaVox Retention: ' . $result['message']);
            }
            
        } catch (\Exception $e) {
            $result['message'] = 'Move failed: ' . $e->getMessage();
            error_log('MetaVox Retention: Error in moveFile: ' . $e->getMessage());
            error_log('MetaVox Retention: Stack trace: ' . $e->getTraceAsString());
        }

        return $result;
    }

    private function deleteFile(int $fileId): array {
        $result = ['success' => false, 'message' => ''];
        
        try {
            error_log('MetaVox Retention: Starting deleteFile - FileID: ' . $fileId);
            
            $user = $this->userSession->getUser();
            if (!$user) {
                $result['message'] = 'No user session available';
                return $result;
            }

            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            $files = $userFolder->getById($fileId);
            
            if (empty($files)) {
                $result['message'] = 'File not found with ID: ' . $fileId;
                return $result;
            }

            $file = $files[0];
            $originalPath = $file->getPath();
            
            // Delete the file
            $file->delete();
            
            $result['success'] = true;
            $result['message'] = 'File successfully deleted: ' . $originalPath;
            error_log('MetaVox Retention: ' . $result['message']);
            
        } catch (\Exception $e) {
            $result['message'] = 'Delete failed: ' . $e->getMessage();
            error_log('MetaVox Retention: Error in deleteFile: ' . $e->getMessage());
        }

        return $result;
    }

    private function archiveFile(int $fileId, string $targetPath): array {
        $result = ['success' => false, 'message' => '', 'final_path' => ''];
        
        try {
            error_log('MetaVox Retention: Starting archiveFile - FileID: ' . $fileId . ', TargetPath: ' . $targetPath);
            
            $user = $this->userSession->getUser();
            if (!$user) {
                $result['message'] = 'No user session available';
                return $result;
            }

            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            $files = $userFolder->getById($fileId);
            
            if (empty($files)) {
                $result['message'] = 'File/folder not found with ID: ' . $fileId;
                return $result;
            }

            $node = $files[0];
            $originalPath = $node->getPath();
            $nodeName = $node->getName();
            
            // 🔧 FIXED: Resolve archive path properly
            $resolvedTarget = $this->resolveTargetPath($userFolder, $targetPath, '');
            $baseTargetDir = $this->getRelativePath($resolvedTarget);
            
            // Create archive directory with timestamp
            $archiveDir = $baseTargetDir . '/archive_' . date('Y-m-d');
            $this->ensureDirectoryExists($userFolder, $archiveDir);
            
            // Create archived name with timestamp
            $timestamp = date('Y-m-d_H-i-s');
            
            if ($node instanceof File) {
                // 📄 ARCHIVE FILE
                error_log('MetaVox Retention: Archiving file: ' . $nodeName);
                
                $pathInfo = pathinfo($nodeName);
                $archivedFileName = $timestamp . '_' . $pathInfo['filename'] . '.' . ($pathInfo['extension'] ?? 'txt');
                
                $archiveFilePath = $archiveDir . '/' . $archivedFileName;
                $uniqueArchivePath = $this->getUniqueTargetPath($userFolder, $archiveFilePath);
                $result['final_path'] = $this->getUserVisiblePath($uniqueArchivePath, $user->getUID());
                
                error_log('MetaVox Retention: Archive file path: ' . $uniqueArchivePath);
                
                // Get archive directory node and create archived file
                $archiveDirNode = $userFolder->get($archiveDir);
                $archivedFile = $archiveDirNode->newFile(basename($uniqueArchivePath));
                
                // Copy content to archived file
                $archivedFile->putContent($node->getContent());
                
                // Verify the archive was successful
                if ($archivedFile->getSize() === $node->getSize()) {
                    // Delete original file
                    $node->delete();
                    $result['success'] = true;
                    $result['message'] = 'File successfully archived from ' . $originalPath . ' to ' . $result['final_path'];
                    error_log('MetaVox Retention: ' . $result['message']);
                } else {
                    // Clean up failed archive
                    $archivedFile->delete();
                    $result['message'] = 'File archive verification failed - size mismatch';
                    error_log('MetaVox Retention: ' . $result['message']);
                }
                
            } elseif ($node instanceof Folder) {
                // 📁 ARCHIVE FOLDER
                error_log('MetaVox Retention: Archiving folder: ' . $nodeName);
                
                $archivedFolderName = $timestamp . '_' . $nodeName;
                $archiveFolderPath = $archiveDir . '/' . $archivedFolderName;
                $uniqueArchivePath = $this->getUniqueTargetPath($userFolder, $archiveFolderPath);
                $result['final_path'] = $this->getUserVisiblePath($uniqueArchivePath, $user->getUID());
                
                error_log('MetaVox Retention: Archive folder path: ' . $uniqueArchivePath);
                
                // Get archive directory node
                $archiveDirNode = $userFolder->get($archiveDir);
                
                // Copy the entire folder to archive location
                $success = $this->copyFolderRecursively($node, $archiveDirNode, basename($uniqueArchivePath));
                
                if ($success) {
                    // Delete original folder
                    $node->delete();
                    $result['success'] = true;
                    $result['message'] = 'Folder successfully archived from ' . $originalPath . ' to ' . $result['final_path'];
                    error_log('MetaVox Retention: ' . $result['message']);
                } else {
                    $result['message'] = 'Folder archive failed during copy operation';
                    error_log('MetaVox Retention: ' . $result['message']);
                }
                
            } else {
                $result['message'] = 'Unknown node type for: ' . $nodeName;
                error_log('MetaVox Retention: ' . $result['message']);
            }
            
        } catch (\Exception $e) {
            $result['message'] = 'Archive failed: ' . $e->getMessage();
            error_log('MetaVox Retention: Error in archiveFile: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * 🆕 NEW: Recursively copy folder contents
     */
    private function copyFolderRecursively(Folder $sourceFolder, Folder $targetParent, string $targetFolderName): bool {
        try {
            error_log('MetaVox Retention: Copying folder ' . $sourceFolder->getName() . ' to ' . $targetFolderName);
            
            // Create target folder
            $targetFolder = $targetParent->newFolder($targetFolderName);
            
            // Get all nodes in source folder
            $sourceNodes = $sourceFolder->getDirectoryListing();
            
            foreach ($sourceNodes as $sourceNode) {
                if ($sourceNode instanceof File) {
                    // Copy file
                    error_log('MetaVox Retention: Copying file: ' . $sourceNode->getName());
                    $targetFile = $targetFolder->newFile($sourceNode->getName());
                    $targetFile->putContent($sourceNode->getContent());
                    
                    // Verify file copy
                    if ($targetFile->getSize() !== $sourceNode->getSize()) {
                        error_log('MetaVox Retention: File copy size mismatch: ' . $sourceNode->getName());
                        return false;
                    }
                    
                } elseif ($sourceNode instanceof Folder) {
                    // Recursively copy subfolder
                    error_log('MetaVox Retention: Copying subfolder: ' . $sourceNode->getName());
                    $success = $this->copyFolderRecursively($sourceNode, $targetFolder, $sourceNode->getName());
                    if (!$success) {
                        error_log('MetaVox Retention: Subfolder copy failed: ' . $sourceNode->getName());
                        return false;
                    }
                }
            }
            
            error_log('MetaVox Retention: Successfully copied folder: ' . $sourceFolder->getName());
            return true;
            
        } catch (\Exception $e) {
            error_log('MetaVox Retention: Error copying folder recursively: ' . $e->getMessage());
            return false;
        }
    }

    // ========================================
    // 🛠️ FIXED PATH RESOLUTION HELPERS
    // ========================================

    /**
     * 🔧 FIXED: Resolve target path to proper user-relative path
     * FIXED: Parameter order - required parameters before optional ones
     */
    private function resolveTargetPath($userFolder, string $targetPath, string $fileName = ''): string {
        // Clean input path
        $targetPath = trim($targetPath, '/');
        
        // If empty, use root
        if (empty($targetPath)) {
            $targetPath = $fileName;
        }
        
        // If path ends with /, append filename
        if (str_ends_with($targetPath, '/') && !empty($fileName)) {
            $targetPath .= $fileName;
        }
        
        // If no filename specified and path doesn't end with extension, append filename
        if (!empty($fileName) && !str_contains(basename($targetPath), '.')) {
            $targetPath = rtrim($targetPath, '/') . '/' . $fileName;
        }
        
        return $targetPath;
    }

    // ========================================
    // 🔧 FIX 2: Missing methods
    // ========================================

    /**
     * 🆕 NEW: Get file IDs for given paths within a groupfolder
     */
    private function getFileIdsForPaths(array $paths, int $groupfolderId): array {
        try {
            error_log('MetaVox Retention: Getting file IDs for paths: ' . json_encode($paths));
            
            $result = [];
            
            // Get all groupfolders to find the mount point
            $groupfolders = $this->getAllGroupfoldersFromDB();
            $mountPoint = null;
            
            foreach ($groupfolders as $gf) {
                if ($gf['id'] == $groupfolderId) {
                    $mountPoint = $gf['mount_point'];
                    break;
                }
            }
            
            if (!$mountPoint) {
                error_log('MetaVox Retention: Mount point not found for groupfolder: ' . $groupfolderId);
                return $result;
            }
            
            error_log('MetaVox Retention: Found mount point: ' . $mountPoint);
            
            // Try to get files via the current user's folder
            $user = $this->userSession->getUser();
            if (!$user) {
                error_log('MetaVox Retention: No user session available');
                return $result;
            }
            
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            
            foreach ($paths as $path) {
                try {
                    // Convert path to be relative to user folder
                    $searchPath = $this->convertPathForSearch($path, $mountPoint);
                    error_log('MetaVox Retention: Searching for path: ' . $searchPath);
                    
                    if ($userFolder->nodeExists($searchPath)) {
                        $node = $userFolder->get($searchPath);
                        $fileId = $node->getId();
                        $result[$path] = $fileId;
                        error_log('MetaVox Retention: Found file ID ' . $fileId . ' for path: ' . $path);
                    } else {
                        error_log('MetaVox Retention: Path not found: ' . $searchPath);
                    }
                } catch (\Exception $e) {
                    error_log('MetaVox Retention: Error getting file ID for path ' . $path . ': ' . $e->getMessage());
                }
            }
            
            error_log('MetaVox Retention: Final file ID mapping: ' . json_encode($result));
            return $result;
            
        } catch (\Exception $e) {
            error_log('MetaVox Retention: Error in getFileIdsForPaths: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 🆕 NEW: Convert path for search within user folder
     */
    private function convertPathForSearch(string $path, string $mountPoint): string {
        // Remove leading slash
        $path = ltrim($path, '/');
        $mountPoint = ltrim($mountPoint, '/');
        
        // If path starts with the mount point, use as-is
        if (strpos($path, $mountPoint) === 0) {
            return $path;
        }
        
        // If path doesn't include mount point, prepend it
        if (!empty($path)) {
            return $mountPoint . '/' . $path;
        }
        
        // Root of groupfolder
        return $mountPoint;
    }

    /**
     * 🆕 NEW: Get retention records for multiple file IDs
     */
    private function getRetentionRecordsForFiles(array $fileIds): array {
        if (empty($fileIds)) {
            return [];
        }
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('fr.*')
               ->from('metavox_file_retention', 'fr')
               ->where($qb->expr()->in('fr.file_id', $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)));

            $result = $qb->execute();
            $records = [];
            
            while ($row = $result->fetch()) {
                $records[] = [
                    'id' => (int)$row['id'],
                    'file_id' => (int)$row['file_id'],
                    'policy_id' => (int)$row['policy_id'],
                    'retention_period' => (int)$row['retention_period'],
                    'retention_unit' => $row['retention_unit'],
                    'expire_date' => $row['expire_date'],
                    'action' => $row['action'],
                    'target_path' => $row['target_path'],
                    'justification' => $row['justification'],
                    'status' => $row['status'],
                    'created_by' => $row['created_by'],
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                ];
            }
            $result->closeCursor();
            
            error_log('MetaVox Retention: Found ' . count($records) . ' retention records for file IDs: ' . json_encode($fileIds));
            return $records;
            
        } catch (\Exception $e) {
            error_log('MetaVox Retention: Error getting retention records: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 🆕 NEW: Sort paths by depth (shortest first)
     */
    private function sortPathsByDepth(array $paths): array {
        usort($paths, function($a, $b) {
            $depthA = substr_count(trim($a, '/'), '/');
            $depthB = substr_count(trim($b, '/'), '/');
            return $depthA - $depthB;
        });
        
        return $paths;
    }

    /**
     * 🆕 NEW: Get all groupfolders from database
     */
    private function getAllGroupfoldersFromDB(): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('folder_id as id', 'mount_point')
               ->from('group_folders');

            $result = $qb->execute();
            $groupfolders = [];
            
            while ($row = $result->fetch()) {
                $groupfolders[] = [
                    'id' => (int)$row['id'],
                    'mount_point' => $row['mount_point']
                ];
            }
            $result->closeCursor();
            
            return $groupfolders;
            
        } catch (\Exception $e) {
            error_log('MetaVox Retention: Error getting groupfolders from DB: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 🆕 NEW: Build path hierarchy for conflict checking
     */
    private function buildPathHierarchy(string $filePath): array {
        // Convert file path to relative paths we need to check
        $filePath = trim($filePath, '/');
        
        // For a file like /user/files/teamfolder/subfolder/file.txt
        // we want to check: [/teamfolder/subfolder/file.txt, /teamfolder/subfolder, /teamfolder]
        
        $pathParts = explode('/', $filePath);
        $paths = [];
        
        // Remove user and files parts if present
        if (count($pathParts) >= 3 && $pathParts[1] === 'files') {
            // Remove /user/files/ part
            $pathParts = array_slice($pathParts, 2);
        }
        
        // Build hierarchy from specific to general
        $currentPath = '';
        for ($i = 0; $i < count($pathParts); $i++) {
            if ($i === 0) {
                $currentPath = '/' . $pathParts[$i];
            } else {
                $currentPath .= '/' . $pathParts[$i];
            }
            $paths[] = $currentPath;
        }
        
        // Return in reverse order (most specific first)
        return array_reverse($paths);
    }

    /**
     * 🔧 FIXED: Get user-visible path for display
     */
    private function getUserVisiblePath(string $internalPath, string $userId): string {
        $relativePath = $this->getRelativePath($internalPath);
        return '/' . $userId . '/files/' . $relativePath;
    }

    /**
     * 🔧 FIXED: Convert full path to user folder relative path
     */
    private function getRelativePath(string $path): string {
        // Remove leading slash
        $path = ltrim($path, '/');
        
        // If already relative, return as-is
        if (!str_contains($path, '/files/')) {
            return $path;
        }
        
        // Extract path after /files/
        $parts = explode('/files/', $path, 2);
        return isset($parts[1]) ? $parts[1] : $path;
    }

    /**
     * 🔧 FIXED: Ensure directory exists using relative paths
     */
    private function ensureDirectoryExists($userFolder, string $dirPath): void {
        try {
            $relativePath = $this->getRelativePath($dirPath);
            
            if (empty($relativePath) || $relativePath === '.') {
                return; // Root already exists
            }
            
            $pathParts = explode('/', $relativePath);
            $currentPath = '';
            
            foreach ($pathParts as $part) {
                if (empty($part)) continue;
                
                $currentPath .= ($currentPath ? '/' : '') . $part;
                
                try {
                    if (!$userFolder->nodeExists($currentPath)) {
                        $userFolder->newFolder($currentPath);
                        error_log('MetaVox Retention: Created directory: ' . $currentPath);
                    }
                } catch (\Exception $e) {
                    error_log('MetaVox Retention: Error creating directory ' . $currentPath . ': ' . $e->getMessage());
                    throw $e;
                }
            }
        } catch (\Exception $e) {
            error_log('MetaVox Retention: Error ensuring directory exists: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 🔧 FIXED: Get unique target path to avoid conflicts
     */
    private function getUniqueTargetPath($userFolder, string $targetPath): string {
        $relativePath = $this->getRelativePath($targetPath);
        
        if (!$userFolder->nodeExists($relativePath)) {
            return $relativePath;
        }
        
        $pathInfo = pathinfo($relativePath);
        $directory = $pathInfo['dirname'] === '.' ? '' : $pathInfo['dirname'] . '/';
        $filename = $pathInfo['filename'];
        $extension = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';
        
        $counter = 1;
        do {
            $newRelativePath = $directory . $filename . '_' . $counter . $extension;
            $counter++;
        } while ($userFolder->nodeExists($newRelativePath) && $counter < 1000);
        
        return $newRelativePath;
    }

    private function updateRetentionStatus(int $retentionId, string $status): void {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->update('metavox_file_retention')
               ->set('status', $qb->createNamedParameter($status))
               ->set('updated_at', $qb->createNamedParameter(date('Y-m-d H:i:s')))
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($retentionId, IQueryBuilder::PARAM_INT)));
            $qb->execute();
        } catch (\Exception $e) {
            error_log('MetaVox Retention: Error updating retention status: ' . $e->getMessage());
        }
    }

    private function logRetentionAction(array $retention, array $result): void {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('metavox_ret_logs')
               ->values([
                   'file_id' => $qb->createNamedParameter($retention['file_id'], IQueryBuilder::PARAM_INT),
                   'policy_id' => $qb->createNamedParameter($retention['policy_id'], IQueryBuilder::PARAM_INT),
                   'action' => $qb->createNamedParameter($retention['action']),
                   'status' => $qb->createNamedParameter($result['success'] ? 'success' : 'failed'),
                   'message' => $qb->createNamedParameter($result['message']),
                   'file_path' => $qb->createNamedParameter($result['original_path'] ?? ''),
                   'target_path' => $qb->createNamedParameter($result['final_path'] ?? $retention['target_path']),
                   'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
               ]);
            $qb->execute();
        } catch (\Exception $e) {
            error_log('MetaVox Retention: Error logging retention action: ' . $e->getMessage());
        }
    }
}