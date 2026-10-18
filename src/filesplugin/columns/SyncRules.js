/**
 * MetaVox SyncRules — when to update the grid from outside: notify_push events
 * and returning to the tab. Pure functions, so the rules are testable.
 */

/** Only refresh on return when notify_push was connected and then dropped. */
export const MIN_HIDDEN_MS = 30000
export const MIN_REFRESH_INTERVAL_MS = 60000

/**
 * @param {object} body the metavox_metadata_changed push
 * @param {?number} activeGroupfolderId
 * @return {{type: 'ignore'}|{type: 'reload', ids: number[]}|{type: 'refresh'}}
 *   reload: load these files again; refresh: reload the whole listing. A push
 *   never carries values: every member gets it, so values come through routes
 *   that check the file for the reader.
 */
export function decideMetadataPush(body, activeGroupfolderId) {
	const groupfolderId = Number(body?.gfId) || 0
	if (!activeGroupfolderId || (groupfolderId && groupfolderId !== activeGroupfolderId)) {
		return { type: 'ignore' }
	}
	const fileId = Number(body?.fileId) || 0
	if (Array.isArray(body?.fileIds)) {
		return { type: 'reload', ids: body.fileIds.map(Number).filter(Boolean) }
	}
	if (fileId) {
		return { type: 'reload', ids: [fileId] }
	}
	return { type: 'refresh' }
}

/**
 * Whether to refresh after returning to the tab. Without notify_push nothing
 * can be missed live, so navigation is what refreshes.
 *
 * @param {{wsOpen: boolean, wsWasOpen: boolean, hiddenMs: number, sinceRefreshMs: number}} state
 */
export function shouldRefreshOnReturn({ wsOpen, wsWasOpen, hiddenMs, sinceRefreshMs }) {
	return !wsOpen && wsWasOpen && hiddenMs >= MIN_HIDDEN_MS && sinceRefreshMs >= MIN_REFRESH_INTERVAL_MS
}
