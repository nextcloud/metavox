# MetaVox — Claude Code Project Guide

## Project Overview
MetaVox is a Nextcloud app that adds SharePoint-style metadata management to Team Folders (groupfolders). Users can define custom metadata fields, assign them to team folders, and edit values inline in the file list grid view.

### Key Features
- **Grid view columns** — metadata columns injected into NC's file list via DOM manipulation
- **Views** — predefined combinations of columns, filters, sort, conditional formatting
- **Inline editing** — double-click cells to edit, with field-type-specific editors
- **Cell locking** — prevents concurrent edits via Redis + notify_push
- **Real-time sync** — metadata changes pushed to other users via notify_push WebSocket
- **Presence tracking** — only push to users actively viewing a groupfolder
- **Filter & sort** — 100% client-side using metadataCache (zero server calls)
- **NC32 + NC33 support** — feature detection, separate filter UI for NC32
- **Conditional formatting** — rule-based cell background colors per view

### Architecture
- **Frontend**: Vanilla JS (MetaVoxColumns.js orchestrator + 11 modules) + Vue 3 components
- **Backend**: PHP controllers + services following Nextcloud AppFramework
- **Real-time**: notify_push (Rust daemon) + Redis for push events, presence, locking
- **Caching**: Redis write-through cache for metadata, APCu fallback

## Communication Style
- Sam communiceert in het Nederlands
- Code, commits, docs in het Engels
- Kort en direct — geen onnodige uitleg
- Bij twijfel: vraag, niet aannemen
- Sam test graag zelf — deploy naar server en laat hem testen
- Versie bump nodig bij route wijzigingen (NC cached routes)
- Altijd deployen naar de juiste servers na wijzigingen

## Servers

### NC33 (primary test)
- **URL**: https://seedmv1.researchdrivede.src.surf-hosted.nl
- **SSH**: `sditmeijer2@145.38.194.10`
- **App path**: `/var/www/nextcloud/apps/metavox/`
- **Admin**: admin / secureadminpass
- **DB**: MySQL op 145.38.207.51 (ncuser / strongdbpass / nextcloud)
- **Redis**: localhost
- **notify_push**: geïnstalleerd + geconfigureerd

### NC32
- **URL**: https://accworks1.hvanextcloudpoc.src.surf-hosted.nl
- **SSH**: `sditmeijer@145.38.184.76`
- **App path**: `/var/www/nextcloud/apps/metavox/`
- **Admin**: admin / secureadminpass

### NC33 (old, may be decommissioned)
- **SSH**: `sditmeijer2@145.38.205.215`
- **App path**: `/var/www/nextcloud/apps/metavox/`

## Deploy Procedure

### Quick JS deploy (no route changes):
```bash
npm run build
scp js/filesplugin.js sditmeijer2@145.38.194.10:/tmp/
ssh sditmeijer2@145.38.194.10 "sudo cp /tmp/filesplugin.js /var/www/nextcloud/apps/metavox/js/ && sudo chown www-data:www-data /var/www/nextcloud/apps/metavox/js/filesplugin.js"
```

### Full deploy (route changes — needs version bump + disable/enable):
```bash
# Upload files
scp lib/Service/*.php sditmeijer2@145.38.194.10:/tmp/
scp lib/Controller/*.php sditmeijer2@145.38.194.10:/tmp/
scp appinfo/routes.php sditmeijer2@145.38.194.10:/tmp/
scp appinfo/info.xml sditmeijer2@145.38.194.10:/tmp/

# Install + version bump + re-enable
ssh sditmeijer2@145.38.194.10 "
sudo cp /tmp/*.php /var/www/nextcloud/apps/metavox/lib/Service/
sudo cp /tmp/routes.php /var/www/nextcloud/apps/metavox/appinfo/
sudo sed -i 's/1.9\.[0-9]*/1.9.XX/' /var/www/nextcloud/apps/metavox/appinfo/info.xml
sudo chown -R www-data:www-data /var/www/nextcloud/apps/metavox/
sudo -u www-data php /var/www/nextcloud/occ app:disable metavox
sudo -u www-data php /var/www/nextcloud/occ app:enable metavox
"
```

