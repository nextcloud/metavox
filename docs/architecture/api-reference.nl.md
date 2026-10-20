# MetaVox API-referentie

De MetaVox-API biedt endpoints voor metadata-beheer, veld-configuratie, weergaven, filter-waarden en batch-operaties.

> **Volledige endpoint-documentatie**: zie de [Engelse versie](api-reference.md) — alle endpoint-paden, JSON-payloads en field-namen zijn intrinsiek Engels in de Nextcloud-API en worden niet vertaald.

## Route-typen

MetaVox biedt twee soorten API-routes:

| Type | Basis-URL | Auth | Use case |
|------|-----------|------|----------|
| **OCS** | `/ocs/v2.php/apps/metavox/api/v1/` | App-wachtwoord, OAuth of sessie | Externe integraties, scripts, migraties |
| **Browser** | `/apps/metavox/api/` | Sessie (CSRF-token vereist) | Interne UI, admin-operaties |

**Voor externe integraties**: gebruik altijd OCS-routes — geen CSRF-token vereist, werkt met app-wachtwoorden.

**Browser-routes**: gebruikt door de MetaVox-frontend. Vereisen Nextcloud-sessie en CSRF-token. Sommige features (permissies, standaardwaarden, AI, back-up, instellingen, licentie, telemetrie) zijn alleen via browser-routes beschikbaar. Browser-routes zijn intern voor de UI en geen stabiele API: paden, parameters en antwoorden kunnen in elke release veranderen.

**WebDAV**: in een maplijst van een teammap voegt MetaVox de eigenschap `nc:metavox` toe met de waarden per bestand.

## Architectuur

| Controller | Verantwoordelijkheid |
|------------|----------------------|
| **`ApiFieldController`** | OCS-endpoints: velden, metadata van bestanden en teammappen, batch-operaties, statistieken |
| **`ApiFilterController`** | OCS-endpoints: directory-metadata, filter-waarden, gesorteerde bestand-ID's |
| **`ApiViewController`** | OCS-endpoints: weergave-CRUD per groupfolder |
| **`ViewController`** | Browser-gebaseerd weergave-beheer (admin-UI, sessie vereist) |
| **`LockController`** | Cell-lock/unlock voor gelijktijdig bewerken |
| **`PresenceController`** | Aanwezigheids-tracking (verlaten bij tab-sluiten) |
| **`AiAutofillController`** | AI metadata-generatie |
| **`BackupController`** | Back-up- & herstel-operaties |
| **`SettingsController`** | Admin-instellingen (AI, links in URL-velden, zoeken) |
| **`TelemetryController`** | Gebruiks-telemetrie-rapportage |
| **`PermissionController`** | Toekennen en intrekken van het beheerrecht (gebruikers en groepen) |
| **`DefaultsController`** | Standaardwaarden per teammap |
| **`LicenseController`** | Licentiesleutel, validatie en gebruik |
| **`MetadataPropertyPlugin`** | De WebDAV-eigenschap `nc:metavox` in maplijsten |
| **`FieldService`** | Veld-definities, metadata-reads/-writes — met gedistribueerde cache |
| **`ViewService`** | Weergave-CRUD — met gedistribueerde cache |

## Belangrijke opmerkingen

- Alle batch-operaties werken met **groupfolder bestand-velden** (opgeslagen in `metavox_gf_fields` en `metavox_file_gf_meta`)
- Batch update/delete-operaties vereisen zowel `file_id` als `groupfolder_id` per item
- De lijst met weergaven en de bestandsvelden van een teammap geven `Cache-Control: private, max-age=600` terug
- Elke route legt met `#[RequireAccess]` vast wie hem mag aanroepen. Weergaven en de metadata van de teammap zelf: wie de teammap mag wijzigen. Velden toewijzen en standaardwaarden: wie daarnaast het MetaVox-beheerrecht heeft. Velddefinities, back-up, instellingen, licentie, telemetrie en permissies: alleen Nextcloud-beheerders

## Veld-naamgevingsconventie

Veldnamen (`field_name`) moeten een strikte conventie volgen:

### Regels

- Begint met het **voorvoegsel van zijn soort** (zie hieronder): `file_gf_` voor een bestandsveld, `gf_` voor een teammapveld (`applies_to_groupfolder: 1`)
- Na het voorvoegsel alleen **kleine letters** (a-z), **cijfers** (0-9) en **underscores** (_), **te beginnen met een letter**
- Geen spaties, hoofdletters of speciale tekens
- De server controleert de naam bij aanmaken, hernoemen en omzetten naar het andere soort veld, via de web-routes en de OCS-API. Een ongeldige naam geeft `400 Bad Request` met `reason: "invalid_input"` en de melding onder `fields.field_name`; het voorvoegsel wordt niet voor je toegevoegd
- Een veld dat een oudere versie zonder voorvoegsel opsloeg, blijft werken zolang naam en soort niet veranderen

### Prefix-conventie

MetaVox gebruikt prefixes om veld-scopes te onderscheiden:

