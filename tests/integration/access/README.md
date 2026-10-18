# Access matrix

Calls every MetaVox route (web and OCS, read from `appinfo/routes.php`) as
ten different users against a real Nextcloud, and checks the answer against
the rule the route should follow. It also checks the Files app's PROPFIND,
which is where the grid gets its values.

## The test world

`world.mjs` builds it before the run and removes it afterwards, including
leftovers of an aborted run. Everything is named `e2e-acc-*` or `E2E-Rechten`.

| Role | Rights |
|---|---|
| `admin` | Nextcloud admin, member |
| `adminBuiten` | Nextcloud admin, not a member |
| `beheerder` | member, MetaVox manage grant |
| `beheerderLezen` | member, manage grant, ACL: read only from the root |
| `bewerker` | member, ACL: one file read only |
| `lezer` | member, ACL: read only from the root |
| `lezerGroep` | member through a read-only team folder group |
| `verborgen` | member, ACL: one file hidden |
| `buitenstaander` | not a member |
| `globaal` | manage grant, not a member |

There are three files (`open`, `alleenLezen`, `geheim`), a file field and a
team folder field with a unique value per file, and one view. A file-link
field on `open` and one on the team folder link to `geheim`; a second team
folder (`E2E-Rechten-buiten`) holds a file to link to from outside.

## What is checked

- The status of every call: allowed, 403, 404, or 400 (`routes.mjs`).
- The content: for example, a reader sees the values of every file it can
  read, and an export contains only readable files.
- Leaks: values, file names and view names a role cannot see never appear
  in any answer.
- Error answers never contain exception text, paths or SQL.
- File links: every route sends ids only, names only as the caller sees the
  file, and no way of writing (web, OCS, batch, defaults, team folder) keeps
  a link outside the team folder.
- The user picker follows Nextcloud's share settings: with "only your own
  groups" it finds nobody outside them (the setting is put back afterwards).
- A grant is revoked by its id, and only through the route of its holder.
- The Files page's start data says who may manage the team folder by the
  same rule the server checks when a view is saved.
- Push (with notify_push): only whoever has the grid of the team folder
  open gets it. A listing registers you, Nextcloud's status heartbeat keeps
  you, leaving ends it. Pushes carry ids, never values.

Routes that only reach their rule in a later authorization step are
reported as pending, grouped by step. Raise `DONE_STEP` in `routes.mjs`
when a step is done; from then on those rows have to pass.

## Running it

Needs Node 18 or newer, the groupfolders app, and an admin account. Passwords
only come from environment variables. The test users get a random password
for each run.

```bash
NC_BASE=https://cloud.example.com \
NC_PASS=… \
NC_OCC='ssh server sudo -u www-data php /var/www/nextcloud/occ' \
npm run test:access
```

For Nextcloud in Docker use `NC_OCC='docker exec -u www-data <container> php occ'`.

Options:
- `NC_USER` for the admin account (default `admin`).
- `ONLY=<regex>` for a subset of routes.
- `VERBOSE=1` to list every pending row.
- `MATRIX_OUT=<file>` to write all results as JSON.

A run takes about three minutes (about 1100 calls).
