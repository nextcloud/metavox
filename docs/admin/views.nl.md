# Weergaven beheren

Je maakt, bewerkt en verwijdert weergaven per team folder, in de Files-app. Weergaven laten gebruikers snel wisselen tussen voor-gedefinieerde combinaties van kolommen, filters en sortering.

## Wie weergaven mag beheren

Leden van de team folder met schrijfrecht op de team folder zelf (het bovenste niveau), en Nextcloud-beheerders. Alle anderen in de team folder kunnen de weergaven gebruiken en alleen-lezen openen met **Details bekijken**.

Onder **Beheer → MetaVox** staat geen pagina voor weergaven; alle weergave-instellingen zitten in de Files-app.

## Een weergave aanmaken

1. Open de team folder in de Files-app
2. Klik op de weergavekiezer boven de bestandenlijst
3. Klik **Nieuwe weergave**
4. Voer een naam in
5. Configureer de opties (zie hieronder)
6. Klik **Opslaan**

De weergave verschijnt direct in het weergavemenu voor iedereen in die team folder.

Je kunt ook filters die je in de bestandenlijst zette omzetten in een weergave met **Filters bewaren als weergave …**. Zie [Weergaven gebruiken](../user/views.md).

![Weergave-editor](../../screenshots/view-editor.png)

## Instellingen

### Naam

De naam getoond in de weergavekiezer en het menu. Houd het kort en beschrijvend (bv. "WOO-verzoeken", "Wacht op review", "Publieke documenten"). Een naam heeft maximaal 100 tekens.

### Standaardweergave

Schakel **Standaardweergave** in om deze weergave automatisch te openen wanneer gebruikers de team folder openen. Slechts één weergave kan de standaardweergave zijn per team folder — een nieuwe instellen verwijdert dat van de vorige. De standaardweergave heeft een ster (★) in het weergavemenu.

Zonder standaardweergave opent de team folder in **Standaard**: de gewone bestandenlijst zonder MetaVox-kolommen.

### Kolommen

Bepaal welke velden in de bestandenlijst verschijnen en in welke volgorde:

| Instelling | Betekenis |
|------------|-----------|
| Zichtbaar | Het veld verschijnt als kolom in de bestandenlijst |
| Filterbaar | Het veld verschijnt in het MetaVox-filter van deze weergave, en in de sectie **Filters** van de editor |

Sleep het ⠿-handvat om rijen te herordenen. De volgorde hier bepaalt de links-naar-rechts kolom-volgorde in de bestandenlijst.

> **Let op**: filterbaar kan alleen ingeschakeld worden als het veld zichtbaar is. Zichtbaar uitvinken schakelt filterbaar automatisch uit.

Wijzigingen aan de Zichtbaar-checkbox werken direct de beschikbare velden in de **Filters**- en **Sortering**-secties van de weergave-editor bij.

### Filters (preset-waarden)

Stel filter-waarden in die activeren zodra de weergave geselecteerd wordt. Alleen velden die zowel **Zichtbaar** als **Filterbaar** zijn, verschijnen hier.

- **Select-/multi-select-velden**: vink de gewenste opties aan
- **Ja/Nee-velden**: vink "Ja" en/of "Nee" aan
- **Tekst-/getal-/gebruiker-velden**: typ een waarde en druk Enter (of een komma) om hem als tag toe te voegen; meerdere tags werken als OR

Datum- en bestandslink-velden hebben geen preset-filter.

Gebruikers kunnen bovenop de preset-filters verder filteren; de weergave zelf verandert daar niet door.

> **Let op**: waarden die je instelde vóór je een veld verbergt, blijven bewaard en blijven filteren, terwijl het veld niet meer in deze sectie staat. Gebruikers zien ze als chip boven de bestandenlijst. Verwijder de waarden voordat je het veld verbergt.

### Cellen markeren

Kleur cellen op hun waarde, bijvoorbeeld een deadline vóór vandaag in rood. Elke zichtbare kolom kan maximaal 10 regels hebben; past een cel bij meer regels, dan telt de bovenste. Zie [Cellen markeren](../user/highlight-rules.md) voor de voorwaarden, de kant-en-klare regels en hoe de volgorde werkt.

### Sortering

Kies het veld om op te sorteren en de richting (**Oplopend**/**Aflopend**), of laat het op "— geen sortering —". Alleen velden die als **Zichtbaar** gemarkeerd zijn, staan in de lijst. Wordt het veld later uit de team folder gehaald, dan verdwijnt de sortering uit de weergave.

## Een weergave bewerken

1. Wissel naar de weergave
2. Open de weergavekiezer en klik **Bewerken …**
3. Breng je wijzigingen aan en klik **Opslaan**

Wijzigingen gelden direct na opslaan voor iedereen.

## Een weergave verwijderen

Open de weergave-editor en klik **Verwijderen** (linksonder). Bevestig met **Weergave verwijderen**. Dit kan niet ongedaan worden gemaakt.

Zat je in de verwijderde weergave, dan opent de standaardweergave, of **Standaard** als die er niet is.

## Tips

- Maak een weergave met alleen de meest relevante kolommen de standaardweergave, om visuele ruis voor gebruikers te verminderen
- Gebruik preset-filters in combinatie met zichtbare kolommen — bv. een "WOO open"-weergave die alleen WOO-gerelateerde velden toont en filtert op status "Open"
- Kolom-volgorde wordt opgeslagen per weergave — verschillende weergaven kunnen verschillende kolom-volgordes hebben
- Weergaven worden gedeeld door alle gebruikers van een team folder; er zijn geen per-user-weergaven
- Checkbox-velden (Ja/Nee) bieden altijd beide "Ja" en "Nee" als filter-opties, ook als geen documenten nog aangevinkt zijn

## Zie ook

- [Weergaven gebruiken](../user/views.md) — Hoe gebruikers met weergaven werken
- [Cellen markeren](../user/highlight-rules.md) — Cellen kleuren op hun waarde
- [Permissies](permissions.md) — Rollen en toegangscontrole
- [Veldtypen](../user/field-types.md) — Beschikbare metadata-veldtypen
