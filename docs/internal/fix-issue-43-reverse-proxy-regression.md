# Issue #43: MetaVox options not visible behind reverse proxy (v1.8.3)

**Status**: Waiting for user diagnostics
**Reporter**: elFerZur (same user as #36)
**Setup**: NC 32.0.5, MetaVox 1.8.3, reverse proxy with subpath (`https://mydomain/nextcloud/`)

## Background

In #36, the user found that `getRequestUri()` didn't work behind a reverse proxy with subpath when pretty URLs were used (no `index.php`). Fixed in v1.4.8 by adding `getPathInfo()` check. That fix is still present in v1.8.3.

## Three hypotheses — fix per scenario

### Scenario A: JS doesn't load (PHP detection failing)

**Diagnosis**: `filesplugin.js` NOT in Network tab.

**Root cause**: `getPathInfo()` returns `false` (not `null`) behind proxy. Current code uses `??` which only catches `null`.

**Fix in `lib/AppInfo/Application.php:57`:**
```php
// Change:
$pathInfo = $request->getPathInfo() ?? '';
// To:
$pathInfo = $request->getPathInfo() ?: '';
```

**Additional safety net — add raw `$_SERVER['REQUEST_URI']` check (line 61-67):**
```php
$serverUri = $_SERVER['REQUEST_URI'] ?? '';

$isFilesApp = (
    str_contains($requestUri, '/apps/files') ||
    str_contains($requestUri, '/index.php/apps/files') ||
    str_contains($pathInfo, '/apps/files') ||
    str_contains($serverUri, '/apps/files') ||
    (($_GET['app'] ?? '') === 'files') ||
    (($_POST['app'] ?? '') === 'files')
);
```

### Scenario B: JS loads but sidebar tab doesn't appear (registration timing)

**Diagnosis**: `filesplugin.js` IS in Network tab (200 OK), but no MetaVox tab in sidebar. Possibly console errors about `OCA.Files.Sidebar`.

**Root cause**: v1.8.2 changed registration from `setTimeout(100ms)` to immediate + 5s polling. On NC32 behind a slow proxy, `OCA.Files.Sidebar` may not be ready in time.

**Fix in `src/filesplugin/filesplugin-main.js`:**
- Increase `maxAttempts` from 50 to 100 (10 seconds)
- OR add a `DOMContentLoaded` + `load` event listener as additional trigger
- OR listen for `OCA.Files.Sidebar` becoming available via MutationObserver

### Scenario C: Sidebar appears but shows errors/empty (access check regression)

**Diagnosis**: `filesplugin.js` loads, MetaVox tab visible, but API calls to `/apps/metavox/api/groupfolders` or `/api/groupfolders/{id}/metadata` return 403.

**Root cause**: v1.8.3 added `hasAccessToGroupfolder()` on all endpoints. `FieldService::getGroupfolders()` fallback DB query only checks `group_folders_groups` table (groups), not circles/teams. If user has access via a circle and `FolderManager` throws, they get 403.

**Fix in `lib/Service/FieldService.php:549` (`hasAccessToGroupfolder`):**
- Ensure `FolderManager::getFoldersForUser()` is tried first
- If it throws, log the error and fall back gracefully
- Consider caching the result to avoid repeated DB queries

## References

- Issue #36: https://github.com/nextcloud/metavox/issues/36
- Issue #43: https://github.com/nextcloud/metavox/issues/43
- NC `getPathInfo()` can return false: https://github.com/nextcloud/server/blob/master/lib/private/AppFramework/Http/Request.php
- NC `getPathInfo()` empty with pretty URLs: https://github.com/nextcloud/server/issues/983
