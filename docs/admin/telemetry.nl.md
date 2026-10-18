# Telemetrie

MetaVox bevat optionele gebruiksrapportage om de applicatie te helpen verbeteren. Die bevat aantallen en instellingen, geen metadata, bestandsnamen of gebruikersnamen; met een abonnementssleutel gaat die sleutel mee. Telemetrie is **standaard ingeschakeld** en kan door beheerders worden uitgeschakeld.

## Wat wordt verzameld?

Eén keer per dag stuurt een achtergrondtaak een rapport naar de VoxCloud-licentieserver (`licenses.voxcloud.nl`). Het bevat aantallen, versies en basale serverconfiguratie — geen bestandsnamen, gebruikersnamen of bestandsinhoud. Dit zijn alle velden van het rapport:

| Data | Sleutels | Beschrijving |
|------|----------|--------------|
| App | `appType` | Altijd `metavox` |
| Instantie-identificatie | `instanceHash` | SHA-256-hash van de instantie-URL (`overwrite.cli.url`, anders het eerste trusted domain), nooit de URL zelf |
| Aantal velden | `totalFields`, `fieldTypeCounts` | Totaal aantal metadata-veld-definities, en het aantal per veldtype |
| Aantal team folders | `totalGroupfolders`, `groupfoldersWithFields`, `groupfoldersWithMetadata` | Alle team folders, team folders met MetaVox-velden, en team folders met metadata |
| Aantal metadata-items | `totalMetadataEntries`, `metadataEntriesPerGroupfolder`, `filesWithMetadata` | Totaal opgeslagen metadata-items (van team folders en bestanden), het aantal per team folder (op team folder-ID), en het aantal bestanden met metadata |
| Aantal gebruikers | `totalUsers`, `activeUsers30d`, `disabledUsers` | Totaal aantal gebruikers, gebruikers die in de laatste 30 dagen hebben ingelogd, en uitgeschakelde gebruikers |
| Versies | `metavoxVersion`, `nextcloudVersion`, `phpVersion` | MetaVox-, Nextcloud- en PHP-versie |
| Serverconfiguratie | `databaseType`, `osFamily`, `webServer`, `defaultLanguage`, `defaultTimezone`, `isDocker` | Databasetype, besturingssysteemfamilie, webserver (leeg als systeem-cron het rapport verstuurt), standaardtaal, standaardtijdzone, of hij in Docker draait |
| Land | `countryCode` | De landcode uit `default_phone_region`, als die is ingesteld |
| Enterprise-abonnement | `hasValidSubscription`, `hasExtendedSupport` | Of Nextcloud een Enterprise-abonnement meldt, en of daar Extended Support bij hoort (twee ja/nee-waarden) |
| Abonnementssleutel | `licenseKey` | Je MetaVox-abonnementssleutel, als je er een hebt ingevoerd. Daarmee kan de server de Enterprise-melding controleren. Leeg zonder sleutel |

Het onderdeel **Ondersteuning** vat dit samen onder **Precies wat er wel en niet wordt verzonden**. Die lijst is korter: de uitgeschakelde gebruikers, het aantal per team folder, het aantal bestanden met metadata en de abonnementssleutel ontbreken, en de twee abonnementswaarden staan er als één. De tabel hierboven is volledig.

## Telemetrie uitschakelen

### Via admin-instellingen

1. Ga naar **Instellingen → Beheer → MetaVox**
2. Open het onderdeel **Ondersteuning** en zoek **Anonieme Gebruiksstatistieken**
3. Zet **Anonieme gebruiksstatistieken delen** uit

Zolang het aan staat, verstuurt **Rapport nu verzenden** direct een rapport.

### Via occ

```bash
sudo -u www-data php occ config:app:set metavox telemetry_enabled --value=false
```

### Via API

Gebruik een app-wachtwoord van een beheerder en stuur de header `OCS-APIRequest: true` mee. Met die header neemt Nextcloud het verzoek aan zonder CSRF-token.

```bash
curl -X POST "https://your-nextcloud.com/index.php/apps/metavox/api/telemetry/settings" \
  -u "admin:app-password" \
  -H "OCS-APIRequest: true" \
  -H "Content-Type: application/json" \
  -d '{"enabled": false}'
```

## Telemetrie-API-endpoints

| Endpoint | Methode | Beschrijving |
|----------|---------|--------------|
| `/apps/metavox/api/telemetry/status` | GET | Check of telemetrie aan staat |
| `/apps/metavox/api/telemetry/stats` | GET | De veld- en metadata-aantallen lokaal bekijken (een deel van het rapport) |
| `/apps/metavox/api/telemetry/send` | POST | Handmatig een telemetrie-rapport triggeren |
| `/apps/metavox/api/telemetry/settings` | POST | Telemetrie aan-/uitzetten (`{"enabled": true}` of `false`) |

## Privacy-notities

- Telemetrie is **opt-out** (standaard aan, kan worden uitgezet)
- Geen persoonlijk identificeerbare informatie wordt verstuurd. Heb je een abonnementssleutel ingevoerd, dan gaat die mee, en daarmee is het rapport aan je abonnement te koppelen
- Geen bestandsnamen, gebruikersnamen of metadata-waarden worden verzonden
- Alleen aantallen, versies en basale serverconfiguratie worden gerapporteerd
- Telemetrie kan volledig uit zonder MetaVox-functionaliteit te beïnvloeden
- Wanneer uit: er wordt geen telemetrie verstuurd. Met een abonnementssleutel neemt de dagelijkse licentiecheck nog wel contact op met de licentieserver, zie [Support & abonnementen](licensing.md#wat-een-abonnementssleutel-verstuurt)

## Zie ook

- [Privacy & beveiliging](../architecture/privacy.md) — Data-privacy-overzicht
- [Installatie](installation.md) — Initiële setup
- [Instellingen](settings.md) — Beheer-instellingen
