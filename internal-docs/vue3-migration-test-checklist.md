# MetaVox Vue 3 Migratie - Handmatige Test Checklist

**Branch:** `nc33-vue3-migration`
**Versie:** 1.5.0
**Datum:** 2026-02-03

---

## Migratie Overzicht

### Belangrijkste Technische Wijzigingen
| Component | Oud (v1.4.x) | Nieuw (v1.5.0) |
|-----------|--------------|----------------|
| Vue | 2.7.16 | 3.4.0 |
| @nextcloud/vue | 8.22.0 | 9.0.0 |
| @nextcloud/files | 3.12.1 | 4.0.0-rc.0 |
| @nextcloud/dialogs | 3.1.2 | 7.2.0 |
| vue-loader | 15.11.1 | 17.0.0 |
| Nextcloud | 31-32 | 31-33 |

### Code Wijzigingen
- `new Vue()` → `createApp()`
- `Vue.component()` → `app.component()`
- `h` function import voor render functions
- `ref()` voor reactive state
- FileAction API: ondersteunt nu NC33 context object format
- Sidebar Tab: Web Components ondersteuning
- PHP: `fetch()` → `fetchAssociative()`

---

## Pre-Test Setup

- [ ] Branch uitchecken: `git checkout nc33-vue3-migration`
- [ ] Dependencies installeren: `npm install`
- [ ] Builden: `npm run build`
- [ ] App kopiëren naar Nextcloud: `./deploy.sh` of handmatig
- [ ] App inschakelen in Nextcloud Admin → Apps

---

## 1. Build & Console Check

### 1.1 Build Proces
- [ ] `npm install` - geen errors
- [ ] `npm run build` - geen errors
- [ ] Output bestanden aanwezig in `/js/`:
  - [ ] `admin.js`
  - [ ] `user.js`
  - [ ] `filesplugin.js`
  - [ ] `metavox-flow.js`

### 1.2 Browser Console
- [ ] Open Developer Tools (F12)
- [ ] Geen Vue errors bij laden
- [ ] Geen JavaScript errors
- [ ] Geen 404 errors voor JS/CSS bestanden

---

## 2. Admin Panel Tests

**Locatie:** Nextcloud → Admin Settings → MetaVox

### 2.1 Pagina Laden
- [ ] Admin pagina laadt zonder errors
- [ ] Alle tabs zichtbaar: "Team Folders", "Statistics", eventueel "About"
- [ ] Styling correct (geen broken layout)

### 2.2 Team Folders Tab
- [ ] Lijst met groupfolders laadt
- [ ] Groupfolders zijn klikbaar
- [ ] Velden per groupfolder worden getoond

### 2.3 Statistics Tab
- [ ] Tab opent correct
- [ ] Statistieken worden getoond (field counts, entries, etc.)
- [ ] "Send report now" knop werkt (als telemetry aan staat)
- [ ] Opt-out toggle werkt

---

## 3. Metadata Velden Beheer

### 3.1 Veld Aanmaken
Test elk veldtype apart:

| Type | Aanmaken | Opslaan | Weergave |
|------|----------|---------|----------|
| Text | [ ] | [ ] | [ ] |
| Textarea | [ ] | [ ] | [ ] |
| Number | [ ] | [ ] | [ ] |
| Date | [ ] | [ ] | [ ] |
| Checkbox | [ ] | [ ] | [ ] |
| Select (dropdown) | [ ] | [ ] | [ ] |
| Multiselect | [ ] | [ ] | [ ] |
| URL | [ ] | [ ] | [ ] |
| User Picker | [ ] | [ ] | [ ] |
| File Link | [ ] | [ ] | [ ] |

### 3.2 Veld Configuratie
- [ ] Dropdown opties toevoegen werkt
- [ ] Dropdown opties verwijderen werkt
- [ ] Veld naam wijzigen werkt
- [ ] Veld verwijderen werkt (met bevestiging)
- [ ] Veld volgorde wijzigen werkt (drag & drop)
- [ ] Required toggle werkt

