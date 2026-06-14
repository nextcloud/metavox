# Back-up & herstel

MetaVox bevat ingebouwde back-up- en herstel-functionaliteit voor alle metadata-tabellen.

## Overzicht

Back-ups omvatten:

- **Veld-definities** (`metavox_gf_fields`)
- **Groupfolder-metadata** (`metavox_gf_metadata`)
- **Bestand-metadata** (`metavox_file_gf_meta`)

Back-ups worden opgeslagen als gzip-gecomprimeerde JSON-bestanden. Maximaal 7 back-ups worden automatisch bewaard.

![Back-up & herstel-paneel](../screenshots/backup-restore.png)

## Een back-up maken

### Via admin-instellingen

1. Ga naar **Instellingen → Beheer → MetaVox**
2. Navigeer naar de sectie **Back-up & herstel**
3. Klik **Back-up aanmaken**
4. Een voortgangsbalk toont de back-up-status

### Via API

```bash
curl -X POST "https://your-nextcloud.com/apps/metavox/api/backup/trigger" \
  -b "session-cookie"
```

### Automatische back-ups

MetaVox bevat een background-job (`MetadataBackupJob`) die back-ups automatisch kan maken via het cron-systeem van Nextcloud.

## Een back-up terugzetten

!!! warning "Herstellen overschrijft alles"
    Een back-up terugzetten vervangt **alle** huidige metadata door de back-up-data. Dit kan niet ongedaan worden gemaakt.

### Via admin-instellingen

1. Ga naar **Instellingen → Beheer → MetaVox**
2. Navigeer naar de sectie **Back-up & herstel**
3. Selecteer een back-up uit de lijst
4. Klik **Herstellen**
5. Bevestig de herstel-operatie
6. Een voortgangsbalk toont de herstel-status

### Via API

```bash
# Lijst beschikbare back-ups
curl "https://your-nextcloud.com/apps/metavox/api/backup/list" \
  -b "session-cookie"

# Specifieke back-up terugzetten
curl -X POST "https://your-nextcloud.com/apps/metavox/api/backup/restore" \
  -H "Content-Type: application/json" \
  -b "session-cookie" \
  -d '{"filename": "metavox_backup_2026-03-24_120000.json.gz"}'
```

## Een back-up downloaden

Back-ups kunnen worden gedownload voor off-server opslag:

```bash
curl "https://your-nextcloud.com/apps/metavox/api/backup/download?filename=metavox_backup_2026-03-24_120000.json.gz" \
  -b "session-cookie" \
  -o backup.json.gz
```

## Voortgang monitoren

De frontend pollt de status-endpoint tijdens back-up-/herstel-operaties:

```bash
curl "https://your-nextcloud.com/apps/metavox/api/backup/status" \
  -b "session-cookie"
```

**Response** (tijdens operatie):

```json
{
  "status": "restoring",
  "progress": 45,
  "table": "metavox_file_gf_meta",
  "rows_processed": 5000,
  "total_rows": 11000
}
```

**Response** (idle):

```json
{"status": "idle"}
```

## Performance

- Back-ups gebruiken keyset-paginering met chunks van 5.000 rijen voor geheugen-efficiëntie
- Herstellen gebruikt batch-inserts van 1.000 rijen met commits elke 10.000 rijen
- Zowel gzip-gecomprimeerde (`.json.gz`) als ongecomprimeerde (`.json`) back-ups worden ondersteund

## Zie ook

- [Installatie](installation.md) — Initiële setup
- [API-referentie](../architecture/api-reference.md) — Volledige API-documentatie
