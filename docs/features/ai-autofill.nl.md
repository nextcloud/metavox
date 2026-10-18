# AI-autofill

MetaVox kan automatisch metadata-waarden voor bestanden suggereren via Nextcloud's AI task-processing-framework.

## Vereisten

- Een Nextcloud AI task-processing-provider geïnstalleerd (bv. de LLM2-app of een externe provider) die tekstgeneratie biedt
- AI ingeschakeld in MetaVox-admin-instellingen (standaard aan)
- Voor tekst uit PDF-, DOCX- en ODT-bestanden: de Nextcloud Assistant-app. MetaVox gebruikt de bibliotheken ervan om die bestanden te lezen
- De gebruiker mag het bestand wijzigen

## Hoe het werkt

1. Open het MetaVox-tabblad in de zijbalk van Bestanden en klik op de potloodknop (**Metadata van dit bestand bewerken** of **Metadata van deze map bewerken**)
2. Klik in het venster op **Genereren met AI**
3. MetaVox analyseert het bestand en genereert metadata-suggesties. De knop toont **Genereren …** zolang hij wacht
4. Elke suggestie verschijnt onder zijn veld, na **AI stelt voor:**, met drie knoppen:
   - Accepteren (**AI-suggestie voor {field} accepteren**): zet de waarde in het veld
   - Opnieuw genereren (**AI-suggestie voor {field} opnieuw genereren**): vraagt een nieuwe suggestie voor dit veld, en vertelt de AI dat de huidige is afgewezen
   - Negeren (**AI-suggestie voor {field} negeren**): haalt de suggestie weg
5. Klik **Opslaan** in het venster om de geaccepteerde waarden te bewaren

![AI-autofill-suggesties](../../screenshots/ai-autofill.png)

De AI vult alleen de velden die aan de Team folder van het bestand zijn toegekend en voor bestanden gelden, niet de eigen velden van de Team folder. Gebruiker- en bestandslink-velden slaat hij over.

Nextcloud voert de AI-taak uit via zijn task processing, op de achtergrond. MetaVox wacht maximaal 2 minuten op het resultaat en toont daarna "AI-taak duurde te lang".

### Ondersteunde bestandsformaten

AI leest de inhoud van:

- **Tekst**-bestanden (platte tekst, CSV, HTML, XML, Markdown, JSON), tot 32.000 tekens
- **PDF**-bestanden (tekst-extractie, vraagt de Assistant-app)
- **DOCX**-bestanden (Word-documenten, vraagt de Assistant-app)
- **ODT**-bestanden (OpenDocument-tekst, vraagt de Assistant-app)

Voor andere bestandstypen, of als de tekst niet te lezen is, gebruikt AI de bestandsnaam, het type, de grootte en het pad als context.

## AI inschakelen

### Via admin-instellingen

1. Ga naar **Instellingen → Beheer → MetaVox**
2. Open het onderdeel **Algemeen**
3. Zet **AI-metadatageneratie inschakelen** aan

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

1. Een AI task-processing-provider voor tekstgeneratie is geïnstalleerd in Nextcloud
2. AI is ingeschakeld in de MetaVox-admin-instellingen

## Hoe suggesties werken

- AI respecteert veldtypen: dropdown-velden ontvangen alleen suggesties uit geconfigureerde opties
- Eerder geweigerde suggesties worden meegegeven aan de AI zodat hij andere waarden genereert
- Elke veld-suggestie kan onafhankelijk worden geaccepteerd, opnieuw gegenereerd of genegeerd
- Suggesties worden niet automatisch opgeslagen — jij kiest wat je behoudt

## Zie ook

- [Gebruikersgids](../user/overview.md) — Algemeen gebruik
- [Veldtypen](../user/field-types.md) — Beschikbare veldtypen
- [Instellingen](../admin/settings.md) — Beheer-instellingen
