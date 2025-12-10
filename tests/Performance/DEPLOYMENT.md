# MetaVox Performance Tests - Deployment naar Test Server

## Server Informatie
**Host:** 145.38.184.26
**User:** sditmeijer
**Nextcloud locatie:** (te bepalen op server)

## Deployment Stappen

### 1. Upload nieuwe files naar server

```bash
# Vanaf je lokale machine (in de metavox directory):
cd /Users/samditmeijer/Downloads/metavox

# Upload alle performance test files
scp -r tests/Performance/ sditmeijer@145.38.184.26:/tmp/metavox-perf-tests/

# Upload het nieuwe OCC command
scp lib/Command/PerformanceTestCommand.php sditmeijer@145.38.184.26:/tmp/metavox-perf-command.php

# Upload de updated info.xml
scp appinfo/info.xml sditmeijer@145.38.184.26:/tmp/metavox-info.xml
```

### 2. SSH naar server en installeer files

```bash
# Verbind met server
ssh sditmeijer@145.38.184.26

# Bepaal waar Nextcloud geïnstalleerd is
# Meestal: /var/www/nextcloud of /var/www/html
ls /var/www/

# Stel dat Nextcloud in /var/www/nextcloud staat:
export NEXTCLOUD_DIR=/var/www/nextcloud
export METAVOX_DIR=$NEXTCLOUD_DIR/apps/metavox

# Check dat MetaVox bestaat
ls -la $METAVOX_DIR

# Maak backup van bestaande files
sudo cp $METAVOX_DIR/appinfo/info.xml $METAVOX_DIR/appinfo/info.xml.backup

# Kopieer nieuwe files
sudo mkdir -p $METAVOX_DIR/tests/Performance
sudo cp -r /tmp/metavox-perf-tests/* $METAVOX_DIR/tests/Performance/
sudo cp /tmp/metavox-perf-command.php $METAVOX_DIR/lib/Command/PerformanceTestCommand.php
sudo cp /tmp/metavox-info.xml $METAVOX_DIR/appinfo/info.xml

# Fix permissions
sudo chown -R www-data:www-data $METAVOX_DIR/tests/
sudo chown www-data:www-data $METAVOX_DIR/lib/Command/PerformanceTestCommand.php
sudo chown www-data:www-data $METAVOX_DIR/appinfo/info.xml

# Maak results directory
sudo mkdir -p $METAVOX_DIR/tests/Performance/results
sudo mkdir -p $METAVOX_DIR/tests/Performance/reports
sudo mkdir -p $METAVOX_DIR/tests/Performance/logs
sudo chown -R www-data:www-data $METAVOX_DIR/tests/Performance/
```

### 3. Verifieer installatie

```bash
# Check dat het OCC command beschikbaar is
cd $NEXTCLOUD_DIR
sudo -u www-data php occ list | grep metavox

# Je zou moeten zien:
# metavox:performance-test    Run MetaVox performance tests with millions of metadata records

# Test het command
sudo -u www-data php occ metavox:performance-test --help
```

### 4. Run een kleine test eerst

**BELANGRIJK:** Begin met een kleine dataset om te verifiëren dat alles werkt!

```bash
# Genereer 1000 records (snel, voor testing)
sudo -u www-data php occ metavox:performance-test \
  --generate-data \
  --records=1000 \
  --user=admin

# Als dit werkt, run een snelle test suite
sudo -u www-data php occ metavox:performance-test \
  --suite=field \
  --user=admin

# Check resultaten
ls -lh $METAVOX_DIR/tests/Performance/results/

# Bekijk resultaat
sudo cat $METAVOX_DIR/tests/Performance/results/field_service_*.json | head -50
```

### 5. Run de volledige 10M test

**LET OP:** Dit duurt lang (30-60 minuten voor data generatie)!

```bash
# Optioneel: Run in screen session zodat het blijft draaien als je disconnect
screen -S metavox-perf

# Start data generatie
sudo -u www-data php occ metavox:performance-test \
  --generate-data \
  --records=10000000 \
  --user=admin

# Dit kan 30-60 minuten duren. Je ziet progress updates.
# Om te disconnecten maar de test te laten draaien: Ctrl+A, D
# Om terug te komen: screen -r metavox-perf

# Na voltooiing: run alle test suites
sudo -u www-data php occ metavox:performance-test \
  --suite=all \
  --user=admin

# Dit duurt ongeveer 10-20 minuten
```

### 6. Bekijk resultaten

```bash
# Alle resultaat files
ls -lh $METAVOX_DIR/tests/Performance/results/

# Bekijk field service resultaten
sudo cat $METAVOX_DIR/tests/Performance/results/field_service_*.json | jq '.'

# Bekijk filter resultaten
sudo cat $METAVOX_DIR/tests/Performance/results/filter_service_*.json | jq '.'

# Bekijk search resultaten
sudo cat $METAVOX_DIR/tests/Performance/results/search_service_*.json | jq '.'

# Bekijk concurrency resultaten
sudo cat $METAVOX_DIR/tests/Performance/results/concurrency_*.json | jq '.'

# Bekijk filecache integration resultaten
sudo cat $METAVOX_DIR/tests/Performance/results/filecache_integration_*.json | jq '.'
```

