# MetaVox App Store Release Checklist

Follow this checklist for every release to the Nextcloud App Store.

---

## 0. Certificate Verification (CRITICAL!)

**Before every release**, verify that your signing key matches the App Store certificate!

- [ ] Verify signing key exists in project root:
  ```bash
  ls -la metavox.key
  ```
- [ ] Verify key is NOT tracked in git:
  ```bash
  git ls-files | grep metavox.key  # Should return nothing
  ```

### Certificate Warnings:
- **NEVER request a new certificate unnecessarily** - this automatically revokes the old one!
- Only request a new certificate if the private key is compromised or lost
- Keep your `.key` file safe (backup in secure location, NOT in git!)
- After certificate change: download the new certificate and store with the key

---

## 1. Code Quality & Security

- [ ] Remove all debug `console.log()` statements from JavaScript (`src/`):
  ```bash
  grep -rn "console\.log" src/ --include="*.js" --include="*.vue" | grep -v "// console"
  ```
  Known locations to check:
  - `src/filesplugin/columns/MetadataLoader.js` (debug loading)
  - `src/filesplugin/columns/*.js` (any debug statements)
  - `src/flow/main.js` (Flow registration debug)
  - `src/components/fields/*.vue` (field input debug)
- [ ] Verify no `error_log()`, `var_dump()`, `print_r()` in PHP (`lib/`):
  ```bash
  grep -rn "error_log\|var_dump\|print_r" lib/
  ```
  Note: `$this->logger->debug()` via LoggerInterface is OK
- [ ] Check for hardcoded credentials, API keys, or passwords
- [ ] Ensure `.gitignore` is up-to-date (keys, certificates, .env files)
- [ ] Verify that sensitive files are NOT tracked in git:
  ```bash
  git ls-files | grep -iE '\.(key|crt|pem|env)$'
  ```
- [ ] Run `npm audit` — fix critical issues if possible
  - Upstream @nextcloud dependency vulnerabilities are usually not fixable
- [ ] **Check tarball for sensitive data** (see Section 9.2)

---

## 1a. OWASP Top 10 (2025) release gate

Loop deze lijst af bij **elke** release. De checks zijn bewust *triggers*, geen
pass/fail-gates: een hit betekent "kijk hier met je ogen naar", niet per se een bug.
Noteer bij een genegeerde hit kort *waarom* in de PR/commit.

Referentie: <https://owasp.org/Top10/2025/> · Cheat Sheets: <https://cheatsheetseries.owasp.org/>

### A01 — Broken Access Control

- [ ] Elke nieuwe/gewijzigde controller-methode heeft een bewuste access-attribute.
      Geen attribute = admin-only (NC-default). Controleer dat dat ook de bedoeling was:
  ```bash
  grep -rn --include='*.php' -B4 'public function' lib/Controller/ \
    | grep -E '#\[(NoAdminRequired|PublicPage|AuthorizedAdminSetting)\]|public function'
  ```
- [ ] Bij elke `#[NoAdminRequired]`: wordt de *ownership* van het object nog apart
      gecheckt? Ingelogd zijn is geen autorisatie — een user mag niet via een geraden
      `fileId`/`id` bij andermans data (IDOR).
- [ ] Bij elke `#[PublicPage]`: is er een token/secret-check, en gebeurt die met
      `hash_equals()` (niet `===`)?
- [ ] Share-scope: bij wijzigingen aan share-/permissie-logica, test expliciet als
      **anonieme** gebruiker én als user *zonder* rechten — niet alleen als eigenaar.

### A02 — Security Misconfiguration

- [ ] Geen debug-/verbose-output in de release-build (zie sectie 1).
- [ ] Foutmeldingen naar de client lekken geen paden, stacktraces of SQL.
- [ ] Nieuwe appconfig-defaults zijn *secure by default* (dicht, niet open).
- [ ] Als de app externe content of iframes rendert: CSP-policy nog passend?

### A03 — Software Supply Chain Failures *(nieuw in 2025, #3)*

