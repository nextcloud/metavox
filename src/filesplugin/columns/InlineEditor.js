/**
 * MetaVox — Inline Cell Editing (SharePoint-style)
 *
 * Extracted from MetaVoxColumns.js. Handles double-click-to-edit cells,
 * field-type-specific editors, click-outside closing, and Excel-style
 * fill-handle drag-to-copy.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { MARKER_CLASS } from './ColumnStyles.js'
import { saveSingleField } from './MetaVoxAPI.js'
import { parseFieldOptions, formatValue } from './ColumnUtils.js'
import { permissionCache, metadataCache, getActiveGroupfolderId } from './MetaVoxState.js'
import { translate } from '@nextcloud/l10n'
import { pushUndo } from './UndoSupport.js'

// ── Module-level state ─────────────────────────────────────────

/** @type {HTMLElement|null} Currently active inline editor */
let activeEditor = null

// ── Callback for updateAllRowCells ─────────────────────────────

/** @type {function|null} */
let _updateAllRowCells = null

/**
 * Register the callback that refreshes every visible row cell from the cache.
 * Called once from the main columns module during initialisation.
 *
 * @param {function} fn
 */
export function setUpdateAllRowCells(fn) { _updateAllRowCells = fn }

// ── Callback for activeColumnConfigs ───────────────────────────

/** @type {function|null} */
let _getActiveColumnConfigs = null

/**
 * Register a getter for the current column configs.
 *
 * @param {function} fn
 */
export function setGetActiveColumnConfigs(fn) { _getActiveColumnConfigs = fn }

// ── Callback for setCellValue ──────────────────────────────────

/** @type {function|null} */
let _setCellValue = null

/**
 * Register the setCellValue function from ColumnDOM.
 *
 * @param {function} fn
 */
export function setSetCellValue(fn) { _setCellValue = fn }

// ── Public functions ───────────────────────────────────────────

/**
 * Check whether the current user has write permission on a file.
 *
 * @param {number} fileId
 * @return {boolean}
 */
export function canEditFile(fileId) {
	const canEdit = permissionCache.get(fileId)
	// If permissions were loaded, use them; if unknown, allow (new files)
	return canEdit !== false
}

/**
 * Attach a double-click handler to a table cell for inline editing.
 *
 * @param {HTMLElement} td     The <td> element
 * @param {Object}      config Column configuration object
 */
export function setupCellEditing(td, config) {
	td.style.cursor = 'pointer'
	td.addEventListener('dblclick', (e) => {
		e.preventDefault()
		e.stopPropagation()

		// Check if cell is locked by another user
		if (td._metavoxLocked) return

		// Check write permission (loaded from API response)
		const fileId = Number(td.dataset.fileId)

		if (!canEditFile(fileId)) {
			td.style.cursor = 'default'
			return
		}

		openInlineEditor(td, config)
	})
}

/**
 * Open an inline editor in the given table cell.
 *
 * Supports field types: checkbox/boolean, select/dropdown, multiselect,
 * date, number, and text (default).
 *
 * @param {HTMLElement} td     The <td> element
 * @param {Object}      config Column configuration object
 */
