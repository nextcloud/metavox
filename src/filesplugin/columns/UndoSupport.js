/**
 * MetaVox — Undo support for inline metadata edits.
 *
 * Provides pushUndo() and revertField() extracted from MetaVoxColumns.js.
 */

import axios from '@nextcloud/axios'
import { showUndo } from '@nextcloud/dialogs'
import { translate } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	metadataCache,
	getActiveGroupfolderId,
	getPendingUndo,
	setPendingUndo,
} from './MetaVoxState.js'

// NOTE: saveSingleField import will be added later when it is extracted to
// its own module.  For now these two functions are self-contained — revertField
// performs its own API call directly.

/** @type {function|null} Callback set by the columns module so revertField can refresh the DOM. */
let _updateAllRowCells = null

/**
 * Register the callback that refreshes every visible row cell from the cache.
 * Called once from MetaVoxColumns.js during initialisation.
 *
 * @param {function} fn
 */
export function setUpdateAllRowCells(fn) { _updateAllRowCells = fn }

/**
 * Show an undo toast for one or more metadata edits.
 *
 * @param {Array<{fileId: number, fieldName: string, oldValue: *}>} entries
 */
export function pushUndo(entries) {
	const prev = getPendingUndo()
	if (prev?.toast) prev.toast.hideToast()

	const t = translate
	const text = entries.length === 1
		? t('metavox', 'Metadata updated')
		: t('metavox', '{count} cells updated', { count: entries.length })

	const toast = showUndo(text, () => {
		for (const { fileId, fieldName, oldValue } of entries) {
			revertField(fileId, fieldName, oldValue)
		}
		setPendingUndo(null)
	})
	setPendingUndo({ entries, toast })
}

/**
 * Revert a single field to its previous value (called from undo callback).
 *
 * @param {number} fileId
 * @param {string} fieldName
 * @param {*}      oldValue
 */
export async function revertField(fileId, fieldName, oldValue) {
	const meta = metadataCache.get(fileId) || {}
	meta[fieldName] = oldValue
	metadataCache.set(fileId, meta)
	if (_updateAllRowCells) _updateAllRowCells()

	try {
		const activeGroupfolderId = getActiveGroupfolderId()
		await axios.post(
			generateUrl(`/apps/metavox/api/groupfolders/${activeGroupfolderId}/files/${fileId}/metadata`),
			{ metadata: { [fieldName]: oldValue } },
		)
		window.dispatchEvent(new CustomEvent('metavox:metadata:saved', {
			detail: { fileId, metadata: { ...meta } },
		}))
	} catch (e) {
		console.error('MetaVox: Failed to undo', e)
	}
}