- [ ] `npm audit` — kritieke issues opgelost of expliciet verantwoord.
      Upstream `@nextcloud/*` issues zijn vaak niet fixbaar; noteer dat dan.
- [ ] Lockfile is gecommit en hoort bij deze release-build.
- [ ] Nieuwe dependency toegevoegd sinds vorige release? Check even:
      onderhouden, redelijk gebruikt, en de licentie past.
  ```bash
  git diff <vorige-tag>..HEAD -- package.json composer.json
  ```

### A04 — Cryptographic Failures

- [ ] Alle nieuwe tokens/secrets via `random_bytes()` — nooit `rand()`, `uniqid()` of `md5()`.
      (`md5()` voor cache-keys/ETags is prima; voor security niet.)
- [ ] Alle secret-vergelijkingen via `hash_equals()`:
  ```bash
  grep -rn --include='*.php' -E '\$(token|secret|key|hash|signature)[A-Za-z]*\s*===' lib/
  ```
- [ ] Secrets staan versleuteld (`ICrypto`) opgeslagen, niet plaintext in appconfig.

### A05 — Injection (incl. XSS)

- [ ] **`v-html` zonder zichtbare sanitizer** — elke hit handmatig nalopen:
  ```bash
  grep -rn --include='*.vue' 'v-html' src/ \
    | grep -viE 'sanitiz|dompurify|escapehtml'
  ```
  Regel: alles wat een *gebruiker* kan beïnvloeden (bestandsinhoud, paginatekst,
  veldwaarden, zoek-snippets) moet door DOMPurify of `escapeHtml()` vóór het in
  `v-html` belandt. Server-side HTML samenstellen en "vertrouwen" telt niet.
- [ ] Geen string-interpolatie in SQL — altijd query-builder met named parameters:
  ```bash
  grep -rn --include='*.php' -E 'executeQuery|executeStatement' lib/ \
    | grep -E '"[^"]*\$|\x27[^\x27]*\$'
  ```
- [ ] Elke `shell_exec`/`proc_open`/`exec` gebruikt `escapeshellarg()` op *elk* argument:
  ```bash
  grep -rn --include='*.php' -E 'shell_exec|proc_open|passthru|\bexec\(|\bsystem\(' lib/
  ```

### A06 — Insecure Design

- [ ] Nieuwe feature met een security-dimensie (sharing, upload, externe API,
      tokens)? Beschrijf in de PR kort wie wát mag en hoe dat afgedwongen wordt.

### A07 — Authentication Failures

- [ ] Endpoints die een wachtwoord/token accepteren hebben
      `#[BruteForceProtection]` en `#[AnonRateLimit]`.
- [ ] Tokens hebben een geldigheidsduur en zijn intrekbaar.

### A08 — Software or Data Integrity Failures

- [ ] Tarball gesigneerd met de juiste key; `.sig` gearchiveerd (zie release-sectie).
- [ ] Build gemaakt vanaf een schone `npm ci` op het release-commit
      (appVersion wordt in de bundle gestempeld).
- [ ] Import-/restore-paden valideren hun input vóór verwerking.

### A09 — Security Logging and Alerting Failures

- [ ] Auth-fouten, permissie-weigeringen en admin-acties worden gelogd via
      `LoggerInterface` (niet `error_log()`).
- [ ] Logs bevatten **geen** wachtwoorden, tokens of volledige persoonsgegevens:
  ```bash
  grep -rn --include='*.php' -iE 'logger->[a-z]+\(.*(password|token|secret|apikey)' lib/
  ```

### A10 — Mishandling of Exceptional Conditions *(nieuw in 2025)*

- [ ] Geen lege `catch {}` die een fout stil opslokt — zeker niet rond een
      permissie- of validatie-check:
  ```bash
  grep -rn --include='*.php' -A2 'catch (' lib/ | grep -B1 '^\s*}' | head -20
  ```
- [ ] Faalt de code *dicht*? Bij een exception in een access-check moet toegang
      geweigerd worden, niet toegestaan.
