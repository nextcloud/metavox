# Changelog

All notable changes to this project will be documented in this file.

## 2.7.0 - 2023-09-20
**Full Changelog**: https://github.com/nextcloud-libraries/nextcloud-logger/compare/v2.6.1...v2.7.0

*Note: The packages of the previous releases (2.6.x) were broken.*

### Fixed
* chore: Add missing description to package by @susnux in https://github.com/nextcloud-libraries/nextcloud-logger/pull/557

### Changed
* Updated dependencies

## 2.6.1 - 2023-09-20
**Full Changelog**: https://github.com/nextcloud-libraries/nextcloud-logger/compare/v2.6.0...v2.6.1

### Fixed
* Remove permission check in publish workflows by @artonge in https://github.com/nextcloud-libraries/nextcloud-logger/pull/551

## 2.6.0 - 2023-09-20
**Full Changelog**: https://github.com/nextcloud-libraries/nextcloud-logger/compare/v2.5.0...v2.6.0

### Added
* Allow logging errors when passed as message parameter by @susnux in https://github.com/nextcloud-libraries/nextcloud-logger/pull/444

### Fixed
* Added missing test cases for `LoggerBuilder` and `index` by @susnux in https://github.com/nextcloud-libraries/* Only add required files to npm package by @susnux in https://github.com/nextcloud-libraries/nextcloud-logger/pull/443
* Add typings to published package by @susnux in https://github.com/nextcloud-libraries/nextcloud-logger/pull/442
* Add unit tests workflow by @susnux in https://github.com/nextcloud-libraries/nextcloud-logger/pull/504
* Lint files using ESLint and add CI workflow by @susnux in https://github.com/nextcloud-libraries/nextcloud-logger/pull/505
* fix: Add missing unit tests for `ConsoleLogger` by @susnux in https://github.com/nextcloud-libraries/nextcloud-logger/pull/506
* Use the original property for debug and loglevel by @artonge in https://github.com/nextcloud-libraries/nextcloud-logger/pull/550

### Changed
* chore: update node engines to next LTS by @nextcloud-command in https://github.com/nextcloud-libraries/nextcloud-logger/pull/496
* Updated dependencies


### New Contributors
* @artonge made their first contribution in https://github.com/nextcloud-libraries/nextcloud-logger/pull/550

## 2.5.0 – 2023-01-11
### Changed
- Postpone log level detection until OC loaded
- Dependency updates
### Fixed
- Fix node scripts for building and documentation

## 2.4.0 – 2022-10-24
### Changed
- Dependency updates

## 2.3.0 - 2022-08-25
### Fixed
- Dependency on global OC
### Changed
- Dependency updates

## 2.2.1 - 2022-07-07
### Fixed
- More robust agains library loading order problems
- Work without the `OC` global

## 2.2.0 – 2022-06-27
### Added
- Respect server log level and debug mode settings
### Changed
- Dependency updates

## 2.1.0 – 2021-09-28
### Changed
- Dependency updates

## 2.0.0 – 2021-04-07
### Changed
- Browserslist config updated, which means some older browsers are not supported anymore now
- Dependency updates

## 1.1.2 - 2020-03-19
### Changed
- Dependency updates
### Fixed
- Update vulnerable packages

## 1.1.1 - 2020-01-10
### Fixed
- Bug in @nextcloud/event-bus dependency

## 1.1.0 - 2020-01-08
### Added
- Convenience builder method to detect the UID
### Changed
- Updated documentation
