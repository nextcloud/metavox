# Back-up & herstel

MetaVox maakt elke dag een back-up van zijn velden en metadatawaarden. Je kunt ook zelf een back-up maken, er een downloaden en er een terugzetten. Dat kunnen alleen Nextcloud-beheerders.

![Het onderdeel Back-up van de MetaVox-beheerpagina](../../screenshots/backup-restore.png)

## Wat een back-up bevat

Een back-up bevat drie tabellen:

| Tabel | Wat erin staat |
|-------|----------------|
| `metavox_gf_fields` | Velddefinities, voor Team folders en voor bestanden |
| `metavox_gf_metadata` | De metadata van Team folders zelf |
| `metavox_file_gf_meta` | De metadata van bestanden |

Een back-up bevat **niet**:

- Weergaven en hun markeerregels (`metavox_gf_views`)
- Welke velden elke Team folder gebruikt, en hun standaardwaarden (`metavox_gf_assigns`)
- Het MetaVox-beheerrecht (`metavox_permissions`)
- De zoekindex (`metavox_search_words`). Een herstel bouwt die opnieuw op uit de teruggezette waarden.
- De bewaarde zoekopdrachten van gebruikers (gebruikersvoorkeuren van Nextcloud)
- Beheerinstellingen en de abonnementssleutel (app-configuratie van Nextcloud)

Wil je die ook bewaren, maak dan een back-up van de Nextcloud-database.

## Waar back-ups staan

Back-ups zijn gzip-gecomprimeerde JSON-bestanden in de app-data van Nextcloud: `<datadirectory>/appdata_<instanceid>/metavox/backups/`.

- Een dagelijkse back-up heet `metavox_backup_<datum>.json.gz`, bijvoorbeeld `metavox_backup_2026-10-18.json.gz`.
- Een back-up die je zelf maakt heet `metavox_backup_<datum>_<tijd>.json.gz`, bijvoorbeeld `metavox_backup_2026-10-18_14-30-05.json.gz`.

MetaVox bewaart maximaal 7 back-ups. Dagelijkse en handmatige back-ups tellen samen: na elke nieuwe back-up verdwijnen de oudste tot er 7 over zijn.

## Dagelijkse back-ups

Een achtergrondtaak (`MetadataBackupJob`) maakt elke 24 uur een back-up. Die draait met de achtergrondtaken van Nextcloud, dus gebruik systeem-cron: met AJAX of Webcron draait hij alleen als iemand een pagina open heeft.

## Een back-up maken

1. Ga naar **Instellingen → Beheer → MetaVox**.
2. Open het onderdeel **Back-up**. Daar staat de sectie **Back-up & Herstel**.
3. Klik **Nu back-up maken**.

Een voortgangsbalk laat zien hoe ver de back-up is, en in welke tabel. Als hij klaar is, meldt MetaVox **Back-up voltooid**, met de duur en de bestandsgrootte. De nieuwe back-up staat bovenaan de lijst.

## Een back-up terugzetten

!!! warning "Herstellen overschrijft alles"
    Een herstel vervangt alle velddefinities en metadatawaarden door die in de back-up. Dat kan niet ongedaan worden gemaakt. Maak eerst een back-up als je misschien terug wilt.

1. Open het onderdeel **Back-up**.
2. Zoek de back-up in de lijst. Elke rij toont de datum, de MetaVox-versie die hem maakte, het aantal items en de grootte.
3. Klik **Herstellen** op die rij.
4. De dialoog **Back-up herstellen** toont de datum van de back-up en hoeveel velddefinities, Team folder-waarden en bestandswaarden erin zitten. Klik **Herstellen** om door te gaan, of **Annuleren**.

Een voortgangsbalk toont het herstel. Als het klaar is, meldt MetaVox **Herstel voltooid**.

