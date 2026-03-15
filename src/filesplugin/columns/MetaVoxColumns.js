/**
 * MetaVox File List Columns — DOM Injection Approach
 *
 * Injects metadata columns directly into the NC33 Files app DOM via MutationObserver.
 * Features: resizable columns, click-to-sort, metadata bulk loading.
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { registerMetaVoxFilter, removeFilters, updateFilterCache } from './MetadataFilter.js'

/** @type {Array<Object>} Column configs from API */
let activeColumnConfigs = []

/** @type {number|null} Current groupfolder ID */
let activeGroupfolderId = null

/** @type {Map<number, Object>} File metadata cache: fileId -> { field_name: value } */
const metadataCache = new Map()

/** @type {boolean} Whether columns are currently injected */
let columnsActive = false

/** @type {MutationObserver|null} Observer for new file rows */
let rowObserver = null

/** @type {Set<number>} File IDs queued for metadata loading */
const loadQueue = new Set()

/** @type {number|null} Debounce timer for batch loading */
let loadTimer = null

/** @type {Object|null} Current sort state: { fieldName, direction } */
let currentSort = null

/** @type {Map<string, number>} Persisted column widths */
const columnWidths = new Map()

// ========================================
// Value Formatting
// ========================================

function formatValue(value, fieldType) {
	if (value === null || value === undefined || value === '') return ''

	switch (fieldType) {
	case 'checkbox':
		return value === '1' || value === 'true' || value === true ? '\u2713' : ''
	case 'date':
		try {
			const d = new Date(value)
			if (!isNaN(d.getTime())) return d.toLocaleDateString()
		} catch (e) { /* fall through */ }
		return value
	case 'multiselect':
		return value.replace(/;\s*/g, ', ')
	default:
		return String(value)
	}
}

function compareValues(a, b, fieldType) {
	const aVal = a ?? ''
	const bVal = b ?? ''
	if (aVal === bVal) return 0
	if (aVal === '') return 1
	if (bVal === '') return -1

	switch (fieldType) {
	case 'number':
		return parseFloat(aVal) - parseFloat(bVal)
	case 'date':
		return new Date(aVal).getTime() - new Date(bVal).getTime()
	case 'checkbox':
		return (aVal === '1' ? 0 : 1) - (bVal === '1' ? 0 : 1)
	default:
		return String(aVal).localeCompare(String(bVal))
	}
}

// ========================================
// API
// ========================================

async function fetchColumnConfig(groupfolderId) {
	try {
		const url = generateOcsUrl(
			'/apps/metavox/api/v1/groupfolders/{groupfolderId}/columns',
			{ groupfolderId },
		)
		const resp = await axios.get(url)
		return resp.data?.ocs?.data || resp.data || []
	} catch (e) {
		console.error('MetaVox: Failed to fetch column config', e)
		return []
	}
}

async function fetchDirectoryMetadata(groupfolderId, fileIds) {
	if (fileIds.length === 0) return {}
	try {
		const url = generateOcsUrl(
			'/apps/metavox/api/v1/groupfolders/{groupfolderId}/directory-metadata',
			{ groupfolderId },
		)
		const resp = await axios.get(url, { params: { file_ids: fileIds.join(',') } })
		return resp.data?.ocs?.data || resp.data || {}
	} catch (e) {
		console.error('MetaVox: Failed to fetch directory metadata', e)
		return {}
	}
}

async function loadGroupfolders() {
	if (window._metavoxGroupfolders) return
	try {
		const url = generateOcsUrl('/apps/metavox/api/v1/groupfolders')
		const resp = await axios.get(url)
		window._metavoxGroupfolders = resp.data?.ocs?.data || resp.data || []
	} catch (e) {
		console.error('MetaVox: Failed to load groupfolders', e)
		window._metavoxGroupfolders = []
	}
}

// ========================================
// Groupfolder Detection (NC33: ?dir= query param)
// ========================================

function detectCurrentGroupfolder() {
	const params = new URLSearchParams(window.location.search)
	let dir = params.get('dir')

	if (!dir || dir === '/') return null

	const path = dir.startsWith('/') ? dir.substring(1) : dir
	const gfs = window._metavoxGroupfolders || []

	for (const gf of gfs) {
		if (path === gf.mount_point || path.startsWith(gf.mount_point + '/')) {
			return gf.id
		}
	}
	return null
}

