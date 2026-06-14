# Weergaven beheren

Als beheerder kun je weergaven aanmaken, bewerken en verwijderen per team folder. Weergaven laten gebruikers snel wisselen tussen voor-gedefinieerde combinaties van kolommen, filters en sortering.

## Instellingen openen

1. Ga naar **Beheer → MetaVox**
2. Selecteer de team folder
3. Open de tab **Weergaven**

## Een weergave aanmaken

1. Klik **+ Nieuwe weergave**
2. Voer een naam in
3. Configureer de opties (zie hieronder)
4. Klik **Opslaan**

De weergave verschijnt direct in de tab-balk voor gebruikers in die team folder.

![Weergave-editor](../screenshots/view-editor.png)

## Instellingen

### Naam

De naam getoond in de weergave-tab-balk. Houd het kort en beschrijvend (bv. "WOO-verzoeken", "Wacht op review", "Publieke documenten").

### Standaard

Schakel **Standaard** in om deze weergave automatisch te activeren wanneer gebruikers de team folder openen. Slechts één weergave kan de standaard zijn per team folder — een nieuwe standaard instellen verwijdert dat van de vorige.

### Kolommen

Bepaal welke velden in de bestandenlijst verschijnen en in welke volgorde:

| Instelling | Betekenis |
|------------|-----------|
| Zichtbaar | Het veld verschijnt als kolom in de bestandenlijst |
| Filterbaar | Het veld is beschikbaar als preset-filter in de editor van deze weergave |

Sleep het ⠿-handvat om rijen te herordenen. De volgorde hier bepaalt de links-naar-rechts kolom-volgorde in de bestandenlijst.

> **Let op**: filterbaar kan alleen ingeschakeld worden als het veld zichtbaar is. Zichtbaar uitvinken schakelt filterbaar automatisch uit.

Wijzigingen aan de Zichtbaar-checkbox werken direct de beschikbare velden in de **Filters**- en **Sortering**-secties van de weergave-editor bij.

### Filters (preset-waarden)

Stel standaard filter-waarden in die activeren zodra de weergave geselecteerd wordt. Alleen velden die zowel **Zichtbaar** als **Filterbaar** zijn, verschijnen hier.

- **Select-/multi-select-velden**: vink de gewenste opties aan
- **Ja/Nee-velden**: vink "Ja" en/of "Nee" aan
- **Tekst-/getal-/gebruiker-velden**: typ een waarde en druk Enter om hem als tag toe te voegen; meerdere tags werken als OR

Preset-filters combineren met tijdelijke filters die een gebruiker er bovenop toepast.

### Sortering

Kies het veld om op te sorteren en de richting (oplopend/aflopend). Alleen velden die als **Zichtbaar** gemarkeerd zijn, verschijnen in de sort-dropdown. Wordt een veld later verborgen door Zichtbaar uit te vinken, dan reset de sortering automatisch naar "geen sortering".

## Een weergave bewerken

Klik op het **potlood-icoon (✎)** naast een weergave in de tab-balk (zichtbaar voor admins), of open de weergave vanuit het admin-paneel. Wijzigingen werken direct na opslaan.

## Een weergave verwijderen

Open de weergave-editor en klik **Verwijderen** (rode knop, linksonder). Dit kan niet ongedaan worden gemaakt.

## Tips

- Maak een "Standaard"-weergave die alleen de meest relevante kolommen toont, om visuele ruis voor gebruikers te verminderen
- Gebruik preset-filters in combinatie met zichtbare kolommen — bv. een "WOO open"-weergave die alleen WOO-gerelateerde velden toont en filtert op status "Open"
- Kolom-volgorde wordt opgeslagen per weergave — verschillende weergaven kunnen verschillende kolom-volgordes hebben
- Weergaven worden gedeeld door alle gebruikers van een team folder; er zijn geen per-user-weergaven
- De Filters-sectie toont alleen velden die Zichtbaar zijn in de huidige weergave — een veld verbergen verwijdert het ook uit de filter-opties
- Checkbox-velden (Ja/Nee) bieden altijd beide "Ja" en "Nee" als filter-opties, ook als geen documenten nog aangevinkt zijn

## Zie ook

- [Weergaven gebruiken](../user/views.md) — Hoe gebruikers met weergaven werken
- [Permissies](permissions.md) — Rollen en toegangscontrole
- [Veldtypen](../user/field-types.md) — Beschikbare metadata-veldtypen