- [ ] Een mislukte upload/import laat geen half-verwerkte staat achter.

### MetaVox-specifiek

- [ ] **`BackupController`**: de `TRUNCATE`/`ALTER TABLE`-statements interpoleren
      tabelnamen. Die namen MOETEN uit de `self::TABLES`-constante blijven komen —
      nooit uit request-input:
  ```bash
  grep -n 'TRUNCATE TABLE\|ALTER TABLE' lib/Controller/BackupController.php
  ```
- [ ] Restore-/import-endpoints zijn admin-only en valideren de payload vóór
      het legen van tabellen (A08 + A10: geen half-gerestorede staat).
- [ ] `composer audit` draaien.

---

## 2. Translations (l10n/)

Supported languages: **NL, DE, FR, SV** (source: EN) — all four kept at key-parity with EN.

### i18n anti-patterns (must avoid)

- **Never compose translatable nouns via parameter substitution.** Pattern to avoid:
  ```js
  t('metavox', '{itemType} Metadata', { itemType })  // ❌ broken
  ```
  `t()` translates the *template* and substitutes `{itemType}` literally with the runtime value (always English: `"File"` / `"Folder"`). Other languages may compound (`Filmetadata`), use a preposition (`Métadonnées de fichier`), or change word order — none of which works with this pattern. Use separate literal keys per noun:
  ```js
  itemType === 'Folder' ? t('metavox', 'Folder Metadata') : t('metavox', 'File Metadata')  // ✓
  ```
  Same applies to empty-state messages, button labels, and any other UI string with a noun that varies by context. Reported by @maghog on #62 (fixed in v2.0.8).

### Checks

- [ ] Extract all translation strings from source code and compare with l10n files:
  ```bash
  # Extract strings from Vue/JS
  grep -roh "t('metavox', '[^']*'" src/ | sed "s/t('metavox', '//;s/'$//" | sort -u > /tmp/source_strings.txt

  # Compare with nl.json
  python3 -c "
  import json
  with open('l10n/nl.json') as f:
      existing = set(json.load(f)['translations'].keys())
  with open('/tmp/source_strings.txt') as f:
      source = set(line.strip() for line in f if line.strip())
  missing = source - existing
  print(f'Source: {len(source)}, Translated: {len(existing)}, Missing: {len(missing)}')
  for s in sorted(missing): print(f'  - {s}')
  "
  ```
- [ ] Verify all translations have keys identical to the EN source:
  ```bash
  python3 -c "
  import json
  en = set(json.load(open('l10n/en.json'))['translations'].keys())
  for lang in ['nl', 'de', 'fr', 'sv']:
      ks = set(json.load(open(f'l10n/{lang}.json'))['translations'].keys())
      print(f'{lang}: {len(ks)}', 'in sync ✓' if ks == en else f'MISMATCH ({len(ks ^ en)} diff)')
  "
  ```
- [ ] Validate JSON syntax in all translation files
- [ ] Regenerate `.js` translation files from JSON (**all four languages** — fr/sv included):
  ```bash
  python3 -c "
  import json
  for lang in ['nl', 'de', 'fr', 'sv']:
      data = json.load(open(f'l10n/{lang}.json'))
      items = list(data['translations'].items())
      lines = ['OC.L10N.register(', '    \"metavox\",', '    {']
      for i, (k, v) in enumerate(items):
          comma = ',' if i < len(items) - 1 else ''
          lines.append(f'    \"{k}\" : \"{v}\"{comma}')
      lines.extend(['},', f'\"nplurals=2; plural=(n != 1);\");', ''])
      open(f'l10n/{lang}.js', 'w').write('\n'.join(lines))
      print(f'{lang}.js: {len(items)} strings')
  "
  ```
- [ ] Verify JS files are newer than JSON files
- [ ] Note: Flow operator names (equals, contains, etc.) are hardcoded English — not translatable

---

## 3. Version Management

