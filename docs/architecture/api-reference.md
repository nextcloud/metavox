# MetaVox API Reference

## Overview
The MetaVox API provides endpoints for metadata management, field configuration, views, column configuration, and batch operations.

### Route Types

MetaVox exposes two types of API routes:

| Type | Base URL | Auth | Use case |
|------|----------|------|----------|
| **OCS** | `/ocs/v2.php/apps/metavox/api/v1/` | App password, OAuth, or session | External integrations, scripts, migrations |
| **Browser** | `/apps/metavox/api/` | Session (CSRF token required) | Internal UI, admin operations |

**For external integrations**, always use OCS routes — they don't require a CSRF token and work with app passwords.

**Browser routes** are used by the MetaVox frontend. They require a Nextcloud session and CSRF token. Some features (permissions, AI, backup, settings, telemetry) are only available via browser routes.

## Architecture

| Controller | Responsibility |
|------------|---------------|
| **`ApiFieldController`** | All OCS endpoints: fields, column config, batch operations, filter values, directory metadata |
| **`ApiFilterController`** | OCS endpoints: directory metadata, filter values, sorted file IDs |
| **`ApiViewController`** | OCS endpoints: view CRUD per groupfolder |
| **`ViewController`** | Browser-based view management (admin UI, requires session) |
| **`LockController`** | Cell lock/unlock for concurrent editing |
| **`PresenceController`** | Presence tracking (leave on tab close) |
| **`AiAutofillController`** | AI metadata generation |
| **`BackupController`** | Backup & restore operations |
| **`SettingsController`** | Admin settings (AI toggle) |
| **`TelemetryController`** | Usage telemetry reporting |
| **`PermissionController`** | Granular permission management |
| **`FieldService`** | Field definitions, metadata reads/writes — with distributed cache |
| **`ViewService`** | View CRUD — with distributed cache |

## Important Notes
- All batch operations work with **groupfolder file fields** (stored in `metavox_gf_fields` and `metavox_file_gf_meta`)
- Batch update/delete operations require both `file_id` and `groupfolder_id` per item
- Read-only endpoints for stable data (column config, views, filter values) return `Cache-Control: private` headers

## Field Naming Convention

Field names (`field_name`) must follow a strict naming convention:

### Rules
- Only **lowercase letters** (a-z), **numbers** (0-9), and **underscores** (_) are allowed
- Must **start with a letter** (not a number or underscore)
- No spaces, uppercase letters, or special characters
- Validated on both frontend and backend — invalid names are rejected with a `400 Bad Request`

### Prefix Convention

MetaVox uses prefixes to distinguish between field scopes:

| Scope | Prefix | Example | Description |
|-------|--------|---------|-------------|
| Team folder metadata | `gf_` | `gf_publication_status` | Metadata that applies to the team folder itself |
| File metadata | `file_gf_` | `file_gf_department` | Metadata that applies to individual files within a team folder |

**Important:** The MetaVox admin UI adds the prefix automatically. The API does **not** — API consumers must include the correct prefix in the `field_name` when creating fields. If you omit the prefix, the field will be stored without it and may not appear correctly in the UI.

### Examples

```bash
# Correct — file field with prefix
curl -X POST ".../api/v1/groupfolder-fields" \
  -d '{"field_name": "file_gf_department", "field_label": "Department", "field_type": "select"}'

# Correct — team folder field with prefix
curl -X POST ".../api/v1/groupfolder-fields" \
  -d '{"field_name": "gf_classification", "field_label": "Classification", "field_type": "text", "applies_to_groupfolder": 1}'

# Invalid — will be rejected (uppercase)
curl -X POST ".../api/v1/groupfolder-fields" \
  -d '{"field_name": "file_gf_Department", ...}'
# → 400: "Field name may only contain lowercase letters, numbers and underscores"

# Invalid — will be rejected (spaces)
curl -X POST ".../api/v1/groupfolder-fields" \
  -d '{"field_name": "file_gf_my field", ...}'
# → 400: "Field name may only contain lowercase letters, numbers and underscores"
```

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

### Get all field definitions

**Endpoint**: `GET /ocs/v2.php/apps/metavox/api/v1/groupfolder-fields`
**Requires**: Nextcloud admin

