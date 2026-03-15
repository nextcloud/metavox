# Getting Started with MetaVox

MetaVox adds structured metadata to documents in Nextcloud Team folders. This guide helps you get started quickly.

## What is MetaVox?

MetaVox enriches your documents with contextual information like:
- Document status (draft, approved, archived)
- Classification (public, confidential)
- Ownership and responsibility
- Compliance information (retention, GDPR grounds)

This metadata is stored separately from the document content, making it searchable and actionable.

## Quick Start by Role

### Users

1. Navigate to any document in a Team folder
2. Open the sidebar (click the info icon or press `i`)
3. View and edit metadata in the MetaVox section

See [User Overview](user/overview.md) for detailed instructions.

### Administrators

1. Go to **Settings** > **MetaVox**
2. Select a Team folder to configure
3. Define metadata fields (text, dropdown, date, etc.)
4. Optionally import a [compliance template](admin/compliance-templates.md)

See [Installation Guide](admin/installation.md) for setup details.

### Architects

Review the [Architecture Overview](architecture/overview.md) to understand:
- How metadata is stored (local database, no external dependencies)
- Integration points (Nextcloud Flow, OCS API)
- Privacy guarantees (all data stays on-premise)

## Key Concepts

| Concept | Description |
|---------|-------------|
| **Team Folder Metadata** | Fields defined per Team folder, visible on all documents (read-only at document level) |
| **Document Metadata** | Fields specific to individual documents, editable by users with write access |
| **Field Types** | Text, number, date, dropdown, multi-select, checkbox, URL, user picker, file link |

## Next Steps

- [User Guide](user/overview.md) - Working with metadata daily
- [Admin Guide](admin/installation.md) - Configuring MetaVox
- [API Reference](architecture/api-reference.md) - Programmatic access