// ========================================
// Metadata Loading (Bulk, Debounced)
// ========================================

function queueMetadataLoad(fileId) {
	if (metadataCache.has(fileId)) return
	loadQueue.add(fileId)

	if (loadTimer) clearTimeout(loadTimer)
	loadTimer = setTimeout(flushLoadQueue, 100)
}

async function flushLoadQueue() {
	if (loadQueue.size === 0 || !activeGroupfolderId) return

	const ids = [...loadQueue]
	loadQueue.clear()
	loadTimer = null

	const data = await fetchDirectoryMetadata(activeGroupfolderId, ids)

	for (const [fileId, fields] of Object.entries(data)) {
		metadataCache.set(Number(fileId), fields)
	}

	// Mark files without metadata as empty
	for (const id of ids) {
		if (!metadataCache.has(id)) {
			metadataCache.set(id, {})
		}
	}

	updateAllRowCells()

	// Re-apply filters after new metadata loaded
	updateFilterCache(metadataCache)
}

// ========================================
// DOM Injection
// ========================================

const MARKER_CLASS = 'metavox-col'
const HEADER_MARKER = 'metavox-col-header'
const RESIZE_HANDLE = 'metavox-resize-handle'
const STYLE_ID = 'metavox-column-styles'

function injectColumnStyles() {
	if (document.getElementById(STYLE_ID)) return

	const style = document.createElement('style')
	style.id = STYLE_ID
	style.textContent = `
		/* Horizontal scroll: only #app-content-vue scrolls, nav stays fixed */
		#content-vue {
			overflow-x: visible !important;
		}
		#app-content-vue {
			overflow-x: auto !important;
		}
		/* Keep the breadcrumb/toolbar pinned during horizontal scroll.
		   left: 44px reserves space for the nav-collapse toggle (which sits at x=8..42
		   relative to #app-content-vue) without adding unwanted space at scrollLeft=0. */
		.files-list__header {
			position: sticky !important;
			left: 44px !important;
			z-index: 10 !important;
		}
		.files-list {
			overflow-x: visible !important;
		}
		.files-list__table {
			table-layout: auto !important;
		}
		.files-list__row-name {
			min-width: 200px !important;
		}
		/* Data cells */
		.${MARKER_CLASS} {
			padding: 0 8px !important;
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
			box-sizing: border-box;
		}
		/* Header cells */
		.${HEADER_MARKER} {
			padding: 0 !important;
			box-sizing: border-box;
			position: relative;
			min-width: 60px;
		}
		.${HEADER_MARKER} .files-list__column-sort-button {
			width: 100%;
			height: 34px;
			display: flex !important;
			align-items: center;
			justify-content: flex-start;
			padding: 1px 8px 0 8px;
			background: transparent;
			border: none;
			color: var(--color-text-maxcontrast);
			font: inherit;
			cursor: pointer;
			border-radius: var(--border-radius-element, 32px);
		}
		.${HEADER_MARKER} .files-list__column-sort-button:hover {
			background: var(--color-background-hover);
			color: var(--color-main-text);
		}
		.${HEADER_MARKER} .files-list__column-sort-button:hover .files-list__column-sort-button-icon {
			opacity: 0.5 !important;
		}
		.${HEADER_MARKER} .files-list__column-sort-button .button-vue__wrapper {
			display: flex;
			align-items: center;
			flex-direction: row-reverse;
			gap: 0;
		}
		.${HEADER_MARKER} .files-list__column-sort-button .button-vue__icon {
			display: flex;
			align-items: center;
			min-width: 24px;
		}
		.${HEADER_MARKER} .files-list__column-sort-button-icon {
			display: flex;
			align-items: center;
		}
		.${HEADER_MARKER} .files-list__column-sort-button-icon svg {
			width: 24px;
			height: 24px;
		}
		.${HEADER_MARKER} .files-list__column-sort-button-text {
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
		}
		/* Resize handle */
		.${RESIZE_HANDLE} {
			position: absolute;
			right: 0;
			top: 0;
			bottom: 0;
			width: 5px;
			cursor: col-resize;
			z-index: 1;
		}
		.${RESIZE_HANDLE}:hover,
		.${RESIZE_HANDLE}.active {
			background: var(--color-primary-element);
			opacity: 0.5;
		}
		.${MARKER_CLASS} {
			color: var(--color-text-maxcontrast);
			min-width: 60px;
		}
		.${MARKER_CLASS}--empty {
			color: var(--color-text-maxcontrast, #767676) !important;
		}
	`
	document.head.appendChild(style)
}

