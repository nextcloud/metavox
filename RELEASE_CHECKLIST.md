# MetaVox App Store Release Checklist

Follow this checklist for every release to the Nextcloud App Store.

---

## 0. Certificate Verification (CRITICAL!)

**Before every release**, verify that your signing key matches the App Store certificate!

- [ ] Verify signing key matches App Store certificate:
  ```bash
  # Hash of local signing key
  openssl rsa -in metavox.key -pubout 2>/dev/null | openssl md5

  # Hash of App Store certificate (must be IDENTICAL!)
  curl -s "https://apps.nextcloud.com/api/v1/apps.json" | \
    python3 -c "import json,sys; [print(a['certificate']) for a in json.load(sys.stdin) if a['id']=='metavox']" | \
    openssl x509 -pubkey -noout 2>/dev/null | openssl md5
  ```
- [ ] Both MD5 hashes are **IDENTICAL**
- [ ] Check certificate serial number and validity

### Certificate Warnings:
- **NEVER request a new certificate unnecessarily** - this automatically revokes the old one!
- Only request a new certificate if the private key is compromised or lost
- Keep your `.key` file safe (backup in secure location, NOT in git!)
- After certificate change: download the new certificate and store with the key

---

## 1. Code Quality & Security

- [ ] Remove all `console.log()` and debug statements from JavaScript (`src/`)
- [ ] Remove all `error_log()` and debug code from PHP (`lib/`)
- [ ] Check for hardcoded credentials, API keys, or passwords
- [ ] Ensure `.gitignore` is up-to-date (keys, certificates, .env files)
- [ ] Verify that sensitive files are NOT in the repository
- [ ] Run `npm audit` and fix critical vulnerabilities
- [ ] Check for XSS, SQL injection, and other OWASP vulnerabilities
- [ ] Review all new code for security issues
- [ ] **Check tarball for sensitive data** (see Section 8.1)

---

## 2. Translations (l10n/)

- [ ] Check that all new strings are translated in all supported languages (EN, NL, DE, FR)
- [ ] Validate JSON syntax in all translation files (`l10n/*.json`)
- [ ] Regenerate `.js` translation files: `python3 regenerate_js_translations.py`
- [ ] Test the application in each language for missing or truncated text

---

## 3. Version Management

- [ ] Determine new version number (semantic versioning: MAJOR.MINOR.PATCH)
- [ ] Update version in `package.json`
- [ ] Update version in `appinfo/info.xml`
- [ ] Verify both versions match
- [ ] Update `CHANGELOG.md` with all changes for this release:
  - [ ] New features
  - [ ] Bug fixes
  - [ ] Breaking changes
  - [ ] Known issues

---

## 4. Build & Testing

- [ ] Remove `node_modules/` and run `npm ci` (clean install)
- [ ] Run `npm run build` without errors or warnings
- [ ] Check bundle size (no unexpected growth)
- [ ] Test all core functionalities manually:
  - [ ] Files sidebar: metadata panel loads for files in groupfolders
  - [ ] Admin panel: field management (create, edit, delete fields)
  - [ ] Admin panel: groupfolder field assignment works
  - [ ] Admin panel: Statistics tab loads
  - [ ] Personal settings page loads
  - [ ] Field types work correctly (text, number, date, select, multiselect, checkbox, URL, user picker, file link)
  - [ ] Bulk metadata editor (select multiple files → Edit Metadata)
  - [ ] Bulk metadata clear works
  - [ ] CSV export of metadata
  - [ ] Unified search finds files by metadata
  - [ ] Flow integration: MetaVox checks appear in Workflow Engine
  - [ ] Flow operators match field types (text, date, number, select, etc.)
  - [ ] Telemetry/Statistics: opt-in/out works, send report now button
  - [ ] Multi-language support works
- [ ] Test on a clean Nextcloud installation
- [ ] Check browser console for JavaScript errors
- [ ] Test with different browsers (Chrome, Firefox, Safari, Edge)

---

## 5. Nextcloud Compatibility

