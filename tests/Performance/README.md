# MetaVox Performance Tests

## Doel
Performance tests voor MetaVox met focus op schaalbaarheid tot 10 miljoen metadata velden.

## Test Scenario's

### 1. Data Volume Tests (10M velden)
- Field Service: CRUD operaties met grote datasets
- Filter Service: Query performance met complexe filters
- Search Index: Full-text search performance

### 2. Concurrency Tests
- Meerdere gebruikers tegelijk
- Read/Write conflicts
- Lock contention

### 3. Filecache Integration Tests
- Gedrag bij `occ files:scan --all`
- File ID wijzigingen en orphaned metadata
- Index rebuild scenarios

## Vereisten

### Database
- MySQL 8.0+ (aanbevolen voor FULLTEXT index)
- PostgreSQL 13+ (alternatief)
- Minimaal 4GB RAM voor test database
- Snelle SSD storage

### PHP
- PHP 8.1+
- Extensions: pdo_mysql, pcntl (voor concurrency)
- memory_limit: 512M minimum
- max_execution_time: 0 (voor lange tests)

### Nextcloud
- Test instance (NIET productie!)
- Nextcloud 31 of 32
- Groupfolders app geïnstalleerd

## Test Setup

### Stap 1: Voorbereiding

```bash
# Zorg dat je in de Nextcloud directory bent
cd /path/to/nextcloud

# Check dat MetaVox geïnstalleerd is
sudo -u www-data php occ app:list | grep metavox

# Als MetaVox niet geïnstalleerd is:
sudo -u www-data php occ app:enable metavox
```

### Stap 2: Configuratie (optioneel)

```bash
# Kopieer en pas config aan voor custom settings
cd apps/metavox
cp tests/Performance/config.sample.php tests/Performance/config.php

# Edit config.php voor custom thresholds, batch sizes, etc.
# Voor standaard configuratie is dit niet nodig
```

### Stap 3: Genereer test data

**BELANGRIJK**: Deze stap genereert MILJOENEN metadata records. Gebruik ALLEEN op een test instance!

```bash
# Genereer 10 miljoen metadata records
# Dit kan 30-60 minuten duren afhankelijk van je hardware
sudo -u www-data php occ metavox:performance-test \
  --generate-data \
  --records=10000000 \
  --user=admin

# Voor snellere tests met kleinere dataset:
sudo -u www-data php occ metavox:performance-test \
  --generate-data \
  --records=100000 \
  --user=admin
```

### Stap 4: Run performance tests

```bash
# Run alle test suites
sudo -u www-data php occ metavox:performance-test --suite=all --user=admin

# Of run individuele suites:
sudo -u www-data php occ metavox:performance-test --suite=field --user=admin
sudo -u www-data php occ metavox:performance-test --suite=filter --user=admin
sudo -u www-data php occ metavox:performance-test --suite=search --user=admin
sudo -u www-data php occ metavox:performance-test --suite=concurrent --user=admin
sudo -u www-data php occ metavox:performance-test --suite=filecache --user=admin
```

### Stap 5: Cleanup

```bash
# Verwijder alle test data na het runnen van tests
sudo -u www-data php occ metavox:performance-test --cleanup --user=admin
```

## Test Suites

### Suite 1: Field Service Performance
```bash
php tests/Performance/run_tests.php --suite=field
```
Tests:
- `getAllFields()` met 10M velden
- `getFieldsByScope()` met verschillende scopes
- `saveFieldValue()` throughput (writes/sec)
- `getBulkFileMetadata()` met batches van 100-10000 files

### Suite 2: Filter Service Performance
```bash
php tests/Performance/run_tests.php --suite=filter
```
Tests:
- Simple filters (1 conditie)
- Complex filters (5+ condities, meerdere operators)
- Filter op verschillende field types (text, number, date, select)
- Performance met/zonder indexes

### Suite 3: Search Index Performance
```bash
php tests/Performance/run_tests.php --suite=search
```
Tests:
- Full-text search met FULLTEXT index (MySQL)
- LIKE fallback search (PostgreSQL)
- Field-specific search (`field_name:value`)
- Index rebuild tijd voor 10M records

### Suite 4: Concurrency Tests
```bash
php tests/Performance/run_tests.php --suite=concurrent
```
Tests:
- 10 concurrent users, read operaties
- 10 concurrent users, write operaties
- 10 concurrent users, mixed read/write
- Deadlock detection

### Suite 5: Filecache Integration
```bash
php tests/Performance/run_tests.php --suite=filecache
```
Tests:
- Simuleer `occ files:scan --all`
- File ID wijzigingen detectie
- Orphaned metadata cleanup
- Metadata preservering bij file copy/move

## Performance Metrics

### Key Performance Indicators (KPIs)

| Operatie | Target | Waarschuwing | Kritiek |
|----------|--------|--------------|---------|
| `getAllFields()` | <100ms | 100-500ms | >500ms |
| `saveFieldValue()` | <10ms | 10-50ms | >50ms |
| Simple filter (1 conditie) | <200ms | 200-1000ms | >1s |
| Complex filter (5 condities) | <500ms | 500-2000ms | >2s |
| Search (FULLTEXT) | <100ms | 100-500ms | >500ms |
| Bulk metadata (100 files) | <500ms | 500-2000ms | >2s |

### Throughput Targets
- Write operations: >100 writes/sec
- Read operations: >1000 reads/sec
- Concurrent users: 50+ zonder degradatie

## Resultaten

Test resultaten worden opgeslagen in:
- `tests/Performance/results/` - Raw data (JSON)
- `tests/Performance/reports/` - HTML rapporten
- `tests/Performance/logs/` - Debug logs

### Rapport Format
```
Performance Test Report
Date: 2025-11-15 12:00:00
Database: MySQL 8.0.35
Records: 10,000,000

=== Field Service ===
getAllFields(): 87ms (PASS)
saveFieldValue(): 6ms (PASS)
getBulkFileMetadata(100): 234ms (PASS)

=== Filter Service ===
Simple filter: 156ms (PASS)
Complex filter (5 conditions): 678ms (WARNING)

=== Concurrency ===
10 users, read: 1234 reads/sec (PASS)
10 users, write: 87 writes/sec (WARNING)
Deadlocks: 0 (PASS)
```

## Troubleshooting

### Test hangt bij grote datasets
- Verhoog PHP memory_limit
- Check database connection timeout
- Gebruik kleinere batches: `--batch-size=1000`

### Deadlocks tijdens concurrency tests
- Normaal bij high concurrency
- Check aantal deadlocks in rapport
- >10 deadlocks = probleem met locking strategie

### Filecache tests falen
- Zorg dat Nextcloud test instance draait
- Check dat groupfolders app actief is
- Verificeer database privileges (CREATE, DROP)

## Best Practices

1. **Run tests op dedicated machine** - Geen productie omgeving!
2. **Warm-up runs** - Eerste run kan langzamer zijn (cache warming)
3. **Consistente omgeving** - Zelfde hardware/database versie
4. **Baseline metingen** - Meet eerst met kleine dataset (1000 records)
5. **Incremental testing** - Test met 1K, 10K, 100K, 1M, 10M records

## Volgende Stappen

Na succesvole performance tests:
1. Analyseer bottlenecks in rapporten
2. Optimaliseer queries (zie indexes in Migration files)
3. Implementeer caching strategie
4. Test opnieuw met optimalisaties
5. Document bevindingen in PERFORMANCE_TEST_SUMMARY.md