function removeColumnStyles() {
	document.getElementById(STYLE_ID)?.remove()
}

function getColWidth(fieldName) {
	return columnWidths.get(fieldName) || 150
}

const SORT_ICON_ASC = '<svg fill="currentColor" width="24" height="24" viewBox="0 0 24 24"><path d="M7,15L12,10L17,15H7Z"></path></svg>'
const SORT_ICON_DESC = '<svg fill="currentColor" width="24" height="24" viewBox="0 0 24 24"><path d="M7,10L12,15L17,10H7Z"></path></svg>'

/**
 * Sync the header actions cell width with the data row actions cell.
 * NC33 uses display:flex on rows; the actions cell is 0px in the header
 * but ~150px in data rows, causing all columns after it to misalign.
 */
function _syncActionsWidth(theadTr) {
	const hActions = theadTr.querySelector('.files-list__row-actions')
	if (!hActions) return

	const dataRow = document.querySelector('.files-list__table tbody tr')
	if (!dataRow) return

	const dActions = dataRow.querySelector('.files-list__row-actions')
	if (!dActions) return

	const dWidth = dActions.getBoundingClientRect().width
	if (dWidth > 0) {
		hActions.style.minWidth = dWidth + 'px'
		hActions.style.width = dWidth + 'px'
		hActions.style.flexShrink = '0'
	}
}