export async function openInlineEditor(td, config) {
	// Don't open if already editing
	if (activeEditor) {
		closeInlineEditor(false)
	}

	const fileId = Number(td.dataset.fileId)
	const fieldName = td.dataset.metavoxField
	const gfId = getActiveGroupfolderId()

	// Try to acquire lock
	if (gfId && fileId && fieldName) {
		try {
			const url = generateUrl('/apps/metavox/api/groupfolders/{gfId}/files/{fileId}/lock', { gfId, fileId })
			const resp = await axios.post(url, { field_name: fieldName })
			if (resp.data?.locked) {
				td.title = translate('metavox', 'Being edited by {user}', { user: resp.data.lockedBy || '?' })
				td.style.cursor = 'not-allowed'
				setTimeout(() => { td.style.cursor = ''; td.title = '' }, 3000)
				return
			}
		} catch (e) {
			if (e.response?.status === 409) {
				const lockedBy = e.response?.data?.lockedBy || '?'
				td.title = translate('metavox', 'Being edited by {user}', { user: lockedBy })
				td.style.cursor = 'not-allowed'
				setTimeout(() => { td.style.cursor = ''; td.title = '' }, 3000)
				return
			}
			// Lock API unavailable — proceed without locking
		}
	}

	const meta = metadataCache.get(fileId) || {}
	const currentValue = meta[fieldName] || ''

	// Store original content for cancel
	td._originalContent = td.textContent
	td._originalValue = currentValue

	let editor

	switch (config.field_type) {
		case 'checkbox':
		case 'boolean': {
			// Yes/No dropdown, same pattern as select
			const container = document.createElement('div')
			container.className = 'metavox-inline-editor metavox-inline-select'

			const boolOpts = [
				{ value: '1', label: translate('metavox', 'Yes') },
				{ value: '0', label: translate('metavox', 'No') },
			]

			for (const opt of boolOpts) {
				const item = document.createElement('div')
				item.className = 'metavox-select-option'
				item.dataset.value = opt.value
				item.textContent = opt.label
				if (opt.value === currentValue) item.classList.add('metavox-select-option--selected')
				item.addEventListener('click', () => {
					saveSingleField(fileId, fieldName, opt.value, { unlock: true })
					closeInlineEditor(false)
				})
				container.appendChild(item)
			}

			container.addEventListener('keydown', (e) => {
				if (e.key === 'Escape') closeInlineEditor(true)
			})
			container.tabIndex = 0
			editor = container
			break
		}

		case 'select':
		case 'dropdown': {
			// Custom dropdown styled like NcSelect
			const container = document.createElement('div')
			container.className = 'metavox-inline-editor metavox-inline-select'
			const opts = parseFieldOptions(config.field_options)

			// Clear option
			const clearOpt = document.createElement('div')
			clearOpt.className = 'metavox-select-option'
			clearOpt.dataset.value = ''
			clearOpt.textContent = '—'
			if (!currentValue) clearOpt.classList.add('metavox-select-option--selected')
			clearOpt.addEventListener('click', () => {
				saveSingleField(fileId, fieldName, '', { unlock: true })
				closeInlineEditor(false)
			})
			container.appendChild(clearOpt)

			for (const opt of opts) {
				const item = document.createElement('div')
				item.className = 'metavox-select-option'
				item.dataset.value = opt
				item.textContent = opt
				if (opt === currentValue) item.classList.add('metavox-select-option--selected')
				item.addEventListener('click', () => {
					saveSingleField(fileId, fieldName, opt, { unlock: true })
					closeInlineEditor(false)
				})
				container.appendChild(item)
			}

			container.addEventListener('keydown', (e) => {
				if (e.key === 'Escape') closeInlineEditor(true)
			})
			container.tabIndex = 0
			editor = container
			break
		}

		case 'multiselect': {
			// Create a dropdown with checkboxes
			const container = document.createElement('div')
			container.className = 'metavox-inline-editor metavox-inline-multiselect'
			const opts = parseFieldOptions(config.field_options)
			const selectedValues = currentValue ? currentValue.split(';#').filter(v => v.trim()) : []

			for (const opt of opts) {
				const label = document.createElement('label')
				label.className = 'metavox-ms-option'
				const cb = document.createElement('input')
				cb.type = 'checkbox'
				cb.value = opt
				cb.checked = selectedValues.includes(opt)
				label.appendChild(cb)
				label.appendChild(document.createTextNode(' ' + opt))
				container.appendChild(label)
			}

			const btnRow = document.createElement('div')
			btnRow.className = 'metavox-ms-actions'
			const saveBtn = document.createElement('button')
			saveBtn.textContent = '\u2713'
			saveBtn.className = 'metavox-ms-save'
			saveBtn.addEventListener('click', () => {
				const checked = Array.from(container.querySelectorAll('input:checked')).map(c => c.value)
				saveSingleField(fileId, fieldName, checked.join(';#'), { unlock: true })
				closeInlineEditor(false)
			})
			const cancelBtn = document.createElement('button')
			cancelBtn.textContent = '\u2715'
			cancelBtn.className = 'metavox-ms-cancel'
			cancelBtn.addEventListener('click', () => closeInlineEditor(true))
			btnRow.appendChild(saveBtn)
			btnRow.appendChild(cancelBtn)
			container.appendChild(btnRow)

			editor = container
			break
		}

		case 'date': {
			editor = document.createElement('input')
			editor.type = 'date'
			editor.className = 'metavox-inline-editor metavox-inline-date'
			editor.value = currentValue || ''
			editor.addEventListener('change', () => {
				saveSingleField(fileId, fieldName, editor.value, { unlock: true })
				closeInlineEditor(false)
			})
			editor.addEventListener('keydown', (e) => {
				if (e.key === 'Escape') closeInlineEditor(true)
				if (e.key === 'Enter') {
					saveSingleField(fileId, fieldName, editor.value, { unlock: true })
					closeInlineEditor(false)
				}
			})
			break
		}

		case 'number': {
			editor = document.createElement('input')
			editor.type = 'number'
			editor.className = 'metavox-inline-editor metavox-inline-input'
			editor.value = currentValue || ''
			editor.addEventListener('keydown', (e) => {
				if (e.key === 'Escape') closeInlineEditor(true)
				if (e.key === 'Enter') {
					saveSingleField(fileId, fieldName, editor.value, { unlock: true })
					closeInlineEditor(false)
				}
			})
			editor.addEventListener('blur', () => {
				if (activeEditor === td) {
					saveSingleField(fileId, fieldName, editor.value, { unlock: true })
					closeInlineEditor(false)
				}
			})
			break
		}

		default: {
			// text, textarea, url — use text input
			editor = document.createElement('input')
			editor.type = 'text'
			editor.className = 'metavox-inline-editor metavox-inline-input'
			editor.value = currentValue || ''
			editor.addEventListener('keydown', (e) => {
				if (e.key === 'Escape') closeInlineEditor(true)
				if (e.key === 'Enter') {
					saveSingleField(fileId, fieldName, editor.value, { unlock: true })
					closeInlineEditor(false)
				}
			})
			editor.addEventListener('blur', () => {
				if (activeEditor === td) {
					saveSingleField(fileId, fieldName, editor.value, { unlock: true })
					closeInlineEditor(false)
				}
			})
			break
		}
	}

	// Replace cell content with editor
	td.textContent = ''
	td.style.position = 'relative'

	// Dropdowns need to be portaled to body to escape table overflow
	const isDropdown = config.field_type === 'multiselect' || config.field_type === 'select' || config.field_type === 'dropdown' || config.field_type === 'checkbox' || config.field_type === 'boolean'
	if (isDropdown) {
		const rect = td.getBoundingClientRect()
		editor.style.position = 'fixed'
		editor.style.zIndex = '10000'
		editor.style.minWidth = '180px'
		editor.style.maxWidth = '300px'
		editor.style.width = 'max-content'
		// Temporarily add to measure dimensions
		editor.style.visibility = 'hidden'
		document.body.appendChild(editor)
		const editorHeight = editor.offsetHeight
		const editorWidth = editor.offsetWidth
		editor.style.visibility = ''

		// Horizontal: keep within viewport
		const spaceRight = window.innerWidth - rect.left
		if (spaceRight < editorWidth) {
			// Align right edge to viewport edge (with margin)
			editor.style.right = '8px'
			editor.style.left = 'auto'
		} else {
			editor.style.left = rect.left + 'px'
		}

		// Vertical: open upward if not enough space below
		const spaceBelow = window.innerHeight - rect.bottom
		const spaceAbove = rect.top
		if (spaceBelow < editorHeight && spaceAbove > spaceBelow) {
			const maxH = Math.min(editorHeight, spaceAbove - 8)
			editor.style.bottom = (window.innerHeight - rect.top) + 'px'
			editor.style.top = 'auto'
			editor.style.maxHeight = maxH + 'px'
		} else {
			editor.style.top = rect.bottom + 'px'
			editor.style.maxHeight = (spaceBelow - 8) + 'px'
		}
		td._portalEditor = editor
		// Show a placeholder in the cell (use span so opacity doesn't affect fill handle)
		const placeholder = document.createElement('span')
		placeholder.textContent = '\u2026'
		placeholder.style.opacity = '0.5'
		td.appendChild(placeholder)
	} else {
		td.appendChild(editor)
	}
	activeEditor = td

	// Add fill handle (Excel-style drag-down to copy value)
	const handle = document.createElement('div')
	handle.className = 'metavox-fill-handle'
	td.appendChild(handle)
	setupFillHandle(handle, td, config)

	// Focus the editor
	if (editor.tagName === 'INPUT' || editor.tagName === 'SELECT') {
		editor.focus()
		if (editor.type === 'text') editor.select()
	}

	// Close on click outside
	setTimeout(() => {
		document.addEventListener('mousedown', handleEditorClickOutside)
	}, 0)

	// Close portaled dropdowns on scroll (they don't follow the page)
	if (isDropdown) {
		const scrollHandler = () => {
			closeInlineEditor(true)
		}
		// Listen on capture phase to catch scroll on any container
		document.addEventListener('scroll', scrollHandler, { capture: true, once: true })
		td._scrollHandler = scrollHandler
	}
}

