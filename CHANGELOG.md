# Changelog

All notable changes to this project will be documented in this file.
This format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning follows [Semantic Versioning](https://semver.org/).

---

## [3.0.0] - 2026-10-18

**MetaVox 3.0 is here.** Notably faster at any size, a permission system that follows Nextcloud's own, and a new design with a new icon. Plus saved searches right in Files, highlighted cells and values you can trust.

### Upgrade notes
Read these before you update: 3.0 is stricter than 2.x in a few places.
- **Required fields are enforced.** The edit dialog saves once every required field has a value, and the API refuses to empty one (400). Files that still miss a value are marked in the grid and get one at their next save. A checkbox is never required (see below).
- **The API is strict and says why.** A wrong or unknown parameter is refused with a 400 that names it, instead of being ignored, and changing a field sends only what changes. Check scripts and integrations that write fields or metadata.
- **Field names carry the prefix of their kind.** A file field's name starts with `file_gf_`, a team folder field's with `gf_`, then lowercase letters, numbers and underscores. Other names are refused (400) on the admin page and the API alike; existing fields keep their names.
- **Permissions follow Nextcloud's.** Seeing and changing a file's metadata follows what Nextcloud allows for that file, ACL included. Views and a team folder's own metadata take write access to the team folder; assigning fields and default values also takes the MetaVox manage permission; field definitions are for Nextcloud administrators.
- **Browser routes removed.** For integrations that called them instead of the OCS API: `GET /admin`, `GET /user`, `GET /api/permissions/check`, `GET /api/permissions/me`, `GET` and `POST /api/groupfolders/{groupfolderId}/files/{fileId}/metadata` (use `/api/files/{fileId}/metadata`), `POST /api/groupfolders/{groupfolderId}/filter-values` and `POST /api/user/groupfolders/{groupfolderId}/metadata`. The OCS API (`/ocs/v2.php/apps/metavox/api/v1/…`) keeps every route.

### New: saved searches, right in Files
- **Keep a filter as a folder.** Save any metadata filter from the search bar and find it under Metadata searches in Files, always up to date. *(Nextcloud 33 and newer)*
- **Off until you turn it on.** Switch on "Save a filter as a folder in Files" under Search in the MetaVox admin settings. The search filter itself is on.
- **Search all your team folders at once.** Pick "All my Team folders" in the filter.
- **Yours alone.** Each person keeps their own saved searches.
- **Only what you may see.** A saved search shows the files you can open, ACL included.
- **Lighter than a normal folder.\*** Up to 500 files in about a tenth of a second.

\* *On the same 1.4 million files, opening a folder of 500 found files took 73–125 ms and 37–346 database queries; a normal folder of 1,000 files took 182 ms and 1,060 queries.*

### Faster, at any size
- **Search filters up to 60× faster.\*\*** Filtering by a field value takes about 20 milliseconds, even in a team folder with half a million files.
- **Dramatically reduced impact on your database.\*\*** Up to 3,000× less work per search, so MetaVox stays snappy all the time.
- **Folders open up to 4× faster.\*\*** Request load reduced 6×.
- **No more jumping grid.** The metadata comes with the folder listing, so the columns fill in as the files appear.
- **3× less to download in Files.\*\*\*** The sidebar, the view editor and bulk edit load when you first open them.
- **Filters open up to 25× faster.\*\*\*\*** Even with 10,000 values: the list shows the first 100, and a search finds the rest.
- **Much smoother scrolling.\*\*\*\*** By redrawing each row in a single step and watching only what changes, scrolling through large folders is much smoother.
- **Sorting by date, 40% faster.\*\*\*\***
- **Smart loading.** Using smart loading, we were able to keep MetaVox out of the way: the sidebar loads once, nothing polls in the background, and the login page and public links load nothing of MetaVox at all.

\*\* *Version 2.2.3 against this version, on the same 5,000 team folders, 1.4 million files and 11 million metadata values, MariaDB with a 256 MB cache; warm cache, the median of 5. Under load this version answers over 300 searches a second at about 0.1 s each: enough for 10,000 people who each search once a minute.*

\*\*\* *What a Files page downloads from MetaVox: about 2.7 MB in 2.2.3, under 0.9 MB in this version.*

\*\*\*\* *A folder of 10,000 files, in Chrome. Opening the filter froze the page for 1.8 s, now under 0.1 s; sorting by date for 0.23 s, now 0.14 s; while scrolling, the page was blocked for 26 ms, now 8 ms.*

### Highlight what needs attention
- **Color cells by their value.** Overdue in red, due soon in orange, approved in green: rules per column in a view.
- **Presets for the usual cases.** Overdue, due today, due soon (the next 7 days), missing, checked, not checked, assigned to you.
- **See it while you build it.** A live preview in the view editor.
- **Say why.** A short text on the highlight says what to do, and screen readers read it with the cell.

### Values you can trust
- **Every value is checked, however it arrives.** In the grid, the sidebar, bulk edit and the API alike. Values saved before stay as they are.
- **Required means required.** The edit dialog saves once every required field is filled in, and the grid marks files that still miss one.
- **A checkbox is never required.** A checkbox always has an answer, ticked or not, so "required" would only forbid "No". Checkbox fields no longer offer the Required switch. A checkbox you marked required in an earlier version, such as "Contains personal data" from the compliance templates, saves either way and shows no Required badge, and the API reports it as not required. Nothing to do after updating.
- **Safe links.** Links that run code are refused. In the new General tab you can allow only web addresses, and block or allow domains.
- **You see why.** A refused value says what is wrong, under the field it is about.
- **Option labels everywhere.** A dropdown option shows its label in the grid and the filters too.

### Permissions, the Nextcloud way
- **MetaVox follows Nextcloud's permissions, ACL included.** You see and change the metadata of exactly the files you can see and change.
- **Nothing shows of files you can't see.** Not in search, filter values, file-link names or live updates, and a file link only points to files you may open.
- **Views and a team folder's own metadata** take write access to the team folder. Assigning fields and setting default values also takes the MetaVox manage permission.

### In your language
- **Dutch, French, German and Swedish,** the new screens included.

### Changed
- **A new MetaVox icon.**
- **Editing goes through a dialog.** The sidebar shows a file's metadata; **Edit file metadata** opens it for editing, with the AI suggestions there too.
- **One view picker above the grid,** instead of a row of view tabs.
- **Delete several fields at once** on the admin page: tick them, or **Select all deletable**, then **Delete selected**.
- **After an update, MetaVox asks you to reload the page,** instead of failing quietly.
- **Flow rules on multi-select fields work now.** A rule with "contains" or "contains all" never matched a file with two or more options. Check your rules: they now apply.
- **The Flow editor offers the conditions of the highlight rules,** such as "is checked" and "is not". Existing rules keep working.
- **A field in use can be made required.** Its name and type stay fixed.
- **Metadata in any WebDAV folder listing.** Add `<nc:metavox/>` to a PROPFIND and every file comes back with its metadata, in the same request.
- **Other apps can show MetaVox's metadata panel.** `<metavox-metadata-form>` is the panel from the Files sidebar as a custom element: a host app loads it through `MetadataFormEmbed`, sets a file id, and gets the same fields, edit dialog and permissions as Files. See [Embedding the metadata panel](https://github.com/nextcloud/metavox/blob/main/docs/architecture/integration.md#embedding-the-metadata-panel).
- **MetaVox shows up in Nextcloud's setup checks.**
- **The English texts use American spelling** ("organization", not "organisation"), as Nextcloud's own do.

### Removed
- **The Statistics tab** in the admin settings.

### Fixed
- **On Nextcloud 31, people who are not an administrator saw no team folders.**
- **On Nextcloud 34, removing a folder could delete the metadata of unrelated files.** Nextcloud reported numbers that are not file ids; MetaVox now removes metadata only when the file is really gone.
- **Team folders in the older storage layout were not found.**
- **Long values could not be saved on PostgreSQL.**
- **Dropdown choices in team folder metadata were not saved.**
- **The edit dialog closed when saving failed.**
- **Importing the compliance templates lost their dropdown options.**
- **Starting a second backup marked the running one as failed.**
- **Default values were lost when a team folder's fields were assigned again.**
- **Sorting failed on PostgreSQL.**
- **Live updates could be missed right after the connection came back.**
- **A lock badge stayed on screen after leaving a folder.**
- **The column headers slid under the view tabs when the tabs bar grew.**
- **A restore lost the last row of every table:** the newest field definition, team folder value and file value, while its report counted one short. Backups made by earlier versions now restore completely too.
- **Values written without a team folder no longer end up under team folder 0** ([#98](https://github.com/nextcloud/metavox/issues/98)). The old writer that stored them there is gone.

### Security
- **Changing metadata takes the right to change it, on every route.** Web and OCS endpoints check write access on the file or team folder; before, some answered 200 without changing or being allowed to change anything ([#98](https://github.com/nextcloud/metavox/issues/98)).
- **Metadata follows file access everywhere.** Search, filter values, value suggestions, saved searches and live updates include only files the user can open, ACL included. Live updates no longer carry the values themselves.
- **Locks, AI suggestions and the start data check access too.**
- **File links no longer reveal names.** A file link stores the file's id; each person sees the name of a file they can open, and nothing of one they can't. Names stored by earlier versions are removed on update.
- **What you may not see answers 404,** not 403, so a refusal does not confirm that a file or team folder exists.
- **Links that run code are refused** in URL fields, such as `javascript:`.
- **Dependency updates** with security fixes for `dompurify` and `source-map-js`.

## [2.2.3] - 2026-09-23

### Changed
- **Nextcloud 35 support.** Nextcloud 31–34 remain supported.
- **Deprecated Nextcloud APIs replaced** (`IAppConfig`, `getDatabaseProvider()`, `getLastInsertId()`, `\OCP\Server::get()`). No migration needed.
- **User picker results are sorted by display name.**
- **Settings page shows a single subscription notice**, only above 100 users.
- **Dependency updates**, including security fixes for `postcss`, `nanoid` and `brace-expansion`. Unused script files removed, so the app is about half the size.

### Fixed
- **App icon was invisible in the app list.**

## [2.2.2] - 2026-08-28

### Added

- **Disabled accounts are now reported in telemetry.** Disabling is how Nextcloud offboards someone while keeping their file ownership, so those accounts still count towards the named-user total. Reporting them separately makes the difference visible when usage is compared against a contract — otherwise a customer who has shrunk looks like one who never did. IntraVox and FormVox already sent this; the count is unreported rather than zero when it cannot be taken, so a failure is never mistaken for "nobody disabled". Reporting only, nothing in the app behaves differently.

### Fixed

- **File Link fields silently lost their value on save**
  ([#95](https://github.com/nextcloud/metavox/issues/95)). Picking a file showed
  the filename and the save reported success, but the field was empty again
  after a reload. The team-folder boundary check introduced in 2.2.0 discarded
  any reference whose path it could not resolve to a file id, and since the file
  picker sends a path rather than an id, an unresolved path emptied the whole
  field — including references that had saved correctly before. An unresolvable
  path no longer counts as "outside this Team folder": the reference is kept as
  a path, exactly as it was before 2.2.0. References that genuinely point
  outside the Team folder are still refused, and a path that cannot be resolved
  is now logged as a warning instead of passing unnoticed.
- **Telemetry mis-detected Nextcloud Enterprise.** The subscription check read the *Extended Support* add-on rather than the subscription itself, so instances with a plain Enterprise subscription were reported as Community. It now uses `IRegistry::delegateHasValidSubscription()` (public API since NC 17). This only affects the usage figures reported back to VoxCloud; nothing in the app behaves differently.
- **The instance identifier did not line up with the other VoxCloud apps.** Without `overwrite.cli.url` it was derived from a bare hostname where the other apps use a full URL, so the same server appeared as a different instance per app. Instances affected by this migrate themselves at the next report; nothing needs to be reconfigured.
- **The reported user count left out LDAP and SSO accounts,** because it counted database rows rather than asking Nextcloud. Counting now goes through `callForAllUsers()`, which covers every user backend, and the report says which method was used so the licence server can tell older readings apart. Only affects reporting, not any limit enforced in the app.

## [2.2.1] - 2026-08-20

### Fixed
- **File-metadata OCS API wrote to and read from different Team folders**
  ([#98](https://github.com/nextcloud/metavox/issues/98)). The group-folder-less
  endpoints (`/api/v1/files/{fileId}/metadata` and the bulk read) guessed the
  Team folder from the metadata table instead of from where the file actually
  lives, and writes landed under a non-existent `groupfolder_id=0`. As a result
  a value you saved could come back different (or empty), the bulk read mixed
  rows from several — even deleted — folders with no way to tell them apart, and
  writing to a non-existent folder silently "succeeded". These endpoints now
  resolve the file's real Team folder from your mount (the same source of truth
  the Files UI uses), so reads and writes always agree; the bulk read scopes
  each file to its own folder; and writing to a folder the file isn't in returns
  a clear error (`404` for a file outside any Team folder, `400` for a folder
  mismatch) instead of storing the value where nothing reads it. Batch copy,
  which never actually copied any values, is fixed as part of the same change.
- **Metadata columns were misaligned in the Nextcloud 34 file list.** NC34
  changed the Files-app grid layout; the metadata columns are now pinned
  relative to the actions cell so they line up with the header again.

### Changed
- **Dependency security updates** — bumped the bundled `axios`
  (1.16 → 1.19) and `dompurify` (3.4.11 → 3.4.14) runtime libraries via
  `package.json` overrides to clear known DoS/XSS advisories. No behavioural
  change; `@nextcloud/axios` itself is unchanged.

---

## [2.2.0] - 2026-06-22

### Added
- **Nextcloud 34 support** — `max-version` in `appinfo/info.xml` bumped to 34,
  description updated. Tested against NC 34.0.0 on a dev instance.
- **Per-folder default values for file metadata fields** — set a default value
  per assigned field per Team folder; new files get it automatically (via a
  `NodeCreatedEvent` listener) and existing files are back-filled by a
  background discovery + queued-apply job (idempotent). Type-correct default
  inputs for every field type (text, number, date/datetime, select,
  multiselect, checkbox, url, user, file link). Configurable from **Admin
  settings** and — for non-admins with the per-folder `manage_fields`
  permission — from **Personal settings**.
- **Unified search integration** — a dedicated "MetaVox" search provider
  (`IProvider` + `IFilteringProvider`) plus a "MetaVox · Filter by field"
  action in the search bar. Pick Team folder → field → value (suggested from the
  values that actually occur in that folder), or type `field:value` inline.
  Results are scoped to the folders the user can access, show the matching field
  first in the subline, and respect per-field view permissions. Theme-aware
  app/result icons.
- **File Link fields: multiple files + stable references** — a File Link field
  can now reference several files; each reference is stored by file id so it
  survives renames/moves, with the path kept only for display. Add/remove files,
  duplicate links are rejected, open a linked file directly, and a
  "Referenced by" backlink view shows which items link to the current file.
  Legacy single-path values keep working ([#73](https://github.com/nextcloud/metavox/issues/73)).

### Fixed
- **Nextcloud Enterprise instances were never recognised in telemetry.** The telemetry instance hash was computed differently from the license `instance_url_hash` (different fallback source and/or no URL normalisation), so the license server's enterprise-claim validation could never match the two. The telemetry hash now delegates to `LicenseService::getInstanceUrlHash()`, guaranteeing both are byte-for-byte identical. Existing instances will report under a new (correct) instance hash on their next telemetry run.
- **Multiselect filter options leaked combined values** — the internal filter
  endpoint (`FilterController::getAllFilterValues`, used by the Files-app
  sidebar) called `getAllDistinctFieldValues()` directly for every field
  including multiselect ones. Because multiselect values are stored as
  `;#`-delimited strings, the filter dropdown surfaced combined entries like
  `"Option A;#Option B"` as if they were a single option, instead of
  showing the configured options. Aligned the controller with its OCS
  counterpart (`ApiFilterController::getAllFilterValues`) so that
  `select`/`multiselect`/`dropdown`/`checkbox` fields source their dropdown
  values from the field configuration (`field_options`) and only free-text
  fields fall back to DB DISTINCT.
- **NC34 boot failure** — `\OC::$server->getRequest()` and
  `\OC::$server->getUserSession()` were removed in NC34, causing every request
  to log `Could not boot metavox: Call to undefined method`. Replaced both
  call-sites in `lib/AppInfo/Application.php` with the PSR-11 container
  pattern: `\OC::$server->get(IRequest::class)` and
  `\OC::$server->get(IUserSession::class)`. Backwards-compatible with NC31+.
- **NC34 service-method removals (round 2)** — beyond the boot-fix in
  Application.php, seven additional call-sites were patched that would silently
  fail on NC34 once their code paths run:
  `\OC::$server->getConfig()` (6×), `getAppManager()` (1×),
  `getDatabaseConnection()` (1×). Affected features: backup/restore
  (BackupController), AI metadata-autofill (AiAutofillService), the daily
  backup background job (MetadataBackupJob), and the fresh-install schema
  migration (Version010000Date20241201000000). Fixes use constructor DI where
  possible (`IConfig`, `IAppManager`) and PSR-11 container lookup in the
  migration (no DI available in SimpleMigrationStep). Backwards-compatible
  with NC31+.

## [2.1.0] - 2026-05-13

### Added
- **Optional time component on Date fields** — Date field-type can now capture a time
  alongside the date (hours/minutes/seconds). Toggled per-field via a new
  "Include time component" checkbox in the field-definition dialog. When enabled,
  inputs render as `datetime-local`/`NcDatetimePicker` in datetime mode and values are
  stored as floating ISO 8601 strings (`YYYY-MM-DDTHH:mm:ss`, no `Z`). When disabled,
  behaviour is unchanged (`YYYY-MM-DD`). Sidebar read-views, the files grid (including
  inline double-click editing and the workflow MetadataCheck filter), and AI autofill
  all branch on the flag. Maps 1-to-1 to SharePoint `SPFieldDateTime` —
  `DisplayFormat=DateOnly` ⇄ off, `DisplayFormat=DateTime` ⇄ on — so SharePoint
  migrations no longer lose the time component (resolves #65).

### Fixed
- **`updateField()` destroyed associative `field_options`** — saving an edit on a
  field with a dict-shape `field_options` (introduced for `includeTime`) silently
  flattened it via `implode("\n", …)` and lost the flag. `updateField()` now
  preserves associative arrays as-is and only newline-flattens list-shape options
  (select/multiselect). The pre-existing path in `createGroupfolderField()` was
  already correct.
- **Groupfolders v19+ compatibility — "No fields configured" / missing folder
  names** — `OCA\GroupFolders\Folder\FolderManager::getFoldersForUser()` and
  `::getAllFolders()` shifted return-shape from objects to associative arrays
  somewhere between groupfolders v17 and v19 (and on some NC 31 distributions).
  `UserFieldService` and `FieldService` accessed `->id`, `->mountPoint`,
  `->quota`, `->acl`, `->groups` as object properties, which on the new shape
  yields a PHP warning and `null` — causing the admin panel to render
  "No fields configured" and the sidebar to show "This file is not in a team
  folder". A new `GroupFolderAccessor::get()` helper handles both shapes
  transparently.
- **`admin.js` bundle rebuilt against complete `@nextcloud/axios` runtime** —
  the initial 2.1.0 bundle was compiled against an incomplete
  `node_modules/@nextcloud/axios/dist/` (only `index.d.ts` present, runtime
  files missing). At runtime the admin form's `axios.post()` aborted
  immediately with `AxiosError: Request aborted`, so the "Add Field" save
  button hung on "Saving…" and no field was persisted. Rebuilt against the
  full `@nextcloud/axios` 2.6.0 distribution. Other bundles
  (`filesplugin.js`, `user.js`, `metavox-flow.js`) were already correct.

### Changed (external API contract)
- **`POST/PUT /apps/metavox/api/groupfolder-fields[/{id}]`** — for `field_type=date`,
  `field_options` now accepts an object `{"includeTime": <bool>}`. Other shapes
  (lists, extra keys, non-bool values) return `400 Bad Request` with an
  `error: "Validation failed"` body and a per-field `fields` map. Empty/missing
  `field_options` normalises to `{"includeTime": false}` — matching pre-2.1.0
  date-only behaviour. Truthy strings/ints (`"1"`, `1`, `"true"`) are accepted
  and cast to bool. Other field types are unchanged.
- **All metadata write endpoints** (`/file-metadata/{fileId}`,
  `/groupfolders/{gf}/metadata`, `/groupfolders/{gf}/files/{fileId}/metadata`)
  now validate `date` values before saving:
   - `includeTime=false` → value MUST match `YYYY-MM-DD`
   - `includeTime=true`  → value MUST match `YYYY-MM-DDTHH:mm:ss` (no `Z`, no offset)

  Mismatches return `400 Bad Request` with `{"error": "Validation failed",
  "fields": {"<field_name>": "<reason>"}}`. All-or-nothing: a single invalid
  date in the batch rejects the whole request, no partial save. Empty strings
  and `null` are allowed (clear-the-field). Other field types are passed
  through unchanged.

---

## [2.0.9] - 2026-05-13

### Changed
- **Pricing UI moved to website** — the admin Support tab no longer hardcodes pricing tiers, the "What a subscription includes" checklist, or the standalone contact block. A single "View pricing & plans" button now links to `voxcloud.nl/pricing/#metavox`, where pricing is maintained out-of-band from app releases (no more App Store review cycle for a price change). Aligns with the same move in IntraVox 1.3.1 and FormVox 1.2.1.
- **Telemetry now includes `hasExtendedSupport`** — a single yes/no flag sourced from `OCP\Util::hasExtendedSupport()` (Nextcloud's public API, available since NC 17), with a safe-false fallback so a Community instance is never reported as Enterprise on error. The license key is sent alongside so the license server can cross-check the claim against an active subscription — the boolean alone is unauthenticated and could otherwise be spoofed. Required for the Nextcloud ISV partnership where bundled-license customers need automatic recognition.
- **Anonymous Usage Statistics disclosure is now complete** — the "What we collect" list now mentions total user counts, active users, and the Extended Support flag. The "What we never collect" list now explicitly includes organization name and contact email.

### Removed
- **Organization name + contact email inputs** — removed from the admin Support tab and from the telemetry payload. Existing values stored in `app_config` are left untouched (no migration) but are no longer read or sent. Cleanest privacy story: no PII flows to the license server, period. (Stored values still appear in the legacy `instanceName` field of the license-usage call as an empty string for new installs, which the server accepts.)

---

## [2.0.8] - 2026-05-03

### Fixed
- **Sidebar header not translated** — `{itemType} Metadata` used an untranslated English variable, resulting in "File-metadata" in Swedish. Replaced with separate `File Metadata` / `Folder Metadata` strings so all languages translate the full header. Reported by Magnus (@maghog) on #62. Same anti-pattern fixed in the "No metadata fields configured" empty-state.

### Changed
- **Dutch (nl) translation polish** — 78 strings rewritten to use idiomatic Dutch compound forms (`Bestandsmetadata`, `Mapmetadata`, `Metadatavelden` instead of `Bestand Metadata`, `Map Metadata`, `Metadata Velden`) and sentence case for button labels (`Veld toevoegen` instead of `Veld Toevoegen`). Aligns with the Nextcloud Dutch style guide.

---

## [2.0.7] - 2026-04-17

### Added
- **Swedish (sv) translation** — complete translation of all 432 strings by Magnus (@maghog). (#62)

---

## [2.0.6] - 2026-04-16

### Fixed
- **Telemetry error feedback**: The "Send report now" button now shows the actual server error message (e.g., rate limit, connectivity issue) instead of silently failing
- **View columns not enriched when created without field_id** — `enrichViewColumns()` only resolved columns by `field_id`, so views created via the API with only `field_name` were missing `field_type`, `field_label`, and `field_options`. This caused column headers to be invisible until the view was re-saved in the UI. Now resolves columns by both `field_id` and `field_name`.
- **Sharing files with metadata crashes** — `FieldService::getGroupfolderIdByFileId()` was called by the Flow engine during share events but never implemented, causing an "undefined method" fatal error. Added the missing method. (#58)

### Removed
- Dead code: unreachable `saveFieldOverride()` and `getFieldOverrides()` controller methods referencing the removed `metavox_gf_overrides` table.

---

## [2.0.5] - 2026-04-05

### Added
- **Support tab** in admin panel — subscription info, pricing table, benefits overview, and subscription key management. Friendly, non-aggressive tone: "no limits, no restrictions, no catch."
- **Remove subscription key** button — allows returning to free usage state after subscription expires.
- **Localized pricing URL** — NL users link to Dutch pricing page, others to English.
- **43 new translations** for Support tab content (EN, NL, DE, FR — 429 strings total).

### Changed
- **Banner tone** — all banners are now blue/info, no more red/orange. Subscription nudge triggers at >50 users or >20 team folders.
- **Support tab replaces license section** in Statistics tab. Statistics now only shows stats, AI toggle, and telemetry.
- **Organization fields above subscription key** — fill in org details before activating a key.
- **Licensing docs** rewritten with current pricing (€49/149/349) and friendly tone.

---

## [2.0.4] - 2026-04-03

### Fixed
- **Dark mode icon invisible** — inline SVGs (sidebar tab, filter bar, bulk edit) used hardcoded `stroke="#000"` which is invisible in dark mode. Changed to `currentColor` which inherits the theme text color automatically.
- **NC32 filter button misaligned** — MetaVox filter button was 3px lower than native buttons (Type, Modified, People). Matched padding and margin to NC's native button styling.
- **Folder copy error** — copying a folder with files showed "Move or copy: failed on some elements" despite successful copy. `FileCopyListener::getAllNodesRecursively()` declared `$type` as `int` but received `FileInfo::TYPE_FOLDER` ("dir") which is a string. Changed to `string` type. (#56)

---

## [2.0.3] - 2026-04-02

### Fixed
- **NC31/NC32 sidebar tab not showing** — MetaVox tab was missing from the sidebar on NC31 and NC32 (reported on NC32.0.6). The NC33 registration path was incorrectly triggered on older versions because `window._nc_files_scope` existed as an empty object. Now verifies that the NC33 `filesSidebarTabs` Map is already initialized by Nextcloud before using it, otherwise falls back to the legacy `OCA.Files.Sidebar` API.
- **NC32 sidebar tab order** — added `order: 100` to legacy tab registration so MetaVox appears after Sharing and Versions.
- **NC32 filter button alignment** — added proper height and vertical-align to match native filter buttons.

---

## [2.0.2] - 2026-04-02

### Fixed
- **Statistics showing 0 team folders** — "Team Folders with Metadata" count was always 0 when only folder-level metadata was configured. The query only checked file-level metadata (`metavox_file_gf_meta`), missing folder-level entries (`metavox_gf_metadata`). Now both tables are counted in statistics, telemetry, and license checks. (#55)

---

## [2.0.1] - 2026-04-02

### Fixed
- **NC32 migration crash** — Migration 14 (`metavox_gf_column_config`) used `boolean` type with `notnull` which caused "Bool NotNull cannot store false" error on NC32. Changed to `smallint` type. (#55)

---

## [2.0.0] - 2026-04-02

### Added
- Inline grid editing with field-type-specific editors (text, number, date, checkbox, URL, filelink, user)
- Cell locking with real-time sync via notify_push + Redis (prevents concurrent edits)
- Presence tracking — push events only sent to users actively viewing a groupfolder
- Views system — predefined column/filter/sort/formatting combinations with tabs UI
- "Default" tab with 🏠 icon — shows standard Nextcloud file view without MetaVox filters, visually separated from custom views
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
- **Field name validation** — only lowercase letters (a-z), numbers (0-9) and underscores allowed. Specific error messages on both frontend and backend.
- **License warning banner** — red/orange banner at top of admin panel for invalid licenses or exceeded free tier limits
- **ApiFieldService facade** — all external API metadata operations go through ApiFieldService, shielding FieldService from API concerns
- **French translation** — complete FR support. All 4 languages (EN, NL, DE, FR) in sync at 386 strings.
- **New fox ears + document icon** — filled triangles with document body, light/dark mode support via app.svg (#000) and app-dark.svg (#fff)
- **Field naming convention** documented in API reference with prefix rules and examples

### Changed
- Complete JS modular refactor: monolithic MetaVoxColumns split into 14 focused modules
- Centralized state management via MetaVoxState.js
- PHP 8 attributes replace annotation docblocks
- BaseController and BaseOCSController extraction for shared logic
- Services split into dedicated responsibilities (Field, Filter, View, Cache, Presence, Lock, Push)
- Single init endpoint eliminates 4 sequential API calls
- Inline init data via IInitialState for instant pageload (~8ms vs ~1500ms)
- Replace error_log with LoggerInterface (fixes #49)
- Documentation completely restructured (admin, user, architecture, features)
- Updated app description and screenshots in info.xml

### Fixed
- View switching crash — `fetchServerSortedIds is not a function` replaced with `triggerResort()`
- NC32 sorting — DOM-based row reorder fallback
- NC32 sidebar tab + filter registration crashes
- NC33 sidebar tab and bulk metadata action registration via scoped globals
- Breadcrumb dropdown no longer blocked by view tabs
- Cell lock released when user switches to another program
- Prevent notify_push crash on malformed WebSocket messages
- Prevent flex layout from inflating column widths
- Loading indicator timing and spinner hang prevention
- N+1 queries in permission and groupfolder lookups
- OCS `/api/v1/files/{fileId}/metadata` endpoint now auto-detects groupfolder correctly

### Performance
- 10K+ user scalability — presence cooldown, push batching, LRU cache
- Direct push cache updates eliminate race conditions
- Metadata cache persists across submap navigation
- Instant metadata on pageload with early prefetch on navigation
- Bulk metadata loading with lazy filters and virtual scroll optimization
- Optimized restore with batch inserts + cross-platform support
- Optimized metadata loading for large datasets (43M+ rows)

### Upgrade Notes
- **Free tier introduced:** MetaVox 2.0.0 includes a free tier (5 team folders, 500 metadata entries per folder). If your installation exceeds these limits, an informational banner will appear in the admin panel. **All features continue to work — nothing is blocked or restricted.** For unlimited usage, visit [voxcloud.nl](https://voxcloud.nl).
- **Nextcloud 31+ required:** Minimum version raised from NC28 to NC31.

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

## [1.4.9] - 2026-02-17

### Added
- Statistics tab in admin panel with usage analytics
- Telemetry expansion with detailed field and groupfolder metrics
- Dutch and German translations for statistics and telemetry

---

## [1.4.8] - 2026-02-14

### Fixed
- Fixed Files app detection behind reverse proxy with subpath

---

## [1.4.7] - 2026-01-15

### Added
- Field-type specific Flow operators for NC33 compatibility
- Telemetry service for usage tracking
- Compliance templates for Dutch government (AVG, WOO, Archiefwet)
- Flow screenshots for documentation

### Fixed
- Fixed bulk metadata action registration
- Fixed field_options format in compliance templates

---

## [1.4.6] - 2026-01-10

### Fixed
- Fixed Flow check persistence and field loading

---

## [1.4.5] - 2026-01-08

### Added
- **Nextcloud Flow Integration**: Metadata-based access control via Flow automation
- Bulk edit documentation and screenshots
- Field types documentation with screenshots

---

## [1.4.1] - 2025-12-18

### Changed
- Sidebar UI improvements
- Removed leftover debug files (debug_page.html, run_migration.php)

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
- Support for NextCloud 32

### Changed
- Updated API documentation
- Updated ROADMAP

### Fixed
- Resolved an issue where values could not be selected in the multi-select component when spaces were present
- Resolved an issue in the external API that prevented retrieving fields associated with a group folder

---

## [1.1.2] - 2025-09-21

### Added
- Possibility to connect externally with the API

---

## [1.1.1] - 2025-09-18

### Fixed
- Fixed an issue where it was not possible to add columns

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

---

## [1.0.2] - 2025-09-01
### Fixed
- Renamed logo file for consistency

---

## [1.0.1] - 2025-08-30
### Fixed
- Updated app metadata in info.xml

---

## [1.0.0] - 2025-08-28
### Added
- Initial release of MetaVox
