# Aan de slag met MetaVox

MetaVox voegt gestructureerde metadata toe aan documenten in Nextcloud Team folders. Deze gids helpt je snel op weg.

## Wat is MetaVox?

MetaVox verrijkt je documenten met contextuele informatie zoals:

- Documentstatus (concept, goedgekeurd, gearchiveerd)
- Classificatie (publiek, vertrouwelijk)
- Eigendom en verantwoordelijkheid
- Compliance-informatie (bewaartermijn, AVG-grondslagen)

Deze metadata wordt los van de documentinhoud opgeslagen, wat het doorzoekbaar en actionable maakt.

## Snelstart per rol

### Gebruikers

1. Navigeer naar een document in een Team folder
2. Open de zijbalk (klik op het info-icoon of druk `i`)
3. Bekijk en bewerk metadata in de MetaVox-sectie

Zie [Gebruikersoverzicht](user/overview.md) voor uitgebreide instructies.

### Beheerders

1. Ga naar **Instellingen → MetaVox**
2. Selecteer een Team folder om te configureren
3. Definieer metadata-velden (tekst, dropdown, datum, etc.)
4. Optioneel: importeer een [compliance-template](admin/compliance-templates.md)
5. Optioneel: configureer [licentiëring](admin/licensing.md)

Zie [Installatie-gids](admin/installation.md) voor setup-details.

### Architecten

Bekijk het [Architectuur-overzicht](architecture/overview.md) om te begrijpen:

- Hoe metadata wordt opgeslagen (lokale database, geen externe dependencies)
- Integratiepunten (Nextcloud Flow, OCS API)
- Privacy-garanties (alle data blijft on-premise)

## Kernconcepten

| Concept | Beschrijving |
|---------|--------------|
| **Team folder-metadata** | Velden gedefinieerd per Team folder, zichtbaar op alle documenten (read-only op documentniveau) |
| **Documentmetadata** | Velden specifiek voor individuele documenten, bewerkbaar door gebruikers met schrijfrechten |
| **Veldtypen** | Tekst, getal, datum, dropdown, multi-select, checkbox, URL, user-picker, file-link |
| **Weergaven** | Voor-gedefinieerde combinaties van kolommen, filters en sortering; geconfigureerd per Team folder door beheerders |
| **Kolomconfig** | Bepaalt welke metadata-velden als kolommen verschijnen in de bestandenlijst en in welke volgorde |

## Volgende stappen

- [Gebruikersgids](user/overview.md) — Dagelijks werken met metadata
- [Beheergids](admin/installation.md) — MetaVox configureren
- [API-referentie](architecture/api-reference.md) — Programmatische toegang

## Zie ook

- [Gebruikersoverzicht](user/overview.md) — Aan de slag als gebruiker
- [Installatie-gids](admin/installation.md) — Setup en configuratie
- [Architectuur-overzicht](architecture/overview.md) — Systeem-design en internals
