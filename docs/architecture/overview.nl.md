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
| `FieldService` | Core metadata-operaties (CRUD), gedistribueerde cache |
| `ViewService` | Weergave-CRUD met gedistribueerde cache |
| `ApiFieldService` | Batch-operaties voor API |
| `ApiFieldController` | OCS API request-handling |
| `ViewController` | Weergaven-API (list, create, update, delete) |
| `MetadataPropertyPlugin` | WebDAV-plugin: levert de property `nc:metavox` in maplijsten |
| `AccessMiddleware` | Controleert de `#[RequireAccess]`-regel van elke route voordat de controller draait; een route zonder regel wordt geweigerd |
| `PushService`, `PresenceService` | Push-events naar de gebruikers die een teammap open hebben |
| `SearchWordsService` | Woordindex voor vrij zoeken |
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

**`metavox_gf_assigns`** — welke velden elke groupfolder gebruikt

**`metavox_gf_metadata`** — metadata-waarden van de groupfolder zelf

**`metavox_gf_views`** — opgeslagen weergaven per groupfolder

- Naam, standaard-vlag
- Kolom-zichtbaarheid en -volgorde (JSON); hier staat ook de kolomconfiguratie
- Preset-filters (JSON) en sort-configuratie

**`metavox_search_words`** — woordindex voor vrij zoeken

- Eén rij per woord, veld, bestand en groupfolder, opgezocht op de eerste drie letters van het woord

**`metavox_permissions`** — gebruikers en groepen met het beheerrecht per groupfolder

## Integratiepunten

### Group Folders-app

MetaVox vereist de Group Folders-app en integreert op deze punten:

- Groupfolder-detectie uit bestandspaden
- Permissie-overerving
- Folder-niveau metadata-scoping

### Nextcloud Files

- Zijbalk-integratie via Files-app-hooks
- Bestandsactie voor bulk-bewerken
- Metadata-weergave in bestandsweergaven: de waarden komen mee met de eigen WebDAV-maplijst van de Files-app (zie [Performance-overwegingen](#performance-overwegingen))

### Unified search

Vrij zoeken zoekt woorden op in een woordindex per teammap (`metavox_search_words`), zodat elk lid bestanden vindt op hun metadata. Een zoekopdracht gebruikt maximaal vijf woorden, begint bij het zeldzaamste en leest maximaal 2.000 rijen per woord, zodat de kosten niet meegroeien met de grootte van de teammap. Is elk woord zo algemeen, dan komen de resultaten uit de 2.000 nieuwste bestanden van het zeldzaamste woord. Een achtergrondtaak vult de index voor bestaande waarden, maximaal 20 seconden per cron-run. Datum-, nummer-, selectievakje- en bestandslinkvelden staan niet in de woordindex; die vind je met een veldfilter of een `field:value`-zoekopdracht.

### Setup-checks

MetaVox voegt checks toe aan **Beheerinstellingen → Overzicht**: "MetaVox-teammappen" (Teammappen-app ingeschakeld), "MetaVox-achtergrondtaken" (systeem-cron, en of die het afgelopen uur heeft gedraaid), "MetaVox-zoekindex" (voortgang van de woordindex), "MetaVox live-updates" (notify_push), "MetaVox-aanwezigheid" (Gebruikersstatus-app) en "MetaVox gedeelde cache" (gedistribueerde cache anders dan APCu).

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

```
Gebruiker opent een teammap → Files-app stuurt zijn PROPFIND (Depth 1)
                            → MetadataPropertyPlugin controleert toegang per bestand
                              en leest alle waarden in één query per 1.000 bestanden
                            → nc:metavox-property per bestand in de maplijst
                            → Grid-kolommen vullen zich vanuit de maplijst
                            → Bestanden die de maplijst niet dekte: API als fallback
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
- Grid-waarden komen mee met de WebDAV-maplijst van de Files-app (`PROPFIND`, property `nc:metavox`): geen apart request per map. Elke waarde wordt daar afgekapt op 512 tekens; een afgekapte waarde is gemarkeerd, en de volledige waarde laadt als de cel wordt bewerkt
- Wat de maplijst niet levert, wordt als fallback via de API geladen, in debounced batches van maximaal 200 bestanden per request
- Stabiele data (veld-definities, weergaven, filter-waarden) wordt gecached in gedistribueerde cache (Redis/APCu) met automatische invalidatie bij write
- HTTP `Cache-Control: private`-headers op read-only API-endpoints verminderen redundante requests
- Huidige filtering is client-side; server-side filtering is de geplande volgende stap voor folders met >5.000 bestanden

## Technology-stack

| Laag | Technologie |
|------|-------------|
| Frontend | Vue 3, Nextcloud Vue, Webpack |
| Backend | PHP 8.1+, Nextcloud 31–35 App API |
| Database | MySQL/MariaDB/PostgreSQL (via Nextcloud); SQLite alleen om te testen |
| API | OCS REST API, WebDAV-property `nc:metavox` |

## Zie ook

- [Privacy & beveiliging](privacy.md) — Databescherming-details
- [API-referentie](api-reference.md) — API-documentatie
- [Integratie-gids](integration.md) — Externe-systeem-integratie
