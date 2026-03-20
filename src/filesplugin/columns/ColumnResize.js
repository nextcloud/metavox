/**
 * MetaVox Column Resize — drag-to-resize columns with localStorage persistence.
 *
 * Extracted from MetaVoxColumns.js
 */

import { MARKER_CLASS, RESIZE_HANDLE } from './ColumnStyles.js'
import { updateTableMinWidth } from './ColumnDOM.js'

// TODO: import columnWidths from MetaVoxState.js once it exists
// For now, accept it via setDependencies().

/** @type {Map<string, number>} Persisted column widths */
let columnWidths = new Map()

/**
 * Inject runtime dependencies that still live in MetaVoxColumns.js.
 * Call this once during bootstrap to wire everything together.
 */
export function setDependencies(deps) {
	if (deps.columnWidths !== undefined) columnWidths = deps.columnWidths
}

// ── Column width helpers ───────────────────────────────────────

export function setColumnWidth(fieldName, width) {
	document.querySelectorAll(`[data-metavox-field="${fieldName}"]`).forEach(el => {
		el.style.width = width + 'px'
		el.style.minWidth = width + 'px'
		el.style.maxWidth = width + 'px'
	})
}

export function startResize(e, th, fieldName) {
	const startX = e.clientX
	const startWidth = th.offsetWidth
	const handle = th.querySelector('.' + RESIZE_HANDLE)
	handle?.classList.add('active')

	const onMouseMove = (ev) => {
		const newWidth = Math.max(60, startWidth + (ev.clientX - startX))
		setColumnWidth(fieldName, newWidth)
	}

	const onMouseUp = () => {
		handle?.classList.remove('active')
		document.removeEventListener('mousemove', onMouseMove)
		document.removeEventListener('mouseup', onMouseUp)

		updateTableMinWidth()

		// Persist width
		const finalWidth = parseInt(th.style.width) || th.offsetWidth
		columnWidths.set(fieldName, finalWidth)

		try {
			const stored = JSON.parse(localStorage.getItem('metavox-col-widths') || '{}')
			stored[fieldName] = finalWidth
			localStorage.setItem('metavox-col-widths', JSON.stringify(stored))
		} catch (e) { /* ignore */ }
	}

	document.addEventListener('mousemove', onMouseMove)
	document.addEventListener('mouseup', onMouseUp)
}

export function loadPersistedWidths() {
	try {
		const stored = JSON.parse(localStorage.getItem('metavox-col-widths') || '{}')
		for (const [key, val] of Object.entries(stored)) {
			columnWidths.set(key, val)
		}
	} catch (e) { /* ignore */ }
}
