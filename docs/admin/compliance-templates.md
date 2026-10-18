# Compliance Templates for Dutch Government

MetaVox includes predefined metadata templates for Dutch government compliance. These templates help organizations meet their obligations under GDPR (AVG), the Open Government Act (WOO), and the Archives Act (Archiefwet).

## Available Templates

| Template | File | Purpose |
|----------|------|---------|
| GDPR Compliance | `avg-compliance.json` | Personal data classification and processing grounds |
| WOO Compliance | `woo-compliance.json` | Publication status and information categories |
| Archives Act | `archiefwet-compliance.json` | Retention periods and selection list codes |
| Complete | `overheid-compleet.json` | All fields combined |

## Installation

The templates come with the app, in its folder `templates/compliance/`, and are in the [GitHub repository](https://github.com/nextcloud/metavox/tree/main/templates/compliance). Save the one you need on your computer first.

1. Go to **Administration settings** → **MetaVox** and open the **Files** pane. The templates describe documents, so they are file fields.
2. Open the menu next to **New field** and choose **Import from JSON …**.
3. Pick the template file.
4. **Import preview** lists the fields in the file, with their internal name and type. Click **Import fields**.

The import adds the prefix of the pane to each name: `file_gf_` on **Files**, `gf_` on **Team folders**. So `bevat_persoonsgegevens` becomes `file_gf_bevat_persoonsgegevens`. A name that already has the prefix stays as it is. A field whose name already exists is not imported; MetaVox then reports how many fields failed.

Then assign the fields to Team folders, on the **Per folder** pane.

## Template Details

### GDPR Compliance (`avg-compliance.json`)

Fields for classifying documents according to the General Data Protection Regulation (GDPR/AVG):

| Field | Type | Description |
|-------|------|-------------|
| Contains personal data | Checkbox | Indicates whether document contains personal data. Not required: a checkbox always has an answer, ticked or not. The template marks it required; the import ignores that |
| Personal data category | Multi-select | Which categories (name/address, financial, health, etc.) |
| Processing ground | Dropdown | GDPR legal basis (consent, contract, legal obligation, etc.) |
| Retention period (years) | Number | Maximum retention period according to GDPR |
| Data controller | Text | Who is responsible for data processing |

**Use case**: GDPR accountability for data subject requests or Data Protection Authority inquiries.

### WOO Compliance (`woo-compliance.json`)

Fields for classifying documents according to the Open Government Act (Wet Open Overheid):

| Field | Type | Description |
|-------|------|-------------|
| Publication status | Dropdown | Required - public, partially public, not public, to be assessed |
| WOO Category | Dropdown | Information category according to article 3.3 |
| Exception ground | Multi-select | Reason for limited publication (articles 5.1-5.5) |
| WOO request received | Date | Date when WOO request was received |
| Active publication | Checkbox | Subject to active publication obligation |
| Publication date | Date | When document was made public |

**Use case**: Quickly responding to WOO requests, identifying documents for proactive publication.

### Archives Act (`archiefwet-compliance.json`)

Fields for archival and selection list compliance:

| Field | Type | Description |
|-------|------|-------------|
| Archive category | Dropdown | Required - to be destroyed, permanent retention, transfer |
| Destruction year | Number | Year in which document must be destroyed |
| Selection list code | Text | Code from VNG or national selection list |
| Case type | Text | Type of case the document belongs to |
| Dossier status | Dropdown | Open, closed, archived, destroyed |
| Retention period (years) | Number | Retention period according to selection list |
| Archive date | Date | Date of archival |
| Archive location | Text | Physical or digital location |

**Use case**: Retention period management, preparation for transfer to archival repository.

### Complete Template (`overheid-compleet.json`)

Combines the most important fields from all three templates for organizations that need a complete compliance package. Contains 12 fields covering the essential aspects of GDPR, WOO, and Archives Act.

## Nextcloud Flow Integration

Combine MetaVox metadata with Nextcloud Flow for automation:

For detailed Flow rule examples, see [Flow Integration](flow-integration.md).

## Legal Context

### GDPR (AVG)
The General Data Protection Regulation requires organizations to:
- Know which personal data they process
- Have a valid legal basis for processing
- Not retain personal data longer than necessary

### Open Government Act (WOO)
The WOO requires government organizations to:
- Proactively publish certain categories of information
- Be transparent about which information is/isn't public
- Handle information requests promptly

### Archives Act (Archiefwet)
The Archives Act sets requirements for:
- Selection and appraisal of documents
- Retention periods and destruction
- Transfer to archival repository

## Customizing Templates

The templates are a starting point. Customize them for your organization:

1. **Export** the fields with **Export to JSON**, in the same menu as the import. The export leaves out the prefix.
2. **Edit** the JSON file
3. **Import** the customized version with **Import from JSON …**

### JSON Structure

```json
[
  {
    "field_name": "internal_name",
    "field_label": "Display Name",
    "field_type": "select",
    "field_description": "Explanation for users",
    "field_options": [
      {"value": "Option 1"},
      {"value": "Option 2"}
    ],
    "is_required": true
  }
]
```

Leave the prefix off `field_name`: the import adds it. `is_required` has no effect on a `checkbox`, which is never required.

**Available field types**: `text`, `textarea`, `number`, `date`, `select`, `multiselect`, `checkbox`, `url`, `user`, `filelink` (`usergroup` from older files is read as `user`)

**Date fields** can optionally include a time component (`includeTime: true`
in `field_options`) — see [Field Types → Date](../user/field-types.md#date).
Useful for SharePoint migrations where `SPFieldDateTime.DisplayFormat = DateTime`
columns need to round-trip lossless.

## Resources

- [MetaVox GitHub](https://github.com/nextcloud/metavox)
- [VNG Selection List](https://vng.nl/selectielijst) - Official municipal selection list (Dutch)
- [Dutch Data Protection Authority](https://autoriteitpersoonsgegevens.nl) - GDPR information (Dutch)
- [Open Government Act](https://wetten.overheid.nl/BWBR0045754) - Official legal text (Dutch)

## See Also

- [Flow Integration](flow-integration.md) - Automate workflows with metadata
- [Installation](installation.md) - Import templates during setup
- [Permissions](permissions.md) - Access control
- [Privacy & Security](../architecture/privacy.md) - GDPR compliance details