- [ ] Determine new version number (semantic versioning: MAJOR.MINOR.PATCH)
- [ ] For pre-releases, use suffix: `-alpha.1`, `-beta.1`, `-rc.1`
- [ ] Update version — both files must match:
  - `appinfo/info.xml` → `<version>X.Y.Z</version>`
  - `package.json` → `"version": "X.Y.Z"`
- [ ] Verify with: `npm run build` (should complete without errors)
- [ ] Update `CHANGELOG.md`:
  - [ ] Add new section: `## [X.Y.Z] - YYYY-MM-DD`
  - [ ] Sections: Added, Changed, Fixed, Performance, Removed, Security

### Pre-release (Beta) Process:
1. Set version to `X.Y.Z-beta.1` in info.xml and package.json
2. Tag as `vX.Y.Z-beta.1`
3. Upload to App Store — only visible to beta/daily/git channel users
4. After approval: remove suffix, tag as `vX.Y.Z`, re-upload as final

---

## 4. API & Routes

- [ ] **No OpenAPI spec is maintained** — `info.xml` has no `<openapi>` declaration, so there's nothing to verify here. If one is ever added, also ship the `openapi.json` file.
- [ ] Verify the routes in `appinfo/routes.php` are functional (currently **~92** web + OCS entries — `grep -cE "'name'\s*=>" appinfo/routes.php`)
- [ ] Remember: route changes require a version bump (NC caches routes — needs `app:disable` + `app:enable`, see §6)

---

## 5. Build & Testing

- [ ] Regenerate l10n JS files (see Section 2)
- [ ] Run `npm run build` without errors
  - Bundle size warnings for filesplugin/admin/user are normal (large app)
- [ ] Test core functionalities on a dev server (default `deploy.sh` → Hetzner `nc-dev`, NC33; use `next` for NC34):
  - [ ] Metadata columns in file list (verify on NC33 + NC34)
  - [ ] Inline cell editing (all field types: text, number, date, dropdown, multi-select, checkbox, URL, user, filelink)
  - [ ] Cell locking and real-time sync via notify_push
  - [ ] Views: create, edit, delete, switch, default view
  - [ ] Filter bar and client-side sorting
  - [ ] Sidebar metadata tab
  - [ ] Bulk metadata editor (multi-file select)
  - [ ] AI autofill (if AI provider configured)
  - [ ] Backup & restore
  - [ ] Flow integration (metadata-based checks)
  - [ ] Admin settings (field management, permissions, statistics, telemetry)
  - [ ] Personal settings (team folder overview)
  - [ ] Fill handle and undo support
  - [ ] Lock badge with username
- [ ] Check browser console for errors
- [ ] Test with different Nextcloud versions (NC31–NC34)

### Server Dependencies:
- [ ] Verify notify_push is installed and working (for real-time sync)
- [ ] Verify Redis is available (for cell locking, presence, push events)
- [ ] Note: App works without Redis/notify_push, but with reduced functionality

---

## 6. Nextcloud Compatibility

- [ ] Check `appinfo/info.xml`:
  ```xml
  <nextcloud min-version="31" max-version="34"/>
  <php min-version="8.1"/>
  ```
- [ ] Test on all supported Nextcloud versions (31–34):
  - NC31 — basic compatibility
  - NC32 — DOM-based sorting fallback, filter registration with try/catch
  - NC33 — scoped globals for sidebar/bulk action registration
  - NC34 — PSR-11 container pattern (removed `\OC::$server->getRequest()`/`getUserSession()`/`getConfig()` convenience methods); see `internal-docs/nc34-compat-plan.md`
- [ ] Verify version bump triggers route cache refresh (`app:disable` + `app:enable`)

---

## 7. Assets & Tarball Contents

Required files in tarball:

| Directory    | Contents                              |
|--------------|---------------------------------------|
| `appinfo/`   | info.xml, routes.php                  |
| `lib/`       | PHP backend (Controllers, Services, Migrations) |
| `js/`        | Compiled JavaScript (webpack output)  |
| `css/`       | Stylesheets                           |
| `img/`       | App icons                             |
| `l10n/`      | Translations (.json + .js)            |
| `templates/` | PHP templates                         |
| Root files   | CHANGELOG.md, LICENSE, README.md      |

