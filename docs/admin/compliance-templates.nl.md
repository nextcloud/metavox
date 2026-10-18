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

De templates zitten bij de app, in de map `templates/compliance/`, en staan in de [GitHub-repository](https://github.com/nextcloud/metavox/tree/main/templates/compliance). Sla eerst het template dat je nodig hebt op je computer op.

1. Ga naar **Instellingen → Beheer → MetaVox** en open het onderdeel **Bestanden**. De templates beschrijven documenten, dus het zijn bestandsvelden.
2. Open het menu naast **Nieuw veld** en kies **Import from JSON …**.
3. Kies het template-bestand.
4. **Import Voorbeeld** toont de velden in het bestand, met hun interne naam en type. Klik **Velden importeren**.

De import zet het voorvoegsel van het onderdeel voor elke naam: `file_gf_` bij **Bestanden**, `gf_` bij **Teammappen**. Zo wordt `bevat_persoonsgegevens` `file_gf_bevat_persoonsgegevens`. Een naam die het voorvoegsel al heeft, blijft zoals hij is. Een veld waarvan de naam al bestaat, wordt niet geïmporteerd; MetaVox meldt dan hoeveel velden mislukten.

Wijs de velden daarna toe aan Team folders, in het onderdeel **Per map**.

## Template-details

### AVG-compliance (`avg-compliance.json`)

Velden voor het classificeren van documenten volgens de Algemene Verordening Gegevensbescherming (AVG/GDPR):

| Veld | Type | Beschrijving |
|------|------|--------------|
| Bevat persoonsgegevens | Selectievakje | Geeft aan of het document persoonsgegevens bevat. Niet verplicht: een selectievakje heeft altijd een antwoord, aangevinkt of niet. Het template markeert het als verplicht; de import negeert dat |
| Categorie persoonsgegevens | Meerkeuze | Welke categorieën (NAW, financieel, gezondheid, etc.) |
| Verwerkingsgrondslag | Dropdown | AVG-rechtsgrond (toestemming, overeenkomst, wettelijke verplichting, etc.) |
| Bewaartermijn (jaren) | Nummer | Maximale bewaartermijn volgens AVG |
| Verwerkingsverantwoordelijke | Tekst | Wie verantwoordelijk is voor de gegevensverwerking |

**Use case**: AVG-verantwoording bij verzoeken van betrokkenen of vragen van de Autoriteit Persoonsgegevens.

### WOO-compliance (`woo-compliance.json`)

Velden voor het classificeren van documenten volgens de Wet Open Overheid:

| Veld | Type | Beschrijving |
|------|------|--------------|
| Publicatie-status | Dropdown | Verplicht — openbaar, gedeeltelijk openbaar, niet openbaar, te beoordelen |
| WOO-categorie | Dropdown | Informatie-categorie volgens artikel 3.3 |
| Uitzonderingsgrond | Meerkeuze | Reden voor beperkte publicatie (artikelen 5.1–5.5) |
| WOO-verzoek ontvangen | Datum | Datum waarop WOO-verzoek werd ontvangen |
| Actieve publicatie | Selectievakje | Onderworpen aan actieve publicatieplicht |
| Publicatie-datum | Datum | Wanneer het document publiek werd gemaakt |

**Use case**: snel reageren op WOO-verzoeken, documenten identificeren voor proactieve publicatie.

### Archiefwet (`archiefwet-compliance.json`)

Velden voor archivering en selectielijst-compliance:

| Veld | Type | Beschrijving |
|------|------|--------------|
| Archief-categorie | Dropdown | Verplicht — te vernietigen, blijvend bewaren, overdragen |
| Vernietigings-jaar | Nummer | Jaar waarin het document moet worden vernietigd |
| Selectielijst-code | Tekst | Code uit VNG- of nationale selectielijst |
| Zaaktype | Tekst | Type zaak waar het document toe behoort |
| Dossier-status | Dropdown | Open, gesloten, gearchiveerd, vernietigd |
| Bewaartermijn (jaren) | Nummer | Bewaartermijn volgens selectielijst |
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

1. **Exporteer** de velden met **Exporteren naar JSON**, in hetzelfde menu als de import. De export laat het voorvoegsel weg.
2. **Bewerk** het JSON-bestand
3. **Importeer** de aangepaste versie met **Import from JSON …**

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

Laat het voorvoegsel weg uit `field_name`: de import zet het ervoor. `is_required` doet niets bij een `checkbox`; die is nooit verplicht.

**Beschikbare veldtypen**: `text`, `textarea`, `number`, `date`, `select`, `multiselect`, `checkbox`, `url`, `user`, `filelink` (`usergroup` uit oudere bestanden wordt gelezen als `user`)

**Datum-velden** kunnen optioneel een tijd-component bevatten (`includeTime: true` in `field_options`) — zie [Veldtypen → Datum](../user/field-types.md#datum). Handig voor SharePoint-migraties waar `SPFieldDateTime.DisplayFormat = DateTime`-kolommen lossless heen-en-weer moeten gaan.

## Bronnen

- [MetaVox GitHub](https://github.com/nextcloud/metavox)
- [VNG selectielijst](https://vng.nl/selectielijst) — Officiële gemeentelijke selectielijst
- [Autoriteit Persoonsgegevens](https://autoriteitpersoonsgegevens.nl) — AVG-informatie
- [Wet Open Overheid](https://wetten.overheid.nl/BWBR0045754) — Officiële wettekst

## Zie ook

- [Flow-integratie](flow-integration.md) — Workflows automatiseren met metadata
- [Installatie](installation.md) — Templates importeren tijdens setup
- [Permissies](permissions.md) — Toegangscontrole
- [Privacy & beveiliging](../architecture/privacy.md) — AVG-compliance-details
