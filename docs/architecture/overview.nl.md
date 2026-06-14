# MetaVox architectuur-overzicht

Dit document biedt een technisch overzicht van MetaVox's architectuur voor architecten, ontwikkelaars en IT-beslissers.

## Systeem-overzicht

MetaVox is een Nextcloud-app die gestructureerde metadata-mogelijkheden toevoegt aan Team folders. Het volgt Nextcloud's app-architectuur en integreert met core Nextcloud-services.

```
┌──────────────────────────────────────────────────────────────┐
│                       Nextcloud-server                       │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────┐   │
│  │  Files-app  │  │ Group       │  │  Workflow Engine    │   │
│  │             │  │ Folders-app │  │  (Flow)             │   │
│  └──────┬──────┘  └──────┬──────┘  └──────────┬──────────┘   │
│         │                │                     │             │
│  ┌──────┴────────────────┴─────────────────────┴──────────┐  │
│  │                       MetaVox-app                       │ │
│  │  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐   │ │
│  │  │ Vue Frontend │  │ PHP Backend  │  │ OCS API      │   │ │
│  │  └──────────────┘  └──────────────┘  └──────────────┘   │ │
│  └─────────────────────────────────────────────────────────┘ │
│                              │                               │
│  ┌───────────────────────────┴────────────────────────────┐  │
│  │                   Nextcloud-database                    │ │
│  │  ┌─────────────────┐  ┌──────────────────────────┐      │ │
│  │  │ metavox_gf_     │  │ metavox_file_gf_meta     │      │ │
│  │  │ fields          │  │ (document-metadata)      │      │ │
│  │  └─────────────────┘  └──────────────────────────┘      │ │
│  └────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────┘
```

## Kerncomponenten

### Frontend (Vue.js)

- **Zijbalk-paneel**: toont en bewerkt metadata in de bestandszijbalk
- **Admin-instellingen**: configuratie-interface voor veld-definities
- **Bulk-editor**: multi-bestand metadata-bewerk-interface
- **Flow-component**: custom check-UI voor Workflow Engine

Technologie: Vue 3, Nextcloud Vue componentbibliotheek

### Backend (PHP)

| Component | Verantwoordelijkheid |
|-----------|----------------------|
| `FieldService` | Core metadata-operaties (CRUD), kolom-config, gedistribueerde cache |
| `ViewService` | Weergave-CRUD met gedistribueerde cache |
| `ApiFieldService` | Batch-operaties voor API |
| `ApiFieldController` | OCS API request-handling |
| `ViewController` | Weergaven-API (list, create, update, delete) |
| `MetadataCheck` | Flow-integratie (ICheck-implementatie) |

### Database-schema

MetaVox gebruikt Nextcloud's database-abstractie-laag met de volgende tabellen:

**`metavox_gf_fields`** — veld-definities

- Veldnaam, label, type, opties
- Groupfolder-associatie
- Verplicht-vlag, beschrijving

**`metavox_file_gf_meta`** — document-metadata-waarden

- Bestand-ID, groupfolder-ID, veldnaam, veld-waarde
- Geïndexeerd voor snelle bulk-lookup en filter-queries

**`metavox_gf_column_config`** — kolom-display-configuratie per groupfolder

- Welke velden als kolommen verschijnen in de bestandenlijst
- Kolom-volgorde en filterbaar-vlag per veld

**`metavox_gf_views`** — opgeslagen weergaven per groupfolder

- Naam, standaard-vlag
- Kolom-zichtbaarheid en -volgorde (JSON)
- Preset-filters (JSON) en sort-configuratie

## Integratiepunten

### Group Folders-app

MetaVox vereist de Group Folders-app en integreert op deze punten:

- Groupfolder-detectie uit bestandspaden
- Permissie-overerving
- Folder-niveau metadata-scoping

### Nextcloud Files

- Zijbalk-integratie via Files-app-hooks
- Bestandsactie voor bulk-bewerken
- Metadata-weergave in bestandsweergaven

### Workflow Engine (Flow)

MetaVox registreert een custom `ICheck`-implementatie waarmee Flow-regels metadata-voorwaarden kunnen evalueren. Zie [Integratie-gids](integration.md) voor details.

### OCS API

RESTful API voor externe integraties:

- Single-bestand metadata-operaties
- Batch-operaties (update, delete, copy)
- Statistieken en rapportage

Zie [API-referentie](api-reference.md) voor endpoints.

## Data-flow

### Metadata bekijken

```
Gebruiker opent bestand → Files-app-zijbalk laadt
                       → MetaVox-paneel vraagt metadata op
                       → FieldService bevraagt database
                       → Geeft veld-definities + waarden terug
                       → Vue rendert metadata-formulier
```

### Metadata bewerken

```
Gebruiker wijzigt veld → Vue stuurt update-request
                      → Controller valideert permissies
                      → FieldService werkt database bij
                      → Geeft success/error terug
                      → UI werkt bij
```

### Flow-regel-evaluatie

```
Bestands-event triggert Flow → Flow laadt MetadataCheck
                            → MetadataCheck haalt bestands-metadata op
                            → Evalueert voorwaarde
                            → Geeft true/false terug
                            → Flow voert actie uit (of niet)
```

## Beveiligingsmodel

- **Authenticatie**: Nextcloud-sessie of app-wachtwoord
- **Autorisatie**: erft van Nextcloud-bestandsrechten
- **Data-validatie**: server-side validatie van alle inputs
- **SQL injection-bescherming**: geparameteriseerde queries via Nextcloud DB-laag

Zie [Privacy & beveiliging](privacy.md) voor details.

## Performance-overwegingen

- Metadata-queries zijn geïndexeerd op `file_id`, `groupfolder_id`, en `(groupfolder_id, field_name, field_value)` voor snelle filter-lookups
- Batch-operaties gebruiken database-transacties
- Frontend laadt metadata in debounced batches (max 200 bestanden per request) terwijl bestanden in de lijst verschijnen
- Stabiele data (veld-definities, kolom-config, weergaven, filter-waarden) wordt gecached in gedistribueerde cache (Redis/APCu) met automatische invalidatie bij write
- HTTP `Cache-Control: private`-headers op read-only API-endpoints verminderen redundante requests
- Huidige filtering is client-side; server-side filtering is de geplande volgende stap voor folders met >5.000 bestanden

## Technology-stack

| Laag | Technologie |
|------|-------------|
| Frontend | Vue 3, Nextcloud Vue, Webpack |
| Backend | PHP 8.x, Nextcloud App API |
| Database | MySQL/MariaDB/PostgreSQL (via Nextcloud) |
| API | OCS REST API |

## Zie ook

- [Privacy & beveiliging](privacy.md) — Databescherming-details
- [API-referentie](api-reference.md) — API-documentatie
- [Integratie-gids](integration.md) — Externe-systeem-integratie
