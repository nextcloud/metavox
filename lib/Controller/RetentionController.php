<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Service\RetentionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Retention Workflow Controller - Enhanced for One Policy -> Multiple Groupfolders
 * UPDATED: Support one retention policy applied to multiple groupfolders
 */
class RetentionController extends Controller {

    private RetentionService $retentionService;
    private IUserSession $userSession;

    public function __construct(
        string $appName, 
        IRequest $request, 
        RetentionService $retentionService,
        IUserSession $userSession
    ) {
        parent::__construct($appName, $request);
        $this->retentionService = $retentionService;
        $this->userSession = $userSession;
    }

    // ========================================
    // 🔑 ADMIN POLICIES - "HOW & WHERE" - ENHANCED
    // ========================================

    /**
     * Get all retention policies (admin only)
     * @NoAdminRequired
     * @IsAdmin
     */
    public function getPolicies(): JSONResponse {
        try {
            $policies = $this->retentionService->getAllPolicies();
            return new JSONResponse($policies);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * 🆕 NEW: Get all policies for a specific groupfolder
     * @NoAdminRequired
     * @IsAdmin
     */
    public function getPoliciesForGroupfolder(int $groupfolderId): JSONResponse {
        try {
            $policies = $this->retentionService->getPoliciesForGroupfolder($groupfolderId);
            return new JSONResponse($policies);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get single retention policy with assigned groupfolders (admin only)
     * @NoAdminRequired
     * @IsAdmin
     */
    public function getPolicy(int $id): JSONResponse {
        try {
            $policy = $this->retentionService->getPolicyById($id);
            if (!$policy) {
                return new JSONResponse(['error' => 'Policy not found'], 404);
            }
            return new JSONResponse($policy);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Create retention policy with multiple groupfolders (admin only)
     * @NoAdminRequired
     * @IsAdmin
     */
    public function createPolicy(): JSONResponse {
        try {
            // Parse allowed retention periods
            $allowedPeriodsParam = $this->request->getParam('allowed_retention_periods', []);
            $allowedPeriods = [];
            
            if (is_string($allowedPeriodsParam)) {
                $allowedPeriods = array_filter(
                    array_map('trim', explode("\n", $allowedPeriodsParam)),
                    function($period) { return !empty($period); }
                );
            } elseif (is_array($allowedPeriodsParam)) {
                $allowedPeriods = $allowedPeriodsParam;
            }

            // Parse groupfolder IDs
            $groupfolderIdsParam = $this->request->getParam('groupfolder_ids', []);
            $groupfolderIds = [];
            
            if (is_string($groupfolderIdsParam)) {
                $groupfolderIds = array_filter(
                    array_map('intval', explode(',', $groupfolderIdsParam)),
                    function($id) { return $id > 0; }
                );
            } elseif (is_array($groupfolderIdsParam)) {
                $groupfolderIds = array_map('intval', $groupfolderIdsParam);
            }

            $policyData = [
                'name' => $this->request->getParam('name'),
                'description' => $this->request->getParam('description'),
                'groupfolder_ids' => $groupfolderIds, // NEW: Multiple groupfolders
                'is_active' => $this->convertToBoolean($this->request->getParam('is_active', true)),
                'default_action' => $this->request->getParam('default_action', 'move'),
                'default_target_path' => $this->request->getParam('default_target_path'),
                'notify_before_days' => (int)$this->request->getParam('notify_before_days', 7),
                'auto_process' => $this->convertToBoolean($this->request->getParam('auto_process', true)),
                'allowed_retention_periods' => $allowedPeriods,
                'require_justification' => $this->convertToBoolean($this->request->getParam('require_justification', false)),
            ];

            $id = $this->retentionService->createPolicy($policyData);
            return new JSONResponse(['id' => $id, 'success' => true]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update retention policy with groupfolders (admin only)
     * @NoAdminRequired
     * @IsAdmin
     */
    public function updatePolicy(int $id): JSONResponse {
        try {
            // Parse allowed retention periods
            $allowedPeriodsParam = $this->request->getParam('allowed_retention_periods', []);
            $allowedPeriods = [];
            
            if (is_string($allowedPeriodsParam)) {
                $allowedPeriods = array_filter(
                    array_map('trim', explode("\n", $allowedPeriodsParam)),
                    function($period) { return !empty($period); }
                );
            } elseif (is_array($allowedPeriodsParam)) {
                $allowedPeriods = $allowedPeriodsParam;
            }

            // Parse groupfolder IDs
            $groupfolderIdsParam = $this->request->getParam('groupfolder_ids', []);
            $groupfolderIds = [];
            
            if (is_string($groupfolderIdsParam)) {
                $groupfolderIds = array_filter(
                    array_map('intval', explode(',', $groupfolderIdsParam)),
                    function($id) { return $id > 0; }
                );
            } elseif (is_array($groupfolderIdsParam)) {
                $groupfolderIds = array_map('intval', $groupfolderIdsParam);
            }

            $policyData = [
                'name' => $this->request->getParam('name'),
                'description' => $this->request->getParam('description'),
                'groupfolder_ids' => $groupfolderIds, // NEW: Multiple groupfolders
                'is_active' => $this->convertToBoolean($this->request->getParam('is_active')),
                'default_action' => $this->request->getParam('default_action'),
                'default_target_path' => $this->request->getParam('default_target_path'),
                'notify_before_days' => (int)$this->request->getParam('notify_before_days'),
                'auto_process' => $this->convertToBoolean($this->request->getParam('auto_process')),
                'allowed_retention_periods' => $allowedPeriods,
                'require_justification' => $this->convertToBoolean($this->request->getParam('require_justification')),
            ];

            $success = $this->retentionService->updatePolicy($id, $policyData);
            return new JSONResponse(['success' => $success]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

        /**
     * NEW: Assign groupfolders to policy (admin only)
     * @NoAdminRequired
     * @IsAdmin
     */
    public function assignGroupfoldersToPolicy(int $policyId): JSONResponse {
        try {
            $groupfolderIdsParam = $this->request->getParam('groupfolder_ids', []);
            $groupfolderIds = [];
            
            if (is_string($groupfolderIdsParam)) {
                $groupfolderIds = array_filter(
                    array_map('intval', explode(',', $groupfolderIdsParam)),
                    function($id) { return $id > 0; }
                );
            } elseif (is_array($groupfolderIdsParam)) {
                $groupfolderIds = array_map('intval', $groupfolderIdsParam);
            }

            $success = $this->retentionService->assignGroupfoldersToPolicy($policyId, $groupfolderIds);
            
            return new JSONResponse([
                'success' => $success,
                'assigned_count' => count($groupfolderIds),
                'message' => 'Policy assigned to ' . count($groupfolderIds) . ' Team folders'
            ]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

/**
 * NEW: Assign multiple policies to a folder (admin only)
 * @NoAdminRequired
 * @IsAdmin
 */
public function assignPoliciesToFolder(): JSONResponse {
    try {
        $folderId = (int)$this->request->getParam('folder_id');
        $policyIdsParam = $this->request->getParam('policy_ids', '');
        
        error_log('MetaVox Retention: assignPoliciesToFolder called with folderId: ' . $folderId . ', policyIds: ' . $policyIdsParam);
        
        if (!$folderId) {
            return new JSONResponse(['error' => 'Folder ID is required'], 400);
        }
        
        // Parse policy IDs
        $policyIds = array_filter(
            array_map('intval', explode(',', $policyIdsParam)),
            function($id) { return $id > 0; }
        );
        
        if (empty($policyIds)) {
            return new JSONResponse(['error' => 'At least one policy ID is required'], 400);
        }
        
        error_log('MetaVox Retention: Parsed policy IDs: ' . json_encode($policyIds));
        
        $successCount = 0;
        $errorCount = 0;
        
        // For each policy, add this folder to its assignments
        foreach ($policyIds as $policyId) {
            try {
                // Get current folder assignments for this policy
                $currentFolders = $this->retentionService->getGroupfoldersForPolicy($policyId);
                $currentFolderIds = array_map(function($f) { return $f['id']; }, $currentFolders);
                
                error_log('MetaVox Retention: Current folders for policy ' . $policyId . ': ' . json_encode($currentFolderIds));
                
                // Add the new folder if not already assigned
                if (!in_array($folderId, $currentFolderIds)) {
                    $currentFolderIds[] = $folderId;
                    
                    // Update the policy assignments
                    $success = $this->retentionService->assignGroupfoldersToPolicy($policyId, $currentFolderIds);
                    if ($success) {
                        $successCount++;
                        error_log('MetaVox Retention: Successfully assigned policy ' . $policyId . ' to folder ' . $folderId);
                    } else {
                        $errorCount++;
                        error_log('MetaVox Retention: Failed to assign policy ' . $policyId . ' to folder ' . $folderId);
                    }
                } else {
                    $successCount++; // Already assigned, count as success
                    error_log('MetaVox Retention: Policy ' . $policyId . ' already assigned to folder ' . $folderId);
                }
            } catch (\Exception $e) {
                error_log('MetaVox Retention: Error assigning policy ' . $policyId . ' to folder ' . $folderId . ': ' . $e->getMessage());
                $errorCount++;
            }
        }
        
        if ($successCount > 0) {
            return new JSONResponse([
                'success' => true,
                'assigned_count' => $successCount,
                'error_count' => $errorCount,
                'message' => "Successfully assigned {$successCount} policies to folder"
            ]);
        } else {
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to assign any policies to folder'
            ], 500);
        }
        
    } catch (\Exception $e) {
        error_log('MetaVox Retention: Error in assignPoliciesToFolder: ' . $e->getMessage());
        return new JSONResponse(['error' => $e->getMessage()], 500);
    }
}

/**
 * Get groupfolders for specific policy (admin only)
 * @NoAdminRequired
 * @IsAdmin
 */
public function getGroupfoldersForPolicy(int $policyId): JSONResponse {
    try {
        $groupfolders = $this->retentionService->getGroupfoldersForPolicy($policyId);
        return new JSONResponse($groupfolders);
    } catch (\Exception $e) {
        return new JSONResponse(['error' => $e->getMessage()], 500);
    }
}

    /**
     * NEW: Get groupfolders without any policy (admin only)
     * @NoAdminRequired
     * @IsAdmin
     */
    public function getGroupfoldersWithoutPolicy(): JSONResponse {
        try {
            $groupfolders = $this->retentionService->getGroupfoldersWithoutPolicy();
            return new JSONResponse($groupfolders);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete retention policy (admin only)
     * @NoAdminRequired
     * @IsAdmin
     */
    public function deletePolicy(int $id): JSONResponse {
        try {
            $success = $this->retentionService->deletePolicy($id);
            return new JSONResponse(['success' => $success]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Toggle policy active status (admin only)
     * @NoAdminRequired
     * @IsAdmin
     */
    public function togglePolicy(int $id): JSONResponse {
        try {
            $isActive = $this->convertToBoolean($this->request->getParam('is_active', false));
            $success = $this->retentionService->togglePolicyStatus($id, $isActive);
            return new JSONResponse(['success' => $success]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    // ========================================
    // 🎯 USER RETENTION - "WHEN"
    // ========================================

    /**
     * Get retention info for a file (user accessible)
     * @NoAdminRequired
     */
    public function getFileRetention(int $fileId): JSONResponse {
        try {
            $retention = $this->retentionService->getFileRetention($fileId);
            return new JSONResponse($retention);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Set retention for a file (user action)
     * @NoAdminRequired
     */
    public function setFileRetention(int $fileId): JSONResponse {
        try {
            $retentionData = [
                'retention_period' => (int)$this->request->getParam('retention_period'),
                'retention_unit' => $this->request->getParam('retention_unit', 'years'),
                'action' => $this->request->getParam('action'),
                'target_path' => $this->request->getParam('target_path'),
                'justification' => $this->request->getParam('justification'),
                'notify_before_days' => (int)$this->request->getParam('notify_before_days'),
            ];

            $success = $this->retentionService->setFileRetention($fileId, $retentionData);
            return new JSONResponse(['success' => $success]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove retention policy for a file (user action)
     * @NoAdminRequired
     */
    public function removeFileRetention(int $fileId): JSONResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }
            
            $success = $this->retentionService->removeFileRetention($fileId);
            
            if ($success) {
                return new JSONResponse([
                    'success' => true,
                    'message' => 'Retention policy removed successfully'
                ]);
            } else {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'No retention policy found for this file'
                ], 404);
            }
            
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'message' => 'Error removing retention policy: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get policies available for a groupfolder (user needs to see options)
     * @NoAdminRequired
     */
    public function getGroupfolderPolicies(int $groupfolderId): JSONResponse {
        try {
            $policies = $this->retentionService->getPoliciesForGroupfolder($groupfolderId);
            return new JSONResponse($policies);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get retention policy for a groupfolder (backward compatibility)
     * @NoAdminRequired
     */
    public function getGroupfolderPolicy(int $groupfolderId): JSONResponse {
        try {
            $policy = $this->retentionService->getPolicyForGroupfolder($groupfolderId);
            return new JSONResponse($policy);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

   /**
     * Get user's retention overview (files with retention set)
     * @NoAdminRequired
     */
    public function getUserRetentionOverview(): JSONResponse {
        try {
            $overview = $this->retentionService->getUserRetentionOverview();
            return new JSONResponse($overview);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    // ========================================
    // 📊 MONITORING & PROCESSING
    // ========================================

    /**
     * Get retention processing logs (admin only)
     * @NoAdminRequired
     * @IsAdmin
     */
    public function getProcessingLogs(): JSONResponse {
        try {
            $logs = $this->retentionService->getProcessingLogs();
            return new JSONResponse($logs);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get upcoming retention actions (admin overview)
     * @NoAdminRequired
     * @IsAdmin
     */
    public function getUpcomingActions(): JSONResponse {
        try {
            $daysParam = $this->request->getParam('days', 30);
            $days = is_numeric($daysParam) ? (int)$daysParam : 30;
            $actions = $this->retentionService->getUpcomingActions($days);
            return new JSONResponse($actions);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }


    /**
     * Process retention actions manually (admin trigger)
     * @NoAdminRequired
     * @IsAdmin
     */
    public function processRetentionActions(): JSONResponse {
        try {
            $dryRun = $this->convertToBoolean($this->request->getParam('dry_run', false));
            $result = $this->retentionService->processRetentionActions($dryRun);
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get retention statistics - ENHANCED with groupfolder info
     * @NoAdminRequired
     * @IsAdmin
     */
    public function getRetentionStats(): JSONResponse {
        try {
            $stats = $this->retentionService->getRetentionStatistics();
            return new JSONResponse($stats);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    // ========================================
    // 🔧 UTILITY FUNCTIONS
    // ========================================

    /**
     * Validate retention settings before saving
     * @NoAdminRequired
     */
    public function validateRetentionSettings(): JSONResponse {
        try {
            $fileId = (int)$this->request->getParam('file_id');
            $retentionPeriod = (int)$this->request->getParam('retention_period');
            $retentionUnit = $this->request->getParam('retention_unit');
            
            $validation = $this->retentionService->validateRetentionSettings($fileId, $retentionPeriod, $retentionUnit);
            return new JSONResponse($validation);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Check retention for multiple paths in batch (prevent inheritance conflicts)
     * @NoAdminRequired
     */
    public function checkRetentionBatch(): JSONResponse {
        try {
            $paths = $this->request->getParam('paths', []);
            $groupfolderId = (int)$this->request->getParam('groupfolder_id');
            
            if (empty($paths) || !$groupfolderId) {
                return new JSONResponse(['error' => 'Missing paths or groupfolder_id'], 400);
            }
            
            $result = $this->retentionService->checkRetentionBatch($paths, $groupfolderId);
            return new JSONResponse($result);
            
        } catch (\Exception $e) {
            error_log('MetaVox Retention: Error in batch check: ' . $e->getMessage());
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Preview retention calculation
     * @NoAdminRequired
     */
    public function previewRetentionDate(): JSONResponse {
        try {
            $retentionPeriod = (int)$this->request->getParam('retention_period');
            $retentionUnit = $this->request->getParam('retention_unit', 'years');
            $startDate = $this->request->getParam('start_date', date('Y-m-d'));
            
            $preview = $this->retentionService->previewRetentionDate($retentionPeriod, $retentionUnit, $startDate);
            return new JSONResponse($preview);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    // ========================================
    // 🆕 NEW API ENDPOINTS FOR MULTIPLE POLICIES
    // ========================================

    /**
     * 🆕 NEW: Get policy priorities for a groupfolder (admin)
     * @NoAdminRequired
     * @IsAdmin
     */
    public function getPolicyPriorities(int $groupfolderId): JSONResponse {
        try {
            $policies = $this->retentionService->getPoliciesForGroupfolder($groupfolderId);
            
            $priorities = array_map(function($policy) {
                return [
                    'id' => $policy['id'],
                    'name' => $policy['name'],
                    'priority' => $policy['priority'],
                    'applies_to_path' => $policy['applies_to_path'],
                    'file_type_filter' => $policy['file_type_filter'],
                    'is_active' => $policy['is_active']
                ];
            }, $policies);
            
            return new JSONResponse($priorities);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * 🆕 NEW: Update policy priorities (admin bulk update)
     * @NoAdminRequired
     * @IsAdmin
     */
    public function updatePolicyPriorities(): JSONResponse {
        try {
            $priorities = $this->request->getParam('priorities', []);
            
            if (empty($priorities) || !is_array($priorities)) {
                return new JSONResponse(['error' => 'Invalid priorities data'], 400);
            }
            
            $updated = 0;
            foreach ($priorities as $priorityData) {
                if (!isset($priorityData['id']) || !isset($priorityData['priority'])) {
                    continue;
                }
                
                $policyId = (int)$priorityData['id'];
                $priority = (int)$priorityData['priority'];
                
                // Update only the priority field
                $success = $this->retentionService->updatePolicy($policyId, ['priority' => $priority]);
                if ($success) {
                    $updated++;
                }
            }
            
            return new JSONResponse([
                'success' => true,
                'updated_count' => $updated,
                'message' => "Updated priorities for {$updated} policies"
            ]);
            
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * 🆕 NEW: Test policy matching for a file (admin debugging)
     * @NoAdminRequired
     * @IsAdmin
     */
    public function testPolicyMatching(): JSONResponse {
        try {
            $fileId = (int)$this->request->getParam('file_id');
            $groupfolderId = (int)$this->request->getParam('groupfolder_id');
            
            if (!$fileId) {
                return new JSONResponse(['error' => 'File ID is required'], 400);
            }
            
            // Get all policies for the groupfolder
            $allPolicies = $this->retentionService->getPoliciesForGroupfolder($groupfolderId);
            
            // Find the best matching policy
            $selectedPolicy = null;
            try {
                // This will use the new findPolicyForFile method
                $selectedPolicy = $this->retentionService->findPolicyForFile($fileId);
            } catch (\Exception $e) {
                // If error, still show debug info
            }
            
            return new JSONResponse([
                'file_id' => $fileId,
                'groupfolder_id' => $groupfolderId,
                'available_policies' => $allPolicies,
                'selected_policy' => $selectedPolicy,
                'matching_logic' => 'Priority-based with path and file type filters'
            ]);
            
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    // ========================================
    // 🔧 HELPER FUNCTION
    // ========================================

    /**
     * Convert various types to boolean (handles "true"/"false" strings, checkboxes, etc.)
     */
    private function convertToBoolean($value): bool {
        // Already boolean
        if (is_bool($value)) {
            return $value;
        }
        
        // Handle null/empty
        if ($value === null || $value === '') {
            return false;
        }
        
        // String conversion
        if (is_string($value)) {
            $lower = strtolower(trim($value));
            // True values
            if (in_array($lower, ['true', '1', 'yes', 'on', 'enabled', 'active'], true)) {
                return true;
            }
            // False values
            if (in_array($lower, ['false', '0', 'no', 'off', 'disabled', 'inactive'], true)) {
                return false;
            }
        }
        
        // Numeric conversion
        if (is_numeric($value)) {
            return (float)$value > 0;
        }
        
        // Default conversion
        return (bool)$value;
    }
}