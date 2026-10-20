/**
 * MetaVox — Inline Cell Editing (SharePoint-style)
 *
 * Extracted from MetaVoxColumns.js. Handles double-click-to-edit cells,
 * field-type-specific editors, click-outside closing, and Excel-style
 * fill-handle drag-to-copy.
 */

import axios from '@nextcloud/axios'
import { showError, showWarning } from '../../utils/toast.js'
import { generateUrl } from '@nextcloud/router'
import { MARKER_CLASS } from './ColumnStyles.js'
import { saveSingleField, saveBulkFields } from './MetaVoxAPI.js'
import { requireFull } from './MetadataLoader.js'
import { formatValue } from './ColumnUtils.js'
import { accountOptions, optionItems } from '../../utils/fieldOptions.js'
import { isRequired } from '../../utils/requiredFields.js'
import { permissionCache, metadataCache, getActiveGroupfolderId, getActiveFolderPath } from './MetaVoxState.js'
import { filelinkHref, filelinkLabel } from './FilelinkNames.js'
import { accountIcon, accountNameElement } from './AccountCells.js'
import { accountItems, accountsOf, rememberAccountNames } from '../../utils/accountNames.js'
import { parseValue, joinTokens, isWithin } from '../../components/fields/filelinkUtils.js'
import { PLAIN_TEXT, splitMulti } from '../../utils/fieldValue.js'
import { translate as t } from '@nextcloud/l10n'
import { pushUndo } from './UndoSupport.js'
import { dateFieldIncludesTime, padDatetimeLocal } from '../../utils/dateField.js'
import { clearCellFormat } from './CellFormat.js'

// ── Module-level state ─────────────────────────────────────────

/** @type {HTMLElement|null} Currently active inline editor */
let activeEditor = null

/** The cell with the open editor, which a redraw has to leave alone. */
export function getActiveEditorCell() { return activeEditor }

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
	// Only what the server said: unknown is not editable yet.
	return permissionCache.get(fileId) === true
}

/**
 * Make a table cell editable: by double-click, and by keyboard.
 *
 * The cell is the control, so it carries the affordances of one — it is
 * focusable, it says what it is, and Enter or F2 opens the editor. A
 * double-click alone left the grid's primary action reachable only with a
 * mouse. F2 is there because that is what a spreadsheet uses, and this is a
 * grid of values.
 *
 * @param {HTMLElement} td     The <td> element
 * @param {Object}      config Column configuration object
 */
/**
 * The tooltip of a cell someone else is editing.
 *
 * @param {string|undefined} user who holds the lock, when the server says
 * @return {string}
 */
function lockedTitle(user) {
	return user
		// TRANSLATORS Tooltip on a grid cell that is locked; {user} is the display name of the person editing it, e.g. "Being edited by Anna de Vries"
		? t('metavox', 'Being edited by {user}', { user }, undefined, PLAIN_TEXT)
		// TRANSLATORS Tooltip on a grid cell that another person is editing, when their name is unknown
		: t('metavox', 'Being edited by someone else')
}

export function setupCellEditing(td, config) {
	const open = (e) => {
		e.preventDefault()
		e.stopPropagation()

		// Locked by another user
		if (td._metavoxLocked) return

		// Write permission, loaded from the API response
		if (!canEditFile(Number(td.dataset.fileId))) {
			td.style.cursor = 'default'
			td.removeAttribute('tabindex')
			return
		}

		openInlineEditor(td, config)
	}

	td.style.cursor = 'pointer'
	td.tabIndex = 0
	td.classList.add('metavox-cell-editable')
	td.addEventListener('dblclick', open)
	td.addEventListener('keydown', (e) => {
		// Enter and F2 only. Space would fight the file list's own row
		// selection, and a bare letter would fight type-ahead.
		if (e.key !== 'Enter' && e.key !== 'F2') return
		// Keys typed in the open editor are the editor's: Enter there saves.
		// This listener captures, so without this it took them first.
		if (e.target !== td) return
		// The file list listens for Enter on an ancestor and opens the file.
		// Stop it here, before the guards below can return early — otherwise a
		// locked or read-only cell still opens the file instead of doing
		// nothing, which is worse than either outcome.
		e.preventDefault()
		e.stopPropagation()
		open(e)
	}, true)
	updateCellAccessibleName(td, config)
}

