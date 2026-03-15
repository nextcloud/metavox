# MetaVox Architecture Overview

This document provides a technical overview of MetaVox's architecture for architects, developers, and IT decision-makers.

## System Overview

MetaVox is a Nextcloud app that adds structured metadata capabilities to Team folders. It follows Nextcloud's app architecture and integrates with core Nextcloud services.

```
┌─────────────────────────────────────────────────────────────┐
│                      Nextcloud Server                        │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────┐  │
│  │  Files App  │  │ Group       │  │  Workflow Engine    │  │
│  │             │  │ Folders App │  │  (Flow)             │  │
│  └──────┬──────┘  └──────┬──────┘  └──────────┬──────────┘  │
│         │                │                     │             │
│  ┌──────┴────────────────┴─────────────────────┴──────────┐  │
│  │                      MetaVox App                        │  │
│  │  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  │  │
│  │  │ Vue Frontend │  │ PHP Backend  │  │ OCS API      │  │  │
│  │  └──────────────┘  └──────────────┘  └──────────────┘  │  │
│  └─────────────────────────────────────────────────────────┘  │
│                              │                               │
│  ┌───────────────────────────┴───────────────────────────┐  │
│  │                   Nextcloud Database                   │  │
│  │  ┌─────────────────┐  ┌──────────────────────────┐    │  │
│  │  │ metavox_gf_     │  │ metavox_file_gf_meta     │    │  │
│  │  │ fields          │  │ (document metadata)      │    │  │
│  │  └─────────────────┘  └──────────────────────────┘    │  │
│  └───────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
```

## Core Components

### Frontend (Vue.js)

- **Sidebar Panel**: Displays and edits metadata in the file sidebar
- **Admin Settings**: Configuration interface for field definitions
- **Bulk Editor**: Multi-file metadata editing interface
- **Flow Component**: Custom check UI for Workflow Engine

Technology: Vue 3, Nextcloud Vue components library

### Backend (PHP)

| Component | Responsibility |
|-----------|---------------|
| `FieldService` | Core metadata operations (CRUD) |
| `ApiFieldService` | Batch operations for API |
| `ApiFieldController` | OCS API request handling |
| `MetadataCheck` | Flow integration (ICheck implementation) |

### Database Schema

MetaVox uses Nextcloud's database abstraction layer with the following tables:

**`metavox_gf_fields`** - Field definitions
- Field name, label, type, options
- Groupfolder association
- Required flag, description

**`metavox_file_gf_meta`** - Document metadata values
- File ID, Groupfolder ID
- Field values (JSON)

## Integration Points

### Group Folders App

MetaVox requires the Group Folders app and integrates at these points:
- Groupfolder detection from file paths
- Permission inheritance
- Folder-level metadata scoping

### Nextcloud Files

- Sidebar integration via Files app hooks
- File action for bulk editing
- Metadata display in file views

### Workflow Engine (Flow)

MetaVox registers a custom `ICheck` implementation that allows Flow rules to evaluate metadata conditions. See [Integration Guide](integration.md) for details.

### OCS API

RESTful API for external integrations:
- Single file metadata operations
- Batch operations (update, delete, copy)
- Statistics and reporting

See [API Reference](api-reference.md) for endpoints.

## Data Flow

### Viewing Metadata

```
User opens file → Files app sidebar loads
                → MetaVox panel requests metadata
                → FieldService queries database
                → Returns field definitions + values
                → Vue renders metadata form
```

### Editing Metadata

```
User changes field → Vue sends update request
                   → Controller validates permissions
                   → FieldService updates database
                   → Returns success/error
                   → UI updates
```

### Flow Rule Evaluation

```
File event triggers Flow → Flow loads MetadataCheck
                        → MetadataCheck fetches file metadata
                        → Evaluates condition
                        → Returns true/false
                        → Flow executes action (or not)
```

## Security Model

- **Authentication**: Nextcloud session or app password
- **Authorization**: Inherits from Nextcloud file permissions
- **Data validation**: Server-side validation of all inputs
- **SQL injection protection**: Parameterized queries via Nextcloud DB layer

See [Privacy & Security](privacy.md) for details.

## Performance Considerations

- Metadata queries are indexed by file_id and groupfolder_id
- Batch operations use database transactions
- Frontend uses lazy loading for large field sets
- API supports pagination for bulk operations

## Technology Stack

| Layer | Technology |
|-------|------------|
| Frontend | Vue 3, Nextcloud Vue, Webpack |
| Backend | PHP 8.x, Nextcloud App API |
| Database | MySQL/MariaDB/PostgreSQL (via Nextcloud) |
| API | OCS REST API |

## See Also

- [Privacy & Security](privacy.md) - Data protection details
- [API Reference](api-reference.md) - API documentation
- [Integration Guide](integration.md) - External system integration
