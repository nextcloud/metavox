# MetaVox v2.1.0 — Nextcloud 34 compatibility release

## Context

### NC 34 release timeline (gevonden op github.com/nextcloud/server)
| Mijlpaal | Datum |
|---|---|
| Beta 5 (huidig) | 2026-05-12 |
| RC1 | 2026-05-14 (morgen) |
| **GA (Hub 26 Spring)** | **2026-06-09** |
| EOL | 2027-06-08 |

Master `version.php` staat op `[34, 0, 0, 6]`, label `'34.0.0 beta 5'`. `OC_VersionCanBeUpgradedFrom` accepteert alleen NC 33 of 34 — upgrade vanaf NC 32 of lager moet via 33.

### NC 34 vereisten
- **PHP min**: 8.2 (ongewijzigd vanaf NC 33). Max <8.6. Aanbevolen 8.5. PHP 8.2 staat als *deprecated* gemarkeerd — waarschijnlijk gedropt in NC 35.
- **DB**: geen wijzigingen vs NC 33. MySQL 8.0+, MariaDB 10.6+, PostgreSQL 14+, SQLite 3.24+, Oracle 19c+.
- **Info.xml schema**: geen bump.
- **App-cert signing**: ongewijzigd.

### NC 34 hard removals (PHP + JS) — relevant voor audit
| Categorie | API's verwijderd |
|---|---|
| JS globals | `OC.Dialogs.fileexists`, `OC.Notifications`, `OC.Apps`, `OC.*menu*`, `live-relative-timestamp` magic class, global `snapper`, gebundelde jQuery/jQuery-UI/Backbone/Handlebars, `OC.Files.Client` |
| PHP klassen | `\OCP\Share_Backend*`, `StrictContentSecurityPolicy`, `StrictEvalContentSecurityPolicy`, `StrictInlineContentSecurityPolicy` |
| PHP methoden | `EmptyContentSecurityPolicy::allowEvalScript`, `addAllowedChildSrcDomain`, `disallowChildSrcDomain`, `IManager::registerResourceProvider`, `IManager::registerNotifier`, `Util::recursiveArraySearch`, `OC_Util::encodePath`/`sanitizeHTML`/`redirectToDefaultPage`/`getDefaultPageUrl`/`checkAdminUser` |

**MetaVox grep-resultaten**: nul matches in `src/`, `js/` en `lib/`. Geen enkele hard-removed API in gebruik.

### NC 34 nieuwe deprecaties (werken nog wel, migratie plannen)
- `\OCP\Util::setChannel` → `\OCP\ServerVersion::setChannel`
- `\OCP\Util::linkToAbsolute`, `linkToRemove` → `\OCP\IUrlGenerator`
- `\OCP\Util::isPublicLinkPasswordRequired`, `isDefaultExpireDateEnforced` → `\OCP\Share\IManager`

MetaVox gebruikt hiervan: geen.

