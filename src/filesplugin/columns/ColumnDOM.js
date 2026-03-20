/**
 * MetaVox Column DOM — header/footer/row column injection and cell rendering.
 *
 * Extracted from MetaVoxColumns.js
 */

import { MARKER_CLASS, HEADER_MARKER, RESIZE_HANDLE } from './ColumnStyles.js'
import { getActiveColumnConfigs, metadataCache, getCachedActionsWidth, setCachedActionsWidth } from './MetaVoxState.js'
import { formatValue, getColWidth } from './ColumnUtils.js'
import { getFilterInstance } from './MetadataFilter.js'

// Late-bound dependencies to avoid circular imports
let queueMetadataLoad = () => {}
let setupCellEditing = () => {}
let startResize = () => {}
let handleSort = () => {}

export function setDependencies(deps) {
	if (deps.queueMetadataLoad) queueMetadataLoad = deps.queueMetadataLoad
	if (deps.setupCellEditing) setupCellEditing = deps.setupCellEditing
	if (deps.startResize) startResize = deps.startResize
	if (deps.handleSort) handleSort = deps.handleSort
}

// ── SVG sort icons ─────────────────────────────────────────────

const SORT_ICON_ASC = '<svg fill="currentColor" width="24" height="24" viewBox="0 0 24 24"><path d="M7,15L12,10L17,15H7Z"></path></svg>'
const SORT_ICON_DESC = '<svg fill="currentColor" width="24" height="24" viewBox="0 0 24 24"><path d="M7,10L12,15L17,10H7Z"></path></svg>'

export { SORT_ICON_ASC, SORT_ICON_DESC }

// ── Actions width sync ─────────────────────────────────────────

/**
 * Sync the header actions cell width with the data row actions cell.
 * NC33 uses display:flex on rows; the actions cell is 0px in the header
 * but ~150px in data rows, causing all columns after it to misalign.
 */
export function _syncActionsWidth(theadTr) {
	const hActions = theadTr.querySelector('.files-list__row-actions')
	if (!hActions) return

	if (getCachedActionsWidth() !== null) {
		hActions.style.minWidth = getCachedActionsWidth() + 'px'
		hActions.style.width = getCachedActionsWidth() + 'px'
		hActions.style.flexShrink = '0'
		return
	}

	const dataRow = document.querySelector('.files-list__table tbody tr')
	if (!dataRow) return

	const dActions = dataRow.querySelector('.files-list__row-actions')
	if (!dActions) return

	const dWidth = dActions.getBoundingClientRect().width
	if (dWidth > 0) {
		setCachedActionsWidth(dWidth)
		hActions.style.minWidth = dWidth + 'px'
		hActions.style.width = dWidth + 'px'
		hActions.style.flexShrink = '0'
	}
}

// ── Header columns ─────────────────────────────────────────────

export function injectHeaderColumns() {
	const theadTr = document.querySelector('.files-list__table thead tr')
	if (!theadTr) return

	theadTr.querySelectorAll('.' + HEADER_MARKER).forEach(el => el.remove())

	// Fix alignment: sync header actions cell width with data row actions cell
	_syncActionsWidth(theadTr)

	const headerFrag = document.createDocumentFragment()
	for (const config of getActiveColumnConfigs()) {
		const th = document.createElement('th')
		th.className = `files-list__column ${HEADER_MARKER} files-list__column--sortable`
		const w = getColWidth(config.field_name, config.field_type, config.field_label)
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

	// Defer layout read to after DOM writes complete
	requestAnimationFrame(() => updateTableMinWidth())
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
		const w = getColWidth(config.field_name, config.field_type, config.field_label)
		td.style.width = w + 'px'
		td.style.minWidth = w + 'px'
		td.style.maxWidth = w + 'px'
		footerFrag.appendChild(td)
	}
	tfootTr.appendChild(footerFrag)
}

// ── Row cell refresh ───────────────────────────────────────────

