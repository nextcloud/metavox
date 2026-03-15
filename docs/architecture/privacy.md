# MetaVox Privacy & Security

This document describes how MetaVox handles data privacy and security, helping organizations evaluate compliance with GDPR and other data protection requirements.

## Privacy by Design

MetaVox is built with privacy as a core principle:

### On-Premise Only

- **No external services**: MetaVox does not connect to any external servers
- **No telemetry**: No usage data is collected or transmitted
- **No cloud dependencies**: Works entirely within your Nextcloud instance
- **Air-gapped compatible**: Functions without internet connectivity

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
│  │  │  - No external calls     │  │  │
│  │  │  - No API requests out   │  │  │
│  │  │  - No tracking           │  │  │
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

## Data Storage

### What MetaVox Stores

| Data Type | Storage Location | Purpose |
|-----------|------------------|---------|
| Field definitions | `metavox_gf_fields` table | Schema for metadata fields |
| Metadata values | `metavox_file_gf_meta` table | Actual metadata values per document |

### What MetaVox Does NOT Store

- File contents (only metadata about files)
- User credentials
- External references
- Analytics or tracking data

### Data Relationship to Files

Metadata is stored by file ID reference, not embedded in files:
- Original files remain unchanged
- Metadata can be deleted without affecting files
- Files can be deleted (metadata is orphaned but not automatically removed)

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
- Read access: View metadata
- Write access: Edit document metadata
- Admin access: Configure field definitions

### Input Validation

All user input is validated:
- Field types enforce data format (date, number, etc.)
- Options are validated against defined choices
- SQL injection prevented via parameterized queries

### API Security

- OCS-APIRequest header required
- Rate limiting via Nextcloud
- Authentication required for all endpoints

## Audit Considerations

### What Can Be Audited

- Metadata field definitions (who created, when)
- Metadata value changes (via Nextcloud activity log if configured)
- API access (via Nextcloud logs)

### Recommendations

1. Enable Nextcloud activity logging
2. Configure log retention per your requirements
3. Regular backup of database (includes metadata)

## Data Retention

### Automatic Cleanup

MetaVox does not automatically delete metadata. When files are deleted:
- Metadata remains in database (orphaned)
- Manual cleanup may be needed for large-scale deletions

### Manual Cleanup

Administrators can:
- Delete individual metadata via UI
- Bulk delete via API
- Clear all metadata for a Team folder

## Encryption

### At Rest

Metadata is stored in Nextcloud's database:
- Use database encryption if required
- Nextcloud server-side encryption does not apply to metadata (only file contents)

### In Transit

- HTTPS encryption for all web traffic
- API calls use same HTTPS protection

## Third-Party Dependencies

MetaVox has no runtime dependencies outside Nextcloud:

| Dependency | Type | Purpose |
|------------|------|---------|
| Nextcloud Server | Required | Platform |
| Group Folders | Required | Team folder support |
| Vue.js | Bundled | Frontend framework |
| PHP libraries | Bundled | Backend functionality |

No external API calls, no CDN resources, no external fonts or scripts.

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
