# Plan: Default Values voor MetaVox Kolommen

## Context

SharePoint Online heeft recent een langverwachte feature uitgerold: wanneer je een kolom aanmaakt met een default value, wordt die waarde ook toegepast op bestaande bestanden. MetaVox heeft default values als "Future Consideration" staan maar nog niet geïmplementeerd. Dit plan voegt default values toe aan file fields via **virtual defaults** — geen extra DB-rijen, direct werkend, en een gewijzigde default werkt instant voor alle bestanden.

## Ontwerpbeslissingen

### Virtual Defaults (geen backfill in v1)

Bij het lezen van metadata wordt de default value geïnjecteerd als een bestand geen expliciete waarde heeft. Dit werkt direct voor alle bestaande en toekomstige bestanden, zonder rijen in de DB aan te maken.

**Waarom geen backfill**: Een groupfolder met 50.000 bestanden zou 50.000 INSERT-rijen vereisen per veld. Dit blaast de `metavox_file_gf_meta` tabel op, vertraagt queries structureel, en maakt het wijzigen van defaults complex (oude rijen behouden de oude waarde). Virtual defaults hebben 0 overhead. Backfill kan later als v2 als zoekindex het nodig heeft.

### Alleen file fields (applies_to_groupfolder=0)

Folder fields hebben typisch unieke waarden per groupfolder — een default is daar minder zinvol. De `default_value` kolom komt wel op de gedeelde `metavox_gf_fields` tabel (future-proof), maar de UI toont het alleen voor file fields.

---

## Implementatie

### Stap 1: Database Migration

**Nieuw bestand**: `lib/Migration/Version20250101000019.php`
- Voeg `default_value` kolom toe aan `metavox_gf_fields` (TEXT, nullable, default NULL)
- Volg patroon van Version20250101000018.php (`hasTable` → `hasColumn` → `addColumn`)

### Stap 2: Backend — Field CRUD

**`lib/Service/FieldService.php`**:
1. `createGroupfolderField()` (~regel 294): voeg `default_value` toe aan INSERT values
2. `updateField()`: accepteer en sla `default_value` op in UPDATE
3. `getAllFields()`, `getFieldById()`, `getAssignedFieldsWithDataForGroupfolder()`: voeg `default_value` toe aan resultaat-arrays

**`lib/Controller/FieldController.php`** + **`lib/Controller/ApiFieldController.php`**:
- `createGroupfolderField()`: accepteer `default_value` parameter
- `updateGroupfolderField()`: accepteer `default_value` parameter

### Stap 3: Backend — Virtual Default Injectie

**`lib/Service/FieldService.php`**:
1. Nieuwe helper: `getFieldDefaultsForGroupfolder(int $gfId): array` — retourneert `[field_name => default_value]` voor velden met non-null defaults. Gecached via bestaande field-cache in Redis.
2. `getGroupfolderFileMetadata()` (~regel 769): voeg `f.default_value` toe aan SELECT, en pas toe:
   ```php
   'value' => $row['value'] ?? $row['default_value'] ?? null,
   'is_default' => $row['value'] === null && $row['default_value'] !== null,
   ```

**`lib/Service/FilterService.php`** — `getDirectoryMetadata()` (~regel 35):
- Na het ophalen van metadata (DB + cache), haal field defaults op via `getFieldDefaultsForGroupfolder()`
- Voor elk bestand: vul ontbrekende velden aan met hun default value
- Dit moet **na** de cache-read gebeuren, zodat een gewijzigde default direct effect heeft zonder file-cache invalidation

### Stap 4: Frontend — UI voor Default Values

**`src/components/FileMetadataFields.vue`**:
1. `formData` (~regel 598): voeg `defaultValue: ''` toe
2. **Create form**: na de "Required field" checkbox (~regel 166), voeg een `DynamicFieldInput` component toe voor de default value (hergebruik bestaand component dat al alle field types ondersteunt)
3. `editData` (~regel 611): voeg `defaultValue` toe
4. **Edit modal**: zelfde `DynamicFieldInput` voor default value
5. `addField()` method: stuur `default_value` mee in API call
6. `saveEdit()` method: stuur `default_value` mee
7. `exportFields()` / import: neem `default_value` mee in JSON

**`src/filesplugin/columns/ColumnUtils.js`** — `formatValue()`:
- Geen wijziging nodig — de virtual default komt al als reguliere value uit de API

### Stap 5: Cleanup

- Update README: verplaats default values van "Future Considerations" naar gedocumenteerde features

---

## Edge Cases

| Situatie | Gedrag |
|----------|--------|
| Default wijzigen | Werkt **direct** voor alle bestanden zonder expliciete waarde (virtual default at-read-time) |
| Default verwijderen (→ NULL) | Bestanden zonder expliciete rij tonen weer leeg |
| Required + default | Default voldoet aan de required-eis — goede UX |
| Select/multiselect default | Valideer dat default in de opties zit bij opslaan |
| Zoekindex | Virtual defaults worden NIET geïndexeerd — bekend beperking, documenteren. Backfill als v2 optie |
| Cache | Virtual default wordt at-read-time geïnjecteerd via field-definities (gecached in Redis), niet in file-cache gebakken → geen mass-invalidation nodig |
| Gebruiker bewerkt een cel met default | Expliciete waarde wordt opgeslagen in DB, overschrijft de virtual default permanent |

## Kritieke Bestanden

| Bestand | Wijziging |
|---------|-----------|
| `lib/Migration/Version20250101000019.php` | **Nieuw** — default_value kolom |
| `lib/Service/FieldService.php` | CRUD + virtual default injectie + helper |
| `lib/Service/FilterService.php` | Virtual default in getDirectoryMetadata() |
| `lib/Controller/FieldController.php` | Accepteer default_value param |
| `lib/Controller/ApiFieldController.php` | Accepteer default_value param |
| `src/components/FileMetadataFields.vue` | UI voor default value in create/edit |

## Verificatie

1. **Maak een file field aan met een default value** → controleer dat bestaande bestanden de default tonen in de grid
2. **Upload een nieuw bestand** → controleer dat de default automatisch verschijnt
3. **Bewerk een cel die een default toont** → controleer dat de expliciete waarde wordt opgeslagen en de default overschrijft
4. **Wijzig de default value** → controleer dat bestanden zonder expliciete waarde **direct** de nieuwe default tonen
5. **Verwijder de default value** → controleer dat bestanden weer leeg tonen
6. **Test import/export** → controleer dat default_value meekomt in JSON
7. **Test met select/multiselect** → controleer dat de default uit de opties wordt gekozen

## Toekomstig (v2)

- **Backfill**: Optionele background job die echte rijen schrijft voor zoekindex-integratie
- **Folder fields**: UI voor default values op folder fields als daar behoefte aan is
- **Visuele indicator**: Optioneel andere styling voor cellen die een default tonen vs expliciete waarden
