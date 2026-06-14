# MetaVox installatie

Deze gids beschrijft het installeren en configureren van MetaVox voor je Nextcloud-instantie.

## Vereisten

### Verplicht

| Dependency | Waarom |
|------------|--------|
| **Nextcloud 31+** (getest tot 33) | Platform |
| **Group Folders**-app | MetaVox voegt metadata toe aan Team folders — zonder Group Folders zijn er geen Team folders |
| **Beheerderstoegang** | Veld-definities en app-instellingen vereisen admin-rechten |

### Aanbevolen

| Dependency | Waarom | Zonder |
|------------|--------|--------|
| **Redis** | MetaVox gebruikt Redis voor metadata-caching (30s TTL), veld-definitie-caching (600s TTL), aanwezigheids-tracking en cell-locking | MetaVox werkt nog steeds maar valt terug op APCu (lokale cache, niet gedeeld tussen PHP-workers). Cell-locking en aanwezigheids-tracking zijn uitgeschakeld. Performance met meerdere gelijktijdige gebruikers is verminderd |

### Optioneel

| Dependency | Waarom | Zonder |
|------------|--------|--------|
| **notify_push**-app | Maakt real-time metadata-sync tussen gebruikers mogelijk. Als één gebruiker metadata bewerkt, zien anderen de wijziging direct via WebSocket | Gebruikers moeten handmatig vernieuwen om elkaars wijzigingen te zien. Cell-locking-indicators worden niet getoond. Last-write-wins bij gelijktijdige edits |
| **Nextcloud AI-provider** (bv. LLM2) | Drijft de AI-autofill-feature die metadata-waarden suggereert op basis van bestandsinhoud | AI-autofill-knop verschijnt niet. Andere features werken normaal |

### Feature-beschikbaarheid

| Feature | Basis-install | + Redis | + Redis + notify_push |
|---------|:-------------:|:-------:|:---------------------:|
| Metadata-velden & bewerken | Ja | Ja | Ja |
| Inline-grid-bewerken | Ja | Ja | Ja |
| Bulk-bewerken & CSV-export | Ja | Ja | Ja |
| Filter & sortering | Ja | Ja | Ja |
| Flow-integratie | Ja | Ja | Ja |
| Back-up & herstel | Ja | Ja | Ja |
| Metadata-caching (performance) | Alleen APCu | Gedistribueerd | Gedistribueerd |
| Cell-locking (conflictpreventie) | Nee | Nee | Ja |
| Aanwezigheids-tracking | Nee | Nee | Ja |
| Real-time sync (live updates) | Nee | Nee | Ja |

## Installatie

### Via App Store (aanbevolen)

1. Log in als beheerder
2. Ga naar **Apps** (klik op je profiel-icoon → Apps)
3. Zoek op "MetaVox" in de zoekbalk
4. Klik **Download en inschakelen**

### Handmatige installatie

1. Download de laatste release van [GitHub](https://github.com/voxcloud/metavox/releases)
2. Pak uit naar `nextcloud/apps/metavox`
3. Ga naar **Apps** en schakel MetaVox in

## Initiële configuratie

Na installatie:

1. Ga naar **Instellingen → MetaVox**
2. Je ziet twee tabs:
   - **Team folder-metadata** — Velden definiëren voor Team folders
   - **Document-metadata** — Velden definiëren voor individuele documenten

### Team folder-metadata opzetten

1. Selecteer een Team folder uit de dropdown
2. Klik **Veld toevoegen** om een nieuw metadata-veld aan te maken
3. Configureer het veld:
   - **Veldnaam**: interne identifier (lowercase, geen spaties)
   - **Veldlabel**: weergave-naam getoond aan gebruikers
   - **Veldtype**: tekst, select, datum, etc.
   - **Beschrijving**: help-tekst voor gebruikers
   - **Verplicht**: of het veld moet worden ingevuld
   - **Opties**: voor dropdown-velden, komma-gescheiden waarden

![Team folder-metadata-setup](../screenshots/Manage%20team%20metadata.png)

Eenmaal geconfigureerd verschijnen de team folder-metadata-velden direct in de Nextcloud-zijbalk wanneer iemand de team folder selecteert — beheerders zien een bewerkbaar paneel, eindgebruikers een read-only weergave (afhankelijk van de configuratie).

![Team folder-metadata in de Nextcloud-zijbalk](../screenshots/Teamfolder%20metadata.png)

### Document-metadata opzetten

Document-metadata-velden worden vergelijkbaar geconfigureerd, maar gelden voor individuele bestanden in plaats van de hele folder.

## Import/Export

### Veld-definities importeren

1. Ga naar **Instellingen → MetaVox → Team folder-metadata**
2. Klik **JSON-bestand selecteren** onder "Import & Export"
3. Selecteer je JSON-bestand
4. Bekijk de preview en bevestig

### Veld-definities exporteren

1. Configureer je velden zoals gewenst
2. Klik **Exporteren** om het JSON-bestand te downloaden
3. Gebruik dit bestand om instellingen op andere instanties te repliceren

### Compliance-templates gebruiken

MetaVox bevat kant-en-klare templates voor Nederlandse overheids-compliance:

| Template | Doel |
|----------|------|
| `avg-compliance.json` | AVG persoonsgegevens-classificatie |
| `woo-compliance.json` | Wet Open Overheid publicatie-status |
| `archiefwet-compliance.json` | Archiefwet bewaartermijnen |
| `overheid-compleet.json` | Gecombineerde compliance-velden |

Templates staan in `/templates/compliance/`. Zie [Compliance-templates](compliance-templates.md) voor details.

## MetaVox updaten

1. Ga naar **Apps**
2. Zoek MetaVox in je geïnstalleerde apps
3. Klik **Update** indien beschikbaar

Of update handmatig door de app-folder te vervangen door de nieuwe versie.

## Problemen oplossen

### MetaVox-sectie niet zichtbaar

- Verifieer dat je een bestand in een Team folder bekijkt (niet een persoonlijke map)
- Controleer dat de Team folder metadata-velden geconfigureerd heeft
- Verifieer dat de gebruiker minstens leesrechten op de folder heeft

### Kan metadata niet bewerken

- Gebruiker heeft bewerk-rechten op het document nodig
- Controleer gebruikersrechten — view, edit en manage-fields zijn aparte niveaus
- Controleer of velden gedefinieerd zijn voor deze Team folder

### Import faalt

- Verifieer dat het JSON-format correct is
- Check op dubbele veldnamen
- Zorg dat veldtypen geldig zijn

### MetaVox-instellingen niet zichtbaar

- Verifieer dat MetaVox aan staat in **Apps**
- Wis browser-cache en herlaad
- Controleer Nextcloud-logs op MetaVox-gerelateerde fouten

## Volgende stappen

- [Permissies](permissions.md) — Toegangscontrole configureren
- [Compliance-templates](compliance-templates.md) — Gebruik vooraf gebouwde templates
- [Flow-integratie](flow-integration.md) — Workflows automatiseren
- [Telemetrie](telemetry.md) — Gebruiks-rapportage-instellingen
- [Back-up & herstel](backup-restore.md) — Metadata back-uppen en herstellen
