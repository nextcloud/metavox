# MetaVox Privacy & Security

This document describes how MetaVox handles data privacy and security, helping organizations evaluate compliance with GDPR and other data protection requirements.

## Privacy by Design

MetaVox is built with privacy as a core principle:

### On-Premise Data Storage

- **Metadata stays local**: All metadata is stored in your Nextcloud database — no metadata leaves your infrastructure
- **No cloud dependencies**: Core functionality works entirely within your Nextcloud instance
- **AI autofill uses your Nextcloud AI provider**: file content goes to whichever Task Processing provider the administrator configured, which may be external. See [AI Autofill](../features/ai-autofill.md)
- **Telemetry, on by default**: once a day MetaVox sends usage statistics to the VoxCloud license server. An administrator can turn it off. See [Telemetry](../admin/telemetry.md) for details
- **Subscription check**: only with a subscription key, MetaVox validates the key and reports usage to the same server once a day, also when telemetry is off. See [Support & Subscriptions](../admin/licensing.md#what-a-subscription-key-sends)
- **Air-gapped compatible**: turn telemetry off, leave the subscription key empty, and turn AI off or use an AI provider inside your network. See [External Connections](#external-connections)

### Data Locality

All data stays within your infrastructure:

```
┌─────────────────────────────────────┐
│         Your Infrastructure          │
│                                      │
│  ┌────────────────────────────────┐  │
│  │        Nextcloud Server        │  │
│  │  ┌──────────────────────────┐  │  │
│  │  │      MetaVox App         │  │  │
│  │  │  - Metadata stays local  │  │  │
│  │  │  - Optional telemetry    │  │  │
│  │  └──────────────────────────┘  │  │
│  │              │                  │  │
│  │  ┌───────────▼──────────────┐  │  │
│  │  │   Your Database Server   │  │  │
│  │  │   - Metadata stored here │  │  │
│  │  │   - Your control         │  │  │
│  │  └──────────────────────────┘  │  │
│  └────────────────────────────────┘  │
└─────────────────────────────────────┘
```

> **Note**: When telemetry is enabled (default), MetaVox sends aggregate statistics (user count, field count, version numbers) to an external server. No metadata values, file names, or user names are transmitted. If you entered a subscription key, the report also carries that key. Telemetry can be fully disabled in admin settings. See [Telemetry](../admin/telemetry.md).

## Data Storage

### What MetaVox Stores

| Data Type | Storage Location | Purpose |
|-----------|------------------|---------|
| Field definitions | `metavox_gf_fields` table | Schema for metadata fields |
| Field assignments | `metavox_gf_assigns` table | Which fields each team folder uses |
| Metadata values | `metavox_file_gf_meta` table | Actual metadata values per document, with creation and last-change time |
| Team folder values | `metavox_gf_metadata` table | Metadata of the team folder itself |
| Search words | `metavox_search_words` table | Words from text values, per team folder, for free-text search |
| Views | `metavox_gf_views` table | Saved views per team folder |
| Manager grants | `metavox_permissions` table | Users and groups allowed to manage a team folder's metadata |
| Saved searches | Nextcloud user preferences (app `metavox`, key `saved_searches`) | Each user's own saved searches (name, field, value, team folder), shown as folders in Files; at most 50 per user. Nobody else sees them |
| Subscription key and settings | Nextcloud app config (app `metavox`) | The subscription key, if entered, and the admin settings |
| Daily backups | App data (`appdata_*/metavox/backups/`) | Gzipped copy of fields, team folder values and file values; the last 7 are kept |
| Presence and cell locks | Nextcloud distributed cache | User ids of who has a team folder open (minutes to hours) and who edits a cell (30 seconds) |

### What MetaVox Does NOT Store

- File contents (only metadata about files)
- User credentials
- External references
- Analytics or tracking data (telemetry only sends aggregate counts, not individual data)

### Data Relationship to Files

Metadata is stored by file ID reference, not embedded in files:
- Original files remain unchanged
- Metadata can be deleted without affecting files
- When a file is deleted for good, its metadata is removed automatically (see [Data Retention](#data-retention))

## GDPR Compliance

MetaVox supports GDPR compliance in several ways:

### Enabling Compliance

- **Document Classification**: Track which documents contain personal data
- **Processing Grounds**: Record legal basis for data processing
- **Retention Periods**: Define and track data retention requirements
- **Accountability**: Demonstrate compliance through metadata

### Compliance Templates

Pre-built templates help organizations meet requirements:
- `avg-compliance.json` - GDPR/AVG fields
- `woo-compliance.json` - Open Government Act (Dutch)
- `archiefwet-compliance.json` - Archives Act (Dutch)

See [Compliance Templates](../admin/compliance-templates.md) for details.

### Data Subject Rights

MetaVox metadata can help fulfill data subject requests:

| Right | How MetaVox Helps |
|-------|-------------------|
| Right to access | Find documents containing personal data |
| Right to erasure | Identify documents for deletion |
| Right to rectification | Locate documents needing updates |

## Security Model

### Authentication

MetaVox uses Nextcloud's authentication:
- Session-based authentication (web interface)
- App passwords (API access)
- Two-factor authentication (if enabled in Nextcloud)

### Authorization

Permissions are inherited from Nextcloud:
- Read access to the file (team folder ACL included): View metadata
- Update access to the file: Edit document metadata
- Membership of the team folder: See its views and fields
- Nextcloud admin: Create, change and delete field definitions
- Admins and managers of a team folder: Assign fields and set default values

### Input Validation

All user input is validated:
- Field types enforce data format (date, number, etc.)
- Options are validated against defined choices
- SQL injection prevented via parameterized queries

### API Security

- OCS endpoints require the `OCS-APIRequest` header; most web routes require Nextcloud's CSRF token
- Authentication required for all endpoints
- Every route declares which permission it needs, checked before the request is handled; a route without one is refused
- MetaVox sets no rate limits of its own

## Audit Considerations

### What Can Be Audited

- Metadata field definitions (when created and last changed)
- Metadata values (when created and last changed; not by whom)
- API access (via Nextcloud logs)

MetaVox does not write metadata changes to Nextcloud's activity stream or audit
log.

### Recommendations

1. Enable Nextcloud activity logging
2. Configure log retention per your requirements
3. Regular backup of database (includes metadata)

## Data Retention

### Automatic Cleanup

Metadata of deleted files is removed in two ways:

- **Right away**: when a team folder file leaves Nextcloud's file cache, its
  values and search words are deleted at the end of that request. This happens
  when the file is deleted for good (trash emptied or expired) and when it moves
  to another storage, such as another team folder or personal files. Moving a
  file to the trash keeps its metadata, so a restored file keeps it too. Files
  outside team folders are ignored.
- **Daily**: a background job (`CleanupDeletedMetadata`) removes the values and
  search words of files that no longer exist, up to 10,000 files per run. It
  catches what the immediate cleanup missed.

Deleting a field removes its values in every team folder; MetaVox only allows
that once no team folder uses the field.

Removed metadata stays in the daily backups until they are rotated out (7
backups are kept). See [Backup & Restore](../admin/backup-restore.md).

### Manual Cleanup

Administrators can:
- Delete individual metadata via UI
- Clear the metadata of selected files (bulk editing)
- Bulk delete via API (`batch-delete`)

## Encryption

### At Rest

Metadata is stored in Nextcloud's database:
- Use database encryption if required
- Nextcloud server-side encryption does not apply to metadata (only file contents)

### In Transit

- HTTPS encryption for all web traffic
- API calls use same HTTPS protection

## Third-Party Dependencies

MetaVox has minimal runtime dependencies:

| Dependency | Type | Purpose |
|------------|------|---------|
| Nextcloud Server | Required | Platform |
| Group Folders | Required | Team folder support |
| notify_push | Optional | Real-time sync and cell locking |
| Redis | Optional | Caching, presence tracking, cell locking |
| User status | Optional | Heartbeat that keeps presence up to date |
| Nextcloud AI (Task Processing) | Optional | AI autofill |
| Vue.js | Bundled | Frontend framework |
| PHP libraries | Bundled | Backend functionality |

No CDN resources, no external fonts or scripts.

## External Connections

MetaVox connects to a server outside your Nextcloud in these cases only:

| Connection | When | To | What is sent |
|------------|------|----|--------------|
| Telemetry | Once a day while **Share anonymous usage statistics** is on, which it is **by default**; also when an admin clicks **Send report now** | VoxCloud license server (`licenses.voxcloud.nl`) | Counts, versions and server configuration, plus the subscription key if you entered one. See [Telemetry](../admin/telemetry.md) |
| Subscription check | Once a day, and when an admin saves a key. Only with a subscription key, whether telemetry is on or off | VoxCloud license server (`licenses.voxcloud.nl`) | The key, the hashed instance URL and usage counts. See [Support & Subscriptions](../admin/licensing.md#what-a-subscription-key-sends) |
| AI autofill | When a user clicks **Generate with AI** | The AI provider Nextcloud is configured with. MetaVox hands the task to Nextcloud; the provider may be external | The fields to fill and the file's name and text; for a file whose text can't be read, its name, type, size and path. See [AI Autofill](../features/ai-autofill.md) |

With telemetry off, no subscription key, and AI off or running inside your network, MetaVox makes no connections outside your infrastructure.

## Recommendations for Sensitive Environments

For high-security deployments:

1. **Network isolation**: Deploy Nextcloud on isolated network
2. **Database encryption**: Enable encryption at database level
3. **Access logging**: Enable comprehensive audit logging
4. **Regular backups**: Include database in backup strategy
5. **Access review**: Periodically review who has admin access

## See Also

- [Architecture Overview](overview.md) - Technical architecture
- [Compliance Templates](../admin/compliance-templates.md) - Pre-built schemas
- [Permissions](../admin/permissions.md) - Access control
- [Telemetry](../admin/telemetry.md) - Usage reporting settings