/**
 * Name the cell for assistive technology: which field, which value, and that
 * it can be edited. Without this a screen reader reads a bare table cell and
 * gives no hint that Enter does anything.
 *
 * @param {HTMLElement} td     The <td> element
 * @param {Object}      config Column configuration object
 */
export function updateCellAccessibleName(td, config) {
	// The renderer writes an em dash for no value and an ellipsis while the
	// row is still loading; neither is a value to read out.
	const shown = (td.textContent || '').trim()
	let value = shown
	if (shown === '\u2026') {
		// TRANSLATORS Read out by screen readers as the value of a cell whose value is still loading
		value = t('metavox', 'Loading\u00a0\u2026')
	} else if (td.dataset.mvRequired) {
		// TRANSLATORS Read out by screen readers as the value of a cell of a required field that has no value yet
		value = t('metavox', 'Required, no value yet')
	} else if (shown === '' || shown === '\u2014') {
		// TRANSLATORS Adjective: read out by screen readers as the value of a cell that has no value
		value = t('metavox', 'Empty')
	}
	// An attribute is plain text. The state of a highlight rule is read
	// from the cell itself: setupCellEditing names the cell again later.
	const state = td.dataset.mvNote
	const vars = { field: config.field_label, value, state }
	const editable = canEditFile(Number(td.dataset.fileId))
	let label
	if (state) {
		label = editable
			// TRANSLATORS Screen-reader name of an editable grid cell; {field} is the column name, {value} the cell value, {state} the name of a highlight, e.g. "Due date: 3 March 2026, Overdue. Press Enter to edit."
			? t('metavox', '{field}: {value}, {state}. Press Enter to edit.', vars, undefined, PLAIN_TEXT)
			// TRANSLATORS Screen-reader name of a read-only grid cell; {field} is the column name, {value} the cell value, {state} the name of a highlight, e.g. "Due date: 3 March 2026, Overdue."
			: t('metavox', '{field}: {value}, {state}.', vars, undefined, PLAIN_TEXT)
	} else {
		label = editable
			// TRANSLATORS Screen-reader name of an editable grid cell; {field} is the column name, {value} the cell value, e.g. "Status: Approved. Press Enter to edit."
			? t('metavox', '{field}: {value}. Press Enter to edit.', vars, undefined, PLAIN_TEXT)
			// TRANSLATORS Screen-reader name of a read-only grid cell; {field} is the column name, {value} the cell value, e.g. "Status: Approved."
			: t('metavox', '{field}: {value}.', vars, undefined, PLAIN_TEXT)
	}
	td.setAttribute('aria-label', label)
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

	// Never edit a cut-off value: load the full one first, before locking.
	if (fileId && fieldName && !(await requireFull([fileId], fieldName))) {
		showError(t('metavox', 'The full value could not be loaded. Try again.'))
		return
	}
	// The file list may have handed the row to another file while we waited.
	const rowReused = () => Number(td.dataset.fileId) !== fileId
	if (rowReused()) return

	// Try to acquire lock
	if (gfId && fileId && fieldName) {
		try {
			const url = generateUrl('/apps/metavox/api/groupfolders/{gfId}/files/{fileId}/lock', { gfId, fileId })
			const resp = await axios.post(url, { field_name: fieldName })
			if (resp.data?.locked) {
				td.title = lockedTitle(resp.data.lockedBy)
				td.style.cursor = 'not-allowed'
				setTimeout(() => { td.style.cursor = ''; td.title = '' }, 3000)
				return
			}
		} catch (e) {
			if (e.response?.status === 409) {
				td.title = lockedTitle(e.response?.data?.lockedBy)
				td.style.cursor = 'not-allowed'
				setTimeout(() => { td.style.cursor = ''; td.title = '' }, 3000)
				return
			}
			// Lock API unavailable — proceed without locking
		}
		if (rowReused()) {
			releaseLock(gfId, fileId, fieldName)
			return
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
				// TRANSLATORS Option in the cell editor of a checkbox field: ticked
				{ value: '1', label: t('metavox', 'Yes') },
				// TRANSLATORS Option in the cell editor of a checkbox field: not ticked
				{ value: '0', label: t('metavox', 'No') },
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

			container.tabIndex = 0
			editor = container
			break
		}

		case 'select':
		case 'dropdown': {
			// Custom dropdown styled like NcSelect
			const container = document.createElement('div')
			container.className = 'metavox-inline-editor metavox-inline-select'

			// Clear option; a required field keeps a value.
			if (!isRequired(config)) {
				const clearOpt = document.createElement('div')
				clearOpt.className = 'metavox-select-option'
				clearOpt.dataset.value = ''
				clearOpt.textContent = '—'
				// TRANSLATORS Verb: accessible name of the empty option that clears the cell value
				clearOpt.setAttribute('aria-label', t('metavox', 'Clear'))
				if (!currentValue) clearOpt.classList.add('metavox-select-option--selected')
				clearOpt.addEventListener('click', () => {
					saveSingleField(fileId, fieldName, '', { unlock: true })
					closeInlineEditor(false)
				})
				container.appendChild(clearOpt)
			}

			for (const opt of optionItems(config.field_options)) {
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

			container.tabIndex = 0
			editor = container
			break
		}

		case 'multiselect': {
			// Create a dropdown with checkboxes
			const container = document.createElement('div')
			container.className = 'metavox-inline-editor metavox-inline-multiselect'
			const selectedValues = splitMulti(currentValue)

			for (const opt of optionItems(config.field_options)) {
				const label = document.createElement('label')
				label.className = 'metavox-ms-option'
				const cb = document.createElement('input')
				cb.type = 'checkbox'
				cb.value = opt.value
				cb.checked = selectedValues.includes(opt.value)
				label.appendChild(cb)
				label.appendChild(document.createTextNode(' ' + opt.label))
				container.appendChild(label)
			}

			const btnRow = document.createElement('div')
			btnRow.className = 'metavox-ms-actions'
			const saveBtn = document.createElement('button')
			saveBtn.textContent = '\u2713'
			// TRANSLATORS Verb: tooltip and accessible name of the check-mark button that saves the cell value
			saveBtn.title = t('metavox', 'Save')
			saveBtn.setAttribute('aria-label', saveBtn.title)
			saveBtn.className = 'metavox-ms-save'
			saveBtn.addEventListener('click', () => {
				const checked = Array.from(container.querySelectorAll('input:checked')).map(c => c.value)
				saveSingleField(fileId, fieldName, checked.join(';#'), { unlock: true })
				closeInlineEditor(false)
			})
			const cancelBtn = document.createElement('button')
			cancelBtn.textContent = '\u2715'
			// TRANSLATORS Verb: tooltip and accessible name of the cross button that closes the cell editor without saving
			cancelBtn.title = t('metavox', 'Cancel')
			cancelBtn.setAttribute('aria-label', cancelBtn.title)
			cancelBtn.className = 'metavox-ms-cancel'
			cancelBtn.addEventListener('click', () => closeInlineEditor(true))
			btnRow.appendChild(saveBtn)
			btnRow.appendChild(cancelBtn)
			container.appendChild(btnRow)
			// Focusable when there are no options to focus.
			container.tabIndex = -1

			editor = container
			break
		}

		case 'date': {
			const withTime = dateFieldIncludesTime(config)
			editor = document.createElement('input')
			editor.type = withTime ? 'datetime-local' : 'date'
			if (withTime) editor.step = '1'
			editor.className = 'metavox-inline-editor metavox-inline-date'
			editor.value = currentValue || ''
			const commit = () => {
				const v = withTime ? padDatetimeLocal(editor.value) : editor.value
				saveSingleField(fileId, fieldName, v, { unlock: true })
				closeInlineEditor(false)
			}
			editor.addEventListener('change', commit)
			editor.addEventListener('keydown', (e) => {
				if (e.key === 'Enter') commit()
			})
			break
		}

		case 'number': {
			editor = document.createElement('input')
			editor.type = 'number'
			editor.className = 'metavox-inline-editor metavox-inline-input'
			editor.value = currentValue || ''
			editor.addEventListener('keydown', (e) => {
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
			// People: Nextcloud's sharee search as you type, of the kinds the
			// field takes. One choice saves on a click; several are ticked and
			// saved with the check mark.
			const takes = accountOptions(config.field_options)
			const several = takes.multiple || accountItems(currentValue).length > 1
			const chosen = new Set(accountItems(currentValue))
			const container = document.createElement('div')
			container.className = 'metavox-inline-editor metavox-inline-select metavox-user-picker'

			const searchInput = document.createElement('input')
			searchInput.type = 'text'
			searchInput.className = 'metavox-user-search'
			searchInput.placeholder = takes.kinds.length === 1 && takes.kinds[0] === 'user'
				// TRANSLATORS Placeholder of the account search box in the cell editor of a person field
				? t('metavox', 'Type to search accounts …')
				// TRANSLATORS Placeholder of the search box of a People field that also takes groups or teams
				: t('metavox', 'Type to search people and groups …')
			container.appendChild(searchInput)

			const listEl = document.createElement('div')
			listEl.className = 'metavox-user-list'
			container.appendChild(listEl)

			const option = (person) => {
				const item = document.createElement(several ? 'label' : 'div')
				item.className = several ? 'metavox-ms-option metavox-user-option' : 'metavox-select-option metavox-user-option'
				if (several) {
					const cb = document.createElement('input')
					cb.type = 'checkbox'
					cb.checked = chosen.has(person.id)
					cb.addEventListener('change', () => {
						if (cb.checked) chosen.add(person.id)
						else chosen.delete(person.id)
					})
					item.appendChild(cb)
				} else {
					if (chosen.has(person.id)) item.classList.add('metavox-select-option--selected')
					item.addEventListener('click', () => {
						saveSingleField(fileId, fieldName, person.id, { unlock: true })
						closeInlineEditor(false)
					})
				}
				item.append(accountIcon(person, 24), accountNameElement(person))
				return item
			}

			// Clear option, for one choice; a required field keeps a value.
			if (!several && !isRequired(config)) {
				const clearOpt = document.createElement('div')
				clearOpt.className = 'metavox-select-option metavox-user-option'
				clearOpt.textContent = '—'
				// TRANSLATORS Verb: accessible name of the empty option that clears the cell value
				clearOpt.setAttribute('aria-label', t('metavox', 'Clear'))
				if (!currentValue) clearOpt.classList.add('metavox-select-option--selected')
				clearOpt.addEventListener('click', () => {
					saveSingleField(fileId, fieldName, '', { unlock: true })
					closeInlineEditor(false)
				})
				listEl.appendChild(clearOpt)
			}

			// Who it holds now, above the search results.
			const current = accountsOf(currentValue)
			current.forEach((person) => listEl.appendChild(option(person)))
			const kept = listEl.children.length
			let searchTimer = null

			const doSearch = (query) => {
				while (listEl.children.length > kept) {
					listEl.removeChild(listEl.lastChild)
				}
				if (query.length < 2) return

				axios.get(generateUrl('/apps/metavox/api/accounts'), { params: { search: query, kinds: takes.kinds.join(',') } }).then(resp => {
					if (searchInput.value.trim() !== query) return
					while (listEl.children.length > kept) {
						listEl.removeChild(listEl.lastChild)
					}
					const found = Array.isArray(resp.data) ? resp.data : []
					rememberAccountNames(found)
					const shown = new Set(current.map((person) => person.id))
					const people = found.filter((person) => !shown.has(person.id))
					if (people.length === 0 && found.length === 0) {
						const empty = document.createElement('div')
						empty.className = 'metavox-select-option metavox-user-empty'
						// TRANSLATORS Shown in the search list of a People field when nothing matches the search
						empty.textContent = t('metavox', 'No one found')
						listEl.appendChild(empty)
						return
					}
					for (const person of people) {
						listEl.appendChild(option({ id: person.id, kind: person.kind, label: person.name || person.id, known: true }))
					}
				}).catch(() => {})
			}

			searchInput.addEventListener('input', () => {
				clearTimeout(searchTimer)
				searchTimer = setTimeout(() => doSearch(searchInput.value.trim()), 300)
			})

			if (several) {
				const btnRow = document.createElement('div')
				btnRow.className = 'metavox-ms-actions'
				const saveBtn = document.createElement('button')
				saveBtn.textContent = '\u2713'
				// TRANSLATORS Verb: tooltip and accessible name of the check-mark button that saves the cell value
				saveBtn.title = t('metavox', 'Save')
				saveBtn.setAttribute('aria-label', saveBtn.title)
				saveBtn.className = 'metavox-ms-save'
				saveBtn.addEventListener('click', () => {
					saveSingleField(fileId, fieldName, [...chosen].join(';#'), { unlock: true })
					closeInlineEditor(false)
				})
				const cancelBtn = document.createElement('button')
				cancelBtn.textContent = '\u2715'
				// TRANSLATORS Verb: tooltip and accessible name of the cross button that closes the cell editor without saving
				cancelBtn.title = t('metavox', 'Cancel')
				cancelBtn.setAttribute('aria-label', cancelBtn.title)
				cancelBtn.className = 'metavox-ms-cancel'
				cancelBtn.addEventListener('click', () => closeInlineEditor(true))
				btnRow.append(saveBtn, cancelBtn)
				container.appendChild(btnRow)
			}

			editor = container
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
			// TRANSLATORS Placeholder of the web address box in the cell editor; {example} is an example address, e.g. "Enter URL (https://example.com)"
			input.placeholder = t('metavox', 'Enter URL ({example})', { example: 'https://example.com' }, undefined, PLAIN_TEXT)
			container.appendChild(input)

			const openBtn = document.createElement('a')
			openBtn.className = 'metavox-url-open'
			openBtn.textContent = '↗'
			// The glyph is not a name and `title` is not reliably announced
			// (§4), so without this the link had no accessible name.
			// TRANSLATORS Accessible name of the small button that opens the entered web address in a new tab
			openBtn.setAttribute('aria-label', t('metavox', 'Open link'))
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
			break
		}

		case 'filelink': {
			// File picker — open NC file picker, append selected path. The backend
			// normalises a bare path to "<fileid>:path" on save. A File Link field
			// holds one or more files; "Add file" appends, "Clear" empties.
			const container = document.createElement('div')
			container.className = 'metavox-inline-editor metavox-inline-filelink'

			const fileTokens = parseValue(currentValue)

			// Existing references (open by fileid when available).
			fileTokens.forEach((token) => {
				const { label, title, missing } = filelinkLabel(token)

				const openBtn = document.createElement(missing ? 'span' : 'a')
				openBtn.className = missing ? 'metavox-filelink-open metavox-filelink-missing' : 'metavox-filelink-open'
				openBtn.textContent = label
				openBtn.title = title
				if (!missing) {
					openBtn.target = '_blank'
					openBtn.rel = 'noopener noreferrer'
					openBtn.href = filelinkHref(token)
				}
				container.appendChild(openBtn)
			})

			if (fileTokens.length === 0) {
				const pathDisplay = document.createElement('span')
				pathDisplay.className = 'metavox-filelink-path'
				// TRANSLATORS Shown in the cell editor of a file-link field when no file is linked yet
				pathDisplay.textContent = t('metavox', 'No files selected')
				container.appendChild(pathDisplay)
			}

			const browseBtn = document.createElement('button')
			browseBtn.className = 'metavox-filelink-browse'
			// TRANSLATORS Verb: button in the cell editor of a file-link field that opens the file picker; keep it short
			browseBtn.textContent = t('metavox', 'Add file')
			browseBtn.addEventListener('click', () => {
				if (typeof OC !== 'undefined' && OC.dialogs) {
					// Start in the team folder: a link must point inside it.
					const base = getActiveFolderPath()
					OC.dialogs.filepicker(
						// TRANSLATORS Title of the file picker dialog for a file-link field
						t('metavox', 'Select a file or folder'),
						(path) => {
							if (path && !isWithin(path, base)) {
								// TRANSLATORS Warning when a picked file lies outside the current folder; "Team folder" is the Nextcloud feature name, use its official translation
								showWarning(t('metavox', 'You can only link files inside this Team folder'))
							} else if (path) {
								// Append the picked file to the existing set.
								const newValue = joinTokens([...fileTokens, { fileId: null, path }])
								saveSingleField(fileId, fieldName, newValue, { unlock: true })
								closeInlineEditor(false)
							}
						},
						false, undefined, true,
						OC.dialogs.FILEPICKER_TYPE_CHOOSE,
						base || '/',
						{ allowDirectoryChooser: true }
					)
				}
			})
			container.appendChild(browseBtn)

			if (fileTokens.length > 0) {
				const clearBtn = document.createElement('button')
				clearBtn.className = 'metavox-filelink-clear'
				clearBtn.textContent = '✕'
				// TRANSLATORS Verb: accessible name of the cross button that removes all linked files from the cell
				clearBtn.setAttribute('aria-label', t('metavox', 'Clear'))
				clearBtn.addEventListener('click', () => {
					saveSingleField(fileId, fieldName, '', { unlock: true })
					closeInlineEditor(false)
				})
				container.appendChild(clearBtn)
			}

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

	// Replace cell content with editor. No highlight while it is edited:
	// closing the editor paints the cell again.
	td.textContent = ''
	clearCellFormat(td)
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

	// Focus the editor so its keys reach it: the field in it, or else the
	// dropdown itself. Without scrolling: a scroll closes a dropdown.
	const focusTarget = editor.matches('input') ? editor : (editor.querySelector('input') || editor)
	focusTarget.focus({ preventScroll: true })
	if (focusTarget === editor && editor.type === 'text') editor.select()
	document.addEventListener('keydown', handleEditorKeydown)

	// Close on click outside
	setTimeout(() => {
		document.addEventListener('mousedown', handleEditorClickOutside)
	}, 0)

	// Close portaled dropdowns on scroll (they don't follow the page)
	if (isDropdown) {
		const scrollHandler = (e) => {
			// The dropdown scrolling its own list (wheel, Tab or arrow keys) keeps it open.
			if (e.target === editor || editor.contains(e.target)) return
			closeInlineEditor(true, { refocus: editor.contains(document.activeElement) })
		}
		// Listen on capture phase to catch scroll on any container
		document.addEventListener('scroll', scrollHandler, { capture: true })
		td._scrollHandler = scrollHandler
	}
}

/** Whether a target is in the open editor: its cell or its dropdown. */
function inEditor(target) {
	return !!activeEditor && (activeEditor.contains(target) || !!activeEditor._portalEditor?.contains(target))
}

/**
 * Escape cancels the edit from anywhere in the editor. Keys typed in the
 * editor stay there: the file list would deselect the row on Escape, rename
 * the file on F2. A dialog opened from the editor (the file picker) keeps
 * its own keys.
 *
 * @param {KeyboardEvent} e
 */
export function handleEditorKeydown(e) {
	if (!activeEditor) return
	if (e.key === 'Escape') {
		if (e.isComposing || (!inEditor(e.target) && e.target !== document.body)) return
		e.preventDefault()
		e.stopPropagation()
		closeInlineEditor(true, { refocus: true })
		return
	}
	if (inEditor(e.target)) e.stopPropagation()
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
	// A dialog over the page, such as the file picker opened from the editor,
	// is part of the edit: picking a file there must not cancel it.
	if (!inEditor(e.target) && !e.target.closest?.('[role="dialog"]')) {
		closeInlineEditor(true)
		document.removeEventListener('mousedown', handleEditorClickOutside)
	}
}

/**
 * Release this user's lock on a cell. A beacon, so it also arrives while the
 * window blurs or closes.
 *
 * @param {number} gfId
 * @param {number} fileId
 * @param {string} fieldName
 */
function releaseLock(gfId, fileId, fieldName) {
	if (!gfId || !fileId || !fieldName) return
	const url = generateUrl('/apps/metavox/api/groupfolders/{gfId}/files/{fileId}/unlock', { gfId, fileId })
	const token = document.querySelector('head[data-requesttoken]')?.dataset?.requesttoken || ''
	const formData = new FormData()
	formData.append('field_name', fieldName)
	formData.append('requesttoken', token)
	navigator.sendBeacon(url, formData)
}

/**
 * Close the currently active inline editor.
 *
 * @param {boolean} cancel If true, restore original cell content instead of
 *                         re-rendering from cache.
 * @param {object} [options]
 * @param {boolean} [options.refocus] Put the focus back on the cell.
 */
export function closeInlineEditor(cancel, { refocus = false } = {}) {
	if (!activeEditor) return
	const td = activeEditor
	activeEditor = null
	td._cancelFill?.()

	document.removeEventListener('mousedown', handleEditorClickOutside)
	document.removeEventListener('keydown', handleEditorKeydown)

	// Release lock only on cancel — on save, the lock is released via combined save+unlock
	if (cancel) {
		releaseLock(getActiveGroupfolderId(), Number(td.dataset?.fileId), td.dataset?.metavoxField)
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

	// Always re-render from cache (restores rich content like avatars, link buttons).
	// On cancel too: a save by someone else while the editor was open is newer
	// than the value it opened with, and the redraws skipped this cell.
	const fileId2 = Number(td.dataset.fileId)
	const fieldName2 = td.dataset.metavoxField
	const activeColumnConfigs = _getActiveColumnConfigs ? _getActiveColumnConfigs() : []
	const config2 = activeColumnConfigs.find(c => c.field_name === fieldName2)
	const meta2 = metadataCache.get(fileId2)
	if (config2 && _setCellValue) {
		_setCellValue(td, meta2 ? meta2[fieldName2] : td._originalValue, config2)
	}

	delete td._originalContent
	delete td._originalValue
	td.style.position = ''
	// Back on the cell, so the keyboard stays where it was.
	if (refocus && td.isConnected) td.focus({ preventScroll: true })
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
	// Each highlighted cell with the file it showed when it was highlighted.
	let highlightedCells = []
	let value = ''

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

	function clearHighlights() {
		for (const { cell } of highlightedCells) cell.classList.remove('metavox-fill-highlight')
		highlightedCells = []
	}

	function highlightTo(targetY) {
		clearHighlights()
		const cellsBelow = getCellsBelow(sourceTd, fieldName)
		for (const cell of cellsBelow) {
			const rect = cell.getBoundingClientRect()
			if (rect.top < targetY) {
				cell.classList.add('metavox-fill-highlight')
				highlightedCells.push({ cell, fileId: Number(cell.dataset.fileId) })
			} else {
				break
			}
		}
	}

	function onMouseMove(e) {
		e.preventDefault()
		highlightTo(e.clientY)
	}

	function stopDrag() {
		document.removeEventListener('mousemove', onMouseMove)
		document.removeEventListener('mouseup', onMouseUp)
		delete sourceTd._cancelFill
	}

	async function onMouseUp(e) {
		stopDrag()

		// A row the file list handed to another file during the drag, or one no
		// longer editable, is left out.
		const cells = []
		for (const { cell, fileId } of highlightedCells) {
			if (cell.isConnected !== false && Number(cell.dataset.fileId) === fileId && canEditFile(fileId)) {
				cells.push(cell)
			} else {
				cell.classList.remove('metavox-fill-highlight')
			}
		}
		highlightedCells = []
		if (cells.length === 0) return

		// Undo keeps the old values, so those must be the full ones.
		if (!(await requireFull(cells.map((cell) => Number(cell.dataset.fileId)), fieldName))) {
			cells.forEach((cell) => cell.classList.remove('metavox-fill-highlight'))
			showError(t('metavox', 'The full values could not be loaded. Nothing was filled.'))
			return
		}

		// Collect file IDs and old values, then save in one bulk call
		const activeColumnConfigs = _getActiveColumnConfigs ? _getActiveColumnConfigs() : []
		const undoEntries = []
		const fileIds = []
		for (const cell of cells) {
			cell.classList.remove('metavox-fill-highlight')
			const fileId = Number(cell.dataset.fileId)
			const meta = metadataCache.get(fileId) || {}
			undoEntries.push({ fileId, fieldName, oldValue: meta[fieldName] })
			fileIds.push(fileId)
			const cellConfig = activeColumnConfigs.find(c => c.field_name === fieldName)
			if (cellConfig && _setCellValue) _setCellValue(cell, value, cellConfig)
		}

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
		// Taken now: the editor may close during the drag.
		const editorEl = sourceTd.querySelector('input, select')
		value = editorEl ? editorEl.value : sourceTd._originalValue || ''
		// Closing the editor ends the drag, so a fill never outlives its source.
		sourceTd._cancelFill = () => {
			stopDrag()
			clearHighlights()
		}
		document.addEventListener('mousemove', onMouseMove)
		document.addEventListener('mouseup', onMouseUp)
	})
}
