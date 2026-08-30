import { translate as t } from '@nextcloud/l10n'

/**
 * The one subscription message an unlicensed instance sees, or null when there
 * is nothing worth saying.
 *
 * Shared by the banner above the tabs and the Support tab, so the two can never
 * drift apart and say different things about the same server.
 *
 * Only one message, ever. An Enterprise instance with 400 users matches both
 * conditions, and two notices both asking the administrator to get in touch
 * reads as nagging. Enterprise wins: an organisation already paying Nextcloud
 * is a stronger signal than headcount alone.
 *
 * Nothing here is a limit. MetaVox behaves identically above and below the
 * threshold — no feature is gated, ever — so the wording leads with that. The
 * number is not arbitrary either: paid subscriptions start at 100 users in the
 * price list, so below it there is genuinely nothing to suggest.
 *
 * Both texts point at Nextcloud rather than at VoxCloud. Subscriptions are sold
 * and invoiced by Nextcloud GmbH under the ISV agreement and first-line support
 * runs through them, so a VoxCloud address would route an administrator past
 * their own account manager.
 *
 * @param {object|null} stats the payload from the licence stats endpoint
 * @return {string|null} the message, or null to show nothing
 */
export function subscriptionNudge(stats) {
	if (!stats || stats.hasLicense) {
		return null
	}

	if (stats.hasValidSubscription || stats.hasExtendedSupport) {
		return t('metavox', 'Nextcloud Enterprise subscription detected on this instance. MetaVox subscriptions are sold through Nextcloud — contact your Nextcloud account manager or sales@nextcloud.com.')
	}

	// A missing threshold means an older backend: say nothing rather than
	// guessing a number the server did not send.
	const threshold = stats.supportNudgeUserThreshold
	const users = stats.totalUsers
	if (typeof threshold !== 'number' || typeof users !== 'number' || users <= threshold) {
		return null
	}

	return t('metavox', 'MetaVox is running for {count} users here and keeps working in full without a subscription. If your organisation gets value from it, a subscription is much appreciated — it funds the maintenance. Sold through Nextcloud: contact your account manager or sales@nextcloud.com.', { count: users })
}
