# Compliance-templates voor Nederlandse overheid

MetaVox bevat voor-gedefinieerde metadata-templates voor Nederlandse overheids-compliance. Deze helpen organisaties hun verplichtingen onder AVG, Wet Open Overheid (WOO) en Archiefwet na te komen.

## Beschikbare templates

| Template | Bestand | Doel |
|----------|---------|------|
| AVG-compliance | `avg-compliance.json` | Persoonsgegevens-classificatie en verwerkingsgrondslag |
| WOO-compliance | `woo-compliance.json` | Publicatie-status en informatie-categorieën |
| Archiefwet | `archiefwet-compliance.json` | Bewaartermijnen en selectielijst-codes |
| Compleet | `overheid-compleet.json` | Alle velden gecombineerd |

## Installatie

1. Ga naar **Instellingen → MetaVox → Team folder-metadata**
2. Klik **JSON-bestand selecteren** onder "Import & Export"
3. Selecteer het gewenste template-bestand uit `/templates/compliance/`
4. Bekijk de preview en klik **Bevestig import**

## Template-details

### AVG-compliance (`avg-compliance.json`)

Velden voor het classificeren van documenten volgens de Algemene Verordening Gegevensbescherming (AVG/GDPR):

| Veld | Type | Beschrijving |
|------|------|--------------|
| Bevat persoonsgegevens | Checkbox | Verplicht — geeft aan of het document persoonsgegevens bevat |
| Categorie persoonsgegevens | Multi-select | Welke categorieën (NAW, financieel, gezondheid, etc.) |
| Verwerkingsgrondslag | Select | AVG-rechtsgrond (toestemming, overeenkomst, wettelijke verplichting, etc.) |
| Bewaartermijn (jaren) | Getal | Maximale bewaartermijn volgens AVG |
| Verwerkingsverantwoordelijke | Tekst | Wie verantwoordelijk is voor de gegevensverwerking |

**Use case**: AVG-verantwoording bij verzoeken van betrokkenen of vragen van de Autoriteit Persoonsgegevens.

### WOO-compliance (`woo-compliance.json`)

Velden voor het classificeren van documenten volgens de Wet Open Overheid:

| Veld | Type | Beschrijving |
|------|------|--------------|
| Publicatie-status | Select | Verplicht — openbaar, gedeeltelijk openbaar, niet openbaar |
| WOO-categorie | Select | Informatie-categorie volgens artikel 3.3 |
| Uitzonderingsgrond | Multi-select | Reden voor beperkte publicatie (artikelen 5.1–5.5) |
| WOO-verzoek ontvangen | Datum | Datum waarop WOO-verzoek werd ontvangen |
| Actieve publicatie | Checkbox | Onderworpen aan actieve publicatieplicht |
| Publicatie-datum | Datum | Wanneer het document publiek werd gemaakt |

**Use case**: snel reageren op WOO-verzoeken, documenten identificeren voor proactieve publicatie.

### Archiefwet (`archiefwet-compliance.json`)

Velden voor archivering en selectielijst-compliance:

| Veld | Type | Beschrijving |
|------|------|--------------|
| Archief-categorie | Select | Verplicht — te vernietigen, blijvend bewaren, overdragen |
| Vernietigings-jaar | Getal | Jaar waarin het document moet worden vernietigd |
| Selectielijst-code | Tekst | Code uit VNG- of nationale selectielijst |
| Zaaktype | Tekst | Type zaak waar het document toe behoort |
| Dossier-status | Select | Open, gesloten, gearchiveerd, vernietigd |
| Bewaartermijn (jaren) | Getal | Bewaartermijn volgens selectielijst |
| Archiveer-datum | Datum | Datum van archivering |
| Archief-locatie | Tekst | Fysieke of digitale locatie |

**Use case**: beheer van bewaartermijnen, voorbereiding van overbrenging naar archief-bewaarplaats.

### Complete template (`overheid-compleet.json`)

Combineert de belangrijkste velden van alle drie templates voor organisaties die een compleet compliance-pakket nodig hebben. Bevat 12 velden die de essentiële aspecten van AVG, WOO en Archiefwet dekken.

## Nextcloud Flow-integratie

Combineer MetaVox-metadata met Nextcloud Flow voor automatisering.

Voor uitgebreide Flow-rule-voorbeelden: zie [Flow-integratie](flow-integration.md).

## Juridische context

### AVG / GDPR

De Algemene Verordening Gegevensbescherming verplicht organisaties:

- Te weten welke persoonsgegevens ze verwerken
- Een geldige rechtsgrond voor verwerking te hebben
- Persoonsgegevens niet langer te bewaren dan nodig

### Wet Open Overheid (WOO)

De WOO verplicht overheidsorganisaties:

- Bepaalde informatie-categorieën proactief te publiceren
- Transparant te zijn over welke informatie wel/niet openbaar is
- Informatieverzoeken vlot af te handelen

### Archiefwet

De Archiefwet stelt eisen aan:

- Selectie en waardering van documenten
- Bewaartermijnen en vernietiging
- Overbrenging naar archiefbewaarplaats

## Templates aanpassen

Templates zijn een startpunt. Pas ze aan voor je organisatie:

1. **Exporteer** huidige velden via MetaVox-beheer
2. **Bewerk** het JSON-bestand
3. **Importeer** de aangepaste versie

### JSON-structuur

```json
[
  {
    "field_name": "internal_name",
    "field_label": "Weergave-naam",
    "field_type": "select",
    "field_description": "Uitleg voor gebruikers",
    "field_options": [
      {"value": "Optie 1"},
      {"value": "Optie 2"}
    ],
    "is_required": true
  }
]
```

**Beschikbare veldtypen**: `text`, `textarea`, `number`, `date`, `select`, `multiselect`, `checkbox`, `url`, `usergroup`, `filelink`

**Datum-velden** kunnen optioneel een tijd-component bevatten (`includeTime: true` in `field_options`) — zie [Veldtypen → Datum](../user/field-types.md#datum). Handig voor SharePoint-migraties waar `SPFieldDateTime.DisplayFormat = DateTime`-kolommen lossless heen-en-weer moeten gaan.

## Bronnen

- [MetaVox GitHub](https://github.com/voxcloud/metavox)
- [VNG selectielijst](https://vng.nl/selectielijst) — Officiële gemeentelijke selectielijst
- [Autoriteit Persoonsgegevens](https://autoriteitpersoonsgegevens.nl) — AVG-informatie
- [Wet Open Overheid](https://wetten.overheid.nl/BWBR0045754) — Officiële wettekst

## Zie ook

- [Flow-integratie](flow-integration.md) — Workflows automatiseren met metadata
- [Installatie](installation.md) — Templates importeren tijdens setup
- [Permissies](permissions.md) — Toegangscontrole
- [Privacy & beveiliging](../architecture/privacy.md) — AVG-compliance-details
