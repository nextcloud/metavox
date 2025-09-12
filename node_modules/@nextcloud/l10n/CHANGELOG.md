# Changelog

All notable changes to this project will be documented in this file.

## 2.2.0 - 2023-06-26

[Full Changelog](https://github.com/nextcloud/nextcloud-l10n/compare/v2.1.0...v2.2.0)

### Added
- `isRTL` was added to check whether a given, or the current, language is read right-to-left [\#639](https://github.com/nextcloud/nextcloud-l10n/pull/639) ([susnux](https://github.com/susnux))

### Fixed
- Add typings to the package exports to fix build for Typescript projects using `node16` or `nodenext` module resolution [\#633](https://github.com/nextcloud/nextcloud-l10n/pull/633) ([susnux](https://github.com/susnux))
- Update exported `NextcloudWindowWithRegistry` type for Nextcloud 27 [\#640](https://github.com/nextcloud/nextcloud-l10n/pull/640) ([susnux](https://github.com/susnux))
- Harden `loadTranslations` by handling edge cases where invalid data is retrieved [\#644](https://github.com/nextcloud/nextcloud-l10n/pull/644) ([susnux](https://github.com/susnux))

### Changed
- Update node engines to next LTS (Node 20 + NPM 9)
- Dependency updates

## 2.1.0 - 2023-02-25

[Full Changelog](https://github.com/nextcloud/nextcloud-l10n/compare/v2.0.1...v2.1.0)

### Added
- `unregister` is now part of the public API [\#579](https://github.com/nextcloud/nextcloud-l10n/pull/579) ([susnux](https://github.com/susnux))

### Fixed
- Export the `Translations` type and add missing documentation [\#566](https://github.com/nextcloud/nextcloud-l10n/pull/566) ([susnux](https://github.com/susnux))
- Fix singular translation in edge cases where plural strings are provided [\#570](https://github.com/nextcloud/nextcloud-l10n/pull/570) ([susnux](https://github.com/susnux))
- Make loading translations use async XMLHttpRequest [\#571](https://github.com/nextcloud/nextcloud-l10n/pull/571) ([susnux](https://github.com/susnux))
- Removed warning "no app translation was registered" [\#572](https://github.com/nextcloud/nextcloud-l10n/pull/572) ([ShGKme](https://github.com/ShGKme))

### Changed
- Added tests and increased code coverage
- Dependency updates

## 2.0.1 - 2023-01-19

[Full Changelog](https://github.com/nextcloud/nextcloud-l10n/compare/v2.0.0...v2.0.1)

### Fixed
- fix\(config\): fix npmignore config [\#563](https://github.com/nextcloud/nextcloud-l10n/pull/563) ([skjnldsv](https://github.com/skjnldsv))
- fix\(config\): fix tsconfig out dir typings [\#562](https://github.com/nextcloud/nextcloud-l10n/pull/562) ([skjnldsv](https://github.com/skjnldsv))
- Fix standalone registration [\#556](https://github.com/nextcloud/nextcloud-l10n/pull/556) ([ShGKme](https://github.com/ShGKme))

### Changed
- Dependency updates

## 2.0.0 - 2023-01-12

[Full Changelog](https://github.com/nextcloud/nextcloud-l10n/compare/v1.6.0...v2.0.0)
### Changed
From 2.0.0, this package is standalone and do not rely on window OC variables to function.

### Fixed
- Provide all translation related functions [\#542](https://github.com/nextcloud/nextcloud-l10n/pull/542) ([susnux](https://github.com/susnux))
- Fix building and deploying documentation [\#546](https://github.com/nextcloud/nextcloud-l10n/pull/546) ([susnux](https://github.com/susnux))

## 1.6.0 - 2022-05-10
### Changed
- Dependency updates
- Remove dependency on OC for `getLocale` and `getLanguage`

## 1.5.0 - 2022-05-10
Superseeded by v1.6.0 as the release was empty.

## 1.4.1 - 2020-09-07
### Changed
- Dependency updates
### Fixed
- Language detection of languages that have a `_` in their code (#172)

## 1.4.0 - 2020-08-31
### Added
- Nextcloud 20 support
### Changed
- Dependency updates

## 1.3.0 - 2020-06-04
### Added
- getCanonicalLocale
### Changed
- Dependency updates

## 1.2.3 - 2020-04-08
### Changed
- Dependency updates
### Fixed
- Source locale of translations is now set to 'en', so fewer warnings are printed

## 1.2.2 - 2020-04-06
### Fixed
- Update vulnerable packages

## 1.2.1 - 2020-04-06
### Changed
- Dependency updates
### Fixed
- Update vulnerable packages

## 1.2.0 - 2020-03-19
### Added
- Optional debug mode with logging, no console.warn for production builds
### Changed
- Dependency updates

## 1.1.1 - 2020-03-19
### Changed
- Dependency updates
### Fixed
- Update vulnerable packages

## 1.1.0 - 2020-02-21
### Added
- Gettext-based translation helpers for Nextcloud-independent translations (mostly for libraries)
### Changed
- Updated documentation
- Updated dependencies

## 1.0.1 - 2020-01-08
### Fixed
- Translate `escape` option typo
### Changed
- Updated documentation