/**
 * Handle clicks outside the active editor to close it.
 *
 * @param {MouseEvent} e
 */
export function handleEditorClickOutside(e) {
	if (!activeEditor) {
		document.removeEventListener('mousedown', handleEditorClickOutside)
		return
	}
	// Check both the cell and portaled dropdown (multiselect)
	const inCell = activeEditor.contains(e.target)
	const inPortal = activeEditor._portalEditor && activeEditor._portalEditor.contains(e.target)
	if (!inCell && !inPortal) {
		closeInlineEditor(true)
		document.removeEventListener('mousedown', handleEditorClickOutside)
	}
}

/**
 * Close the currently active inline editor.
 *
 * @param {boolean} cancel If true, restore original cell content instead of
 *                         re-rendering from cache.
 */
export function closeInlineEditor(cancel) {
	if (!activeEditor) return
	const td = activeEditor
	activeEditor = null

	document.removeEventListener('mousedown', handleEditorClickOutside)

	// Release lock only on cancel — on save, the lock is released via combined save+unlock
	if (cancel) {
		const fileId = Number(td.dataset?.fileId)
		const fieldName = td.dataset?.metavoxField
		const gfId = getActiveGroupfolderId()
		if (gfId && fileId && fieldName) {
			const url = generateUrl('/apps/metavox/api/groupfolders/{gfId}/files/{fileId}/unlock', { gfId, fileId })
			axios.post(url, { field_name: fieldName }).catch(() => {})
		}
	}

	// Remove portaled dropdown from body
	if (td._portalEditor) {
		td._portalEditor.remove()
		delete td._portalEditor
	}

	// Clean up scroll handler
	if (td._scrollHandler) {
		document.removeEventListener('scroll', td._scrollHandler, { capture: true })
		delete td._scrollHandler
	}

	if (cancel && td._originalContent !== undefined) {
		td.textContent = td._originalContent
	} else {
		// Re-render from cache
		const fileId = Number(td.dataset.fileId)
		const fieldName = td.dataset.metavoxField
		const activeColumnConfigs = _getActiveColumnConfigs ? _getActiveColumnConfigs() : []
		const config = activeColumnConfigs.find(c => c.field_name === fieldName)
		const meta = metadataCache.get(fileId) || {}
		if (config && _setCellValue) _setCellValue(td, meta[fieldName], config)
	}

	delete td._originalContent
	delete td._originalValue
	td.style.position = ''
}

