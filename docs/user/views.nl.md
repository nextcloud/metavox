# Weergaven

Weergaven laten je snel wisselen tussen voor-gedefinieerde combinaties van kolommen, filters en sortering binnen een team folder.

## Wat is een weergave?

Een weergave definieert:

- Welke metadata-kolommen zichtbaar zijn in de bestandenlijst
- De volgorde van die kolommen
- Welke filters voor-ingesteld zijn
- Hoe bestanden worden gesorteerd

Weergaven worden door een beheerder geconfigureerd en gelden per team folder. Elke team folder kan meerdere weergaven hebben voor verschillende workflows of doelgroepen.

> **Beheerders**: zie [Weergaven beheren](../admin/views.md) voor aanmaken en beheren.

## Tussen weergaven wisselen

Boven de bestandenlijst zie je een balk met de beschikbare weergaven voor de huidige team folder. Klik op een tab om die weergave te activeren.

De actieve weergave is vetgedrukt met een blauwe stip. Als een weergave een potlood-icoon (✎) toont, opent klikken daarop de editor (alleen voor beheerders).

![Weergave-tabs](../screenshots/views-tabs.png)

## Filters tijdelijk aanpassen

Naast de weergave-tabs verschijnt de **MetaVox-filterknop** in de Nextcloud-filterbalk. Gebruik die om extra filters bovenop de actieve weergave toe te passen.

1. Klik op de **MetaVox**-knop in de filterbalk
2. Klik op een veldnaam om de filter-opties voor dat veld uit te klappen
3. Vink één of meerdere waarden aan
4. De bestandenlijst werkt direct bij

Elk veld toont als inklapbare sectie. Een badge naast de veldnaam toont hoeveel filter-waarden actief zijn voor dat veld.

![Filter-paneel](../screenshots/filter-panel.png)

Filters die zo zijn ingesteld zijn **tijdelijk** — ze worden niet opgeslagen in de weergave en verdwijnen als je weg-navigeert of van weergave wisselt.

### Filteren op meerdere waarden

Binnen één veld werkt het selecteren van meerdere waarden als OR — bestanden die match'en met **een van** de aangevinkte waarden worden getoond.

Tussen verschillende velden werken filters als AND — bestanden moeten match'en met de actieve filter voor **elk** veld.

### Ja / Nee-filtering

Voor checkbox-velden (Ja/Nee) kun je filteren op:

- **Ja** — bestanden waarbij het veld aangevinkt is
- **Nee** — bestanden waarbij het veld uit staat of geen waarde heeft

Beide opties zijn altijd beschikbaar, ongeacht welke waarden in de folder voorkomen.

### Filters wissen

- **Selectie wissen** (per veld zichtbaar bij actieve filter): verwijdert filters voor alleen dat veld
- **Filters wissen** (knop onderaan): verwijdert alle tijdelijke filters in één keer

## Standaard weergave

Een team folder kan een standaard weergave hebben die automatisch activeert bij openen. De standaard wordt aangeduid met een ster (★) in het admin-paneel. Geen standaard ingesteld? Dan is er geen weergave voor-geselecteerd.

## Tips

- Wisselen naar een andere weergave reset eventuele tijdelijke filters die je had toegepast
- De actieve weergave en filter-state worden bewaard in de URL — je kunt een specifieke weergave bookmarken of delen
- Weergaven beïnvloeden alleen de bestandenlijst-weergave; ze veranderen of beperken de daadwerkelijke bestanden of metadata niet
- Alleen velden die zowel **Zichtbaar** als **Filterbaar** zijn, verschijnen in het filter-paneel
- Kolom-volgorde in de bestandenlijst volgt wat de beheerder per weergave instelde

## Zie ook

- [Bulk-bewerken](bulk-editing.md) — Metadata voor meerdere bestanden bewerken
- [Veldtypen](field-types.md) — Metadata-veldtypen begrijpen
- [Weergaven beheren](../admin/views.md) — Weergaven aanmaken en configureren (beheerders)