Returns all defined fields across all groupfolders.

### Assign fields to a groupfolder

**Endpoint**: `POST /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/fields`
**Requires**: Nextcloud admin

**Request body**:
```json
{"field_ids": [4, 5, 6]}
```

### Get assigned fields for a groupfolder

**Endpoint**: `GET /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/fields`

Returns the list of fields assigned to this groupfolder.

### Get file-level fields for a groupfolder

**Endpoint**: `GET /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/file-fields`

Returns fields assigned to this groupfolder that apply to individual files (not folder-level). Used by the view editor to populate column options.

---

## File Metadata

### Get metadata for a single file

**Endpoint**: `GET /ocs/v2.php/apps/metavox/api/v1/files/{fileId}/metadata`

Returns all metadata for a file. Requires read access to the file.

**Response**:
```json
{
  "ocs": {
    "data": {
      "file_gf_publicatiestatus": "Open",
      "file_gf_verantwoordelijke": "j.doe"
    }
  }
}
```

### Save metadata for a single file

**Endpoint**: `POST /ocs/v2.php/apps/metavox/api/v1/files/{fileId}/metadata`

Accepts a partial metadata object — only the fields included are updated. Requires write access.

**Request body**:
```json
{
  "metadata": {
    "file_gf_publicatiestatus": "Gesloten",
    "file_gf_verantwoordelijke": "a.jansen"
  }
}
```

### Get metadata for multiple files (bulk read)

**Endpoint**: `GET /ocs/v2.php/apps/metavox/api/v1/files/metadata/bulk?file_ids=123,456,789`

Returns metadata for up to **100 file IDs** per request. Only accessible files are returned.

**Response**:
```json
{
  "ocs": {
    "data": {
      "123": {"file_gf_publicatiestatus": "Open"},
      "456": {"file_gf_publicatiestatus": "Gesloten"}
    }
  }
}
```

### Get file metadata within a groupfolder

**Endpoint**: `GET /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/files/{fileId}/metadata`

Same as single file metadata, but scoped to a groupfolder context.

### Save file metadata within a groupfolder

**Endpoint**: `POST /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/files/{fileId}/metadata`

Same as single file save, but scoped to a groupfolder context. Supports `unlock` and `unlock_field` parameters to atomically save and release a cell lock.

---

## Groupfolders

### List all groupfolders

**Endpoint**: `GET /ocs/v2.php/apps/metavox/api/v1/groupfolders`

Returns all groupfolders the authenticated user has access to.

### Get groupfolder metadata

**Endpoint**: `GET /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/metadata`

Returns folder-level metadata (not per-file).

### Save groupfolder metadata

**Endpoint**: `POST /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/metadata`

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

### Reorder views

**Endpoint**: `PUT /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/views/reorder`
**Requires**: admin or manage-fields permission

**Request body**:
```json
{"view_ids": [3, 1, 5, 2]}
```

The order of the IDs determines the tab display order.

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

### Get all filter values (batch)

**Endpoint**: `GET /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/all-filter-values`
**Response includes `Cache-Control: private, max-age=300`**

Returns distinct values for all fields in one request. For select/multiselect/checkbox fields, returns configured options from field definition. For text/date/number fields, queries the database.

**Optional parameter**: `field_names` (comma-separated) to limit to specific fields.

**Response**:
```json
{
  "publicatiestatus": ["Open", "Gesloten", "In behandeling"],
  "verantwoordelijke": ["j.doe", "a.jansen"],
  "is_verwerkt": ["1", "0"]
}
```

### Get scoped filter values (current directory)

**Endpoint**: `POST /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/filter-values`

Returns distinct values scoped to specific file IDs (e.g., files in the current directory).

**Request body**:
```json
{"file_ids": [123, 456, 789]}
```

> **Note**: For checkbox fields, values are `"1"` (checked) and `"0"` (unchecked). The frontend maps these to "Yes"/"No".

### Get sorted and filtered file IDs

**Endpoint**: `GET /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/sorted-file-ids`

Returns an ordered list of file IDs matching the given sort and filter criteria. Used for server-side sorting on large datasets.

