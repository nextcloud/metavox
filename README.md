# MetaVox - Metadata for Nextcloud

**MetaVox** adds structured metadata to documents in Nextcloud Team folders, making them easier to organize, classify, and retrieve.

Developed by the University of Amsterdam and Amsterdam University of Applied Sciences. Originally built for education, now broadly applicable across government, non-profit, and enterprise sectors.

![MetaVox interface](screenshots/MetaVox%20v1.0.0.png)

## Features

- **Structured Metadata** - Add custom fields (text, dropdown, date, checkbox, etc.) to documents
- **Team Folder Integration** - Metadata scoped per Team folder with inheritance
- **Compliance Templates** - Ready-to-use templates for GDPR, WOO, and Archives Act
- **Flow Integration** - Automate workflows based on metadata conditions
- **Batch API** - Programmatic access for migrations and integrations
- **Privacy-First** - All data stays on-premise, no external dependencies

## Quick Start

1. Install MetaVox from the Nextcloud App Store
2. Go to **Settings** > **MetaVox**
3. Select a Team folder and define metadata fields
4. Users can now add metadata via the file sidebar

## Documentation

| Audience | Guide |
|----------|-------|
| **Users** | [User Guide](docs/user/overview.md) - Working with metadata |
| **Administrators** | [Installation](docs/admin/installation.md) - Setup and configuration |
| **Architects** | [Architecture](docs/architecture/overview.md) - Technical overview |

**Quick links:**
- [Getting Started](docs/getting-started.md)
- [API Reference](docs/architecture/api-reference.md)
- [Compliance Templates](docs/admin/compliance-templates.md)
- [Privacy & Security](docs/architecture/privacy.md)

## Screenshots

| File Metadata | Team Folder Settings |
|---------------|---------------------|
| ![File metadata](screenshots/File%20metadata.png) | ![Team folder settings](screenshots/Manage%20team%20metadata.png) |

## Resources

- [Changelog](CHANGELOG.md)
- [Roadmap](docs/roadmap.md)
- [GitHub Issues](https://github.com/nextcloud/metavox/issues)

## Authors

Initial version created by Sam Ditmeijer and Rik Dekker.

See [AUTHORS.md](AUTHORS.md) for full credits.

## License

[GNU Affero General Public License v3 (AGPLv3)](https://www.gnu.org/licenses/agpl-3.0.html)
