# Performance Limits & Capacity Planning

This document outlines the practical performance limits of MetaVox when managing metadata across Nextcloud Team Folders. It covers files per view, column limits, concurrent user capacity, and hardware scaling considerations. Where applicable, limits are compared to SharePoint Online as a reference point.

## Summary

| Dimension | MetaVox Recommended Limit | MetaVox Hard Limit | SharePoint Online |
|-----------|--------------------------|--------------------|--------------------|
| **Files per view** | 5,000 | None (gradual degradation) | 5,000 (blocked above) |
| **Fields per Team Folder** | Unlimited | Unlimited (EAV model) | Limited by 8,000-byte row size |
| **Active filters (API `sorted-file-ids`)** | 10 | ~15 before noticeable slowdown | 12 join operations (blocked above) |
| **Displayed columns** | 10–20 | No hard limit | Limited by row size |
| **Concurrent users (small folder)** | 10–100 (hardware dependent) | N/A | N/A |
| **Concurrent users (large folder)** | 2–20 (hardware dependent) | N/A | N/A |

**Key takeaway:** SharePoint enforces hard thresholds — queries above 5,000 items are refused. MetaVox has no hard limits; performance degrades gradually. The primary bottleneck above 5,000 files is the Nextcloud browser-side file list, not MetaVox itself. Use filtered Views to keep per-view file counts under 5,000 for the best experience.

## SharePoint Online Reference Limits

