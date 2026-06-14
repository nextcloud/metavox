# MetaVox Flow-integratie

MetaVox integreert met Nextcloud's **Flow** (Workflow Engine) voor metadata-gebaseerde automatisering en toegangscontrole.

## Overzicht

Met Flow-integratie kun je regels maken zoals:

- Toegang blokkeren tot documenten gemarkeerd als vertrouwelijk
- Notificaties versturen bij goedkeuring van documenten
- Bestanden automatisch verplaatsen op basis van hun status

## Vereisten

1. MetaVox geïnstalleerd en geconfigureerd
2. Voor toegangscontrole: installeer de **Files Access Control**-app uit de Nextcloud App Store

## Flow-regels opzetten

### Een regel aanmaken

1. Ga naar **Instellingen → Flow** (admin-instellingen)
2. Klik **Nieuw flow toevoegen**
3. Selecteer een trigger (bv. "Bestand geopend", "Bestand aangemaakt")
4. Klik onder **Voorwaarden** op **Voorwaarde toevoegen**
5. Selecteer **"MetaVox-metadata"** uit de dropdown
6. Configureer je voorwaarde:
   - **Veld**: selecteer het metadata-veld om te controleren
   - **Operator**: kies uit beschikbare operators
   - **Waarde**: voer de waarde in om mee te vergelijken
   - **Team folder** (optioneel): beperk tot een specifieke folder

![Flow-integratie](../screenshots/MetaVox-flow.png)

### Beschikbare operators

#### Tekst / algemeen

| Operator | Beschrijving |
|----------|--------------|
| `is` | Exacte match |
| `!is` | Match niet |
| `contains` | Waarde bevat de tekst |
| `!contains` | Waarde bevat de tekst niet |
| `matches` | Reguliere expressie match |
| `!matches` | Reguliere expressie match niet |

#### Lege-checks (alle veldtypen)

| Operator | Beschrijving |
|----------|--------------|
| `empty` | Veld heeft geen waarde |
| `!empty` | Veld heeft een waarde |

#### Datum-velden

| Operator | Beschrijving |
|----------|--------------|
| `before` | Datum is voor de gegeven waarde |
| `after` | Datum is na de gegeven waarde |

De voorwaarde-waarde-picker schakelt automatisch: alleen-datum-velden gebruiken een `date`-input, velden met **Tijd-component meenemen** ingeschakeld gebruiken een `datetime-local`-input. Vergelijkingen worden lexicografisch uitgevoerd op de opgeslagen ISO 8601-string, wat chronologisch correct is voor beide formaten.

#### Getal-velden

| Operator | Beschrijving |
|----------|--------------|
| `greater` | Waarde is groter dan |
| `less` | Waarde is kleiner dan |
| `greaterOrEqual` | Waarde is groter dan of gelijk aan |
| `lessOrEqual` | Waarde is kleiner dan of gelijk aan |

#### Select / multi-select-velden

| Operator | Beschrijving |
|----------|--------------|
| `oneOf` | Waarde match't één van de gegeven opties |
| `containsAll` | Waarde bevat alle gegeven opties |

#### Checkbox-velden

| Operator | Beschrijving |
|----------|--------------|
| `isTrue` | Checkbox is aangevinkt |
| `isFalse` | Checkbox is uitgevinkt |

### Veld-specifieke input

De waarde-input past zich aan het veldtype aan:

- **Dropdown-velden**: tonen geconfigureerde opties
- **Checkbox-velden**: tonen Ja/Nee
- **Datum-velden**: tonen datum-picker
- **Getal-velden**: numerieke input
- **Tekst-velden**: vrije tekst-input

## Voorbeeld use-cases

### Toegang blokkeren tot vertrouwelijke bestanden

![Toegang blokkeren-voorbeeld](../screenshots/Flow-BlockAccess.png)

1. Maak een Flow-regel met trigger "Bestand geopend"
2. Voeg voorwaarde toe: MetaVox-metadata > `classification` **is** `confidential`
3. Voeg actie toe: **Blokkeer toegang**

Resultaat: gebruikers kunnen geen bestanden openen die als vertrouwelijk zijn gemarkeerd, tenzij de voorwaarde wordt gewijzigd.

### Notificatie bij document-goedkeuring

![Notificatie-voorbeeld](../screenshots/Flow-Notification.png)

> **Let op**: de "Notificatie versturen"-actie is alleen beschikbaar in **persoonlijke** Flow-instellingen (**Instellingen → Persoonlijk → Flow**), niet in het admin Flow-paneel. Dit is een ontwerpkeuze van Nextcloud — notificatie-flows worden per gebruiker geconfigureerd.

1. Ga naar **Instellingen → Persoonlijk → Flow**
2. Maak een Flow-regel met trigger "Bestand bijgewerkt"
3. Voeg voorwaarde toe: MetaVox-metadata > `status` **is** `approved`
4. Voeg actie toe: **Notificatie versturen**

Resultaat: wanneer een document-status verandert naar "approved", ontvang je een notificatie.

### Downloads beperken voor niet-gereviewde documenten

1. Maak een Flow-regel met trigger "Bestand geopend"
2. Voeg voorwaarde toe: MetaVox-metadata > `review_status` **is niet** `reviewed`
3. Voeg actie toe: **Download blokkeren**

Resultaat: documenten die niet gereviewd zijn, kunnen niet worden gedownload.

### Auto-tagging op basis van metadata

1. Maak een Flow-regel met trigger "Bestand aangemaakt"
2. Voeg voorwaarde toe: MetaVox-metadata > `department` **is** `Legal`
3. Voeg actie toe: **Tag toevoegen** "legal-team"

Resultaat: bestanden aangemaakt in de Legal-afdeling worden automatisch getagd.

## Tips

- **Team folder-detectie**: de Team folder wordt automatisch gedetecteerd uit de bestandslocatie in de meeste gevallen
- **Veld-groepering**: velden worden gegroepeerd op type: "Bestand-velden" (per-document) en "Team folder-velden" (overgeërfd van folder)
- **Testen**: test regels eerst met niet-kritieke bestanden
- **Logging**: check Nextcloud-logs als regels niet triggeren zoals verwacht

## Admin- vs persoonlijke Flow

| Actie | Waar configureren | Scope |
|-------|-------------------|-------|
| **Toegang tot een bestand blokkeren** | Instellingen → Beheer → Flow | Geldt voor alle gebruikers |
| **Notificatie versturen** | Instellingen → Persoonlijk → Flow | Geldt alleen voor jou |

Beide soorten flow-regels kunnen MetaVox-metadata als voorwaarde gebruiken.

## Beperkingen

- Flow-regels evalueren wanneer triggers vuren (bestand geopend, aangemaakt, etc.)
- Metadata-wijzigingen alleen triggeren mogelijk geen regels tenzij gecombineerd met bestands-events
- Complexe voorwaarden vereisen mogelijk meerdere regels

## Zie ook

- [Compliance-templates](compliance-templates.md) — Voorgebouwde metadata-schema's met Flow-voorbeelden
- [Permissies](permissions.md) — Basis-toegangscontrole
- [Architectuur-overzicht](../architecture/overview.md) — Hoe Flow-integratie technisch werkt
