# Changelog

All notable changes to this project will be documented in this file.
This format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning follows [Semantic Versioning](https://semver.org/).

---

## [1.3.0] - 2025-12-12

### Added
- Dutch (nl) and German (de) translations for the entire application
- Caching for groupfolder mappings and field labels to improve performance
- PHP 8.x `match` expression for file icon detection with expanded file type support
- API response caching for groupfolders (5-minute TTL) with request cancellation
- Memoization for `getFieldOptions()` to prevent redundant parsing
- File access permission checks on all file metadata API endpoints

### Changed
- **Major Architecture Refactoring**: Removed global metadata system, now exclusively uses groupfolder-scoped metadata
- Modernized PHP codebase to PHP 8.x standards:
  - Added `declare(strict_types=1)` to all PHP files
  - Implemented constructor property promotion with `readonly` properties
  - Replaced `strpos()` with `str_contains()` for string checks
  - Replaced `error_log()` and `file_put_contents()` with PSR-3 `LoggerInterface`
  - Replaced deprecated `execute()` with `executeQuery()`/`executeStatement()`
  - Added proper return type declarations to all methods
- Refactored event listeners to use dependency injection for `IJobList` and `LoggerInterface`
- Improved code organization with proper use statements and class imports
- **Vue Component Optimizations**:
  - Fixed v-for keys using index anti-pattern in `GroupfolderMetadataFields.vue` and `FileMetadataFields.vue`
  - Added proper `required` attribute binding to `SelectFieldInput.vue` and `CheckboxFieldInput.vue`
  - Removed debug console.log statements from all Vue components

### Removed
- Global metadata tables (`metavox_fields`, `metavox_metadata`)
- Field override system (`metavox_gf_overrides`)
- License/subscription model
- Filter functionality
- Retention manager
- Hardcoded log file paths
- Performance test commands from app registration

### Fixed
- Database error "Table 'nextcloud.oc_metavox_fields' doesn't exist" after migration
- Updated all services to use `metavox_gf_fields` and `metavox_file_gf_meta` tables
- Cleaned up orphaned test data (4776 test groupfolders removed)
- Unified search icon visibility in light theme (changed SVG fill from `currentColor` to `#1a1a1a`)
- Fixed "UpdateSearchIndex called without file_id" warning by correcting background job registration
- Fixed 404 error when editing fields in admin panel (incorrect API URL)

### Security
- Removed hardcoded absolute paths for logging
- Improved input validation in background jobs
- API endpoints now verify user has file access before allowing metadata read/write operations
- Admin-only endpoints (`updateField`, `deleteField`, `createGroupfolderField`, etc.) no longer allow non-admin access

---

## [1.2.0] - 2025-12-10

### Fixed
- Fixed database table prefix retrieval for NC32+ compatibility in MySQL FULLTEXT index migration

---

## [1.1.3] - 2025-10-01

### Added
- Support for NextCloud 32.

### Fixed
- Resolved an issue where values could not be selected in the multi-select component when spaces were present.
- Resolved an issue in the external API that prevented retrieving fields associated with a group folder.

---

## [1.1.2] - 2025-09-21

### Added
- Possibility to connect externally with the API

---

## [1.1.1] - 2025-09-18

### Fixed
- Fixed an issue where it was not possible to add columns.

---

## [1.1.0] - 2025-09-18
### Added
- Native Nextcloud controls for improved integration and consistency with the Nextcloud design system

### Changed
- Refactored UI components to use the Nextcloud design language
- Improved stability and maintainability

### Fixed
- Minor bugs and performance issues
- Not able to edit text in File metadata text field

---

## [1.0.6] - 2025-09-07
### Added
- Initial public release of Metavox for Nextcloud
  
---

## [1.0.5] - 2025-09-04
### Added
- Initial public release of Metavox for Nextcloud
