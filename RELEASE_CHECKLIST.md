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

## 2. Translations (l10n/)

Supported languages: **NL, DE** (source: EN)

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
- [ ] Verify NL and DE have identical keys:
  ```bash
  python3 -c "
  import json
  nl = set(json.load(open('l10n/nl.json'))['translations'].keys())
  de = set(json.load(open('l10n/de.json'))['translations'].keys())
  print(f'NL: {len(nl)}, DE: {len(de)}')
  print('In sync ✓' if nl == de else f'Mismatch: {len(nl ^ de)} diff')
  "
  ```
- [ ] Validate JSON syntax in all translation files
- [ ] Regenerate `.js` translation files from JSON:
  ```bash
  python3 -c "
  import json
  for lang in ['nl', 'de']:
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

## 4. API & OpenAPI Documentation

- [ ] If `openapi.json` is declared in `info.xml`, verify the file exists and is up-to-date
- [ ] If not maintaining OpenAPI spec, remove the `<openapi>` line from `info.xml`
- [ ] Verify all 82 routes in `appinfo/routes.php` are functional
- [ ] Check that route changes require version bump (NC caches routes!)

---

## 5. Build & Testing

- [ ] Regenerate l10n JS files (see Section 2)
- [ ] Run `npm run build` without errors
  - Bundle size warnings for filesplugin/admin/user are normal (large app)
- [ ] Test core functionalities on 3dev:
  - [ ] Metadata columns in file list (NC32 and NC33)
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
- [ ] Test with different Nextcloud versions (NC31, NC32, NC33)

### Server Dependencies:
- [ ] Verify notify_push is installed and working (for real-time sync)
- [ ] Verify Redis is available (for cell locking, presence, push events)
- [ ] Note: App works without Redis/notify_push, but with reduced functionality

---

## 6. Nextcloud Compatibility

- [ ] Check `appinfo/info.xml`:
  ```xml
  <nextcloud min-version="31" max-version="33"/>
  ```
- [ ] Add PHP requirement if missing:
  ```xml
  <php min-version="8.1"/>
  ```
- [ ] Test on all supported Nextcloud versions:
  - NC31 — basic compatibility
  - NC32 — DOM-based sorting fallback, filter registration with try/catch
  - NC33 — scoped globals for sidebar/bulk action registration
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

**Exclude from tarball:** `src/`, `node_modules/`, `screenshots/`, `docs/`, `internal-docs/`, `.git/`, `*.key`, `deploy.sh`, `push-to-github.sh`, `scripts/`, `ROADMAP.md`, `CLAUDE.md`, `.tx/`, `*.tar.gz`

---

## 8. Git & Repository

- [ ] All changes committed
- [ ] No uncommitted changes: `git status`
- [ ] Sensitive files not tracked: `git ls-files | grep -iE '\.(key|crt|pem|env)$'`
- [ ] Push to both remotes:
  ```bash
  git push origin main --tags   # Gitea
  git push github main --tags   # GitHub
  ```

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
mv metavox-X.Y.Z.tar.gz /Users/rikdekker/Documents/Development/MetaVox/ && \
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
git push origin main --tags    # Gitea (primary)
git push github main --tags    # GitHub (mirror)
```

### 9.4 Deploy to Test Server

```bash
bash deploy.sh
# Deploys to 3dev (145.38.188.218) automatically
```

For other servers:
```bash
# NC33 (primary test)
scp metavox-X.Y.Z.tar.gz sditmeijer2@145.38.194.10:/tmp/
ssh sditmeijer2@145.38.194.10 "sudo tar -xzf /tmp/metavox-X.Y.Z.tar.gz -C /var/www/nextcloud/apps/ && sudo chown -R www-data:www-data /var/www/nextcloud/apps/metavox && sudo -u www-data php /var/www/nextcloud/occ app:disable metavox && sudo -u www-data php /var/www/nextcloud/occ app:enable metavox"

# NC32
scp metavox-X.Y.Z.tar.gz sditmeijer@145.38.184.76:/tmp/
ssh sditmeijer@145.38.184.76 "sudo tar -xzf /tmp/metavox-X.Y.Z.tar.gz -C /var/www/nextcloud/apps/ && sudo chown -R www-data:www-data /var/www/nextcloud/apps/metavox && sudo -u www-data php /var/www/nextcloud/occ app:disable metavox && sudo -u www-data php /var/www/nextcloud/occ app:enable metavox"
```

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

### 9.7 App Store Upload

- **URL:** https://apps.nextcloud.com/developer/apps/releases/new
- **Download URL:** GitHub release download URL (lowercase `metavox` in filename!)
- **Signature:** Output from step 9.5
- **Note:** Regenerate signature after any tarball change!

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
- [ ] Test servers (3dev, NC33, NC32) available for emergencies
- [ ] Server backup stored at `/tmp/metavox.backup.*` after deploy
- [ ] Rollback commands:
  ```bash
  # Git rollback
  git checkout v<previous-tag>

  # Server rollback (3dev)
  ssh rdekker@145.38.188.218 'sudo rm -rf /var/www/nextcloud/apps/metavox && sudo mv /tmp/metavox.backup.YYYYMMDD_HHMMSS /var/www/nextcloud/apps/metavox'

  # Re-enable after rollback (routes cache)
  ssh rdekker@145.38.188.218 'sudo -u www-data php /var/www/nextcloud/occ app:disable metavox && sudo -u www-data php /var/www/nextcloud/occ app:enable metavox'
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
git push github main --tags    # GitHub

# 4. Tarball (see section 9.1)

# 5. Deploy & test
bash deploy.sh

# 6. Sign & upload (see sections 9.5-9.7)
```

---

## Notes

- **App ID:** `metavox`
- **Nextcloud versions:** 31, 32, 33
- **PHP version:** >= 8.1
- **Supported languages:** NL, DE (source: EN)
- **Server dependencies:** Redis (optional, for locking/presence/push), notify_push (optional, for real-time sync)
- **App Store:** https://apps.nextcloud.com
- **Gitea:** https://gitea.rikdekker.nl/rik/MetaVox (primary)
- **GitHub:** https://github.com/nextcloud/metavox (mirror, releases)
- **Signing key:** `metavox.key` in project root (NOT in git!)
- **Route changes require version bump** — NC caches routes, needs `app:disable` + `app:enable`

---

*Last updated: March 2026*
