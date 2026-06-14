# AI-autofill

MetaVox kan automatisch metadata-waarden voor bestanden suggereren via Nextcloud's AI task-processing-framework.

## Vereisten

- Een Nextcloud AI task-processing-provider geïnstalleerd (bv. de LLM2-app of een externe provider)
- AI ingeschakeld in MetaVox-admin-instellingen

## Hoe het werkt

1. Open de metadata-zijbalk van een bestand
2. Klik op de **AI-autofill**-knop
3. MetaVox analyseert het bestand (naam, pad, inhoud voor ondersteunde formaten) en genereert metadata-suggesties
4. Bekijk elke suggestie — accepteer of weiger individueel
5. Klik **Opnieuw genereren** voor nieuwe suggesties voor geweigerde velden

![AI-autofill-suggesties](../screenshots/ai-autofill.png)

### Ondersteunde bestandsformaten

AI kan inhoud extraheren uit:

- **PDF**-bestanden (tekst-extractie)
- **DOCX**-bestanden (Word-documenten)
- **ODT**-bestanden (OpenDocument-tekst)

Voor andere bestandstypen gebruikt AI de bestandsnaam en het pad als context.

## AI inschakelen

### Via admin-instellingen

1. Ga naar **Instellingen → Beheer → MetaVox**
2. Zet **AI-metadata-generatie inschakelen** aan

### Via API

```bash
curl -X POST "https://your-nextcloud.com/apps/metavox/api/settings" \
  -H "Content-Type: application/json" \
  -b "session-cookie" \
  -d '{"ai_enabled": true}'
```

## AI-beschikbaarheid checken

```bash
curl "https://your-nextcloud.com/apps/metavox/api/ai/status" \
  -b "session-cookie"
```

**Response**:

```json
{"available": true}
```

AI is beschikbaar wanneer beide voorwaarden waar zijn:

1. Een AI task-processing-provider is geïnstalleerd in Nextcloud
2. AI is ingeschakeld in de MetaVox-admin-instellingen

## Hoe suggesties werken

- AI respecteert veldtypen: dropdown-velden ontvangen alleen suggesties uit geconfigureerde opties
- Eerder geweigerde suggesties worden meegegeven aan de AI zodat hij andere waarden genereert
- Elke veld-suggestie kan onafhankelijk worden geaccepteerd of geweigerd
- Suggesties worden niet automatisch opgeslagen — jij kiest wat je behoudt

## Zie ook

- [Gebruikersgids](../user/overview.md) — Algemeen gebruik
- [Veldtypen](../user/field-types.md) — Beschikbare veldtypen
- [Instellingen](../admin/settings.md) — Beheer-instellingen
