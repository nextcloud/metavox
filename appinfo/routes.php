<?php

return [
    'routes' => [
        // Admin page route
        ['name' => 'admin#index', 'url' => '/admin', 'verb' => 'GET'],
        
        // Global field management routes
        ['name' => 'field#getFields', 'url' => '/api/fields', 'verb' => 'GET'],
        ['name' => 'field#createField', 'url' => '/api/fields', 'verb' => 'POST'],
        ['name' => 'field#getField', 'url' => '/api/fields/{id}', 'verb' => 'GET'], // 🆕 GET single field
        ['name' => 'field#updateField', 'url' => '/api/fields/{id}', 'verb' => 'PUT'], // ✅ Already exists
        ['name' => 'field#deleteField', 'url' => '/api/fields/{id}', 'verb' => 'DELETE'],
        
        // Groupfolder field management routes
        ['name' => 'field#getGroupfolderFields', 'url' => '/api/groupfolder-fields', 'verb' => 'GET'],
        ['name' => 'field#createGroupfolderField', 'url' => '/api/groupfolder-fields', 'verb' => 'POST'],
        ['name' => 'field#updateGroupfolderField', 'url' => '/api/groupfolder-fields/{id}', 'verb' => 'PUT'], // 🆕 UPDATE route
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
    ]
];