| Scope | Prefix | Voorbeeld | Beschrijving |
|-------|--------|-----------|--------------|
| Team folder-metadata | `gf_` | `gf_publication_status` | Metadata die geldt voor de team folder zelf |
| Bestand-metadata | `file_gf_` | `file_gf_department` | Metadata die geldt voor individuele bestanden binnen een team folder |

## Endpoint-categorieën

De volledige API is georganiseerd in deze groepen — zie de [EN-volledige referentie](api-reference.md) voor exacte paden, methoden, payloads en response-codes:

### Velden

- Veld aanmaken/bijwerken/verwijderen
- Alle veld-definities ophalen
- Velden toewijzen aan een groupfolder
- Toegewezen velden ophalen voor een groupfolder
- Meerdere velden tegelijk verwijderen (browser-route `bulk-delete`; velden die nog zijn toegewezen worden overgeslagen)
- Bij aanmaken en bijwerken controleert de server elk deel en noemt alles wat niet klopt in één `400` met `fields`: een label (verplicht, hoogstens 255 tekens), een bestaand veldtype (`dropdown`, `multi_select`, `boolean` en `usergroup` worden opgeslagen als `select`, `multiselect`, `checkbox` en `user`), minstens één keuze voor een keuzelijst en geen `;#` erin, `{"includeTime": true}` of `false` voor een datum, `{"multiple": true, "kinds": ["user", "group", "team"]}` voor een veld met personen (`user`; weggelaten is één account, zoals voorheen), `true`/`false` voor de vlaggen
- Bijwerken stuurt alleen wat verandert; wat wegblijft houdt zijn waarde. Een veld met waarden houdt zijn naam en type
- Velden toewijzen: `field_ids` is verplicht (`[]` haalt alle velden weg); een id van een veld dat niet bestaat geeft `400`

### Bestand-metadata

- Metadata ophalen voor enkel bestand
- Metadata opslaan voor enkel bestand
- Metadata voor meerdere bestanden (bulk-read)
- Bestand-metadata binnen een groupfolder
- Browser-routes: backlinks (welke bestanden via een File Link-veld naar dit bestand verwijzen), bulk opslaan, metadata wissen (`clear-metadata`; verplichte velden houden hun waarde), export

### Waarden per veldtype

- Elke route die een waarde opslaat, controleert die eerst tegen het veld: het raster, de zijbalk, bulk bewerken, de OCS-API en de batch, standaardwaarden
- Een waarde die het veld niet neemt geeft `400` met `reason: "invalid_input"` en per veld wat het neemt; er wordt dan niets opgeslagen. Voorbeelden: `"1,5"` in een getalveld (alleen `1.5`), `2026-02-30`, een keuze die niet in de lijst staat, `"yes"` in een vinkje (alleen `1`/`0`, `true`/`false`)
- Een geaccepteerde waarde wordt in één vorm opgeslagen: een keuze zoals die in het veld staat (hoofdletters maken niet uit), een meerkeuze mag ook als JSON-lijst, een vinkje als `"1"` of `"0"`
- Een verplicht veld met een waarde kan niet leeg, een verplicht vinkje niet uit. Een bestand zonder metadata mag zonder blijven
- Waarden van vóór deze controle blijven staan; wie ze ongewijzigd terugstuurt, wordt daarop niet geweigerd
- Een veld dat de teammap niet gebruikt, wordt overgeslagen; de batch noemt het in `unused_fields`
- Een bestandslink wijst naar een bestand in dezelfde teammap dat je mag openen (ACL telt mee; beheerders alles). Een link daarbuiten of naar een bestand dat je niet mag openen valt zonder melding weg; een link die al in de waarde stond, blijft staan
- Personen (`user`): account-ids, `group:<id>` en `team:<id>`, gescheiden door `;#` of als JSON-lijst; groepen en teams alleen als het veld ze neemt, meer dan één alleen met `multiple`. Wat nieuw is in een waarde moet bestaan en iets zijn waarmee je mag delen volgens de deelinstellingen van Nextcloud (alleen eigen groepen, delen met groepen, teams die je kunt zien). Wat er al in stond, blijft staan
- URL-velden: standaard elke link behalve een die code uitvoert (`javascript:`). De beheerder kan in het tabblad *Algemeen* alleen web- en e-mailadressen toestaan, domeinen blokkeren (`.ru`) of alleen bepaalde domeinen toestaan
- Zie de Engelse referentie, *Values per field type*, voor de tabel per veldtype en de meldingen

### Groupfolders

- Alle groupfolders listen
- Groupfolder-metadata ophalen/opslaan

### Datum / DateTime-velden

- Veld-definitie-shape
- Waarde-format-contract (writes)
- SharePoint migratie-mapping

### Batch-operaties

