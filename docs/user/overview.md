# MetaVox User Guide

MetaVox helps you organize and classify documents by adding metadata - structured information about your files.

## Why Metadata?

Documents often need context beyond their content:
- Who is responsible for this document?
- Is it approved or still a draft?
- When should it be reviewed?
- Is it confidential or public?

MetaVox lets you capture this information in a structured, searchable way.

## Viewing Metadata

1. Navigate to a document in a Team folder
2. Open the sidebar (info icon or press `i`)
3. Find the **MetaVox** section

You'll see two types of metadata:

### Team Folder Metadata
- Applies to the entire Team folder
- Set by administrators
- Read-only for regular users
- Shown at the top of the MetaVox section

### Document Metadata
- Specific to this document
- Editable if you have write access
- Shown below the Team folder metadata

![MetaVox sidebar](../../screenshots/File%20metadata.png)

## Editing Metadata

If you have edit permissions on a document:

1. Open the document's sidebar
2. Find the MetaVox section
3. Click on any editable field
4. Enter or select a value
5. Changes save automatically

### Field Types

Different fields accept different types of input:

| Type | Example |
|------|---------|
| Text | Short descriptions, titles |
| Textarea | Longer notes, summaries |
| Number | Version numbers, counts |
| Date | Due dates, review dates |
| Dropdown | Status (Draft/Approved/Archived) |
| Multi-select | Multiple categories |
| Checkbox | Yes/No flags |
| URL | Links to external resources |
| User | Select a Nextcloud user |
| File link | Link to another file in Nextcloud |

See [Field Types](field-types.md) for detailed information.

## Editing Multiple Files

Need to update metadata for many files at once? Use the Bulk Editor:

1. Select multiple files in the file list
2. Click **Edit Metadata** in the toolbar
3. Fill in the fields you want to update
4. Choose a merge strategy (overwrite or fill empty only)
5. Click **Save**

See [Bulk Editing](bulk-editing.md) for details.

## Using Views

Views let you switch between predefined combinations of columns, filters, and sort order. Your administrator creates views for each Team folder to suit different workflows.

See [Views](views.md) for details.

## Tips

- **Required fields** are marked with an asterisk (*)
- **Descriptions** appear below fields to help you understand what to enter
- **Dropdowns** show predefined options - you cannot enter custom values
- **Changes are immediate** - there's no separate save button

## Need Help?

- Check the [Getting Started](../getting-started.md) guide for an introduction

Contact your Nextcloud administrator if:
- You need different metadata fields
- You can't edit fields you should be able to edit
- You have questions about what values to enter

## See Also

- [Field Types](field-types.md) - All available field types
- [Views](views.md) - Switching between predefined views
- [Bulk Editing](bulk-editing.md) - Edit metadata for multiple files
- [Exporting Data](exporting-data.md) - Export metadata to CSV
