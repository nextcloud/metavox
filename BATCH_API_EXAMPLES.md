# MetaVox Batch API Examples

## Overview
The MetaVox API includes batch operations for efficient bulk metadata management of **groupfolder file fields** through the `ApiFieldService`.

## Architecture
- **Controller**: `ApiFieldController` - Handles API requests and validation
- **Service**: `ApiFieldService` - Business logic for batch operations
- **Base Service**: `FieldService` - Core field and metadata operations

## Important Notes
- All batch operations work with **groupfolder file fields** (fields stored in `metavox_gf_fields` and `metavox_file_gf_meta`)
- Field names are **case-insensitive** (e.g., `file_gf_testfield` matches `file_gf_Testfield`)
- All operations require both `file_id` and `groupfolder_id`

## Batch Operations

### 1. Batch Update File Metadata

Update metadata for multiple files in one request.

**Endpoint**: `POST /ocs/v2.php/apps/metavox/api/v1/files/metadata/batch-update`

**Request Body**:
```json
{
  "updates": [
    {
      "file_id": 290,
      "groupfolder_id": 1,
      "metadata": {
        "file_gf_testfield": "test value",
        "file_gf_status": "In Progress",
        "file_gf_priority": "High"
      }
    },
    {
      "file_id": 628,
      "groupfolder_id": 1,
      "metadata": {
        "file_gf_testfield": "another value",
        "file_gf_status": "Completed",
        "file_gf_priority": "Medium"
      }
    }
  ]
}
```

**Response**:
```json
{
  "ocs": {
    "meta": {
      "status": "ok",
      "statuscode": 200
    },
    "data": {
      "success": true,
      "total": 2,
      "successful": 2,
      "failed": 0,
      "results": [
        {
          "file_id": 123,
          "success": true,
          "fields_updated": 3
        },
        {
          "file_id": 456,
          "success": true,
          "fields_updated": 3
        }
      ]
    }
  }
}
```

**cURL Example**:
```bash
curl -X POST "https://your-nextcloud.com/ocs/v2.php/apps/metavox/api/v1/files/metadata/batch-update" \
  -H "OCS-APIRequest: true" \
  -H "Content-Type: application/json" \
  -u "username:password" \
  -d '{
    "updates": [
      {
        "file_id": 290,
        "groupfolder_id": 1,
        "metadata": {
          "file_gf_testfield": "test value",
          "file_gf_status": "In Progress"
        }
      }
    ]
  }'
```

---

### 2. Batch Delete File Metadata

Delete metadata from multiple files.

**Endpoint**: `POST /ocs/v2.php/apps/metavox/api/v1/files/metadata/batch-delete`

**Request Body (Delete All Metadata)**:
```json
{
  "deletes": [
    {
      "file_id": 290,
      "groupfolder_id": 1,
      "field_names": null
    },
    {
      "file_id": 628,
      "groupfolder_id": 1,
      "field_names": null
    }
  ]
}
```

**Request Body (Delete Specific Fields)**:
```json
{
  "deletes": [
    {
      "file_id": 290,
      "groupfolder_id": 1,
      "field_names": ["file_gf_testfield", "file_gf_status"]
    },
    {
      "file_id": 628,
      "groupfolder_id": 1,
      "field_names": ["file_gf_testfield"]
    }
  ]
}
```

**Response**:
```json
{
  "ocs": {
    "meta": {
      "status": "ok",
      "statuscode": 200
    },
    "data": {
      "success": true,
      "total": 2,
      "successful": 2,
      "failed": 0,
      "results": [
        {
          "file_id": 290,
          "groupfolder_id": 1,
          "success": true,
          "fields_deleted": 2
        },
        {
          "file_id": 628,
          "groupfolder_id": 1,
          "success": true,
          "fields_deleted": 1
        }
      ]
    }
  }
}
```

---

### 3. Batch Copy File Metadata

Copy metadata from one file to multiple files within the same groupfolder.

**Endpoint**: `POST /ocs/v2.php/apps/metavox/api/v1/files/metadata/batch-copy`

**Request Body (Copy All Fields)**:
```json
{
  "source_file_id": 290,
  "source_groupfolder_id": 1,
  "target_file_ids": [628, 789, 101],
  "groupfolder_id": 1
}
```

**Request Body (Copy Specific Fields)**:
```json
{
  "source_file_id": 290,
  "source_groupfolder_id": 1,
  "target_file_ids": [628, 789],
  "groupfolder_id": 1,
  "field_names": ["file_gf_testfield", "file_gf_status", "file_gf_priority"]
}
```

**Response**:
```json
{
  "ocs": {
    "meta": {
      "status": "ok",
      "statuscode": 200
    },
    "data": {
      "success": true,
      "source_file_id": 290,
      "fields_copied": 3,
      "target_results": [
        {
          "file_id": 628,
          "success": true,
          "fields_updated": 3
        },
        {
          "file_id": 789,
          "success": true,
          "fields_updated": 3
        },
        {
          "file_id": 101,
          "success": true,
          "fields_updated": 3
        }
      ]
    }
  }
}
```

