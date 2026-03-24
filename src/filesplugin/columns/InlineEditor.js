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
import { saveSingleField, saveBulkFields } from './MetaVoxAPI.js'
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
					if (!document.hasFocus()) {
						// Window lost focus (alt-tab) — cancel with sendBeacon unlock
						closeInlineEditor(true)
					} else {
						saveSingleField(fileId, fieldName, editor.value, { unlock: true })
						closeInlineEditor(false)
					}
				}
			})
			break
		}

		case 'user': {
			// User picker with server-side search-as-you-type
			const container = document.createElement('div')
			container.className = 'metavox-inline-editor metavox-inline-select metavox-user-picker'

			const searchInput = document.createElement('input')
			searchInput.type = 'text'
			searchInput.className = 'metavox-user-search'
			searchInput.placeholder = translate('metavox', 'Type to search users...')
			container.appendChild(searchInput)

			const listEl = document.createElement('div')
			listEl.className = 'metavox-user-list'
			container.appendChild(listEl)

			// Clear option (always visible)
			const clearOpt = document.createElement('div')
			clearOpt.className = 'metavox-select-option metavox-user-option'
			clearOpt.textContent = '—'
			if (!currentValue) clearOpt.classList.add('metavox-select-option--selected')
			clearOpt.addEventListener('click', () => {
				saveSingleField(fileId, fieldName, '', { unlock: true })
				closeInlineEditor(false)
			})
			listEl.appendChild(clearOpt)

			// Show current value if set
			if (currentValue) {
				const currentItem = document.createElement('div')
				currentItem.className = 'metavox-select-option metavox-user-option metavox-select-option--selected'
				const currentAvatar = document.createElement('img')
				currentAvatar.className = 'metavox-user-avatar'
				currentAvatar.src = generateUrl('/avatar/{userId}/24', { userId: currentValue })
				currentAvatar.width = 24
				currentAvatar.height = 24
				currentAvatar.onerror = () => { currentAvatar.style.display = 'none' }
				currentItem.appendChild(currentAvatar)
				currentItem.appendChild(document.createTextNode(currentValue))
				listEl.appendChild(currentItem)
			}

			let searchTimer = null

			const doSearch = (query) => {
				if (query.length < 2) {
					// Clear results, keep clear option + current
					while (listEl.children.length > (currentValue ? 2 : 1)) {
						listEl.removeChild(listEl.lastChild)
					}
					return
				}

				axios.get(generateUrl('/apps/metavox/api/users'), { params: { search: query } }).then(resp => {
					// Remove old results (keep clear option + current value)
					while (listEl.children.length > (currentValue ? 2 : 1)) {
						listEl.removeChild(listEl.lastChild)
					}

					const users = Array.isArray(resp.data) ? resp.data : []

					if (users.length === 0) {
						const empty = document.createElement('div')
						empty.className = 'metavox-select-option metavox-user-empty'
						empty.textContent = translate('metavox', 'No users found')
						listEl.appendChild(empty)
						return
					}

					for (const u of users) {
						// Skip if already shown as current value
						if (u.id === currentValue) continue

						const item = document.createElement('div')
						item.className = 'metavox-select-option metavox-user-option'

						const avatar = document.createElement('img')
						avatar.className = 'metavox-user-avatar'
						avatar.src = generateUrl('/avatar/{userId}/24', { userId: u.id })
						avatar.width = 24
						avatar.height = 24
						avatar.onerror = () => { avatar.style.display = 'none' }
						item.appendChild(avatar)

						const nameSpan = document.createElement('span')
						nameSpan.textContent = u.displayname || u.id
						item.appendChild(nameSpan)

						item.addEventListener('click', () => {
							saveSingleField(fileId, fieldName, u.id, { unlock: true })
							closeInlineEditor(false)
						})
						listEl.appendChild(item)
					}
				}).catch(() => {})
			}

			searchInput.addEventListener('input', () => {
				clearTimeout(searchTimer)
				searchTimer = setTimeout(() => doSearch(searchInput.value.trim()), 300)
			})

			container.addEventListener('keydown', (e) => {
				if (e.key === 'Escape') closeInlineEditor(true)
			})

			editor = container
			setTimeout(() => searchInput.focus(), 0)
			break
		}

		case 'url': {
			// URL input with open-link button
			const container = document.createElement('div')
			container.className = 'metavox-inline-editor metavox-inline-url'

			const input = document.createElement('input')
			input.type = 'url'
			input.className = 'metavox-url-input'
			input.value = currentValue || ''
			input.placeholder = translate('metavox', 'https://...')
			container.appendChild(input)

			const openBtn = document.createElement('a')
			openBtn.className = 'metavox-url-open'
			openBtn.textContent = '↗'
			openBtn.title = translate('metavox', 'Open link')
			openBtn.target = '_blank'
			openBtn.rel = 'noopener noreferrer'
			const updateLink = () => {
				const val = input.value.trim()
				openBtn.href = val && !/^https?:\/\//i.test(val) ? 'https://' + val : val
				openBtn.style.visibility = val ? 'visible' : 'hidden'
			}
			updateLink()
			input.addEventListener('input', updateLink)
			container.appendChild(openBtn)

			input.addEventListener('keydown', (e) => {
				if (e.key === 'Escape') closeInlineEditor(true)
				if (e.key === 'Enter') {
					saveSingleField(fileId, fieldName, input.value.trim(), { unlock: true })
					closeInlineEditor(false)
				}
			})
			input.addEventListener('blur', () => {
				if (activeEditor === td) {
					if (!document.hasFocus()) {
						closeInlineEditor(true)
					} else {
						saveSingleField(fileId, fieldName, input.value.trim(), { unlock: true })
						closeInlineEditor(false)
					}
				}
			})

			editor = container
			setTimeout(() => input.focus(), 0)
			break
		}

		case 'filelink': {
			// File picker — open NC file picker dialog, save selected path
			const container = document.createElement('div')
			container.className = 'metavox-inline-editor metavox-inline-filelink'

			const pathDisplay = document.createElement('span')
			pathDisplay.className = 'metavox-filelink-path'
			pathDisplay.textContent = currentValue ? currentValue.split('/').pop() : translate('metavox', 'No file selected')
			container.appendChild(pathDisplay)

			const browseBtn = document.createElement('button')
			browseBtn.className = 'metavox-filelink-browse'
			browseBtn.textContent = translate('metavox', 'Browse')
			browseBtn.addEventListener('click', () => {
				if (typeof OC !== 'undefined' && OC.dialogs) {
					OC.dialogs.filepicker(
						translate('metavox', 'Select a file or folder'),
						(path) => {
							if (path) {
								saveSingleField(fileId, fieldName, path, { unlock: true })
								closeInlineEditor(false)
							}
						},
						false, undefined, true,
						OC.dialogs.FILEPICKER_TYPE_CHOOSE,
						'/',
						{ allowDirectoryChooser: true }
					)
				}
			})
			container.appendChild(browseBtn)

			if (currentValue) {
				const openBtn = document.createElement('a')
				openBtn.className = 'metavox-filelink-open'
				openBtn.textContent = '↗'
				openBtn.title = translate('metavox', 'Open file')
				openBtn.target = '_blank'
				openBtn.rel = 'noopener noreferrer'
				const dir = currentValue.substring(0, currentValue.lastIndexOf('/'))
				openBtn.href = generateUrl('/apps/files/?dir={dir}&openfile={file}', { dir, file: currentValue })
				container.appendChild(openBtn)

				const clearBtn = document.createElement('button')
				clearBtn.className = 'metavox-filelink-clear'
				clearBtn.textContent = '✕'
				clearBtn.title = translate('metavox', 'Clear')
				clearBtn.addEventListener('click', () => {
					saveSingleField(fileId, fieldName, '', { unlock: true })
					closeInlineEditor(false)
				})
				container.appendChild(clearBtn)
			}

			container.addEventListener('keydown', (e) => {
				if (e.key === 'Escape') closeInlineEditor(true)
			})
			container.tabIndex = 0

			editor = container
			break
		}

		default: {
			// text, textarea — use text input
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
					if (!document.hasFocus()) {
						// Window lost focus (alt-tab) — cancel with sendBeacon unlock
						closeInlineEditor(true)
					} else {
						saveSingleField(fileId, fieldName, editor.value, { unlock: true })
						closeInlineEditor(false)
					}
				}
			})
			break
		}
	}

	// Replace cell content with editor
	td.textContent = ''
	td.style.position = 'relative'

	// Dropdowns need to be portaled to body to escape table overflow
	const isDropdown = config.field_type === 'multiselect' || config.field_type === 'select' || config.field_type === 'dropdown' || config.field_type === 'checkbox' || config.field_type === 'boolean' || config.field_type === 'user'
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
	// Uses sendBeacon to ensure the unlock reaches the server even during window blur/close
	if (cancel) {
		const fileId = Number(td.dataset?.fileId)
		const fieldName = td.dataset?.metavoxField
		const gfId = getActiveGroupfolderId()
		if (gfId && fileId && fieldName) {
			const url = generateUrl('/apps/metavox/api/groupfolders/{gfId}/files/{fileId}/unlock', { gfId, fileId })
			const token = document.querySelector('head[data-requesttoken]')?.dataset?.requesttoken || ''
			const formData = new FormData()
			formData.append('field_name', fieldName)
			formData.append('requesttoken', token)
			navigator.sendBeacon(url, formData)
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

	// Always re-render from cache (restores rich content like avatars, link buttons)
	const fileId2 = Number(td.dataset.fileId)
	const fieldName2 = td.dataset.metavoxField
	const activeColumnConfigs = _getActiveColumnConfigs ? _getActiveColumnConfigs() : []
	const config2 = activeColumnConfigs.find(c => c.field_name === fieldName2)
	const meta2 = metadataCache.get(fileId2) || {}
	if (config2 && _setCellValue) {
		_setCellValue(td, cancel ? td._originalValue : meta2[fieldName2], config2)
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

		// Collect file IDs and old values, then save in one bulk call
		const activeColumnConfigs = _getActiveColumnConfigs ? _getActiveColumnConfigs() : []
		const undoEntries = []
		const fileIds = []
		for (const cell of highlightedCells) {
			cell.classList.remove('metavox-fill-highlight')
			const fileId = Number(cell.dataset.fileId)
			const meta = metadataCache.get(fileId) || {}
			undoEntries.push({ fileId, fieldName, oldValue: meta[fieldName] })
			fileIds.push(fileId)
			const cellConfig = activeColumnConfigs.find(c => c.field_name === fieldName)
			if (cellConfig && _setCellValue) _setCellValue(cell, value, cellConfig)
		}
		highlightedCells = []

		// Single bulk API call instead of N separate requests
		if (fileIds.length > 0) {
			saveBulkFields(fileIds, fieldName, value)
		}

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
