# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

MetaVox is a Nextcloud app for adding custom metadata fields to files and groupfolders. It's a PHP backend + Vue.js 2 frontend application.

**Nextcloud version**: 31-32
**License**: AGPL-3.0-or-later

## Build Commands

```bash
# Build frontend for production
npm run build

# Build frontend for development
npm run dev

# Watch mode for development
npm run watch

# Development server
npm run serve
```

The built JavaScript files are output to `/js/` directory.

## Architecture

### Frontend Entry Points (webpack.config.js)
- `src/admin.js` → Admin settings page (MetaVoxAdmin.vue)
- `src/user.js` → Personal settings page (MetaVoxPersonal.vue)
- `src/filesplugin/filesplugin-main.js` → Files app sidebar integration (FilesSidebarTab.vue)

### Backend Structure
- **Controllers**: `/lib/Controller/` - FieldController, ApiFieldController, PermissionController, UserFieldController
- **Services**: `/lib/Service/` - Business logic (FieldService, PermissionService, SearchIndexService)
- **Routes**: `/appinfo/routes.php` - Web routes (`/api/*`) and OCS API routes (`/ocs/v2.php/apps/metavox/api/v1/*`)
- **Migrations**: `/lib/Migration/` - Database schema changes
- **Background Jobs**: `/lib/BackgroundJobs/` - CleanupDeletedMetadata, UpdateSearchIndex
- **Event Listeners**: `/lib/Listener/` - FileCopyListener, FileDeleteListener

### Key Components
- **Field Types**: Text, Textarea, Number, Date, Select, Checkbox, URL, User/Group picker, File Link
- **Field inputs**: `/src/components/fields/` - DynamicFieldInput.vue routes to specific input components

### Database Tables (Active)
- `metavox_gf_fields` - Groupfolder field definitions
- `metavox_gf_metadata` - Groupfolder metadata values
- `metavox_file_gf_meta` - File metadata within groupfolders
- `metavox_gf_assigns` - Field assignments to groupfolders
- `metavox_permissions` - User/group permissions
- `metavox_search_index` - Search index for Nextcloud unified search

### Dual API Pattern
The app exposes two API types:
1. **Web API** (`/api/*`) - CSRF-protected, for frontend Vue components
2. **OCS API** (`/ocs/v2.php/apps/metavox/api/v1/*`) - Token-based, for external integrations

## Internationalization

Translations are in `/l10n/` (nl.json, de.json). Use `t('metavox', 'text')` in Vue components.

## Removed Features (do not re-add)

These features were intentionally removed:
- License system (LicenseController, LicenseService)
- Filter functionality (FilterController, FilesFilterPanel)
- Global fields (only groupfolder fields are used)
- Field overrides
- Retention Manager