### 7. Download resultaten naar lokale machine

```bash
# Op je lokale machine:
cd /Users/samditmeijer/Downloads/metavox

# Download alle resultaten
scp -r sditmeijer@145.38.184.26:$METAVOX_DIR/tests/Performance/results/ ./tests/Performance/results-server/

# Nu kun je de resultaten lokaal analyseren
```

### 8. Cleanup na testen

**BELANGRIJK:** Verwijder test data na het testen!

```bash
# Op de server:
sudo -u www-data php occ metavox:performance-test \
  --cleanup \
  --user=admin

# Verificeer dat test files verwijderd zijn
sudo -u www-data php occ files:scan --all
```

## Troubleshooting

### Command niet gevonden
**Probleem:** `metavox:performance-test` verschijnt niet in `occ list`

**Oplossingen:**
```bash
# 1. Check dat info.xml correct is
sudo cat $METAVOX_DIR/appinfo/info.xml | grep -A 3 "<commands>"

# 2. Herstart Nextcloud
sudo -u www-data php occ app:disable metavox
sudo -u www-data php occ app:enable metavox

# 3. Clear cache
sudo -u www-data php occ maintenance:repair
```

### Memory limit errors
**Probleem:** "Allowed memory size exhausted"

**Oplossing:**
```bash
# Tijdelijk verhogen:
sudo -u www-data php -d memory_limit=2G occ metavox:performance-test ...

# Permanent in php.ini:
sudo nano /etc/php/8.1/cli/php.ini
# Zoek: memory_limit = 128M
# Verander naar: memory_limit = 2G
```

### Permission errors
**Probleem:** "Permission denied" bij het schrijven van results

**Oplossing:**
```bash
# Fix directory permissions:
sudo mkdir -p $METAVOX_DIR/tests/Performance/results
sudo chown -R www-data:www-data $METAVOX_DIR/tests/Performance/
sudo chmod -R 755 $METAVOX_DIR/tests/Performance/
```

### Test duurt te lang
**Probleem:** Data generatie duurt uren

**Oplossingen:**
```bash
# 1. Start met minder records:
--records=100000  # 100K in plaats van 10M

# 2. Verhoog batch size in config.php:
sudo nano $METAVOX_DIR/tests/Performance/config.php
# Wijzig: 'batch_size' => 5000

# 3. Check database performance:
sudo mysql -e "SHOW VARIABLES LIKE 'innodb_buffer_pool_size';"
# Zou minimaal 1G moeten zijn voor grote datasets
```

### Database connection errors
**Probleem:** "MySQL has gone away"

**Oplossing:**
```bash
# Verhoog MySQL timeouts:
sudo mysql -e "SET GLOBAL wait_timeout = 3600;"
sudo mysql -e "SET GLOBAL interactive_timeout = 3600;"
sudo mysql -e "SET GLOBAL max_allowed_packet = 67108864;"  # 64MB
```

## Monitoring tijdens tests

### Database load
```bash
# In een aparte terminal:
watch -n 2 'mysqladmin processlist -u root -p | grep metavox'
```

### System resources
```bash
# CPU en memory:
htop

# Disk I/O:
iostat -x 2

# MySQL status:
watch -n 5 'mysqladmin status -u root -p'
```

### Test progress
```bash
# Volg de logs:
tail -f $NEXTCLOUD_DIR/data/nextcloud.log

# Of als je screen gebruikt:
screen -r metavox-perf
```

## Verwachte Performance (op 145.38.184.26)

Deze waardes zijn afhankelijk van de server specs. Pas aan na eerste test run.

| Metric | Target | Waarschuwing | Kritiek |
|--------|--------|--------------|---------|
| getAllFields() | <100ms | 100-500ms | >500ms |
| saveFieldValue() | <10ms | 10-50ms | >50ms |
| Simple filter | <200ms | 200-1000ms | >1s |
| Complex filter (5) | <500ms | 500-2000ms | >2s |
| Search (FULLTEXT) | <100ms | 100-500ms | >500ms |
| Write throughput | >100/sec | 50-100/sec | <50/sec |
| Read throughput | >1000/sec | 500-1000/sec | <500/sec |

## Belangrijke Notes

1. **ALLEEN op test server uitvoeren!** Deze tests genereren MILJOENEN records.

2. **Backup eerst:** Maak een database backup voor je begint:
   ```bash
   sudo -u www-data php occ maintenance:mode --on
   mysqldump -u root -p nextcloud > nextcloud_backup_$(date +%Y%m%d).sql
   sudo -u www-data php occ maintenance:mode --off
   ```

3. **Disk space:** Zorg voor minimaal 20GB vrije ruimte voor 10M records.

4. **Tijd:** Plan 2-3 uur in totaal (data gen + alle tests + cleanup).

5. **Monitoring:** Houd CPU, memory en disk I/O in de gaten tijdens de tests.
