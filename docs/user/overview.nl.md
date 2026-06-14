# MetaVox gebruikersgids

MetaVox helpt je documenten te organiseren en classificeren door **metadata** toe te voegen — gestructureerde informatie over je bestanden.

## Waarom metadata?

Documenten hebben vaak context nodig buiten hun inhoud:

- Wie is verantwoordelijk voor dit document?
- Is het goedgekeurd of nog concept?
- Wanneer moet het herzien worden?
- Is het vertrouwelijk of publiek?

MetaVox legt deze informatie gestructureerd en doorzoekbaar vast.

## Metadata bekijken

1. Navigeer naar een document in een Team folder
2. Open de zijbalk (info-icoon of druk `i`)
3. Zoek de **MetaVox**-sectie

Je ziet twee soorten metadata:

### Team folder-metadata

- Geldt voor de hele Team folder
- Ingesteld door beheerders
- Read-only voor reguliere gebruikers
- Verschijnt bovenaan de MetaVox-sectie

### Documentmetadata

- Specifiek voor dit document
- Bewerkbaar als je schrijfrechten hebt
- Verschijnt onder de Team folder-metadata

![MetaVox-zijbalk](../screenshots/File%20metadata.png)

## Inline bewerken in de bestandenlijst

Je kunt metadata ook direct in de bestandenlijst bewerken door dubbel te klikken op een cel. Een inline-editor opent voor het veldtype (tekstveld, dropdown, datumkiezer, etc.).

![Inline-editor geopend op een cel](../screenshots/inline-editor.png)

In actie:

![Inline bewerken in beweging](../screenshots/inline-editing.gif)

## Metadata bewerken

Als je bewerk-rechten hebt op een document:

1. Open de zijbalk van het document
2. Zoek de MetaVox-sectie
3. Klik op een bewerkbaar veld
4. Voer een waarde in of selecteer
5. Wijzigingen worden automatisch opgeslagen

### Veldtypen

Verschillende velden accepteren verschillende soorten input:

| Type | Voorbeeld |
|------|-----------|
| Tekst | Korte beschrijvingen, titels |
| Tekstvak | Langere notities, samenvattingen |
| Getal | Versienummers, aantallen |
| Datum | Deadlines, herzieningsdata |
| Dropdown | Status (Concept/Goedgekeurd/Gearchiveerd) |
| Multi-select | Meerdere categorieën |
| Checkbox | Ja/Nee-vlaggen |
| URL | Links naar externe bronnen |
| Gebruiker | Selecteer een Nextcloud-gebruiker |
| Bestand-link | Link naar een ander Nextcloud-bestand |

Zie [Veldtypen](field-types.md) voor uitgebreide informatie.

## Meerdere bestanden tegelijk bewerken

Metadata bijwerken voor veel bestanden in één keer? Gebruik de bulk-editor:

1. Selecteer meerdere bestanden in de lijst
2. Klik **Metadata bewerken** in de toolbar
3. Vul de velden in die je wilt bijwerken
4. Kies een merge-strategie (overschrijven of alleen lege vullen)
5. Klik **Opslaan**

Zie [Bulk-bewerken](bulk-editing.md) voor details.

## Weergaven gebruiken

Weergaven laten je wisselen tussen voor-gedefinieerde combinaties van kolommen, filters en sortering. Je beheerder maakt weergaven per Team folder voor verschillende workflows.

Zie [Weergaven](views.md) voor details.

## Tips

- **Verplichte velden** zijn gemarkeerd met een asterisk (*)
- **Beschrijvingen** verschijnen onder velden om uit te leggen wat je moet invoeren
- **Dropdowns** tonen voor-gedefinieerde opties — je kunt geen eigen waarden invoeren
- **Wijzigingen zijn direct** — geen aparte opslaan-knop

## Hulp nodig?

- Bekijk de [Snelstart-gids](../getting-started.md) voor een introductie

Neem contact op met je Nextcloud-beheerder als:

- Je andere metadata-velden nodig hebt
- Je velden niet kunt bewerken die je wel zou moeten kunnen bewerken
- Je vragen hebt over welke waarden je moet invoeren

## Zie ook

- [Veldtypen](field-types.md) — Alle beschikbare veldtypen
- [Weergaven](views.md) — Wisselen tussen voor-gedefinieerde weergaven
- [Bulk-bewerken](bulk-editing.md) — Metadata voor meerdere bestanden bewerken
- [Data exporteren](exporting-data.md) — Metadata exporteren naar CSV
- [AI-autofill](../features/ai-autofill.md) — AI-gedreven metadata-suggesties
- [Real-time sync](../features/real-time-sync.md) — Collaboratief bewerken met cell-locking
