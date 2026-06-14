# Telemetrie

MetaVox bevat optionele, anonieme gebruiks-rapportage om de applicatie te helpen verbeteren. Telemetrie is **standaard ingeschakeld** en kan door beheerders worden uitgeschakeld.

## Wat wordt verzameld?

Alleen geaggregeerde, anonieme statistieken — geen bestandsnamen, gebruikersnamen of bestandsinhoud:

| Data | Beschrijving |
|------|--------------|
| Aantal team folders | Aantal groupfolders met MetaVox-velden |
| Aantal gebruikers | Aantal actieve gebruikers |
| Aantal velden | Totaal aantal metadata-veld-definities |
| Aantal metadata-waarden | Totaal opgeslagen metadata-entries |
| Nextcloud-versie | Server-versie voor compatibility-tracking |
| MetaVox-versie | App-versie |

## Telemetrie uitschakelen

### Via admin-instellingen

1. Ga naar **Instellingen → Beheer → MetaVox**
2. Zoek de **Telemetrie**-sectie
3. Zet telemetrie uit

### Via API

```bash
curl -X POST "https://your-nextcloud.com/apps/metavox/api/telemetry/settings" \
  -H "Content-Type: application/json" \
  -b "session-cookie" \
  -d '{"telemetry_enabled": false}'
```

## Telemetrie-API-endpoints

| Endpoint | Methode | Beschrijving |
|----------|---------|--------------|
| `/apps/metavox/api/telemetry/status` | GET | Check of telemetrie aan staat |
| `/apps/metavox/api/telemetry/stats` | GET | Verzamelde statistieken lokaal bekijken |
| `/apps/metavox/api/telemetry/send` | POST | Handmatig een telemetrie-rapport triggeren |
| `/apps/metavox/api/telemetry/settings` | POST | Telemetrie aan-/uitzetten |

## Privacy-notities

- Telemetrie is **opt-out** (standaard aan, kan worden uitgezet)
- Geen persoonlijk identificeerbare informatie wordt verstuurd
- Geen bestandsnamen, gebruikersnamen of metadata-waarden worden verzonden
- Alleen geaggregeerde aantallen worden gerapporteerd
- Telemetrie kan volledig uit zonder MetaVox-functionaliteit te beïnvloeden
- Wanneer uit: geen data wordt verstuurd, geen externe connecties worden gemaakt

## Zie ook

- [Privacy & beveiliging](../architecture/privacy.md) — Data-privacy-overzicht
- [Installatie](installation.md) — Initiële setup
- [Instellingen](settings.md) — Beheer-instellingen
