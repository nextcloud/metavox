# Performance-limieten & capaciteitsplanning

Dit document beschrijft de praktische performance-limieten van MetaVox bij het beheren van metadata over Nextcloud Team folders. Het behandelt bestanden per weergave, kolom-limieten, gelijktijdige-gebruikerscapaciteit en hardware-scaling-overwegingen. Waar van toepassing worden limieten vergeleken met SharePoint Online als referentiepunt.

## Samenvatting

| Dimensie | MetaVox aanbevolen limiet | MetaVox harde limiet | SharePoint Online |
|----------|----------------------------|----------------------|-------------------|
| **Bestanden per weergave** | 5.000 | Geen (geleidelijke degradatie) | 5.000 (geblokkeerd erboven) |
| **Velden per team folder** | Onbeperkt | Onbeperkt (EAV-model) | Beperkt door 8.000-byte rijgrootte |
| **Actieve filters (API `sorted-file-ids`)** | 10 | ~15 voor merkbare vertraging | 12 join-operaties (geblokkeerd erboven) |
| **Weergegeven kolommen** | 10–20 | Geen harde limiet | Beperkt door rijgrootte |
| **Gelijktijdige gebruikers (kleine folder)** | 10–100 (hardware-afhankelijk) | N.v.t. | N.v.t. |
| **Gelijktijdige gebruikers (grote folder)** | 2–20 (hardware-afhankelijk) | N.v.t. | N.v.t. |

**Kernpunt**: SharePoint hanteert harde drempels — queries boven 5.000 items worden geweigerd. MetaVox heeft geen harde limieten; performance degradeert geleidelijk. De primaire bottleneck boven 5.000 bestanden is de Nextcloud-browser-side bestandenlijst, niet MetaVox zelf. Gebruik gefilterde weergaven om bestand-aantallen per weergave onder 5.000 te houden voor de beste ervaring.

## SharePoint Online-referentielimieten

