# MetaVox Integration Guide

This guide covers integrating MetaVox with external systems, migrating from other platforms, and leveraging Nextcloud's ecosystem.

## Nextcloud Integration

### Group Folders

MetaVox requires the Group Folders app and integrates deeply:

- **Folder Detection**: Automatically detects which Group folder a file belongs to
- **Permission Inheritance**: Respects Group folder ACL settings
- **Metadata Scoping**: Each Group folder can have unique metadata schemas

### Nextcloud Flow

MetaVox registers with the Workflow Engine for metadata-based automation.

**How it works:**

1. MetaVox implements `ICheck` interface
2. Registers via `RegisterChecksEvent`
3. Provides Vue component for condition configuration
4. Evaluates metadata conditions when Flow triggers

The check is only available in admin Flow rules, and only matches files inside
a team folder (not folders, not personal files).

**Example Flow rule:**
```
Trigger: File accessed
Condition: MetaVox > classification = confidential
Action: Block access
```

See [Flow Integration](../admin/flow-integration.md) for configuration details.

### Nextcloud Search

Metadata values are searchable through Nextcloud's unified search. Free-text search uses a word index per team folder, so every member finds files by their metadata; results only include files the user may read. Date, number, checkbox and File Link fields are not in the word index; find those with a field filter or a `field:value` query. See [Architecture Overview](overview.md#unified-search).

## SharePoint Migration

MetaVox is designed to support migrations from Microsoft SharePoint by preserving document metadata.

### Migration Approach

```
SharePoint          MetaVox API              Nextcloud
┌─────────┐        ┌──────────────┐        ┌──────────┐
│Documents│───────▶│batch-update  │───────▶│Group     │
│+Metadata│        │endpoint      │        │Folders   │
└─────────┘        └──────────────┘        └──────────┘
```

### Migration Steps

1. **Export SharePoint metadata** - Extract document metadata to JSON/CSV
2. **Create field definitions** - Set up equivalent fields in MetaVox
3. **Upload files** - Transfer documents to Nextcloud Group folders
4. **Map metadata** - Transform SharePoint fields to MetaVox format
5. **Batch import** - Use API to populate metadata

### Field Mapping Example

| SharePoint Field | MetaVox Equivalent |
|-----------------|-------------------|
| Content Type | `select` field with options |
| Modified By | `user` field |
| Retention Label | `select` field |
| Custom columns | Various field types |

### API for Migration

Use the batch update endpoint for efficient imports:

```bash
POST /ocs/v2.php/apps/metavox/api/v1/files/metadata/batch-update

{
  "updates": [
    {
      "file_id": 123,
      "groupfolder_id": 1,
      "metadata": {
        "file_gf_status": "Approved",
        "file_gf_department": "Legal",
        "file_gf_retention": "7 years"
      }
    },
    // ... more files
  ]
}
```

See [API Reference](api-reference.md) for complete documentation.

### Migration Tips

- **Batch size**: Limit to 100 files per API call
- **Field creation**: Create fields via API or import JSON template first
- **Validation**: Test with small batch before full migration
- **Error handling**: Check response for partial failures

## External System Integration

### Via OCS API

External systems can interact with MetaVox via the OCS REST API:

**Authentication:**
- Basic auth with app password
- Bearer token (if using OAuth)

**Common operations:**
- Get metadata for a file
- Update metadata
- Batch operations
- Get statistics

### Webhook Integration

Combine MetaVox with Nextcloud Flow to trigger external systems:

1. Flow rule detects metadata condition
2. Flow calls webhook action
3. External system receives notification

Example: Notify document management system when document status changes to "Published"

### Custom App Integration

PHP apps can use MetaVox services directly. These services do not check the
user's rights; the calling app does.

```php
use OCA\MetaVox\Service\FieldService;

$fieldService = \OCP\Server::get(FieldService::class);

// Get metadata for a file
$metadata = $fieldService->getGroupfolderFileMetadata($groupfolderId, $fileId);

// Update metadata: file id => [field name => value]
$fieldService->saveValues($groupfolderId, [$fileId => [$fieldName => $value]]);
```

### Embedding the metadata panel

Another app can show MetaVox's own metadata panel, the one in the Files
sidebar, as the custom element `<metavox-metadata-form>`. The host gets the
field pickers, the edit dialog, the team folder fields and the "Linked from"
list, without depending on MetaVox's Vue version.

Load the bundle while rendering the page. MetaVox is optional to the host, so
guard the call:

```php
if (class_exists(\OCA\MetaVox\Embed\MetadataFormEmbed::class)) {
    \OCP\Server::get(\OCA\MetaVox\Embed\MetadataFormEmbed::class)->load();
}
```

`isAvailable($userId)` tells whether MetaVox is enabled for a user.

Then place the element with a file id:

```js
const el = document.createElement('metavox-metadata-form')
el.fileId = node.fileid
container.appendChild(el)
```

The `file-id` attribute works too. MetaVox finds the team folder itself and
decides whether the user may edit, by the same rule as every save: the host
does not pass a team folder or permissions. A file outside a team folder
shows a message instead of fields.

Events bubble from the element:

| Event | Detail |
|---|---|
| `metavox:loaded` | `{ fileId, fields }` |
| `metavox:saved` | `{ fileId, metadata }` (the changed fields) |
| `metavox:error` | `{ message }` |

## Data Exchange Formats

### JSON Field Definition

```json
[
  {
    "field_name": "status",
    "field_label": "Document Status",
    "field_type": "select",
    "field_description": "Current status of the document",
    "field_options": [
      {"value": "Draft"},
      {"value": "In Review"},
      {"value": "Approved"},
      {"value": "Archived"}
    ],
    "is_required": true
  }
]
```

### CSV Export

Bulk editor exports metadata as CSV. The columns are the file path, the file
name and one column per field, headed by the field label:

```csv
File path,File name,Status,Department,Review date
/Documents/report.pdf,report.pdf,Approved,Legal,2025-01-15
/Documents/memo.docx,memo.docx,Draft,HR,
```

## Integration Patterns

### Event-Driven

```
File uploaded → Flow detects → Check metadata → Trigger action
```

Use case: Automatic classification, notifications, access control

### Scheduled

```
Cron job → Query API → Process metadata → Update external system
```

Use case: Compliance reporting, sync with external systems

### On-Demand

```
User action → API call → Get/update metadata → Return result
```

Use case: Custom UI, mobile apps, integrations

## Third-Party Tools

MetaVox can integrate with:

| Tool | Integration Method |
|------|-------------------|
| n8n | OCS API calls |
| Windmill | OCS API calls |
| Zapier | Webhooks via Flow |
| Power Automate | OCS API (with custom connector) |
| Custom scripts | OCS API, PHP SDK |

## Best Practices

1. **Use batch operations** for bulk changes
2. **Validate field names** before API calls
3. **Handle errors gracefully** - check for partial failures
4. **Cache field definitions** in integrations
5. **Test in staging** before production migrations

## See Also

- [API Reference](api-reference.md) - Complete API documentation
- [Architecture Overview](overview.md) - System design
- [Privacy & Security](privacy.md) - Data protection