### 3.3 Veld Validatie
- [ ] Veld naam mag niet leeg zijn
- [ ] Duplicate veldnamen niet toegestaan
- [ ] Dropdown moet minimaal 1 optie hebben

---

## 4. Files Sidebar Tab

**Locatie:** Files app → Selecteer bestand → Sidebar → MetaVox tab

### 4.1 Tab Weergave
- [ ] MetaVox tab verschijnt in sidebar
- [ ] Tab is klikbaar
- [ ] Correcte icon wordt getoond
- [ ] Geen dubbele tabs (check `window.__metavox_sidebar_registered`)

### 4.2 Metadata Formulier
- [ ] Velden laden voor geselecteerd bestand
- [ ] Velden tonen bestaande waarden
- [ ] Lege velden tonen placeholders

### 4.3 Invoer per Veldtype
| Type | Invoer werkt | Validatie | Opslaan |
|------|--------------|-----------|---------|
| Text | [ ] | [ ] | [ ] |
| Textarea | [ ] | [ ] | [ ] |
| Number | [ ] alleen cijfers | [ ] | [ ] |
| Date | [ ] datepicker | [ ] | [ ] |
| Checkbox | [ ] toggle | [ ] | [ ] |
| Select | [ ] dropdown | [ ] | [ ] |
| Multiselect | [ ] meerdere | [ ] | [ ] |
| URL | [ ] link button | [ ] | [ ] |
| User Picker | [ ] zoeken | [ ] avatar | [ ] |
| File Link | [ ] file picker | [ ] | [ ] |

### 4.4 Opslaan & Feedback
- [ ] Opslaan knop werkt
- [ ] Success feedback zichtbaar
- [ ] Error feedback bij fout
- [ ] Data blijft behouden na pagina refresh

---

## 5. Bulk Metadata Editor

**Locatie:** Files app → Selecteer 2+ bestanden → Actions → "Edit Metadata"

### 5.1 Modal Openen
- [ ] Actie verschijnt alleen bij 2+ bestanden
- [ ] Modal opent correct
- [ ] Velden laden voor geselecteerde bestanden
- [ ] Bestandsnamen zichtbaar

### 5.2 Bulk Bewerken
- [ ] Merge strategie "Overwrite existing values" werkt
- [ ] Merge strategie "Only fill empty fields" werkt
- [ ] Opslaan past alle bestanden aan
- [ ] Progress indicator tijdens opslaan

### 5.3 Extra Functies
- [ ] **CSV Export**: Download werkt, bestand bevat correcte data
- [ ] **Clear All**: Verwijdert metadata van alle geselecteerde bestanden
- [ ] Clear All toont bevestigingsdialoog

---

## 6. Nextcloud Flow Integration

**Locatie:** Admin Settings → Flow → Voeg regel toe

### 6.1 Check Beschikbaar
- [ ] "MetaVox metadata" check verschijnt in dropdown
- [ ] Check is selecteerbaar

### 6.2 Configuratie
- [ ] Groupfolder selector werkt
- [ ] Metadata veld selector laadt velden
- [ ] Operators laden correct per veldtype:

| Veldtype | Verwachte Operators |
|----------|---------------------|
| Text/Textarea | equals, contains, does not contain, is not empty, is empty |
| Date | equals, is before, is after, is not empty, is empty |
| Number | equals, greater than, less than, ≥, ≤, is not empty, is empty |
| Select | equals, is one of, is not empty, is empty |
| Multiselect | contains, contains all, is not empty, is empty |
| Checkbox | equals (Yes/No), is not empty, is empty |

### 6.3 Flow Regel Werking
- [ ] Maak test regel aan (bijv. "If status = approved, then tag file")
- [ ] Upload bestand met juiste metadata
- [ ] Verifieer dat Flow regel activeert

---

## 7. Backwards Compatibility

### 7.1 Nextcloud Versies
Test op elke beschikbare versie:

