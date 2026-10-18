/**
 * MetaVox Presence — pushes go to whoever has the grid of a team folder open.
 * Arriving needs no call (listing the folder registers you) and staying needs
 * none either (Nextcloud's own status heartbeat keeps it alive). Leaving does:
 * when the grid switches team folder, and when the tab closes.
 */

import { generateUrl } from '@nextcloud/router'

let current = null

export function leavePresence(gfId) {
	if (!gfId) return
	const data = new FormData()
	data.append('gf_id', gfId)
	data.append('requesttoken', document.querySelector('head[data-requesttoken]')?.dataset?.requesttoken || '')
	navigator.sendBeacon(generateUrl('/apps/metavox/api/presence/leave'), data)
}

/** The grid now shows this team folder, or none: leave the one before. */
export function showingTeamFolder(gfId) {
	const next = gfId || null
	if (current && current !== next) leavePresence(current)
	current = next
}

/** The tab closes. */
export function leaveOnClose() {
	leavePresence(current)
}