SharePoint Online hanteert de volgende harde limieten ([Microsoft-documentatie](https://learn.microsoft.com/en-us/office365/servicedescriptions/sharepoint-online-service-description/sharepoint-online-limits)):

| Beperking | Limiet | Gedrag bij overschrijding |
|-----------|--------|----------------------------|
| **List View Threshold** | 5.000 items per weergave | Query wordt **geblokkeerd** |
| Admin override-threshold | 20.000 items | Query wordt geblokkeerd |
| Max items per lijst/bibliotheek | 30 miljoen | Kan geen items meer toevoegen |
| Rijgrootte-limiet | 8.000 bytes per item | Kan geen kolommen meer toevoegen |
| Lookup join-threshold | 12 join-operaties | Query wordt geblokkeerd (8+ lookup-kolommen) |
| Unieke permissies per lijst | 50.000 (aanbevolen: 5.000) | Performance-degradatie |
| Max bestandsgrootte | 250 GB | Upload geweigerd |

De List View Threshold is SharePoint's meest impact-volle limiet: elke weergave-, sort- of filter-operatie die meer dan 5.000 niet-geïndexeerde items raakt, wordt **volledig geblokkeerd** — niet vertraagd, maar geweigerd door de server.

## MetaVox: bestanden per weergave

MetaVox handhaaft geen harde item-per-weergave-drempel. Performance degradeert geleidelijk naarmate folder-grootte stijgt. Er zijn vier onafhankelijke bottleneck-tiers:

### Bottleneck 1: waarden laden met de maplijst

De metadata-waarden komen mee met de eigen maplijst van de Files-app. MetaVox voegt de WebDAV-property `nc:metavox` toe aan de `PROPFIND` (Depth 1) van de Files-app, en een server-plugin (`MetadataPropertyPlugin.php`) beantwoordt die voor maplijsten van teammappen: één toegangscontrole per bestand in het geheugen, daarna één database-query per 1.000 bestanden voor alle waarden. Elke waarde wordt in de maplijst afgekapt op **512 tekens**; de cel markeert een afgekapte waarde, en de volledige waarde laadt als de cel wordt bewerkt.

Wat de maplijst niet levert (een bestand waarvoor de plugin niet kon instaan, of een maplijst die er was voordat MetaVox klaar stond), wordt als fallback via de API geladen: `directory-metadata`, maximaal **200 bestanden per request**, gebundeld 50 ms na het laatst aangeboden bestand en uiterlijk 200 ms na het eerste.

| Bestanden in folder | Waarde-queries op de server | Extra API-calls bij open | Scroll-gedrag |
|---------------------|-----------------------------|--------------------------|---------------|
| 200 | 1 | 0 | Instant (gecached) |
| 1.000 | 1 | 0 | Instant (gecached) |
| 5.000 | 5 | 0 | Instant (gecached) |
| 10.000 | 10 | 0 | Instant (gecached) |

> **Let op**: de waarden komen mee met de maplijst. Scrollen door de bestandenlijst triggert **geen** extra requests — alle data komt uit de in-memory cache. De MutationObserver detecteert virtual-scroll row-recycling (via `data-cy-files-list-row-fileid` attribute-wijzigingen) en vult cellen direct uit de cache.

### Bottleneck 2: Nextcloud-bestandenlijst

Nextcloud's Files-app laadt alle bestanden in een folder in een client-side `dirContents`-array. Dit is de primaire beperkende factor en is **niet MetaVox-specifiek**:

| Bestanden in folder | Nextcloud-gedrag |
|---------------------|--------------------|
| < 1.000 | Instant laden |
| 1.000–5.000 | 1–2 seconden laden |
| 5.000–10.000 | Merkbare vertraging, UI wordt traag |
| 10.000–50.000 | Multi-seconden laden, browser-geheugendruk |
| 50.000+ | Browser kan hangen of crashen |

### Bottleneck 3: SQL filtering & sortering (API)

In de Files-app draaien filteren en sorteren op MetaVox-kolommen in de browser, over de waarden die het grid al heeft (`FilterSortCore.js`); een afgekapte waarde wordt gefilterd en gesorteerd zoals getoond. Konden niet alle waarden worden geladen, dan pauzeert het grid sorteren en filteren op MetaVox-kolommen en meldt dat.

Server-side filteren en sorteren gebruikt het OCS-endpoint `sorted-file-ids`. Dat werkt met een Entity-Attribute-Value (EAV) model met self-JOINs (`FilterService.php`). Met juiste database-indexen (toegevoegd in migratie `Version20250101000010`) schaalt query-performance logaritmisch:

| Metadata-records | 1 filter | 3 filters | 5 filters |
|------------------|----------|-----------|-----------|
| 10.000 | < 10ms | < 20ms | < 50ms |
| 100.000 | < 50ms | < 100ms | < 200ms |
| 1.000.000 | ~200ms | ~400ms | ~800ms |
| 10.000.000 | ~1s | ~2s | ~4s |

> **Belangrijk**: deze getallen gaan ervan uit dat de database-indexen uit `Version20250101000010` actief zijn. Zonder indexen daalt performance met 40–100×.

### Bottleneck 4: DOM-rendering

Nextcloud 33+ gebruikt virtual scrolling voor de bestandenlijst. MetaVox gebruikt een MutationObserver die luistert naar nieuwe rijen (`childList`) en row-recycling (`attributes` op `data-cy-files-list-row-fileid`). Wanneer een rij wordt gerecycled tijdens scrollen, worden de metadata-cellen direct bijgewerkt uit de in-memory cache zonder API-call. Slechts ~15–50 rijen bestaan in de DOM tegelijkertijd. **Dit is geen bottleneck**, ongeacht folder-grootte.

### Gecombineerde praktische limieten

| Bestanden per weergave | MetaVox-status | SharePoint-status |
|------------------------|----------------|-------------------|
| < 1.000 | Uitstekend | OK |
| 1.000–5.000 | Goed | OK (nadert drempel) |
| 5.000–10.000 | Bruikbaar met vertragingen | **Geblokkeerd** (boven drempel) |
| 10.000–50.000 | Traag, niet aanbevolen | Geblokkeerd |
| 50.000+ | Onwerkbaar | Geblokkeerd |

**Kernverschil**: SharePoint **blokkeert** queries boven 5.000 items. MetaVox **degradeert geleidelijk** — performance wordt slechter, maar operaties worden nooit geweigerd.

## Kolom- & veld-limieten

### MetaVox veld-architectuur

MetaVox gebruikt een EAV (Entity-Attribute-Value) database-model. Metadata-velden worden als rijen opgeslagen, niet als database-kolommen. Dat betekent:

- **Geen hardcoded maximum** op het aantal velden per team folder
- Velden toevoegen verandert het database-schema niet
- Elke veld-waarde wordt opgeslagen als TEXT-type (geen vaste byte-limiet per waarde); de grid-maplijst bevat de eerste 512 tekens

| Beperking | MetaVox | SharePoint |
|-----------|---------|------------|
| Max velden/kolommen per lijst | **Onbeperkt** (EAV-model) | Beperkt door 8.000-byte rijgrootte |
| Veldnaam-lengte | 255 tekens | 255 tekens |
| Veldwaarde-grootte | TEXT (onbeperkt); 512 tekens in de grid-maplijst | Varieert per kolom-type |
| Lookup/join-threshold | Geen harde limiet | 12 join-operaties |
| Veldtypen | 10 (tekst, getal, textarea, datum, select, multi-select, checkbox, url, gebruiker, file-link) | 30+ |

### Kolom-weergave

De frontend rendert metadata-kolommen via DOM-injectie met horizontaal scrollen. Er is geen hardcoded kolom-weergave-limiet:

- Minimum kolom-breedte: 60px
- Kolom-breedtes worden dynamisch berekend op basis van label-lengte en type
- Horizontaal scrollen met sticky headers ondersteund
- Kolom-breedtes worden per browser bewaard (local storage)

### Praktische kolom-limieten

Hoewel er geen harde limiet is, gelden performance-overwegingen:

| Actieve kolommen | Effect |
|------------------|--------|
| 1–10 | Geen impact |
| 10–20 | Bredere horizontaal-scroll-area, nog steeds performant |
| 20–30 | Maplijst-response groeit: elke niet-lege waarde voegt tot 512 tekens per bestand toe |
| 30+ | Overweeg weergaven te gebruiken om subsets te tonen |

### Filter-performance vs. kolom-aantal (API)

Voor het endpoint `sorted-file-ids` voegt elke **actieve filter** één SQL-JOIN-operatie toe aan de query. Dat is daar de primaire scaling-factor voor kolom-aantal (het grid in de Files-app filtert in de browser en voegt geen JOINs toe):

| Actieve filters | SQL-JOINs | Performance-impact |
|-----------------|-----------|---------------------|
| 1–5 | 2–6 | Verwaarloosbaar met indexen |
| 5–10 | 6–11 | Minimaal, < 200ms bij 100K records |
| 10–15 | 11–16 | Merkbaar, queries kunnen 500ms overschrijden |
| 15+ | 16+ | Niet aanbevolen, overweeg herstructurering |

> **Vergelijking**: SharePoint blokkeert queries wanneer meer dan 12 join-operaties vereist zijn (triggered bij 8+ lookup/persoon/workflow-kolommen). MetaVox blokkeert niet maar wordt langzaam boven ~15 actieve filters.

## Gelijktijdige gebruikers

### Per-gebruiker server-belasting

MetaVox injecteert initialisatie-data (groupfolders, en velden en weergaven per teammap) inline in de HTML via Nextcloud's `IInitialState`-mechanisme. Dit elimineert aparte API-calls voor de initiële page load. Navigeren naar een andere teammap gebruikt dezelfde data; een `/api/init`-call is alleen de fallback als die ontbreekt.

Elke gebruiker die een folder met metadata-kolommen opent genereert:

| Operatie | Extra API-calls | Database-queries |
|----------|-----------------|------------------|
| Folder open (eerste laad) | 0 (inline via IInitialState) | Uitgevoerd tijdens PHP page-render |
| Folder open (navigatie) | 0–1 (`/api/init` als fallback) | ~4 als die wordt aangeroepen (groupfolders, velden, weergaven, permissies) |
| Metadata-waarden (alle bestanden) | 0 (met de PROPFIND van de Files-app) | 1 per 1.000 bestanden, binnen de PROPFIND |
| Waarden die de maplijst miste | 0–1 per 200 bestanden | 1 per request |
| Filter-waarden (bij dropdown open) | 0 (in de browser berekend uit geladen waarden) | 0 |
| **Totaal per folder-open** | **0–1, plus fallbacks** | **~1–5** |

### Data-consistentie & real-time sync

Wanneer **notify_push** is geïnstalleerd en geconfigureerd, ondersteunt MetaVox real-time samenwerking:

- **Cell-locking**: wanneer een gebruiker een cel begint te bewerken, wordt die voor andere gebruikers gelockt (30-seconden TTL via Redis). Andere gebruikers zien een lock-indicator met de editor-naam.
- **Real-time push**: wijzigingen worden via WebSocket (notify_push) gemeld aan de gebruikers die de teammap open hebben: alleen bestands-id's, geen waarden. Hun browser laadt die bestanden opnieuw via de API. Geen polling nodig.
- **Aanwezigheids-tracking**: een teammap openen registreert de gebruiker; de heartbeat van de Gebruikersstatus-app van Nextcloud houdt de registratie in leven, en naar een andere teammap gaan of het tabblad sluiten beëindigt hem. Zonder de Gebruikersstatus-app blijft een registratie 12 uur staan.
- **Graceful degradation**: zonder notify_push wint de laatste write; wijzigingen van anderen verschijnen na navigeren of vernieuwen. Cell-locks weigeren nog steeds een tweede bewerker.

Zie [Real-time sync](../features/real-time-sync.md) voor volledige details.

### Gelijktijdige-gebruikerscapaciteit

Capaciteit hangt sterk af van server-configuratie en folder-grootte:

#### Kleine folders (< 500 bestanden)

| Server-configuratie | Gelijktijdige gebruikers | Status |
|---------------------|---------------------------|--------|
| Single-core, 8 GB RAM | 10–15 | Goed |
| 4 cores, 16 GB RAM + Redis | 40–60 | Goed |
| 4 cores + aparte database | 80–100 | Goed |

#### Middelgrote folders (500–5.000 bestanden)

| Server-configuratie | Gelijktijdige gebruikers | Status |
|---------------------|---------------------------|--------|
| Single-core, 8 GB RAM | 3–5 | Traag |
| 4 cores, 16 GB RAM + Redis | 15–25 | Acceptabel |
| 4 cores + aparte database | 30–50 | Goed |

#### Grote folders (5.000+ bestanden)

| Server-configuratie | Gelijktijdige gebruikers | Status |
|---------------------|---------------------------|--------|
| Single-core, 8 GB RAM | 1–2 | Zeer traag |
| 4 cores, 16 GB RAM + Redis | 5–10 | Traag |
| 4 cores + aparte database | 10–20 | Acceptabel |

> **Let op**: PHP verwerkt requests single-threaded. Op een single-core server worden requests sequentieel in een queue verwerkt. Meerdere cores laten PHP-FPM requests parallel afhandelen.

## Hardware-scaling-impact

### Wat helpt

| Upgrade | Impact | Waarom |
|---------|--------|--------|
| **Database op aparte server** | Hoog | SQL-queries concurreren niet meer met PHP voor CPU/RAM |
| **RAM (8 → 16+ GB)** | Hoog | Grotere database buffer pool, meer PHP-workers |
| **SSD/NVMe-storage** | Hoog | Snellere database-I/O, vooral voor JOINs |
| **Redis/APCu-caching** | Hoog | MetaVox cachet veld-definities en weergaven (600s TTL), API-filterwaarden (300s) en API-sorteer-/filterresultaten (30s); aanwezigheid en cell-locks hebben het nodig |
| **Meer CPU-cores** | Middel | Helpt met gelijktijdige gebruikers (parallel PHP-FPM-workers), beperkte winst voor single-user performance |

### Wat niet helpt

| Wijziging | Waarom beperkt effect |
|-----------|------------------------|
| Meer CPU-cores (single user) | PHP is single-threaded per request |
| Meer netwerk-bandbreedte | Responses zijn klein: alleen niet-lege waarden, maximaal 512 tekens per waarde in de maplijst |
| CDN / edge caching | Metadata is dynamisch en gebruiker-specifiek |

### Geschatte praktische limieten per hardware-tier

| Hardware | Bestanden per weergave | Gelijktijdige gebruikers | Actieve filters |
|----------|------------------------|---------------------------|------------------|
| **Entry** (1 core, 8 GB, lokale DB) | ~5.000 | ~10 | ~5 |
| **Standaard** (4 cores, 16 GB, Redis) | ~8.000 | ~40 | ~10 |
| **Aanbevolen** (4 cores, 16 GB, aparte DB, Redis) | ~10.000–15.000 | ~80 | ~15 |

> **Herinnering**: de primaire bottleneck boven ~5.000 bestanden is Nextcloud's client-side bestandenlijst, niet MetaVox. Hardware-upgrades verbeteren MetaVox' backend-verwerking maar kunnen de browser-side-beperking niet wegnemen.

## Best practices

1. **Gebruik gefilterde weergaven** om het aantal zichtbare bestanden per weergave onder 5.000 te houden. De MetaVox-backend kan miljoenen metadata-records opslaan en bevragen — de limiet zit in het browser-rendering.

2. **Zorg dat database-indexen actief zijn.** De indexen toegevoegd in migratie `Version20250101000010` verbeteren filter-query-performance met 40–100×. Verifieer dat ze bestaan op de `metavox_file_gf_meta`-tabel.

3. **Schakel Redis of APCu-caching in** in je Nextcloud-configuratie. MetaVox gebruikt gedistribueerde caching voor veld-definities en weergaven (600s TTL) en voor API-filterresultaten (30–300s); met meer dan één webserver hebben aanwezigheid en cell-locks een gedeelde cache zoals Redis nodig. Metadata-waarden worden altijd uit de database gelezen.

4. **Beperk voor de `sorted-file-ids`-API actieve filters tot 10 of minder.** Elke filter voegt een SQL-JOIN toe. Performance blijft uitstekend tot ~10 filters maar degradeert boven 15.

5. **Gebruik de weergaven-feature** om alleen relevante kolommen per use case te tonen, in plaats van alle velden tegelijk. Dit verbetert rendering-performance.

6. **Voor multi-user bewerken**, installeer notify_push voor real-time sync en cell-locking. Zonder geldt last-write-wins.

7. **Overweeg folder-structuur.** Grote collecties splitsen over meerdere folders (elk onder 5.000 bestanden) is effectiever dan alleen vertrouwen op hardware-upgrades.
