# Changelog

All notable changes to this project will be documented in this file.
This format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning follows [Semantic Versioning](https://semver.org/).

---

## [2.0.0] - 2026-03-26

### Added
- Inline grid editing with field-type-specific editors (text, number, date, checkbox, URL, filelink, user)
- Cell locking with real-time sync via notify_push + Redis (prevents concurrent edits)
- Presence tracking — push events only sent to users actively viewing a groupfolder
- Views system — predefined column/filter/sort/formatting combinations with tabs UI
- Client-side filter and sort — zero server calls, operates on metadata cache
- Undo support with toast notification and field revert
- Fill handle for quick cell value propagation
- Lock badge with username — portaled, always visible during editing
- Rich cell rendering for user, URL, and filelink field types
- Backup/restore with progress bar, gzip compression, and keyset pagination
- AI autofill capability for metadata fields
- Bulk metadata modal UI with search
- Flow integration (vanilla JS, Vue 2 compatible)
- Checkbox fields use Yes/No dropdown with fill handle support
- NC32 compatibility with feature detection and fallback UI

### Changed
- Complete JS modular refactor: monolithic MetaVoxColumns split into 14 focused modules
- Centralized state management via MetaVoxState.js
- PHP 8 attributes replace annotation docblocks
- BaseController extraction for shared controller logic
- Services split into dedicated responsibilities (Field, Filter, View, Cache, Presence, Lock, Push)
- Single init endpoint eliminates 4 sequential API calls
- Inline init data via IInitialState for instant pageload (~8ms vs ~1500ms)
- Replace error_log with LoggerInterface (fixes #49)
- Documentation completely restructured (admin, user, architecture, features)

### Fixed
- NC32 sorting — DOM-based row reorder fallback
- NC32 sidebar tab + filter registration crashes
- NC33 sidebar tab and bulk metadata action registration via scoped globals
- Breadcrumb dropdown no longer blocked by view tabs
- Cell lock released when user switches to another program
- Prevent notify_push crash on malformed WebSocket messages
- Prevent flex layout from inflating column widths
- Loading indicator timing and spinner hang prevention
- N+1 queries in permission and groupfolder lookups

### Performance
- 10K+ user scalability — presence cooldown, push batching, LRU cache
- Direct push cache updates eliminate race conditions
- Metadata cache persists across submap navigation
- Instant metadata on pageload with early prefetch on navigation
- Bulk metadata loading with lazy filters and virtual scroll optimization
- Optimized restore with batch inserts + cross-platform support
- Optimized metadata loading for large datasets (43M+ rows)

---

## [1.8.3] - 2026-02-20

### Added
- Automatic daily metadata backup to JSON (background job)
- Admin panel: Backup & Restore tab with manual backup, download, and restore
- Streaming backup/restore for large datasets (millions of entries)
- Circles/Teams support: team folders assigned to circles are now correctly detected

### Fixed
- Fixed search index not updating when saving metadata
- Search index now updates directly on save instead of via background job
- Admin panel now shows all team folders regardless of membership
- Added groupfolder access checks on all metadata endpoints

---

## [1.8.2] - 2026-02-19

### Fixed
- Fixed sidebar tab not appearing on NC32 due to registration timing
- Added missing telemetry/statistics routes

---

## [1.8.1] - 2026-02-18

### Fixed
- NC33 sidebar tab now registers correctly by writing to NC33's scoped globals (`window._nc_files_scope.v4_0`) instead of the bundled `@nextcloud/files` window global
- NC33 bulk metadata action now registers correctly using the same scoped globals approach
- Groupfolder path matching uses exact boundary checks to prevent substring false positives

---

## [1.8.0] - 2026-02-18

### Added
- NC33 + Vue 3 migration with backwards compatibility (NC31-33)
- `CacheCleanupListener` for real-time metadata cleanup on file removal

### Changed
- Migrated frontend from Vue 2 to Vue 3 with `@nextcloud/vue` v9
- Dual sidebar registration strategy (NC33 `getSidebar()` API + legacy fallback)
- `CleanupDeletedMetadata` converted from `QueuedJob` to `TimedJob`

### Fixed
- File copy listener now only copies metadata within the same team folder, preventing orphaned metadata when copying between different team folders

---

## [1.6.2] - 2026-02-14

### Fixed
- Fixed "The requested uri() cannot be processed by the script '/var/www/nextcloud/occ'" error when running occ commands — `boot()` now skips request-dependent logic in CLI mode

---

## [1.6.1] - 2026-02-11

### Fixed
- Bulk metadata action no longer appears when only a single file is selected (requires 2+ files)
- File copy listener now only copies metadata within the same team folder, preventing orphaned metadata when copying files between different team folders

### Changed
- Replaced hardcoded `file_put_contents` debug logging in FileCopyListener with PSR-3 `LoggerInterface`
- Removed dead `copyGlobalFileMetadata` method (global metadata tables were removed in v1.3.0)
- Metadata cleanup now uses `CacheEntryRemovedEvent` for real-time cleanup when files are permanently deleted from filecache
- `CleanupDeletedMetadata` converted from `QueuedJob` to `TimedJob` (runs daily) — catches orphaned metadata for deleted files and files moved out of their team folder
- Removed `FileDeleteListener` (replaced by `CacheCleanupListener` + periodic cleanup job)

---

## [1.6.0] - 2025-12-16

### Added
- **Bulk Metadata Clear**: New "Clear All" button in bulk editor to remove all metadata from selected files
  - Confirmation dialog to prevent accidental data loss
  - Also clears search index entries for the files
- **Metadata Export to CSV**: Export metadata from selected files to CSV format
  - Includes file path, file name, and all metadata field values
  - Automatic download with date-stamped filename
  - Proper CSV escaping for special characters
- New API endpoints:
  - `POST /api/files/clear-metadata` - Clear metadata for multiple files
  - `POST /api/files/export-metadata` - Export metadata for multiple files
- Dutch and German translations for all new bulk editor features

### Changed
- Improved bulk metadata modal layout with action buttons grouped left (destructive/export) and right (cancel/save)

---

## [1.5.0] - 2025-12-16

### Added
- **Bulk Metadata Editor**: Edit metadata for multiple selected files at once from the Files app toolbar
  - Appears as "Edit Metadata" action when files are selected
  - Supports both single and multiple file selection
  - Merge strategies: "Overwrite existing values" or "Only fill empty fields"
  - Reuses existing MetadataForm component for consistent UI
- New API endpoint `/api/files/bulk-metadata` for batch metadata updates
- Buffer polyfill for webpack 5 compatibility with @nextcloud/files

### Fixed
- Fixed "Only fill empty fields" option incorrectly overwriting existing values
- Fixed fields not loading in bulk editor (now uses same endpoint as sidebar)
- Corrected field filtering to use `applies_to_groupfolder` instead of `field_scope`

---

## [1.4.0] - 2025-12-16

### Added
- **New Field Types**: Three new metadata field types for enhanced data capture:
  - **URL Field**: URL input with validation and clickable external link button
  - **User Picker**: Select Nextcloud users with avatar display
  - **File Link**: Browse and link to files/folders within Nextcloud using the native file picker
- New API endpoint `/api/users` for user listing in picker fields

---

## [1.3.0] - 2025-12-12

### Added
- **Unified Search Integration**: Search files by metadata content directly from Nextcloud's search bar
- **File Copy Metadata Preservation**: Metadata is automatically copied when files are duplicated (via `FileCopyListener`)
- **Bulk API Operations**: New API endpoints for batch metadata operations:
  - `getBulkFileMetadata`: Fetch metadata for multiple files in one request
  - `batchUpdateFileMetadata`: Update metadata for multiple files at once
  - `batchDeleteFileMetadata`: Delete metadata from multiple files
  - `batchCopyFileMetadata`: Copy metadata from one file to multiple target files
- **Sidebar Tab in Files App**: MetaVox metadata panel integrated into the Files app sidebar, fully rewritten in Vue.js (replaces vanilla JavaScript implementation)
- Dutch (nl) and German (de) translations for the entire application
- Caching for groupfolder mappings and field labels to improve performance
- PHP 8.x `match` expression for file icon detection with expanded file type support
- API response caching for groupfolders (5-minute TTL) with request cancellation
- Memoization for `getFieldOptions()` to prevent redundant parsing
- File access permission checks on all file metadata API endpoints
- Duplicate sidebar tab registration prevention (window flag guard)

### Changed
- **Major Architecture Refactoring**: Removed global metadata system, now exclusively uses groupfolder-scoped metadata
- Modernized PHP codebase to PHP 8.x standards:
  - Added `declare(strict_types=1)` to all PHP files
  - Implemented constructor property promotion with `readonly` properties
  - Replaced `strpos()` with `str_contains()` for string checks
  - Replaced `error_log()` and `file_put_contents()` with PSR-3 `LoggerInterface`
  - Replaced deprecated `execute()` with `executeQuery()`/`executeStatement()`
  - Added proper return type declarations to all methods
- Refactored event listeners to use dependency injection for `IJobList` and `LoggerInterface`
- Improved code organization with proper use statements and class imports
- **Vue Component Optimizations**:
  - Fixed v-for keys using index anti-pattern in `GroupfolderMetadataFields.vue` and `FileMetadataFields.vue`
  - Added proper `required` attribute binding to `SelectFieldInput.vue` and `CheckboxFieldInput.vue`
  - Removed debug console.log statements from all Vue components

### Removed
- Global metadata tables (`metavox_fields`, `metavox_metadata`)
- Field override system (`metavox_gf_overrides`)
- License/subscription model
- Filter functionality
- Retention manager
- Hardcoded log file paths
- Performance test commands from app registration

### Fixed
- Database error "Table 'nextcloud.oc_metavox_fields' doesn't exist" after migration
- Updated all services to use `metavox_gf_fields` and `metavox_file_gf_meta` tables
- Cleaned up orphaned test data (4776 test groupfolders removed)
- Unified search icon visibility in light theme (changed SVG fill from `currentColor` to `#1a1a1a`)
- Fixed "UpdateSearchIndex called without file_id" warning by correcting background job registration
- Fixed 404 error when editing fields in admin panel (incorrect API URL)
- Fixed dropdown options input only allowing one character at a time (v-for key reactivity issue)

### Security
- Removed hardcoded absolute paths for logging
- Improved input validation in background jobs
- API endpoints now verify user has file access before allowing metadata read/write operations
- Admin-only endpoints (`updateField`, `deleteField`, `createGroupfolderField`, etc.) no longer allow non-admin access

---

## [1.2.0] - 2025-12-10

### Fixed
- Fixed database table prefix retrieval for NC32+ compatibility in MySQL FULLTEXT index migration

---

## [1.1.3] - 2025-10-01

### Added
- Support for NextCloud 32.

### Fixed
- Resolved an issue where values could not be selected in the multi-select component when spaces were present.
- Resolved an issue in the external API that prevented retrieving fields associated with a group folder.

---

## [1.1.2] - 2025-09-21

### Added
- Possibility to connect externally with the API

---

## [1.1.1] - 2025-09-18

### Fixed
- Fixed an issue where it was not possible to add columns.

---

## [1.1.0] - 2025-09-18
### Added
- Native Nextcloud controls for improved integration and consistency with the Nextcloud design system

### Changed
- Refactored UI components to use the Nextcloud design language
- Improved stability and maintainability

### Fixed
- Minor bugs and performance issues
- Not able to edit text in File metadata text field

---

## [1.0.6] - 2025-09-07
### Added
- Initial public release of Metavox for Nextcloud
  
---

## [1.0.5] - 2025-09-04
### Added
- Initial public release of Metavox for Nextcloud
