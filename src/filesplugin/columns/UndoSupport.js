/**
 * MetaVox — Undo support for inline metadata edits.
 *
 * Provides pushUndo() and revertField() extracted from MetaVoxColumns.js.
 */

import { showUndo } from '../../utils/toast.js'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { getPendingUndo, setPendingUndo } from './MetaVoxState.js'
import { saveFileFields } from './MetaVoxAPI.js'

/**
 * Show an undo toast for one or more metadata edits.
 *
 * @param {Array<{fileId: number, fieldName: string, oldValue: *}>} entries
 */
export function pushUndo(entries) {
	const prev = getPendingUndo()
	if (prev?.toast) prev.toast.hideToast()

	// A toast: its text is HTML, so the placeholder keeps the default escaping.
	const toast = showUndo(entries.length === 1
		// TRANSLATORS Message with an Undo button after one cell value was changed
		? t('metavox', 'Metadata updated')
		// TRANSLATORS Message with an Undo button; {count} is the number of cells that were changed at once, e.g. "5 cells updated"
		: n('metavox', '{count} cell updated', '{count} cells updated', entries.length, { count: entries.length }), () => {
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
	try {
		// Saved like an edit: the grid follows at once and goes back if it fails.
		await saveFileFields(fileId, { [fieldName]: oldValue ?? '' })
	} catch (e) {
		console.error('MetaVox: Failed to undo', e)
	}
}
