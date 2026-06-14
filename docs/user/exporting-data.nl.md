# Data exporteren

MetaVox laat je metadata exporteren naar CSV voor gebruik in spreadsheets, rapporten of externe systemen.

## Hoe te exporteren

1. Navigeer naar een team folder in de Files-app
2. Selecteer de bestanden die je wilt exporteren (checkboxes of `Ctrl/Cmd+klik`)
3. Klik op **"Metadata bewerken"** in de toolbar om de bulk-editor te openen
4. Klik op de **"CSV exporteren"**-knop

De export downloadt automatisch met een datum-gestempelde bestandsnaam, bijvoorbeeld `metadata-export-2026-03-17.csv`.

## Export-format

Het CSV-bestand bevat één rij per geselecteerd bestand, met de volgende kolommen:

| Kolom | Beschrijving |
|-------|--------------|
| `file_path` | Volledig pad binnen Nextcloud |
| `file_name` | Bestandsnaam |
| *metadata-velden* | Eén kolom per geconfigureerd metadata-veld |

**Voorbeeld:**

```csv
file_path,file_name,status,afdeling,herzieningsdatum
/Documenten/rapport.pdf,rapport.pdf,Goedgekeurd,Juridisch,2026-01-15
/Documenten/memo.docx,memo.docx,Concept,HR,
/Documenten/beleid.pdf,beleid.pdf,Gearchiveerd,Management,2025-06-30
```

## Tips

- **Selecteer specifieke bestanden** om alleen te exporteren wat je nodig hebt, of selecteer alle bestanden voor een complete export
- **Speciale tekens** (komma's, aanhalingstekens, regeleindes) worden correct ge-escaped in de CSV-output
- **Lege velden** verschijnen als lege waarden in de CSV — ze worden niet weggelaten
- **Openen in Excel**: dubbel-klik op het gedownloade `.csv`-bestand. Als speciale tekens niet correct weergegeven worden, gebruik Excel's "Data → Uit tekst/CSV"-import met UTF-8-encoding
- **Grote exports**: de export werkt prima voor typische selecties. Voor zeer grote datasets (1000+ bestanden), overweeg de [API](../architecture/api-reference.md) te gebruiken voor batch-operaties

## Zie ook

- [Bulk-bewerken](bulk-editing.md) — Metadata voor meerdere bestanden tegelijk bewerken
- [Veldtypen](field-types.md) — Metadata-veldtypen begrijpen
- [API-referentie](../architecture/api-reference.md) — Programmatische toegang tot metadata
