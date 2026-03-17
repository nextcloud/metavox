# Licensing

MetaVox supports optional license management to monitor and control team folder usage across your Nextcloud instance.

## Prerequisites

1. A running MetaVox License Server instance
2. A valid license key (format: `MVOX-XXXX-XXXX-XXXX-XXXX`)
3. Administrator access to your Nextcloud `config/config.php`

## Configuration

### Step 1: Get Your License Key

1. Open the License Server admin panel
2. Log in with your admin credentials
3. Navigate to the **Licenses** tab
4. Create a new license with your desired team folder limit
5. Copy the generated license key

### Step 2: Configure Nextcloud

Edit your Nextcloud `config/config.php` and add:

```php
'metavox_license_key' => 'MVOX-XXXX-XXXX-XXXX-XXXX',
'metavox_license_server' => 'https://your-license-server.example.com',
```

Replace the values with your actual license key and server URL.

### Step 3: Verify

1. Go to **Settings** → **Administration** → **MetaVox**
2. Open the **License** tab
3. You should see:
   - **License Active** status
   - License type (Standard / Enterprise / Trial)
   - Current team folder usage vs. limit
   - License validity period

## License Dashboard

The License tab in MetaVox admin settings shows:

| Information | Description |
|-------------|-------------|
| License status | Active, Inactive, or Expired |
| License type | Standard, Enterprise, or Trial |
| Validity period | Start and end date of the license |
| Usage statistics | Current team folder and user counts |
| Progress bars | Visual representation of usage vs. limits |

Warnings are displayed when usage approaches or exceeds limits.

## Automatic Usage Reporting

MetaVox reports aggregate usage to the license server:

- **What is tracked**: Number of team folders and users (no file names or user data)
- **Frequency**: Every hour via background job
- **Privacy**: Only aggregate numbers are transmitted

## API Endpoints

| Endpoint | Description |
|----------|-------------|
| `GET /apps/metavox/api/license/info` | Current license status, limits, and usage |
| `GET /apps/metavox/api/license/check-limit` | Whether creating a new team folder is allowed |
| `GET /apps/metavox/api/license/config` | License configuration (admin only) |

## Troubleshooting

### License shows as "Invalid"

1. Verify the license key in `config.php` is correct
2. Check that the license server URL is reachable from your Nextcloud server
3. Confirm the license is active in the license server admin panel
4. Check that the license hasn't expired

**Test the connection:**
```bash
curl -X POST https://your-license-server.example.com/api/licenses/validate \
  -H "Content-Type: application/json" \
  -d '{"licenseKey": "MVOX-XXXX-XXXX-XXXX-XXXX", "instanceUrl": "https://your-nextcloud.com"}'
```

### License shows as "Not Configured"

Add the license configuration to `config/config.php` as described in Step 2.

### Usage not updating

1. Verify background jobs are running: `php occ background:cron`
2. Check logs: `tail -f data/nextcloud.log | grep -i license`
3. Manually trigger: `php occ background-job:execute '\OCA\MetaVox\BackgroundJobs\UpdateLicenseUsage'`

## Security Notes

- Store the license key in `config.php` with proper file permissions (readable only by the web server user)
- Always use HTTPS for the license server URL
- Only aggregate statistics are sent — no file names, user names, or file content

## See Also

- [Installation](installation.md) - Initial setup
- [Permissions](permissions.md) - Access control
- [Architecture Overview](../architecture/overview.md) - System design
