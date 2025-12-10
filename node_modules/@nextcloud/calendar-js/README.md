# :date: @nextcloud/calendar-js - Heart of the [Nextcloud calendar app](https://github.com/nextcloud/calendar)

[![Build Status](https://img.shields.io/endpoint.svg?url=https%3A%2F%2Factions-badge.atrox.dev%2Fnextcloud%2Fcalendar-js%2Fbadge%3Fref%3Dmain&style=flat)](https://actions-badge.atrox.dev/nextcloud/calendar-js/goto?ref=main) [![codecov](https://codecov.io/gh/nextcloud/calendar-js/branch/main/graph/badge.svg)](https://codecov.io/gh/nextcloud/calendar-js)

This library is a wrapper for [ICAL.js](https://github.com/mozilla-comm/ical.js/) that provides more convenient ways for editing.
Together with [cdav-library](https://github.com/nextcloud/cdav-library), it's the heart of the Nextcloud calendar app.

## Maintainers

* [@ChristophWurst](https://github.com/ChristophWurst)
* [@GretaD](https://github.com/GretaD)
* [@st3iny](https://github.com/st3iny)

## Developing

Please take note that this library has been developed solely for the purpose of using it in the Nextcloud calendar app.
Feel free to use it in your project, but don't expect any support / bugfixes / features.

### Setup
```bash
npm ci
```

### Tests

```bash
npm run test
```

### Linting

```bash
npm run lint
```

## Releases

This repository follows the concept of [conventional commits](https://www.conventionalcommits.org/en/v1.0.0). A github action workflow automates the release. However, an authorized [maintainer](#maintainers) has to approve the workflow before it can run.

1) Go to https://github.com/nextcloud/calendar-js/actions/workflows/release.yml
2) Click *Run workflow*
   1) Select *Branch: main*
   2) Click *Run workflow*
3) Reload the page
4) Click on the waiting *Release* workflow (yellow clock icon) and approve it if you have permission or ask one of the [maintainers](#maintainers)

## License

Calendar-js is licensed under the [GNU Affero General Public License](https://www.gnu.org/licenses/agpl-3.0.en.html), version 3 or later. 
