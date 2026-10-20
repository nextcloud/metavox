# Exporting Data

MetaVox lets you export metadata to CSV for use in spreadsheets, reports, or external systems.

## How to Export

1. Navigate to a Team folder in the Files app
2. Select the files you want to export (checkboxes or Ctrl/Cmd+click). The Bulk Editor needs at least two selected files.
3. Click **"Edit metadata ({count} items)"** in the toolbar above the file list (it may be under the **…** menu) to open the Bulk Editor
4. Click the **"Export CSV"** button

The export downloads automatically with a date-stamped filename, for example `metadata-export-2026-03-17.csv`.

## Export Format

The CSV file contains one row per selected file with the following columns:

| Column | Description |
|--------|-------------|
| **File path** | Path of the file in your Files |
| **File name** | File name |
| *metadata fields* | One column per file metadata field of the Team folder, headed by the field label |

The headers are in your Nextcloud language: in Dutch, for example, the first two are `Bestandspad` and `Bestandsnaam`. Team folder metadata is not included.

**Example:**
```csv
File path,File name,Status,Department,Review date
/Documents/report.pdf,report.pdf,Approved,Legal,2026-01-15
/Documents/memo.docx,memo.docx,Draft,HR,
/Documents/policy.pdf,policy.pdf,Archived,Management,2025-06-30
```

## Tips

- **Select specific files** to export only what you need, or select all files in the folder for a complete export
- **Special characters** (commas, quotes, line breaks) are properly escaped in the CSV output
- **Empty fields** appear as empty values in the CSV — they are not omitted
- **File Link fields** show the names of the linked files, separated by commas. A file that no longer exists or that you can't access shows as `Unavailable file`.
- **Multi-select fields** keep their stored form, with the values separated by `;#` (for example `Legal;#Finance`)
- **People and groups fields** show the stored ids: an account ID, `group:<id>`, `team:<id>`, several joined with `;#`; **Checkbox fields** show `1` (checked) or `0` (unchecked)
- **Opening in Excel**: Double-click the downloaded `.csv` file. If special characters don't display correctly, use Excel's Data → From Text/CSV import with UTF-8 encoding
- **Large exports**: The export works well for typical selections. For very large datasets (1000+ files), consider using the [API](../architecture/api-reference.md) for batch operations

## See Also

- [Bulk Editing](bulk-editing.md) - Edit metadata for multiple files at once
- [Field Types](field-types.md) - Understanding metadata field types
- [API Reference](../architecture/api-reference.md) - Programmatic access to metadata
