/**
 * MetaVox Column DOM — header/footer/row column injection and cell rendering.
 *
 * Extracted from MetaVoxColumns.js
 */

import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { MARKER_CLASS, HEADER_MARKER, RESIZE_HANDLE } from './ColumnStyles.js'
import { getActiveColumnConfigs, metadataCache } from './MetaVoxState.js'
import { filelinkHref, filelinkLabel, requestFilelinkName } from './FilelinkNames.js'
import { parseValue } from '../../components/fields/filelinkUtils.js'
import { formatValue, getColWidth } from './ColumnUtils.js'
import { getFilterInstance } from './MetadataFilter.js'
import { getListingGroupfolderId, getListingNode, isListingLoading } from './ListingSync.js'
import { isTruncated, seedFromNode } from './MetadataStore.js'
import { RULES_ICON_PATH, applyCellFormat, clearCellFormat, compiledFor, ruleNames, svgIcon } from './CellFormat.js'
import { PLAIN_TEXT } from '../../utils/fieldValue.js'
import { missingReason } from '../../utils/requiredFields.js'

// Late-bound dependencies to avoid circular imports
let queueMetadataLoad = () => {}
let setupCellEditing = () => {}
let updateCellAccessibleName = () => {}
let startResize = () => {}
let handleSort = () => {}
let getActiveEditorCell = () => null
let closeInlineEditor = () => {}

export function setDependencies(deps) {
	if (deps.queueMetadataLoad) queueMetadataLoad = deps.queueMetadataLoad
	if (deps.setupCellEditing) setupCellEditing = deps.setupCellEditing
	if (deps.updateCellAccessibleName) updateCellAccessibleName = deps.updateCellAccessibleName
	if (deps.startResize) startResize = deps.startResize
	if (deps.handleSort) handleSort = deps.handleSort
	if (deps.getActiveEditorCell) getActiveEditorCell = deps.getActiveEditorCell
	if (deps.closeInlineEditor) closeInlineEditor = deps.closeInlineEditor
}

// Head, cells and foot alike: a column with highlights has room for its sign.
const widthOf = (config) => getColWidth(config.field_name, config.field_type, config.field_label, compiledFor(config) ? 20 : 0)

/** Column configs by field name, looked up once per redraw. */
const configsByField = () => new Map(getActiveColumnConfigs().map((c) => [c.field_name, c]))

// ── SVG sort icons ─────────────────────────────────────────────

const SORT_ICON_ASC = '<svg fill="currentColor" width="24" height="24" viewBox="0 0 24 24"><path d="M7,15L12,10L17,15H7Z"></path></svg>'
const SORT_ICON_DESC = '<svg fill="currentColor" width="24" height="24" viewBox="0 0 24 24"><path d="M7,10L12,15L17,10H7Z"></path></svg>'

export { SORT_ICON_ASC, SORT_ICON_DESC }

// ── Header columns ─────────────────────────────────────────────
//
// Column alignment (header ↔ data rows) is handled entirely in CSS: the actions
// cell is pinned to one fixed width for the header and every data row (see the
// nc32Styles block in ColumnStyles.js), so all our columns share the same x.
// No JS baseline measurement is needed — a JS-written inline width would only
// fight that !important CSS rule.

