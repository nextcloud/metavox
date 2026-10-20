# MetaVox veldtypen

MetaVox ondersteunt diverse veldtypen voor het vastleggen van metadata op Team folders en documenten. Deze gids beschrijft elk veldtype en het gebruik.

---

## Basis-veldtypen

### Tekst

Een eenvoudig eenregelig tekstveld voor korte waarden.

**Use cases**: titel, auteurnaam, document-ID, korte beschrijvingen

**Voorbeeld**: "Q4 Budget Rapport"

**Opties**: geen

---

### Lange tekst (Textarea)

Een meerregelig tekstveld voor langere inhoud.

**Use cases**: beschrijvingen, notities, samenvattingen, comments

**Voorbeeld**: "Dit document beschrijft het voorgestelde budget voor Q4, inclusief projecties voor..."

**Opties**: geen

---

### Getal

Numeriek veld dat alleen getallen accepteert.

**Use cases**: versienummers, aantallen, hoeveelheden, jaartallen

**Voorbeeld**: `42`

**Opties**: geen

---

### Datum

Een datumkiezer voor het selecteren van datums. Optioneel met een tijd-component.

**Use cases**: publicatiedatum, vervaldatum, herzieningsdatum, vergader-starttijd, deadlines

**Voorbeelden**:

- Alleen datum: `2026-04-15`
- Datum + tijd: `2026-04-15T14:30:00`

**Opties**:

- **Tijd meenemen** (standaard: uit) — legt uren, minuten en seconden vast naast de datum. Opgeslagen als floating ISO 8601-string (zonder tijdzone), conform SharePoint's `SPFieldDateTime` met `DisplayFormat = DateTime`.

Bij het aanmaken of bewerken van een Datum-veld in de admin-instellingen, vink **Tijd meenemen** aan om het tijdgedeelte in te schakelen:

![Datum-veld met "Tijd meenemen" checkbox](../../screenshots/fields-datetime.png)

In de bestandszijbalk en inline-grid-editor toont het veld dan een datetime-picker:

![Datetime-input in de bestandszijbalk](../../screenshots/fields-datetime-view.png)

**SharePoint-migratie-notitie**: kolommen met `DisplayFormat = DateOnly` mappen naar MetaVox Datum-velden met deze optie **uit**; `DateTime` mapt naar **aan**. Bestaande pre-2.1.0 Datum-velden tonen alleen-datum — er wordt geen data-migratie uitgevoerd. CSV-import-ondersteuning is gepland voor een toekomstige release.

---

### Checkbox

Een boolean ja/nee-schakelaar.

**Use cases**: goedkeur-status, vertrouwelijk-vlag, gepubliceerd-status

**Voorbeeld**: ✅ (aangevinkt) of ☐ (niet aangevinkt)

**Opties**: geen

Een selectievakje is nooit verplicht: het heeft altijd een antwoord, aangevinkt of niet. De schakelaar **Verplicht veld** ontbreekt daarom bij dit veldtype.

---

### Select (Dropdown)

Een dropdown-menu met voor-gedefinieerde opties. Gebruikers selecteren één waarde.

**Use cases**: status, categorie, afdeling, documenttype

**Voorbeeld**: "In review" (uit opties: Concept, In review, Goedgekeurd, Gearchiveerd)

**Opties**: voer de keuzes in de veld-configuratie in onder **Dropdown-opties**, één per regel. Klik op **Optie toevoegen** voor elke extra keuze. Een veld heeft minstens één optie nodig.

---

### Multi-select

Vergelijkbaar met Select, maar staat meerdere selecties toe.

**Use cases**: tags, toepasselijke afdelingen, gerelateerde onderwerpen

**Voorbeeld**: "Juridisch, Financiën" (uit opties: Juridisch, Financiën, HR, IT)

**Opties**: voer de keuzes in de veld-configuratie in onder **Dropdown-opties**, één per regel. Klik op **Optie toevoegen** voor elke extra keuze. Een veld heeft minstens één optie nodig.

---

## Geavanceerde veldtypen

### URL

Een URL-veld met validatie en een klikbare externe-link-knop.

**Use cases**: bron-links, referentie-URL's, gerelateerde resources

**Features**:

- URL-format-validatie
- Klik op de knop om de link in een nieuw tabblad te openen

![URL-veld](../../screenshots/Fields-URL.png)

---

### Personen en groepen

Selecteer Nextcloud-accounts, groepen of teams uit een doorzoekbare dropdown. Toont avatars, en een icoon voor een groep of team.

**Use cases**: document-eigenaar, reviewer, verantwoordelijke persoon of team, betrokkenen

**Features**:

