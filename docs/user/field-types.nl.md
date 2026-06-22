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

### Tekstvak (Textarea)

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

- **Tijd-component meenemen** (standaard: uit) — legt uren, minuten en seconden vast naast de datum. Opgeslagen als floating ISO 8601-string (zonder tijdzone), conform SharePoint's `SPFieldDateTime` met `DisplayFormat = DateTime`.

Bij het aanmaken of bewerken van een Datum-veld in de admin-instellingen, vink **Tijd-component meenemen** aan om het tijdgedeelte in te schakelen:

![Datum-veld met "Tijd-component meenemen" checkbox](../../screenshots/fields-datetime.png)

In de bestandszijbalk en inline-grid-editor toont het veld dan een datetime-picker:

![Datetime-input in de bestandszijbalk](../../screenshots/fields-datetime-view.png)

**SharePoint-migratie-notitie**: kolommen met `DisplayFormat = DateOnly` mappen naar MetaVox Datum-velden met deze optie **uit**; `DateTime` mapt naar **aan**. Bestaande pre-2.1.0 Datum-velden tonen alleen-datum — er wordt geen data-migratie uitgevoerd. CSV-import-ondersteuning is gepland voor een toekomstige release.

---

### Checkbox

Een boolean ja/nee-schakelaar.

**Use cases**: goedkeur-status, vertrouwelijk-vlag, gepubliceerd-status

**Voorbeeld**: ✅ (aangevinkt) of ☐ (niet aangevinkt)

**Opties**: geen

---

### Select (Dropdown)

Een dropdown-menu met voor-gedefinieerde opties. Gebruikers selecteren één waarde.

**Use cases**: status, categorie, afdeling, documenttype

**Voorbeeld**: "In review" (uit opties: Concept, In review, Goedgekeurd, Gearchiveerd)

**Opties**: definieer de beschikbare keuzes als komma-gescheiden waarden in de veld-configuratie.

---

### Multi-select

Vergelijkbaar met Select, maar staat meerdere selecties toe.

**Use cases**: tags, toepasselijke afdelingen, gerelateerde onderwerpen

**Voorbeeld**: "Juridisch, Financiën" (uit opties: Juridisch, Financiën, HR, IT)

**Opties**: definieer de beschikbare keuzes als komma-gescheiden waarden in de veld-configuratie.

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

### Gebruiker-picker

Selecteer een Nextcloud-gebruiker uit een doorzoekbare dropdown. Toont gebruikers-avatars.

**Use cases**: document-eigenaar, reviewer, verantwoordelijk persoon, auteur

**Features**:

- Doorzoekbare gebruikerslijst
- Toont gebruikers-avatar en weergavenaam
- Integreert met de Nextcloud-gebruikersdatabase

![Gebruiker-picker-veld](../../screenshots/Fields-User.png)

---

### Bestand-link

Blader en link naar één of meer bestanden of mappen binnen Nextcloud via de native file-picker.

**Use cases**: gerelateerde documenten, bron-bestanden, bijlagen, ouder-documenten

**Features**:

- Native Nextcloud-file-picker-integratie
- **Meerdere bestanden per veld** — gebruik **Bestand toevoegen** om meerdere bestanden te koppelen; verwijder er een met de **×**-knop
- Koppelingen worden opgeslagen op **bestands-ID**, zodat een verwijzing blijft werken nadat het doelbestand hernoemd of verplaatst is
- Hetzelfde bestand kan niet twee keer worden toegevoegd (duplicaten worden geweigerd)
- Klik op een gekoppeld bestand om het te openen
- **Referenced by** — op een gekoppeld bestand zie je welke items ernaar verwijzen (backlinks)

![Bestand-link-veld met meerdere gekoppelde bestanden](../../screenshots/fields-filelink-multiselect.png)

> Enkelvoudige koppelingen uit oudere versies blijven ongewijzigd werken; je kunt er op elk moment meer bestanden aan toevoegen.

---

## Veld-configuratie

Bij het aanmaken van een veld in de admin-instellingen kun je configureren:

| Instelling | Beschrijving |
|------------|--------------|
| **Veldnaam** | Interne identifier (geen spaties, lowercase aanbevolen) |
| **Veldlabel** | Display-naam getoond aan gebruikers |
| **Veldtype** | Een van de hierboven beschreven typen |
| **Beschrijving** | Help-tekst getoond onder het veld |
| **Verplicht** | Of het veld moet worden ingevuld |
| **Opties** | Voor Select en Multi-select velden: komma-gescheiden lijst van keuzes |

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

- Vink een veld aan en voer de standaardwaarde in bij het **Default value**-invoerveld dat verschijnt.
- Laat het veld leeg om een standaardwaarde te wissen.
- Gebruik na het opslaan **Apply defaults now** om bestaande bestanden direct bij te werken
  (anders past een achtergrondtaak ze binnen enkele minuten toe).

**Wie kan standaardwaarden instellen:** beheerders (voor elke folder) en gebruikers met de
per-folder-rechten **Velden beheren** (`manage_fields`). Die laatsten configureren velden en
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
- [Bulk-bewerken](bulk-editing.md) — Velden voor meerdere bestanden bewerken
- [Data exporteren](exporting-data.md) — Metadata naar CSV exporteren
