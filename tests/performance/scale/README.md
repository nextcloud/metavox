# Scale test

How MetaVox's search behaves at the size of a large customer, on a database
whose cache is much smaller than the data, as it is in production. The CI's
performance check (`../`) counts queries on a small team folder; this measures
time, rows and disk reads on a big one.

## The world

Built by `seed.php`, straight into the database:

- 5,000 team folders, half of them with advanced permissions (ACL);
- about 1.4 million files in the file cache: three big team folders
  (500,000, 100,000 and 10,000 files), the rest 20 to 300;
- 8 file fields of the usual types (select, multiselect, text, number, date,
  checkbox, textarea) with a value on every file: about 11 million rows. One
  value is on 25% of the files, others are rare, one option is never used;
- 10,000 users in 1,000 groups, each in about 15 team folders;
- three test users: `scale-big` (the three big team folders and 12 small
  ones), `scale-normal` (15 small ones) and `scale-acl` (the 100,000 one,
  where an ACL hides half of it from them).

MariaDB gets a 256 MB buffer pool, so the data does not fit in memory.

## Running it

Needs Docker and about 20 GB of disk. MetaVox comes from this working tree,
mounted read-only, so another version is a `git checkout` away.

```bash
export NC_PASS=…      # admin password of the test instance
export SCALE_PASS=…   # password of the three test users
./setup.sh            # once: containers, Nextcloud, the world, the search index
node measure.mjs      # the measurements, results also in /tmp/metavox-scale-results.json
docker compose down -v   # remove everything, data included
```

`setup.sh` also says how long filling the search index took, as after an
upgrade, and how big the tables are.

`measure.mjs` runs every search twice on the same data: once the way before
the value index (it sets the "index is being filled" flag, so the filter reads
the metadata itself) and once with the index. Each one cold, right after the
database restarted, and warm, the median of 5. Per search it shows the time,
the rows the database read and its reads from disk: what other users of the
same database notice.

## Two versions side by side

`versions.mjs` compares another version with this one, for the changelog: each
action (opening a folder of 1,000 files, the sidebar, saving, the search
filter, free text) as that version's own frontend sends it, on the same world.
Build the other one next to it from a release tag:

```bash
git archive v2.2.3 appinfo lib js css img l10n templates | (mkdir -p /tmp/metavox-223 && tar -x -C /tmp/metavox-223)
COMPOSE_PROJECT_NAME=metavox-scale-223 METAVOX_SRC=/tmp/metavox-223 SCALE_PORT=8023 ./setup.sh
node versions.mjs     # OLD=metavox-scale-223@8023 NEW=metavox-scale@8035 by default
```

`load.mjs` then lets 10, 25, 50 and 100 people search at the same time, each
one search after the other without a pause, on both: how long a search takes
then, and how many searches per second the server answers.

2.2.3's free-text index gave each file to one user, taken from Nextcloud's
mounts, which only exist once a user has logged in: for a fair comparison,
send one request as `scale-big` to the 2.2.3 instance and run `fill.php` there
again. 2.2.3 also keeps search results in a cache per user, so its warm free
text says little.
