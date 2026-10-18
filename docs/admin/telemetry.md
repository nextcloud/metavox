# Telemetry

MetaVox includes optional usage reporting to help improve the application. It holds counts and settings, no metadata, file names or user names; with a subscription key, the key goes along. Telemetry is **enabled by default** and can be disabled by administrators.

## What Is Collected

Once a day, a background job sends a report to the VoxCloud license server (`licenses.voxcloud.nl`). It contains counts, versions and basic server configuration — no file names, user names, or file content. These are all the fields of the report:

| Data | Keys | Description |
|------|------|-------------|
| App | `appType` | Always `metavox` |
| Instance identifier | `instanceHash` | SHA-256 hash of the instance URL (`overwrite.cli.url`, or else the first trusted domain), never the URL itself |
| Number of fields | `totalFields`, `fieldTypeCounts` | Total metadata field definitions, and the count per field type |
| Number of team folders | `totalGroupfolders`, `groupfoldersWithFields`, `groupfoldersWithMetadata` | All team folders, team folders with MetaVox fields, and team folders with metadata |
| Number of metadata entries | `totalMetadataEntries`, `metadataEntriesPerGroupfolder`, `filesWithMetadata` | Total stored metadata entries (of team folders and files), the count per team folder (by team folder ID), and the number of files with metadata |
| Number of users | `totalUsers`, `activeUsers30d`, `disabledUsers` | Total users, users who logged in during the last 30 days, and disabled users |
| Versions | `metavoxVersion`, `nextcloudVersion`, `phpVersion` | MetaVox, Nextcloud and PHP versions |
| Server configuration | `databaseType`, `osFamily`, `webServer`, `defaultLanguage`, `defaultTimezone`, `isDocker` | Database type, operating system family, web server (empty when system cron sends the report), default language, default timezone, whether it runs in Docker |
| Country | `countryCode` | The country code from `default_phone_region`, if set |
| Enterprise subscription | `hasValidSubscription`, `hasExtendedSupport` | Whether Nextcloud reports an Enterprise subscription, and whether it includes Extended Support (two yes/no values) |
| Subscription key | `licenseKey` | Your MetaVox subscription key, if you entered one. It lets the server check the Enterprise claim. Empty without a key |

The **Support** pane sums this up under **Exactly what is and is not sent**. That list is shorter: it leaves out the disabled users, the count per team folder, the number of files with metadata and the subscription key, and shows the two subscription values as one. The table above is complete.

## Disabling Telemetry

### Via Admin Settings

1. Go to **Settings** > **Administration** > **MetaVox**
2. Open the **Support** pane and find **Anonymous usage statistics**
3. Turn off **Share anonymous usage statistics**

While it is on, **Send report now** sends a report straight away.

### Via occ

```bash
sudo -u www-data php occ config:app:set metavox telemetry_enabled --value=false
```

### Via API

Use an app password of an administrator and send the header `OCS-APIRequest: true`. With that header Nextcloud accepts the request without a CSRF token.

```bash
curl -X POST "https://your-nextcloud.com/index.php/apps/metavox/api/telemetry/settings" \
  -u "admin:app-password" \
  -H "OCS-APIRequest: true" \
  -H "Content-Type: application/json" \
  -d '{"enabled": false}'
```

## Telemetry API Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/apps/metavox/api/telemetry/status` | GET | Check if telemetry is enabled |
| `/apps/metavox/api/telemetry/stats` | GET | View the field and metadata counts locally (a part of the report) |
| `/apps/metavox/api/telemetry/send` | POST | Manually trigger a telemetry report |
| `/apps/metavox/api/telemetry/settings` | POST | Enable or disable telemetry (`{"enabled": true}` or `false`) |

## Privacy Notes

- Telemetry is **opt-out** (enabled by default, can be disabled)
- No personally identifiable information is sent. If you entered a subscription key, the report carries it, which links the report to your subscription
- No file names, user names, or metadata values are transmitted
- Only counts, versions and basic server configuration are reported
- Telemetry can be fully disabled without affecting MetaVox functionality
- When disabled, no telemetry is sent. With a subscription key, the daily license check still contacts the license server, see [Support & Subscriptions](licensing.md#what-a-subscription-key-sends)

## See Also

- [Privacy & Security](../architecture/privacy.md) - Data privacy overview
- [Installation](installation.md) - Initial setup
- [Settings](settings.md) - Admin settings
