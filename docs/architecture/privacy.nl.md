# MetaVox privacy & beveiliging

Dit document beschrijft hoe MetaVox omgaat met data-privacy en beveiliging, om organisaties te helpen compliance met AVG en andere databescherming-eisen te evalueren.

## Privacy by design

MetaVox is gebouwd met privacy als kernprincipe:

### On-premise data-opslag

- **Metadata blijft lokaal**: alle metadata wordt opgeslagen in je Nextcloud-database — geen metadata verlaat je infrastructuur
- **Geen cloud-dependencies**: kern-functionaliteit werkt volledig binnen je Nextcloud-instantie
- **Optionele telemetrie**: anonieme gebruiksstatistieken kunnen worden verstuurd om MetaVox te helpen verbeteren (standaard aan, kan uit). Zie [Telemetrie](../admin/telemetry.md) voor details
- **Air-gapped-compatibel**: schakel telemetrie uit voor volledig air-gapped bedrijfsvoering

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

> **Let op**: wanneer telemetrie aan staat (standaard), stuurt MetaVox anonieme aggregaat-statistieken (aantal gebruikers, aantal velden, versie-nummers) naar een externe server. Geen metadata-waarden, bestandsnamen of gebruikersnamen worden verstuurd. Telemetrie kan volledig uit in admin-instellingen. Zie [Telemetrie](../admin/telemetry.md).

## Data-opslag

### Wat MetaVox opslaat

| Data-type | Opslag-locatie | Doel |
|-----------|----------------|------|
| Veld-definities | `metavox_gf_fields`-tabel | Schema voor metadata-velden |
| Metadata-waarden | `metavox_file_gf_meta`-tabel | Daadwerkelijke metadata-waarden per document |

### Wat MetaVox NIET opslaat

- Bestandsinhoud (alleen metadata over bestanden)
- Gebruikersgegevens
- Externe referenties
- Analytics- of tracking-data (telemetrie verstuurt alleen aggregaat-aantallen, geen individuele data)

### Data-relatie met bestanden

Metadata wordt opgeslagen via bestand-ID-referentie, niet ingebed in bestanden:

- Originele bestanden blijven onveranderd
- Metadata kan worden verwijderd zonder bestanden te raken
- Bestanden kunnen worden verwijderd (metadata wordt verweesd maar niet automatisch verwijderd)

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

- Leesrechten: metadata bekijken
- Schrijfrechten: document-metadata bewerken
- Admin-rechten: veld-definities configureren

### Input-validatie

Alle gebruikers-input wordt gevalideerd:

- Veldtypen handhaven data-format (datum, getal, etc.)
- Opties worden gevalideerd tegen gedefinieerde keuzes
- SQL-injection voorkomen via geparameteriseerde queries

### API-beveiliging

- `OCS-APIRequest`-header vereist
- Rate limiting via Nextcloud
- Authenticatie vereist voor alle endpoints

## Audit-overwegingen

### Wat kan worden geaudit

- Metadata-veld-definities (wie aangemaakt, wanneer)
- Metadata-waarde-wijzigingen (via Nextcloud activity-log indien geconfigureerd)
- API-toegang (via Nextcloud-logs)

### Aanbevelingen

1. Schakel Nextcloud activity-logging in
2. Configureer log-bewaartermijn volgens je eisen
3. Regelmatige database-back-ups (inclusief metadata)

## Data-bewaartermijn

### Automatische cleanup

MetaVox verwijdert metadata niet automatisch. Wanneer bestanden worden verwijderd:

- Metadata blijft in database (verweesd)
- Handmatige cleanup kan nodig zijn bij grootschalige verwijderingen

### Handmatige cleanup

Beheerders kunnen:

- Individuele metadata via UI verwijderen
- Bulk-verwijdering via API
- Alle metadata voor een team folder wissen

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
| Vue.js | Gebundeld | Frontend-framework |
| PHP-bibliotheken | Gebundeld | Backend-functionaliteit |

Geen CDN-resources, geen externe fonts of scripts. De enige externe verbinding is optionele telemetrie (kan uit).

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