export function injectHeaderColumns() {
	const theadTr = document.querySelector('.files-list__table thead tr')
	if (!theadTr) return

	theadTr.querySelectorAll('.' + HEADER_MARKER).forEach(el => el.remove())

	const headerFrag = document.createDocumentFragment()
	for (const [index, config] of getActiveColumnConfigs().entries()) {
		const th = document.createElement('th')
		th.className = `files-list__column ${HEADER_MARKER} files-list__column--sortable`
		const w = widthOf(config)
		th.style.width = w + 'px'
		th.style.minWidth = w + 'px'
		th.style.maxWidth = w + 'px'
		th.dataset.metavoxField = config.field_name

		// Button matching NC33's native sort button structure
		const btn = document.createElement('button')
		btn.type = 'button'
		btn.title = config.field_label
		btn.className = 'files-list__column-sort-button button-vue button-vue--size-normal button-vue--icon-and-text button-vue--vue-tertiary button-vue--tertiary button-vue--start button-vue--reverse'

		const wrapper = document.createElement('span')
		wrapper.className = 'button-vue__wrapper'

		// Sort icon
		const iconSpan = document.createElement('span')
		iconSpan.className = 'button-vue__icon'
		iconSpan.setAttribute('aria-hidden', 'true')
		const iconInner = document.createElement('span')
		iconInner.className = 'material-design-icon menu-up-icon files-list__column-sort-button-icon'
		iconInner.setAttribute('aria-hidden', 'true')
		iconInner.setAttribute('role', 'img')
		const _sortState = getFilterInstance()?.getSortState()
		if (_sortState?.fieldName === config.field_name) {
			iconInner.innerHTML = _sortState.direction === 'asc' ? SORT_ICON_ASC : SORT_ICON_DESC
		} else {
			iconInner.innerHTML = SORT_ICON_ASC
			iconInner.style.opacity = '0'
		}
		iconSpan.appendChild(iconInner)
		wrapper.appendChild(iconSpan)

		// Label text
		const textSpan = document.createElement('span')
		textSpan.className = 'button-vue__text'
		const labelSpan = document.createElement('span')
		labelSpan.className = 'files-list__column-sort-button-text'
		labelSpan.textContent = config.field_label
		textSpan.appendChild(labelSpan)
		wrapper.appendChild(textSpan)

		btn.appendChild(wrapper)

		// A column that highlights: a neutral sign for sighted users, and the
		// names of its rules for a screen reader, without a tooltip.
		const names = ruleNames(config)
		if (names.length) {
			const sign = document.createElement('span')
			sign.className = 'metavox-col-rules-icon'
			sign.setAttribute('aria-hidden', 'true')
			sign.appendChild(svgIcon(RULES_ICON_PATH))
			textSpan.appendChild(sign)
			// The label may be cut off; the sign stays.
			textSpan.classList.add('metavox-col-has-rules')

			// Hidden, not only out of sight: inside the th it would otherwise
			// become part of the column's name. By position: a field name may
			// hold a space, which aria-describedby would read as two ids.
			const description = document.createElement('span')
			description.id = 'metavox-rules-' + index
			description.hidden = true
			// TRANSLATORS Screen-reader description of a column header; {names} is a comma-separated list of the column's highlight rules, e.g. "Highlights: Overdue, Due soon"
			description.textContent = t('metavox', 'Highlights: {names}', { names: names.join(', ') }, undefined, PLAIN_TEXT)
			th.appendChild(description)
			btn.setAttribute('aria-describedby', description.id)
		}

		btn.addEventListener('click', () => {
			handleSort(config.field_name, config.field_type)
		})

		th.appendChild(btn)

		// Resize handle
		const handle = document.createElement('div')
		handle.className = RESIZE_HANDLE
		handle.addEventListener('mousedown', (e) => {
			e.preventDefault()
			e.stopPropagation()
			startResize(e, th, config.field_name)
		})
		th.appendChild(handle)

		headerFrag.appendChild(th)
	}
	theadTr.appendChild(headerFrag)

	// Defer layout reads until after DOM writes, then size the table for
	// horizontal scroll. (Alignment is CSS-only now — see ColumnStyles.js.)
	requestAnimationFrame(() => {
		updateTableMinWidth()
	})
}

// ── Footer columns ─────────────────────────────────────────────

export function injectFooterColumns() {
	const tfootTr = document.querySelector('.files-list__table tfoot tr')
	if (!tfootTr) return

	tfootTr.querySelectorAll('.' + MARKER_CLASS).forEach(el => el.remove())

	const footerFrag = document.createDocumentFragment()
	for (const config of getActiveColumnConfigs()) {
		const td = document.createElement('td')
		td.className = MARKER_CLASS
		const w = widthOf(config)
		td.style.width = w + 'px'
		td.style.minWidth = w + 'px'
		td.style.maxWidth = w + 'px'
		footerFrag.appendChild(td)
	}
	tfootTr.appendChild(footerFrag)
}

// ── Row cell refresh ───────────────────────────────────────────

// A row's values: from the store, else from its listed node, else from the
// API (never while the Files app still shows the previous folder's nodes).
function metaFor(fileId) {
	if (fileId && !metadataCache.has(fileId)) {
		const node = getListingNode(fileId)
		if (node) {
			seedFromNode(node, getListingGroupfolderId())
		}
		if (!metadataCache.has(fileId) && !isListingLoading()) {
			queueMetadataLoad([fileId])
		}
	}
	return metadataCache.get(fileId)
}

export function _refreshRowCells(row) {
	const fileId = Number(row.getAttribute('data-cy-files-list-row-fileid'))
	const meta = metaFor(fileId)
	const cells = row.querySelectorAll('.' + MARKER_CLASS)

	if (cells.length === 0) {
		injectRowColumns(row)
		return
	}

	const configs = configsByField()
	for (const cell of cells) {
		const config = configs.get(cell.dataset.metavoxField)
		if (!config) continue
		// The file list reuses rows while scrolling: nothing of the file before
		// may stay on the cell, not its open editor and not its lock.
		if (Number(cell.dataset.fileId) !== fileId) {
			if (getActiveEditorCell() === cell) {
				closeInlineEditor(true)
			}
			clearCellLock(cell)
			cell.classList.remove('metavox-fill-highlight')
			// A read-only file before may have taken the cell out of the tab order.
			if (cell.classList.contains('metavox-cell-editable')) {
				cell.tabIndex = 0
				cell.style.cursor = 'pointer'
			}
		}
		cell.dataset.fileId = fileId
		if (meta) {
			setCellValue(cell, meta[config.field_name], config)
		} else {
			paintPlaceholder(cell, config)
		}
	}
}

