#!/bin/bash
# Builds the scale test from nothing: containers, Nextcloud, groupfolders,
# MetaVox, the world (seed.php) and the search index, and says how long the
# index took to fill. Passwords come from the environment:
#   NC_PASS     the admin password
#   SCALE_PASS  the test users' password
# Another version side by side: COMPOSE_PROJECT_NAME, METAVOX_SRC and
# SCALE_PORT (see compose.yml).
set -euo pipefail
cd "$(dirname "$0")"
: "${NC_PASS:?Set NC_PASS}" "${SCALE_PASS:?Set SCALE_PASS}"

occ() { docker compose exec -T -u www-data app php occ "$@"; }

docker compose up -d
until docker compose exec -T app test -f /var/www/html/lib/versioncheck.php; do sleep 5; done
docker compose exec -T app chown www-data:www-data /var/www/html/custom_apps
if ! occ status 2>/dev/null | grep -q 'installed: true'; then
	docker compose exec -T -u www-data -e NC_PASS app sh -c 'php occ maintenance:install --database=mysql --database-host=db \
		--database-name=nextcloud --database-user=nextcloud --database-pass=scale-db --admin-user=admin --admin-pass="$NC_PASS"'
fi
occ app:install groupfolders > /dev/null 2>&1 || occ app:enable groupfolders
occ app:enable metavox
# Background jobs only when the test runs them.
occ background:cron

docker compose exec -T -u www-data -e SCALE_PASS app php /scale/seed.php

# The search index, as after an upgrade: queued, then filled by its job. An
# older MetaVox fills its own index in the same call.
start=$(date +%s)
docker compose exec -T -u www-data app php /scale/fill.php
runs=0
while id=$(occ background-job:list --class='OCA\MetaVox\BackgroundJobs\BuildSearchWords' 2>/dev/null | grep -oE '^\| [0-9]+' | tr -d '| ' | head -1) && [ -n "$id" ]; do
	occ background-job:execute "$id" --force-execute > /dev/null
	runs=$((runs + 1))
done
echo "search index filled in $(( $(date +%s) - start )) s, $runs runs of the job"
docker compose exec -T db mariadb -uroot -pscale-root nextcloud -e "ANALYZE TABLE oc_filecache, oc_metavox_file_gf_meta, oc_metavox_search_words;
	SELECT table_name, table_rows, ROUND((data_length + index_length) / 1024 / 1024) AS mb FROM information_schema.tables
	WHERE table_schema = 'nextcloud' AND table_name IN ('oc_filecache', 'oc_metavox_file_gf_meta', 'oc_metavox_search_words', 'oc_metavox_search_index');" 2>/dev/null
