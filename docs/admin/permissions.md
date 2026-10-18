# MetaVox Permissions Guide

MetaVox follows Nextcloud's own permissions. Whether someone may see or change metadata depends on what Nextcloud allows for the file and its Team folder, advanced permissions (ACL) included. MetaVox adds one right of its own: assigning fields and setting default values.

## Who may do what

| Action | Who |
|--------|-----|
| See the metadata of a file (columns, sidebar, search, export) | Anyone who can open the file in its Team folder |
| Change the metadata of a file (grid, sidebar, bulk editor, API) | Anyone who may change the file in its Team folder |
| Create, change, reorder and delete views | Members who may change the Team folder |
| Change the metadata of the Team folder itself | Members who may change the Team folder |
| Assign fields to a Team folder, set default values | Members with the MetaVox manage permission who may change the Team folder |
| Create, change and delete field definitions | Nextcloud administrators |
| MetaVox admin settings | Nextcloud administrators |

Nextcloud administrators may do all of this in every Team folder.

- **May change the file** means Nextcloud's update permission on the file in its Team folder, ACL included. A file you only reach through a share does not count: metadata belongs to the Team folder.
- With ACL, the rights apply per file. In a folder where you may write everywhere, a file you may only read stays read-only, in the grid and in the sidebar.
- **May change the Team folder** means Nextcloud's update permission on the Team folder's top folder, ACL included.
- Views are shared by all members. Whoever may change the Team folder may also change or delete views made by others.
- In the web interface, the Team folder's own metadata is edited in personal settings → MetaVox, which only users with the manage permission see. Other members who may change the Team folder can change it through the API. In the Files sidebar it is always read-only.

## The manage permission

An administrator gives it to groups on the MetaVox admin page: Administration settings → MetaVox → **Permissions** → **Add permission**. Pick one or more groups under **Select groups** and click **Grant permission**. The grant shows as **Manage metadata fields & views** and applies to every Team folder. **Revoke** takes it away again.

What the permission allows, in a Team folder where the user is a member and may change the Team folder:

- Assign fields to the Team folder
- Set default values for its file fields

Whoever has it for at least one Team folder finds a MetaVox section in their personal settings. There they use **Configure fields** to assign fields and set default values, and **Edit metadata** to change the Team folder's own metadata.

What the permission does not do:

- It gives no access to files or metadata. Seeing and changing file metadata follow Nextcloud's own permissions.
- It is not needed for views, despite its label. Creating and changing views needs write permission on the Team folder's top folder, nothing more.
- It does not allow creating, changing or deleting field definitions. That stays with Nextcloud administrators.

## When something is not allowed

- What you may not see (a file, or a Team folder you are not a member of) answers **404**, as if it is not there.
- What you see but may not do answers **403**.
- API answers carry a fixed `reason`, such as `not_found`, `read_only`, `not_manager` or `forbidden`. See the [API reference](../architecture/api-reference.md).

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

1. Can the user open the file in the Team folder? A file hidden by ACL shows no metadata.
2. Is MetaVox enabled for the user?
3. Are fields assigned to this Team folder?

### User can't edit document metadata

1. Does the user have write permission on this file? With ACL, check the file itself, not only the folder.
2. Does the Team folder give the user's group only read permission?
3. Is the file in a Team folder? Metadata of files reached only through a share can't be edited.

### User can't create or change views

The user needs write permission on the Team folder's top folder. Write permission in a subfolder only is not enough.

### User can't assign fields or set default values

The user needs the manage permission, through one of their groups (MetaVox admin page → **Permissions**), and write permission on the Team folder's top folder.

## See Also

- [Installation](installation.md) - Initial setup
- [Managing Views](views.md) - Create views per team folder
- [Compliance Templates](compliance-templates.md) - Pre-built metadata schemas
- [Getting Started](../getting-started.md) - Quick start guide