**Exclude from tarball:** `src/`, `node_modules/`, `screenshots/`, `docs/`, `internal-docs/`, `.git/`, `*.key`, `deploy.sh`, `push-to-github.sh`, `scripts/`, `ROADMAP.md`, `CLAUDE.md`, `.tx/`, `*.tar.gz`, `tests/`, `vendor/`, `composer.json`, `composer.lock`, `phpunit.xml`, `.phpunit.cache/`

> `composer.json`/`composer.lock` list **dev-only** dependencies (PHPUnit, the
> `nextcloud/ocp` stubs, `doctrine/dbal`). The app has no runtime composer
> dependencies, so neither the lockfile nor `vendor/` belongs in the tarball —
> shipping them would make the App Store scan dev packages the app never loads.

---

## 8. Git & Repository

- [ ] All changes committed
- [ ] No uncommitted changes: `git status`
- [ ] Sensitive files not tracked: `git ls-files | grep -iE '\.(key|crt|pem|env)$'`
- [ ] Push to both remotes — **GitHub MUST go through `push-to-github.sh`** (strips `internal-docs/`, `deploy.sh` and everything in `.gitignore-github`; never `git push github` directly):
  ```bash
  git push origin main --tags    # Gitea (primary, full repo)
  ./push-to-github.sh main       # GitHub (public mirror, internal files stripped)
  ```
  Note: `push-to-github.sh` pushes the **branch only**, not tags — the GitHub tag/asset is created by `gh release create` in §9.6.

---

## 9. Release Package

### 9.1 Create Tarball

**Root folder must be `metavox` (lowercase, no version number)**

```bash
npm run build && \
TEMP_DIR=$(mktemp -d) && \
mkdir -p "$TEMP_DIR/metavox" && \
cp -r appinfo lib l10n templates css img js "$TEMP_DIR/metavox/" && \
cp CHANGELOG.md LICENSE README.md "$TEMP_DIR/metavox/" 2>/dev/null || true && \
cd "$TEMP_DIR" && \
tar -czf metavox-X.Y.Z.tar.gz metavox && \
mv metavox-X.Y.Z.tar.gz /Users/rikdekker/Documents/Development/voxcloud-apps/metavox/ && \
rm -rf "$TEMP_DIR"
```

### 9.2 Tarball Security Check (CRITICAL!)

```bash
# Verify no sensitive files
tar -tzf metavox-X.Y.Z.tar.gz | grep -iE '(credential|\.key|\.env|deploy|\.git/|node_modules|src/|\.pem|\.crt|internal-docs|CLAUDE\.md)'

# Verify root folder is "metavox/"
tar -tzf metavox-X.Y.Z.tar.gz | head -1

# Verify required directories exist
for dir in appinfo lib l10n templates js img css; do
  echo -n "$dir: "; tar -tzf metavox-X.Y.Z.tar.gz | grep "^metavox/$dir/" | wc -l
done

# Verify src/ is NOT included (should be 0)
tar -tzf metavox-X.Y.Z.tar.gz | grep 'src/' | wc -l
```

### 9.3 Push & Tag

```bash
git push origin main --tags    # Gitea (primary, full repo incl. internal-docs/ + deploy.sh)
./push-to-github.sh main       # GitHub (public mirror — strips internal files; branch only, no tags)
```

⚠️ **Never run `git push github` directly** — it would leak `internal-docs/` and `deploy.sh`. The GitHub tag is created via the release in §9.6.

### 9.4 Deploy to Test Server

`deploy.sh` handles tarball + extract + chown + `occ upgrade` + app:disable/enable per target.
Run `./deploy.sh` (or `--help`) — default target is **Hetzner `nc-dev`**, not SURF.

