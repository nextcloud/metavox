# MetaVox Performance Tests - Quick Start Guide

## TL;DR

```bash
# In je Nextcloud directory:
cd /path/to/nextcloud

# 1. Genereer 10M test records (duurt 30-60 min)
sudo -u www-data php occ metavox:performance-test --generate-data --records=10000000 --user=admin

# 2. Run alle performance tests
sudo -u www-data php occ metavox:performance-test --suite=all --user=admin

# 3. Bekijk resultaten
cat apps/metavox/tests/Performance/results/*.json

# 4. Cleanup
sudo -u www-data php occ metavox:performance-test --cleanup --user=admin
```

## Wat wordt er getest?

### 1. Field Service (read/write performance)
- `getAllFields()` - Hoe snel kunnen we alle velden ophalen?
- `saveFieldValue()` - Hoeveel writes/sec kunnen we aan?
- `getBulkFileMetadata()` - Batch performance met 100-1000 files

**Verwachte resultaten bij 10M records:**
- getAllFields: <100ms
- saveFieldValue: >100 writes/sec
- getBulk(100): <500ms

### 2. Filter Service (complexe queries)
- Simple filters (1 conditie): Target <200ms
- Complex filters (5 condities): Target <500ms
- Verschillende operators: equals, contains, greater_than, etc.

**Kritieke vraag:** Blijven filters werkbaar bij 10M records?

### 3. Search Index Service
- Full-text search met FULLTEXT index (MySQL)
- Field-specific search (`field_name:value`)
- Search caching (5-minute cache)
- Index rebuild performance

**Verwachte resultaten:**
- Search: <100ms met FULLTEXT index
- Index update: <50ms per file

### 4. Concurrency (meerdere gebruikers)
- 10 concurrent users, read operaties
- 10 concurrent users, write operaties
- Mixed read/write scenarios
- Deadlock detectie

**Verwachte throughput:**
- Reads: >1000 ops/sec
- Writes: >100 ops/sec
- Deadlocks: <10 bij normale load

### 5. FilCache Integration (KRITIEK!)
- Wat gebeurt er bij `occ files:scan --all`?
- File ID wijzigingen → orphaned metadata
- Metadata preservering bij copy/delete
- Cleanup performance

**Belangrijkste bevinding:**
File IDs kunnen veranderen bij files:scan → metadata raakt "orphaned"

## Interpreteren van resultaten

Resultaten worden opgeslagen in: `apps/metavox/tests/Performance/results/`

### Voorbeeld resultaat (field_service_*.json):

```json
{
  "metrics": [
    {
      "name": "getAllFields",
      "duration_avg_ms": 87.5,
      "duration_median_ms": 85.2,
      "duration_p95_ms": 95.1,
      "status": "PASS"
    }
  ],
  "summary": {
    "total_tests": 45,
    "successful_tests": 42,
    "failed_tests": 3
  }
}
```

### Status indicators:
- **PASS**: Binnen target threshold (groen)
- **WARNING**: Boven target, onder critical (geel)
- **FAIL**: Boven critical threshold (rood)

## Veelvoorkomende problemen

### Test hangt tijdens data generatie
**Oorzaak:** PHP memory limit te laag
**Oplossing:**
```bash
# Tijdelijk verhogen:
sudo -u www-data php -d memory_limit=1G occ metavox:performance-test --generate-data
```

### "No user logged in" error
**Oorzaak:** Geen user opgegeven
**Oplossing:**
```bash
# Specificeer altijd een user:
... --user=admin
```

### Deadlocks tijdens concurrency test
**Oorzaak:** Normaal bij high concurrency, maar >10 is probleem
**Oplossing:** Check InnoDB lock timeout settings:
```sql
SHOW VARIABLES LIKE 'innodb_lock_wait_timeout';
SET GLOBAL innodb_lock_wait_timeout = 120;
```

### Orphaned metadata gevonden
**Oorzaak:** File IDs zijn veranderd (waarschijnlijk door files:scan)
**Oplossing:**
```bash
# Run cleanup:
sudo -u www-data php occ metavox:performance-test --cleanup --user=admin

# Voor productie: zie PERFORMANCE_TEST_SUMMARY.md voor mitigatie strategie
```

## Volgende stappen

Na het runnen van de tests:

1. **Analyseer bottlenecks**
   - Welke operaties zijn te langzaam?
   - Welke queries hebben indexes nodig?

2. **Optimaliseer**
   - Voeg database indexes toe
   - Implementeer extra caching
   - Optimaliseer queries

3. **Test opnieuw**
   - Meet de impact van optimalisaties
   - Vergelijk resultaten

4. **Documenteer**
   - Update PERFORMANCE_TEST_SUMMARY.md
   - Noteer aanbevelingen voor productie

## Minimale hardware voor 10M records

**Database server:**
- CPU: 4+ cores
- RAM: 8GB+ (16GB aanbevolen)
- Storage: SSD (100GB+ vrije ruimte)
- MySQL 8.0+ met InnoDB

**Application server:**
- PHP 8.1+
- memory_limit: 512M minimum
- max_execution_time: 3600 (voor data generatie)

**Verwachte tijden:**
- Data generatie (10M): 30-60 minuten
- Alle tests: 10-20 minuten
- Cleanup: 2-5 minuten

## Support

Vragen of problemen?
- Zie [README.md](README.md) voor gedetailleerde documentatie
- Zie [PERFORMANCE_TEST_SUMMARY.md](../../PERFORMANCE_TEST_SUMMARY.md) voor analyse
- Check [GitHub Issues](https://github.com/nextcloud/metavox/issues)
