# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

MetaVox is a **Nextcloud app** (app id `metavox`) that adds SharePoint-style metadata columns to Nextcloud **Team / Group Folders**. It is installed into a running Nextcloud server's `apps/` directory — it is not a standalone application. Backend is PHP (Nextcloud App Framework), frontend is Vue 3 built with webpack.

Supports Nextcloud 31–34 (PHP ≥ 8.1) with runtime feature detection — code must not assume APIs that only exist in newer NC versions. See [internal-docs/nc34-compat-plan.md](internal-docs/nc34-compat-plan.md) and recent commits for the NC34 compatibility pattern (e.g. removed `\OC::$server->getRequest()` convenience methods are replaced with `\OC::$server->get(IRequest::class)`).

## Build commands

There is no PHP build step and **no test suite or linter configured**. The "build" is the webpack bundling of the JS frontend.

```bash
npm run build      # production bundle → js/   (run before committing JS changes)
npm run dev        # development bundle
npm run watch      # rebuild on change
```

Webpack entry points ([webpack.config.js](webpack.config.js)) → `js/`:
- `admin` ← [src/admin.js](src/admin.js) — admin settings page
- `user` ← [src/user.js](src/user.js) — personal settings page
- `filesplugin` ← [src/filesplugin/filesplugin-main.js](src/filesplugin/filesplugin-main.js) — the Files-app integration (columns, inline editing, sidebar)
- `metavox-flow` ← [src/flow/main.js](src/flow/main.js) — Flow / Workflow Engine UI

**Built `js/` output is committed to git** (Nextcloud apps ship pre-built). After editing anything under `src/`, run `npm run build` and commit the regenerated `js/` files.

## Architecture

### Frontend → Backend → DB flow

The PHP backend has **no entity/mapper layer** (`lib/Db/` does not exist). Services talk to the database directly via `IDBConnection` query builders — `lib/Service/FieldService.php` is the core example. Don't look for Doctrine-style entities; raw queries against the tables below are the convention.

Bootstrap is [lib/AppInfo/Application.php](lib/AppInfo/Application.php):
- `register()` wires event listeners (file copy, cache cleanup), the search provider, and Flow check registration.
- `boot()` detects whether the current request is the Files app and, if so, **injects initial state** (`provideInitialState('init', ...)`) — accessible groupfolders, the active groupfolder's field definitions, views, permissions, and prefetched directory metadata (≤100 files). This is why the file grid renders instantly without an API round-trip. Falls back to API calls if anything fails.

### Database tables (no migrations create entities — they create these tables)
- `metavox_gf_fields` — field definitions (name, label, type, options, groupfolder, required)
- `metavox_file_gf_meta` — per-document metadata values (fileId, groupfolderId, field, value); heavily indexed for bulk lookup and filtering
- `metavox_gf_column_config` — which fields show as columns per groupfolder, order, filterable flag
- `metavox_gf_views` — saved views per groupfolder (column visibility/order, preset filters, sort — stored as JSON)

Schema changes go in [lib/Migration/](lib/Migration/) as versioned `VersionXXXX` classes (Nextcloud `ISchemaMigration` convention).

### Key backend components ([lib/](lib/))
- `Controller/` — `FieldController` (web UI routes), `Api*Controller` + `BaseOCSController` (external OCS API), plus Lock/Presence/Permission/Backup/AiAutofill/License/Telemetry controllers
- `Service/` — business logic; `FieldService`, `ViewService`, `PermissionService`, `FilterService`, `LockService`/`PresenceService` (real-time collaboration), `MetaVoxCacheService` (distributed cache), `PushService` (notify_push WebSocket sync)
- `Flow/MetadataCheck.php` — `ICheck` implementation letting NC Workflow Engine evaluate metadata conditions
- `Listener/` — file copy (propagate/clean metadata), filecache removal cleanup, Flow check registration
- `Search/MetadataSearchProvider.php` — unified-search integration
- `BackgroundJobs/` — telemetry, license usage, deleted-metadata cleanup, daily metadata backup

### Frontend structure ([src/](src/))
- `filesplugin/columns/` — the heart of the Files-app grid: `MetaVoxColumns.js`, `InlineEditor.js`, `MetadataLoader.js`/`MetadataCache.js`, `ViewManager.js`, `Sorting.js`, `MetadataFilter.js`, DOM/style helpers. Filtering and sorting are **client-side** (no server calls).
- `components/fields/` — one Vue input component per field type (Text, Number, Date, Select, Checkbox, Url, FileLink, UserGroup, TextArea); `DynamicFieldInput.vue` dispatches by type
- `components/` — admin/personal settings UI (MetaVoxAdmin, PermissionsManager, BackupRestore, etc.)
- `flow/` — Flow check UI

### Routes
Two route surfaces in [appinfo/routes.php](appinfo/routes.php): web-interface `routes` (used by the Vue frontend, CSRF-protected) and OCS API endpoints (for external integrations). User-scoped endpoints live under `/api/user/...` and enforce per-document permission inheritance; admin endpoints manage field definitions.

## Git remotes — IMPORTANT

This repo has **two remotes with different contents**:
- `origin` → private Gitea (`gitea.rikdekker.nl`) — full repo including `internal-docs/` and `deploy.sh`
- GitHub (`nextcloud/metavox`) — public, **must not contain** `internal-docs/` or `deploy.sh`

`internal-docs/` and `deploy.sh` are gitignored from GitHub but tracked on Gitea. **Never push directly to GitHub.** Use [push-to-github.sh](push-to-github.sh), which strips files listed in [.gitignore-github](.gitignore-github) on a temp branch before pushing.

## Release process

Releases go to the Nextcloud App Store and require code signing with `metavox.key` (kept in project root, **never committed** — see `.gitignore`). Follow [RELEASE_CHECKLIST.md](RELEASE_CHECKLIST.md) exactly; key points:
- Bump the version in **both** [appinfo/info.xml](appinfo/info.xml) and [package.json](package.json) (keep them in sync).
- Strip all `console.log` from `src/` and `error_log`/`var_dump`/`print_r` from `lib/` before release.
- Run `npm run build` so shipped `js/` matches `src/`.
- Never request a new signing certificate unless the key is lost/compromised — it revokes the old one.

## Localization

UI strings are translated; keep `l10n/` in sync. Translation source is `l10n/en.json`; nl/de/fr/sv are kept at parity (see commit history). Translatable strings use Nextcloud's `t('metavox', ...)` (JS) / `$l->t(...)` (PHP).
