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

        // Bulk file metadata routes
        ['name' => 'field#saveBulkFileMetadata', 'url' => '/api/files/bulk-metadata', 'verb' => 'POST'],
        ['name' => 'field#clearFileMetadata', 'url' => '/api/files/clear-metadata', 'verb' => 'POST'],
        ['name' => 'field#exportFileMetadata', 'url' => '/api/files/export-metadata', 'verb' => 'POST'],
        
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
        
        // Get users (for user/group picker field)
        ['name' => 'field#getUsers', 'url' => '/api/users', 'verb' => 'GET'],

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

        // ========================================
        // ⚙️ ADMIN SETTINGS ROUTES
        // ========================================
        ['name' => 'settings#get', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#save', 'url' => '/api/settings', 'verb' => 'POST'],

        // ========================================
        // 📊 TELEMETRY ROUTES
        // ========================================
        ['name' => 'telemetry#getStatus', 'url' => '/api/telemetry/status', 'verb' => 'GET'],
        ['name' => 'telemetry#getStats', 'url' => '/api/telemetry/stats', 'verb' => 'GET'],
        ['name' => 'telemetry#sendTelemetry', 'url' => '/api/telemetry/send', 'verb' => 'POST'],
        ['name' => 'telemetry#saveSettings', 'url' => '/api/telemetry/settings', 'verb' => 'POST'],

        // ========================================
        // 👁 VIEWS ROUTES
        // ========================================
        ['name' => 'view#listViews',    'url' => '/api/groupfolders/{gfId}/views',           'verb' => 'GET'],
        ['name' => 'view#createView',   'url' => '/api/groupfolders/{gfId}/views',           'verb' => 'POST'],
        ['name' => 'view#reorderViews', 'url' => '/api/groupfolders/{gfId}/views/reorder',   'verb' => 'PUT'],
        ['name' => 'view#updateView',   'url' => '/api/groupfolders/{gfId}/views/{viewId}',  'verb' => 'PUT'],
        ['name' => 'view#deleteView',   'url' => '/api/groupfolders/{gfId}/views/{viewId}',  'verb' => 'DELETE'],

        // Files plugin init (single call for all startup data)
        ['name' => 'view#init', 'url' => '/api/init', 'verb' => 'GET'],

        // Filter values scoped to current directory
        ['name' => 'filter#getAllFilterValues', 'url' => '/api/groupfolders/{groupfolderId}/filter-values', 'verb' => 'POST'],

        // ========================================
        // 🤖 AI AUTOFILL ROUTES
        // ========================================
        ['name' => 'aiAutofill#status', 'url' => '/api/ai/status', 'verb' => 'GET'],
        ['name' => 'aiAutofill#generate', 'url' => '/api/ai/generate', 'verb' => 'POST'],

        // ========================================
        // 💾 BACKUP & RESTORE ROUTES
        // ========================================
        ['name' => 'backup#list', 'url' => '/api/backup/list', 'verb' => 'GET'],
        ['name' => 'backup#trigger', 'url' => '/api/backup/trigger', 'verb' => 'POST'],
        ['name' => 'backup#restore', 'url' => '/api/backup/restore', 'verb' => 'POST'],
        ['name' => 'backup#download', 'url' => '/api/backup/download', 'verb' => 'GET'],
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
        // 📊 COLUMN & DIRECTORY METADATA ROUTES
        // ========================================

        // Available file-level fields for a groupfolder (for view editor)
        ['name' => 'apiField#getGroupfolderFileFields', 'url' => '/api/v1/groupfolders/{groupfolderId}/file-fields', 'verb' => 'GET'],

        // Bulk directory metadata (optimized for file list columns)
        ['name' => 'apiFilter#getDirectoryMetadata', 'url' => '/api/v1/groupfolders/{groupfolderId}/directory-metadata', 'verb' => 'GET'],

        // Filter values (distinct values for filter dropdowns — batch)
        ['name' => 'apiFilter#getAllFilterValues', 'url' => '/api/v1/groupfolders/{groupfolderId}/all-filter-values', 'verb' => 'GET'],

        // Server-side sorted & filtered file IDs
        ['name' => 'apiFilter#getSortedFileIds', 'url' => '/api/v1/groupfolders/{groupfolderId}/sorted-file-ids', 'verb' => 'GET'],

        // Field update and delete (OCS)
        ['name' => 'apiField#updateGroupfolderField', 'url' => '/api/v1/groupfolder-fields/{id}', 'verb' => 'PUT'],
        ['name' => 'apiField#deleteGroupfolderField', 'url' => '/api/v1/groupfolder-fields/{id}', 'verb' => 'DELETE'],

        // ========================================
        // 👁 VIEWS ROUTES (OCS — CSRF-free)
        // ========================================
        ['name' => 'apiView#listViews',    'url' => '/api/v1/groupfolders/{groupfolderId}/views',           'verb' => 'GET'],
        ['name' => 'apiView#createView',   'url' => '/api/v1/groupfolders/{groupfolderId}/views',           'verb' => 'POST'],
        ['name' => 'apiView#reorderViews', 'url' => '/api/v1/groupfolders/{groupfolderId}/views/reorder',   'verb' => 'PUT'],
        ['name' => 'apiView#updateView',   'url' => '/api/v1/groupfolders/{groupfolderId}/views/{viewId}',  'verb' => 'PUT'],
        ['name' => 'apiView#deleteView',   'url' => '/api/v1/groupfolders/{groupfolderId}/views/{viewId}',  'verb' => 'DELETE'],
    ]
];