### Gitea
- **Repo**: https://gitea.rikdekker.nl/rik/MetaVox
- **Main branch**: `main`
- **Active branches**: `feature/js-refactor`, `feature/files-app-columns`, `feature/conditional-formatting`

## Code Structure

### Frontend (src/filesplugin/columns/)
| File | Lines | Responsibility |
|------|-------|---------------|
| MetaVoxColumns.js | ~570 | Orchestrator: wiring, observers, init, watcher |
| MetaVoxState.js | ~120 | Centralized state with getters/setters |
| ColumnUtils.js | ~105 | formatValue, parseFieldOptions, column widths |
| ColumnStyles.js | ~280 | CSS injection, NC32/NC33 styles |
| ColumnDOM.js | ~330 | Header/footer/row injection, cell rendering |
| ColumnResize.js | ~70 | Drag-to-resize, width persistence |
| InlineEditor.js | ~530 | Cell editing, fill handle, all field types |
| MetadataLoader.js | ~165 | Batch loading, queue, dirContents polling |
| MetaVoxAPI.js | ~255 | All API calls (fetch, save, detect) |
| Sorting.js | ~140 | Client-side sort, NC33 sort bypass |
| ViewManager.js | ~1000 | Views, tabs, editor, apply/clear |
| MetadataFilter.js | ~500 | NC filter bar integration, client-side filter |
| UndoSupport.js | ~80 | Undo toast and field revert |

### Backend (lib/)
| File | Responsibility |
|------|---------------|
| Service/FieldService.php | Metadata CRUD, field management |
| Service/FilterService.php | Directory metadata queries, sorted file IDs |
| Service/ViewService.php | View CRUD, enrichment, caching |
| Service/MetaVoxCacheService.php | Metadata read/write-through cache |
| Service/PresenceService.php | Presence tracking per groupfolder |
| Service/LockService.php | Cell locking with 30s TTL |
| Service/PushService.php | Push notifications via notify_push |
| Controller/FieldController.php | Metadata endpoints |
| Controller/LockController.php | Cell lock/unlock |
| Controller/PresenceController.php | Presence leave |
| Controller/ViewController.php | Views + init endpoint |
| Controller/FilterController.php | Filter values |

## Key Patterns

### IInitialState
Boot data (groupfolders, fields, views) is inlined in HTML via `Application.php` boot(). Frontend reads via `loadState('metavox', 'init')`. Eliminates API call on first load (~8ms vs ~1500ms).

### Write-through Cache
On metadata save: DB write → Redis cache update → push event. Next read comes from Redis (~0.1ms), not DB (~5-50ms).

### Client-side Filter/Sort
Filter and sort operate entirely on `metadataCache` in the browser. Zero server calls. Sort compares by field type (text/number/date/checkbox).

### Presence Tracking
JSON map per groupfolder in Redis: `{userId: timestamp}`. 30 min TTL. Push events only sent to active viewers. Explicit cleanup on tab close via `sendBeacon`.

### notify_push Integration
Custom events via `notify_custom` Redis channel. Raw WebSocket messages intercepted (NC client strips JSON body). Format: `"event_name {json_body}"`.

## Common Gotchas
- **Route changes need version bump** — NC caches routes. Do `app:disable` + `app:enable`
- **Never run background builds in parallel** — concurrent npm builds corrupt output
- **`_notify_push_ws` can be a boolean** — always check `typeof ws === 'object'`
- **NC32 POST on internal routes returns 405** — use OCS routes for POST endpoints
- **`dirContents` loads async** — poll for 10s after folder open
- **Virtual scroll recycles rows** — observe `data-cy-files-list-row-fileid` attribute changes