/**
 * Take the "being edited by someone else" state off a cell.
 *
 * @param {HTMLElement} cell
 */
export function clearCellLock(cell) {
	if (!cell._metavoxLocked && !cell.dataset.lockedBy && !cell._lockBadge) return
	cell.style.backgroundColor = ''
	cell.style.boxShadow = ''
	// Back to what setupCellEditing gave it.
	cell.style.cursor = cell.classList.contains('metavox-cell-editable') ? 'pointer' : ''
	cell.style.position = ''
	cell.style.overflow = ''
	delete cell.dataset.lockedBy
	delete cell._metavoxLocked
	if (cell._lockBadge) {
		cell._lockBadge.remove()
		delete cell._lockBadge
	}
	if (cell._lockScrollHandler) {
		document.removeEventListener('scroll', cell._lockScrollHandler, { capture: true })
		delete cell._lockScrollHandler
	}
}

// ── Row column injection ───────────────────────────────────────

export function injectRowColumns(row) {
	if (row.querySelector('.' + MARKER_CLASS)) return

	const fileId = Number(row.getAttribute('data-cy-files-list-row-fileid'))
	const meta = metaFor(fileId)

	const rowFrag = document.createDocumentFragment()
	for (const config of getActiveColumnConfigs()) {
		const td = document.createElement('td')
		td.className = `files-list__column ${MARKER_CLASS}`
		const w = widthOf(config)
		td.style.width = w + 'px'
		td.style.minWidth = w + 'px'
		td.style.maxWidth = w + 'px'
		td.dataset.metavoxField = config.field_name
		td.dataset.fileId = fileId

		if (meta) {
			setCellValue(td, meta[config.field_name], config)
		} else {
			paintPlaceholder(td, config)
		}

		// Enable inline editing on double-click
		setupCellEditing(td, config)

		rowFrag.appendChild(td)
	}
	row.appendChild(rowFrag)
}

// ── Cell value rendering ───────────────────────────────────────

export function setCellValue(td, value, config) {
	// The content below replaces what was there in one write: while scrolling,
	// clearing first doubled the changes per cell.
	td.title = ''
	renderCellContent(td, value, config)
	markRequired(td, value, config)
	applyCellFormat(td, value, config)
	// The cell's accessible name quotes its value and state, so it is rebuilt
	// on every way out — otherwise a screen reader keeps reading what was there before.
	updateCellAccessibleName(td, config)
}

/** The ellipsis of a row whose values have not arrived yet. */
function paintPlaceholder(td, config) {
	td.textContent = '…'
	td.title = ''
	td.classList.add(MARKER_CLASS + '--empty')
	delete td.dataset.mvRequired
	clearCellFormat(td)
	updateCellAccessibleName(td, config)
}

/** A required field without its value stands out, and says why. */
function markRequired(td, value, config) {
	if (missingReason(config, value) === '') {
		delete td.dataset.mvRequired
		return
	}
	td.dataset.mvRequired = '1'
	// TRANSLATORS Tooltip on a grid cell of a required field that has no value yet
	td.title = t('metavox', 'Required, no value yet')
}

