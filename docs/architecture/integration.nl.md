# MetaVox integratie-gids

Deze gids beschrijft het integreren van MetaVox met externe systemen, migreren vanaf andere platforms en het benutten van Nextcloud's ecosysteem.

## Nextcloud-integratie

### Group Folders

MetaVox vereist de Group Folders-app en integreert diep:

- **Folder-detectie**: detecteert automatisch tot welke group folder een bestand behoort
- **Permissie-overerving**: respecteert Group folder-ACL-instellingen
- **Metadata-scoping**: elke group folder kan unieke metadata-schema's hebben

### Nextcloud Flow

MetaVox registreert zich bij de Workflow Engine voor metadata-gebaseerde automatisering.

**Hoe het werkt:**

1. MetaVox implementeert `ICheck`-interface
2. Registreert via `RegisterChecksEvent`
3. Levert Vue-component voor voorwaarde-configuratie
4. Evalueert metadata-voorwaarden wanneer Flow triggert

**Voorbeeld Flow-regel:**

```
Trigger: Bestand geopend
Voorwaarde: MetaVox > classification = confidential
Actie: Blokkeer toegang
```

Zie [Flow-integratie](../admin/flow-integration.md) voor configuratie-details.

### Nextcloud Search

Metadata-waarden worden geïndexeerd en doorzoekbaar via Nextcloud's unified search. Gebruikers kunnen documenten vinden op basis van metadata-waarden.

## SharePoint-migratie

MetaVox is ontworpen om migraties vanaf Microsoft SharePoint te ondersteunen door document-metadata te behouden.

### Migratie-aanpak

```
SharePoint          MetaVox API              Nextcloud
┌─────────┐        ┌──────────────┐        ┌──────────┐
│Documenten│──────▶│batch-update  │───────▶│Group     │
│+Metadata│        │-endpoint     │        │Folders   │
└─────────┘        └──────────────┘        └──────────┘
```

### Migratie-stappen

1. **Exporteer SharePoint-metadata** — extract document-metadata naar JSON/CSV
2. **Maak veld-definities aan** — zet equivalente velden op in MetaVox
3. **Upload bestanden** — verplaats documenten naar Nextcloud-group folders
4. **Map metadata** — transformeer SharePoint-velden naar MetaVox-format
5. **Batch-importeer** — gebruik API om metadata te vullen

### Veld-mapping voorbeeld

| SharePoint-veld | MetaVox-equivalent |
|-----------------|---------------------|
| Content Type | `select`-veld met opties |
| Modified By | `usergroup`-veld |
| Retention Label | `select`-veld |
| Custom-kolommen | Diverse veldtypen |

### API voor migratie

Gebruik het batch-update-endpoint voor efficiënte imports:

```bash
POST /ocs/v2.php/apps/metavox/api/v1/files/metadata/batch-update

{
  "updates": [
    {
      "file_id": 123,
      "groupfolder_id": 1,
      "metadata": {
        "file_gf_status": "Goedgekeurd",
        "file_gf_department": "Juridisch",
        "file_gf_retention": "7 jaar"
      }
    },
    // ... meer bestanden
  ]
}
```

Zie [API-referentie](api-reference.md) voor complete documentatie.

### Migratie-tips

- **Batch-grootte**: beperk tot 100 bestanden per API-call
- **Veld-aanmaak**: maak velden eerst via API of importeer JSON-template
- **Validatie**: test met kleine batch vóór volledige migratie
- **Error-handling**: check response op partiële mislukkingen

## Externe-systeem-integratie

### Via OCS API

Externe systemen kunnen met MetaVox interacteren via de OCS REST API:

**Authenticatie:**

- Basic auth met app-wachtwoord
- Bearer token (bij gebruik van OAuth)

**Veelvoorkomende operaties:**

- Metadata ophalen voor een bestand
- Metadata bijwerken
- Batch-operaties
- Statistieken ophalen

### Webhook-integratie

Combineer MetaVox met Nextcloud Flow om externe systemen te triggeren:

1. Flow-regel detecteert metadata-voorwaarde
2. Flow roept webhook-actie aan
3. Extern systeem ontvangt notificatie

Voorbeeld: notificeer document-management-systeem wanneer documentstatus naar "Gepubliceerd" verandert.

### Custom-app-integratie

PHP-apps kunnen MetaVox-services direct gebruiken:

```php
use OCA\MetaVox\Service\FieldService;

$fieldService = \OC::$server->get(FieldService::class);

// Haal metadata op voor een bestand
$metadata = $fieldService->getGroupfolderFileMetadata($fileId, $groupfolderId);

// Update metadata
$fieldService->saveFileGfMetadata($fileId, $groupfolderId, $fieldName, $value);
```

## Data-uitwisselings-formaten

### JSON veld-definitie

```json
[
  {
    "field_name": "status",
    "field_label": "Document-status",
    "field_type": "select",
    "field_description": "Huidige status van het document",
    "field_options": [
      {"value": "Concept"},
      {"value": "In review"},
      {"value": "Goedgekeurd"},
      {"value": "Gearchiveerd"}
    ],
    "is_required": true
  }
]
```

### CSV-export

Bulk-editor exporteert metadata als CSV:

```csv
file_path,file_name,status,afdeling,herzieningsdatum
/Documenten/rapport.pdf,rapport.pdf,Goedgekeurd,Juridisch,2025-01-15
/Documenten/memo.docx,memo.docx,Concept,HR,
```

## Integratie-patronen

### Event-driven

```
Bestand geüpload → Flow detecteert → Check metadata → Trigger actie
```

Use case: automatische classificatie, notificaties, toegangscontrole.

### Scheduled

```
Cron-job → Bevraag API → Verwerk metadata → Update extern systeem
```

Use case: compliance-rapportage, sync met externe systemen.

### On-demand

```
Gebruikersactie → API-call → Get/update metadata → Geef resultaat terug
```

Use case: custom UI, mobile apps, integraties.

## Third-party-tools

MetaVox kan integreren met:

| Tool | Integratie-methode |
|------|---------------------|
| n8n | OCS API-calls |
| Windmill | OCS API-calls |
| Zapier | Webhooks via Flow |
| Power Automate | OCS API (met custom connector) |
| Custom scripts | OCS API, PHP-SDK |

## Best practices

1. **Gebruik batch-operaties** voor bulk-wijzigingen
2. **Valideer veldnamen** vóór API-calls
3. **Handel fouten netjes af** — check op partiële mislukkingen
4. **Cache veld-definities** in integraties
5. **Test in staging** vóór productie-migraties

## Zie ook

- [API-referentie](api-reference.md) — Complete API-documentatie
- [Architectuur-overzicht](overview.md) — Systeem-design
- [Privacy & beveiliging](privacy.md) — Databescherming