SharePoint Online enforces the following hard limits ([Microsoft documentation](https://learn.microsoft.com/en-us/office365/servicedescriptions/sharepoint-online-service-description/sharepoint-online-limits)):

| Constraint | Limit | Behavior when exceeded |
|------------|-------|------------------------|
| **List View Threshold** | 5,000 items per view | Query is **blocked** |
| Admin override threshold | 20,000 items | Query is blocked |
| Max items per list/library | 30 million | Cannot add more items |
| Row size limit | 8,000 bytes per item | Cannot add more columns |
| Lookup join threshold | 12 join operations | Query is blocked (8+ lookup columns) |
| Unique permissions per list | 50,000 (recommended: 5,000) | Performance degradation |
| Max file size | 250 GB | Upload rejected |

The List View Threshold is SharePoint's most impactful limit: any view, sort, or filter operation that touches more than 5,000 non-indexed items is **blocked entirely** — not slowed down, but refused by the server.

## MetaVox: Files Per View

MetaVox does not enforce a hard item-per-view threshold. Instead, performance degrades gradually as folder size increases. There are four independent bottleneck tiers:

### Bottleneck 1: Loading Values with the Listing

The metadata values come with the Files app's own folder listing. MetaVox adds
the WebDAV property `nc:metavox` to the Files app's `PROPFIND` (Depth 1), and a
server plugin (`MetadataPropertyPlugin.php`) answers it for team folder
listings: one access check per file in memory, then one database query per
1,000 files for all values. Each value is cut at **512 characters** in the
listing; the cell marks a cut-off value, and the full value loads when the cell
is edited.

What the listing does not deliver (a file the plugin could not vouch for, or a
listing that came before MetaVox was ready) is loaded through the API as a
fallback: `directory-metadata`, at most **200 files per request**, bundled 50 ms
after the last file is queued and at most 200 ms after the first.

| Files in folder | Value queries on the server | Extra API calls at open | Scroll behavior |
|-----------------|-----------------------------|-------------------------|-----------------|
| 200 | 1 | 0 | Instant (cached) |
| 1,000 | 1 | 0 | Instant (cached) |
| 5,000 | 5 | 0 | Instant (cached) |
| 10,000 | 10 | 0 | Instant (cached) |

> **Note:** Values arrive with the listing. Scrolling through the file list does **not** trigger additional requests — all data is served from the in-memory cache. The MutationObserver detects virtual scroll row recycling (via `data-cy-files-list-row-fileid` attribute changes) and fills cells from the cache instantly.

### Bottleneck 2: Nextcloud File List

Nextcloud's Files app loads all files in a folder into a client-side `dirContents` array. This is the primary limiting factor and is **not MetaVox-specific**:

| Files in folder | Nextcloud behavior |
|-----------------|-------------------|
| < 1,000 | Instant load |
| 1,000–5,000 | 1–2 second load |
| 5,000–10,000 | Noticeable delay, UI becomes sluggish |
| 10,000–50,000 | Multi-second load, browser memory pressure |
| 50,000+ | Browser may hang or crash |

### Bottleneck 3: SQL Filtering & Sorting (API)

In the Files app, filtering and sorting on MetaVox columns run in the browser,
over the values the grid already has (`FilterSortCore.js`); a cut-off value is
filtered and sorted as shown. When not all values could be loaded, the grid
pauses sorting and filtering on MetaVox columns and says so.

Server-side filtering and sorting is used by the OCS endpoint `sorted-file-ids`.
It uses an Entity-Attribute-Value (EAV) model with self-JOINs (`FilterService.php`). With proper database indexes (added in migration `Version20250101000010`), query performance scales logarithmically:

| Metadata records | 1 filter | 3 filters | 5 filters |
|-----------------|----------|-----------|-----------|
| 10,000 | < 10ms | < 20ms | < 50ms |
| 100,000 | < 50ms | < 100ms | < 200ms |
| 1,000,000 | ~200ms | ~400ms | ~800ms |
| 10,000,000 | ~1s | ~2s | ~4s |

> **Important:** These numbers assume the database indexes from `Version20250101000010` are active. Without indexes, performance drops by 40–100x.

### Bottleneck 4: DOM Rendering

Nextcloud 33+ uses virtual scrolling for the file list. MetaVox uses a MutationObserver that listens for both new rows (`childList`) and row recycling (`attributes` on `data-cy-files-list-row-fileid`). When a row is recycled during scrolling, the metadata cells are instantly updated from the in-memory cache without any API call. Only ~15–50 rows exist in the DOM at any time. **This is not a bottleneck** regardless of folder size.

### Combined Practical Limits

| Files per view | MetaVox status | SharePoint status |
|----------------|---------------|-------------------|
| < 1,000 | Excellent | OK |
| 1,000–5,000 | Good | OK (approaching threshold) |
| 5,000–10,000 | Usable with delays | **Blocked** (above threshold) |
| 10,000–50,000 | Slow, not recommended | Blocked |
| 50,000+ | Unworkable | Blocked |

**Key difference:** SharePoint **blocks** queries above 5,000 items. MetaVox **degrades gradually** — performance gets worse, but operations are never refused.

## Column & Field Limits

### MetaVox Field Architecture

MetaVox uses an EAV (Entity-Attribute-Value) database model. Metadata fields are stored as rows, not as database columns. This means:

- **No hardcoded maximum** on the number of fields per Team Folder
- Adding fields does not alter the database schema
- Each field value is stored as a TEXT type (no fixed byte limit per value); the grid listing carries the first 512 characters

| Constraint | MetaVox | SharePoint |
|------------|---------|------------|
| Max fields/columns per list | **Unlimited** (EAV model) | Limited by 8,000-byte row size |
| Field name length | 255 characters | 255 characters |
| Field value size | TEXT (unbounded); 512 characters in the grid listing | Varies by column type |
| Lookup/join threshold | No hard limit | 12 join operations |
| Field types | 10 (text, number, textarea, date, select, multiselect, checkbox, url, user, filelink) | 30+ |

### Column Display

The frontend renders metadata columns via DOM injection with horizontal scrolling. There is no hardcoded column display limit:

- Minimum column width: 60px
- Column widths are calculated dynamically based on field label length and type
- Horizontal scrolling with sticky headers is supported
- Column widths are persisted per browser (local storage)

### Practical Column Limits

While there is no hard limit, performance considerations apply:

| Active columns | Effect |
|---------------|--------|
| 1–10 | No impact |
| 10–20 | Wider horizontal scroll area, still performant |
| 20–30 | Listing response grows: each non-empty value adds up to 512 characters per file |
| 30+ | Consider using Views to show subsets of columns |

### Filter Performance vs. Column Count (API)

For the `sorted-file-ids` endpoint, each **active filter** adds one SQL JOIN operation to the query. This is the primary scaling factor for column count there (the Files app grid filters in the browser and adds no JOINs):

| Active filters | SQL JOINs | Performance impact |
|---------------|-----------|-------------------|
| 1–5 | 2–6 | Negligible with indexes |
| 5–10 | 6–11 | Minimal, < 200ms at 100K records |
| 10–15 | 11–16 | Noticeable, queries may exceed 500ms |
| 15+ | 16+ | Not recommended, consider restructuring |

> **Comparison:** SharePoint blocks queries when more than 12 join operations are required (triggered by 8+ lookup/person/workflow columns). MetaVox does not block but slows down beyond ~15 active filters.

## Concurrent Users

### Per-User Server Load

MetaVox injects initialization data (groupfolders, and fields and views per team folder) inline into the HTML via Nextcloud's `IInitialState` mechanism. This eliminates separate API calls for the initial page load. Navigating to another team folder uses the same data; a `/api/init` call is only the fallback when it is missing.

Each user opening a folder with metadata columns generates:

| Operation | Extra API calls | Database queries |
|-----------|-----------------|-----------------|
| Folder open (first load) | 0 (inline via IInitialState) | Executed during PHP page render |
| Folder open (navigation) | 0–1 (`/api/init` as fallback) | ~4 when called (groupfolders, fields, views, permissions) |
| Metadata values (all files) | 0 (with the Files app's PROPFIND) | 1 per 1,000 files, inside the PROPFIND |
| Values the listing missed | 0–1 per 200 files | 1 per request |
| Filter values (on dropdown open) | 0 (computed in the browser from loaded values) | 0 |
| **Total per folder open** | **0–1, plus fallbacks** | **~1–5** |

### Data Consistency & Real-Time Sync

When **notify_push** is installed and configured, MetaVox supports real-time collaboration:

- **Cell locking**: When a user starts editing a cell, it is locked for other users (30-second TTL via Redis). Other users see a lock indicator with the editor's name.
- **Real-time push**: Changes are announced via WebSocket (notify_push) to the users who have the team folder open: only file ids, no values. Their browser reloads those files through the API. No polling required.
- **Presence tracking**: Listing a team folder registers the user; Nextcloud's User status heartbeat keeps the registration alive, and switching team folder or closing the tab ends it. Without the User status app, a registration lasts 12 hours.
- **Graceful degradation**: Without notify_push, last write wins; changes by others appear after navigating or refreshing. Cell locks still refuse a second editor.

See [Real-Time Sync](../features/real-time-sync.md) for full details.

### Concurrent User Capacity

Capacity depends heavily on server configuration and folder size:

#### Small folders (< 500 files)

| Server configuration | Concurrent users | Status |
|---------------------|-----------------|--------|
| Single core, 8 GB RAM | 10–15 | Good |
| 4 cores, 16 GB RAM + Redis | 40–60 | Good |
| 4 cores + separate database | 80–100 | Good |

#### Medium folders (500–5,000 files)

| Server configuration | Concurrent users | Status |
|---------------------|-----------------|--------|
| Single core, 8 GB RAM | 3–5 | Slow |
| 4 cores, 16 GB RAM + Redis | 15–25 | Acceptable |
| 4 cores + separate database | 30–50 | Good |

#### Large folders (5,000+ files)

| Server configuration | Concurrent users | Status |
|---------------------|-----------------|--------|
| Single core, 8 GB RAM | 1–2 | Very slow |
| 4 cores, 16 GB RAM + Redis | 5–10 | Slow |
| 4 cores + separate database | 10–20 | Acceptable |

> **Note:** PHP processes requests single-threaded. On a single-core server, requests are queued sequentially. Multiple cores allow PHP-FPM to handle requests in parallel.

## Hardware Scaling Impact

### What helps

| Upgrade | Impact | Why |
|---------|--------|-----|
| **Database on separate server** | High | SQL queries no longer compete with PHP for CPU/RAM |
| **RAM (8 → 16+ GB)** | High | Larger database buffer pool, more PHP workers |
| **SSD/NVMe storage** | High | Faster database I/O, especially for JOINs |
| **Redis/APCu caching** | High | MetaVox caches field definitions and views (600s TTL), API filter values (300s) and API sort/filter results (30s); presence and cell locks need it |
| **More CPU cores** | Medium | Helps with concurrent users (parallel PHP-FPM workers), limited benefit for single-user performance |

### What doesn't help

| Change | Why it has limited effect |
|--------|--------------------------|
| More CPU cores (single user) | PHP is single-threaded per request |
| More network bandwidth | Responses are small: only non-empty values, at most 512 characters each in the listing |
| CDN / edge caching | Metadata is dynamic and user-specific |

### Estimated Practical Limits by Hardware Tier

| Hardware | Files per view | Concurrent users | Active filters |
|----------|---------------|-----------------|----------------|
| **Entry** (1 core, 8 GB, local DB) | ~5,000 | ~10 | ~5 |
| **Standard** (4 cores, 16 GB, Redis) | ~8,000 | ~40 | ~10 |
| **Recommended** (4 cores, 16 GB, separate DB, Redis) | ~10,000–15,000 | ~80 | ~15 |

> **Reminder:** The primary bottleneck above ~5,000 files is Nextcloud's client-side file list, not MetaVox. Hardware upgrades improve MetaVox's backend processing but cannot eliminate the browser-side limitation.

## Best Practices

1. **Use filtered Views** to keep the number of visible files per view under 5,000. The MetaVox backend can store and query millions of metadata records — the limit is in the browser rendering.

2. **Ensure database indexes are active.** The indexes added in migration `Version20250101000010` improve filter query performance by 40–100x. Verify they exist on the `metavox_file_gf_meta` table.

3. **Enable Redis or APCu caching** in your Nextcloud configuration. MetaVox uses distributed caching for field definitions and views (600s TTL) and for API filter results (30–300s); with more than one web server, presence and cell locks need a shared cache such as Redis. Metadata values are always read from the database.

4. **For the `sorted-file-ids` API, limit active filters to 10 or fewer.** Each filter adds a SQL JOIN. Performance remains excellent up to ~10 filters but degrades beyond 15.

5. **Use the Views feature** to show only relevant columns per use case, rather than displaying all fields at once. This improves rendering performance.

6. **For multi-user editing**, install notify_push for real-time sync and cell locking. Without it, last-write-wins applies.

7. **Consider folder structure.** Splitting large collections across multiple folders (each under 5,000 files) is more effective than relying on hardware upgrades alone.
