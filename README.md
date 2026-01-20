# 📘 MetaVox – Metadata for Nextcloud

**MetaVox** ("Vox” = voice. Metadata as 'the voice of the document’.) is an open-source Nextcloud app developed by the University of Amsterdam and the Amsterdam University of Applied Sciences.  
Originally built for education, MetaVox is broadly applicable across government, non-profit, and other professional sectors.

It adds metadata to documents stored in Nextcloud, making them easier to organize, interpret, and retrieve.

<img width="1503" height="832" alt="image" src="https://github.com/nextcloud/metavox/blob/main/screenshots/MetaVox%20v1.0.0.png" />


## 🎯 Purpose

The goal of MetaVox is to enrich digital documents with contextual metadata — such as topic, author intent, relevance, or classification — in a way that is:

- **Structured** and machine-readable  
- **Non-intrusive** (no changes to file content)  
- **Compatible** with existing Nextcloud workflows  
- **Designed for flexible use across education, government, and other document-driven environments**

## 👥 Target Users

MetaVox is suitable for:

- **Universities and educational institutions**  
- **Government organizations**  
- **Knowledge workers in research, legal, and administrative fields**  
- **IT admins and architects deploying metadata-enhanced cloud infrastructure**

## 🌱 Why MetaVox?

While Nextcloud provides basic metadata capabilities (tags, comments, etc.), MetaVox introduces **rich, structured semantic metadata** to support use cases such as:

- Curriculum and course tagging
- Policy and compliance classification
- Research annotation
- Document lifecycle and role-specific categorization

## 🏛️ Government Compliance Templates

MetaVox includes ready-to-use metadata templates for Dutch government compliance requirements. These templates help organizations meet their obligations under:

- **AVG (GDPR)** - Classify documents containing personal data, track processing grounds and retention periods
- **Wet Open Overheid (WOO)** - Manage publication status, information categories, and exception grounds
- **Archiefwet** - Track retention periods, selection list codes, and archival status

### Quick Start

1. Download a template from [`/templates/compliance/`](templates/compliance/)
2. Go to **Settings** > **MetaVox** > **Team folder Metadata**
3. Click **Select JSON File** and import the template
4. Start classifying your documents

### Available Templates

| Template | Description |
|----------|-------------|
| `avg-compliance.json` | Personal data classification, processing grounds, retention |
| `woo-compliance.json` | Publication status, WOO categories, exception grounds |
| `archiefwet-compliance.json` | Archival categories, destruction dates, selection list codes |
| `overheid-compleet.json` | Combined template with all essential fields |

See [`/docs/COMPLIANCE_TEMPLATES.md`](docs/COMPLIANCE_TEMPLATES.md) for detailed documentation and Flow integration examples.

### Why Metadata-First?

Traditional document-centric approaches (Word, PDF) store information in formats designed for printing, not searching. This makes compliance challenging:
- WOO requests require manual document review
- AVG accountability is difficult to demonstrate
- Archival selection is time-consuming

MetaVox separates metadata from content, enabling:
- Machine-readable classification
- Automated workflows via Nextcloud Flow
- Faster compliance reporting  

# MetaVox - Metadata Management Requirements

## Scope
MetaVox is a Nextcloud app designed to manage metadata specifically for **Team folders**. The goal is to offer controlled, role-based metadata definition and editing, with a clear distinction between **Team folder metadata** and **Document-specific metadata**.

---

## Functional Requirements

### 1. Metadata Scope and Visibility
- [ ] Metadata can only be assigned to **Team folders** and documents **within Team folders**.
- [ ] Metadata must **not** be assignable to personal folders or files.
- [ ] For each document inside a Team folder, the following must be shown:
  - [ ] Team folder metadata (read-only).
    - [ ] Not stored with the document
  - [ ] Document-specific metadata (editable depending on permissions).

### 2. Team folder Metadata Management
- [ ] Only **administrators** can define or modify Team folder metadata.
- [ ] Metadata types for Team folders must be configurable per folder.
- [ ] Team folder metadata definitions are created independently of document metadata definitions.
- [ ] Admins can import Team folder metadata definitions via a predefined `.json` format.
- [ ] Admins can export Team folder metadata definitions into a predefined `.json` format.

### 3. Document Metadata within Team folders
- [ ] Metadata can be assigned to individual documents inside Team folders.
- [ ] Only users with **edit permissions** on the document may edit document-specific metadata.
- [ ] Users with **read-only access** may view but **not** modify metadata.
- [ ] Metadata editing rights should **inherit the document’s permissions**.
- [ ] Admins can import Document metadata definitions via a predefined `.json` format.
- [ ] Admins can export Document metadata definitions into a predefined `.json` format.

---

## Permissions & Roles
- [ ] Metadata definitions (Team folder and document level) can only be created/modified by users with the **admin role**.
- [ ] Regular users may only **view or edit** document metadata based on their **access level** to the document.

---

## User Interface Requirements
- [ ] Metadata associated with a Team folder must be **clearly visible** when browsing the folder.
- [ ] When viewing a document, the interface must distinguish between:
  - [ ] Metadata shown from the Team folder (read-only).
  - [ ] Metadata specific to the document (editable if user has permission).

---

## Technical Requirements
- [ ] JSON schema must be defined for importing metadata definitions
- [ ] App must ensure metadata integrity and prevent unauthorized changes.
- [ ] Integration with Nextcloud’s permission system is required.

---

## Non-Functional Requirements
- [ ] Compatibility with the latest stable version of Nextcloud.
- [ ] Localization-ready (multi-language support).
- [ ] Performance should not be significantly impacted by large metadata sets.

---

## Future Considerations
- [ ] Each metadata field may have an optional **default value**.
  - [ ] JSON import support for default values  
- [ ] Versioning of metadata definitions.
- [ ] Audit logs for metadata changes.
- [ ] Metadata must be **searchable** via Nextcloud’s global search.
- [ ] Metadata must be **filterable** in search results (e.g., by metadata field values).

## Visuals
<img alt="image" src="https://github.com/nextcloud/metavox/blob/main/screenshots/Teamfolder%20metadata.png" />
<img alt="image" src="https://github.com/nextcloud/metavox/blob/main/screenshots/File%20metadata.png" />
<img alt="image" src="https://github.com/nextcloud/metavox/blob/main/screenshots/Manage%20team%20metadata.png" />

## Installation
Add the app via the Nextcloud app store under Office & text apps

---

## 🔄 Flow Integration (Access Control)

MetaVox integrates with Nextcloud's **Flow** (Workflow Engine) to enable metadata-based automation and access control.

### Prerequisites
- Install the **Files Access Control** app from the Nextcloud App Store (if you want to restrict file access based on metadata)

### Setting up a Flow Rule

1. Go to **Settings** → **Flow** (Admin settings)
2. Click **Add new flow**
3. Select a trigger (e.g., "File accessed", "File created")
4. Under **Conditions**, click **Add condition**
5. Select **"MetaVox metadata"** from the dropdown
6. Configure your condition:
   - **Field**: Select the metadata field to check
   - **Operator**: Choose from `is`, `is not`, `contains`, `does not contain`, `matches regex`, `does not match regex`
   - **Value**: Enter the value to compare against (input type adapts to field type)
   - **Team folder** (optional): Select a specific team folder, or leave empty for auto-detection

### Example Use Cases

#### Block access to confidential files
1. Create a Flow rule with trigger "File accessed"
2. Add condition: MetaVox metadata → `classification` **is** `confidential`
3. Add action: **Block access**

#### Notify when document is approved
1. Create a Flow rule with trigger "Tag assigned" or use a webhook
2. Add condition: MetaVox metadata → `status` **is** `approved`
3. Add action: **Send notification** to document owner

#### Restrict download based on review status
1. Create a Flow rule with trigger "File accessed"
2. Add condition: MetaVox metadata → `review_status` **is not** `approved`
3. Add action: **Block access**

### Tips
- The groupfolder is automatically detected from the file location in most cases
- Fields are grouped by type: "File fields" (per-document) and "Team folder fields" (inherited from folder)
- Dropdown fields show their configured options; checkbox fields show Yes/No; date fields show a date picker

---

## Roadmap
Retention policies

## Authors and acknowledgment
Initial version created by Sam Ditmeijer and Rik Dekker

## 🛡 License

This project is licensed under the [GNU Affero General Public License v3 (AGPLv3)](https://www.gnu.org/licenses/agpl-3.0.html).  
You are free to use, modify, and distribute this software under the terms of the AGPL license.
