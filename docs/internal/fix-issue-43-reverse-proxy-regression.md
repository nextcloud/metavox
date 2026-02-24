# Issue #43: MetaVox options not visible behind reverse proxy (v1.8.3)

**Status**: Diagnosed — Scenario B confirmed. Ready to implement.
**Reporter**: elFerZur (same user as #36)
**Setup**: NC 32.0.5, MetaVox 1.8.3, reverse proxy with subpath (`https://mydomain/nextcloud/`)

## Background

In #36, the user found that `getRequestUri()` didn't work behind a reverse proxy with subpath when pretty URLs were used (no `index.php`). Fixed in v1.4.8 by adding `getPathInfo()` check. That fix is still present in v1.8.3.

## User Diagnostics (2026-02-24)

1. **JS loads correctly** — `filesplugin.js` 200 OK in Network tab
2. **No console errors**
3. **Only 1 MetaVox API call** — `/api/groupfolders` (200 OK). No calls to `/api/groupfolders/{id}/metadata` or file metadata endpoints, meaning the sidebar tab never mounted.
4. **URL**: `https://mydomain/nextcloud/apps/files/folders/2387?dir=/incendios`

**Conclusion**: This is **Scenario B** — the script loads but the sidebar tab never registers.

## Root Cause

Comparing the registration logic between v1.4.7 (worked) and v1.8.3 (broken):

**v1.4.7** — simple, synchronous polling:
```js
// Polls specifically for OCA.Files.Sidebar, then registers directly
function waitForFilesApp() {
    if (window.OCA?.Files?.Sidebar) {
        registerMetadataTab()   // direct, synchronous call
        return
    }
    // Poll every 100ms for max 5 seconds
    const checkInterval = setInterval(() => {
        if (window.OCA?.Files?.Sidebar) {
            clearInterval(checkInterval)
            registerMetadataTab()
        }
    }, 100)
}
```

**v1.8.3** — async, tries NC33 first every iteration:
```js
function waitForFilesApp() {
    registerAllTabs()           // async, not awaited!
    const pollInterval = setInterval(() => {
        registerAllTabs()       // async, not awaited! overlapping calls possible
    }, 100)
}

async function registerAllTabs() {
    await registerNewSidebarTab()   // NC33 check — always runs first, adds async overhead
    await registerLegacySidebarTab() // NC32 fallback
}
```

**Problems with v1.8.3 approach:**
1. `registerAllTabs()` is `async` but called without `await` in the polling interval — causes overlapping concurrent async calls
2. `registerNewSidebarTab()` runs first every iteration, adding unnecessary async overhead on NC32
3. Multiple in-flight `registerAllTabs()` calls can race against each other, potentially causing the `_metavoxTabRegistered` flag to be checked before a previous call sets it

## Fix

### File: `src/filesplugin/filesplugin-main.js` (lines 274-296)

Replace the `waitForFilesApp()` function with a cleaner approach that separates NC33 and NC32 paths:

```js
/**
 * Wait for Files app to be ready
 */
function waitForFilesApp() {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => waitForFilesApp())
        return
    }

    // Try NC33 API first (scoped globals are available early)
    registerNewSidebarTab().then(success => {
        if (success) {
            window._metavoxTabRegistered = true
            return
        }

        // NC33 not available — poll for legacy OCA.Files.Sidebar (NC31-32)
        // Same pattern as v1.4.7 which worked reliably behind reverse proxy
        let attempts = 0
        const maxAttempts = 100  // 10 seconds max (increased from 5s for slow proxy setups)
        const pollInterval = setInterval(() => {
            attempts++
            if (window._metavoxTabRegistered || attempts >= maxAttempts) {
                clearInterval(pollInterval)
                return
            }
            // Check for Sidebar existence first (like v1.4.7), then register
            if (window.OCA?.Files?.Sidebar) {
                clearInterval(pollInterval)
                registerLegacySidebarTab().then(legacySuccess => {
                    if (legacySuccess) {
                        window._metavoxTabRegistered = true
                    }
                })
            }
        }, 100)
    })
}
```

**Key changes:**
1. Try NC33 once, then fall into legacy polling — no overlapping async calls
2. Poll for `OCA.Files.Sidebar` existence first (like v1.4.7 did), then call registration
3. Increase timeout to 10 seconds for slow proxy setups
4. No concurrent `registerAllTabs()` calls — eliminates race condition

### After implementing

1. Run `npm run build`
2. Test on NC32 (verify sidebar tab appears)
3. Test on NC33 (verify NC33 registration still works)
4. Release patch version and ask user to test on their reverse proxy setup

## Three hypotheses — for reference

### Scenario A: JS doesn't load (PHP detection failing) — RULED OUT

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

> Note: still worth applying the `?:` fix as a hardening measure, even though it's not the cause here.

### Scenario B: JS loads but sidebar tab doesn't appear — CONFIRMED

See "Fix" section above.

### Scenario C: Sidebar appears but shows errors/empty (access check regression) — RULED OUT

**Diagnosis**: `filesplugin.js` loads, MetaVox tab visible, but API calls return 403.

**Root cause**: v1.8.3 added `hasAccessToGroupfolder()` on all endpoints.

**Fix in `lib/Service/FieldService.php:549` (`hasAccessToGroupfolder`):**
- Ensure `FolderManager::getFoldersForUser()` is tried first
- If it throws, log the error and fall back gracefully

## References

- Issue #36: https://github.com/nextcloud/metavox/issues/36
- Issue #43: https://github.com/nextcloud/metavox/issues/43
- NC `getPathInfo()` can return false: https://github.com/nextcloud/server/blob/master/lib/private/AppFramework/Http/Request.php
- NC `getPathInfo()` empty with pretty URLs: https://github.com/nextcloud/server/issues/983
