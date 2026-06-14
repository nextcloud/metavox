# Bulk-metadata-editor

MetaVox laat je metadata bewerken voor meerdere bestanden tegelijk met de **bulk-metadata-editor**. Beschikbaar vanuit de Files-app-toolbar wanneer je meerdere bestanden selecteert.

---

## Bulk-editor openen

1. Navigeer naar een team folder in de Files-app
2. Selecteer meerdere bestanden via checkboxes of `Ctrl/Cmd+klik`
3. Klik op de **"Metadata bewerken"**-knop in de toolbar

![Bulk-bewerken-knop](../screenshots/BulkeditMetadata.png)

---

## De bulk-editor gebruiken

Wanneer het bulk-editor-modaal opent, zie je alle beschikbare metadata-velden voor de geselecteerde bestanden.

![Bulk-bewerken-modaal](../screenshots/BulkeditMetadataModal.png)

### Velden vinden

Wanneer een team folder meer dan 6 metadata-velden heeft, verschijnt een zoekbalk bovenaan het modaal. Typ om velden te filteren op naam — de zoekopdracht is hoofdletter-ongevoelig en match't op zowel veldlabel als interne naam.

### Velden bewerken

- Vul de velden in die je wilt bijwerken
- Laat velden leeg als je ze niet wilt wijzigen
- Alle geselecteerde bestanden krijgen dezelfde waarden

### Merge-strategieën

Kies hoe om te gaan met bestaande metadata-waarden:

| Strategie | Beschrijving |
|-----------|--------------|
| **Bestaande waarden overschrijven** | Vervangt alle bestaande waarden door de nieuwe waarden die je invoert |
| **Alleen lege velden vullen** | Werkt alleen velden bij die nu leeg zijn, met behoud van bestaande waarden |

---

## Aanvullende acties

### Alle metadata wissen

Klik op de **"Alles wissen"**-knop om alle metadata van de geselecteerde bestanden te verwijderen.

- Een bevestigings-dialoog verschijnt om onbedoeld data-verlies te voorkomen
- Dit wist ook de zoekindex-entries voor die bestanden

### Exporteren naar CSV

Exporteer metadata van de geselecteerde bestanden naar een CSV-bestand. Zie [Data exporteren](exporting-data.md) voor details.

---

## Tips

- **Grote selecties**: de bulk-editor werkt efficiënt met veel bestanden, maar voor zeer grote selecties (100+) overweeg in batches te verwerken
- **Verplichte velden**: als velden gemarkeerd zijn als verplicht, zorg dat je waarden invoert bij "Overschrijven"-modus
- **Undo**: er is geen undo-functie — gebruik "Alleen lege velden vullen" als je bestaande data wilt behouden

---

## Zie ook

- [Data exporteren](exporting-data.md) — Metadata naar CSV exporteren
- [Veldtypen](field-types.md) — Alle beschikbare veldtypen
- [API-referentie](../architecture/api-reference.md) — Batch-operaties via API
