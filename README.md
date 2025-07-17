# 📘 MetaVox – Metadata for Nextcloud

**MetaVox** ("Vox” = voice. Metadata as 'the voice of the document’.) is an open-source Nextcloud app developed by the University of Amsterdam and the Amsterdam University of Applied Sciences.  
It adds a semantic metadata layer to documents stored in Nextcloud, making them easier to organize, interpret, and retrieve.

## 🎯 Purpose

The goal of MetaVox is to enrich digital documents with contextual metadata — such as topic, author intent, academic relevance, or curriculum linkage — in a way that is:

- **Structured** and machine-readable  
- **Non-intrusive** (no changes to file content)  
- **Compatible** with existing Nextcloud workflows  
- **Designed for education and research environments**

## 👩‍🏫 Target Users

MetaVox is designed primarily for:

- Universities and educational institutions  
- Researchers and lecturers  
- Students working in document-heavy environments  
- Educational IT admins integrating Nextcloud

## 🌱 Why MetaVox?

While Nextcloud provides basic metadata capabilities (tags, comments, etc.), MetaVox introduces **rich, domain-specific semantic metadata** to support use cases such as:

- Curriculum tagging  
- Academic document classification  
- Research annotation  
- Educational version tracking

# Metavox - Metadata Management Requirements

## Scope
Metavox is a Nextcloud app designed to manage metadata specifically for **Teamfolders**. The goal is to offer controlled, role-based metadata definition and editing, with a clear distinction between **Teamfolder metadata** and **Document-specific metadata**.

---

## Functional Requirements

### 1. Metadata Scope and Visibility
- [ ] Metadata can only be assigned to **Teamfolders** and documents **within Teamfolders**.
- [ ] Metadata must **not** be assignable to personal folders or files.
- [ ] For each document inside a Teamfolder, the following must be shown:
  - [ ] Inherited Teamfolder metadata (read-only).
  - [ ] Document-specific metadata (editable depending on permissions).

### 2. Teamfolder Metadata Management
- [ ] Only **administrators** can define or modify Teamfolder metadata.
- [ ] Metadata types for Teamfolders must be configurable per folder.
- [ ] Each metadata field may have an optional **default value**.
- [ ] Teamfolder metadata definitions are created independently of document metadata definitions.
- [ ] Admins can import Teamfolder metadata definitions via a predefined `.json` format.
- [ ] Admins can export Teamfolder metadata definitions into a predefined `.json` format.

### 3. Document Metadata within Teamfolders
- [ ] Metadata can be assigned to individual documents inside Teamfolders.
- [ ] Only users with **edit permissions** on the document may edit document-specific metadata.
- [ ] Users with **read-only access** may view but **not** modify metadata.
- [ ] Metadata editing rights should **inherit the document’s permissions**.
- [ ] When a metadata field has a default value, this value is pre-filled but can be changed by authorized users.
- [ ] Admins can import Document metadata definitions via a predefined `.json` format.
- [ ] Admins can export Document metadata definitions into a predefined `.json` format.

---

## Permissions & Roles
- [ ] Metadata definitions (Teamfolder and document level) can only be created/modified by users with the **admin role**.
- [ ] Regular users may only **view or edit** document metadata based on their **access level** to the document.

---

## User Interface Requirements
- [ ] Metadata associated with a Teamfolder must be **clearly visible** when browsing the folder.
- [ ] When viewing a document, the interface must distinguish between:
  - [ ] Metadata inherited from the Teamfolder (read-only).
  - [ ] Metadata specific to the document (editable if user has permission).
- [ ] Default values should be prefilled in forms when present.

---

## Technical Requirements
- [ ] JSON schema must be defined for importing metadata definitions, including optional default values.
- [ ] App must ensure metadata integrity and prevent unauthorized changes.
- [ ] Integration with Nextcloud’s permission system is required.

---

## Non-Functional Requirements
- [ ] Compatibility with the latest stable version of Nextcloud.
- [ ] Localization-ready (multi-language support).
- [ ] Performance should not be significantly impacted by large metadata sets.

---

## Future Considerations
- [ ] Versioning of metadata definitions.
- [ ] Audit logs for metadata changes.
- [ ] Metadata must be **searchable** via Nextcloud’s global search.
- [ ] Metadata must be **filterable** in search results (e.g., by metadata field values).

## Visuals
ToDo

## Installation
ToDo

## Usage
ToDo

## Support
ToDo

## Roadmap
ToDo

## Contributing
ToDo

## Authors and acknowledgment
Initial version created by Sam Ditmeijer and Rik Dekker

## 🛡 License

This project is licensed under the [GNU Affero General Public License v3 (AGPLv3)](https://www.gnu.org/licenses/agpl-3.0.html).  
You are free to use, modify, and distribute this software under the terms of the AGPL license.

## Project status
Beta
