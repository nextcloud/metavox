# Searching Metadata

MetaVox integrates with Nextcloud's **Unified Search** (the magnifier / `Ctrl/Cmd + K`),
so you can find files by their metadata from anywhere in Nextcloud — not just inside a
Team folder.

There are two ways to search:

1. **Free text** — type any value and MetaVox returns files whose metadata contains it.
2. **Filter by field** — pick a specific field and value through the **MetaVox · Filter by field** action.

![Filter by metadata picker and a MetaVox search result](../../screenshots/search-metavox-fields.png)

---

## Free-text search

Open search (`Ctrl/Cmd + K`), type a term, and look under the **MetaVox** section in the
results. Each result shows the file, with the **matching field** first in its subline
(e.g. searching `Mercedes-Benz` shows `Make: Mercedes-Benz`).

You can also type a precise `field:value` query directly in the search box, for example:

```
archive_category:Permanent retention
```

---

## Filter by field

For a guided, exact filter, use the **MetaVox · Filter by field** action in the search bar.
It opens a picker:

1. **Team folder** — choose the folder to search in.
2. **Field** — choose one of that folder's metadata fields.
3. **Value** — pick from the values that **actually occur** in the folder (so a filter never
   lands on an empty result), or type one for free-text fields.

Confirm with **Apply filter**. A filter chip appears in the search bar and the matching
files are listed under **MetaVox**.

> **File Link** fields are not offered in the picker: their stored value is an internal
> file reference, not a value you would filter on.

---

## What you see and what stays private

- Results are limited to the **Team folders you can access** — you never see files from
  folders you're not a member of.
- The subline respects **per-field view permissions**: a field you're not allowed to see
  is omitted from the result, even if it matched.
- Files with the same name are disambiguated by showing their folder in the result title.

---

## See Also

- [Field Types](field-types.md) - The fields you can search and filter on
- [Views](views.md) - Preset filters and columns inside a Team folder
- [Overview](overview.md) - Getting started with metadata