function injectHeaderColumns() {
	const theadTr = document.querySelector('.files-list__table thead tr')
	if (!theadTr) return

	theadTr.querySelectorAll('.' + HEADER_MARKER).forEach(el => el.remove())

	// Fix alignment: sync header actions cell width with data row actions cell
	_syncActionsWidth(theadTr)

	for (const config of activeColumnConfigs) {
		const th = document.createElement('th')
		th.className = `files-list__column ${HEADER_MARKER} files-list__column--sortable`
		const w = getColWidth(config.field_name)
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
		if (currentSort?.fieldName === config.field_name) {
			iconInner.innerHTML = currentSort.direction === 'asc' ? SORT_ICON_ASC : SORT_ICON_DESC
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

		theadTr.appendChild(th)
	}

	updateTableMinWidth()
}

function injectFooterColumns() {
	const tfootTr = document.querySelector('.files-list__table tfoot tr')
	if (!tfootTr) return

	tfootTr.querySelectorAll('.' + MARKER_CLASS).forEach(el => el.remove())

	for (const config of activeColumnConfigs) {
		const td = document.createElement('td')
		td.className = MARKER_CLASS
		const w = getColWidth(config.field_name)
		td.style.width = w + 'px'
		td.style.minWidth = w + 'px'
		td.style.maxWidth = w + 'px'
		tfootTr.appendChild(td)
	}
}

function injectRowColumns(row) {
	if (row.querySelector('.' + MARKER_CLASS)) return

	const fileId = Number(row.getAttribute('data-cy-files-list-row-fileid'))
	const meta = metadataCache.get(fileId)

	for (const config of activeColumnConfigs) {
		const td = document.createElement('td')
		td.className = `files-list__column ${MARKER_CLASS}`
		const w = getColWidth(config.field_name)
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

		row.appendChild(td)
	}
}

function setCellValue(td, value, config) {
	if (value !== undefined && value !== null && value !== '') {
		const formatted = formatValue(value, config.field_type)
		td.textContent = formatted
		td.title = formatted
		td.classList.remove(MARKER_CLASS + '--empty')
	} else {
		td.textContent = '\u2014'
		td.title = ''
		td.classList.add(MARKER_CLASS + '--empty')
	}
}

function updateAllRowCells() {
	const rows = document.querySelectorAll('tr[data-cy-files-list-row]')
	for (const row of rows) {
		const fileId = Number(row.getAttribute('data-cy-files-list-row-fileid'))
		const meta = metadataCache.get(fileId)
		if (!meta) continue

		const cells = row.querySelectorAll('.' + MARKER_CLASS)
		for (const cell of cells) {
			const fieldName = cell.dataset.metavoxField
			const config = activeColumnConfigs.find(c => c.field_name === fieldName)
			if (!config) continue
			setCellValue(cell, meta[fieldName], config)
		}
	}
}

function injectAllExistingRows() {
	const rows = document.querySelectorAll('tr[data-cy-files-list-row]')
	for (const row of rows) {
		injectRowColumns(row)
	}
}

function removeAllInjectedColumns() {
	document.querySelectorAll('.' + MARKER_CLASS + ', .' + HEADER_MARKER).forEach(el => el.remove())
	const table = document.querySelector('.files-list__table')
	if (table) table.style.minWidth = ''
	const filesList = document.querySelector('.files-list')
	if (filesList) filesList.style.minWidth = ''
}

function updateTableMinWidth() {
	const table = document.querySelector('.files-list__table')
	if (!table) return
	const headerRow = table.querySelector('.files-list__row-head')
	if (!headerRow) return

	const totalWidth = headerRow.scrollWidth
	if (totalWidth > 0) {
		table.style.minWidth = totalWidth + 'px'
		// Set min-width on files-list so #app-content-vue scrollWidth expands
		const filesList = document.querySelector('.files-list')
		if (filesList) filesList.style.minWidth = totalWidth + 'px'
	}
}

// ========================================
// Column Resizing
// ========================================

function setColumnWidth(fieldName, width) {
	document.querySelectorAll(`[data-metavox-field="${fieldName}"]`).forEach(el => {
		el.style.width = width + 'px'
		el.style.minWidth = width + 'px'
		el.style.maxWidth = width + 'px'
	})
}

function startResize(e, th, fieldName) {
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

function loadPersistedWidths() {
	try {
		const stored = JSON.parse(localStorage.getItem('metavox-col-widths') || '{}')
		for (const [key, val] of Object.entries(stored)) {
			columnWidths.set(key, val)
		}
	} catch (e) { /* ignore */ }
}

// ========================================
// Sorting
// ========================================

function handleSort(fieldName, fieldType) {
	if (currentSort?.fieldName === fieldName) {
		currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc'
	} else {
		currentSort = { fieldName, fieldType, direction: 'asc' }
	}

	applySort()
	updateSortIndicators()
}

function applySort() {
	if (!currentSort) return

	const tbody = document.querySelector('.files-list__table tbody')
	if (!tbody) return

	const rows = [...tbody.querySelectorAll('tr[data-cy-files-list-row]')]
	if (rows.length === 0) return

	const { fieldName, fieldType, direction } = currentSort
	const multiplier = direction === 'asc' ? 1 : -1

	rows.sort((rowA, rowB) => {
		const idA = Number(rowA.getAttribute('data-cy-files-list-row-fileid'))
		const idB = Number(rowB.getAttribute('data-cy-files-list-row-fileid'))
		const metaA = metadataCache.get(idA) || {}
		const metaB = metadataCache.get(idB) || {}

		return multiplier * compareValues(metaA[fieldName], metaB[fieldName], fieldType)
	})

	// Re-append in sorted order (moves DOM nodes)
	for (const row of rows) {
		tbody.appendChild(row)
	}
}

function updateSortIndicators() {
	document.querySelectorAll('.' + HEADER_MARKER).forEach(th => {
		const fieldName = th.dataset.metavoxField
		const iconEl = th.querySelector('.files-list__column-sort-button-icon')
		if (!iconEl) return

		if (currentSort?.fieldName === fieldName) {
			iconEl.innerHTML = currentSort.direction === 'asc' ? SORT_ICON_ASC : SORT_ICON_DESC
			iconEl.style.opacity = '1'
		} else {
			iconEl.innerHTML = SORT_ICON_ASC
			iconEl.style.opacity = '0'
		}
	})
}

// ========================================
// MutationObserver for New Rows
// ========================================

function startRowObserver() {
	if (rowObserver) return

	const tbody = document.querySelector('.files-list__table tbody')
	if (!tbody) {
		setTimeout(startRowObserver, 300)
		return
	}

	rowObserver = new MutationObserver((mutations) => {
		if (!columnsActive) return

		for (const mutation of mutations) {
			for (const node of mutation.addedNodes) {
				if (node.nodeType === 1 && node.matches?.('tr[data-cy-files-list-row]')) {
					injectRowColumns(node)
				}
			}
		}
	})

	rowObserver.observe(tbody, { childList: true })
}

function stopRowObserver() {
	if (rowObserver) {
		rowObserver.disconnect()
		rowObserver = null
	}
}

// ========================================
// Main Flow
// ========================================

export async function updateColumnsForCurrentFolder() {
	await loadGroupfolders()

	const groupfolderId = detectCurrentGroupfolder()

	if (groupfolderId === activeGroupfolderId && columnsActive) {
		return
	}

	// Clean up
	if (columnsActive) {
		removeAllInjectedColumns()
		removeColumnStyles()
		removeFilters()
		stopRowObserver()
		columnsActive = false
	}

	if (!groupfolderId) {
		activeGroupfolderId = null
		activeColumnConfigs = []
		metadataCache.clear()
		currentSort = null
		return
	}

	activeGroupfolderId = groupfolderId

	// Fetch column config
	const configs = await fetchColumnConfig(groupfolderId)
	activeColumnConfigs = configs.filter(c => c.show_as_column)

	if (activeColumnConfigs.length === 0) {
		return
	}

	console.info('MetaVox v1.8.28: Activating', activeColumnConfigs.length, 'columns')

	// Bulk load metadata
	const rows = document.querySelectorAll('tr[data-cy-files-list-row]')
	const fileIds = [...rows].map(r => Number(r.getAttribute('data-cy-files-list-row-fileid'))).filter(Boolean)

	if (fileIds.length > 0) {
		const data = await fetchDirectoryMetadata(groupfolderId, fileIds)
		for (const [fileId, fields] of Object.entries(data)) {
			metadataCache.set(Number(fileId), fields)
		}
		for (const id of fileIds) {
			if (!metadataCache.has(id)) {
				metadataCache.set(id, {})
			}
		}
	}

	columnsActive = true
	loadPersistedWidths()

	injectColumnStyles()
	injectHeaderColumns()
	registerMetaVoxFilter(activeColumnConfigs, groupfolderId, metadataCache)
	injectFooterColumns()
	injectAllExistingRows()
	startRowObserver()
}

function scheduleInjection(attempt = 0) {
	const table = document.querySelector('.files-list__table tbody')
	if (table && table.children.length > 0) {
		updateColumnsForCurrentFolder()
		return
	}

	if (attempt < 30) {
		setTimeout(() => scheduleInjection(attempt + 1), 200)
	}
}

export function getActiveColumnConfigs() {
	return activeColumnConfigs
}

export function getActiveGroupfolderId() {
	return activeGroupfolderId
}

export function startColumnWatcher() {
	let lastUrl = window.location.href

	const checkNavigation = () => {
		const currentUrl = window.location.href
		if (currentUrl !== lastUrl) {
			lastUrl = currentUrl
			if (columnsActive) {
				removeAllInjectedColumns()
				removeColumnStyles()
				removeFilters()
				stopRowObserver()
				columnsActive = false
				activeGroupfolderId = null
			}
			metadataCache.clear()
			currentSort = null
			scheduleInjection()
		}
	}

	window.addEventListener('popstate', checkNavigation)

	const origPush = history.pushState.bind(history)
	const origReplace = history.replaceState.bind(history)

	history.pushState = function(...args) {
		origPush(...args)
		setTimeout(checkNavigation, 100)
	}

	history.replaceState = function(...args) {
		origReplace(...args)
		setTimeout(checkNavigation, 100)
	}

	setInterval(checkNavigation, 2000)

	scheduleInjection()
}
