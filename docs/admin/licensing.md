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

MetaVox subscriptions are sold through Nextcloud. For subscriptions and pricing, contact your Nextcloud account manager or [sales@nextcloud.com](mailto:sales@nextcloud.com).

For questions about the app itself, contact [info@voxcloud.nl](mailto:info@voxcloud.nl).

## Subscription Banner

Without a subscription key, the MetaVox admin page can show a blue informational banner at the top. It shows one message at most:

- When Nextcloud reports an Enterprise subscription on this instance: "Nextcloud Enterprise subscription detected on this instance. MetaVox subscriptions are sold through Nextcloud — contact your Nextcloud account manager or sales@nextcloud.com."
- Otherwise, when the instance has more than 100 users: "MetaVox is running for {count} users here and keeps working in full without a subscription. …", pointing to your account manager or sales@nextcloud.com

The Enterprise message wins when both apply. With 100 users or fewer and no Enterprise subscription, there is no banner. This is a friendly reminder, not a warning — all features continue to work normally.

When a subscription key is entered but is invalid or expired, the banner says "Your MetaVox subscription key needs attention." instead, with a **Visit Support** link.

The banner disappears when a subscription key is entered. It cannot be dismissed.

The banner is only visible to administrators, never to regular users.

## Entering a Subscription Key

1. Go to **Settings** > **Administration** > **MetaVox**
2. Open the **Support** pane
3. Enter your subscription key in the **Subscription key** field (format: `MVOX-XXXX-XXXX-XXXX-XXXX`)
4. Click **Save & activate**

MetaVox saves the key, validates it with the VoxCloud license server, and reports its usage there to bind it to this instance. On success, you will see "Subscription activated!" and the banner will disappear. If validation fails, the key is saved and the message gives the reason.

MetaVox never asks for an organization name or contact email.

## Removing a Subscription Key

If your subscription expires and you choose not to renew, you can remove the key:

1. Go to **Settings** > **Administration** > **MetaVox** > **Support**
2. Click **Remove subscription key**
3. The key is removed and you return to the free usage state

## What a Subscription Key Sends

With a subscription key, a daily background job validates the key and reports usage to the VoxCloud license server (`licenses.voxcloud.nl`). This is separate from [telemetry](telemetry.md) and runs even when anonymous usage statistics are off. It sends:

- The subscription key and the hashed instance URL
- The number of Team folders with metadata fields, metadata entries, users and disabled users
- An organization name, only if an older MetaVox version saved one

Without a subscription key, this job sends nothing.

## Single-Instance Binding

Each subscription key is bound to one Nextcloud instance. When you save and activate a key, it is bound via a SHA-256 hash of your instance URL: `overwrite.cli.url` from `config.php`, or else the first trusted domain. The URL itself is never sent. If you need to move your subscription to a new instance (e.g. server migration), contact [info@voxcloud.nl](mailto:info@voxcloud.nl).

## Offline / Server Unreachable

If the VoxCloud license server is unreachable during validation, MetaVox falls back to the last known validation result: a key that was valid stays valid. MetaVox never blocks functionality due to connectivity issues.

## See Also

- [Installation](installation.md) - Requirements and setup
- [Settings](settings.md) - AI and telemetry settings
- [Telemetry](telemetry.md) - Anonymous usage reporting
