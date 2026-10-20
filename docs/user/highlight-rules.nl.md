# Cellen markeren

Met markeringsregels kleur je cellen in de bestandenlijst op basis van hun waarde: een verlopen deadline rood, een ontbrekende eigenaar oranje, een goedgekeurd document groen. De regels horen bij een [weergave](views.md), dus iedereen die die weergave gebruikt, ziet dezelfde markeringen.

Markeringen veranderen alleen hoe de lijst eruitziet. Ze passen geen bestanden of metadata aan en verbergen niets. Wil je alleen bepaalde bestanden zien, gebruik dan een filter.

## Wat je ziet

Een gemarkeerde cel krijgt een zachte achtergrond in de kleur van de regel, een balk aan de beginkant en een icoon. Elke kleur heeft een eigen icoon, zodat een markering nooit alleen op kleur leunt:

| Kleur | Icoon | Gebruikelijk voor |
|---|---|---|
| Rood | Badge met uitroepteken | Er is iets mis of te laat |
| Oranje | Waarschuwingsdriehoek | Vraagt aandacht |
| Groen | Vinkje | Klaar, in orde |
| Blauw | "i" | Ter informatie |

- De precieze tint volgt je Nextcloud-thema, ook in de donkere modus.
- Een kolom met regels toont in de kolomkop een palet-icoon naast de naam.
- Wijs het icoon aan om de tekst van de markering te zien, bijvoorbeeld wat er moet gebeuren: *Nog goedkeuren*. Een schermlezer leest die tekst voor bij de cel, bijvoorbeeld "Deadline: 3 oktober 2026, Te laat."
- Markeringen verschijnen in de lijstweergave van de app Bestanden. De tegelweergave toont ze niet.
- Regels over vandaag, zoals **Vóór vandaag**, volgen de kalender: om middernacht worden de cellen opnieuw gecontroleerd, ook als de pagina openstaat.

Wil je weten welke regels een weergave gebruikt? Open het weergavemenu boven de bestandenlijst en kies **Details bekijken**. De sectie **Cellen markeren** toont de regels per kolom, op volgorde. Beheer je weergaven, dan heet dit menu-item **Bewerken …**.

## Welke regel telt

Regels horen bij één kolom en kijken alleen naar de waarde in die kolom. Een cel krijgt **hooguit één** markering.

De regels van een kolom worden **van boven naar beneden** gecontroleerd. De eerste regel die past, bepaalt de kleur en de naam; de regels daaronder worden voor die cel niet meer bekeken. De editor zegt het boven de lijst: *Past een cel bij meer regels, dan telt de bovenste.*

**Voorbeeld.** Een kolom *Deadline* heeft drie regels:

| # | Voorwaarde | Kleur | Naam |
|---|---|---|---|
| 1 | Vóór vandaag | Rood | Te laat |
| 2 | Vandaag | Oranje | Vervalt vandaag |
| 3 | Binnen de komende 7 dagen | Oranje | Binnenkort |

Een deadline van vandaag past bij regel 2 én regel 3, want *binnen de komende 7 dagen* telt vandaag mee. Regel 2 staat hoger, dus de cel toont **Vervalt vandaag**. Wissel regel 2 en 3 om, en elke deadline van vandaag toont **Binnenkort**. Zet de meest specifieke of dringendste regel dus bovenaan.

In een paar gevallen telt een regel niet mee, of stopt de controle:

- **MetaVox kan het niet bepalen.** Kan een regel niet gecontroleerd worden, dan stopt MetaVox bij die regel en blijft de cel ongemarkeerd. Een regel verderop neemt het dan niet over. Dat gebeurt bij een waarde van meer dan 512 tekens, die afgekapt in de lijst aankomt: daarvoor worden alleen **Is leeg** en **Is niet leeg** bepaald; alle andere regels, ook die voor een checkbox, niet. Het gebeurt ook bij een opgeslagen waarde die in een Datum-veld geen datum is, of in een Getal-veld geen getal.
- **Mappen** hebben hun eigen metadata en worden gemarkeerd zoals bestanden, ook als hun waarde leeg is: **Ontbreekt** markeert een map zonder waarde.
- **Nooit gemarkeerd:** een rij waarvan de waarden nog laden, en een map uit een andere opslag die binnen de teammap staat, zoals een geneste teammap; die heeft daar geen MetaVox-waarden.
- **De regel past niet meer bij het veld.** Krijgt een veld een ander type, dan kloppen de regels soms niet meer. Zo'n regel markeert niets en wordt overgeslagen. De editor geeft het aan: *Deze regel werkt niet bij dit veld en markeert niets.*

## Markeringen instellen

Je kunt markeringsregels toevoegen en wijzigen als je de weergaven van de teammap mag beheren. Dat zijn de leden met schrijfrechten op de teammap. Ieder ander ziet de regels onder **Details bekijken**, zonder ze te kunnen wijzigen.

1. Open de teammap in de app Bestanden.
2. Open het weergavemenu boven de bestandenlijst en kies **Bewerken …**, of **Nieuwe weergave** voor een nieuwe.
3. Ga naar de sectie **Cellen markeren**. Daar staat elke kolom die onder **Kolommen** op **Zichtbaar** staat. Klik op een kolom om hem te openen.
4. Klik op **Regel toevoegen voor** *kolom* en kies een kant-en-klare regel of **Eigen regel**.
5. Kies een **Voorwaarde**, vul een waarde in als de voorwaarde daarom vraagt, en kies een **Kleur**. Vul eventueel een **Tekst bij het icoon** in.
6. **Voorbeeld** laat zien hoe de markering eruitziet en wat hij zegt. Klik op **Klaar**.
7. Klik op **Opslaan** om de weergave op te slaan.