```bash
./deploy.sh            # default → Hetzner nc-dev container (NC33, dev.rikdekker.nl)
./deploy.sh next       # Hetzner nc-next container (NC34 prerelease, next.voxcloud.nl)
./deploy.sh 3dev       # SURF bare-metal 3dev (145.38.188.218)
./deploy.sh 1dev       # SURF bare-metal 1dev (145.38.193.235)
```

Always smoke-test the **NC34** target (`next`) for any release that touches NC-version-gated code paths.

### 9.5 Generate Signature (for App Store)

```bash
# Generate signature using the LOCAL key in project root:
openssl dgst -sha512 -sign metavox.key metavox-X.Y.Z.tar.gz | openssl base64 -A
```

**Note:** The signing key is `metavox.key` in the project root (NOT in git!).

### 9.6 GitHub Release

```bash
gh release create vX.Y.Z metavox-X.Y.Z.tar.gz \
  --repo nextcloud/metavox \
  --title "vX.Y.Z - [Label]" \
  --notes "$(cat <<'EOF'
## What's New in vX.Y.Z

[Summary from CHANGELOG.md]

Full changelog: https://github.com/nextcloud/metavox/blob/main/CHANGELOG.md
EOF
)"
```

**Download URL:**
```
https://github.com/nextcloud/metavox/releases/download/vX.Y.Z/metavox-X.Y.Z.tar.gz
```

> De release-note mag issues noemen die in deze versie zitten — dat is precies
> waar hij voor is. Maar reageer nog **niet** op de issues zelf: dat gebeurt pas
> na de App Store-upload (§9.9). Een GitHub-release is nog geen app die iemand
> kan installeren.

### 9.7 App Store Upload

Upload via the API (token stored in `Development/.claude/NextcloudApps/Keys/appstore-api-token.txt`):

```bash
curl -X POST https://apps.nextcloud.com/api/v1/apps/releases \
  -H "Authorization: Token $(cat /Users/rikdekker/Documents/Development/.claude/NextcloudApps/Keys/appstore-api-token.txt)" \
  -H "Content-Type: application/json" \
  -d "{
    \"download\": \"https://github.com/nextcloud/metavox/releases/download/vX.Y.Z/metavox-X.Y.Z.tar.gz\",
    \"signature\": \"$(openssl dgst -sha512 -sign metavox.key metavox-X.Y.Z.tar.gz | openssl base64 -A)\"
  }"
```

HTTP 200 = success. Alternatively, upload manually at https://apps.nextcloud.com/developer/apps/releases/new.

- **Note:** Regenerate signature after any tarball change!

### 9.8 Is de release echt af?

Een release kan halverwege blijven steken zonder dat iets faalt: gebouwd,
getest en gedeployed, maar nooit getagd of gepubliceerd. Deze drie regels
sluiten dat af:

```bash
V=X.Y.Z
git rev-list -n1 "v$V" >/dev/null 2>&1 && echo "tag ok"     || echo "GEEN TAG"
gh release view "v$V" --repo nextcloud/metavox --json assets \
  -q '.assets[0].state' 2>/dev/null                          || echo "GEEN GITHUB RELEASE"
curl -s -H "Accept: application/json" \
  "https://apps.nextcloud.com/api/v1/platform/34.0.0/apps.json?t=$(date +%s)" \
  | python3 -c "import json,sys;a=[x for x in json.load(sys.stdin) if x['id']=='metavox'][0];print('app store:',a['releases'][0]['version'])"
```

> De cache-buster (`?t=`) is nodig: zonder die parameter geeft de App Store-API
> minutenlang de vorige versie terug, wat leest als een mislukte upload.

### 9.9 Pas hierna: reageren op GitHub-issues

- [ ] **Reageer pas op een issue nadat de App Store-upload bevestigd is** (§9.8
      hierboven toont de nieuwe versie). Niet eerder — ook niet als de fix al
      gemerged, getagd of naar dev gedeployed is.

      Reden: "fixed in X.Y.Z" is voor de melder een uitnodiging om te updaten.
      Zolang de App Store de versie nog niet serveert, kan die dat niet, en een
      release kan alsnog stranden (afgekeurde signature, mislukte upload). Dan
      staat er een belofte die niet waargemaakt is.