**Use Case Example**:
```bash
# Copy metadata from a template file to newly created files
curl -X POST "https://your-nextcloud.com/ocs/v2.php/apps/metavox/api/v1/files/metadata/batch-copy" \
  -H "OCS-APIRequest: true" \
  -H "Content-Type: application/json" \
  -u "username:password" \
  -d '{
    "source_file_id": 290,
    "source_groupfolder_id": 1,
    "target_file_ids": [628, 789],
    "groupfolder_id": 1,
    "field_names": ["file_gf_testfield", "file_gf_status"]
  }'
```

---

### 4. Get Metadata Statistics

Get statistics about metadata usage.

**Endpoint**: `GET /ocs/v2.php/apps/metavox/api/v1/metadata/statistics`

**Response**:
```json
{
  "ocs": {
    "meta": {
      "status": "ok",
      "statuscode": 200
    },
    "data": {
      "total_fields": 25,
      "total_values": 1547,
      "files_with_metadata": 423,
      "fields_by_type": [
        {
          "field_type": "text",
          "count": "15"
        },
        {
          "field_type": "select",
          "count": "5"
        },
        {
          "field_type": "date",
          "count": "3"
        },
        {
          "field_type": "multi_select",
          "count": "2"
        }
      ]
    }
  }
}
```

---

## Error Handling

All batch operations return detailed error information for each item:

**Example with Errors**:
```json
{
  "ocs": {
    "meta": {
      "status": "ok",
      "statuscode": 200
    },
    "data": {
      "success": true,
      "total": 3,
      "successful": 2,
      "failed": 1,
      "results": [
        {
          "file_id": 123,
          "success": true,
          "fields_updated": 3
        },
        {
          "file_id": 456,
          "success": false,
          "error": "File not found"
        },
        {
          "file_id": 789,
          "success": true,
          "fields_updated": 3
        }
      ]
    }
  }
}
```

---

## Best Practices

1. **Batch Size**: Limit batches to 100 items per request for optimal performance
2. **Transaction Safety**: All batch operations use database transactions
3. **Error Handling**: Check individual results for partial failures
4. **Field Names**: Use the exact field names as defined in MetaVox
5. **Authentication**: All endpoints require proper Nextcloud authentication

---

## JavaScript/TypeScript Example

```javascript
// Batch update example
async function batchUpdateMetadata(files, groupfolderId) {
  const updates = files.map(file => ({
    file_id: file.id,
    groupfolder_id: groupfolderId,
    metadata: {
      file_gf_testfield: file.testValue,
      file_gf_status: file.status,
      file_gf_priority: file.priority
    }
  }));

  const response = await fetch(
    '/ocs/v2.php/apps/metavox/api/v1/files/metadata/batch-update',
    {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'OCS-APIRequest': 'true'
      },
      body: JSON.stringify({ updates })
    }
  );

  const result = await response.json();

  if (result.ocs.data.failed > 0) {
    console.error('Some updates failed:', result.ocs.data.results);
  }

  return result.ocs.data;
}

// Usage
const filesToUpdate = [
  { id: 290, testValue: 'test', status: 'Active', priority: 'High' },
  { id: 628, testValue: 'another', status: 'Completed', priority: 'Low' }
];

batchUpdateMetadata(filesToUpdate, 1)
  .then(result => console.log(`Updated ${result.successful} files`))
  .catch(error => console.error('Batch update failed:', error));
```

---

## PHP Example

```php
<?php
use OCA\MetaVox\Service\ApiFieldService;

// In your controller or service
$apiFieldService = \OC::$server->get(ApiFieldService::class);

// Batch update
$updates = [
    [
        'file_id' => 290,
        'groupfolder_id' => 1,
        'metadata' => [
            'file_gf_testfield' => 'test value',
            'file_gf_status' => 'In Progress'
        ]
    ],
    [
        'file_id' => 628,
        'groupfolder_id' => 1,
        'metadata' => [
            'file_gf_testfield' => 'another value',
            'file_gf_status' => 'Completed'
        ]
    ]
];

$results = $apiFieldService->batchUpdateFileMetadata($updates);

foreach ($results as $result) {
    if ($result['success']) {
        echo "File {$result['file_id']}: {$result['fields_updated']} fields updated\n";
    } else {
        echo "File {$result['file_id']}: Error - {$result['error']}\n";
    }
}
```

---

## Summary

The new `ApiFieldService` provides a clean separation of concerns:

- **ApiFieldController**: Handles HTTP requests, validation, and responses
- **ApiFieldService**: Contains business logic for batch operations
- **FieldService**: Low-level field and metadata operations

Benefits:
- ✅ Efficient bulk operations
- ✅ Transaction safety
- ✅ Detailed error reporting per item
- ✅ Clean separation of concerns
- ✅ Easy to test and maintain
