<?php

return [
    'routes' => [
        // Admin page route
        ['name' => 'admin#index', 'url' => '/admin', 'verb' => 'GET'],
        
        // ========================================
        // 📋 FIELD MANAGEMENT ROUTES (Web Interface)
        // ========================================
        
        // Global field management routes
        ['name' => 'field#getFields', 'url' => '/api/fields', 'verb' => 'GET'],
        ['name' => 'field#createField', 'url' => '/api/fields', 'verb' => 'POST'],
        ['name' => 'field#getField', 'url' => '/api/fields/{id}', 'verb' => 'GET'],
        ['name' => 'field#updateField', 'url' => '/api/fields/{id}', 'verb' => 'PUT'],
        ['name' => 'field#deleteField', 'url' => '/api/fields/{id}', 'verb' => 'DELETE'],
        
        // Groupfolder field management routes
        ['name' => 'field#getGroupfolderFields', 'url' => '/api/groupfolder-fields', 'verb' => 'GET'],
        ['name' => 'field#createGroupfolderField', 'url' => '/api/groupfolder-fields', 'verb' => 'POST'],
        ['name' => 'field#updateGroupfolderField', 'url' => '/api/groupfolder-fields/{id}', 'verb' => 'PUT'],
        ['name' => 'field#deleteGroupfolderField', 'url' => '/api/groupfolder-fields/{id}', 'verb' => 'DELETE'],
        
        // File metadata routes
        ['name' => 'field#getFileMetadata', 'url' => '/api/files/{fileId}/metadata', 'verb' => 'GET'],
        ['name' => 'field#saveFileMetadata', 'url' => '/api/files/{fileId}/metadata', 'verb' => 'POST'],
        
        // Groupfolder routes
        ['name' => 'field#getGroupfolders', 'url' => '/api/groupfolders', 'verb' => 'GET'],
        ['name' => 'field#getGroupfolderMetadata', 'url' => '/api/groupfolders/{groupfolderId}/metadata', 'verb' => 'GET'],
        ['name' => 'field#saveGroupfolderMetadata', 'url' => '/api/groupfolders/{groupfolderId}/metadata', 'verb' => 'POST'],
        
        // Groupfolder file metadata routes (individual files within groupfolders)
        ['name' => 'field#getGroupfolderFileMetadata', 'url' => '/api/groupfolders/{groupfolderId}/files/{fileId}/metadata', 'verb' => 'GET'],
        ['name' => 'field#saveGroupfolderFileMetadata', 'url' => '/api/groupfolders/{groupfolderId}/files/{fileId}/metadata', 'verb' => 'POST'],
        
        // Groupfolder field assignment routes
        ['name' => 'field#getGroupfolderAssignedFields', 'url' => '/api/groupfolders/{groupfolderId}/fields', 'verb' => 'GET'],
        ['name' => 'field#setGroupfolderFields', 'url' => '/api/groupfolders/{groupfolderId}/fields', 'verb' => 'POST'],
        
        // Field overrides voor groupfolder-specifieke configuraties
        ['name' => 'field#saveFieldOverride', 'url' => '/api/groupfolders/{groupfolderId}/field-overrides', 'verb' => 'POST'],
        ['name' => 'field#getFieldOverrides', 'url' => '/api/groupfolders/{groupfolderId}/field-overrides', 'verb' => 'GET'],

        // ========================================
        // 📄 RETENTION WORKFLOW ROUTES
        // ========================================
        
        // Retention Policies (Admin Management)
        ['name' => 'Retention#getPolicies', 'url' => '/api/retention/policies', 'verb' => 'GET'],
        ['name' => 'Retention#getPolicy', 'url' => '/api/retention/policies/{id}', 'verb' => 'GET'],
        ['name' => 'Retention#createPolicy', 'url' => '/api/retention/policies', 'verb' => 'POST'],
        ['name' => 'Retention#updatePolicy', 'url' => '/api/retention/policies/{id}', 'verb' => 'PUT'],
        ['name' => 'Retention#deletePolicy', 'url' => '/api/retention/policies/{id}', 'verb' => 'DELETE'],
        ['name' => 'Retention#togglePolicy', 'url' => '/api/retention/policies/{id}/toggle', 'verb' => 'POST'],
        ['name'=> 'Retention#getGroupfoldersWithoutPolicy', 'url' => '/api/retention/groupfolders-without-policy', 'verb' => 'GET'],
        ['name' => 'retention#getGroupfoldersForPolicy', 'url' => '/api/retention/policies/{policyId}/groupfolders', 'verb' => 'GET'],
        ['name' => 'retention#assignPoliciesToFolder', 'url' => '/api/retention/policies/assign-to-folder', 'verb' => 'POST'],
        
        // File Retention (User Management)
        ['name' => 'Retention#getFileRetention', 'url' => '/api/retention/files/{fileId}', 'verb' => 'GET'],
        ['name' => 'Retention#setFileRetention', 'url' => '/api/retention/files/{fileId}', 'verb' => 'POST'],
        ['name' => 'Retention#removeFileRetention', 'url' => '/api/retention/files/{fileId}', 'verb' => 'DELETE'],
        ['name' => 'Retention#checkRetentionBatch', 'url' => '/api/retention/check-batch', 'verb' => 'POST'],
        ['name' => 'retention#getGroupfolderPolicies', 'url' => '/api/retention/groupfolders/{groupfolderId}/policies', 'verb' => 'GET'],
        
        // Groupfolder Policy Access (User Needs)
        ['name' => 'Retention#getGroupfolderPolicy', 'url' => '/api/retention/groupfolders/{groupfolderId}/policy', 'verb' => 'GET'],
        
        // User Retention Overview
        ['name' => 'Retention#getUserRetentionOverview', 'url' => '/api/retention/user/overview', 'verb' => 'GET'],
        
        // Processing & Monitoring (Admin)
        ['name' => 'Retention#getProcessingLogs', 'url' => '/api/retention/logs', 'verb' => 'GET'],
        ['name' => 'Retention#getUpcomingActions', 'url' => '/api/retention/upcoming', 'verb' => 'GET'],
        ['name' => 'Retention#processRetentionActions', 'url' => '/api/retention/process', 'verb' => 'POST'],
        ['name' => 'Retention#getRetentionStats', 'url' => '/api/retention/stats', 'verb' => 'GET'],
        
        // Utility Functions
        ['name' => 'Retention#validateRetentionSettings', 'url' => '/api/retention/validate', 'verb' => 'POST'],
        ['name' => 'Retention#previewRetentionDate', 'url' => '/api/retention/preview', 'verb' => 'POST'],
    ],

    // ========================================
    // 🔌 OCS API ROUTES (CSRF-Free for External APIs)
    // ========================================
    'ocs' => [
        
        // File metadata routes
        ['name' => 'apiField#getFileMetadata', 'url' => '/api/v1/files/{fileId}/metadata', 'verb' => 'GET'],
        ['name' => 'apiField#saveFileMetadata', 'url' => '/api/v1/files/{fileId}/metadata', 'verb' => 'POST'],
        
        // Groupfolder routes
        ['name' => 'apiField#getGroupfolders', 'url' => '/api/v1/groupfolders', 'verb' => 'GET'],
        ['name' => 'apiField#getGroupfolderMetadata', 'url' => '/api/v1/groupfolders/{groupfolderId}/metadata', 'verb' => 'GET'],
        ['name' => 'apiField#saveGroupfolderMetadata', 'url' => '/api/v1/groupfolders/{groupfolderId}/metadata', 'verb' => 'POST'],
        
        // Groupfolder field management routes
        ['name' => 'apiField#getGroupfolderFields', 'url' => '/api/v1/groupfolder-fields', 'verb' => 'GET'],
        ['name' => 'apiField#createGroupfolderField', 'url' => '/api/v1/groupfolder-fields', 'verb' => 'POST'],
        
        // Groupfolder file metadata routes
        ['name' => 'apiField#getGroupfolderFileMetadata', 'url' => '/api/v1/groupfolders/{groupfolderId}/files/{fileId}/metadata', 'verb' => 'GET'],
        ['name' => 'apiField#saveGroupfolderFileMetadata', 'url' => '/api/v1/groupfolders/{groupfolderId}/files/{fileId}/metadata', 'verb' => 'POST'],
        
        // Groupfolder field assignment routes
        ['name' => 'apiField#getGroupfolderAssignedFields', 'url' => '/api/v1/groupfolders/{groupfolderId}/fields', 'verb' => 'GET'],
        ['name' => 'apiField#setGroupfolderFields', 'url' => '/api/v1/groupfolders/{groupfolderId}/fields', 'verb' => 'POST'],
        
        // Field overrides routes
        ['name' => 'apiField#saveFieldOverride', 'url' => '/api/v1/groupfolders/{groupfolderId}/field-overrides', 'verb' => 'POST'],
        ['name' => 'apiField#getFieldOverrides', 'url' => '/api/v1/groupfolders/{groupfolderId}/field-overrides', 'verb' => 'GET'],
    ]
];