- [ ] Vóór de upload mag je wél reageren met wat *gemeten* is — oorzaak,
      reproductie, gestelde vragen — zolang je geen versie belooft.
- [ ] Vermeld bij het sluiten het issuenummer zoals het in de CHANGELOG staat,
      zodat melder en changelog naar hetzelfde verwijzen.

---

## 10. Post-Release Verification

- [ ] Install from App Store on clean test server
- [ ] Verify version displayed correctly in admin settings
- [ ] Test upgrade path from previous version
- [ ] Verify database migrations run without errors
- [ ] Test metadata columns appear in file list
- [ ] Test real-time sync between two browser sessions
- [ ] Verify translations are loaded (switch language to NL or DE)

---

## 11. Rollback Plan

- [ ] Previous release tarball available
- [ ] Rollback tag exists: `v1.8.3-pre-merge` (pre v2.0.0 state)
- [ ] Dev servers available for emergencies (Hetzner `nc-dev`/`nc-next`, SURF `1dev`/`3dev`)
- [ ] Server backup stored at `/tmp/metavox.backup.*` after deploy (created by `deploy.sh`)
- [ ] Rollback commands:
  ```bash
  # Git rollback
  git checkout v<previous-tag>

  # Re-deploy the previous tag's tarball with deploy.sh, OR restore the on-server backup.
  # Example — restore backup on Hetzner nc-dev (docker):
  ssh -i ~/.ssh/hetzner_ed25519 <hetzner-host> \
    "docker exec nc-dev bash -c 'rm -rf /var/www/html/custom_apps/metavox && mv /tmp/metavox.backup.YYYYMMDD_HHMMSS /var/www/html/custom_apps/metavox'"

  # Re-enable after rollback (routes cache) — adjust occ path per container
  ssh -i ~/.ssh/hetzner_ed25519 <hetzner-host> \
    "docker exec -u www-data nc-dev php occ app:disable metavox && docker exec -u www-data nc-dev php occ app:enable metavox"
  ```

---

## Quick Release Flow

```bash
# 1. Prep
# Regenerate l10n JS files (see Section 2)
npm run build

# 2. Commit & tag
git add -A
git commit -m "Release vX.Y.Z - [Label]"
git tag -a vX.Y.Z -m "Release vX.Y.Z - [Label]"

# 3. Push
git push origin main --tags    # Gitea
./push-to-github.sh main       # GitHub (strips internal files; branch only)

# 4. Tarball (see section 9.1)

# 5. Deploy & test
./deploy.sh           # default: Hetzner nc-dev (NC33); ./deploy.sh next for NC34

# 6. Sign & upload (see sections 9.5-9.7)
```

---

## Notes

- **App ID:** `metavox`
- **Nextcloud versions:** 31, 32, 33, 34
- **PHP version:** >= 8.1
- **Supported languages:** NL, DE, FR, SV (source: EN)
- **Server dependencies:** Redis (optional, for locking/presence/push), notify_push (optional, for real-time sync)
- **App Store:** https://apps.nextcloud.com
- **Gitea:** https://gitea.rikdekker.nl/rik/MetaVox (primary, full repo)
- **GitHub:** https://github.com/nextcloud/metavox (public mirror — push via `./push-to-github.sh`, never directly)
- **Signing key:** `metavox.key` in project root (NOT in git!)
- **Deploy:** `./deploy.sh` → Hetzner `nc-dev` (default); `next` → `nc-next` NC34; `1dev`/`3dev` → SURF
- **Route changes require version bump** — NC caches routes, needs `app:disable` + `app:enable`

---

*Last updated: June 2026 (NC34 support; FR/SV translations; ~92 routes; Hetzner nc-dev/nc-next deploy targets; GitHub via push-to-github.sh)*
