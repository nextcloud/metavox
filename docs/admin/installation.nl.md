# MetaVox installatie

Deze gids beschrijft het installeren en configureren van MetaVox voor je Nextcloud-instantie.

## Vereisten

### Verplicht

| Dependency | Waarom |
|------------|--------|
| **Nextcloud 31 t/m 35** | Platform. Dit zijn de versies die `appinfo/info.xml` opgeeft |
| **PHP 8.1+** | Platform |
| **Teammappen**-app (`groupfolders`) | MetaVox voegt metadata toe aan Team folders. Zonder de app doet MetaVox niets |
| **MySQL, MariaDB of PostgreSQL** | Daarvoor is MetaVox gebouwd en getest. SQLite werkt om uit te proberen, maar het zoekfilter leest dan elke waarde in plaats van de zoekindex |
| **Beheerderstoegang** | Veld-definities en app-instellingen vereisen admin-rechten |

### Aanbevolen

| Dependency | Waarom | Zonder |
|------------|--------|--------|
| **Systeem-cron** voor achtergrondtaken | Achtergrondtaken bouwen de zoekindex op en vullen standaardwaarden in | Met AJAX of Webcron draaien taken alleen als iemand een pagina open heeft. De zoekindex en de standaardwaarden lopen dan achter |
| **Gebruikersstatus**-app (`user_status`) | De heartbeat ervan vertelt MetaVox wie een Team folder nog open heeft | Wie de Team folder gesloten heeft, blijft tot 12 uur als aanwezig zichtbaar |
| **Redis** (gedeelde cache) | MetaVox gebruikt Redis voor metadata-caching (30s TTL), veld-definitie-caching (600s TTL), aanwezigheids-tracking en cell-locking | MetaVox valt terug op APCu, dat per webserver lokaal is. Op één webserver werken aanwezigheid en cell-locking gewoon. Met meer dan één webserver hebben ze een gedeelde cache nodig. Performance met veel gelijktijdige gebruikers is verminderd |

### Optioneel

| Dependency | Waarom | Zonder |
|------------|--------|--------|
| **Client Push**-app (`notify_push`) | Maakt real-time metadata-sync tussen gebruikers mogelijk. Als één gebruiker metadata bewerkt, zien anderen de wijziging direct via WebSocket | Gebruikers zien elkaars wijzigingen pas na verversen. Cell-locking-indicators worden niet getoond. Last-write-wins bij gelijktijdige edits |
| **Nextcloud AI-provider** (bv. LLM2) | Drijft de knop **Genereren met AI**, die metadata-waarden suggereert op basis van bestandsinhoud | De knop **Genereren met AI** verschijnt niet. Andere features werken normaal |
| **Assistant**-app | MetaVox gebruikt de bibliotheken ervan om de tekst van PDF-, DOCX- en ODT-bestanden te lezen voor AI-suggesties | Voor die bestanden krijgt de AI alleen de bestandsnaam, het type, de grootte en het pad |

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

Op één webserver doet APCu het werk van Redis voor cell-locking en aanwezigheid. De Redis-kolom telt zodra je meer dan één webserver draait.

## Installatie

### Via App Store (aanbevolen)

1. Log in als beheerder
2. Ga naar **Apps** (klik op je profiel-icoon → Apps)
3. Zoek op "MetaVox" in de zoekbalk
4. Klik **Download en inschakelen**

### Handmatige installatie