**Parameters**:
- `sort_field` — field name to sort by
- `sort_order` — `asc` or `desc` (default: `asc`)
- `sort_field_type` — `text`, `number`, `date`, or `checkbox` (default: `text`)
- `filters` — JSON object of filter criteria, e.g. `{"publicatiestatus": ["Open"]}`

**Response**:
```json
{"ocs": {"data": [456, 123, 789]}}
```

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

### OCS API (app password / OAuth — no CSRF)

| Area | Endpoints |
|------|-----------|
| **Fields** | Create, update, delete fields; assign to groupfolder; list file-level fields |
| **File Metadata** | Read/write per file; bulk read (≤100); groupfolder-scoped read/write |
| **Groupfolders** | List, read/write folder-level metadata |
| **Views** | List, create, update, delete, reorder views per groupfolder |
| **Column config** | Get/set visible columns and filter config |
| **Batch operations** | Batch update, delete, copy metadata (≤100 files/request) |
| **Filter values** | All values (batch), scoped values, sorted file IDs |
| **Directory metadata** | Bulk metadata for file list columns (≤200 files) |
| **Statistics** | Metadata usage stats |

### Browser API (session + CSRF)

| Area | Endpoints |
|------|-----------|
| **Initialization** | Single-call bootstrap (groupfolders + fields + views) |
| **User endpoints** | Non-admin groupfolder/field/metadata access |
| **Cell locking** | Lock/unlock cells for concurrent editing |
| **Presence** | Track active users per groupfolder |
| **AI Autofill** | AI-powered metadata generation |
| **Backup & Restore** | Create, list, download, restore metadata backups |
| **Settings** | Admin app settings (AI toggle) |
| **Telemetry** | Anonymous usage reporting |
| **Permissions** | Granular permission management (user/group) |
| **Utilities** | User search, metadata export |
| **Cell locking** | Lock/unlock cells for concurrent editing |
| **Presence** | Track active users per groupfolder |
| **AI Autofill** | AI-powered metadata generation |
| **Backup & Restore** | Create and restore metadata backups |
| **Settings** | Admin app settings |
| **Telemetry** | Anonymous usage reporting |
| **Permissions** | Granular permission management |

---

## Cell Locking (Real-Time Collaboration)

Requires `notify_push` and Redis for full functionality.

### Lock a cell

**Endpoint**: `POST /apps/metavox/api/groupfolders/{groupfolderId}/files/{fileId}/lock`

**Request body**:
```json
{"field_name": "publicatiestatus"}
```

**Response** (success):
```json
{"locked": false}
```

**Response** (already locked by another user, HTTP 409):
```json
{"locked": true, "lockedBy": "jane"}
```

### Unlock a cell

**Endpoint**: `POST /apps/metavox/api/groupfolders/{groupfolderId}/files/{fileId}/unlock`

**Request body**:
```json
{"field_name": "publicatiestatus"}
```

### Presence leave

Called via `sendBeacon` on tab close to clean up presence tracking.

**Endpoint**: `POST /apps/metavox/api/presence/leave`

---

## AI Autofill

Requires a Nextcloud AI task processing provider (e.g., the LLM2 app).

### Check AI availability

**Endpoint**: `GET /apps/metavox/api/ai/status`

**Response**:
```json
{"available": true}
```

### Generate metadata suggestions

**Endpoint**: `POST /apps/metavox/api/ai/generate`

**Request body**:
```json
{
  "fileId": 290,
  "groupfolderId": 3,
  "rejectedSuggestions": {}
}
```

**Response**:
```json
{
  "suggestions": {
    "file_gf_onderwerp": "Project planning",
    "file_gf_auteur": "Jan de Vries"
  }
}
```

---

## Backup & Restore

Admin-only endpoints for metadata backup and restore.

### List backups

**Endpoint**: `GET /apps/metavox/api/backup/list`

### Trigger backup

**Endpoint**: `POST /apps/metavox/api/backup/trigger`

Creates a gzip-compressed JSON backup of all metadata tables. Maximum 7 backups are retained.

### Restore backup

**Endpoint**: `POST /apps/metavox/api/backup/restore`

**Request body**:
```json
{"filename": "metavox_backup_2026-03-24_120000.json.gz"}
```

### Download backup

