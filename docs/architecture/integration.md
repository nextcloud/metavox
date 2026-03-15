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

**Example Flow rule:**
```
Trigger: File accessed
Condition: MetaVox > classification = confidential
Action: Block access
```

See [Flow Integration](../admin/flow-integration.md) for configuration details.

### Nextcloud Search

Metadata values are indexed and searchable through Nextcloud's unified search. Users can find documents by metadata values.

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
| Modified By | `usergroup` field |
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

PHP apps can use MetaVox services directly:

```php
use OCA\MetaVox\Service\FieldService;

$fieldService = \OC::$server->get(FieldService::class);

// Get metadata for a file
$metadata = $fieldService->getGroupfolderFileMetadata($fileId, $groupfolderId);

// Update metadata
$fieldService->saveFileGfMetadata($fileId, $groupfolderId, $fieldName, $value);
```

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

Bulk editor exports metadata as CSV:

```csv
file_path,file_name,status,department,review_date
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
