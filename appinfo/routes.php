<?php

$routes = [
    'routes' => [
        // ========================================
        // 📋 FIELD MANAGEMENT ROUTES (Web Interface)
        // ========================================

        // Groupfolder field management routes
        ['name' => 'field#getGroupfolderFields', 'url' => '/api/groupfolder-fields', 'verb' => 'GET'],
        ['name' => 'field#createGroupfolderField', 'url' => '/api/groupfolder-fields', 'verb' => 'POST'],
        ['name' => 'field#updateGroupfolderField', 'url' => '/api/groupfolder-fields/{id}', 'verb' => 'PUT'],
        ['name' => 'field#deleteGroupfolderField', 'url' => '/api/groupfolder-fields/{id}', 'verb' => 'DELETE'],
        ['name' => 'field#bulkDeleteFields', 'url' => '/api/groupfolder-fields/bulk-delete', 'verb' => 'POST'],
        
        // Groupfolder routes
        ['name' => 'field#getGroupfolders', 'url' => '/api/groupfolders', 'verb' => 'GET'],
        ['name' => 'field#getGroupfolderMetadata', 'url' => '/api/groupfolders/{groupfolderId}/metadata', 'verb' => 'GET'],
        ['name' => 'field#saveGroupfolderMetadata', 'url' => '/api/groupfolders/{groupfolderId}/metadata', 'verb' => 'POST'],
        
        // Groupfolder file metadata routes (individual files within groupfolders)
        // A file's metadata; the server finds its team folder.
        ['name' => 'field#getFileMetadata', 'url' => '/api/files/{fileId}/metadata', 'verb' => 'GET'],
        ['name' => 'field#saveFileMetadata', 'url' => '/api/files/{fileId}/metadata', 'verb' => 'POST'],
        ['name' => 'field#getFileBacklinks', 'url' => '/api/groupfolders/{groupfolderId}/files/{fileId}/backlinks', 'verb' => 'GET'],

        // Bulk file metadata routes
        ['name' => 'field#saveBulkFileMetadata', 'url' => '/api/files/bulk-metadata', 'verb' => 'POST'],
        ['name' => 'field#clearFileMetadata', 'url' => '/api/files/clear-metadata', 'verb' => 'POST'],
        ['name' => 'field#exportFileMetadata', 'url' => '/api/files/export-metadata', 'verb' => 'POST'],
        
        // Groupfolder field assignment routes
        ['name' => 'field#getGroupfolderAssignedFields', 'url' => '/api/groupfolders/{groupfolderId}/fields', 'verb' => 'GET'],
        ['name' => 'field#setGroupfolderFields', 'url' => '/api/groupfolders/{groupfolderId}/fields', 'verb' => 'POST'],
        ['name' => 'field#getGroupfolderFileFields', 'url' => '/api/groupfolders/{groupfolderId}/file-fields', 'verb' => 'GET'],

        // Per-folder default value routes
        ['name' => 'defaults#getDefaults', 'url' => '/api/groupfolders/{groupfolderId}/defaults', 'verb' => 'GET'],
        ['name' => 'defaults#setDefault', 'url' => '/api/groupfolders/{groupfolderId}/defaults', 'verb' => 'POST'],
        ['name' => 'defaults#trigger', 'url' => '/api/groupfolders/{groupfolderId}/defaults/trigger', 'verb' => 'POST'],
        ['name' => 'defaults#status', 'url' => '/api/groupfolders/{groupfolderId}/defaults/status', 'verb' => 'GET'],

        // User routes (for personal settings)
        ['name' => 'user_field#getAccessibleGroupfolders', 'url' => '/api/user/groupfolders', 'verb' => 'GET'],
        ['name' => 'user_field#getGroupfolderFields', 'url' => '/api/user/groupfolders/{groupfolderId}/fields', 'verb' => 'GET'],
        ['name' => 'user_field#getFieldValues', 'url' => '/api/user/groupfolders/{groupfolderId}/field-values', 'verb' => 'GET'],
        ['name' => 'user_field#getAllGroupfolderFields', 'url' => '/api/user/groupfolder-fields', 'verb' => 'GET'],
        ['name' => 'user_field#getGroupfolderMetadata', 'url' => '/api/user/groupfolders/{groupfolderId}/metadata', 'verb' => 'GET'],
        ['name' => 'user_field#setGroupfolderFields', 'url' => '/api/user/groupfolders/{groupfolderId}/fields', 'verb' => 'POST'],

        // The user's saved metadata searches, shown as folders in Files
        ['name' => 'metadata_search#index', 'url' => '/api/user/metadata-searches', 'verb' => 'GET'],
        ['name' => 'metadata_search#create', 'url' => '/api/user/metadata-searches', 'verb' => 'POST'],
        ['name' => 'metadata_search#destroy', 'url' => '/api/user/metadata-searches/{id}', 'verb' => 'DELETE'],

        // ========================================
        // 🔐 PERMISSION MANAGEMENT ROUTES (NEW)
        // ========================================
        
        // Accounts, groups and teams for the People picker
        ['name' => 'field#getAccounts', 'url' => '/api/accounts', 'verb' => 'GET'],

        // Get groups (for permission assignment)
        ['name' => 'permission#getGroups', 'url' => '/api/permissions/groups', 'verb' => 'GET'],
        
        // Get permissions
        ['name' => 'permission#getAllPermissions', 'url' => '/api/permissions', 'verb' => 'GET'],
        
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
        // 🔑 LICENSE ROUTES
        // ========================================
        ['name' => 'license#getStats', 'url' => '/api/license/stats', 'verb' => 'GET'],
        ['name' => 'license#saveSettings', 'url' => '/api/settings/license', 'verb' => 'POST'],
        ['name' => 'license#validate', 'url' => '/api/license/validate', 'verb' => 'POST'],
        ['name' => 'license#updateUsage', 'url' => '/api/license/update-usage', 'verb' => 'POST'],

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

        // Grid values and permissions for the files in view
        ['name' => 'filter#getDirectoryMetadata', 'url' => '/api/groupfolders/{groupfolderId}/directory-metadata', 'verb' => 'GET'],

        // Cell locking for concurrent editing
        ['name' => 'lock#lockCell', 'url' => '/api/groupfolders/{groupfolderId}/files/{fileId}/lock', 'verb' => 'POST'],
        ['name' => 'lock#unlockCell', 'url' => '/api/groupfolders/{groupfolderId}/files/{fileId}/unlock', 'verb' => 'POST'],

        // Presence cleanup (called via sendBeacon on tab close)
        ['name' => 'presence#leave', 'url' => '/api/presence/leave', 'verb' => 'POST'],

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
        ['name' => 'backup#status', 'url' => '/api/backup/status', 'verb' => 'GET'],
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

        // Filter values scoped to current directory (POST to support large file_ids arrays)
        ['name' => 'apiFilter#getScopedFilterValues', 'url' => '/api/v1/groupfolders/{groupfolderId}/filter-values', 'verb' => 'POST'],

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

// An id in a URL is a number: Nextcloud would read "12abc" as 12, now it is
// no route at all (404).
foreach ($routes as &$list) {
    foreach ($list as &$route) {
        preg_match_all('/\{(\w+)\}/', $route['url'], $params);
        foreach ($params[1] as $param) {
            if ($param === 'id' || str_ends_with($param, 'Id')) {
                $route['requirements'][$param] = '\d+';
            }
        }
    }
}
unset($list, $route);

return $routes;
