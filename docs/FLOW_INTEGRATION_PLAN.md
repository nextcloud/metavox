# MetaVox + Nextcloud Flow Integratie - Analyse & Plan

## Samenvatting

**Vraag**: "Could Metavox be integrated with the Flow app. For a file containing metadata, it would be nice to add the metadata accessible to do some rules conditioning."

**Antwoord**: **JA, dit is haalbaar.** Nextcloud Flow biedt een uitbreidbaar check-systeem via de `ICheck` interface waarmee MetaVox metadata als conditie kan worden toegevoegd.

---

## Wat is de integratie?

Na implementatie kun je Flow regels maken zoals:
- "Als bestand geüpload wordt EN metadata veld 'Status' = 'Goedgekeurd' → verplaats naar map X"
- "Als bestand gewijzigd wordt EN metadata veld 'Prioriteit' = 'Hoog' → stuur notificatie"
- "Blokkeer toegang als metadata veld 'Vertrouwelijk' = 'Ja'"

---

## Technische Haalbaarheid

| Aspect | Status | Toelichting |
|--------|--------|-------------|
| **Flow extensie-API** | ✅ Beschikbaar | `ICheck` interface + `RegisterChecksEvent` |
| **MetaVox metadata ophalen** | ✅ Bestaat al | `FieldService::getGroupfolderFileMetadata()` |
| **Groupfolder detectie** | ✅ Bestaat al | Logica in `FileCopyListener::getGroupfolderId()` |
| **Frontend componenten** | ⚠️ Nieuw nodig | Vue component voor veld/waarde selectie |

---

## Implementatie Overzicht

### Nieuwe bestanden (4 stuks)

```
metavox/lib/Flow/
├── MetadataCheck.php          # ICheck implementatie (PHP)
└── RegisterChecksListener.php # Event listener voor registratie

metavox/src/flow/
├── MetadataCheck.vue          # UI component voor check configuratie
└── flow-main.js               # Frontend registratie script
```

### Bestaande bestanden aan te passen (2 stuks)

| Bestand | Wijziging |
|---------|-----------|
| `lib/AppInfo/Application.php` | Event listener toevoegen voor `RegisterChecksEvent` |
| `webpack.config.js` | Nieuwe entry point `flow` toevoegen |

---

## Implementatie Stappen

### 1. PHP Check Class (`lib/Flow/MetadataCheck.php`)

Implementeert `OCP\WorkflowEngine\ICheck` en `IFileCheck`:
- `executeCheck($operator, $value)` - Evalueert metadata conditie
- `validateCheck($operator, $value)` - Valideert configuratie
- `supportedEntities()` - Retourneert `[File::class]`
- `setFileInfo()` / `setEntitySubject()` - Ontvangt bestandsinfo van Flow

Hergebruikt bestaande logica:
- `FieldService::getGroupfolderFileMetadata()` voor metadata ophalen
- Groupfolder detectie patroon uit `FileCopyListener`

### 2. Event Listener (`lib/Flow/RegisterChecksListener.php`)

Luistert naar `RegisterChecksEvent` en registreert de `MetadataCheck`.

### 3. Vue Component (`src/flow/MetadataCheck.vue`)

UI voor check configuratie:
- Dropdown om metadata veld te selecteren
- Waarde input (dynamisch op basis van veldtype: select/text/checkbox)
- Slaat configuratie op als JSON: `{"field":"Status","value":"Approved"}`

### 4. Frontend Registratie (`src/flow/flow-main.js`)

Registreert check bij Nextcloud WorkflowEngine:
```javascript
OCA.WorkflowEngine.registerCheck({
    class: 'OCA\\MetaVox\\Flow\\MetadataCheck',
    name: t('metavox', 'File metadata'),
    operators: [
        { operator: 'is', name: 'is' },
        { operator: '!is', name: 'is not' },
    ],
    component: MetadataCheck,
})
```

### 5. Application.php Update

```php
$context->registerEventListener(
    RegisterChecksEvent::class,
    RegisterChecksListener::class
);
```

### 6. Webpack Config Update

```javascript
entry: {
    // ...existing entries...
    flow: path.join(__dirname, 'src', 'flow', 'flow-main.js'),
}
```

---

## Complexiteit

**MEDIUM**

| Factor | Niveau |
|--------|--------|
| PHP backend | Laag - ICheck interface is eenvoudig |
| Groupfolder detectie | Laag - Logica bestaat al |
| Vue component | Medium - Dynamische veldtypes |
| Testing | Medium - Verschillende scenario's |

---

## Alternatieven

### Alternatief 1: Tag-sync (Lager risico, minder flexibel)
Synchroniseer metadata automatisch naar Nextcloud systeem-tags. Gebruik dan de bestaande `FileSystemTags` check.

**Nadeel**: Tag-vervuiling, minder precisie, geen complexe waarden.

### Alternatief 2: Externe workflow tools
Gebruik OCS API van MetaVox met n8n of Windmill.

**Nadeel**: Geen native Flow integratie, externe configuratie nodig.

---

## Kritieke Bestanden

Te raadplegen voor implementatie:
- `lib/AppInfo/Application.php` - Bootstrap/registratie
- `lib/Service/FieldService.php` - Metadata ophalen methodes
- `lib/Listener/FileCopyListener.php` - Groupfolder detectie logica
- `webpack.config.js` - Build configuratie
- `src/components/fields/*.vue` - Referentie voor veldtype handling

---

## Conclusie

De integratie is **technisch haalbaar** en past goed binnen de bestaande architectuur van zowel MetaVox als Nextcloud Flow. De implementatie hergebruikt veel bestaande code en volgt standaard Nextcloud patronen.

**Aanbeveling**: Implementeer de custom `ICheck` aanpak (niet tag-sync) voor maximale flexibiliteit en een schone oplossing.

---

## Bronnen

- [Nextcloud Flow Documentation](https://docs.nextcloud.com/server/latest/developer_manual/digging_deeper/flow.html)
- [Nextcloud Flow Overview](https://nextcloud.com/flow/)
- [WorkflowEngine Manager.php](https://github.com/nextcloud/server/blob/master/apps/workflowengine/lib/Manager.php)
- [Files Access Control Operation](https://github.com/nextcloud/files_accesscontrol/blob/main/lib/Operation.php)
