# MetaVox Permissions Guide

MetaVox uses Nextcloud's existing permission system. This guide explains how permissions work for metadata.

## Permission Model

MetaVox follows a simple principle: **metadata permissions follow document permissions**.

| User Permission | Can View Metadata | Can Edit Document Metadata | Can Edit Team Folder Metadata |
|-----------------|-------------------|---------------------------|------------------------------|
| Read-only | Yes | No | No |
| Read/Write | Yes | Yes | No |
| Administrator | Yes | Yes | Yes |

## Roles Explained

### Regular Users

Users with **read access** to a Team folder can:
- View all metadata (Team folder and document level)
- See metadata in the sidebar when viewing files

Users with **write access** can additionally:
- Edit document-specific metadata
- Use the bulk metadata editor
- Export metadata to CSV

### Administrators

Administrators can:
- Define metadata fields for Team folders
- Define document metadata schemas
- Import/export field definitions
- Edit Team folder metadata values
- Access MetaVox settings

## Team Folder Metadata vs Document Metadata

### Team Folder Metadata

- **Defined by**: Administrators
- **Applies to**: All documents in the Team folder
- **Editable by**: Administrators only
- **Visibility**: Read-only for regular users

Use cases:
- Project classification
- Department assignment
- Compliance category

### Document Metadata

- **Defined by**: Administrators
- **Applies to**: Individual documents
- **Editable by**: Users with write access
- **Visibility**: Anyone with read access

Use cases:
- Document status
- Review dates
- Responsible person

## Inheritance

Metadata permissions are inherited from the Nextcloud file system:

```
Team Folder (Admin sets folder metadata)
├── Subfolder A (inherits permissions)
│   ├── Document 1 (user edits document metadata)
│   └── Document 2
└── Subfolder B
    └── Document 3
```

If a user has write access to "Document 1", they can edit its document metadata. Team folder metadata remains read-only.

## Best Practices

### For Administrators

1. **Keep field lists manageable** - Too many fields overwhelm users
2. **Use clear labels** - Field names should be self-explanatory
3. **Add descriptions** - Help users understand what to enter
4. **Mark critical fields as required** - Ensure important metadata is captured

### For Organizations

1. **Document your schema** - Keep a record of what each field means
2. **Train users** - Explain why metadata matters
3. **Start small** - Begin with essential fields, add more later
4. **Review regularly** - Remove unused fields

## Troubleshooting

### User can't see metadata

1. Check if user has read access to the Team folder
2. Verify MetaVox is enabled
3. Confirm fields are defined for this Team folder

### User can't edit document metadata

1. Verify user has write access to the specific document
2. Check if the document is in a Team folder (not personal folder)
3. Ensure document metadata fields are defined

### User wants to edit Team folder metadata

Only administrators can edit Team folder metadata. If a regular user needs to set folder-level information:
1. Promote them to administrator (if appropriate)
2. Or have an administrator make the changes
3. Or reconsider whether this should be document-level metadata instead

## See Also

- [Installation](installation.md) - Initial setup
- [Managing Views](views.md) - Create views per team folder
- [Compliance Templates](compliance-templates.md) - Pre-built metadata schemas
- [Getting Started](../getting-started.md) - Quick start guide