1. Download de laatste release van [GitHub](https://github.com/nextcloud/metavox/releases)
2. Pak uit naar `nextcloud/apps/metavox`
3. Ga naar **Apps** en schakel MetaVox in

## Setup-checks

MetaVox voegt zes checks toe aan de setup-checks van Nextcloud zelf. Je vindt ze onder **Beheerinstellingen → Overzicht**, in de groep **Systeem**, of op de commandline:

```bash
sudo -u www-data php occ setupchecks
```

| Check | Waar hij naar kijkt | Wanneer hij waarschuwt | Zo los je het op |
|-------|---------------------|------------------------|------------------|
| **MetaVox-teammappen** | Of de Teammappen-app geïnstalleerd is | Fout: "De app Teammappen staat uit. MetaVox voegt metadata toe aan teammappen en doet zonder die app niets." | Schakel de Teammappen-app in: `occ app:enable groupfolders` |
| **MetaVox-achtergrondtaken** | De modus van de achtergrondtaken, en wanneer cron voor het laatst liep | Waarschuwing: "Achtergrondtaken draaien via AJAX/Webcron …", of "Achtergrondtaken liepen meer dan een uur geleden voor het laatst …" | Kies onder **Beheerinstellingen → Basisinstellingen → Achtergrondtaken** voor **Cron** en zet een crontab-regel voor de webserver-gebruiker, zoals `*/5 * * * * php -f /var/www/nextcloud/cron.php`. Staat cron al aan, controleer dan of de crontab-regel nog draait |
| **MetaVox live-updates** | Of Client Push (`notify_push`) geïnstalleerd en ingesteld is | Info: "De app Client Push (notify_push) is niet ingesteld. Wijzigingen van anderen verschijnen dan pas na verversen." | Installeer Client Push en draai `occ notify_push:setup`. Optioneel: zonder werkt MetaVox, maar zonder live-updates |
| **MetaVox-aanwezigheid** | Of de Gebruikersstatus-app geïnstalleerd is | Info: "De app Gebruikersstatus staat uit. Wie een teammap open heeft, blijft dan tot 12 uur zichtbaar." | Schakel de Gebruikersstatus-app in: `occ app:enable user_status` |
| **MetaVox gedeelde cache** | Of `memcache.distributed` in `config.php` een gedeelde cache is, niet leeg of APCu | Info: "Er is geen gedeelde cache zoals Redis ingesteld. Met meer dan één webserver hebben aanwezigheid en bewerkingslocks er een nodig." | Zet Redis op en stel `'memcache.distributed' => '\OC\Memcache\Redis'` in `config.php` in. Met één webserver kun je dit laten zoals het is |
| **MetaVox-zoekindex** | Of de taak die de zoekindex vult nog loopt | Info: "De zoekindex wordt opgebouwd: X van Y bestanden. Tot die tijd vindt zoeken op vrije tekst alleen de bestanden die al klaar zijn." | Niets te doen: de taak maakt zichzelf af, zolang de achtergrondtaken draaien. Bewegen de aantallen niet, kijk dan naar de check voor achtergrondtaken |

## Initiële configuratie

Na installatie:

1. Ga naar **Beheerinstellingen → MetaVox**
2. De pagina heeft een rij onderdelen. Je ziet er steeds één:
   - **Teammappen** — Velden definiëren voor Team folders zelf
   - **Bestanden** — Velden definiëren voor bestanden en mappen in Team folders
   - **Per map** — Per Team folder: welke velden hij gebruikt, hun standaardwaarden en de eigen metadata van de Team folder
   - **Zoeken** — Het metadatafilter in de zoekbalk, en filters als map bewaren in Bestanden
   - **Algemeen** — welke links URL-velden nemen, en AI-suggesties aan of uit
   - **Rechten** — Groepen het MetaVox-beheerrecht geven
   - **Back-up** — Metadata back-uppen en herstellen
   - **Ondersteuning** — Abonnementssleutel en anonieme gebruiksstatistieken

### Velden aanmaken

Velden definieer je één keer voor de hele instantie, in het onderdeel **Teammappen** of **Bestanden**. Daarna ken je ze in het onderdeel **Per map** toe aan Team folders.

1. Open het onderdeel **Teammappen** of **Bestanden**
2. Klik **Nieuw veld**
3. Configureer het veld:
   - **Veldnaam**: interne identifier. Alleen kleine letters (a-z), cijfers (0-9) en underscores (_), beginnend met een letter. Typ de naam zonder voorvoegsel: MetaVox zet `gf_` voor Team folder-velden en `file_gf_` voor bestandsvelden. `department` in het onderdeel **Teammappen** wordt dus opgeslagen als `gf_department`
   - **Veld Label**: weergave-naam getoond aan gebruikers
   - **Veldtype**: tekst, select, datum, etc. Zie [Veldtypen](../user/field-types.md)
   - **Beschrijving**: getoond aan gebruikers naast het veld
   - **Verplicht veld**: of het veld moet worden ingevuld
   - **Dropdown-opties**: voor select- en multi-select-velden. Typ één waarde per regel en klik **Optie toevoegen** voor elke volgende waarde. Een select-veld heeft minstens één optie nodig
   - **Tijd meenemen**: voor datumvelden, om een datum en een tijd op te slaan
4. Klik **Veld aanmaken**

Via de API stuur je de volledige naam mét voorvoegsel (`gf_…` of `file_gf_…`). MetaVox weigert een naam zonder het voorvoegsel van zijn soort.

### Velden wijzigen en verwijderen

Klik op een veld, of kies **Veld bewerken** in het menu ervan, om het te wijzigen. Een veld is in gebruik zolang het aan minstens één Team folder is toegekend. Zolang het in gebruik is:

- liggen de **Interne veldnaam** en het type vast ("Ligt vast zolang het veld in gebruik is")
- kan het **Verplicht** worden: bestanden zonder waarde zijn dan gemarkeerd in het raster, en krijgen er een bij hun volgende opslag
- kan het niet worden verwijderd. Het menu toont **Kan niet verwijderen (in gebruik)**

Wil je een veld verwijderen dat in gebruik is, haal het dan eerst bij elke Team folder weg in het onderdeel **Per map**.

Om meerdere velden tegelijk te verwijderen, vink je ze aan in de lijst en klik je **Selectie verwijderen**. **Selecteer alle verwijderbare** vinkt elk zichtbaar veld aan dat niet in gebruik is. Een veld verwijderen haalt de waarden ervan weg in elke Team folder en is niet terug te draaien. Is een veld intussen in gebruik genomen, dan slaat MetaVox het over en meldt dat.

### Velden toekennen aan een Team folder

1. Open het onderdeel **Per map**
2. Klik in het menu van een Team folder op **Velden configureren**
3. Vink de Team folder-velden en bestandsvelden aan die deze Team folder gebruikt. Voor een bestandsveld kun je een standaardwaarde instellen
4. Om bestaande bestanden zonder waarde de standaardwaarde te geven, klik je **Standaardwaarden nu toepassen**

![Team folder-metadata-setup](../../screenshots/Manage%20team%20metadata.png)

### Team folder-metadata

De waarden van Team folder-velden horen bij de Team folder zelf. Je bewerkt ze hier:

- Beheerders: onderdeel **Per map** → menu van de Team folder → **Metadata bewerken**
- Gebruikers met het MetaVox-beheerrecht: **Persoonlijke instellingen → MetaVox** → menu van de Team folder → **Metadata bewerken**

In de Nextcloud-zijbalk zijn de waarden altijd alleen-lezen, ook voor beheerders. Ze verschijnen wanneer iemand de Team folder selecteert, en als **Teammap-metadata** boven de metadata van elk bestand erin.

![Team folder-metadata in de Nextcloud-zijbalk](../../screenshots/Teamfolder%20metadata.png)

### Bestandsmetadata

Bestandsvelden configureer je op dezelfde manier in het onderdeel **Bestanden**, maar ze gelden voor individuele bestanden en mappen in plaats van de hele Team folder. Gebruikers bewerken de waarden in de Bestanden-app.

## Import/Export

### Veld-definities importeren

1. Ga naar **Beheerinstellingen → MetaVox** en open het onderdeel **Teammappen** of **Bestanden**
2. Open het menu naast **Nieuw veld** en klik **Importeren uit JSON …**
3. Selecteer je JSON-bestand
4. Bekijk het **Import Voorbeeld** en klik **Velden importeren**

Geïmporteerde velden komen in het onderdeel waarin je importeert. Namen zonder voorvoegsel krijgen het voorvoegsel van dat onderdeel.

### Veld-definities exporteren

1. Open het onderdeel waarvan je de velden wilt exporteren
2. Open het menu naast **Nieuw veld** en klik **Exporteren naar JSON**
3. Gebruik dit bestand om instellingen op andere instanties te repliceren

De export bevat de velden van dat onderdeel, met hun namen zonder voorvoegsel.

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

Begin bij de [setup-checks](#setup-checks): die dekken de meest voorkomende oorzaken.

### MetaVox-sectie niet zichtbaar

- Verifieer dat je een bestand in een Team folder bekijkt (niet een persoonlijke map)
- Controleer dat er velden aan deze Team folder zijn toegekend (**Per map** → **Velden configureren**)
- Verifieer dat de gebruiker minstens leesrechten op de folder heeft

### Kan metadata niet bewerken

- Gebruiker heeft bewerk-rechten op het document nodig
- MetaVox heeft geen aparte lees- en bewerkrechten: bestandsmetadata zien en wijzigen volgt de eigen rechten van Nextcloud op het bestand. Het enige eigen recht van MetaVox is het beheerrecht. Zie [Permissies](permissions.md)
- Controleer of er velden aan deze Team folder zijn toegekend

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
