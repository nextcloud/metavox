# Compliance Templates voor Nederlandse Overheid

MetaVox bevat voorgedefinieerde metadata templates voor Nederlandse overheidscompliance. Deze templates helpen bij het voldoen aan de AVG, Wet Open Overheid (WOO) en Archiefwet.

## Beschikbare Templates

| Template | Bestand | Doel |
|----------|---------|------|
| AVG Compliance | `avg-compliance.json` | Classificatie persoonsgegevens en verwerkingsgrondslagen |
| WOO Compliance | `woo-compliance.json` | Openbaarheidsstatus en informatiecategorieën |
| Archiefwet | `archiefwet-compliance.json` | Bewaartermijnen en selectielijst codes |
| Compleet | `overheid-compleet.json` | Alle velden gecombineerd |

## Installatie

1. Ga naar **Instellingen** > **MetaVox** > **Team folder Metadata**
2. Klik op **Select JSON File** onder "Import & Export"
3. Selecteer het gewenste template bestand uit `/templates/compliance/`
4. Bekijk de preview en klik op **Confirm Import**

## Template Details

### AVG Compliance (`avg-compliance.json`)

Velden voor het classificeren van documenten volgens de Algemene Verordening Gegevensbescherming:

| Veld | Type | Beschrijving |
|------|------|--------------|
| Bevat persoonsgegevens | Checkbox | Verplicht - geeft aan of document persoonsgegevens bevat |
| Categorie persoonsgegevens | Multiselect | Welke categorieën (naam/adres, financieel, gezondheid, etc.) |
| Grondslag verwerking | Select | AVG-grondslag (toestemming, overeenkomst, wettelijk, etc.) |
| Bewaartermijn (jaren) | Nummer | Maximale bewaartermijn volgens AVG |
| Verwerkingsverantwoordelijke | Tekst | Wie is verantwoordelijk voor de verwerking |

**Use case**: AVG-verantwoording bij verzoeken van betrokkenen of Autoriteit Persoonsgegevens.

### WOO Compliance (`woo-compliance.json`)

Velden voor het classificeren van documenten volgens de Wet Open Overheid:

| Veld | Type | Beschrijving |
|------|------|--------------|
| Openbaarheidsstatus | Select | Verplicht - openbaar, deels openbaar, niet openbaar |
| WOO Categorie | Select | Informatiecategorie volgens artikel 3.3 |
| Uitzonderingsgrond | Multiselect | Reden voor beperkte openbaarheid (artikel 5.1-5.5) |
| WOO-verzoek ontvangen | Datum | Datum van binnenkomst WOO-verzoek |
| Actieve openbaarmaking | Checkbox | Valt onder actieve openbaarmakingsplicht |
| Publicatiedatum | Datum | Wanneer openbaar gemaakt |

**Use case**: Snel beantwoorden van WOO-verzoeken, identificeren van documenten voor actieve openbaarmaking.

### Archiefwet (`archiefwet-compliance.json`)

Velden voor archivering en selectielijst compliance:

| Veld | Type | Beschrijving |
|------|------|--------------|
| Archiefcategorie | Select | Verplicht - te vernietigen, blijvend bewaren, overbrengen |
| Vernietigingsjaar | Nummer | Jaar waarin document vernietigd moet worden |
| Selectielijst code | Tekst | Code uit VNG of rijks selectielijst |
| Zaaktype | Tekst | Type zaak waartoe document behoort |
| Dossierstatus | Select | Lopend, afgesloten, gearchiveerd, vernietigd |
| Bewaartermijn (jaren) | Nummer | Bewaartermijn volgens selectielijst |
| Archiefdatum | Datum | Datum van archivering |
| Archieflocatie | Tekst | Fysieke of digitale locatie |

**Use case**: Beheer van bewaartermijnen, voorbereiding op overbrenging naar archiefbewaarplaats.

### Compleet Template (`overheid-compleet.json`)

Combineert de belangrijkste velden uit alle drie de templates voor organisaties die een volledig compliance-pakket nodig hebben. Bevat 12 velden die de essentiële aspecten van AVG, WOO en Archiefwet dekken.

## Nextcloud Flow Integratie

Combineer MetaVox metadata met Nextcloud Flow voor automatisering:

### Voorbeeld 1: Blokkeer toegang tot niet-geclassificeerde documenten

1. Ga naar **Instellingen** > **Flow**
2. Klik op **Add new flow**
3. Trigger: "File accessed"
4. Conditie: MetaVox metadata > `openbaarheid_status` **is** `Nog te beoordelen`
5. Actie: **Block access**

### Voorbeeld 2: Notificatie bij naderende vernietigingsdatum

1. Maak een Flow rule met trigger "File accessed"
2. Conditie: MetaVox metadata > `vernietigingsjaar` **is** `2026`
3. Actie: **Send notification** "Document nadert vernietigingsdatum"

### Voorbeeld 3: Automatisch markeren voor archivering

1. Trigger: "Tag assigned" of periodiek via background job
2. Conditie: MetaVox metadata > `dossier_status` **is** `Afgesloten`
3. Actie: Verplaats naar archief-folder of stuur notificatie naar archivaris

## Wettelijke Context

### AVG (GDPR)
De Algemene Verordening Gegevensbescherming vereist dat organisaties:
- Weten welke persoonsgegevens ze verwerken
- Een geldige grondslag hebben voor verwerking
- Persoonsgegevens niet langer bewaren dan noodzakelijk

### Wet Open Overheid (WOO)
De WOO verplicht overheidsorganisaties tot:
- Actieve openbaarmaking van bepaalde categorieën informatie
- Transparantie over welke informatie wel/niet openbaar is
- Snelle afhandeling van informatieverzoeken

### Archiefwet
De Archiefwet stelt eisen aan:
- Selectie en waardering van documenten
- Bewaartermijnen en vernietiging
- Overbrenging naar archiefbewaarplaats

## Aanpassen van Templates

De templates zijn een startpunt. Pas ze aan voor uw organisatie:

1. **Exporteer** de huidige velden via MetaVox Admin
2. **Bewerk** het JSON bestand
3. **Importeer** de aangepaste versie

### JSON Structuur

```json
[
  {
    "field_name": "interne_naam",
    "field_label": "Weergavenaam",
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

**Beschikbare field types**: `text`, `textarea`, `number`, `date`, `select`, `multiselect`, `checkbox`, `url`, `usergroup`, `filelink`

## Ondersteuning

- [MetaVox GitHub](https://github.com/nextcloud/metavox)
- [VNG Selectielijst](https://vng.nl/selectielijst) - Officiële gemeentelijke selectielijst
- [Autoriteit Persoonsgegevens](https://autoriteitpersoonsgegevens.nl) - AVG-informatie
- [Wet Open Overheid](https://wetten.overheid.nl/BWBR0045754) - Officiële wettekst
