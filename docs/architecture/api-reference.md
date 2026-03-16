# MetaVox API Reference

## Overview
The MetaVox OCS API provides endpoints for metadata management, field configuration, views, column configuration, and batch operations.

All endpoints are available at `/ocs/v2.php/apps/metavox/api/v1/` and require Nextcloud authentication.
Use an **app password** or OAuth token — the OCS API does not require a browser session or CSRF token.

## Architecture

| Controller | Responsibility |
|------------|---------------|
| **`ApiFieldController`** | All OCS endpoints: fields, column config, views, batch operations, filter values, directory metadata |
| **`ViewController`** | Browser-based view management (admin UI, requires session) |
| **`FieldService`** | Field definitions, column config, metadata reads/writes — with distributed cache |
| **`ViewService`** | View CRUD — with distributed cache |

## Important Notes
- All batch operations work with **groupfolder file fields** (stored in `metavox_gf_fields` and `metavox_file_gf_meta`)
- Field names are **case-insensitive** (e.g., `file_gf_testfield` matches `file_gf_Testfield`)
- Batch update/delete operations require both `file_id` and `groupfolder_id` per item
- Read-only endpoints for stable data (column config, views, filter values) return `Cache-Control: private` headers

---

## Fields

### Create a field

**Endpoint**: `POST /ocs/v2.php/apps/metavox/api/v1/groupfolder-fields`
**Requires**: Nextcloud admin

**Request body**:
```json
{
  "field_name": "publicatiestatus",
  "field_label": "Publicatiestatus",
  "field_type": "select",
  "field_description": "Status van de publicatie",
  "field_options": ["Open", "Gesloten", "In behandeling"],
  "is_required": false,
  "sort_order": 1
}
```

### Update a field

**Endpoint**: `PUT /ocs/v2.php/apps/metavox/api/v1/groupfolder-fields/{id}`
**Requires**: Nextcloud admin
Body: same as create.

### Delete a field

**Endpoint**: `DELETE /ocs/v2.php/apps/metavox/api/v1/groupfolder-fields/{id}`
**Requires**: Nextcloud admin

### Assign fields to a groupfolder

**Endpoint**: `POST /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/fields`
**Requires**: Nextcloud admin

**Request body**:
```json
{"field_ids": [4, 5, 6]}
```

---

## Views

### List views for a groupfolder

**Endpoint**: `GET /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/views`
**Response includes `Cache-Control: private, max-age=600`**

**Response**:
```json
{
  "views": [
    {
      "id": 1,
      "gf_id": 3,
      "name": "WOO open",
      "is_default": true,
      "columns": [
        {"field_name": "publicatiestatus", "visible": true, "filterable": true, "column_order": 0},
        {"field_name": "verantwoordelijke", "visible": true, "filterable": false, "column_order": 1}
      ],
      "filters": {"publicatiestatus": ["Open"]},
      "sort_field": "publicatiestatus",
      "sort_order": "ASC"
    }
  ],
  "can_manage": true
}
```

### Create a view

**Endpoint**: `POST /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/views`
**Requires**: admin or manage-fields permission

**Request body**:
```json
{
  "name": "WOO open",
  "is_default": false,
  "columns": [
    {"field_name": "publicatiestatus", "visible": true, "filterable": true, "column_order": 0}
  ],
  "filters": {"publicatiestatus": ["Open"]},
  "sort_field": "publicatiestatus",
  "sort_order": "ASC"
}
```

### Update a view

**Endpoint**: `PUT /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/views/{viewId}`
**Requires**: admin or manage-fields permission
Body: same as create.

### Delete a view

**Endpoint**: `DELETE /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/views/{viewId}`
**Requires**: admin or manage-fields permission

---

## Column Configuration

### Get column config (visible columns only)

**Endpoint**: `GET /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/columns`
**Response includes `Cache-Control: private, max-age=600`**

Returns only fields with `show_as_column = true`, ordered by `column_order`.

