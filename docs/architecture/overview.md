# MetaVox Architecture Overview

This document provides a technical overview of MetaVox's architecture for architects, developers, and IT decision-makers.

## System Overview

MetaVox is a Nextcloud app that adds structured metadata capabilities to Team folders. It follows Nextcloud's app architecture and integrates with core Nextcloud services.

```
┌──────────────────────────────────────────────────────────────┐
│                       Nextcloud Server                       │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────┐  │
│  │  Files App  │  │ Group       │  │  Workflow Engine    │  │
│  │             │  │ Folders App │  │  (Flow)             │  │
│  └──────┬──────┘  └──────┬──────┘  └──────────┬──────────┘  │
│         │                │                     │             │
│  ┌──────┴────────────────┴─────────────────────┴──────────┐  │
│  │                       MetaVox App                       │  │
│  │  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  │  │
│  │  │ Vue Frontend │  │ PHP Backend  │  │ OCS API      │  │  │
│  │  └──────────────┘  └──────────────┘  └──────────────┘  │  │
│  └─────────────────────────────────────────────────────────┘  │
│                              │                                │
│  ┌───────────────────────────┴────────────────────────────┐  │
│  │                   Nextcloud Database                    │  │
│  │  ┌─────────────────┐  ┌──────────────────────────┐     │  │
│  │  │ metavox_gf_     │  │ metavox_file_gf_meta     │     │  │
│  │  │ fields          │  │ (document metadata)      │     │  │
│  │  └─────────────────┘  └──────────────────────────┘     │  │
│  └────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────┘
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
| `FieldService` | Core metadata operations (CRUD), distributed cache |
| `ViewService` | View CRUD with distributed cache |
| `ApiFieldService` | Batch operations for API |
| `ApiFieldController` | OCS API request handling |
| `ViewController` | Views API (list, create, update, delete) |
| `MetadataPropertyPlugin` | WebDAV plugin: serves the `nc:metavox` property in folder listings |
| `AccessMiddleware` | Checks each route's `#[RequireAccess]` rule before the controller runs; a route without one is refused |
| `PushService`, `PresenceService` | Push events to the users who have a team folder open |
| `SearchWordsService` | Word index for free-text search |
| `MetadataCheck` | Flow integration (ICheck implementation) |

### Database Schema

MetaVox uses Nextcloud's database abstraction layer with the following tables:

**`metavox_gf_fields`** - Field definitions
- Field name, label, type, options
- Groupfolder association
- Required flag, description

**`metavox_file_gf_meta`** - Document metadata values
- File ID, Groupfolder ID, field name, field value
- Indexed for fast bulk lookup and filter queries

**`metavox_gf_assigns`** - Which fields each groupfolder uses

**`metavox_gf_metadata`** - Metadata values of the groupfolder itself

**`metavox_gf_views`** - Saved views per groupfolder
- Name, default flag
- Column visibility and order (JSON); this is also where column configuration lives
- Preset filters (JSON) and sort configuration

**`metavox_search_words`** - Word index for free-text search
- One row per word, field, file and groupfolder, looked up by the word's first three letters

**`metavox_permissions`** - Users and groups with the manage permission per groupfolder

## Integration Points

### Group Folders App

MetaVox requires the Group Folders app and integrates at these points:
- Groupfolder detection from file paths
- Permission inheritance
- Folder-level metadata scoping

### Nextcloud Files

- Sidebar integration via Files app hooks
- File action for bulk editing
- Metadata display in file views: the values come with the Files app's own WebDAV folder listing (see [Performance Considerations](#performance-considerations))

### Unified Search

Free-text search looks words up in a word index per team folder
(`metavox_search_words`), so every member finds files by their metadata. A
search uses up to five words, starts from the rarest and reads at most 2,000
rows per word, so its cost does not grow with the size of the team folder. When
every word is that common, the results come from the rarest word's 2,000 newest
files. A background job fills the index for existing values, working at most
20 seconds per cron run. Date, number, checkbox and File Link fields are not in
the word index; find those with a field filter or a `field:value` query.

### Setup Checks

MetaVox adds checks to **Administration settings > Overview**: "MetaVox team
folders" (Team folders app enabled), "MetaVox background jobs" (system cron, and
whether it ran in the last hour), "MetaVox search index" (progress of the word
index), "MetaVox live updates" (notify_push), "MetaVox presence" (User status
app) and "MetaVox shared cache" (distributed cache other than APCu).

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

```
User opens a team folder → Files app sends its PROPFIND (Depth 1)
                         → MetadataPropertyPlugin checks access per file
                           and reads all values in one query per 1,000 files
                         → nc:metavox property per file in the listing
                         → Grid columns fill from the listing
                         → Files the listing did not cover: API fallback
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

- Metadata queries are indexed by `file_id`, `groupfolder_id`, and `(groupfolder_id, field_name, field_value)` for fast filter lookups
- Batch operations use database transactions
- Grid values come with the Files app's WebDAV listing (`PROPFIND`, property `nc:metavox`): no separate request per folder. Each value is cut at 512 characters there; a cut-off value is marked, and the full value loads when the cell is edited
- What the listing does not deliver is loaded through the API as a fallback, in debounced batches of at most 200 files per request
- Stable data (field definitions, views, filter values) is cached in distributed cache (Redis/APCu) with automatic invalidation on write
- HTTP `Cache-Control: private` headers on read-only API endpoints reduce redundant requests
- Current filtering is client-side; server-side filtering is the planned next step for folders with >5,000 files

## Technology Stack

| Layer | Technology |
|-------|------------|
| Frontend | Vue 3, Nextcloud Vue, Webpack |
| Backend | PHP 8.1+, Nextcloud 31–35 App API |
| Database | MySQL/MariaDB/PostgreSQL (via Nextcloud); SQLite for testing only |
| API | OCS REST API, WebDAV property `nc:metavox` |

## See Also

- [Privacy & Security](privacy.md) - Data protection details
- [API Reference](api-reference.md) - API documentation
- [Integration Guide](integration.md) - External system integration
