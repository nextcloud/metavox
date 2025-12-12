<?php

return [
    'routes' => [
        // ========================================
        // 🏠 PAGE ROUTES
        // ========================================
        
        // Admin page route
        ['name' => 'admin#index', 'url' => '/admin', 'verb' => 'GET'],
        
        // User page route (NEW)
        ['name' => 'user#index', 'url' => '/user', 'verb' => 'GET'],
        
        // ========================================
        // 📋 FIELD MANAGEMENT ROUTES (Web Interface)
        // ========================================

        // Groupfolder field management routes
        ['name' => 'field#getGroupfolderFields', 'url' => '/api/groupfolder-fields', 'verb' => 'GET'],
        ['name' => 'field#createGroupfolderField', 'url' => '/api/groupfolder-fields', 'verb' => 'POST'],
        ['name' => 'field#updateGroupfolderField', 'url' => '/api/groupfolder-fields/{id}', 'verb' => 'PUT'],
        ['name' => 'field#deleteGroupfolderField', 'url' => '/api/groupfolder-fields/{id}', 'verb' => 'DELETE'],
        
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

        // User routes (for personal settings)
        ['name' => 'user_field#getAccessibleGroupfolders', 'url' => '/api/user/groupfolders', 'verb' => 'GET'],
        ['name' => 'user_field#getGroupfolderFields', 'url' => '/api/user/groupfolders/{groupfolderId}/fields', 'verb' => 'GET'],
        ['name' => 'user_field#getAllGroupfolderFields', 'url' => '/api/user/groupfolder-fields', 'verb' => 'GET'],
        ['name' => 'user_field#getGroupfolderMetadata', 'url' => '/api/user/groupfolders/{groupfolderId}/metadata', 'verb' => 'GET'],
        ['name' => 'user_field#saveGroupfolderMetadata', 'url' => '/api/user/groupfolders/{groupfolderId}/metadata', 'verb' => 'POST'],
        ['name' => 'user_field#setGroupfolderFields', 'url' => '/api/user/groupfolders/{groupfolderId}/fields', 'verb' => 'POST'],

        // ========================================
        // 🔐 PERMISSION MANAGEMENT ROUTES (NEW)
        // ========================================
        
        // Get groups (for permission assignment)
        ['name' => 'permission#getGroups', 'url' => '/api/permissions/groups', 'verb' => 'GET'],
        
        // Get permissions
        ['name' => 'permission#getAllPermissions', 'url' => '/api/permissions', 'verb' => 'GET'],
        ['name' => 'permission#getMyPermissions', 'url' => '/api/permissions/me', 'verb' => 'GET'],
        ['name' => 'permission#checkPermission', 'url' => '/api/permissions/check', 'verb' => 'GET'],
        
        // Grant permissions
        ['name' => 'permission#grantUserPermission', 'url' => '/api/permissions/user', 'verb' => 'POST'],
        ['name' => 'permission#grantGroupPermission', 'url' => '/api/permissions/group', 'verb' => 'POST'],
        
        // Revoke permissions
        ['name' => 'permission#revokeUserPermission', 'url' => '/api/permissions/user/{permissionId}', 'verb' => 'DELETE'],
        ['name' => 'permission#revokeGroupPermission', 'url' => '/api/permissions/group/{permissionId}', 'verb' => 'DELETE'],
    ],

    // ========================================
    // 🔌 OCS API ROUTES (CSRF-Free for External APIs)
    // ========================================
    'ocs' => [
        
        // File metadata routes
        ['name' => 'apiField#getFileMetadata', 'url' => '/api/v1/files/{fileId}/metadata', 'verb' => 'GET'],
        ['name' => 'apiField#saveFileMetadata', 'url' => '/api/v1/files/{fileId}/metadata', 'verb' => 'POST'],
        ['name' => 'api_field#getBulkFileMetadata', 'url' => '/api/v1/files/metadata/bulk', 'verb' => 'GET'],
        
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

        // ========================================
        // 📦 BATCH OPERATIONS ROUTES
        // ========================================

        // Batch file metadata operations (groupfolder file fields)
        ['name' => 'apiField#batchUpdateFileMetadata', 'url' => '/api/v1/files/metadata/batch-update', 'verb' => 'POST'],
        ['name' => 'apiField#batchDeleteFileMetadata', 'url' => '/api/v1/files/metadata/batch-delete', 'verb' => 'POST'],
        ['name' => 'apiField#batchCopyFileMetadata', 'url' => '/api/v1/files/metadata/batch-copy', 'verb' => 'POST'],

        // Metadata statistics
        ['name' => 'apiField#getMetadataStatistics', 'url' => '/api/v1/metadata/statistics', 'verb' => 'GET'],

        // ========================================
        // 🔬 PERFORMANCE TEST ROUTES
        // ========================================

        // Ping test
        ['name' => 'performanceTest#ping', 'url' => '/api/v1/performance/ping', 'verb' => 'GET'],

        // List available performance tests
        ['name' => 'performanceTest#listTests', 'url' => '/api/v1/performance/tests', 'verb' => 'GET'],

        // Start a performance test
        ['name' => 'performanceTest#startTest', 'url' => '/api/v1/performance/tests/{testId}/start', 'verb' => 'POST'],

        // Get test status
        ['name' => 'performanceTest#getTestStatus', 'url' => '/api/v1/performance/runs/{testRunId}/status', 'verb' => 'GET'],

        // Get test output/logs
        ['name' => 'performanceTest#getTestOutput', 'url' => '/api/v1/performance/runs/{testRunId}/output', 'verb' => 'GET'],

        // Get test results
        ['name' => 'performanceTest#getTestResults', 'url' => '/api/v1/performance/runs/{testRunId}/results', 'verb' => 'GET'],

        // Stop a running test
        ['name' => 'performanceTest#stopTest', 'url' => '/api/v1/performance/runs/{testRunId}/stop', 'verb' => 'POST'],

        // Get available datasets
        ['name' => 'performanceTest#getDatasets', 'url' => '/api/v1/performance/datasets', 'verb' => 'GET'],
        ['name' => 'performanceTest#preflightDatasets', 'url' => '/api/v1/performance/datasets', 'verb' => 'OPTIONS'],
    ]
];