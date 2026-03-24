# MetaVox Permissions Guide

MetaVox uses Nextcloud's existing permission system. This guide explains how permissions work for metadata.

## Permission Model

MetaVox uses a granular permission system with three levels that can be assigned per user, per group, and per groupfolder:

| Permission | Description | Default |
|------------|-------------|---------|
| `view_metadata` | View metadata values | All users with folder access |
| `edit_metadata` | Edit metadata values for files | Users with write access |
| `manage_fields` | Create/edit/delete fields, manage views | Administrators only |

Nextcloud administrators always have all permissions regardless of configuration.

### Permission Matrix

| Action | view_metadata | edit_metadata | manage_fields | Admin |
|--------|:---:|:---:|:---:|:---:|
| View metadata in sidebar | Yes | Yes | Yes | Yes |
| View metadata columns in file list | Yes | Yes | Yes | Yes |
| Edit document metadata | No | Yes | Yes | Yes |
| Use bulk metadata editor | No | Yes | Yes | Yes |
| Export metadata to CSV | No | Yes | Yes | Yes |
| Create/edit/delete fields | No | No | Yes | Yes |
| Create/edit/delete views | No | No | Yes | Yes |
| Import/export field definitions | No | No | Yes | Yes |
| Access MetaVox admin settings | No | No | No | Yes |

## Granting Permissions

### To a User

```bash
curl -X POST "https://your-nextcloud.com/apps/metavox/api/permissions/user" \
  -H "Content-Type: application/json" \
  -b "session-cookie" \
  -d '{"user_id": "jane", "permission": "edit_metadata", "groupfolder_id": 3}'
```

### To a Group

```bash
curl -X POST "https://your-nextcloud.com/apps/metavox/api/permissions/group" \
  -H "Content-Type: application/json" \
  -b "session-cookie" \
  -d '{"group_id": "woo-beheerders", "permission": "manage_fields", "groupfolder_id": 3}'
```

### Viewing Permissions

```bash
# View all permissions
curl "https://your-nextcloud.com/apps/metavox/api/permissions" -b "session-cookie"

# Check your own permissions
curl "https://your-nextcloud.com/apps/metavox/api/permissions/me" -b "session-cookie"

# Check a specific permission
curl "https://your-nextcloud.com/apps/metavox/api/permissions/check?permission=edit_metadata&groupfolder_id=3" -b "session-cookie"
```

### Revoking Permissions

```bash
# Revoke user permission
curl -X DELETE "https://your-nextcloud.com/apps/metavox/api/permissions/user/{permissionId}" -b "session-cookie"

# Revoke group permission
curl -X DELETE "https://your-nextcloud.com/apps/metavox/api/permissions/group/{permissionId}" -b "session-cookie"
```

## Inheritance

Permissions are scoped to groupfolders. If a permission is granted without a `groupfolder_id`, it applies to all groupfolders:

```
Team Folder A (user has edit_metadata)
├── Subfolder A (inherits edit_metadata)
│   ├── Document 1 (user can edit metadata)
│   └── Document 2 (user can edit metadata)
└── Subfolder B
    └── Document 3 (user can edit metadata)

Team Folder B (user has view_metadata only)
├── Document 4 (user can only view metadata)
```

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

1. Check if the user has `edit_metadata` permission for this groupfolder
2. Verify user has write access to the specific document in Nextcloud
3. Check if the document is in a Team folder (not personal folder)
4. Ensure metadata fields are defined for this Team folder

### User wants to manage fields or views

The user needs the `manage_fields` permission. Grant it via the admin API or ask an administrator to assign it.

## See Also

- [Installation](installation.md) - Initial setup
- [Managing Views](views.md) - Create views per team folder
- [Compliance Templates](compliance-templates.md) - Pre-built metadata schemas
- [Getting Started](../getting-started.md) - Quick start guide
