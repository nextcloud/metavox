#!/bin/bash
# Nextcloud for the CI jobs: the release, a database, groupfolders and MetaVox,
# and the profiler app with --profiler. Writes NC_PASS to $GITHUB_ENV.
# --apcu gives Nextcloud a local cache, as a normal install has (PHP needs the
# apcu extension with apc.enable_cli=1).
# Downloads have time limits and retries, so a stalled connection fails the
# step within minutes instead of hanging the job for hours.
# usage: nextcloud.sh <version> <sqlite|mysql|pgsql> [--profiler] [--apcu]
set -euo pipefail

version=$1
db=$2
profiler=0
apcu=0
for flag in "${@:3}"; do
	case "$flag" in
	--profiler) profiler=1 ;;
	--apcu) apcu=1 ;;
	*) echo "Unknown option: $flag" >&2; exit 2 ;;
	esac
done
NC=${NC:-/tmp/nextcloud}
occ() { php "$NC/occ" "$@"; }

# Runs a download up to three times. A failed try can leave a half-written
# folder ($1) that blocks the next one, so it is removed in between.
tries() {
	local dir=$1 try
	shift
	for try in 1 2 3; do
		"$@" && return
		echo "Try $try of 3 failed (exit $?): $*" >&2
		[ "$try" = 3 ] && return 1
		rm -rf "$dir"
		sleep 10
	done
}

pass="$(openssl rand -base64 18)aA1!"
echo "NC_PASS=$pass" >> "${GITHUB_ENV:-/dev/null}"
dbpass="$(openssl rand -hex 16)"

case "$db" in
mysql)
	apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq mariadb-server > /dev/null
	service mariadb start > /dev/null
	mysql -e "CREATE DATABASE nextcloud CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci; CREATE USER 'nextcloud'@'localhost' IDENTIFIED BY '$dbpass'; GRANT ALL ON nextcloud.* TO 'nextcloud'@'localhost';"
	dbargs=(--database mysql --database-host localhost --database-name nextcloud --database-user nextcloud --database-pass "$dbpass")
	;;
pgsql)
	apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq postgresql > /dev/null
	service postgresql start > /dev/null
	su postgres -c "psql -q -c \"CREATE USER nextcloud WITH PASSWORD '$dbpass';\" -c \"CREATE DATABASE nextcloud OWNER nextcloud;\""
	dbargs=(--database pgsql --database-host 127.0.0.1 --database-name nextcloud --database-user nextcloud --database-pass "$dbpass")
	;;
*)
	dbargs=(--database sqlite)
	;;
esac

# To a file first: curl starts a retry from zero there, but not in a pipe.
tarball=$(mktemp)
trap 'rm -f "$tarball"' EXIT
curl -fsSL --connect-timeout 30 --speed-limit 50000 --speed-time 60 --max-time 600 \
	--retry 3 --retry-delay 10 --retry-all-errors \
	-o "$tarball" "https://download.nextcloud.com/server/releases/latest-$version.tar.bz2"
tar -xjf "$tarball" -C "$(dirname "$NC")"
occ maintenance:install "${dbargs[@]}" --admin-user admin --admin-pass "$pass"
occ config:system:set trusted_domains 1 --value=127.0.0.1
if [ "$apcu" = 1 ]; then
	occ config:system:set memcache.local --value='\OC\Memcache\APCu'
fi
# The app store picks the groupfolders version that fits this Nextcloud. occ
# gives that download no time limit, and skips the store for five minutes after
# a failed request, so each try gets a limit and a fresh start.
groupfolders() {
	occ config:app:delete settings appstore-fetcher-lastFailure > /dev/null
	timeout -k 10 180 php "$NC/occ" app:enable groupfolders
}
tries "$NC/apps/groupfolders" groupfolders
if [ "$profiler" = 1 ]; then
	tries "$NC/apps/profiler" timeout -k 10 120 \
		git clone -q --depth 1 -b "stable$version" https://github.com/nextcloud/profiler.git "$NC/apps/profiler"
	occ app:enable --force profiler
fi
mkdir "$NC/apps/metavox"
cp -R appinfo lib js css img l10n templates "$NC/apps/metavox/"
occ app:enable metavox
occ app:list --enabled | grep -E 'groupfolders|metavox|profiler'