export function _refreshRowCells(row) {
	const fileId = Number(row.getAttribute('data-cy-files-list-row-fileid'))
	const meta = metadataCache.get(fileId)
	const cells = row.querySelectorAll('.' + MARKER_CLASS)

	if (cells.length === 0) {
		injectRowColumns(row)
		return
	}

	for (const cell of cells) {
		const fieldName = cell.dataset.metavoxField
		const config = getActiveColumnConfigs().find(c => c.field_name === fieldName)
		if (!config) continue
		cell.dataset.fileId = fileId
		if (meta) {
			setCellValue(cell, meta[fieldName], config)
		} else {
			cell.textContent = '\u2026'
			cell.classList.add(MARKER_CLASS + '--empty')
			cell.style.backgroundColor = ''
			if (fileId) queueMetadataLoad(fileId)
		}
	}
}

// ── Row column injection ───────────────────────────────────────

export function injectRowColumns(row) {
	if (row.querySelector('.' + MARKER_CLASS)) return

	const fileId = Number(row.getAttribute('data-cy-files-list-row-fileid'))
	const meta = metadataCache.get(fileId)

	const rowFrag = document.createDocumentFragment()
	for (const config of getActiveColumnConfigs()) {
		const td = document.createElement('td')
		td.className = `files-list__column ${MARKER_CLASS}`
		const w = getColWidth(config.field_name, config.field_type, config.field_label)
		td.style.width = w + 'px'
		td.style.minWidth = w + 'px'
		td.style.maxWidth = w + 'px'
		td.dataset.metavoxField = config.field_name
		td.dataset.fileId = fileId

		if (meta) {
			setCellValue(td, meta[config.field_name], config)
		} else {
			td.textContent = '\u2026'
			td.classList.add(MARKER_CLASS + '--empty')
			if (fileId) queueMetadataLoad(fileId)
		}

		// Enable inline editing on double-click
		setupCellEditing(td, config)

		rowFrag.appendChild(td)
	}
	row.appendChild(rowFrag)
}

// ── Cell value rendering ───────────────────────────────────────

export function setCellValue(td, value, config) {
	if (value !== undefined && value !== null && value !== '') {
		const formatted = formatValue(value, config.field_type)
		if (formatted !== '') {
			td.textContent = formatted
			td.title = formatted
			td.classList.remove(MARKER_CLASS + '--empty')
			return
		}
	}
	td.textContent = '\u2014'
	td.title = ''
	td.classList.add(MARKER_CLASS + '--empty')
}

// ── Bulk row updates ───────────────────────────────────────────

export function updateAllRowCells() {
	const rows = document.querySelectorAll('tr[data-cy-files-list-row]')
	for (const row of rows) {
		const fileId = Number(row.getAttribute('data-cy-files-list-row-fileid'))
		const meta = metadataCache.get(fileId)
		if (!meta) continue

		const cells = row.querySelectorAll('.' + MARKER_CLASS)
		for (const cell of cells) {
			const fieldName = cell.dataset.metavoxField
			const config = getActiveColumnConfigs().find(c => c.field_name === fieldName)
			if (!config) continue
			setCellValue(cell, meta[fieldName], config)
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
	document.querySelectorAll('.' + MARKER_CLASS + ', .' + HEADER_MARKER).forEach(el => el.remove())
	const table = document.querySelector('.files-list__table')
	if (table) table.style.minWidth = ''
	const filesList = document.querySelector('.files-list')
	if (filesList) filesList.style.minWidth = ''
}

// ── Table min-width ────────────────────────────────────────────

export function updateTableMinWidth() {
	const table = document.querySelector('.files-list__table')
	if (!table) return
	const headerRow = table.querySelector('.files-list__row-head')
	if (!headerRow) return

	// Calculate total width including flex gaps by measuring the last child's extent
	const lastChild = headerRow.lastElementChild
	const totalWidth = lastChild ? lastChild.offsetLeft + lastChild.offsetWidth : 0

	if (totalWidth > 0) {
		table.style.minWidth = totalWidth + 'px'
		// Set min-width on files-list so #app-content-vue scrollWidth expands
		const filesList = document.querySelector('.files-list')
		if (filesList) filesList.style.minWidth = totalWidth + 'px'
	}
}
