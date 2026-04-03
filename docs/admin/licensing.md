# Licensing

MetaVox is free and open source (AGPL-3.0). All features are always available regardless of license status — this is a trust-based model. A license removes the free tier volume limits and includes support from VoxCloud.

## Free Tier

Without a license, MetaVox is fully functional with these limits:

| Metric | Free tier limit |
|--------|----------------|
| Team folders with metadata | 5 |
| Metadata entries per team folder | 500 |

All features (inline editing, views, AI autofill, Flow integration, backup/restore, REST API) are available in the free tier. Nothing is disabled.

## License Tiers

| | Standard | Enterprise |
|---|---|---|
| **Price** | €1,500/year | €3,500/year |
| All features | ✓ | ✓ |
| Team folders | 25 | Unlimited |
| Entries per folder | Unlimited | Unlimited |
| Nextcloud instances | 1 | 1 |
| Support | Email, 3 business days | Priority, 1 business day |
| SLA | — | ✓ |
| Dedicated contact | — | ✓ |
| Onboarding assistance | — | ✓ |

Education and government: 25% discount on all tiers.

Organizations with multiple Nextcloud instances need a separate license per instance.

## Entering a License Key

1. Go to **Settings** > **Administration** > **MetaVox**
2. Open the **Statistics** tab
3. Scroll to **Support & Licensing**
4. Enter your license key in the **License key** field (format: `MVOX-XXXX-XXXX-XXXX-XXXX`)
5. Click **Save & validate**

On success, you will see "License saved and validated!" and the usage warning will be replaced by "License active — unlimited usage."

## Organization Details

In the same Support & Licensing section, you can optionally fill in your organization name and contact email. These are sent with your usage statistics so VoxCloud can reach you if needed. They are never shared with third parties.

## Single-Instance Binding

Each license key is bound to exactly 1 Nextcloud instance. When you save and validate a key, it is bound to your instance via a SHA-256 hash of your instance URL. If the same key is used on a different instance, validation will fail.

If you need to move your license to a new instance (e.g. server migration), contact info@voxcloud.nl to reset the binding.

## Usage Reporting

When a license is active, MetaVox reports usage statistics to VoxCloud once every 24 hours via a background job:

- Number of team folders with metadata
- Total metadata entries
- Number of users

This is separate from the anonymous telemetry (which runs independently and can be disabled). Usage reporting only runs when a license key is configured.

## License Status in Admin Settings

The Statistics tab shows your current usage with progress bars:

- **Team folders with metadata**: X / 5 (free tier) or unlimited (licensed)
- **Total metadata entries**: count displayed

Color coding:
- **Blue**: within limits
- **Yellow**: approaching 80% of limit
- **Red**: exceeded limit

## What Happens When Limits Are Exceeded

Nothing breaks. MetaVox continues to work normally. A warning banner appears in the admin settings suggesting a license. This banner is only visible to administrators, never to regular users.

## Offline / Server Unreachable

If the VoxCloud license server is unreachable during validation, MetaVox falls back to the last known validation result. If the license was previously valid, it continues to be treated as valid. MetaVox never blocks functionality due to connectivity issues.

## Purchasing a License

Visit [voxcloud.nl](https://voxcloud.nl/#pricing) for pricing, or contact [info@voxcloud.nl](mailto:info@voxcloud.nl).