- [ ] Check min/max Nextcloud version in `appinfo/info.xml`
- [ ] Test on the minimum supported Nextcloud version (31)
- [ ] Test on the latest Nextcloud version
- [ ] Verify PHP version requirement
- [ ] Check that all Nextcloud API calls still work
- [ ] Test with the latest version of @nextcloud/vue
- [ ] Verify `@nextcloud/files` compatibility (v3.x for NC 28-32, v4.x for NC 33+)
- [ ] Verify groupfolders app compatibility

---

## 6. Assets & Files

- [ ] Verify all required files are in the tarball:
  - [ ] `appinfo/` (info.xml, routes.php, services.xml)
  - [ ] `lib/` (PHP backend)
  - [ ] `js/` (compiled JavaScript: admin.js, user.js, filesplugin.js, metavox-flow.js)
  - [ ] `css/` (stylesheets)
  - [ ] `img/` (icons)
  - [ ] `l10n/` (translations - both .json and .js)
  - [ ] `templates/` (PHP templates)
  - [ ] `README.md`
  - [ ] `CHANGELOG.md`
  - [ ] `LICENSE`
- [ ] Verify that `src/` is NOT in the tarball (only compiled code)
- [ ] Verify that `docs/` is NOT in the tarball
- [ ] Check app icon for App Store
- [ ] Update screenshots if UI has changed

---

## 7. Git & Repository

- [ ] All changes are committed
- [ ] No uncommitted changes present
- [ ] Branch is up-to-date with main
- [ ] Merge conflicts are resolved
- [ ] Check that sensitive files are not in git history

---

## 8. Release Package

- [ ] Create the tarball
- [ ] Verify tarball contents (`tar -tzf metavox-x.x.x.tar.gz`)
- [ ] **IMPORTANT:** Verify root folder is `metavox` (lowercase, no version number)
- [ ] Push to remote(s):
  ```bash
  git push origin main
  git push github main
  ```
- [ ] Create git tag:
  ```bash
  git tag -a vX.Y.Z -m "Release vX.Y.Z"
  git push origin vX.Y.Z
  git push github vX.Y.Z
  ```
- [ ] Upload tarball to release
- [ ] Generate signature with the correct key:
  ```bash
  openssl dgst -sha512 -sign metavox.key metavox-x.x.x.tar.gz | openssl base64 -A
  ```
- [ ] Upload to Nextcloud App Store:
  - [ ] Download URL (lowercase!)
  - [ ] Signature (regenerate after any tarball change!)
  - [ ] Release notes

### 8.1 Tarball Security Check (CRITICAL!)

**ALWAYS check** the tarball for sensitive data before uploading!

```bash
# Check for sensitive files
tar -tzf metavox-x.x.x.tar.gz | grep -iE '(internal|credential|\.key|\.env|deploy|docs/)'

# Search for IP addresses, passwords
tar -xzf metavox-x.x.x.tar.gz -O 2>/dev/null | \
  grep -iE '(password\s*=|api_key\s*=|secret\s*=|145\.|192\.168\.)' | head -20
```

**Do NOT include in tarball:**
- `src/` - Source code (only compiled js/)
- `node_modules/` - Dependencies
- `.git/` - Git history
- `docs/` - Internal documentation
- `*.key`, `*.crt`, `*.pem` - Certificates and keys
- `deploy.sh` - Deployment script with server details
- `push-to-github.sh` - GitHub push helper
- `openapi.json` - API spec (not needed at runtime)
- Any files containing server IPs, credentials, or usernames

---

## 9. Post-Release Verification

- [ ] Install the app from the App Store on a test server
- [ ] Verify the app works correctly after installation
- [ ] Check that the version is displayed correctly
- [ ] Test the upgrade path from the previous version
- [ ] Verify database migrations run correctly on upgrade
- [ ] Sync all remotes
- [ ] Make a release announcement if major release

---

## 10. Rollback Plan

- [ ] Backup of the previous release is available
- [ ] Rollback procedure is tested
- [ ] Test server available for emergencies

---

## Quick Commands

