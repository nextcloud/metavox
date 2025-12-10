# Filter Service Performance Optimalisatie

## Probleem Analyse

Bij 180K metadata records duren filter queries **2-5 seconden** (target: <200ms).

### Root Cause

De `FilterService->filterFilesByMetadata()` gebruikt **meerdere INNER JOINs** per filter:

```sql
SELECT DISTINCT fm.file_id
FROM metavox_file_gf_meta fm
INNER JOIN metavox_file_gf_meta fm_abc123
  ON fm.file_id = fm_abc123.file_id
  AND fm_abc123.groupfolder_id = 999999
  AND fm_abc123.field_name = 'gf_perf_title'
INNER JOIN metavox_file_gf_meta fm_def456
  ON fm.file_id = fm_def456.file_id
  AND fm_def456.groupfolder_id = 999999
  AND fm_def456.field_name = 'gf_perf_status'
WHERE fm.groupfolder_id = 999999
  AND fm_abc123.field_value LIKE '%Test%'
  AND fm_def456.field_value = 'draft'
```

**Zonder indexes** doet elke JOIN een **full table scan** op 180K records!

---

## Oplossing 1: Database Indexes (KRITIEK!)

### Composite Index voor Filter Queries

```sql
-- Index voor snelle filter lookups
CREATE INDEX idx_gf_file_meta_filter
ON oc_metavox_file_gf_meta (groupfolder_id, field_name, field_value(100));

-- Index voor file_id joins
CREATE INDEX idx_gf_file_meta_file_id
ON oc_metavox_file_gf_meta (file_id, groupfolder_id);
```

### Waarom Deze Indexes?

1. **idx_gf_file_meta_filter**:
   - Covers: `WHERE groupfolder_id = X AND field_name = Y AND field_value LIKE 'Z%'`
   - Maakt INNER JOIN lookups O(log n) ipv O(n)
   - Field_value(100): Prefix index voor VARCHAR performance

2. **idx_gf_file_meta_file_id**:
   - Covers: JOIN conditions op file_id + groupfolder_id
   - Versnelt file lookups na filter

### Verwachte Impact

- **Zonder indexes**: 2-5 seconden per filter (op 180K records)
- **Met indexes**: <50ms per filter ✅ (40-100x sneller!)

---

## Oplossing 2: Query Herstructurering (OPTIONEEL)

Als indexes niet genoeg zijn bij 10M+ records, herstructureer de query:

### Huidige Approach: Multiple INNER JOINs
```php
// Elk filter = 1 extra JOIN
// 5 filters = 5 JOINs = TRAAG op grote datasets
```

### Alternatief: Subquery per Filter + INTERSECT
```sql
SELECT file_id FROM (
  -- Filter 1
  SELECT file_id FROM metavox_file_gf_meta
  WHERE groupfolder_id = 999999
    AND field_name = 'title'
    AND field_value LIKE '%Test%'

  INTERSECT

  -- Filter 2
  SELECT file_id FROM metavox_file_gf_meta
  WHERE groupfolder_id = 999999
    AND field_name = 'status'
    AND field_value = 'draft'
) AS filtered_files
```

**Voordelen**:
- Elke subquery gebruikt index optimaal
- MySQL kan subqueries parallel optimaliseren
- Beter voor 10M+ records

**Nadeel**: Complexere code

---

## Oplossing 3: Materialized Filter Cache (ADVANCED)

Voor **veelgebruikte filters** op 10M+ records:

```sql
-- Cache table voor populaire filter combinaties
CREATE TABLE oc_metavox_filter_cache (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  groupfolder_id INT NOT NULL,
  filter_hash VARCHAR(64) NOT NULL,
  file_ids TEXT NOT NULL,  -- JSON array van file IDs
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP,
  INDEX idx_filter_lookup (groupfolder_id, filter_hash)
);
```

**Strategy**:
1. Hash filter criteria → lookup in cache
2. Cache hit: Return cached file_ids (< 1ms!)
3. Cache miss: Run query + cache result (5 min TTL)
4. Invalidate cache on metadata updates

---

## Oplossing 4: Field Value Normalisatie (LONG TERM)

Voor **number** en **date** fields:

### Huidige Problem
```
field_value VARCHAR(2000)  -- "100", "2024-01-15" als strings
```

Vergelijkingen zoals `field_value > '50'` werken **niet correct**:
- "9" > "100" (string sorting!) ❌

### Oplossing: Typed Columns
```sql
ALTER TABLE oc_metavox_file_gf_meta
ADD COLUMN field_value_number DECIMAL(20,6) NULL,
ADD COLUMN field_value_date DATE NULL,
ADD INDEX idx_value_number (groupfolder_id, field_name, field_value_number),
ADD INDEX idx_value_date (groupfolder_id, field_name, field_value_date);
```

**Migration Strategy**:
1. Add new columns
2. Populate from existing data
3. Update FilterService to use typed columns for number/date fields
4. Much faster range queries!

---

## Implementatie Prioriteit

### DIRECT IMPLEMENTEREN (voor 1M test):
✅ **Oplossing 1: Database Indexes**
- Grootste impact (40-100x sneller)
- Geen code changes
- Simpel te implementeren

### Later Overwegen (voor 10M+):
⏳ **Oplossing 2: Query Herstructurering**
- Alleen als indexes niet genoeg zijn
- Significant code refactor

⏳ **Oplossing 3: Filter Cache**
- Voor production met veelgebruikte filters
- Meer complexiteit (cache invalidation)

⏳ **Oplossing 4: Typed Columns**
- Breaking change (schema migration)
- Lange termijn verbetering

---

## SQL Script voor Indexes

```sql
-- Run op de database server
USE nextcloud;

-- Index 1: Filter lookups
CREATE INDEX idx_gf_file_meta_filter
ON oc_metavox_file_gf_meta (groupfolder_id, field_name, field_value(100));

-- Index 2: File ID joins
CREATE INDEX idx_gf_file_meta_file_id
ON oc_metavox_file_gf_meta (file_id, groupfolder_id);

-- Index 3: Timestamps (voor cleanup queries)
CREATE INDEX idx_gf_file_meta_timestamps
ON oc_metavox_file_gf_meta (created_at, updated_at);

-- Verify indexes
SHOW INDEX FROM oc_metavox_file_gf_meta;

-- Test query performance
EXPLAIN SELECT DISTINCT fm.file_id
FROM oc_metavox_file_gf_meta fm
WHERE fm.groupfolder_id = 999999
  AND fm.field_name = 'gf_perf_title'
  AND fm.field_value LIKE '%Test%';
```

---

## Performance Targets

| Dataset | Zonder Index | Met Index | Target |
|---------|-------------|-----------|--------|
| 100K records | 2-5s ❌ | <50ms ✅ | <200ms |
| 1M records | 20-50s ❌ | <200ms ✅ | <500ms |
| 10M records | 200-500s ❌ | <1s ✅ | <2s |

---

## Next Steps

1. ✅ **Upload gefixte GroupfolderConcurrencyTest.php**
2. ✅ **Maak indexes aan op database**
3. ⏳ **Re-run 100K filter tests**
4. ⏳ **Test met 1M records**
5. ⏳ **Evalueer of Oplossing 2 nodig is voor 10M**