Een regel wijzigen doe je door erop te klikken. De volgorde wijzigen of een regel verwijderen gaat via het menu **⋯** ernaast: **Omhoog**, **Omlaag** en **Verwijderen**.

- Een kolom kan maximaal **10 regels** hebben.
- Zolang een regel openstaat en niet compleet is, kun je niet **Opslaan**. Klik op **Klaar** om de regel af te ronden, of op **Annuleren** om de wijziging te laten vallen.
- Alleen zichtbare kolommen kunnen regels krijgen. Een kolom die je verbergt, **houdt** zijn regels; ze komen terug als je de kolom weer zichtbaar maakt. De editor noemt die kolommen onder *Verborgen kolommen houden hun regels*.

### Kant-en-klare regels

| Regel | Veldtypen | Voorwaarde | Kleur |
|---|---|---|---|
| Te laat | Datum | Vóór vandaag | Rood |
| Vervalt vandaag | Datum | Vandaag | Oranje |
| Binnenkort | Datum | Binnen de komende 7 dagen | Oranje |
| Ontbreekt | Alle behalve Checkbox | Is leeg | Oranje |
| Aangevinkt | Checkbox | Is aangevinkt | Groen |
| Niet aangevinkt | Checkbox | Is niet aangevinkt | Oranje |
| Toegewezen aan wie kijkt | Personen en groepen | Is de persoon die kijkt, of een van diens groepen of teams | Blauw |

- Elke kant-en-klare regel kun je één keer per kolom toevoegen. Daarna wijzig je voorwaarde, kleur en naam zoals bij elke andere regel.
- Kant-en-klare regels krijgen vanzelf een logische plek: **Te laat**, **Vervalt vandaag**, **Binnenkort**, **Ontbreekt**. Een eigen regel komt onderaan; schuif hem omhoog als hij eerder moet tellen.
- **Toegewezen aan wie kijkt** is persoonlijk: ieder ziet de bestanden die aan hem of haar zijn toegewezen gemarkeerd, met de naam *Toegewezen aan jou*.

### Voorwaarden per veldtype

| Veldtype | Voorwaarden |
|---|---|
| Tekst, Tekstvak, URL | Is, Is niet, Bevat, Bevat niet, Is leeg, Is niet leeg |
| Getal | Is, Is niet, Groter dan, Kleiner dan, Minstens, Hoogstens, Is leeg, Is niet leeg |
| Datum | Vóór vandaag, Vandaag, Na vandaag, Binnen de komende … dagen, Meer dan … dagen geleden, Vóór een datum, Op een datum, Na een datum, Is leeg, Is niet leeg |
| Select (Dropdown) | Is, Is niet, Is een van, Is leeg, Is niet leeg |
| Multi-select | Bevat een van, Bevat alle van, Is leeg, Is niet leeg |
| Checkbox | Is aangevinkt, Is niet aangevinkt |
| Personen en groepen | Is de persoon die kijkt, Is leeg, Is niet leeg |
| Bestand-link | Is leeg, Is niet leeg |

### Hoe voorwaarden vergelijken

- **Tekst** wordt vergeleken zonder op hoofd- en kleine letters te letten. Spaties voor en na de waarde tellen niet mee.
- **Lege cellen.** *Is niet*, *Bevat niet* en *Is niet aangevinkt* passen ook bij een lege cel. De andere voorwaarden, zoals *Is*, *Bevat*, *Groter dan* en alle datumvoorwaarden, nooit. Wil je lege cellen anders behandelen, zet dan een regel **Is leeg** erboven.
- **Datums** worden per dag vergeleken; een tijdstip telt niet mee. *Binnen de komende 7 dagen* loopt van vandaag tot en met de zevende dag vanaf nu. *Meer dan 30 dagen geleden* past bij datums vóór de dag van 30 dagen geleden. Een aantal dagen is een heel getal van 1 tot en met 3650.
- **Vandaag** is de datum op de computer van wie kijkt. Rond middernacht kunnen twee mensen in verschillende tijdzones dus verschillende markeringen zien.
- **Getallen** typ je zonder scheidingstekens voor duizendtallen, bijvoorbeeld `10000`. Een decimale komma mag.
- **Opties.** Een regel op een Select- of Multi-select-veld gebruikt de tekst van de optie. Wordt die optie later uit het veld verwijderd, dan houdt de regel hem, en de editor meldt: *Deze regel gebruikt een optie die het veld niet meer heeft.*

### De tekst bij het icoon

**Tekst bij het icoon** is optioneel en mag maximaal 40 tekens lang zijn. Je ziet hem als je het icoon in een gemarkeerde cel aanwijst, dus het is een goede plek voor wat er moet gebeuren: *Nog goedkeuren*, *Verzekering verlengen*. Zonder tekst toont een kant-en-klare regel zijn eigen naam (*Te laat*) en een eigen regel wat hij controleert (*vóór vandaag*). Een kant-en-klare regel houdt zijn naam alleen zolang voorwaarde en waarde ongewijzigd zijn.

De tekst staat ook onder **Details bekijken** en wordt door schermlezers bij de cel voorgelezen. Zeg dus wat de toestand betekent of wat er moet gebeuren, niet de kleur.

## Goed om te weten

- Markeringsregels worden met de weergave opgeslagen. Ze zitten niet in de MetaVox-back-up.
- Wie de map al openheeft, ziet gewijzigde regels na het herladen van de pagina.
- Koppelingen kunnen de regels lezen en schrijven via de API: zie [Highlight rules in de API-referentie](../architecture/api-reference.md#highlight-rules-format).

## Zie ook

- [Weergaven](views.md) - Tussen weergaven wisselen en filteren
- [Weergaven beheren](../admin/views.md) - Weergaven aanmaken en instellen
- [Veldtypen](field-types.md) - De metadata-veldtypen
