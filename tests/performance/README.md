# Performance check

Runs the grid's hot requests against a real Nextcloud with Nextcloud's own
profiler app, on a team folder with 100 and with 1,000 files, and judges the
database queries per request. The idea comes from Nextcloud server's own
`performance.yml`.

What fails the check (`compare.mjs`):

- a request needs more queries than on the code before the change. Nextcloud
  runs with a local cache (APCu), as a normal install does, and reloads its
  app config from it every few seconds: which request pays for that is chance,
  so those two queries are shown but not counted;
- MetaVox's own queries grow with the number of files (100 vs 1,000), which is
  how an N+1 shows; known cases are listed in `KNOWN_GROWTH`, held to how fast
  they grow today.

Memory and times are shown, not judged, as in Nextcloud's own check. PHP
reports memory in 2 MB steps and keeps some from the requests before it in the
same process, so the same request shows 8 MB in one run and 14 MB in the next.
A CI runner is too noisy for times. Real timings at scale
are measured on the test server before a release (see RELEASE_CHECKLIST.md).

## Running it

Against a Nextcloud with groupfolders, the profiler app and MetaVox:

```bash
export NC_BASE=http://127.0.0.1:8080 NC_PASS=… NC_OCC='php /path/to/nextcloud/occ' PERF_OUT=/tmp/metavox-perf
$NC_OCC profiler:disable
node tests/performance/run.mjs seed          # once: team folder, users, fields, values
$NC_OCC profiler:enable
node tests/performance/run.mjs requests      # warm up
$NC_OCC profiler:clear
node tests/performance/run.mjs requests
$NC_OCC profiler:export > $PERF_OUT/after.json
node tests/performance/compare.mjs $PERF_OUT/labels.json $PERF_OUT/after.json [before-labels.json before.json]
```

The CI job (`.forgejo/workflows/performance.yml`) does this twice on the same
data: with the code before the push and with the new code. On the code before,
`PERF_BEFORE=1` lets a route the push adds answer 404; that request counts as
new, with nothing to compare it with.
