# MetaVox v2.1.0 — Optional time component on `date` fields (issue #65)

## Context

[Issue #65](https://github.com/nextcloud/metavox/issues/65) (MigrationViking, 2026-05-06) meldt dataverlies bij SharePoint-migraties: het MetaVox `date` veldtype ondersteunt geen tijd, terwijl SharePoint's `SPFieldDateTime` (zoals het systeem-kolom `Created`) altijd een tijdcomponent heeft.

**Hoe SharePoint dit modelleert** ([Microsoft Graph dateTimeColumn](https://learn.microsoft.com/en-us/graph/api/resources/datetimecolumn), [CSOM DisplayFormatType](https://learn.microsoft.com/en-us/dotnet/api/microsoft.sharepoint.client.fielddatetime.displayformat)):
- Eén type `SPFieldDateTime` met een `DisplayFormat` flag: `DateOnly` (0) of `DateTime` (1).
- Onder de motorkap altijd UTC datetime, maar de flag bepaalt UI en round-trip.
- Date-only waardes worden in SharePoint opgeslagen als `T00:00:00Z` — de bron van de beruchte off-by-one-day bug bij DST.

**Beslissingen voor MetaVox** (door gebruiker bevestigd):
1. Eén type `date` blijft, met per-veld optie `includeTime: bool` opgeslagen in de bestaande `field_options` TEXT-kolom als JSON dict.
2. Floating ISO strings — **geen** timezone-conversie:
   - `includeTime=false` → `YYYY-MM-DD` (ongewijzigd)
   - `includeTime=true` → `YYYY-MM-DDTHH:mm:ss` (geen `Z`, geen offset)
3. Scope: basis-eerst (schema + UI + storage + AI). Date-range filtering en SharePoint CSV-importer zijn aparte vervolgissues.
4. Werkt voor **zowel** team folder fields (`metavox_gf_metadata`) als file fields (`metavox_file_gf_meta`); de field-definitie ligt sowieso in `metavox_gf_fields`.
5. Target: **v2.1.0** (minor — gebruikerszichtbare feature).

Doel: SharePoint's `DateOnly` mapt 1-op-1 op een MetaVox date-veld met `includeTime=false`, `DateTime` op `includeTime=true`. Bestaande `date` velden (`field_options = NULL`) renderen ongewijzigd als date-only.

## Privacy/data-veiligheid

Geen impact. Geen nieuwe externe calls, geen nieuwe persistente data, geen schemamigratie. Bestaande waardes worden niet herschreven wanneer een admin `includeTime` flipt.

## Pre-existing bug (must-fix, anders breekt het feature stil)

[lib/Service/FieldService.php:210-226](MetaVox/lib/Service/FieldService.php#L210-L226) — `updateField()` vernietigt associatieve `field_options`:

```php
// Huidige (kapotte) code:
if (is_array($fieldData['field_options'])) {
    $fieldOptions = implode("\n", $fieldData['field_options']);  // ['includeTime' => true] → "1"
}
->set('field_options', $qb->createNamedParameter(
    json_encode(array_filter(explode("\n", $fieldOptions)))      // → ["1"] of []
))
```

Bij elke save vanuit de edit-dialog gaat de `includeTime` flag verloren. `createGroupfolderField()` op regel 299 doet het wél correct (`json_encode($fieldData['field_options'] ?? [])`), dus de bug treft alleen `updateField`. Fix is onderdeel van dit plan.

## Files to change

### 1. Backend: fix update-path en documenteer parse-contract

**[lib/Service/FieldService.php:210-226](MetaVox/lib/Service/FieldService.php#L210-L226)** — vervang `$fieldOptions` preprocessing zodat associatieve arrays intact blijven:

```php
// Process field_options — preserve associative-array shape (date includeTime),
// flatten list-shape arrays/strings (select/multiselect options) the legacy way.
$fieldOptionsRaw = $fieldData['field_options'] ?? '';
if (is_array($fieldOptionsRaw)
    && $fieldOptionsRaw !== []
    && array_keys($fieldOptionsRaw) !== range(0, count($fieldOptionsRaw) - 1)) {
    // Associative array — JSON-encode as-is (e.g. {"includeTime": true})
    $fieldOptionsJson = json_encode($fieldOptionsRaw);
} else {
    // List or string — legacy newline-flatten
    $flat = is_array($fieldOptionsRaw) ? implode("\n", $fieldOptionsRaw) : (string)$fieldOptionsRaw;
    $fieldOptionsJson = json_encode(array_values(array_filter(explode("\n", $flat), fn($v) => $v !== '')));
}
// …
->set('field_options', $qb->createNamedParameter($fieldOptionsJson))
```

**[lib/Service/FieldService.php:50-62](MetaVox/lib/Service/FieldService.php#L50-L62)** — `parseFieldOptions()` werkt al correct: `json_decode` levert dict of list verbatim terug. Voeg alleen een docblock toe die het contract documenteert:

```php
/**
 * Parse a field_options TEXT blob.
 *
 * Returns:
 *  - Associative array for date fields, e.g. `['includeTime' => true]`
 *  - List of strings for select/multiselect fields, e.g. `['Yes', 'No']`
 *  - Empty array if NULL/empty/invalid
 *
 * Existing call sites gate on `field_type` before treating the return as a list.
 */
```

**[lib/Service/FieldService.php:299](MetaVox/lib/Service/FieldService.php#L299)** — `createGroupfolderField` is al correct, geen wijziging.

### 2. Field-definitie UI (admin)

Twee structureel identieke files (copy-paste tussen file fields en team folder fields):

| File | Wijziging |
|---|---|
| [src/components/FileMetadataFields.vue](MetaVox/src/components/FileMetadataFields.vue) | Voeg `includeTime` checkbox toe + persisteer in `field_options` |
| [src/components/GroupfolderMetadataFields.vue](MetaVox/src/components/GroupfolderMetadataFields.vue) | Identiek |

**Template-blok** (toevoegen in zowel add-form (~regel 146) als edit-form (~regel 392), conditional op `type === 'date'`):

```vue
<div v-if="formData.type === 'date'" class="form-row">
  <NcCheckboxRadioSwitch
    :model-value="formData.includeTime"
    @update:model-value="formData.includeTime = $event"
    type="checkbox">
    {{ t('metavox', 'Include time component') }}
  </NcCheckboxRadioSwitch>
  <p class="helper-text">
    {{ t('metavox', 'When enabled, this field stores both date and time (e.g. meetings, deadlines).') }}
  </p>
</div>
```

**`data()` — formData / editData** (regels 599-606 en 612-618): voeg `includeTime: false` toe.

**Schrijfpad — `addField()` (~regel 890-893)**, type-afhankelijke branch:
```js
field_options: this.formData.type === 'date'
  ? { includeTime: !!this.formData.includeTime }
  : this.formData.options.filter(o => String(o.value || '').trim()).map(o => String(o.value).trim()).join('\n'),
```

**Schrijfpad — `saveEdit()` (~regel 966-970)**: identieke branch op het type van het bewerkte veld.

**Leespad — `editField()` (~regel 943-952)**: vul `editData.includeTime` uit `field.field_options.includeTime`. Vergeet niet `!Array.isArray(opts)` te checken zodat select-arrays niet als dict worden geïnterpreteerd:
```js
includeTime: !!(opts && typeof opts === 'object' && !Array.isArray(opts) && opts.includeTime),
```

**`hasFieldOptions()` / `formatFieldOptions()` (~regel 298, 1088-1112)** — voorkom dat de date-dict als "Options:" pill wordt gerenderd:
```js
if (typeof opts === 'object' && !Array.isArray(opts)) return false  // date dict, not a list
```

### 3. Input-componenten — branch op `includeTime`

| File | Wijziging |
|---|---|
| [src/components/fields/DateFieldInput.vue](MetaVox/src/components/fields/DateFieldInput.vue) | `NcDatetimePicker` `:type="includeTime ? 'datetime' : 'date'"`, emit `YYYY-MM-DDTHH:mm:ss` (geen Z) of `YYYY-MM-DD` |
| [src/components/fields/DynamicFieldInput.vue:14-23](MetaVox/src/components/fields/DynamicFieldInput.vue#L14-L23) | `<input :type="includeTime ? 'datetime-local' : 'date'">` met seconde-padding |
| [src/components/MetaVoxPersonal.vue:317-325](MetaVox/src/components/MetaVoxPersonal.vue#L317-L325) | Idem |
| [src/components/ManageGroupfolders.vue:338-346](MetaVox/src/components/ManageGroupfolders.vue#L338-L346) | Idem |
| [src/filesplugin/MetadataForm.vue:48-57](MetaVox/src/filesplugin/MetadataForm.vue#L48-L57) | Idem (sidebar edit form) |
| [src/filesplugin/columns/InlineEditor.js:266-283](MetaVox/src/filesplugin/columns/InlineEditor.js#L266-L283) | Inline grid editor (double-click op cel) |
| [src/flow/MetadataCheck.vue:113-120](MetaVox/src/flow/MetadataCheck.vue#L113-L120) + [.js:222-227](MetaVox/src/flow/MetadataCheck.js#L222-L227) | Workflow filter editor |

**Cruciaal detail — seconde-padding**: `<input type="datetime-local">` emit `YYYY-MM-DDTHH:mm` (16 tekens). Pad naar `:00` voor canonieke 19-teken opslag:

```js
if (this.includeTime && value && value.length === 16) value = value + ':00'
```

**Cruciaal detail — `NcDatetimePicker` emit-formaat**: bibliotheek levert een `Date` object. Voor `includeTime=true` formatteren als floating local (geen UTC-conversie):

```js
function pad(n) { return String(n).padStart(2, '0') }
const s = `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`
```

Voor `includeTime=false` blijft het legacy `d.toISOString().split('T')[0]`.

**`includeTime` computed** (dezelfde shape in elk component, factor desgewenst uit naar een mini-util in `src/utils/dateField.js`):
```js
includeTime() {
  const opts = this.field?.field_options
  return !!(opts && typeof opts === 'object' && !Array.isArray(opts) && opts.includeTime)
}
```

### 4. Read-only display

**[src/filesplugin/FilesSidebarTab.vue:772-780](MetaVox/src/filesplugin/FilesSidebarTab.vue#L772-L780)** — branch in `formatValue()`:

```js
if (field.field_type === 'date' && value) {
  try {
    const d = new Date(value)
    if (isNaN(d.getTime())) return value
    const opts = field.field_options
    const includeTime = !!(opts && typeof opts === 'object' && !Array.isArray(opts) && opts.includeTime)
    return includeTime ? d.toLocaleString() : d.toLocaleDateString()
  } catch (e) { return value }
}
```

**[src/filesplugin/columns/ColumnUtils.js:18-37](MetaVox/src/filesplugin/columns/ColumnUtils.js#L18-L37)** — `formatValue(value, fieldType)` krijgt een derde optionele `field`-argument:

```js
export function formatValue(value, fieldType, field = null) {
  // …
  case 'date': {
    const d = new Date(value)
    if (isNaN(d.getTime())) return value
    const opts = field?.field_options
    const includeTime = !!(opts && typeof opts === 'object' && !Array.isArray(opts) && opts.includeTime)
    return includeTime ? d.toLocaleString() : d.toLocaleDateString()
  }
  // …
}
```

Update call-site **[src/filesplugin/columns/ColumnDOM.js:317](MetaVox/src/filesplugin/columns/ColumnDOM.js#L317)** naar `formatValue(value, config.field_type, config)` (config draagt `field_options` op de regels 81, 248, 567).

### 5. AI autofill

**[lib/Service/AiAutofillService.php:403-405](MetaVox/lib/Service/AiAutofillService.php#L403-L405)** — prompt-branch:
```php
case 'date':
    $includeTime = is_array($field['field_options'] ?? null) && !empty($field['field_options']['includeTime']);
    $desc .= $includeTime
        ? 'return date AND time in YYYY-MM-DDTHH:mm:ss format (24-hour, NO timezone suffix)'
        : 'return in YYYY-MM-DD format';
    break;
```

**[lib/Service/AiAutofillService.php:517-521](MetaVox/lib/Service/AiAutofillService.php#L517-L521)** — validatie:
```php
case 'date':
    // Accepts YYYY-MM-DD (date-only) or YYYY-MM-DDTHH:mm:ss (datetime, no TZ)
    if (!preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2})?$/', $stringValue)) {
        continue 2;
    }
    $expectsTime = is_array($fieldOptions[$fieldName] ?? null)
        && !empty($fieldOptions[$fieldName]['includeTime']);
    $hasTime = strpos($stringValue, 'T') !== false;
    if ($expectsTime !== $hasTime) {
        continue 2;
    }
    break;
```

Het bestaande `$fieldOptions` array op regel 491-495 behoudt al dict-shape via `is_array(...) ? ... : explode(...)` — geen extra wijziging nodig.

### 6. API-contract documentatie

[lib/Service/ApiFieldService.php:60-96](MetaVox/lib/Service/ApiFieldService.php#L60-L96) — passeert waarde als opake string. Geen code-wijziging; documenteer het contract in de docblock:

> Voor `date` velden: `includeTime=false` ⇒ waarde MOET matchen `YYYY-MM-DD`; `includeTime=true` ⇒ waarde MOET matchen `YYYY-MM-DDTHH:mm:ss` (geen timezone). Floating ISO strings — MetaVox doet geen TZ-conversie.

### 7. Documentatie

**[docs/user/field-types.md:42-49](MetaVox/docs/user/field-types.md#L42-L49)** — vervang de Date-sectie:

```markdown
### Date
A date picker for selecting dates. Optionally, the field can also capture a time component.

**Use cases:** Publication date, expiry date, review date, meeting start, deadlines

**Examples:**
- Date only: `2026-04-15`
- Date + time: `2026-04-15T14:30:00`

**Options:**
- **Include time component** (default: off) — captures hours, minutes and seconds in addition
  to the date. Stored as a floating ISO 8601 string (no timezone), matching SharePoint's
  `SPFieldDateTime` with `DisplayFormat = DateTime`.

**SharePoint migration note:** Columns with `DisplayFormat = DateOnly` map to MetaVox Date
fields with this option **off**; `DateTime` maps to **on**. CSV import support is planned
for a future release.
```

### 8. CHANGELOG + version bump

| File | Wijziging |
|---|---|
| [appinfo/info.xml:28](MetaVox/appinfo/info.xml#L28) | `2.0.9` → `2.1.0` |
| [package.json:4](MetaVox/package.json#L4) | `2.0.9` → `2.1.0` |
| `package-lock.json` | Top-level `"version"` op 2 plekken bumpen (zelfde patroon als v2.0.9) |
| `CHANGELOG.md` | Nieuwe `[2.1.0]` sectie met `### Added` (de feature) + `### Fixed` (de updateField-bug). Verwijst naar #65. |

### 9. Translation review

Per [Translation Review Process](memory:feedback_translation_review) — slechts 2 nieuwe strings:

| English | Bucket |
|---|---|
| `Include time component` | UI label (checkbox) |
| `When enabled, this field stores both date and time (e.g. meetings, deadlines).` | UI helper |

**Onder de >10 drempel**, maar wel een categorisch diff produceren vóór commit:

- **Toegevoegd (B)**: 2 strings in `l10n/en.js` + `l10n/en.json`. Transifex pakt nl/de/fr/sv automatisch op.
- **Verwijderd (A)**: geen.
- **Gewijzigd (C)**: geen (Date-sectie in `docs/user/field-types.md` is geen l10n).

## Reuse / geen nieuwe patterns

- `field_options TEXT` op `metavox_gf_fields` — al aanwezig, JSON-tolerant via `parseFieldOptions()`.
- `field_value TEXT` op beide metadata-tabellen — accepteert al beide lengtes (10 of 19 tekens).
- `NcDatetimePicker` met `type="datetime"` — beschikbaar in NC 31–33, gebruikt door NC core (Activity-app filter).
- Lexicografische sort in [FilterService.php:266-275](MetaVox/lib/Service/FilterService.php#L266-L275) — ISO 8601 sorteert correct in beide formaten, geen wijziging.
- Search-index in [SearchIndexService.php](MetaVox/lib/Service/SearchIndexService.php) — TEXT-match werkt voor beide formaten, geen wijziging.

**Geen** nieuwe DB-migratie. **Geen** nieuwe routes. **Geen** nieuwe services.

## Verification (end-to-end)

Deploy via de docker-cp flow naar `dev.rikdekker.nl` (zelfde patroon als v2.0.9).

| # | Test | Verwacht |
|---|---|---|
| 1 | Admin → File Metadata Fields → Add (type=Date, includeTime=OFF) | Veld opgeslagen; `field_options = {"includeTime":false}` of legacy lege list |
| 2 | Idem met includeTime=ON | `field_options = {"includeTime":true}` |
| 3 | Sidebar edit op veld (1) | DatetimePicker date-only; storage `YYYY-MM-DD` |
| 4 | Sidebar edit op veld (2) | DatetimePicker datetime; storage `YYYY-MM-DDTHH:mm:ss` (géén Z) |
| 5 | Files grid: dubbelklik op cel veld (1) | `<input type="date">`, output `YYYY-MM-DD` |
| 6 | Files grid: dubbelklik op cel veld (2) | `<input type="datetime-local">`, output gepad naar `:00` |
| 7 | Sidebar read-only veld (1) | `toLocaleDateString()` |
| 8 | Sidebar read-only veld (2) | `toLocaleString()` |
| 9 | Pre-v2.1.0 date-veld (`field_options = NULL`) | Renders date-only, geen regressie |
| 10 | **Updatebug-regressie**: Edit veld (1), flip includeTime ON, save, reload, edit opnieuw | Checkbox blijft ON na save (zonder de fix in §1 blijft hij OFF — must-pass) |
| 11 | Edit veld (2), flip includeTime OFF | Bestaande datetime-waardes renderen als date via truncatie; storage onveranderd |
| 12 | AI autofill veld (1) — antwoord `2026-05-13` | Geaccepteerd |
| 12b | AI autofill veld (1) — antwoord `2026-05-13T10:00:00` | Afgewezen |
| 13 | AI autofill veld (2) — antwoord `2026-05-13T10:00:00` | Geaccepteerd |
| 13b | AI autofill veld (2) — antwoord `2026-05-13` | Afgewezen |
| 14 | `POST /apps/metavox/api/...` met `"2026-05-13T14:30:00"` voor veld (2) | Opgeslagen as-is, `GET` retourneert hetzelfde |
| 15 | Workflow MetadataCheck-filter editor op veld (2) | Datetime-input zichtbaar |
| 16 | Sort kolom op veld (2) | Chronologisch correct (lexicografische ISO-sort) |
| 17 | View Editor: kolom voor veld (2) | Geen parse-error op de dict |

## Risico's

1. **De `updateField` fix in §1 is voorwaarde voor het hele feature**. Zonder fix lekt elke save de `includeTime` flag weg → test #10 vangt dit.
2. `new Date('YYYY-MM-DDTHH:mm:ss')` (zonder Z) wordt door ECMA-262 als **local time** geparseerd — exact wat we willen voor floating values. `new Date('YYYY-MM-DD')` blijft **UTC** (legacy gedrag, niet veranderen).
3. Oudere Safari-versies negeren `step="1"` op `<input type="datetime-local">` en emit alleen `:mm` — de seconde-padding handler is daarvoor de vangnet.
4. Bij flippen van `includeTime` (test #10/#11) worden bestaande waardes **niet** herschreven. Bewuste keuze — geen destructieve data-migratie.

## Out of scope (vervolgissues)

- **Date-range filtering** in `FilterService` — vereist `>=`/`<=` predicates + datepicker UI in `MetaVoxFilters`. Nieuw issue.
- **SharePoint CSV-importer** met `SPFieldDateTime.DisplayFormat` parsing — apart traject, raakt nieuwe `ImportService`.
- **Timezone-aware display** — de app blijft expliciet floating. Pas overwegen als gebruikers er om vragen.
- **Backfill** van `YYYY-MM-DD` → `YYYY-MM-DDT00:00:00` bij flip van `includeTime` — afgewezen.

## Critical files

- [lib/Service/FieldService.php](MetaVox/lib/Service/FieldService.php) — updateField fix + parseFieldOptions doc
- [lib/Service/AiAutofillService.php](MetaVox/lib/Service/AiAutofillService.php) — prompt + validatie
- [src/components/FileMetadataFields.vue](MetaVox/src/components/FileMetadataFields.vue) + [GroupfolderMetadataFields.vue](MetaVox/src/components/GroupfolderMetadataFields.vue) — admin UI
- [src/components/fields/DateFieldInput.vue](MetaVox/src/components/fields/DateFieldInput.vue) + [DynamicFieldInput.vue](MetaVox/src/components/fields/DynamicFieldInput.vue) — input
- [src/filesplugin/FilesSidebarTab.vue](MetaVox/src/filesplugin/FilesSidebarTab.vue) + [columns/ColumnUtils.js](MetaVox/src/filesplugin/columns/ColumnUtils.js) + [InlineEditor.js](MetaVox/src/filesplugin/columns/InlineEditor.js) — read + inline edit
