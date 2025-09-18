# Changelog
All notable changes to this project will be documented in this file.

## 2.5.1 - 2024-09-18
### Fixed
* Make license info SPDX compliant: GPL-3.0-or-later

## 2.5.0 - 2024-04-30
### Added
* Export `isAxiosError` and Axios types - So in most cases you do not need to also depend on vanilla Axios

### Fixed
* fix: Set X-Requested-With header on all requests to avoid browser auth dialogs

### Changed
* Update NPM to v10 for LTS Node version 20
* Bump axios from 1.5.0 to 1.6.8
* Bump @nextcloud/router from 2.1.2 to 3.0.1
* Bump @nextcloud/auth from 2.1.0 to 2.3.0
* Migrate to vite and vitest
* Adjust badges and links in README

## 2.4.0 – 2023-06-28
### Fixed
- Fix package exports to allow Typescript projects with module
  resolution of `Node16` or `NodeNext` to import the package

### Changed
- Axios upgrade from v0.27 to v1.4
- Update node engines to next LTS (node 20 / npm 9) 
- Dependency updates

## 2.3.0 – 2022-12-13
### Changed
- Dependency updates
### Fixed
- Cancelled request handling in interceptors
- External rollup dependency @nextcloud/router

## 2.2.0 – 2022-11-24
### Added
- Session expiry handler (opt-in)
### Changed
- Dependency updates

## 2.1.0 - 2022-10-01
### Added
- Maintenance mode retry handler
- Expired CSRF token retry handler
### Changed
- Dependency updates

## 2.0.0 - 2022-08-11
### Added
- Use rollup as bundler
- Add ESM Support
- Run tests in more Node versions
### Changed
- Remove babel

## 1.11.0 – 2022-08-10
### Changed
- Require Node 16 with NPM 7 or NPM 8
- Dependency updates

## 1.10.0 – 2022-04-27
### Changed
- Dependency updates
- Remove babel as production dependency
- Set `@nextcloud/auth` as dependency

## 1.9.0 – 2022-01-18
### Changed
- Dependency updates (esp. axios v0.25.0)
### Fixed
- Update vulnerable packages (follow-redirects v1.14.7 via axios v0.25.0)

## 1.8.0 – 2021-11-26
### Changed
- Dependency updates (esp. axios v0.24.0)

## 1.7.0 – 2021-09-28
### Changed
- Dependency updates

## 1.6.0 - 2021-01-05
### Changed
- Dependency updates (esp. axios v0.21.1)

## 1.5.0 - 2020-10-27
### Changed
- Dependency updates (esp. axios v0.21.0)

## 1.4.0 - 2020-08-31
### Changed
- Dependency updates (esp. axios v0.20.0)
### Fixed
- Update vulnerable packages

## 1.3.3 - 2020-06-08
### Changed
- Dependency updates
### Fixed
- Update vulnerable packages

## 1.3.2 - 2020-03-19
### Changed
- Dependency updates
### Fixed
- Update vulnerable packages

## 1.3.1 - 2020-01-10
### Changed
- Fixed bug in @nextcloud/event-bus

## 1.2.0 - 2020-01-07
### Changed
- Updated dependencies

## 0.5
### Added
- Cancellation support via axios.CancelToken
### Changed
- Updated browserslist config
