# MetaVox Installation Guide

This guide covers installing and configuring MetaVox for your Nextcloud instance.

## Requirements

- Nextcloud 28 or higher
- Group Folders app installed and configured
- Administrator access

## Installation

### From App Store (Recommended)

1. Log in as administrator
2. Go to **Apps** (click your profile icon > Apps)
3. Search for "MetaVox" in the search bar
4. Click **Download and enable**

### Manual Installation

1. Download the latest release from [GitHub](https://github.com/nextcloud/metavox/releases)
2. Extract to `nextcloud/apps/metavox`
3. Go to **Apps** and enable MetaVox

## Initial Configuration

After installation:

1. Go to **Settings** > **MetaVox**
2. You'll see two tabs:
   - **Team folder Metadata** - Define fields for Team folders
   - **Document Metadata** - Define fields for individual documents

### Setting Up Team Folder Metadata

1. Select a Team folder from the dropdown
2. Click **Add Field** to create a new metadata field
3. Configure the field:
   - **Field Name**: Internal identifier (lowercase, no spaces)
   - **Field Label**: Display name shown to users
   - **Field Type**: Text, select, date, etc.
   - **Description**: Help text for users
   - **Required**: Whether the field must be filled in
   - **Options**: For dropdown fields, comma-separated values

![Team folder metadata setup](../../screenshots/Manage%20team%20metadata.png)

### Setting Up Document Metadata

Document metadata fields are configured similarly but apply to individual files rather than the whole folder.

## Import/Export

### Importing Field Definitions

1. Go to **Settings** > **MetaVox** > **Team folder Metadata**
2. Click **Select JSON File** under "Import & Export"
3. Select your JSON file
4. Review the preview and confirm

### Exporting Field Definitions

1. Configure your fields as desired
2. Click **Export** to download the JSON file
3. Use this file to replicate settings on other instances

### Using Compliance Templates

MetaVox includes ready-to-use templates for Dutch government compliance:

| Template | Purpose |
|----------|---------|
| `avg-compliance.json` | GDPR personal data classification |
| `woo-compliance.json` | Open Government Act (WOO) publication status |
| `archiefwet-compliance.json` | Archives Act retention periods |
| `overheid-compleet.json` | Combined compliance fields |

Templates are located in `/templates/compliance/`. See [Compliance Templates](compliance-templates.md) for details.

## Updating MetaVox

1. Go to **Apps**
2. Find MetaVox in your installed apps
3. Click **Update** if available

Or update manually by replacing the app folder with the new version.

## Troubleshooting

### MetaVox section not showing

- Ensure you're viewing a file in a Team folder (not a personal folder)
- Check that the Team folder has metadata fields configured
- Verify the user has at least read access to the folder

### Cannot edit metadata

- User needs edit permissions on the document
- Team folder metadata is always read-only for non-admins
- Check if fields are defined for this Team folder

### Import fails

- Verify JSON format is correct
- Check for duplicate field names
- Ensure field types are valid

## Next Steps

- [Permissions](permissions.md) - Configure access control
- [Compliance Templates](compliance-templates.md) - Use pre-built templates
- [Flow Integration](flow-integration.md) - Automate workflows
