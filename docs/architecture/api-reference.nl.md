# MetaVox API-referentie

De MetaVox-API biedt endpoints voor metadata-beheer, veld-configuratie, weergaven, kolom-configuratie en batch-operaties.

> **Volledige endpoint-documentatie**: zie de [Engelse versie](../../en/metavox/architecture/api-reference/) — alle endpoint-paden, JSON-payloads en field-namen zijn intrinsiek Engels in de Nextcloud-API en worden niet vertaald.

## Route-typen

MetaVox biedt twee soorten API-routes:

| Type | Basis-URL | Auth | Use case |
|------|-----------|------|----------|
| **OCS** | `/ocs/v2.php/apps/metavox/api/v1/` | App-wachtwoord, OAuth of sessie | Externe integraties, scripts, migraties |
| **Browser** | `/apps/metavox/api/` | Sessie (CSRF-token vereist) | Interne UI, admin-operaties |

**Voor externe integraties**: gebruik altijd OCS-routes — geen CSRF-token vereist, werkt met app-wachtwoorden.

**Browser-routes**: gebruikt door de MetaVox-frontend. Vereisen Nextcloud-sessie en CSRF-token. Sommige features (permissies, AI, back-up, instellingen, telemetrie) zijn alleen via browser-routes beschikbaar.

## Architectuur

| Controller | Verantwoordelijkheid |
|------------|----------------------|
| **`ApiFieldController`** | Alle OCS-endpoints: velden, kolom-config, batch-operaties, filter-waarden, directory-metadata |
| **`ApiFilterController`** | OCS-endpoints: directory-metadata, filter-waarden, gesorteerde bestand-ID's |
| **`ApiViewController`** | OCS-endpoints: weergave-CRUD per groupfolder |
| **`ViewController`** | Browser-gebaseerd weergave-beheer (admin-UI, sessie vereist) |
| **`LockController`** | Cell-lock/unlock voor gelijktijdig bewerken |
| **`PresenceController`** | Aanwezigheids-tracking (verlaten bij tab-sluiten) |
| **`AiAutofillController`** | AI metadata-generatie |
| **`BackupController`** | Back-up- & herstel-operaties |
| **`SettingsController`** | Admin-instellingen (AI-toggle) |
| **`TelemetryController`** | Gebruiks-telemetrie-rapportage |
| **`PermissionController`** | Granulair permissie-beheer |
| **`FieldService`** | Veld-definities, metadata-reads/-writes — met gedistribueerde cache |
| **`ViewService`** | Weergave-CRUD — met gedistribueerde cache |

## Belangrijke opmerkingen

- Alle batch-operaties werken met **groupfolder bestand-velden** (opgeslagen in `metavox_gf_fields` en `metavox_file_gf_meta`)
- Batch update/delete-operaties vereisen zowel `file_id` als `groupfolder_id` per item
- Read-only endpoints voor stabiele data (kolom-config, weergaven, filter-waarden) returnen `Cache-Control: private`-headers

## Veld-naamgevingsconventie

Veldnamen (`field_name`) moeten een strikte conventie volgen:

### Regels

- Alleen **lowercase letters** (a-z), **getallen** (0-9) en **underscores** (_) toegestaan
- Moet **beginnen met een letter** (geen getal of underscore)
- Geen spaties, hoofdletters of speciale tekens
- Gevalideerd op zowel frontend als backend — ongeldige namen worden geweigerd met `400 Bad Request`

### Prefix-conventie

MetaVox gebruikt prefixes om veld-scopes te onderscheiden:

| Scope | Prefix | Voorbeeld | Beschrijving |
|-------|--------|-----------|--------------|
| Team folder-metadata | `gf_` | `gf_publication_status` | Metadata die geldt voor de team folder zelf |
| Bestand-metadata | `file_gf_` | `file_gf_department` | Metadata die geldt voor individuele bestanden binnen een team folder |

## Endpoint-categorieën

De volledige API is georganiseerd in deze groepen — zie de [EN-volledige referentie](../../en/metavox/architecture/api-reference/) voor exacte paden, methoden, payloads en response-codes:

### Velden

- Veld aanmaken/bijwerken/verwijderen
- Alle veld-definities ophalen
- Velden toewijzen aan een groupfolder
- Toegewezen velden ophalen voor een groupfolder

### Bestand-metadata

- Metadata ophalen voor enkel bestand
- Metadata opslaan voor enkel bestand
- Metadata voor meerdere bestanden (bulk-read)
- Bestand-metadata binnen een groupfolder

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
- File-IDs sorteren

### Weergaven

- Weergave CRUD per groupfolder
- Lijst van weergaven

### Permissies

- Granulair toekennen/intrekken
- Permissies bekijken

### Backup & herstel

- Back-up triggeren / lijst / herstellen / downloaden / status

### Telemetrie

- Status check / send / settings

### AI-autofill

- Status / metadata-suggesties genereren

### Cell-locking

- Lock / unlock / status

### Aanwezigheids-tracking

- Aanwezigheid registreren / verlaten

## Authenticatie

### OCS-API (extern)

```bash
# Met app-wachtwoord
curl -u "username:app_password" \
  "https://your-nextcloud.com/ocs/v2.php/apps/metavox/api/v1/fields" \
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

Browser-endpoints geven direct JSON terug:

```json
{
  "success": true,
  "data": { ... }
}
```

## Rate limiting

MetaVox volgt Nextcloud's rate-limiting. Voor bulk-operaties: gebruik batch-endpoints in plaats van vele individuele calls.

## Versionering

De API is op `/v1/` gepind. Backward-compatibele wijzigingen worden binnen v1 toegevoegd. Breaking changes zouden naar een `/v2/`-pad gaan met aankondiging.

## Zie ook

- [Volledige EN-API-referentie](../../en/metavox/architecture/api-reference/) — alle endpoint-details
- [Integratie-gids](integration.md) — Voorbeelden van externe systeem-integratie
- [Architectuur-overzicht](overview.md) — Hoe de API in het systeem past