**Endpoint**: `GET /apps/metavox/api/backup/download?filename=metavox_backup_2026-03-24_120000.json.gz`

### Backup status

**Endpoint**: `GET /apps/metavox/api/backup/status`

Returns the current status of a running backup or restore operation. Used by the frontend to show progress.

**Response**:
```json
{"status": "idle"}
```

---

## Settings

Admin-only endpoints for app configuration.

### Get settings

**Endpoint**: `GET /apps/metavox/api/settings`
**Requires**: Nextcloud admin

**Response**:
```json
{
  "success": true,
  "settings": {
    "ai_enabled": true
  }
}
```

### Save settings

**Endpoint**: `POST /apps/metavox/api/settings`
**Requires**: Nextcloud admin

**Request body**:
```json
{"ai_enabled": false}
```

---

## Telemetry

See [Telemetry](../admin/telemetry.md) for details.

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/apps/metavox/api/telemetry/status` | GET | Check if telemetry is enabled |
| `/apps/metavox/api/telemetry/stats` | GET | View collected statistics |
| `/apps/metavox/api/telemetry/send` | POST | Manually trigger telemetry report |
| `/apps/metavox/api/telemetry/settings` | POST | Enable or disable telemetry |

---

## Permissions

See [Permissions](../admin/permissions.md) for details.

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/apps/metavox/api/permissions` | GET | List all permissions |
| `/apps/metavox/api/permissions/me` | GET | Check your own permissions |
| `/apps/metavox/api/permissions/check` | GET | Check a specific permission |
| `/apps/metavox/api/permissions/groups` | GET | List available groups |
| `/apps/metavox/api/permissions/user` | POST | Grant permission to a user |
| `/apps/metavox/api/permissions/group` | POST | Grant permission to a group |
| `/apps/metavox/api/permissions/user/{id}` | DELETE | Revoke user permission |
| `/apps/metavox/api/permissions/group/{id}` | DELETE | Revoke group permission |

---

## Initialization

### Bootstrap endpoint

**Endpoint**: `GET /apps/metavox/api/init`

Single-call initialization for the files plugin. Returns all data needed to render metadata columns in one request, eliminating multiple sequential API calls.

**Parameters**:
- `dir` — current directory path (used to detect groupfolder)

**Response**:
```json
{
  "groupfolders": [
    {"id": 3, "mount_point": "Shared Documents", ...}
  ],
  "groupfolder_id": 3,
  "fields": [
    {"field_name": "publicatiestatus", "field_label": "Publicatiestatus", "field_type": "select", ...}
  ],
  "views": [
    {"id": 1, "name": "WOO open", "is_default": true, "columns": [...], ...}
  ],
  "can_manage": true
}
```

> **Note**: This endpoint also registers presence for the detected groupfolder (for real-time sync).

---

## User Endpoints (Browser Routes)

Non-admin endpoints for users to manage their accessible groupfolders and metadata. These require a browser session.

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/apps/metavox/api/user/groupfolders` | GET | List groupfolders accessible by current user |
| `/apps/metavox/api/user/groupfolder-fields` | GET | Get all available field definitions |
| `/apps/metavox/api/user/groupfolders/{id}/fields` | GET | Get assigned fields for a groupfolder |
| `/apps/metavox/api/user/groupfolders/{id}/fields` | POST | Assign fields to a groupfolder |
| `/apps/metavox/api/user/groupfolders/{id}/metadata` | GET | Get groupfolder metadata |
| `/apps/metavox/api/user/groupfolders/{id}/metadata` | POST | Save groupfolder metadata |

---

## Utility Endpoints (Browser Routes)

### Search users

**Endpoint**: `GET /apps/metavox/api/users?search={query}`

Returns up to 25 matching Nextcloud users. Used by the `user` field type for autocomplete.

**Response**:
```json
[
  {"id": "jdoe", "displayName": "Jane Doe"},
  {"id": "asmith", "displayName": "Alice Smith"}
]
```

### Export metadata to CSV

**Endpoint**: `POST /apps/metavox/api/files/export-metadata`

Exports metadata for selected files as a JSON array (client formats to CSV).

**Request body**:
```json
{"file_ids": [123, 456, 789], "groupfolder_id": 3}
```
