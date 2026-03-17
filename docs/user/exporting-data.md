# Exporting Data

MetaVox lets you export metadata to CSV for use in spreadsheets, reports, or external systems.

## How to Export

1. Navigate to a Team folder in the Files app
2. Select the files you want to export (checkboxes or Ctrl/Cmd+click)
3. Click **"Edit Metadata"** in the toolbar to open the Bulk Editor
4. Click the **"Export CSV"** button

The export downloads automatically with a date-stamped filename, for example `metadata-export-2026-03-17.csv`.

## Export Format

The CSV file contains one row per selected file with the following columns:

| Column | Description |
|--------|-------------|
| `file_path` | Full path within Nextcloud |
| `file_name` | File name |
| *metadata fields* | One column per configured metadata field |

**Example:**
```csv
file_path,file_name,status,department,review_date
/Documents/report.pdf,report.pdf,Approved,Legal,2026-01-15
/Documents/memo.docx,memo.docx,Draft,HR,
/Documents/policy.pdf,policy.pdf,Archived,Management,2025-06-30
```

## Tips

- **Select specific files** to export only what you need, or select all files in the folder for a complete export
- **Special characters** (commas, quotes, line breaks) are properly escaped in the CSV output
- **Empty fields** appear as empty values in the CSV — they are not omitted
- **Opening in Excel**: Double-click the downloaded `.csv` file. If special characters don't display correctly, use Excel's Data → From Text/CSV import with UTF-8 encoding
- **Large exports**: The export works well for typical selections. For very large datasets (1000+ files), consider using the [API](../architecture/api-reference.md) for batch operations

## See Also

- [Bulk Editing](bulk-editing.md) - Edit metadata for multiple files at once
- [Field Types](field-types.md) - Understanding metadata field types
- [API Reference](../architecture/api-reference.md) - Programmatic access to metadata
