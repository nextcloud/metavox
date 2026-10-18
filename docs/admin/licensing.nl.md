# Support & abonnementen

MetaVox is gratis en open source (AGPL-3.0). Alle features zijn altijd beschikbaar — geen limieten, geen beperkingen, geen verborgen voorwaarden. Een abonnement is optioneel en financiert actieve ontwikkeling, gegarandeerde Nextcloud-compatibility en e-mail-support.

## Gratis gebruik

MetaVox is volledig functioneel zonder abonnement. Geen feature-beperkingen, geen verminderde performance, geen vervallende trials. Je overtreedt niets door MetaVox zonder abonnement te gebruiken.

## Waarom abonneren?

Als MetaVox waardevol is voor je organisatie, helpt een abonnement het onderhouden en verbeteren.

| Inbegrepen | Beschrijving |
|------------|--------------|
| Gegarandeerde compatibility | Getest met elke nieuwe Nextcloud-release |
| E-mail-support | Directe support van de ontwikkelaars |
| Prioriteits-bugfixes | Jouw issues krijgen voorrang |
| Actieve ontwikkeling | Nieuwe features en verbeteringen |

## Prijzen

MetaVox-abonnementen worden verkocht via Nextcloud. Voor abonnementen en prijzen neem je contact op met je Nextcloud-accountmanager of [sales@nextcloud.com](mailto:sales@nextcloud.com).

Voor vragen over de app zelf mail je [info@voxcloud.nl](mailto:info@voxcloud.nl).

## Abonnements-banner

Zonder abonnementssleutel kan de MetaVox-beheerpagina bovenaan een blauwe informatieve banner tonen. Die toont hooguit één melding:

- Als Nextcloud een Enterprise-abonnement op deze instantie meldt: "Nextcloud Enterprise-abonnement gedetecteerd op deze instantie. MetaVox-abonnementen worden verkocht via Nextcloud — neem contact op met je Nextcloud-accountmanager of sales@nextcloud.com."
- Anders, als de instantie meer dan 100 gebruikers heeft: "MetaVox draait hier voor {count} gebruikers en blijft volledig werken zonder abonnement. …", met een verwijzing naar je accountmanager of sales@nextcloud.com

Gelden beide, dan wint de Enterprise-melding. Met 100 gebruikers of minder en geen Enterprise-abonnement is er geen banner. Dit is een vriendelijke herinnering, geen waarschuwing — alle features blijven normaal werken.

Is er een abonnementssleutel ingevoerd die ongeldig of verlopen is, dan zegt de banner in plaats daarvan "Je MetaVox abonnementssleutel vereist aandacht.", met een link **Ga naar Ondersteuning**.

De banner verdwijnt zodra er een abonnementssleutel is ingevoerd. Wegklikken kan niet.

De banner is alleen zichtbaar voor beheerders, nooit voor reguliere gebruikers.

## Een abonnements-sleutel invoeren

1. Ga naar **Instellingen → Beheer → MetaVox**
2. Open het onderdeel **Ondersteuning**
3. Voer je abonnements-sleutel in het veld **Abonnementssleutel** in (format: `MVOX-XXXX-XXXX-XXXX-XXXX`)
4. Klik **Opslaan & activeren**

MetaVox slaat de sleutel op, valideert hem bij de VoxCloud-licentieserver en meldt daar het gebruik om hem aan deze instantie te binden. Bij succes zie je "Abonnement geactiveerd!" en verdwijnt de banner. Mislukt de validatie, dan is de sleutel wel opgeslagen en noemt de melding de reden.

MetaVox vraagt nooit om een organisatienaam of contact-e-mail.

## Een abonnements-sleutel verwijderen

Als je abonnement afloopt en je kiest om niet te verlengen, kun je de sleutel verwijderen:

1. Ga naar **Instellingen → Beheer → MetaVox → Ondersteuning**
2. Klik **Abonnementssleutel verwijderen**
3. De sleutel wordt verwijderd en je keert terug naar de gratis gebruikssituatie

## Wat een abonnementssleutel verstuurt

Met een abonnementssleutel valideert een dagelijkse achtergrondtaak de sleutel en meldt hij het gebruik aan de VoxCloud-licentieserver (`licenses.voxcloud.nl`). Dit staat los van [telemetrie](telemetry.md) en draait ook als anonieme gebruiksstatistieken uit staan. Het verstuurt:

- De abonnementssleutel en de gehashte instantie-URL
- Het aantal Team folders met metadata-velden, metadata-items, gebruikers en uitgeschakelde gebruikers
- Een organisatienaam, alleen als een oudere MetaVox-versie er een heeft opgeslagen

Zonder abonnementssleutel verstuurt deze taak niets.

## Single-instance binding

Elke abonnements-sleutel is gebonden aan één Nextcloud-instantie. Wanneer je een sleutel opslaat en activeert, wordt hij gebonden via een SHA-256-hash van je instantie-URL: `overwrite.cli.url` uit `config.php`, of anders het eerste trusted domain. De URL zelf wordt nooit verstuurd. Wil je je abonnement naar een nieuwe instantie verhuizen (bv. server-migratie)? Mail [info@voxcloud.nl](mailto:info@voxcloud.nl).

## Offline / server onbereikbaar

Als de VoxCloud-licentie-server onbereikbaar is tijdens validatie, valt MetaVox terug op de laatst bekende validatie-uitkomst: een sleutel die geldig was, blijft geldig. MetaVox blokkeert nooit functionaliteit vanwege connectiviteits-problemen.

## Zie ook

- [Installatie](installation.md) — Vereisten en setup
- [Instellingen](settings.md) — AI- en telemetrie-instellingen
- [Telemetrie](telemetry.md) — Anonieme gebruiks-rapportage
