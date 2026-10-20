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

1. Navigate to a file or folder in a Team folder
2. Open the sidebar (info icon or press `i`)
3. Open the **MetaVox** tab

You'll see two types of metadata:

### Team Folder Metadata
- Applies to the entire Team folder
- Set by administrators
- Read-only for regular users
- Shown at the top of the MetaVox tab, under **Team folder metadata**. Click the heading to collapse it.

### File Metadata
- Specific to this file
- Editable if you have write access
- Shown below the Team folder metadata, under **File metadata** (or **Folder metadata** for a folder)

If other files link to this one through a File link field, a **Linked from** section lists them. Click a name to open that file.

![MetaVox sidebar](../../screenshots/File%20metadata.png)

## Editing Metadata

The sidebar shows the values; you edit them in a dialog. If you have edit permissions on the file:

1. Open the file's sidebar
2. Open the **MetaVox** tab
3. Click the pencil button next to **File metadata** (**Edit file metadata**)
4. Enter or select values. Each field's description is shown below it.
5. Click **Save**. **Cancel** closes the dialog without saving.

**Save** stays disabled until you change a value. After saving you see *Metadata saved*. If your administrator has enabled AI, the dialog also has a **Generate with AI** button; see [AI Autofill](../features/ai-autofill.md).

### Field Types

Different fields accept different types of input:

| Type | Example |
|------|---------|
| Text | Short descriptions, titles |
| Long Text | Longer notes, summaries |
| Number | Version numbers, counts |
| Date | Due dates, review dates |
| Dropdown | Status (Draft/Approved/Archived) |
| Multi-select | Multiple categories |
| Checkbox | Yes/No flags |
| URL | Links to external resources |
| People and groups | Select Nextcloud accounts, groups or teams |
| File Link | Links to files or folders in the same Team folder |

See [Field Types](field-types.md) for detailed information.

## Working in the File List

MetaVox fields can also appear as columns in the file list of a Team folder. Which columns you see depends on the active [view](views.md).

### Editing a cell

- Double-click a cell to open an editor for the field type (text input, dropdown, date picker, etc.). With the keyboard, move to the cell and press `Enter` or `F2`.
- Press `Enter` to save, or `Escape` to cancel. Choosing an option in a dropdown saves it right away.
- You can only edit cells of files you have write access to.

![Inline editor opened on a cell](../../screenshots/inline-editor.png)

In motion:

![Inline editing in action](../../screenshots/inline-editing.gif)

### Copying a value down

While a cell's editor is open, a small square appears at its bottom-right corner. Drag it down to copy the value to the cells below, like the fill handle in a spreadsheet. Cells of files you can't edit are skipped.

### Undo

After an edit in the file list, a message appears at the bottom of the screen: *Metadata updated*, or *{count} cells updated* after copying a value down. Click **Undo** in that message to put the old values back. Only the most recent change can be undone this way.

### Sorting

- Click a MetaVox column header to sort by that field. Click it again to reverse the order.
- Sorting on a Files app column, such as Name or Modified, ends the MetaVox sort.
- If not all values could be loaded, you see *Sorting and filtering on MetaVox columns is paused: not all values could be loaded.* The list keeps its current order. Open the folder again to retry.

### Long values

A value longer than 512 characters is shortened in the list and ends with "…". Hover over the cell to see *Shortened. The full value loads when you edit the cell.* A shortened link is not clickable. Open the editor to see and change the full value.

### Highlighted cells

A view can color cells by their value, for example a deadline that has passed in red. A column with such rules shows an icon next to its name. See [Highlight Rules](highlight-rules.md).

### Editing at the same time

When someone else is editing a cell, it does not open for you. Instead the cell tells you who has it: *Being edited by {user}*. If your administrator has set up [real-time sync](../features/real-time-sync.md), changes by others appear in your list without reloading the page.

## Editing Multiple Files

Need to update metadata for many files at once? Use the Bulk Editor:

1. Select two or more files in the file list
2. Click **Edit metadata ({count} items)** in the toolbar above the file list (it may be under the **…** menu)
3. Fill in the fields you want to update
4. Choose a merge strategy (**Overwrite existing values** or **Only fill empty fields**)
5. Click **Save**

See [Bulk Editing](bulk-editing.md) for details.

## Using Views

Views let you switch between predefined combinations of columns, filters, sort order, and highlighted cells. Your administrator creates views for each Team folder to suit different workflows.

See [Views](views.md) for details.

## Tips

- **Required fields** are marked with an asterisk (*). The edit dialog saves only when each required field has a value and each required checkbox is ticked, and says under the field what is missing. In the grid an empty required cell is marked, and a required field cannot be emptied
- **Descriptions** appear below fields to help you understand what to enter
- **Dropdowns** show predefined options - you cannot enter custom values
- **Sidebar changes need Save** - in the edit dialog nothing is stored until you click **Save**; edits in the file list save when you press `Enter` or pick an option

## Need Help?

- Check the [Getting Started](../getting-started.md) guide for an introduction

Contact your Nextcloud administrator if:
- You need different metadata fields
- You can't edit fields you should be able to edit
- You have questions about what values to enter

## See Also

- [Field Types](field-types.md) - All available field types
- [Views](views.md) - Switching between predefined views
- [Highlight Rules](highlight-rules.md) - Cells colored by their value
- [Searching Metadata](search.md) - Find files by their metadata
- [Bulk Editing](bulk-editing.md) - Edit metadata for multiple files
- [Exporting Data](exporting-data.md) - Export metadata to CSV
- [AI Autofill](../features/ai-autofill.md) - AI-powered metadata suggestions
- [Real-Time Sync](../features/real-time-sync.md) - Collaborative editing with cell locking