### Eén deprecation-surface in MetaVox
[lib/Listener/RegisterFlowChecksListener.php:22](MetaVox/lib/Listener/RegisterFlowChecksListener.php#L22) injecteert `OCP\IServerContainer` om vervolgens op regel 32 `$this->container->get(MetadataCheck::class)` te doen. Niet hard-removed in NC 34, maar wel direction-of-travel deprecated. Refactor in dit release per gebruikersbeslissing.

### Nieuwe NC 34-only API's (bewust níet adopteren)
- `\OCP\DB\QueryBuilder\ITypedQueryBuilder` via `IDBConnection::getTypedQueryBuilder()`
- `\OCP\Migration\IRepairStepExpensive`

Beide niet beschikbaar in NC 31-33 → adoptie zou `min-version=34` forceren.

### NC 35 status (2026-05-13)
- **Geen `stable35` branch**. Hoogste is `stable33`.
- **Geen v35 milestone op GitHub**. Actieve milestone is "Nextcloud 34" (485 open / 603 gesloten issues, due 2026-06-09).
- **Geen documentatie, geen blog post**. `docs.nextcloud.com/server/35/` retourneert HTTP 404.
- **Enige NC 35-artifact**: lege `35-feedback` GitHub label (Nextcloud pre-creëert deze labels).
- Realistische GA: Q4 2026 (≈6 maanden na NC 34 per de NC release cadence). **Geen autoritatieve datum.**

### MetaVox baseline (na v2.0.9, vandaag uitgerold)
- Huidige versie: 2.0.9
- info.xml: `<nextcloud min-version="31" max-version="33"/>`, PHP min 8.1
- Architectuur: IBootstrap, attribute-style controllers (NoAdminRequired/NoCSRFRequired/CORS), TimedJob+QueuedJob, PSR LoggerInterface — al volledig op moderne stack
- Frontend: Vue 3, `@nextcloud/files@^4.0.0-rc.0`, `@nextcloud/vue@^9.0.0`, dual-path sidebar registratie (NC 33+ scoped global + NC 31-32 `OCA.Files.Sidebar.registerTab` fallback al aanwezig)
- 5 background jobs (Telemetry, LicenseUsage, CleanupDeletedMetadata, MetadataBackup, UpdateSearchIndex)
- Hard GroupFolders-afhankelijkheid via `\OC::$server->get(\OCA\GroupFolders\Folder\FolderManager::class)`

## Decisions (door gebruiker bevestigd)

1. **Compat range**: `min-version="31"`, `max-version="34"` — behoud NC 31-32-33, voeg 34 toe. Conservatief; geen NC 34-only API's adopteren.
2. **Validatie**: parallelle Hetzner-container `nc-34-dev` opzetten, MetaVox 2.1.0 daar deployen op NC 34 beta 5 → RC1 → GA. Runtime-evidence vóór GA.
3. **`IServerContainer` refactor**: ja, in deze release. Eén file, ~5 regels.
4. **Compat-only release** — geen features, geen deps-bumps, geen schema-migraties.
5. **Target**: v2.1.0 (minor — compat is feature-level in semver).

## Pre-existing bug: geen

In tegenstelling tot het vorige datetime-plan (v2.0.9 onthulde een `updateField` bug): voor deze NC 34 audit zijn er **geen verborgen bugs** ontdekt. Alle NC 34-removal patterns retourneren nul matches in MetaVox.

## Files to change

### 1. `IServerContainer` → directe DI refactor

[lib/Listener/RegisterFlowChecksListener.php](MetaVox/lib/Listener/RegisterFlowChecksListener.php) — volledige before/after:

**Voor:**
```php
namespace OCA\MetaVox\Listener;

use OCA\MetaVox\Flow\MetadataCheck;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IServerContainer;
use OCP\Util;
use OCP\WorkflowEngine\Events\RegisterChecksEvent;

/**
 * @template-implements IEventListener<RegisterChecksEvent>
 */
class RegisterFlowChecksListener implements IEventListener {

    public function __construct(
        private IServerContainer $container,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof RegisterChecksEvent) {
            return;
        }

        $check = $this->container->get(MetadataCheck::class);
        $event->registerCheck($check);

        Util::addScript('metavox', 'metavox-flow');
    }
}
```

**Na:**
```php
namespace OCA\MetaVox\Listener;

use OCA\MetaVox\Flow\MetadataCheck;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;
use OCP\WorkflowEngine\Events\RegisterChecksEvent;

/**
 * @template-implements IEventListener<RegisterChecksEvent>
 */
class RegisterFlowChecksListener implements IEventListener {

    public function __construct(
        private MetadataCheck $metadataCheck,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof RegisterChecksEvent) {
            return;
        }

        $event->registerCheck($this->metadataCheck);

        Util::addScript('metavox', 'metavox-flow');
    }
}
```

**Geen wijziging nodig in [lib/AppInfo/Application.php:45](MetaVox/lib/AppInfo/Application.php#L45)** — die regel is:
```php
$context->registerEventListener(RegisterChecksEvent::class, RegisterFlowChecksListener::class);
```
Geverifieerd; NC's DI-container resolved `MetadataCheck` zelf via autowiring.

**Impact**: `MetadataCheck` wordt nu eager geconstructed op DI-resolve in plaats van lazy bij eerste `handle()`-call. `RegisterChecksEvent` vuurt alleen wanneer de Workflow-UI/engine wordt aangeroepen, niet op elke request — geen perf-regressie.

### 2. `appinfo/info.xml` — drie edits

| Regel | Voor | Na |
|---|---|---|
| 26 (description) | `Supports **Nextcloud 31, 32, and 33** with automatic feature detection.` | `Supports **Nextcloud 31, 32, 33, and 34** with automatic feature detection.` |
| 28 (version) | `<version>2.0.9</version>` | `<version>2.1.0</version>` |
| 50 (dependencies) | `<nextcloud min-version="31" max-version="33"/>` | `<nextcloud min-version="31" max-version="34"/>` |

PHP min blijft 8.1. Geen schema-bump.

### 3. `package.json` + `package-lock.json`

[package.json:4](MetaVox/package.json#L4) — `"version": "2.0.9"` → `"2.1.0"`.

[package-lock.json](MetaVox/package-lock.json) — twee top-level versievelden bumpen (regels ~3 en ~9, in `packages[""]`). Niet de dep-versie entries (regel 42+) aanraken. Eenvoudigste path: `npm version 2.1.0 --no-git-tag-version` daarna gerichte commit van alleen de twee regels.

**Geen dep-bumps**. `@nextcloud/files@^4.0.0-rc.0` en `@nextcloud/vue@^9.0.0` zijn al forward-compat met NC 34 per NC's release notes.

### 4. `CHANGELOG.md`

Voeg toe vóór `## [2.0.9] - 2026-05-13`:

```markdown
## [2.1.0] - 2026-06-09

### Added
- **Nextcloud 34 support** — MetaVox is gecertificeerd op Nextcloud 34
  (GA 2026-06-09). Getest op RC1 en GA tegen het volledige feature-oppervlak:
  inline editing, sidebar tab, bulk actions, views, Flow checks, search,
  AI autofill, background jobs, en admin/personal settings. Ondersteund
  bereik is nu **Nextcloud 31, 32, 33, and 34**.

### Fixed
- **`IServerContainer` deprecation** — de Flow check listener
  (`RegisterFlowChecksListener`) injecteert nu `MetadataCheck` direct via
  constructor-DI in plaats van het via `\OCP\IServerContainer` op te lossen
  tijdens `handle()`. NC 34 markeert `IServerContainer` als
  direction-of-travel deprecated; deze wijziging haalt onze enige
  usage weg. Geen gedragsverandering, geen API-verandering.

### Compatibility
- Minimum: Nextcloud 31, PHP 8.1.
- Maximum: Nextcloud 34, PHP 8.5 (NC 34 aanbevolen).
- Geen schema-migraties. Geen nieuwe dependencies. Upgrade vanaf 2.0.x is een drop-in.
```

## Translation impact

**Zero.** De refactor raakt geen l10n-strings. Beschrijvingstekst in `info.xml` is niet in onze l10n-catalogus opgenomen. Geen categorisch diff nodig.

## Validatie: nieuwe `nc-34-dev` container op Hetzner

Eenmalige infra-setup, los van per-release deploy.

### DNS + proxy
- Nieuwe subdomain: **`nc34.rikdekker.nl`** (cleaner dan path-prefix sharing met `dev.rikdekker.nl`)
- DNS: A-record naar dezelfde Hetzner host als dev.rikdekker.nl
- nginx-proxy-manager: nieuwe proxy host `nc34.rikdekker.nl` → container's port (suggestie `127.0.0.1:8034` host-side), met Let's Encrypt SSL

### Container layout
- Host directory: `/opt/docker/nc-34-dev/` (parallel aan `/opt/docker/nc-dev/`)
- Eigen `docker-compose.yml`, eigen volumes, **eigen DB** (geen sharing met nc-dev — vermijdt contaminatie van test-data tussen NC-versies)
- Image: `nextcloud:34.0.0-beta5-apache` nu → `nextcloud:34.0.0-rc1-apache` per 2026-05-14 → `nextcloud:34.0.0-apache` per 2026-06-09
- Na install: enable `groupfolders` (eerst verifiëren dat een NC34-compatible groupfolders release bestaat — zie risico-tabel), `notify_push`, `workflowengine` (built-in)

### Deploy flow voor MetaVox naar nc-34-dev
Spiegelt het bestaande nc-dev flow per de `nc-dev Deploy Flow` memory — `docker cp` van het tarball, daarna `occ`. **Niet** `npm run deploy`:

```bash
docker cp metavox-2.1.0.tar.gz nc-34-dev:/tmp/
docker exec nc-34-dev tar -xzf /tmp/metavox-2.1.0.tar.gz -C /var/www/html/custom_apps/
docker exec --user www-data nc-34-dev php occ app:enable metavox
docker exec --user www-data nc-34-dev php occ upgrade
```

Documenteer `nc-34-dev` setup in `internal-docs/`, **niet** in `deploy.sh`. Houd `deploy.sh` op `nc-dev` (NC 33) tot NC 34 de standaard wordt; later voeg je een `-e nc34` flag toe.

## Validatie-checklist (op nc-34-dev)

Code-review alleen is onvoldoende — deze lijst is de gating evidence vóór tag 2.1.0:

### Install + boot
- [ ] `occ app:enable metavox` succes, geen errors in `nextcloud.log`
- [ ] App-icoon verschijnt, admin opent admin-panel
- [ ] Eerste pageload: nul `Deprecated`/`Removed` warnings in `nextcloud.log`

### Files app integratie
- [ ] File-list toont MetaVox kolommen in een groupfolder
- [ ] Sidebar tab "MetaVox" verschijnt bij file-selectie
- [ ] Bulk-actie "Edit metadata" verschijnt in multi-select toolbar
- [ ] Filesplugin kolom-injectie rendert per veldtype
- [ ] `OCA.Files.Sidebar.close()` calls in ViewManager.js werken (3 sites: regel 596, 790, 891)

### Inline editing — één test per veldtype
- [ ] text, number, date, datetime, checkbox, dropdown, multi-select, URL, user picker, file link
- [ ] Dubbelklik op cel → edit popover opent
- [ ] Save → real-time update via notify_push naar tweede browser-tab
- [ ] Cell-lock conflict UI vuurt als twee tabs dezelfde cel bewerken

### Views
- [ ] View aanmaken met kolom-subset + filter + sort
- [ ] Switch views, refresh, view blijft persistent
- [ ] Default-view selectie per groupfolder respected

### Background jobs (één execution per job)
- [ ] `occ background-job:execute <id>` voor TelemetryJob, LicenseUsageJob, CleanupDeletedMetadata, MetadataBackupJob, UpdateSearchIndex — allemaal succes
- [ ] License usage cron hit `https://licenses.voxcloud.nl` met HTTP 200
- [ ] Telemetry payload bevat `hasExtendedSupport` (regressie-check vanaf 2.0.9)

### Admin features
- [ ] Statistics tab laadt
- [ ] Backup & Restore: on-demand backup + restore werkt
- [ ] Support tab: "View pricing & plans" link naar `voxcloud.nl/pricing/#metavox`
- [ ] AI autofill toggle werkt

### Flow integratie (de gerefactorde listener)
- [ ] Workflow → Add rule → "MetaVox metadata" verschijnt in dropdown
- [ ] Rule aanmaken (bv. "Tag = confidential" → notify admin)
- [ ] File upload met die metadata triggert de flow

### Search
- [ ] Unified search retourneert MetaVox results uit `metavox_search_index`
- [ ] Metadata-update wordt opgepakt door `UpdateSearchIndex` op volgende run

### AI
- [ ] TaskProcessing API availability gate werkt (geen errors als provider disabled)
- [ ] Met provider configured: autofill produceert suggesties

### Permissions
- [ ] User in limited group ziet alleen permitted fields
- [ ] `can_manage` flag in init-data is `false` voor non-managers

### REST/OCS API
- [ ] `GET /ocs/v2.php/apps/metavox/api/v1/fields/<gf_id>` → 200 met verwacht shape
- [ ] Bulk update via OCS API werkt

**Hervalueer de hele lijst op RC1 (2026-05-14+) en op GA-dag (2026-06-09).**

## Forward-looking NC 35

- Status 2026-05-13: geen `stable35` branch, geen v35 milestone, geen docs, geen blog. Enige artifact: lege `35-feedback` label.
- Realistische GA: Q4 2026 (~6 maanden na NC 34) — **geen autoritatieve datum**.
- **Trigger voor re-audit**: zodra `nextcloud/server` een v35 milestone opent OF een `stable35` branch verschijnt.
- **Concrete actie**: kalender-reminder op 2026-08-01 om milestone-status te checken.

### `min-version` lifecycle
- NC 31 GA was ~april 2025; standaard ~12-maanden EOL → NC 31 is per vandaag al *over* EOL.
- We houden `min-version="31"` voor ISV-klant continuïteit (enterprise klanten lopen achter op LTS-achtige schedules).
- **Aanbevolen bump in 2.2.x of 3.0**: raise `min-version` naar 33 zodra MetaVox-telemetrie toont dat <5% van installs nog op NC 31/32 zit. Ontgrendelt `ITypedQueryBuilder` en `IRepairStepExpensive` voor toekomstige features.

## Out of scope

- `\OCP\DB\QueryBuilder\ITypedQueryBuilder` adoptie (zou `min-version=34` forceren)
- `\OCP\Migration\IRepairStepExpensive` adoptie (idem)
- Sidebar tabs redesign adaptatie (WIP per `nextcloud-libraries/nextcloud-vue#7520` — wacht op GA)
- Unified sharing API adaptatie (WIP; we gebruiken geen `Share_Backend` of share-registratie sowieso)
- Settings titles alignment (WIP)
- Composer / PHPUnit harness setup (apart ticket)
- GitHub Actions CI matrix voor NC 34 (apart ticket — TODO)
- `@nextcloud/*` JS deps bumpen (geen upstream guidance zegt NC 34 vereist het)

## Risk table

| Risico | Kans | Impact | Mitigatie |
|---|---|---|---|
| Sidebar tabs redesign landt vóór NC 34 GA en breekt onze tab-registratie | Medium | Hoog | Test op RC1 binnen 24u na 2026-05-14; bij breakage: upstream-issue + fix in [src/filesplugin/filesplugin-main.js:31-43](MetaVox/src/filesplugin/filesplugin-main.js#L31-L43) vóór 2026-06-09 |
| WIP unified sharing API wijziging | Laag | Laag | We gebruiken geen `Share_Backend*` of share-registratie API's — geen exposure |
| TaskProcessing API surface verandert tussen NC 33 en 34 | Laag | Medium | Al gegate met availability-checks; verifieer in validatie-stap |
| GroupFolders heeft geen NC34-compatible release op RC1 dag | Medium | Critical | Verifieer `groupfolders` releases vóór nc-34-dev spin-up. Als geen compatible release: upstream-issue + nc-34-dev pinnen op laatste NC33-compatible groupfolders voor partiële test; volledige validatie geblokt tot upstream ships |
| Beta-only API regressie reverted tussen RC1 en GA | Laag | Laag | Volledige validatie op GA-dag (2026-06-09); 2.1.0 binnen 48u na GA shippen |
| `MetadataCheck` constructor signature change breekt eager-DI in gerefactorde listener | Heel laag | Medium | `MetadataCheck` is onze eigen klasse — controle over constructor. CI-stijl smoke test: app op fresh install, open Workflow admin |
| PHP 8.1 floor maakt dat we NC 34 PHP 8.2+ idiomen missen | Laag | Laag | Acceptabel — compat release only, geen nieuwe code paths |
| info.xml description wijziging triggert re-translation request | Heel laag | Laag | Description niet in onze l10n catalog; verwijs translator naar Transifex |

## Critical files

- [lib/Listener/RegisterFlowChecksListener.php](MetaVox/lib/Listener/RegisterFlowChecksListener.php) — DI refactor
- [appinfo/info.xml](MetaVox/appinfo/info.xml) — version + dependencies bump
- [package.json](MetaVox/package.json) + [package-lock.json](MetaVox/package-lock.json) — version bump
- [CHANGELOG.md](MetaVox/CHANGELOG.md) — release notes

Verified, geen edits nodig: [lib/AppInfo/Application.php:45](MetaVox/lib/AppInfo/Application.php#L45) (Flow listener registratie heeft geen DI-hints, autowiring lost MetadataCheck op).