Na het herstel bouwt MetaVox de zoekindex opnieuw op en gooit het zijn gecachete waarden weg, zodat de teruggezette metadata verschijnt zodra een pagina opnieuw laadt. Bestandslinks uit een back-up van een oudere versie bevatten nog bestandspaden; MetaVox haalt die weg, en de links blijven werken via het bestands-ID.

## Eén tegelijk

Zolang een back-up of herstel loopt, zijn **Nu back-up maken** en **Herstellen** uitgeschakeld. Start je er toch nog een, bijvoorbeeld via de API, dan wordt dat geweigerd met 409 en de melding "A backup or restore is already running". De dagelijkse back-up kijkt hier niet naar.

## Een back-up downloaden

Klik **Downloaden** op een rij om het back-upbestand op te slaan, bijvoorbeeld om het buiten de server te bewaren.

## Via de API

Dit zijn browserroutes voor de beheerpagina, geen onderdeel van de stabiele OCS-API (zie [API-referentie](../architecture/api-reference.md)). Gebruik vanuit een script een app-wachtwoord van een beheerder en stuur de header `OCS-APIRequest: true` mee. Met die header neemt Nextcloud het verzoek aan zonder CSRF-token.

```bash
# Back-ups opvragen
curl "https://your-nextcloud.com/index.php/apps/metavox/api/backup/list" \
  -u "admin:app-password" \
  -H "OCS-APIRequest: true"

# Een back-up maken; antwoordt als hij klaar is
curl -X POST "https://your-nextcloud.com/index.php/apps/metavox/api/backup/trigger" \
  -u "admin:app-password" \
  -H "OCS-APIRequest: true"

# Een back-up downloaden
curl "https://your-nextcloud.com/index.php/apps/metavox/api/backup/download?filename=metavox_backup_2026-10-18.json.gz" \
  -u "admin:app-password" \
  -H "OCS-APIRequest: true" \
  -o metavox_backup_2026-10-18.json.gz

# Een back-up terugzetten; antwoordt als het klaar is
curl -X POST "https://your-nextcloud.com/index.php/apps/metavox/api/backup/restore" \
  -u "admin:app-password" \
  -H "OCS-APIRequest: true" \
  -H "Content-Type: application/json" \
  -d '{"filename": "metavox_backup_2026-10-18.json.gz"}'
```

`list` antwoordt met `backups`: per back-up de `filename`, `created_at`, `version`, `size` in bytes, `counts` per tabel, en `compressed`.

### Status

De beheerpagina vraagt elke 2 seconden de status op zolang een back-up of herstel loopt:

```bash
curl "https://your-nextcloud.com/index.php/apps/metavox/api/backup/status" \
  -u "admin:app-password" \
  -H "OCS-APIRequest: true"
```

Als er niets loopt, of een uur nadat de laatste klaar was:

```json
{"status": "idle"}
```

Tijdens een herstel (`operation` is `export` bij een back-up, `import` bij een herstel):

```json
{
  "status": "running",
  "operation": "import",
  "progress": 5000,
  "total": 11000,
  "table": "metavox_file_gf_meta",
  "started_at": "2026-10-18T14:30:05+02:00"
}
```

`progress` en `total` tellen rijen. Als het klaar is, is `status` `completed`, met `filename`, `progress`, `total` en `duration` in seconden; een back-up voegt `size` toe, een herstel `restored` (rijen per tabel). Als het misgaat, is `status` `error`, met `error` en `reason`.

## Performance

- Een back-up leest elke tabel in stukken van 5.000 rijen, zodat het geheugengebruik laag blijft bij miljoenen waarden.
- Een herstel schrijft 1.000 rijen tegelijk en commit elke 10.000 rijen.
- Een herstel leest zowel `.json.gz` als ongecomprimeerde `.json`-bestanden.

## Zie ook

- [Instellingen](settings.md) — De onderdelen van de beheerpagina
- [Privacy & beveiliging](../architecture/privacy.md) — Wat MetaVox opslaat
- [API-referentie](../architecture/api-reference.md) — Volledige API-documentatie
