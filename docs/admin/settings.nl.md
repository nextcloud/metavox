# Beheerder-instellingen

MetaVox-beheer-instellingen vind je via **Instellingen → Beheer → MetaVox**.

![Beheer-instellingen](../screenshots/admin-settings.png)

## Instellingen

### AI metadata-generatie

Schakel automatische AI-metadata-suggesties aan of uit voor alle gebruikers.

- **Standaard**: uitgeschakeld
- **Vereist**: een Nextcloud AI task-processing provider (bv. LLM2-app)

Wanneer ingeschakeld, zien gebruikers een "AI-autofill"-knop in de metadata-zijbalk die suggesties genereert op basis van bestandsinhoud.

Zie [AI-autofill](../features/ai-autofill.md) voor details.

### Telemetrie

Schakel anonieme gebruiks-rapportage aan of uit.

- **Standaard**: ingeschakeld
- **Privacy**: alleen geaggregeerde aantallen worden verstuurd (geen bestandsnamen, gebruikersnamen of metadata-waarden)

Zie [Telemetrie](telemetry.md) voor details.

## Instellingen-API

### Instellingen ophalen

```bash
curl "https://your-nextcloud.com/apps/metavox/api/settings" \
  -b "session-cookie"
```

**Response**:

```json
{
  "success": true,
  "settings": {
    "ai_enabled": false
  }
}
```

### Instellingen opslaan

```bash
curl -X POST "https://your-nextcloud.com/apps/metavox/api/settings" \
  -H "Content-Type: application/json" \
  -b "session-cookie" \
  -d '{"ai_enabled": true}'
```

## Zie ook

- [Installatie](installation.md) — Initiële setup
- [AI-autofill](../features/ai-autofill.md) — AI-feature-documentatie
- [Telemetrie](telemetry.md) — Gebruiks-rapportage
- [Back-up & herstel](backup-restore.md) — Metadata-back-ups