**Response**:
```json
[
  {
    "field_id": 4,
    "field_name": "publicatiestatus",
    "field_label": "Publicatiestatus",
    "field_type": "select",
    "field_options": ["Open", "Gesloten", "In behandeling"],
    "show_as_column": true,
    "column_order": 0,
    "filterable": true
  }
]
```

### Set column config

**Endpoint**: `POST /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/columns`
**Requires**: access to the groupfolder

**Request body**:
```json
{"columns": [
  {"field_id": 4, "show_as_column": true, "column_order": 0, "filterable": true},
  {"field_id": 5, "show_as_column": true, "column_order": 1, "filterable": false}
]}
```

### Get filter values for a field

**Endpoint**: `GET /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/filter-values?field_name={name}`
**Response includes `Cache-Control: private, max-age=300`**

Returns all distinct non-empty values stored for this field in the groupfolder. Used to populate filter dropdowns.

**Response**:
```json
["Gesloten", "In behandeling", "Open"]
```

> **Note**: For checkbox fields the frontend always offers "Yes" and "No" as options, regardless of what this endpoint returns.

### Get directory metadata (for file list columns)

**Endpoint**: `GET /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/directory-metadata?file_ids=1,2,3`

Returns metadata for up to **200 file IDs** per request, limited to column-configured fields. Used by the file list to populate metadata columns.

**Response**:
```json
{
  "123": {"publicatiestatus": "Open", "verantwoordelijke": "j.doe"},
  "456": {"publicatiestatus": "Gesloten"}
}
```