- Metadata batch update
- Metadata batch delete
- Metadata batch copy
- Per bestand een resultaat; bij een fout een vaste `reason` (o.a. `not_found`, `read_only`, `save_failed`) en een `requestId` om de details in de Nextcloud-log te vinden
- Een batch slaat per teammap in één keer op: mislukt dat, dan krijgen alle bestanden van die teammap in de batch `save_failed` en is daarvan niets opgeslagen
- Een item met een waarde die het veld niet neemt, wordt geweigerd (`invalid_input`, met `fields`); de andere items worden opgeslagen
- Batch delete laat verplichte velden staan: alles wissen houdt ze (`kept_required_fields`), een item dat er een noemt, wordt geweigerd

### Filter-waarden en directory-metadata

- Alle filter-waarden, filter-waarden per map, gesorteerde file-IDs, metadata voor de kolommen van de bestandslijst
- De kolom-config-endpoints (`.../columns`) zijn in 1.8.67 vervallen; de kolommen van een weergave bepalen wat de bestandslijst toont

### Weergaven

- Weergave CRUD per groupfolder
- Lijst van weergaven
- Cellen markeren: regels per kolom onder `format`, op volgorde gecontroleerd; de eerste regel die past, telt (zie [Cellen markeren](../user/highlight-rules.md)). Laat je `format` weg in een kolom die je meestuurt, dan blijven de opgeslagen regels staan; `[]` wist ze; `null` geeft een 400. Zie de Engelse referentie voor de voorwaarden en grenzen

### Permissies

- MetaVox heeft één eigen recht: het beheerrecht (`manage_fields`) om velden toe te wijzen en standaardwaarden in te stellen, per teammap of voor alle teammappen
- Toekennen/intrekken per gebruiker of groep, toekenningen bekijken (alleen beheerders)

### Standaardwaarden

- Standaardwaarden per teammap ophalen en instellen, nu toepassen (`defaults/trigger`) en de status opvragen (`defaults/status`)
- Een standaardwaarde wordt gecontroleerd zoals een waarde van dat veld; `null` of `""` wist hem; een bestandslink moet naar een bestand in de teammap wijzen dat je mag openen

### Licentie

- Licentiesleutel opslaan, valideren, gebruik melden, statistieken (alleen beheerders)

### WebDAV-eigenschap `nc:metavox`

- Een `PROPFIND` met `Depth: 1` op een map in een teammap geeft per bestand een JSON-document: versie (`v`), teammap (`g`), bewerkrecht (`e`), waarden (`f`), afgekapte velden (`t`) en de namen van de accounts, groepen en teams in de waarden (`n`), zoals jij ze mag zien. Waarden langer dan 512 tekens worden afgekapt

### Backup & herstel

- Back-up triggeren / lijst / herstellen / downloaden / status

### Telemetrie

- Status check / send / settings

### AI-autofill

- Status / metadata-suggesties genereren

### Cell-locking

- Lock / unlock

### Aanwezigheids-tracking

- Aanwezigheid registreren / verlaten

## Authenticatie

### OCS-API (extern)

```bash
# Met app-wachtwoord
curl -u "username:app_password" \
  "https://your-nextcloud.com/ocs/v2.php/apps/metavox/api/v1/groupfolder-fields" \
  -H "OCS-APIRequest: true"
```

### Browser-API (intern)

Gebruikt automatisch de sessie van de gebruiker met een CSRF-token. Niet bruikbaar voor externe integraties.

## Response-formaten

OCS-endpoints geven gestandaardiseerde XML/JSON-responses terug:

```json
{
  "ocs": {
    "meta": {
      "status": "ok",
      "statuscode": 200,
      "message": "OK"
    },
    "data": {
      // endpoint-specifieke data
    }
  }
}
```

Browser-endpoints geven direct JSON terug, zonder OCS-envelop. Een geweigerd verzoek heeft in beide gevallen een `error`-tekst en een vaste `reason`, zoals `not_found`, `read_only`, `not_manager` of `forbidden`.

Een parameter van de verkeerde soort wordt geweigerd in plaats van stil omgezet: `400` met `reason: "invalid_input"` en in `fields` de parameter met wat die neemt. Voorbeelden: `"abc"` voor een getal, `"ja"` voor true/false, iets anders dan een id in een lijst met ids, meer ids dan een route neemt. Een id in de URL bestaat alleen uit cijfers; `/groupfolders/12abc/...` is geen route (`404`).

Gebruik `/ocs/v2.php`. Op `/ocs/v1.php` antwoordt Nextcloud altijd met HTTP `200` (een `403` wordt `401`) en staat de status alleen in `ocs.meta.statuscode`.

## Rate limiting

MetaVox volgt Nextcloud's rate-limiting. Voor bulk-operaties: gebruik batch-endpoints in plaats van vele individuele calls.

## Versionering

De API is op `/v1/` gepind. Backward-compatibele wijzigingen worden binnen v1 toegevoegd. Breaking changes zouden naar een `/v2/`-pad gaan met aankondiging.

## Zie ook

- [Volledige EN-API-referentie](api-reference.md) — alle endpoint-details
- [Integratie-gids](integration.md) — Voorbeelden van externe systeem-integratie
- [Architectuur-overzicht](overview.md) — Hoe de API in het systeem past