```bash
# Regenerate translations
python3 regenerate_js_translations.py

# Production build
npm run build

# Security audit
npm audit

# Deploy to test server
./deploy.sh 3dev
```

---

## Quick Release Flow

### 1. Preparation
```bash
# Verify versions match
grep version package.json appinfo/info.xml

# Build
npm run build

# Regenerate translations if needed
python3 regenerate_js_translations.py
```

### 2. Commit & Push
```bash
git add -A
git commit -m "Release vX.Y.Z - [Description]"
git push origin main
```

### 3. Create Tag & Push to Remotes
```bash
git tag -a vX.Y.Z -m "Release vX.Y.Z - [Description]"
git push origin main --tags
git push github main --tags
```

### 4. Create Tarball
**IMPORTANT:** Root folder must be `metavox` (lowercase, no version number)

```bash
TEMP_DIR=$(mktemp -d) && \
mkdir -p "$TEMP_DIR/metavox" && \
cp -r appinfo lib l10n templates css img js "$TEMP_DIR/metavox/" && \
cp CHANGELOG.md LICENSE README.md "$TEMP_DIR/metavox/" && \
cd "$TEMP_DIR" && \
tar -czf metavox-X.Y.Z.tar.gz metavox && \
mv metavox-X.Y.Z.tar.gz /Users/rikdekker/Documents/Development/MetaVox/ && \
rm -rf "$TEMP_DIR"
```

**Exclude:** src/, node_modules/, .git/, docs/, *.key, deploy.sh, push-to-github.sh, openapi.json

### 5. Generate Signature (for App Store)
```bash
# First decrypt secrets on USB drive (requires GPG passphrase):
cd /Volumes/WDS && gpg --decrypt secrets.gpg | tar xzf -

# Then generate signature:
openssl dgst -sha512 -sign /Volumes/WDS/secrets/projects/metavox/metavox.key /Users/rikdekker/Documents/Development/MetaVox/metavox-X.Y.Z.tar.gz | openssl base64 -A

# After signing, remove decrypted files:
rm -rf /Volumes/WDS/secrets
```

### 6. Create GitHub Release
```bash
gh release create vX.Y.Z metavox-X.Y.Z.tar.gz \
  --title "vX.Y.Z - [Description]" \
  --notes "## What's New in vX.Y.Z

### New Features
- Feature 1
- Feature 2

### Improvements
- Improvement 1

Full changelog: https://github.com/nextcloud/metavox/blob/main/CHANGELOG.md"
```

**Download URL after release:**
```
https://github.com/nextcloud/metavox/releases/download/vX.Y.Z/metavox-X.Y.Z.tar.gz
```

### 7. App Store Upload
- **URL:** GitHub release download URL (lowercase app name!)
- **Signature:** Output from step 5

**Note:** Regenerate signature after any tarball change!

---

## Notes

- **Minimum Nextcloud version:** 31 (check `appinfo/info.xml`)
- **Supported languages:** EN, NL, DE, FR
- **App Store:** https://apps.nextcloud.com
- **Gitea:** https://gitea.rikdekker.nl/rik/MetaVox
- **GitHub:** https://github.com/nextcloud/metavox
- **Signing key:** `/Volumes/WDS/secrets/projects/metavox/metavox.key` (USB drive, versleuteld)
- **JS entry points:** admin.js, user.js, filesplugin.js, metavox-flow.js
- **Depends on:** groupfolders app (for metadata scoping)

---

## MetaVox-Specific Checks

- [ ] Verify groupfolder-scoped metadata works (no global metadata tables)
- [ ] Test Files sidebar tab registration (no duplicate tabs)
- [ ] Verify Flow integration registers correctly in Workflow Engine
- [ ] Check `@nextcloud/files` FileAction API compatibility (v3.x vs v4.x)
- [ ] Verify database migrations are idempotent (safe to re-run)
- [ ] Test with groupfolders app enabled AND disabled (graceful degradation)
- [ ] Verify OpenAPI spec matches actual API routes (if updating docs)
- [ ] Check search index updates correctly when metadata changes

---

*Last updated: January 2026*