function renderCellContent(td, value, config) {
	if (value === undefined || value === null || value === '') {
		td.textContent = '\u2014'
		td.classList.add(MARKER_CLASS + '--empty')
		return
	}

	td.classList.remove(MARKER_CLASS + '--empty')

	// A cut-off value (over 512 characters) shows its start, and never acts as a
	// link: a shortened URL or file link could point somewhere else.
	if (isTruncated(Number(td.dataset.fileId), config.field_name)) {
		td.textContent = value + '\u2026'
		// TRANSLATORS Tooltip on a cell whose long value is cut off in the grid
		td.title = t('metavox', 'Shortened. The full value loads when you edit the cell.')
		return
	}

	switch (config.field_type) {
	case 'user': {
		const wrapper = document.createElement('span')
		wrapper.className = 'metavox-cell-user'

		const avatar = document.createElement('img')
		avatar.className = 'metavox-cell-avatar'
		avatar.src = generateUrl('/avatar/{userId}/20', { userId: value })
		avatar.width = 20
		avatar.height = 20
		avatar.onerror = () => { avatar.style.display = 'none' }
		wrapper.appendChild(avatar)

		const name = document.createElement('span')
		name.textContent = value
		wrapper.appendChild(name)

		td.replaceChildren(wrapper)
		td.title = value
		break
	}
	case 'url': {
		const wrapper = document.createElement('span')
		wrapper.className = 'metavox-cell-url'

		const text = document.createElement('span')
		text.className = 'metavox-cell-url-text'
		text.textContent = value
		wrapper.appendChild(text)

		const link = document.createElement('a')
		link.className = 'metavox-cell-link-btn'
		link.href = /^https?:\/\//i.test(value) ? value : 'https://' + value
		link.target = '_blank'
		link.rel = 'noopener noreferrer'
		link.textContent = '↗'
		link.title = value
		// TRANSLATORS Accessible name of the small button in a cell that opens the link in a new tab
		link.setAttribute('aria-label', t('metavox', 'Open link'))
		link.addEventListener('click', (e) => e.stopPropagation())
		wrapper.appendChild(link)

		td.replaceChildren(wrapper)
		td.title = value
		break
	}
	case 'filelink': {
		const wrapper = document.createElement('span')
		wrapper.className = 'metavox-cell-filelink'

		const tokens = parseValue(value)
		const titles = []
		tokens.forEach((token, idx) => {
			if (idx > 0) {
				const sep = document.createElement('span')
				sep.className = 'metavox-cell-filelink-sep'
				sep.textContent = ', '
				wrapper.appendChild(sep)
			}

			if (token.fileId != null) requestFilelinkName(token.fileId)
			const { label, title, missing } = filelinkLabel(token)
			titles.push(title)

			// A file that is gone or hidden to you is no link.
			const link = document.createElement(missing ? 'span' : 'a')
			link.className = missing
				? 'metavox-cell-filelink-text metavox-cell-filelink-missing'
				: 'metavox-cell-filelink-text metavox-cell-link-btn'
			link.textContent = label
			link.title = title
			if (!missing) {
				link.target = '_blank'
				link.rel = 'noopener noreferrer'
				link.href = filelinkHref(token)
				link.addEventListener('click', (e) => e.stopPropagation())
			}
			wrapper.appendChild(link)
		})

		td.replaceChildren(wrapper)
		td.title = titles.join(', ')
		break
	}
	default: {
		const formatted = formatValue(value, config.field_type, config)
		td.textContent = formatted || '\u2014'
		td.title = formatted || ''
		if (!formatted) td.classList.add(MARKER_CLASS + '--empty')
	}
	}
}

// ── Bulk row updates ───────────────────────────────────────────

export function updateAllRowCells() {
	const configs = configsByField()
	// Someone typing there: closing the editor draws that cell itself.
	const editing = getActiveEditorCell()
	const rows = document.querySelectorAll('tr[data-cy-files-list-row]')
	for (const row of rows) {
		const fileId = Number(row.getAttribute('data-cy-files-list-row-fileid'))
		const meta = metadataCache.get(fileId)
		if (!meta) continue

		const cells = row.querySelectorAll('.' + MARKER_CLASS)
		for (const cell of cells) {
			const config = configs.get(cell.dataset.metavoxField)
			if (!config || cell === editing) continue
			setCellValue(cell, meta[config.field_name], config)
		}
	}
}

// ── Inject / remove all ────────────────────────────────────────

export function injectAllExistingRows() {
	const rows = document.querySelectorAll('tr[data-cy-files-list-row]')
	for (const row of rows) {
		injectRowColumns(row)
	}
}

export function removeAllInjectedColumns() {
	document.querySelectorAll('.' + MARKER_CLASS + ', .' + HEADER_MARKER).forEach((el) => {
		// A lock badge hangs on the body, not in the cell: it goes too.
		clearCellLock(el)
		el.remove()
	})
	const table = document.querySelector('.files-list__table')
	if (table) table.style.minWidth = ''
	const filesList = document.querySelector('.files-list')
	if (filesList) filesList.style.minWidth = ''
}

// ── Table min-width ────────────────────────────────────────────

export function updateTableMinWidth() {
	const table = document.querySelector('.files-list__table')
	const filesList = document.querySelector('.files-list')

	// Nothing listed means no columns to make room for, and the width would
	// then only push Nextcloud's empty state sideways: it centres itself in
	// the list, so a 1771px list on a 963px viewport put "No files in here"
	// some 400px off to the right, half of it out of sight.
	const hasRows = !!document.querySelector('.files-list__row')
	if (!hasRows) {
		if (table) table.style.minWidth = ''
		if (filesList) filesList.style.minWidth = ''
		return
	}

	if (!table) return
	const headerRow = table.querySelector('.files-list__row-head')
	if (!headerRow) return

	// Calculate total width including flex gaps by measuring the last child's extent
	const lastChild = headerRow.lastElementChild
	const totalWidth = lastChild ? lastChild.offsetLeft + lastChild.offsetWidth : 0

	if (totalWidth > 0) {
		table.style.minWidth = totalWidth + 'px'
		// Set min-width on files-list so #app-content-vue scrollWidth expands
		if (filesList) filesList.style.minWidth = totalWidth + 'px'
	}
}
