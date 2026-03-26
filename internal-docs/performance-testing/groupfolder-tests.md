# MetaVox Groupfolder Performance Tests

## Overzicht

Deze groupfolder-specific performance tests testen de **ECHTE productie scenario's** van MetaVox:
- **Groupfolder metadata** (team folder niveau - `applies_to_groupfolder = 1`)
- **File metadata binnen groupfolders** (per file - `applies_to_groupfolder = 0`)

## Verschil met Global Tests

| Aspect | Global Tests | Groupfolder Tests |
|--------|--------------|-------------------|
| **Tabellen** | `oc_metavox_fields`<br>`oc_metavox_metadata` | `oc_metavox_gf_fields`<br>`oc_metavox_gf_metadata`<br>`oc_metavox_file_gf_meta` |
| **Scope** | `scope = 'global'` | Groupfolder ID specific |
| **Use Case** | Legacy/deprecated | **Production use case** |
| **Service** | FieldService (global methods) | FieldService (groupfolder methods) |

## Strategie

### Data Generatie: **Snel**
**GroupfolderDataGenerator** inject dummy data **direct in DB**:
- ✅ Geen echte files aanmaken (duurt uren!)
- ✅ Directe INSERT statements (secondes!)
- ✅ Dummy file IDs genereren

### Testing: **Realistisch**
Tests gebruiken **FieldService** methods (niet direct DB!):
- ✅ `getGroupfolderMetadata()` - groupfolder metadata
- ✅ `getGroupfolderFileMetadata()` - file metadata
- ✅ `getBulkFileMetadata()` - bulk operations
- ✅ Alle business logic wordt uitgevoerd
- ✅ Caches worden correct gebruikt

## Usage

### 1. Genereer Dummy Data (SNEL!)

```bash
cd /var/www/nextcloud

# Genereer 100K metadata records via DB injection (< 1 minuut!)
sudo -u www-data php occ metavox:gf-performance-test \
  --generate-data \
  --records=100000 \
  --user=admin
```

**Wat gebeurt er:**
- Maakt 1 test groupfolder (ID: 999999)
- Maakt 7 field definities (2 groupfolder, 5 file fields)
- Inject 20,000 dummy file IDs
- Inject 100,000 metadata records (20K files × 5 fields)
- **Duurt < 60 seconden** (vs 6+ uur voor echte files!)

### 2. Run Performance Tests (via Services!)

```bash
# Run alle groupfolder tests
sudo -u www-data php occ metavox:gf-performance-test \
  --suite=all \
  --user=admin

# Of specifieke test suites:
--suite=field         # FieldService tests
--suite=filter        # FilterService tests (groupfolder context)
--suite=search        # SearchService tests (groupfolder context)
```

**Wat wordt getest:**
- FieldService groupfolder methods
- FilterService met groupfolder files
- SearchService met groupfolder metadata
- Cache performance
- Bulk operations

### 3. Cleanup

```bash
sudo -u www-data php occ metavox:gf-performance-test \
  --cleanup \
  --user=admin
```

## Test Groupfolder Details

**Groupfolder ID:** 999999
**Mount Point:** MetaVox_Perf_Test
**Dummy File ID Range:** 20000000 - 20019999 (20K files)

### Groupfolder Fields (applies_to_groupfolder = 1)
- `gf_perf_team_name` (text)
- `gf_perf_department` (select)

### File Fields (applies_to_groupfolder = 0)
- `gf_perf_title` (text)
- `gf_perf_status` (select)
- `gf_perf_category` (select)
- `gf_perf_priority` (number)
- `gf_perf_tags` (multiselect)

## Performance Targets

| Metric | Target | Warning | Critical |
|--------|--------|---------|----------|
| getGroupfolderMetadata() | <50ms | 50-200ms | >200ms |
| getGroupfolderFileMetadata() | <20ms | 20-100ms | >100ms |
| getBulkFileMetadata(100) | <500ms | 500-2000ms | >2s |
| Groupfolder filter | <200ms | 200-1000ms | >1s |
| Groupfolder search | <100ms | 100-500ms | >500ms |

## Database Tables

### oc_metavox_gf_fields
Field definitions voor groupfolders.

**Belangrijke kolommen:**
- `applies_to_groupfolder`: 0 = file fields, 1 = groupfolder fields

### oc_metavox_gf_assigns
Welke fields zijn toegewezen aan welke groupfolders.

### oc_metavox_gf_metadata
Metadata voor de **groupfolder zelf** (applies_to_groupfolder = 1).

**Schema:**
- `groupfolder_id`
- `field_id`
- `field_value`

### oc_metavox_file_gf_meta
Metadata voor **files binnen groupfolders** (applies_to_groupfolder = 0).

**Schema:**
- `groupfolder_id`
- `file_id`
- `field_id`
- `field_value`

## Voordelen van Deze Aanpak

### ✅ Snelheid
- Data generatie: **< 1 minuut** (vs 6+ uur voor echte files)
- Direct DB insertion met bulk inserts
- Geen file I/O overhead

### ✅ Realisme
- Test via **productie services** (FieldService, FilterService, SearchService)
- Alle business logic wordt uitgevoerd
- Caches worden correct gebruikt
- Indexen worden correct getest

### ✅ Schaalbaarheid
- Test gemakkelijk met 100K, 1M, 10M records
- Geen disk space problemen (geen echte files)
- Geen file permission issues

### ✅ Reproduceerbaarheid
- Deterministische dummy data
- Gemakkelijk cleanup
- Geen dependencies op groupfolder app state

## Bekende Limitaties

1. **Geen echte files** - File I/O performance wordt niet getest
2. **Geen file permissions** - Nextcloud permission checks worden niet getest
3. **Geen groupfolder app integratie** - Groupfolders app methods worden niet aangeroepen
4. **Dummy file IDs** - File IDs bestaan niet in `oc_filecache`

Deze limitaties zijn **acceptabel** omdat we de **metadata performance** testen, niet file I/O performance.

## Resultaten

Resultaten worden opgeslagen in:
```
tests/Performance/results/gf_*.json
```

Format:
```json
{
  "test_class": "GroupfolderFieldServiceTest",
  "database": {
    "platform": "MariaDb1060Platform",
    "version": "10.6.22"
  },
  "metrics": [
    {
      "name": "getGroupfolderMetadata",
      "duration_avg_ms": 12.5,
      "duration_median_ms": 11.2,
      "duration_p95_ms": 15.8
    }
  ]
}
```

## Troubleshooting

### "Table 'oc_metavox_gf_fields' doesn't exist"
De groupfolder tables zijn nog niet aangemaakt. Zorg dat MetaVox correct geïnstalleerd is.

### "Groupfolder 999999 not found"
Run eerst `--generate-data` om de test groupfolder aan te maken.

### "No test files available"
Er is geen dummy data. Run `--generate-data`.

### Performance is slechter dan verwacht
Check:
1. Database indexes: `SHOW INDEX FROM oc_metavox_file_gf_meta`
2. Query cache: `SHOW VARIABLES LIKE 'query_cache%'`
3. InnoDB buffer pool: `SHOW VARIABLES LIKE 'innodb_buffer_pool_size'`

## Volgende Stappen

1. Run tests met 100K records
2. Analyseer bottlenecks
3. Optimaliseer queries/indexes
4. Re-test en vergelijk resultaten
5. Documenteer aanbevelingen voor productie

## Support

Zie ook:
- [README.md](README.md) - Algemene performance test documentatie
- [QUICK_START.md](QUICK_START.md) - Quick start guide
- [DEPLOYMENT.md](DEPLOYMENT.md) - Deployment instructies
