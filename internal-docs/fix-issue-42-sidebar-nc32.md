# Fix Plan voor Issue #42 - MetaVox sidebar verdwijnt op NC32

**Issue**: https://github.com/nextcloud/metavox/issues/42
**Melder**: claudereal op NC 32.0.6, update naar MetaVox v1.8.1
**Symptomen**: MetaVox tab verdwijnt uit Files sidebar, metadata lijkt weg
**Prioriteit**: Hoog

---

## Analyse

### Worden er echt tabellen verwijderd?

**Ja.** Migratie `Version20250101000011.php` (toegevoegd in v1.3.0) dropt drie tabellen:

| Tabel | Wat er in zat | Risico |
|-------|--------------|--------|
| `metavox_fields` | Globale velddefinities | Data verloren als gebruiker van <v1.3.0 kwam |
| `metavox_metadata` | Globale metadata waarden (file_id + field_id + value) | **Potentieel dataverlies** |
| `metavox_gf_overrides` | Veld-overrides (nooit in frontend gebruikt) | Geen risico |

Sinds v1.3.0 gebruikt de app **uitsluitend** groupfolder-tabellen (`metavox_gf_fields`, `metavox_file_gf_meta`, `metavox_gf_metadata`). De legacy tabellen worden nergens meer aangesproken in services of controllers.

**Cruciale vraag voor de melder**: Als ze al op v1.3.0+ zaten, was hun data al in de nieuwe tabellen en zijn de gedropte tabellen leeg/ongebruikt geweest. Als ze van een versie **voor** v1.3.0 kwamen, is er nooit een data-migratie geweest van `metavox_metadata` -> `metavox_file_gf_meta` en is die data weg.

### Waarom verschijnt de sidebar niet op NC32?

**Hoofdoorzaak**: Timing-probleem in `src/filesplugin/filesplugin-main.js`.

De `waitForFilesApp()` functie (regel 269-280) wacht slechts **100ms** na DOMContentLoaded. Op NC32:

1. `registerNewSidebarTab()` faalt (geen `window._nc_files_scope.v4_0` op NC32) -> returns `false`
2. `registerLegacySidebarTab()` wordt aangeroepen, maar `OCA.Files.Sidebar` is na 100ms nog niet geladen -> returns `false`
3. **Geen retry** -> sidebar tab wordt nooit geregistreerd
4. Gebruiker ziet geen MetaVox tab

De metadata staat waarschijnlijk **gewoon nog in de database** (`metavox_file_gf_meta`), maar is niet zichtbaar omdat de sidebar niet laadt.

---

## Te wijzigen bestanden

### 1. `src/filesplugin/filesplugin-main.js` — Sidebar registratie fix

**Wat**: Vervang de simpele `setTimeout(100ms)` door een robuust poll-mechanisme.

**Huidige code** (regel 269-280):
```javascript
function waitForFilesApp() {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => { waitForFilesApp() })
        return
    }
    setTimeout(() => { registerAllTabs() }, 100)
}
```

**Nieuwe code**:
```javascript
function waitForFilesApp() {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => { waitForFilesApp() })
        return
    }

    // Try immediately for NC33 (scoped globals are available early)
    registerAllTabs()

    // Also poll for legacy API (NC31-32) which loads asynchronously
    let attempts = 0
    const maxAttempts = 50 // 5 seconds max
    const pollInterval = setInterval(() => {
        attempts++
        if (window._metavoxTabRegistered || attempts >= maxAttempts) {
            clearInterval(pollInterval)
            return
        }
        // Retry registration
        window._metavoxTabRegistered = false
        registerAllTabs()
    }, 100)
}
```

Pas ook `registerAllTabs()` aan zodat `window._metavoxTabRegistered` alleen op `true` gezet wordt als registratie **echt succesvol** was:

```javascript
async function registerAllTabs() {
    if (window._metavoxTabRegistered) {
        return
    }

    const newApiSuccess = await registerNewSidebarTab()
    if (newApiSuccess) {
        window._metavoxTabRegistered = true
        return
    }

    const legacySuccess = await registerLegacySidebarTab()
    if (legacySuccess) {
        window._metavoxTabRegistered = true
    }
}
```

### 2. `lib/Migration/Version20250101000011.php` — Veiligere migratie

**Wat**: Verwijder de `dropTable()` calls. Laat de legacy tabellen staan — ze zijn onschadelijk en nemen minimale ruimte in. Dit voorkomt dataverlies bij gebruikers die van een oude versie upgraden.

**Wijziging**: Vervang de `dropTable()` calls door commentaar/info dat de tabellen niet meer actief gebruikt worden maar bewaard blijven als backup.

### 3. Namespace fix (optioneel, lage prioriteit)

**Bestanden**: Alle migraties in `lib/Migration/` met `namespace OCA\metavox\Migration`

**Wat**: Corrigeer naar `namespace OCA\MetaVox\Migration` voor consistentie met de rest van de app (`Application.php` gebruikt `OCA\MetaVox`). Dit kan migratie-detectieproblemen voorkomen.

**Let op**: Deze wijziging kan problemen veroorzaken als Nextcloud de oude namespace al in de `oc_migrations` tabel heeft geregistreerd. Test dit zorgvuldig.

---

## Verificatie

1. `npm run build` om de frontend te bouwen
2. Installeer op NC32 testomgeving
3. Open Files app -> klik op een bestand -> controleer dat MetaVox tab verschijnt
4. Controleer browser console op errors
5. Sla metadata op en herlaad pagina -> controleer dat het bewaard blijft
6. Test ook op NC33 dat het nog werkt
7. Run `occ upgrade` en controleer dat er geen tabellen meer gedropt worden
