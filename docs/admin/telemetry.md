# Telemetry

MetaVox includes optional, anonymous usage reporting to help improve the application. Telemetry is **enabled by default** and can be disabled by administrators.

## What Is Collected

Only aggregate, anonymous statistics are sent — no file names, user names, or file content:

| Data | Description |
|------|-------------|
| Number of team folders | Count of groupfolders with MetaVox fields |
| Number of users | Count of active users |
| Number of fields | Total metadata field definitions |
| Number of metadata values | Total stored metadata entries |
| Nextcloud version | Server version for compatibility tracking |
| MetaVox version | App version |

## Disabling Telemetry

### Via Admin Settings

1. Go to **Settings** > **Administration** > **MetaVox**
2. Find the **Telemetry** section
3. Toggle telemetry off

### Via API

```bash
curl -X POST "https://your-nextcloud.com/apps/metavox/api/telemetry/settings" \
  -H "Content-Type: application/json" \
  -b "session-cookie" \
  -d '{"telemetry_enabled": false}'
```

## Telemetry API Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/apps/metavox/api/telemetry/status` | GET | Check if telemetry is enabled |
| `/apps/metavox/api/telemetry/stats` | GET | View collected statistics locally |
| `/apps/metavox/api/telemetry/send` | POST | Manually trigger a telemetry report |
| `/apps/metavox/api/telemetry/settings` | POST | Enable or disable telemetry |

## Privacy Notes

- Telemetry is **opt-out** (enabled by default, can be disabled)
- No personally identifiable information is sent
- No file names, user names, or metadata values are transmitted
- Only aggregate counts are reported
- Telemetry can be fully disabled without affecting MetaVox functionality
- When disabled, no data is sent and no external connections are made

## See Also

- [Privacy & Security](../architecture/privacy.md) - Data privacy overview
- [Installation](installation.md) - Initial setup
- [Settings](settings.md) - Admin settings
