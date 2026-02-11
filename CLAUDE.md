# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

MetaVox is a Nextcloud app for adding custom metadata fields to files and groupfolders. PHP backend + Vue 3 frontend.

- **Nextcloud compatibility**: 31-33
- **License**: AGPL-3.0-or-later
- **App ID**: `metavox` (namespace `OCA\MetaVox`)

## Build Commands

```bash
npm run build     # Production build
npm run dev       # Development build
npm run watch     # Development build with watch
npm run serve     # Dev server
```

Built JS outputs to `/js/` directory. No test suite or linter is configured.

## Architecture

### Frontend (Vue 3)

The app uses Vue 3 with `@nextcloud/vue` v9 components. No centralized state management — components manage their own state and make API calls directly via `@nextcloud/axios`.

**Four webpack entry points** (`webpack.config.js`):
- `src/admin.js` → `MetaVoxAdmin.vue` — Admin settings with 5 tabs (Team folder Metadata, File Metadata, Manage Team folders, User Permissions, Statistics)
- `src/user.js` → `MetaVoxPersonal.vue` — Personal settings page
- `src/filesplugin/filesplugin-main.js` → `FilesSidebarTab.vue` — Files app sidebar integration
- `src/flow/main.js` → `MetadataCheck.vue` — Nextcloud Workflow Engine integration

**Field input components** in `src/components/fields/`: `DynamicFieldInput.vue` routes to type-specific inputs (Text, Textarea, Number, Date, Select, Checkbox, URL, UserGroup, FileLink).

**NC33 backwards compatibility**: The files plugin uses a dual registration strategy — tries the new `getSidebar()` API from `@nextcloud/files` first (NC33), falls back to legacy `OCA.Files.Sidebar.registerTab` (NC31-32). The NC33 sidebar tab uses a Custom Element (`<metavox-sidebar-tab>`) that wraps the Vue app.

### Backend (PHP)

Standard Nextcloud app pattern: Controllers → Services → Database.

- **Controllers** (`lib/Controller/`): `FieldController` (web API), `ApiFieldController` (OCS API), `PermissionController`, `UserFieldController`, `UserController`, `TelemetryController`
- **Services** (`lib/Service/`): `FieldService`, `ApiFieldService`, `PermissionService`, `UserFieldService`, `SearchIndexService`, `TelemetryService`
- **Event Listeners** (`lib/Listener/`): `FileCopyListener` (NodeCopiedEvent + NodeCreatedEvent), `FileDeleteListener` (NodeDeletedEvent), `RegisterFlowChecksListener`
- **Background Jobs** (`lib/BackgroundJobs/`): `CleanupDeletedMetadata` (TimedJob), `UpdateSearchIndex` (QueuedJob — added per file on metadata save), `TelemetryJob`
- **Flow** (`lib/Flow/MetadataCheck.php`): Workflow Engine check with 17+ operators (is, contains, empty, before, after, greater, oneOf, etc.)
- **Search** (`lib/Search/MetadataSearchProvider.php`): Nextcloud unified search integration
- **Bootstrap** (`lib/AppInfo/Application.php`): Registers listeners, search provider, background jobs; conditionally loads filesplugin JS only on Files app pages

### Dual API Pattern

Both APIs share the same service layer but differ in authentication:
1. **Web API** (`/api/*`) — CSRF-protected, used by Vue frontend
2. **OCS API** (`/ocs/v2.php/apps/metavox/api/v1/*`) — Token-based, for external integrations

Routes defined in `appinfo/routes.php`. OCS routes include batch operations (bulk update/delete/copy).

### Database Tables

- `metavox_gf_fields` — Groupfolder field definitions
- `metavox_gf_metadata` — Groupfolder-level metadata values
- `metavox_file_gf_meta` — File-level metadata within groupfolders
- `metavox_gf_assigns` — Field-to-groupfolder assignments
- `metavox_permissions` — User/group permissions
- `metavox_search_index` — Full-text search index

Migrations in `lib/Migration/` (version-based, latest: `Version20250101000011`).

## Internationalization

Translations in `/l10n/` (nl.json, de.json). Use `t('metavox', 'text')` in Vue components via `@nextcloud/l10n`.

## Removed Features (do not re-add)

- License system (LicenseController, LicenseService)
- Filter functionality (FilterController, FilesFilterPanel)
- Global fields (only groupfolder fields are used)
- Field overrides
- Retention Manager