- Doorzoekbare lijst, zoals bij delen: je vindt wie je ook een bestand zou kunnen sturen
- Toont de weergavenaam, nooit het account-id
- **Kan bevatten**: accounts, groepen en teams; een beheerder vinkt aan welke. Teams vragen de app Teams
- **Meer dan één toestaan**: meer dan één in hetzelfde veld. Later uitgezet, houdt een bestand met meer dan één die tot iemand de waarde bewerkt
- *Toegewezen aan jou* telt ook je groepen en teams
- Een team dat je niet kunt zien, staat er als *Team niet beschikbaar*

![Personen-en-groepen-veld](../../screenshots/Fields-User.png)

---

### Bestand-link

Blader en link naar één of meer bestanden of mappen binnen Nextcloud via de native file-picker.

**Use cases**: gerelateerde documenten, bron-bestanden, bijlagen, ouder-documenten

**Features**:

- Native Nextcloud-file-picker-integratie
- **Meerdere bestanden per veld** — gebruik **Bestand toevoegen** om meerdere bestanden te koppelen; verwijder er een met de **×**-knop
- Koppelingen worden opgeslagen op **bestands-ID**, zodat een verwijzing blijft werken nadat het doelbestand hernoemd of verplaatst is
- Alleen bestanden en mappen binnen dezelfde Team folder kunnen gekoppeld worden
- Een bestand kan niet naar zichzelf verwijzen
- Hetzelfde bestand kan niet twee keer worden toegevoegd (duplicaten worden geweigerd)
- Klik op een gekoppeld bestand om het te openen
- Is een gekoppeld bestand verwijderd of heb je er geen toegang meer toe, dan staat het er als **Bestand niet beschikbaar**
- **Gelinkt vanuit** — in de zijbalk van een gekoppeld bestand zie je welke bestanden ernaar verwijzen (backlinks)

![Bestand-link-veld met meerdere gekoppelde bestanden](../../screenshots/fields-filelink-multiselect.png)

> Enkelvoudige koppelingen uit oudere versies blijven ongewijzigd werken; je kunt er op elk moment meer bestanden aan toevoegen.

---

## Veld-configuratie

Bij het aanmaken van een veld in de admin-instellingen kun je configureren:

| Instelling | Beschrijving |
|------------|--------------|
| **Veldnaam** | Interne identifier. Alleen kleine letters (a-z), cijfers (0-9) en underscores (_), beginnend met een letter |
| **Veld Label** | Display-naam getoond aan gebruikers |
| **Veldtype** | Een van de hierboven beschreven typen |
| **Beschrijving** | Help-tekst getoond onder het veld |
| **Verplicht veld** | Of het veld moet worden ingevuld. Ontbreekt bij een selectievakje, dat nooit verplicht is |
| **Dropdown-opties** | Voor Select en Multi-select velden: één keuze per regel, toegevoegd met **Optie toevoegen** |

---

## Standaardwaarden

Elke Team folder kan zijn toegewezen bestandsvelden een **standaardwaarde** geven. Nieuwe
bestanden in die folder krijgen de standaardwaarden automatisch, en bestaande bestanden
zonder waarde worden op de achtergrond bijgewerkt — zo beginnen de documenten van een
folder consistent geclassificeerd, zonder handmatig invoerwerk.

Standaardwaarden worden **per Team folder** ingesteld, met een type-correcte invoer voor
elk veldtype (tekst, getal, datum/tijd, dropdown, multi-select, checkbox, URL, gebruiker,
bestand-link):

![Standaardwaarden per veld instellen op een Team folder](../../screenshots/manage-teamfolders-defaultvalue.png)

- Vink een veld aan en voer de standaardwaarde in bij het **Standaardwaarde**-invoerveld dat verschijnt.
- Laat het veld leeg om een standaardwaarde te wissen.
- Gebruik na het opslaan **Standaardwaarden nu toepassen** om bestaande bestanden direct bij te werken
  (anders past een achtergrondtaak ze binnen enkele minuten toe).

**Wie kan standaardwaarden instellen:** beheerders (voor elke folder) en leden met het
MetaVox-beheerrecht die de folder mogen wijzigen. Die laatsten configureren velden en
standaardwaarden voor hun folder via **Instellingen → Persoonlijk → MetaVox**, waar alleen de
folders staan die zij mogen beheren. Zie [Rechten](../admin/permissions.md).

---

## Veld-scopes

MetaVox ondersteunt twee scopes voor metadata:

### Team folder-metadata

Metadata die geldt voor de hele Team folder. Zichtbaar op alle documenten binnen de folder (read-only op documentniveau).

### Document-metadata

Metadata specifiek voor individuele documenten binnen een Team folder. Bewerkbaar door gebruikers met document-bewerk-rechten.

---

## Zie ook

- [Gebruikersoverzicht](overview.md) — Aan de slag met metadata
- [Weergaven](views.md) — Filteren en sorteren op veldwaarden
- [Cellen markeren](highlight-rules.md) — Cellen kleuren op hun waarde
- [Bulk-bewerken](bulk-editing.md) — Velden voor meerdere bestanden bewerken
- [Data exporteren](exporting-data.md) — Metadata naar CSV exporteren