/**
 * Fill handle: drag down from active editor cell to copy value to cells below.
 * Works like Excel's fill handle — drag the blue square at the bottom-right corner.
 *
 * @param {HTMLElement} handle   The fill-handle element
 * @param {HTMLElement} sourceTd The source <td> element
 * @param {Object}      config   Column configuration object
 */
export function setupFillHandle(handle, sourceTd, config) {
	const fieldName = sourceTd.dataset.metavoxField
	let highlightedCells = []

	function getCellsBelow(sourceTd, fieldName) {
		const sourceRow = sourceTd.closest('tr')
		if (!sourceRow) return []
		const cells = []
		let row = sourceRow.nextElementSibling
		while (row) {
			const cell = row.querySelector(`[data-metavox-field="${fieldName}"]`)
			if (cell) {
				const cellFileId = Number(cell.dataset.fileId)
				if (canEditFile(cellFileId)) {
					cells.push(cell)
				}
			}
			row = row.nextElementSibling
		}
		return cells
	}

	function highlightTo(targetY) {
		// Clear previous highlights
		for (const c of highlightedCells) c.classList.remove('metavox-fill-highlight')
		highlightedCells = []

		const cellsBelow = getCellsBelow(sourceTd, fieldName)
		for (const cell of cellsBelow) {
			const rect = cell.getBoundingClientRect()
			if (rect.top < targetY) {
				cell.classList.add('metavox-fill-highlight')
				highlightedCells.push(cell)
			} else {
				break
			}
		}
	}

	function onMouseMove(e) {
		e.preventDefault()
		highlightTo(e.clientY)
	}

	function onMouseUp(e) {
		document.removeEventListener('mousemove', onMouseMove)
		document.removeEventListener('mouseup', onMouseUp)

		if (highlightedCells.length === 0) return

		// Get value from editor
		const editorEl = sourceTd.querySelector('input, select')
		const value = editorEl ? editorEl.value : sourceTd._originalValue || ''

		// Collect old values for batch undo, then save
		const activeColumnConfigs = _getActiveColumnConfigs ? _getActiveColumnConfigs() : []
		const undoEntries = []
		for (const cell of highlightedCells) {
			cell.classList.remove('metavox-fill-highlight')
			const fileId = Number(cell.dataset.fileId)
			const meta = metadataCache.get(fileId) || {}
			undoEntries.push({ fileId, fieldName, oldValue: meta[fieldName] })
			saveSingleField(fileId, fieldName, value, { skipUndo: true })
			const cellConfig = activeColumnConfigs.find(c => c.field_name === fieldName)
			if (cellConfig && _setCellValue) _setCellValue(cell, value, cellConfig)
		}
		highlightedCells = []

		// Single undo toast for the entire fill operation
		if (undoEntries.length > 0) {
			pushUndo(undoEntries)
		}

		// Close the editor after fill
		closeInlineEditor(false)
	}

	handle.addEventListener('mousedown', (e) => {
		e.preventDefault()
		e.stopPropagation()
		document.addEventListener('mousemove', onMouseMove)
		document.addEventListener('mouseup', onMouseUp)
	})
}
