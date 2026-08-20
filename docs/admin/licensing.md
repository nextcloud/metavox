# Support & Subscriptions

MetaVox is free and open source (AGPL-3.0). All features are always available — no limits, no restrictions, no catch. A subscription is optional and funds active development, guaranteed Nextcloud compatibility, and email support.

## Free Usage

MetaVox is fully functional without a subscription. There are no feature restrictions, no degraded performance, and no expiring trials. You are not in violation of anything by using MetaVox without a subscription.

## Why Subscribe?

If MetaVox is valuable to your organization, a subscription helps keep it maintained and improved.

| Included | Description |
|----------|-------------|
| Guaranteed compatibility | Tested with every new Nextcloud release |
| Email support | Direct support from the developers |
| Priority bug fixes | Your issues get priority attention |
| Active development | New features and improvements |

## Pricing

VoxCloud apps are moving to a bundled offering together with Nextcloud. The
pricing model is being finalised and is not published here.

If you would like to know what a subscription would look like for your
organization, see [voxcloud.nl/pricing](https://voxcloud.nl/pricing/) or contact
[info@voxcloud.nl](mailto:info@voxcloud.nl).

## Subscription Banner

Organizations with substantial MetaVox usage (more than 50 Nextcloud users, or more than 20 team folders with metadata fields) who do not have an active subscription will see a blue informational banner at the top of the MetaVox admin panel. This is a friendly reminder, not a warning — all features continue to work normally.

The banner disappears when:
- A valid subscription key is entered
- The admin dismisses it (reappears on next page load)

The banner is only visible to administrators, never to regular users.

## Entering a Subscription Key

1. Go to **Settings** > **Administration** > **MetaVox**
2. Open the **Support** tab
3. Optionally fill in your organization name and contact email
4. Enter your subscription key in the **Subscription key** field (format: `MVOX-XXXX-XXXX-XXXX-XXXX`)
5. Click **Save & activate**

On success, you will see "Subscription activated!" and the banner will disappear.

## Removing a Subscription Key

If your subscription expires and you choose not to renew, you can remove the key:

1. Go to **Settings** > **Administration** > **MetaVox** > **Support**
2. Click **Remove subscription key**
3. The key is removed and you return to the free usage state

## Organization Details

In the Support tab, you can optionally provide your organization name and contact email. These are sent with your anonymous usage statistics so we can reach you if needed. They are never shared with third parties.

## Single-Instance Binding

Each subscription key is bound to one Nextcloud instance. When you save and activate a key, it is bound via a SHA-256 hash of your instance URL. If you need to move your subscription to a new instance (e.g. server migration), contact [info@voxcloud.nl](mailto:info@voxcloud.nl).

## Offline / Server Unreachable

If the VoxCloud license server is unreachable during validation, MetaVox falls back to the last known validation result. MetaVox never blocks functionality due to connectivity issues.

## See Also

- [Installation](installation.md) - Requirements and setup
- [Settings](settings.md) - AI and telemetry settings
- [Telemetry](telemetry.md) - Anonymous usage reporting
