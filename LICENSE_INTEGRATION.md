# MetaVox License Server Integration

This document explains how to integrate MetaVox with the license server to enforce team folder limits.

## Prerequisites

1. **License Server Running**: You need a running instance of the MetaVox License Server
2. **License Key**: A valid license key generated from the license server admin panel
3. **Admin Access**: Access to your Nextcloud `config/config.php` file

## Configuration

### Step 1: Get Your License Key

1. Open the License Server admin panel (e.g., `https://metalicense.hvanextcloudpoc.src.surf-hosted.nl`)
2. Login with your admin credentials
3. Navigate to the **Licenses** tab
4. Create a new license with your desired team folder limit
5. Copy the generated license key (format: `MVOX-XXXX-XXXX-XXXX-XXXX`)

### Step 2: Configure MetaVox

Edit your Nextcloud `config/config.php` and add the following lines:

```php
<?php
$CONFIG = array(
    // ... other config ...

    // MetaVox License Configuration
    'metavox_license_key' => 'MVOX-XXXX-XXXX-XXXX-XXXX',  // Your license key
    'metavox_license_server' => 'https://metalicense.hvanextcloudpoc.src.surf-hosted.nl',  // License server URL

    // ... rest of config ...
);
```

**Important**: Replace `MVOX-XXXX-XXXX-XXXX-XXXX` with your actual license key!

### Step 3: Verify Installation

1. Navigate to MetaVox admin settings: **Settings** → **Administration** → **MetaVox**
2. Click on the **License** tab
3. You should see:
   - ✅ **License Active** status
   - Your license type (Standard/Enterprise/Trial)
   - Current team folder usage vs. limit
   - License validity period

## Features

### Automatic Usage Reporting

MetaVox automatically reports usage statistics to the license server:

- **What is tracked**: Number of team folders and users
- **Update frequency**: Every hour via background job
- **Privacy**: Only aggregate numbers are sent, no file names or user data

### License Information Dashboard

The License tab in MetaVox admin shows:

- License status (Active/Inactive/Expired)
- License type and validity period
- Real-time usage statistics
- Progress bars showing current usage vs. limits
- Warnings when limits are exceeded

### Team Folder Limit Enforcement

**Note**: The current implementation provides monitoring and warnings but does **not block** team folder creation in the Nextcloud Groupfolders app.

**Why?** Team folders are created through the native Nextcloud Groupfolders app, which MetaVox cannot directly control.

**Future Enhancement**: To enforce limits, you would need to:
1. Modify the Groupfolders app to check with MetaVox before creating folders, OR
2. Implement a webhook/event listener that prevents folder creation when limit is reached

**Current Behavior**:
- License status and limits are displayed in MetaVox admin
- Warnings are shown when limits are exceeded
- Usage is continuously monitored and reported

## API Endpoints

MetaVox exposes these license-related endpoints:

### Get License Info
```bash
GET /apps/metavox/api/license/info
```

Returns current license status, limits, and usage.

### Check Team Folder Limit
```bash
GET /apps/metavox/api/license/check-limit
```

Returns whether creating a new team folder is allowed.

### Get License Config
```bash
GET /apps/metavox/api/license/config
```

Returns license configuration (admin only).

## Troubleshooting

### License shows as "Invalid"

**Check**:
1. License key is correctly entered in `config.php`
2. License server URL is accessible from your Nextcloud server
3. License is active in the license server admin panel
4. License hasn't expired

**Test connection**:
```bash
curl -X POST https://metalicense.hvanextcloudpoc.src.surf-hosted.nl/api/licenses/validate \
  -H "Content-Type: application/json" \
  -d '{"licenseKey": "MVOX-XXXX-XXXX-XXXX-XXXX", "instanceUrl": "https://your-nextcloud.com"}'
```

### License shows as "Not Configured"

**Solution**: Add the license configuration to `config/config.php` as described in Step 2.

### Usage not updating

**Check**:
1. Background jobs are running: `php occ background:cron` or configure cron job
2. Check logs: `tail -f /var/www/nextcloud/data/nextcloud.log | grep -i license`
3. Manually trigger update: `php occ background-job:execute '\\OCA\\MetaVox\\BackgroundJobs\\UpdateLicenseUsage'`

### License limit exceeded but folders still being created

**Expected behavior**: The current version monitors and warns but doesn't prevent folder creation. See "Team Folder Limit Enforcement" section above for details.

## Security Notes

1. **License Key Security**: The license key is stored in `config.php` - ensure this file has proper permissions (readable only by web server user)
2. **HTTPS Required**: Always use HTTPS for the license server URL in production
3. **No Data Sent**: Only aggregate statistics (counts) are sent to the license server - no file names, user names, or file content

## Support

For issues with:
- **License Server**: Contact your license provider or check the license server logs
- **MetaVox Integration**: Check Nextcloud logs and MetaVox admin panel
- **Configuration**: Review this document and verify all settings in `config.php`

## License Management

To manage your licenses:
1. Access the license server admin panel
2. View usage statistics per instance
3. Create, update, or deactivate licenses
4. Monitor expiration dates
5. Upgrade license limits as needed

---

**Version**: 1.0.0
**Last Updated**: October 2025
