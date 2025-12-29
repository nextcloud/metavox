# MetaVox - Nextcloud App

## Project Overview
MetaVox is een Nextcloud app voor het toevoegen van custom metadata aan bestanden en groupfolders.

## Project Context
- **Framework**: Nextcloud App (PHP backend + Vue.js frontend)
- **Doel**: Custom metadata fields toevoegen aan bestanden binnen Nextcloud groupfolders

## Belangrijke Beslissingen

### Verwijderde Features (december 2024)
1. **Licentie systeem** - Niet meer nodig, volledig verwijderd:
   - LicenseController.php
   - LicenseService.php
   - UpdateLicenseUsage.php (BackgroundJob)
   - LicenseInfo.vue
   - License routes in routes.php
   - LICENSE_INTEGRATION.md
   - License warning/info sectie in ManageGroupfolders.vue

2. **Filter functionaliteit** - Niet meer nodig, volledig verwijderd:
   - FilterController.php
   - FilterService.php
   - FilesFilterPanel.vue
   - files-filter-main.js
   - files-filter.js (built)
   - Filter routes in routes.php
   - FILTER_SERVICE_OPTIMIZATION.md

3. **Global fields** - Alleen groupfolder fields worden gebruikt:
   - Global field routes verwijderd (/api/fields/*)
   - Global file metadata routes verwijderd (/api/files/{fileId}/metadata)
   - FieldController methodes verwijderd: getFields(), getField(), createField(), getFileMetadata(), saveFileMetadata()

4. **Field overrides** - Niet gebruikt in frontend:
   - Field override routes verwijderd (zowel regulier als OCS)
   - FieldController methodes verwijderd: saveFieldOverride(), getFieldOverrides()

5. **Retention Manager** - Niet meer nodig:
   - RetentionManager.vue verwijderd
   - Retention tab verwijderd uit MetaVoxAdmin.vue

## Database Tabellen
### Actief gebruikt:
- `metavox_gf_fields` - Groupfolder field definities
- `metavox_gf_metadata` - Groupfolder metadata waarden
- `metavox_file_gf_meta` - File metadata binnen groupfolders
- `metavox_gf_assigns` - Field toewijzingen aan groupfolders
- `metavox_permissions` - User/group permissions
- `metavox_search_index` - Search index voor Nextcloud unified search

### Niet meer actief (kan verwijderd worden):
- `metavox_fields` - Global field definities (niet meer gebruikt)
- `metavox_metadata` - Global metadata waarden (niet meer gebruikt)
- `metavox_gf_overrides` - Field overrides (niet meer gebruikt)

## Huidige Functionaliteit
- Groupfolder metadata velden beheer
- File metadata velden beheer (binnen groupfolders)
- Groupfolder configuratie
- User permissions beheer
- Nextcloud unified search integratie (MetadataSearchProvider)

## Architectuur
- **Backend**: PHP controllers en services in `/lib/`
- **Frontend**: Vue.js componenten in `/src/components/`
- **Routes**: Gedefinieerd in `/appinfo/routes.php`
- **Database migraties**: `/lib/Migration/`
- **Vertalingen**: `/l10n/` (nl.json, de.json)

## Vertalingen (i18n)
De app ondersteunt meerdere talen via Nextcloud's l10n systeem:
- **Nederlands (nl.json)** - Volledig vertaald
- **Duits (de.json)** - Volledig vertaald
- **Engels** - Standaardtaal (geen apart bestand nodig)

Vertalingen worden automatisch geladen door Nextcloud op basis van de gebruikerstaal.
Nieuwe strings toevoegen: voeg de Engelse tekst toe in de Vue componenten met `t('metavox', 'tekst')` en voeg vertalingen toe aan de JSON bestanden.

## Development Notes
- Build frontend: `npm run build`
- Vue componenten in `/src/components/`
- Admin interface: `MetaVoxAdmin.vue`
