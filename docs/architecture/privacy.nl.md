# MetaVox privacy & beveiliging

Dit document beschrijft hoe MetaVox omgaat met data-privacy en beveiliging, om organisaties te helpen compliance met AVG en andere databescherming-eisen te evalueren.

## Privacy by design

MetaVox is gebouwd met privacy als kernprincipe:

### On-premise data-opslag

- **Metadata blijft lokaal**: alle metadata wordt opgeslagen in je Nextcloud-database — geen metadata verlaat je infrastructuur
- **Geen cloud-dependencies**: kern-functionaliteit werkt volledig binnen je Nextcloud-instantie
- **AI-autofill gebruikt je Nextcloud-AI-provider**: bestandsinhoud gaat naar de Task Processing-provider die de beheerder heeft ingesteld, en die kan extern zijn. Zie [AI-autofill](../features/ai-autofill.md)
- **Telemetrie, standaard aan**: eens per dag stuurt MetaVox gebruiksstatistieken naar de VoxCloud-licentieserver. Een beheerder kan dit uitzetten. Zie [Telemetrie](../admin/telemetry.md) voor details
- **Abonnementscontrole**: alleen met een abonnementssleutel controleert MetaVox de sleutel en meldt het gebruik eens per dag aan dezelfde server, ook als telemetrie uit staat. Zie [Support & abonnementen](../admin/licensing.md#wat-een-abonnementssleutel-verstuurt)
- **Air-gapped-compatibel**: zet telemetrie uit, laat de abonnementssleutel leeg, en zet AI uit of gebruik een AI-provider binnen je eigen netwerk. Zie [Externe verbindingen](#externe-verbindingen)

### Data-locality

Alle data blijft binnen je infrastructuur:

```
┌─────────────────────────────────────┐
│       Je eigen infrastructuur        │
│                                      │
│  ┌────────────────────────────────┐  │
│  │       Nextcloud-server         │  │
│  │  ┌──────────────────────────┐  │  │
│  │  │      MetaVox-app         │  │  │
│  │  │  - Metadata blijft lokaal│  │  │
│  │  │  - Optionele telemetrie  │  │  │
│  │  └──────────────────────────┘  │  │
│  │              │                  │  │
│  │  ┌───────────▼──────────────┐  │  │
│  │  │   Je database-server     │  │  │
│  │  │   - Metadata opgeslagen  │  │  │
│  │  │   - Onder jouw controle  │  │  │
│  │  └──────────────────────────┘  │  │
│  └────────────────────────────────┘  │
└─────────────────────────────────────┘
```

> **Let op**: wanneer telemetrie aan staat (standaard), stuurt MetaVox aggregaat-statistieken (aantal gebruikers, aantal velden, versie-nummers) naar een externe server. Geen metadata-waarden, bestandsnamen of gebruikersnamen worden verstuurd. Heb je een abonnementssleutel ingevoerd, dan gaat die sleutel ook mee. Telemetrie kan volledig uit in admin-instellingen. Zie [Telemetrie](../admin/telemetry.md).

## Data-opslag

### Wat MetaVox opslaat

| Data-type | Opslag-locatie | Doel |
|-----------|----------------|------|
| Veld-definities | `metavox_gf_fields`-tabel | Schema voor metadata-velden |
| Veld-toewijzingen | `metavox_gf_assigns`-tabel | Welke velden elke teammap gebruikt |
| Metadata-waarden | `metavox_file_gf_meta`-tabel | Daadwerkelijke metadata-waarden per document, met tijdstip van aanmaken en laatste wijziging |
| Teammap-waarden | `metavox_gf_metadata`-tabel | Metadata van de teammap zelf |
| Zoekwoorden | `metavox_search_words`-tabel | Woorden uit tekstwaarden, per teammap, voor vrij zoeken |
| Weergaven | `metavox_gf_views`-tabel | Opgeslagen weergaven per teammap |
| Beheerrechten | `metavox_permissions`-tabel | Gebruikers en groepen die de metadata van een teammap mogen beheren |
| Bewaarde zoekopdrachten | Gebruikersvoorkeuren van Nextcloud (app `metavox`, sleutel `saved_searches`) | De eigen bewaarde zoekopdrachten van elke gebruiker (naam, veld, waarde, teammap), als mappen in Bestanden; maximaal 50 per gebruiker. Niemand anders ziet ze |
| Abonnementssleutel en instellingen | App-configuratie van Nextcloud (app `metavox`) | De abonnementssleutel, als die is ingevoerd, en de beheerinstellingen |
| Dagelijkse back-ups | App-data (`appdata_*/metavox/backups/`) | Gzip-kopie van velden, teammap-waarden en bestandswaarden; de laatste 7 blijven bewaard |
| Aanwezigheid en cell-locks | Gedistribueerde cache van Nextcloud | Gebruikers-id's van wie een teammap open heeft (minuten tot uren) en wie een cel bewerkt (30 seconden) |

### Wat MetaVox NIET opslaat

- Bestandsinhoud (alleen metadata over bestanden)
- Gebruikersgegevens
- Externe referenties
- Analytics- of tracking-data (telemetrie verstuurt alleen aggregaat-aantallen, geen individuele data)

### Data-relatie met bestanden

Metadata wordt opgeslagen via bestand-ID-referentie, niet ingebed in bestanden:

- Originele bestanden blijven onveranderd
- Metadata kan worden verwijderd zonder bestanden te raken
- Wordt een bestand definitief verwijderd, dan wordt de metadata automatisch opgeruimd (zie [Data-bewaartermijn](#data-bewaartermijn))

## AVG-compliance

MetaVox ondersteunt AVG-compliance op verschillende manieren:

### Compliance mogelijk maken

- **Document-classificatie**: track welke documenten persoonsgegevens bevatten
- **Verwerkingsgrondslagen**: leg rechtsgrond voor data-verwerking vast
- **Bewaartermijnen**: definieer en volg data-bewaar-eisen
- **Verantwoording**: toon compliance aan via metadata

### Compliance-templates

Voor-gebouwde templates helpen organisaties aan eisen voldoen:

- `avg-compliance.json` — AVG/GDPR-velden
- `woo-compliance.json` — Wet Open Overheid
- `archiefwet-compliance.json` — Archiefwet

Zie [Compliance-templates](../admin/compliance-templates.md) voor details.

### Rechten van betrokkenen

MetaVox-metadata kan helpen bij het vervullen van betrokkenen-verzoeken:

| Recht | Hoe MetaVox helpt |
|-------|-------------------|
| Recht op inzage | Documenten met persoonsgegevens vinden |
| Recht op vergetelheid | Documenten identificeren voor verwijdering |
| Recht op rectificatie | Documenten lokaliseren die updates nodig hebben |

## Beveiligingsmodel

### Authenticatie

MetaVox gebruikt Nextcloud's authenticatie:

- Sessie-gebaseerde authenticatie (web-interface)
- App-wachtwoorden (API-toegang)
- Tweefactor-authenticatie (indien ingeschakeld in Nextcloud)

### Autorisatie

Permissies worden overgeërfd van Nextcloud:

- Leesrecht op het bestand (inclusief teammap-ACL): metadata bekijken
- Bewerkrecht op het bestand: document-metadata bewerken
- Lidmaatschap van de teammap: de weergaven en velden ervan zien
- Nextcloud-beheerder: veld-definities aanmaken, wijzigen en verwijderen
- Beheerders en managers van een teammap: velden toewijzen en standaardwaarden instellen

### Input-validatie

Alle gebruikers-input wordt gevalideerd:

- Veldtypen handhaven data-format (datum, getal, etc.)
- Opties worden gevalideerd tegen gedefinieerde keuzes
- SQL-injection voorkomen via geparameteriseerde queries

### API-beveiliging

- OCS-endpoints vereisen de `OCS-APIRequest`-header; de meeste webroutes vereisen het CSRF-token van Nextcloud
- Authenticatie vereist voor alle endpoints
- Elke route legt vast welk recht hij nodig heeft, gecontroleerd voordat het request wordt afgehandeld; een route zonder regel wordt geweigerd
- MetaVox stelt zelf geen rate limits in

## Audit-overwegingen

### Wat kan worden geaudit

- Metadata-veld-definities (wanneer aangemaakt en laatst gewijzigd)
- Metadata-waarden (wanneer aangemaakt en laatst gewijzigd; niet door wie)
- API-toegang (via Nextcloud-logs)

MetaVox schrijft metadata-wijzigingen niet naar de activiteitenstroom of de audit-log van Nextcloud.

### Aanbevelingen

1. Schakel Nextcloud activity-logging in
2. Configureer log-bewaartermijn volgens je eisen
3. Regelmatige database-back-ups (inclusief metadata)

## Data-bewaartermijn

### Automatische cleanup

Metadata van verwijderde bestanden wordt op twee manieren opgeruimd:

- **Direct**: verlaat een teammap-bestand de bestandscache van Nextcloud, dan worden de waarden en zoekwoorden aan het eind van dat request verwijderd. Dat gebeurt als het bestand definitief wordt verwijderd (prullenbak geleegd of verlopen) en als het naar een andere opslag gaat, zoals een andere teammap of persoonlijke bestanden. Een bestand naar de prullenbak verplaatsen houdt de metadata, dus een teruggezet bestand houdt die ook. Bestanden buiten teammappen worden genegeerd.
- **Dagelijks**: een achtergrondtaak (`CleanupDeletedMetadata`) verwijdert de waarden en zoekwoorden van bestanden die niet meer bestaan, tot 10.000 bestanden per run. Die vangt op wat de directe opruiming miste.

Een veld verwijderen haalt de waarden ervan in elke teammap weg; MetaVox staat dat pas toe als geen teammap het veld nog gebruikt.

Verwijderde metadata blijft in de dagelijkse back-ups staan tot die worden doorgeschoven (er blijven er 7 bewaard). Zie [Back-up & herstel](../admin/backup-restore.md).

### Handmatige cleanup

Beheerders kunnen:

- Individuele metadata via UI verwijderen
- De metadata van geselecteerde bestanden wissen (bulkbewerking)
- Bulk-verwijdering via API (`batch-delete`)

## Encryptie

### At rest

Metadata wordt opgeslagen in Nextcloud's database:

- Gebruik database-encryptie indien vereist
- Nextcloud server-side encryptie geldt niet voor metadata (alleen bestandsinhoud)

### In transit

- HTTPS-encryptie voor al het webverkeer
- API-calls gebruiken dezelfde HTTPS-bescherming

## Third-party dependencies

MetaVox heeft minimale runtime-dependencies:

| Dependency | Type | Doel |
|------------|------|------|
| Nextcloud Server | Vereist | Platform |
| Group Folders | Vereist | Team folder-ondersteuning |
| notify_push | Optioneel | Real-time sync en cell-locking |
| Redis | Optioneel | Caching, aanwezigheids-tracking, cell-locking |
| Gebruikersstatus | Optioneel | Heartbeat die de aanwezigheid bijhoudt |
| Nextcloud AI (Task Processing) | Optioneel | AI-autofill |
| Vue.js | Gebundeld | Frontend-framework |
| PHP-bibliotheken | Gebundeld | Backend-functionaliteit |

Geen CDN-resources, geen externe fonts of scripts.

## Externe verbindingen

MetaVox maakt alleen in deze gevallen verbinding met een server buiten je Nextcloud:

| Verbinding | Wanneer | Naar | Wat er meegaat |
|------------|---------|------|----------------|
| Telemetrie | Eens per dag zolang **Anonieme gebruiksstatistieken delen** aan staat, en dat is **standaard zo**; ook als een beheerder op **Rapport nu verzenden** klikt | VoxCloud-licentieserver (`licenses.voxcloud.nl`) | Aantallen, versies en serverconfiguratie, plus de abonnementssleutel als je die hebt ingevoerd. Zie [Telemetrie](../admin/telemetry.md) |
| Abonnementscontrole | Eens per dag, en als een beheerder een sleutel opslaat. Alleen met een abonnementssleutel, of telemetrie nu aan of uit staat | VoxCloud-licentieserver (`licenses.voxcloud.nl`) | De sleutel, de gehashte instantie-URL en gebruiksaantallen. Zie [Support & abonnementen](../admin/licensing.md#wat-een-abonnementssleutel-verstuurt) |
| AI-autofill | Als een gebruiker op **Genereren met AI** klikt | De AI-provider waarmee Nextcloud is ingesteld. MetaVox geeft de taak aan Nextcloud; de provider kan extern zijn | De te vullen velden en de naam en tekst van het bestand; van een bestand waarvan de tekst niet te lezen is de naam, het type, de grootte en het pad. Zie [AI-autofill](../features/ai-autofill.md) |

Met telemetrie uit, geen abonnementssleutel, en AI uit of binnen je eigen netwerk maakt MetaVox geen verbindingen buiten je infrastructuur.

## Aanbevelingen voor gevoelige omgevingen

Voor high-security deployments:

1. **Netwerk-isolatie**: deploy Nextcloud op geïsoleerd netwerk
2. **Database-encryptie**: schakel encryptie op database-niveau in
3. **Toegangs-logging**: schakel uitgebreide audit-logging in
4. **Regelmatige back-ups**: include database in back-up-strategie
5. **Toegangs-review**: review periodiek wie admin-toegang heeft

## Zie ook

- [Architectuur-overzicht](overview.md) — Technische architectuur
- [Compliance-templates](../admin/compliance-templates.md) — Voor-gebouwde schema's
- [Permissies](../admin/permissions.md) — Toegangscontrole
- [Telemetrie](../admin/telemetry.md) — Gebruiks-rapportage-instellingen
