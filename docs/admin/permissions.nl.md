# MetaVox permissies

MetaVox gebruikt Nextcloud's bestaande permissie-systeem. Deze gids legt uit hoe permissies voor metadata werken.

## Permissie-model

MetaVox heeft een granulair systeem met drie niveaus, toewijsbaar per gebruiker, per groep en per groupfolder:

| Permissie | Beschrijving | Standaard |
|-----------|--------------|-----------|
| `view_metadata` | Metadata-waarden bekijken | Alle gebruikers met folder-toegang |
| `edit_metadata` | Metadata bewerken voor bestanden | Gebruikers met schrijfrechten |
| `manage_fields` | Velden aanmaken/bewerken/verwijderen, weergaven beheren | Alleen beheerders |

Nextcloud-beheerders hebben altijd alle permissies, ongeacht configuratie.

### Permissie-matrix

| Actie | view_metadata | edit_metadata | manage_fields | Admin |
|-------|:-------------:|:-------------:|:-------------:|:-----:|
| Metadata in zijbalk bekijken | Ja | Ja | Ja | Ja |
| Metadata-kolommen in bestandenlijst zien | Ja | Ja | Ja | Ja |
| Documentmetadata bewerken | Nee | Ja | Ja | Ja |
| Bulk-metadata-editor gebruiken | Nee | Ja | Ja | Ja |
| Metadata naar CSV exporteren | Nee | Ja | Ja | Ja |
| Velden aanmaken/bewerken/verwijderen | Nee | Nee | Ja | Ja |
| Weergaven aanmaken/bewerken/verwijderen | Nee | Nee | Ja | Ja |
| Veld-definities import/export | Nee | Nee | Ja | Ja |
| MetaVox-admin-instellingen openen | Nee | Nee | Nee | Ja |

## Permissies toekennen

### Aan een gebruiker

```bash
curl -X POST "https://your-nextcloud.com/apps/metavox/api/permissions/user" \
  -H "Content-Type: application/json" \
  -b "session-cookie" \
  -d '{"user_id": "jane", "permission": "edit_metadata", "groupfolder_id": 3}'
```

### Aan een groep

```bash
curl -X POST "https://your-nextcloud.com/apps/metavox/api/permissions/group" \
  -H "Content-Type: application/json" \
  -b "session-cookie" \
  -d '{"group_id": "woo-beheerders", "permission": "manage_fields", "groupfolder_id": 3}'
```

### Permissies bekijken

```bash
# Alle permissies bekijken
curl "https://your-nextcloud.com/apps/metavox/api/permissions" -b "session-cookie"

# Eigen permissies checken
curl "https://your-nextcloud.com/apps/metavox/api/permissions/me" -b "session-cookie"

# Specifieke permissie checken
curl "https://your-nextcloud.com/apps/metavox/api/permissions/check?permission=edit_metadata&groupfolder_id=3" -b "session-cookie"
```

### Permissies intrekken

```bash
# Gebruikerspermissie intrekken
curl -X DELETE "https://your-nextcloud.com/apps/metavox/api/permissions/user/{permissionId}" -b "session-cookie"

# Groepspermissie intrekken
curl -X DELETE "https://your-nextcloud.com/apps/metavox/api/permissions/group/{permissionId}" -b "session-cookie"
```

## Overerving

Permissies zijn scope'd op groupfolders. Als een permissie zonder `groupfolder_id` wordt toegekend, geldt die voor alle groupfolders:

```
Team folder A (gebruiker heeft edit_metadata)
├── Submap A (erft edit_metadata)
│   ├── Document 1 (gebruiker kan metadata bewerken)
│   └── Document 2 (gebruiker kan metadata bewerken)
└── Submap B
    └── Document 3 (gebruiker kan metadata bewerken)

Team folder B (gebruiker heeft alleen view_metadata)
├── Document 4 (gebruiker kan alleen metadata zien)
```

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

1. Check of gebruiker leesrechten heeft op de Team folder
2. Verifieer dat MetaVox aan staat
3. Bevestig dat velden gedefinieerd zijn voor deze Team folder

### Gebruiker kan documentmetadata niet bewerken

1. Check of de gebruiker `edit_metadata`-permissie heeft voor deze groupfolder
2. Verifieer dat de gebruiker schrijfrechten heeft op het specifieke document in Nextcloud
3. Check of het document in een Team folder zit (niet een persoonlijke map)
4. Zorg dat metadata-velden gedefinieerd zijn voor deze Team folder

### Gebruiker wil velden of weergaven beheren

De gebruiker heeft de `manage_fields`-permissie nodig. Ken die toe via de admin-API of vraag een beheerder om hem toe te wijzen.

## Zie ook

- [Installatie](installation.md) — Initiële setup
- [Weergaven beheren](views.md) — Weergaven aanmaken per team folder
- [Compliance-templates](compliance-templates.md) — Voorgebouwde metadata-schema's
- [Aan de slag](../getting-started.md) — Snelstart-gids