| NC Versie | App Laadt | Sidebar Tab | Bulk Editor | Flow |
|-----------|-----------|-------------|-------------|------|
| NC 31 | [ ] | [ ] | [ ] | [ ] |
| NC 32 | [ ] | [ ] | [ ] | [ ] |
| NC 33 | [ ] | [ ] | [ ] | [ ] |

### 7.2 FileAction API Compatibility
- [ ] NC32: Legacy API werkt (array van nodes)
- [ ] NC33: Nieuwe API werkt (context object met `{ nodes, view, folder }`)

### 7.3 Sidebar Tab API
- [ ] NC32: Legacy tab registratie werkt
- [ ] NC33: Web Component registratie werkt

---

## 8. API & Data Integriteit

### 8.1 API Endpoints
- [ ] `GET /api/groupfolders` - laadt groupfolders
- [ ] `GET /api/fields/{gfId}` - laadt velden
- [ ] `POST /api/fields` - maakt veld aan
- [ ] `PUT /api/fields/{id}` - update veld
- [ ] `DELETE /api/fields/{id}` - verwijdert veld
- [ ] `GET /api/files/{fileId}/metadata` - laadt file metadata
- [ ] `POST /api/files/{fileId}/metadata` - slaat metadata op
- [ ] `POST /api/files/bulk-metadata` - bulk update
- [ ] `POST /api/files/export-metadata` - CSV export
- [ ] `POST /api/files/clear-metadata` - bulk clear

### 8.2 Data Persistentie
- [ ] Metadata blijft behouden na app disable/enable
- [ ] Metadata blijft behouden na Nextcloud update
- [ ] Metadata wordt verwijderd als bestand verwijderd wordt

---

## 9. Performance & Memory

### 9.1 Laadtijden
- [ ] Admin pagina laadt < 3 seconden
- [ ] Sidebar tab laadt < 1 seconde
- [ ] Bulk editor laadt < 2 seconden

### 9.2 Memory Leaks
- [ ] Open/sluit sidebar tab 10x - geen memory groei
- [ ] Open/sluit bulk modal 10x - geen memory groei
- [ ] Navigeer tussen files 20x - geen memory groei

### 9.3 Network
- [ ] Geen onnodige API calls
- [ ] Request cancellation werkt bij snel navigeren

---

## 10. Edge Cases & Error Handling

### 10.1 Lege States
- [ ] Geen groupfolders - toont melding
- [ ] Geen velden geconfigureerd - toont melding
- [ ] Bestand zonder metadata - toont lege form

### 10.2 Foutafhandeling
- [ ] API timeout - toont error message
- [ ] Network error - toont retry optie
- [ ] Ongeldige input - toont validatie error
- [ ] Geen permissies - toont access denied

### 10.3 Speciale Karakters
- [ ] Veldnamen met speciale tekens: `Bestand (2023)`, `Status: Final`
- [ ] Dropdown opties met komma's en quotes
- [ ] Metadata waarden met HTML karakters: `<script>`, `&amp;`

---

## Test Resultaten

**Tester:** _____________________
**Datum:** _____________________
**Nextcloud versie:** _____________________
**Browser:** _____________________

### Samenvatting
- **Geslaagd:** ___ / 100+
- **Gefaald:** ___
- **Overgeslagen:** ___

### Gevonden Issues
| # | Beschrijving | Ernst | Component |
|---|--------------|-------|-----------|
| 1 | | | |
| 2 | | | |
| 3 | | | |

### Opmerkingen
_Ruimte voor extra notities tijdens het testen..._

---

## Na het Testen

Als alle tests geslaagd zijn:
1. [ ] Merge branch naar main: `git merge nc33-vue3-migration`
2. [ ] Update CHANGELOG.md met Vue 3 migratie notes
3. [ ] Bump versie naar 1.5.0 (al gedaan op branch)
4. [ ] Push naar Gitea: `git push origin main`
5. [ ] Push naar GitHub: `./push-to-github.sh main`
6. [ ] Maak release tarball: `tar -czf metavox-1.5.0.tar.gz ...`
