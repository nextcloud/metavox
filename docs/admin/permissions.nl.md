# MetaVox permissies

MetaVox volgt de rechten van Nextcloud zelf. Of iemand metadata mag zien of wijzigen, hangt af van wat Nextcloud toestaat voor het bestand en de Team folder, geavanceerde rechten (ACL) inbegrepen. MetaVox voegt één eigen recht toe: velden toekennen en standaardwaarden instellen.

## Wie mag wat

| Actie | Wie |
|-------|-----|
| Metadata van een bestand zien (kolommen, zijbalk, zoeken, export) | Iedereen die het bestand in zijn Team folder kan openen |
| Metadata van een bestand wijzigen (raster, zijbalk, bulkeditor, API) | Iedereen die het bestand in zijn Team folder mag wijzigen |
| Weergaven maken, wijzigen, ordenen en verwijderen | Leden die de Team folder mogen wijzigen |
| Metadata van de Team folder zelf wijzigen | Leden die de Team folder mogen wijzigen |
| Velden toekennen aan een Team folder, standaardwaarden instellen | Leden met het MetaVox-beheerrecht die de Team folder mogen wijzigen |
| Velddefinities maken, wijzigen en verwijderen | Nextcloud-beheerders |
| MetaVox-beheerinstellingen | Nextcloud-beheerders |

Nextcloud-beheerders mogen dit allemaal in elke Team folder.

- **Het bestand mogen wijzigen** betekent: het wijzigrecht van Nextcloud op het bestand in zijn Team folder, ACL inbegrepen. Een bestand dat je alleen via een share bereikt telt niet: metadata hoort bij de Team folder.
- Met ACL gelden de rechten per bestand. In een map waar je overal mag schrijven, blijft een bestand waarop je alleen leesrecht hebt alleen-lezen, in het raster én in de zijbalk.
- **De Team folder mogen wijzigen** betekent: het wijzigrecht van Nextcloud op de bovenste map van de Team folder, ACL inbegrepen.
- Weergaven zijn gedeeld door alle leden. Wie de Team folder mag wijzigen, mag ook weergaven van anderen wijzigen of verwijderen.
- In de webinterface bewerk je de eigen metadata van de Team folder in de persoonlijke instellingen → MetaVox, die alleen gebruikers met het beheerrecht zien. Andere leden die de Team folder mogen wijzigen, kunnen die metadata via de API wijzigen. In de zijbalk van Bestanden is ze altijd alleen-lezen.

## Het beheerrecht

Een beheerder geeft het aan groepen op de MetaVox-beheerpagina: Beheerinstellingen → MetaVox → **Rechten** → **Recht toevoegen**. Kies een of meer groepen onder **Selecteer groepen** en klik **Recht toekennen**. Het recht staat er als **Metadatavelden en weergaves beheren** en geldt voor elke Team folder. Met **Intrekken** haal je het weer weg.

Wat het recht toestaat, in een Team folder waar de gebruiker lid van is en die hij mag wijzigen:

- Velden aan de Team folder toekennen
- Standaardwaarden instellen voor de bestandsvelden ervan

Wie het voor minstens één Team folder heeft, vindt een MetaVox-onderdeel in de persoonlijke instellingen. Daar ken je met **Velden configureren** velden toe en stel je standaardwaarden in, en wijzig je met **Metadata bewerken** de eigen metadata van de Team folder.

Wat het recht niet doet:

- Het geeft geen toegang tot bestanden of metadata. Bestandsmetadata zien en wijzigen volgt de eigen rechten van Nextcloud.
- Het is niet nodig voor weergaven, ondanks het label. Weergaven maken en wijzigen vraagt schrijfrecht op de bovenste map van de Team folder, meer niet.
- Het staat niet toe velddefinities te maken, te wijzigen of te verwijderen. Dat blijft bij Nextcloud-beheerders.

## Als iets niet mag

- Wat je niet mag zien (een bestand, of een Team folder waar je geen lid van bent) geeft **404**, alsof het er niet is.
- Wat je wel ziet maar niet mag doen geeft **403**.
- API-antwoorden hebben een vaste `reason`, zoals `not_found`, `read_only`, `not_manager` of `forbidden`. Zie de [API-referentie](../architecture/api-reference.md).

## Best practices

### Voor beheerders

1. **Houd veld-lijsten beheerbaar** — te veel velden overweldigt gebruikers
2. **Gebruik heldere labels** — veldnamen moeten zelfsprekend zijn
3. **Voeg beschrijvingen toe** — help gebruikers begrijpen wat ze moeten invoeren
4. **Markeer kritieke velden als verplicht** — zorg dat belangrijke metadata wordt vastgelegd

### Voor organisaties

1. **Documenteer je schema** — houd bij wat elk veld betekent
2. **Train gebruikers** — leg uit waarom metadata ertoe doet
3. **Begin klein** — start met essentiële velden, voeg later meer toe
4. **Review regelmatig** — verwijder ongebruikte velden

## Problemen oplossen

### Gebruiker ziet geen metadata

1. Kan de gebruiker het bestand in de Team folder openen? Een bestand dat via ACL verborgen is, toont geen metadata.
2. Staat MetaVox aan voor de gebruiker?
3. Zijn er velden aan deze Team folder toegekend?

### Gebruiker kan de metadata van een document niet wijzigen

1. Heeft de gebruiker schrijfrecht op dit bestand? Kijk bij ACL naar het bestand zelf, niet alleen naar de map.
2. Geeft de Team folder de groep van de gebruiker alleen leesrecht?
3. Staat het bestand in een Team folder? Metadata van bestanden die je alleen via een share bereikt, kun je niet wijzigen.

### Gebruiker kan geen weergaven maken of wijzigen

De gebruiker heeft schrijfrecht nodig op de bovenste map van de Team folder. Schrijfrecht in alleen een submap is niet genoeg.

### Gebruiker kan geen velden toekennen of standaardwaarden instellen

De gebruiker heeft het beheerrecht nodig, via een van zijn groepen (MetaVox-beheerpagina → **Rechten**), en schrijfrecht op de bovenste map van de Team folder.

## Zie ook

- [Installatie](installation.md) — Initiële setup
- [Weergaven beheren](views.md) — Weergaven aanmaken per team folder
- [Compliance-templates](compliance-templates.md) — Voorgebouwde metadata-schema's
- [Aan de slag](../getting-started.md) — Snelstart-gids