---

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
    "meta": {"status": "ok", "statuscode": 200},
    "data": {
      "success": true,
      "total": 2,
      "successful": 2,
      "failed": 0,
      "results": [
        {"file_id": 290, "success": true, "fields_updated": 3},
        {"file_id": 628, "success": true, "fields_updated": 3}
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
  -u "username:app-password" \
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
    {"file_id": 290, "groupfolder_id": 1, "field_names": null},
    {"file_id": 628, "groupfolder_id": 1, "field_names": null}
  ]
}
```

**Request Body (Delete Specific Fields)**:
```json
{
  "deletes": [
    {"file_id": 290, "groupfolder_id": 1, "field_names": ["file_gf_testfield", "file_gf_status"]},
    {"file_id": 628, "groupfolder_id": 1, "field_names": ["file_gf_testfield"]}
  ]
}
```

**Response**:
```json
{
  "ocs": {
    "meta": {"status": "ok", "statuscode": 200},
    "data": {
      "success": true,
      "total": 2,
      "successful": 2,
      "failed": 0,
      "results": [
        {"file_id": 290, "groupfolder_id": 1, "success": true, "fields_deleted": 2},
        {"file_id": 628, "groupfolder_id": 1, "success": true, "fields_deleted": 1}
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
    "meta": {"status": "ok", "statuscode": 200},
    "data": {
      "success": true,
      "source_file_id": 290,
      "fields_copied": 3,
      "target_results": [
        {"file_id": 628, "success": true, "fields_updated": 3},
        {"file_id": 789, "success": true, "fields_updated": 3},
        {"file_id": 101, "success": true, "fields_updated": 3}
      ]
    }
  }
}
```

---

### 4. Get Metadata Statistics

**Endpoint**: `GET /ocs/v2.php/apps/metavox/api/v1/metadata/statistics`

**Response**:
```json
{
  "ocs": {
    "meta": {"status": "ok", "statuscode": 200},
    "data": {
      "total_fields": 25,
      "total_values": 1547,
      "files_with_metadata": 423,
      "fields_by_type": [
        {"field_type": "text", "count": "15"},
        {"field_type": "select", "count": "5"},
        {"field_type": "date", "count": "3"},
        {"field_type": "multi_select", "count": "2"}
      ]
    }
  }
}
```

---

## Migration Workflow

Complete OCS-only workflow for programmatic setup — suitable for migrations from SharePoint or other systems. All steps use app password authentication; no browser session required.

### Step 1 — Create fields (admin)

```bash
curl -X POST ".../ocs/v2.php/apps/metavox/api/v1/groupfolder-fields" \
  -H "OCS-APIRequest: true" -H "Content-Type: application/json" \
  -u "admin:app-password" \
  -d '{"field_name": "publicatiestatus", "field_label": "Publicatiestatus", "field_type": "select", "field_options": ["Open", "Gesloten"]}'
```

Repeat for each field. Note the returned `id` for step 2.

### Step 2 — Assign fields to groupfolder (admin)

```bash
curl -X POST ".../ocs/v2.php/apps/metavox/api/v1/groupfolders/3/fields" \
  -H "OCS-APIRequest: true" -H "Content-Type: application/json" \
  -u "admin:app-password" \
  -d '{"field_ids": [4, 5, 6]}'
```

### Step 3 — Set column config

```bash
curl -X POST ".../ocs/v2.php/apps/metavox/api/v1/groupfolders/3/columns" \
  -H "OCS-APIRequest: true" -H "Content-Type: application/json" \
  -u "admin:app-password" \
  -d '{"columns": [{"field_id": 4, "show_as_column": true, "column_order": 0, "filterable": true}]}'
```

### Step 4 — Create views

```bash
curl -X POST ".../ocs/v2.php/apps/metavox/api/v1/groupfolders/3/views" \
  -H "OCS-APIRequest: true" -H "Content-Type: application/json" \
  -u "admin:app-password" \
  -d '{"name": "WOO open", "is_default": true, "columns": [{"field_name": "publicatiestatus", "visible": true, "filterable": true, "column_order": 0}], "filters": {"publicatiestatus": ["Open"]}, "sort_field": "publicatiestatus", "sort_order": "ASC"}'
```

### Step 5 — Grant permissions (admin)

```bash
# Grant manage-fields to a group
curl -X POST ".../index.php/apps/metavox/api/permissions/group" \
  -H "Content-Type: application/json" -b "session-cookie" \
  -d '{"group_id": "woo-beheerders", "permission": "manage-fields", "groupfolder_id": 3}'
```

> **Note**: Permission endpoints are on traditional routes (require session or admin). App password works if the user is a Nextcloud admin.

### Step 6 — Import metadata in batches of ≤100 files

```bash
curl -X POST ".../ocs/v2.php/apps/metavox/api/v1/files/metadata/batch-update" \
  -H "OCS-APIRequest: true" -H "Content-Type: application/json" \
  -u "admin:app-password" \
  -d '{"updates": [{"file_id": 290, "groupfolder_id": 3, "metadata": {"file_gf_publicatiestatus": "Open"}}]}'
```

For large datasets (10 000+ files), paginate in batches of 100 on the client side.

### Step 7 — Trigger backup (optional)

```bash
curl -X POST ".../index.php/apps/metavox/api/backup/trigger" \
  -b "session-cookie"
```

---

## Error Handling

All batch operations return detailed error information per item:

```json
{
  "ocs": {
    "meta": {"status": "ok", "statuscode": 200},
    "data": {
      "success": true,
      "total": 3,
      "successful": 2,
      "failed": 1,
      "results": [
        {"file_id": 123, "success": true, "fields_updated": 3},
        {"file_id": 456, "success": false, "error": "File not found"},
        {"file_id": 789, "success": true, "fields_updated": 3}
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
5. **Authentication**: Use app passwords for external scripts — no session or CSRF token needed

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
```

---

## Summary

All MetaVox API endpoints are served by `ApiFieldController` via the OCS API (`/ocs/v2.php/...`). This means every operation — field management, view configuration, column config, and bulk metadata — is accessible with an app password or OAuth token without requiring a browser session.

| Area | Endpoints |
|------|-----------|
| **Fields** | Create, update, delete fields; assign to groupfolder |
| **Views** | List, create, update, delete views per groupfolder |
| **Column config** | Get/set visible columns and filter config |
| **Metadata** | Read/write individual and bulk file metadata |
| **Batch operations** | Batch update, delete, copy metadata (≤100 files/request) |
| **Filter values** | Distinct field values for filter dropdowns |
| **Statistics** | Metadata usage stats |
