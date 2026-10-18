# Bulk-metadata-editor

MetaVox laat je metadata bewerken voor meerdere bestanden tegelijk met de **bulk-metadata-editor**. Beschikbaar vanuit de Files-app-toolbar wanneer je twee of meer bestanden of mappen selecteert.

---

## Bulk-editor openen

1. Navigeer naar een team folder in de Files-app
2. Selecteer twee of meer bestanden via checkboxes of `Ctrl/Cmd+klik`
3. Klik op **"Metadata bewerken ({count} items)"** in de toolbar boven de bestandenlijst (mogelijk onder het **…**-menu)

![Bulk-bewerken-knop](../../screenshots/BulkeditMetadata.png)

---

## De bulk-editor gebruiken

Het venster **Metadata bewerken** opent. Bovenaan staan de namen van de geselecteerde bestanden (de eerste vijf, daarna een telling van de rest). Daaronder zie je de bestandsmetadatavelden van de Team folder; Team folder-metadata kun je hier niet wijzigen.

![Bulk-bewerken-modaal](../../screenshots/BulkeditMetadataModal.png)

### Velden vinden

Wanneer een team folder meer dan 6 metadata-velden heeft, verschijnt een zoekvak **Velden zoeken** bovenaan het venster. Typ om velden te filteren op naam — de zoekopdracht is hoofdletter-ongevoelig en match't op zowel veldlabel als interne naam.

### Velden bewerken

- Vul de velden in die je wilt bijwerken
- Laat velden leeg als je ze niet wilt wijzigen
- Alle geselecteerde bestanden krijgen dezelfde waarden
- Klik op **Opslaan**. De knop blijft uitgeschakeld tot je een veld invult. Daarna zie je *Metadata opgeslagen voor {count} bestanden*.
- Bestanden waarop je geen schrijfrechten hebt worden overgeslagen

### Merge-strategieën

Kies hoe om te gaan met bestaande metadata-waarden:

| Strategie | Beschrijving |
|-----------|--------------|
| **Bestaande waarden overschrijven** | Vervangt alle bestaande waarden door de nieuwe waarden die je invoert |
| **Alleen lege velden invullen** | Werkt alleen velden bij die nu leeg zijn, met behoud van bestaande waarden |

---

## Aanvullende acties

### Alle metadata wissen

Klik op de **"Alles wissen"**-knop om alle metadata van de geselecteerde bestanden te verwijderen.

- Een bevestigings-dialoog vraagt *Alle metadata van {count} bestanden wissen? Dit kan niet ongedaan worden gemaakt.* Klik op **Metadata wissen** om door te gaan, of op **Annuleren**
- Verplichte velden houden hun waarde: alles wissen is daar geen weg omheen
- Dit wist ook de zoekindex-entries voor die bestanden

### Exporteren naar CSV

Exporteer metadata van de geselecteerde bestanden naar een CSV-bestand. Zie [Data exporteren](exporting-data.md) voor details.

---

## Tips

- **Grote selecties**: de bulk-editor werkt efficiënt met veel bestanden, maar voor zeer grote selecties (100+) overweeg in batches te verwerken
- **Verplichte velden**: een veld dat je leeg laat blijft op elk bestand zoals het was, in beide modi. Een verplicht vinkje kun je niet op uit zetten
- **Undo**: er is geen undo-functie — gebruik "Alleen lege velden invullen" als je bestaande data wilt behouden

---

## Zie ook

- [Data exporteren](exporting-data.md) — Metadata naar CSV exporteren
- [Veldtypen](field-types.md) — Alle beschikbare veldtypen
- [API-referentie](../architecture/api-reference.md) — Batch-operaties via API
