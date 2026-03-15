/**
 * MetaVox File List Columns — DOM Injection Approach
 *
 * Injects metadata columns directly into the NC33 Files app DOM via MutationObserver.
 * Features: resizable columns, click-to-sort, metadata bulk loading.
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import { registerMetaVoxFilter, removeFilters, updateFilterCache, getFilterInstance } from './MetadataFilter.js'

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

/** @type {Array<Object>} Views for the current groupfolder */
let activeViews = []

/** @type {Object|null} Currently applied view */
let activeView = null

/** @type {HTMLElement|null} The view-selector container element */
let viewSelectorEl = null

/** @type {Array<Object>} Full column configs from server (before view filtering) */
let fullColumnConfigs = []

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

async function fetchViews(groupfolderId) {
	try {
		const url = generateUrl(
			'/apps/metavox/api/groupfolders/{gfId}/views',
			{ gfId: groupfolderId },
		)
		const resp = await axios.get(url)
		return resp.data || []
	} catch (e) {
		console.error('MetaVox: Failed to fetch views', e)
		return []
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
// View Selector
// ========================================

const VIEW_SELECTOR_ID = 'metavox-view-selector'
const VIEW_SELECTOR_STYLE_ID = 'metavox-view-selector-styles'

function injectViewSelectorStyles() {
	if (document.getElementById(VIEW_SELECTOR_STYLE_ID)) return
	const style = document.createElement('style')
	style.id = VIEW_SELECTOR_STYLE_ID
	style.textContent = `
		#${VIEW_SELECTOR_ID} {
			position: relative;
			display: inline-flex;
			align-items: center;
		}
		#${VIEW_SELECTOR_ID} .metavox-view-btn {
			display: inline-flex;
			align-items: center;
			gap: 4px;
			height: 34px;
			padding: 0 12px;
			background: transparent;
			border: none;
			border-radius: var(--border-radius-element, 32px);
			color: var(--color-text-maxcontrast);
			font: inherit;
			font-size: 14px;
			cursor: pointer;
			white-space: nowrap;
		}
		#${VIEW_SELECTOR_ID} .metavox-view-btn:hover,
		#${VIEW_SELECTOR_ID} .metavox-view-btn.active {
			background: var(--color-background-hover);
			color: var(--color-main-text);
		}
		#${VIEW_SELECTOR_ID} .metavox-view-btn-icon {
			display: flex;
			align-items: center;
			width: 20px;
			height: 20px;
			flex-shrink: 0;
		}
		#${VIEW_SELECTOR_ID} .metavox-view-dropdown {
			display: none;
			position: absolute;
			top: calc(100% + 4px);
			left: 0;
			min-width: 180px;
			background: var(--color-main-background);
			border: 1px solid var(--color-border);
			border-radius: var(--border-radius-large, 8px);
			box-shadow: 0 2px 12px rgba(0,0,0,0.15);
			z-index: 1000;
			padding: 4px 0;
			list-style: none;
			margin: 0;
		}
		#${VIEW_SELECTOR_ID} .metavox-view-dropdown.open {
			display: block;
		}
		#${VIEW_SELECTOR_ID} .metavox-view-dropdown li {
			padding: 0;
			margin: 0;
		}
		#${VIEW_SELECTOR_ID} .metavox-view-dropdown li button {
			width: 100%;
			padding: 8px 16px;
			background: transparent;
			border: none;
			text-align: left;
			font: inherit;
			font-size: 14px;
			color: var(--color-main-text);
			cursor: pointer;
			display: block;
		}
		#${VIEW_SELECTOR_ID} .metavox-view-dropdown li button:hover {
			background: var(--color-background-hover);
		}
		#${VIEW_SELECTOR_ID} .metavox-view-dropdown li button.metavox-view-active {
			font-weight: bold;
			color: var(--color-primary-element);
		}
	`
	document.head.appendChild(style)
}

function removeViewSelectorStyles() {
	document.getElementById(VIEW_SELECTOR_STYLE_ID)?.remove()
}

const VIEW_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M3 5h2V3c-1.1 0-2 .9-2 2zm0 8h2v-2H3v2zm4 8h2v-2H7v2zM3 9h2V7H3v2zm10-6h-2v2h2V3zm6 0v2h2c0-1.1-.9-2-2-2zM5 21v-2H3c0 1.1.9 2 2 2zm-2-4h2v-2H3v2zM9 3H7v2h2V3zm2 18h2v-2h-2v2zm8-8h2v-2h-2v2zm0 8c1.1 0 2-.9 2-2h-2v2zm0-12h2V7h-2v2zm0 8h2v-2h-2v2zm-4 4h2v-2h-2v2zm0-16h2V3h-2v2z"/></svg>'

/**
 * Inject the view-selector widget into the NC33 toolbar.
 * Inserts adjacent to the MetaVox filter button in the filter bar.
 */
function injectViewSelector(views) {
	removeViewSelector()

	if (!views || views.length === 0) return

	injectViewSelectorStyles()

	const container = document.createElement('div')
	container.id = VIEW_SELECTOR_ID

	const btn = document.createElement('button')
	btn.type = 'button'
	btn.className = 'metavox-view-btn'
	btn.setAttribute('aria-haspopup', 'listbox')
	btn.setAttribute('aria-expanded', 'false')

	const iconSpan = document.createElement('span')
	iconSpan.className = 'metavox-view-btn-icon'
	iconSpan.setAttribute('aria-hidden', 'true')
	iconSpan.innerHTML = VIEW_ICON_SVG

	const labelSpan = document.createElement('span')
	labelSpan.className = 'metavox-view-btn-label'
	labelSpan.textContent = activeView?.name || 'Weergave'

	btn.appendChild(iconSpan)
	btn.appendChild(labelSpan)

	const dropdown = document.createElement('ul')
	dropdown.className = 'metavox-view-dropdown'
	dropdown.setAttribute('role', 'listbox')

	// "No view" option
	const noViewLi = document.createElement('li')
	const noViewBtn = document.createElement('button')
	noViewBtn.type = 'button'
	noViewBtn.textContent = 'Geen weergave'
	if (!activeView) noViewBtn.className = 'metavox-view-active'
	noViewBtn.addEventListener('click', () => {
		clearView()
		closeDropdown()
	})
	noViewLi.appendChild(noViewBtn)
	dropdown.appendChild(noViewLi)

	for (const view of views) {
		const li = document.createElement('li')
		const vBtn = document.createElement('button')
		vBtn.type = 'button'
		vBtn.textContent = view.name
		if (activeView?.id === view.id) vBtn.className = 'metavox-view-active'
		vBtn.addEventListener('click', () => {
			const fi = getFilterInstance()
			if (fi) applyView(view, fi)
			closeDropdown()
		})
		li.appendChild(vBtn)
		dropdown.appendChild(li)
	}

	container.appendChild(btn)
	container.appendChild(dropdown)

	// Toggle dropdown on button click
	btn.addEventListener('click', (e) => {
		e.stopPropagation()
		const isOpen = dropdown.classList.contains('open')
		if (isOpen) {
			dropdown.classList.remove('open')
			btn.classList.remove('active')
			btn.setAttribute('aria-expanded', 'false')
		} else {
			dropdown.classList.add('open')
			btn.classList.add('active')
			btn.setAttribute('aria-expanded', 'true')
		}
	})

	// Close when clicking outside
	document.addEventListener('click', closeDropdown)

	// Insert into the filter bar near the MetaVox filter button
	const inserted = _insertViewSelectorInToolbar(container)
	if (!inserted) {
		// Fallback: prepend to files-list header
		const header = document.querySelector('.files-list__header')
		if (header) {
			header.prepend(container)
		}
	}

	viewSelectorEl = container
}

function closeDropdown() {
	if (!viewSelectorEl) return
	const dropdown = viewSelectorEl.querySelector('.metavox-view-dropdown')
	const btn = viewSelectorEl.querySelector('.metavox-view-btn')
	dropdown?.classList.remove('open')
	btn?.classList.remove('active')
	btn?.setAttribute('aria-expanded', 'false')
}

/**
 * Try to insert the view-selector into the NC33 filter bar toolbar.
 * Returns true if successfully inserted.
 */
function _insertViewSelectorInToolbar(container) {
	// Try to find the MetaVox filter button by its id attribute on the filter element
	const filterBtn = document.querySelector(`[data-filter-id="${FILTER_ID}"], #${FILTER_ID}, .files-list__filters [class*="metavox"]`)
	if (filterBtn) {
		const parent = filterBtn.closest('.files-list__filters') || filterBtn.parentElement
		if (parent) {
			parent.appendChild(container)
			return true
		}
	}

	// Try the native filter bar container
	const filterBar = document.querySelector('.files-list__filters')
	if (filterBar) {
		filterBar.appendChild(container)
		return true
	}

	// Try the breadcrumb/toolbar area
	const toolbar = document.querySelector('.files-list__header .breadcrumb, .files-list__header nav, .files-list__header')
	if (toolbar) {
		toolbar.appendChild(container)
		return true
	}

	return false
}

// Need FILTER_ID from MetadataFilter — re-declare locally for DOM lookup
const FILTER_ID = 'metavox-metadata'

function removeViewSelector() {
	document.removeEventListener('click', closeDropdown)
	viewSelectorEl?.remove()
	viewSelectorEl = null
	removeViewSelectorStyles()
}

function updateViewSelectorLabel() {
	if (!viewSelectorEl) return
	const label = viewSelectorEl.querySelector('.metavox-view-btn-label')
	if (label) label.textContent = activeView?.name || 'Weergave'

	// Update active state on dropdown items
	viewSelectorEl.querySelectorAll('.metavox-view-dropdown li button').forEach(btn => {
		btn.classList.remove('metavox-view-active')
	})
	if (!activeView) {
		const noViewBtn = viewSelectorEl.querySelector('.metavox-view-dropdown li:first-child button')
		noViewBtn?.classList.add('metavox-view-active')
	} else {
		// Match by text content (simple approach)
		viewSelectorEl.querySelectorAll('.metavox-view-dropdown li button').forEach(btn => {
			if (btn.textContent === activeView.name) {
				btn.classList.add('metavox-view-active')
			}
		})
	}
}

/**
 * Apply a view: reset filters, apply view filters, apply column visibility, update URL.
 * @param {Object} view
 * @param {Object} filterInstance
 */
function applyView(view, filterInstance) {
	activeView = view

	// Reset existing filters (suppresses URL update; we'll set URL after)
	filterInstance._activeFilters.clear()
	filterInstance._emitChips()

	// Apply view filters — stored as { field_id: "val1, val2" } dict
	const filtersDict = view.filters || {}
	for (const [fieldId, valuesStr] of Object.entries(filtersDict)) {
		if (!valuesStr) continue
		// Resolve field_id -> field_name using fullColumnConfigs
		const config = fullColumnConfigs.find(c => String(c.field_id) === String(fieldId))
		const fieldName = config?.field_name
		if (!fieldName) continue
		const values = String(valuesStr).split(',').map(v => v.trim()).filter(Boolean)
		if (values.length > 0) {
			filterInstance._activeFilters.set(fieldName, new Set(values))
		}
	}

	// Emit filter update (will also call _syncToUrl but we overwrite below)
	filterInstance._emitChips()
	filterInstance.dispatchEvent(new CustomEvent('update:filter'))
	window._nc_event_bus?.emit('files:filters:changed')

	// Apply column visibility
	_applyViewColumns(view)

	// Apply sort if specified
	if (view.sort_field) {
		const config = fullColumnConfigs.find(c => c.field_name === view.sort_field)
		currentSort = {
			fieldName: view.sort_field,
			fieldType: config?.field_type || 'text',
			direction: view.sort_order || 'asc',
		}
		applySort()
		updateSortIndicators()
	}

	// Update URL: set mvview, clear mvfilter
	const params = new URLSearchParams(window.location.search)
	params.set('mvview', view.id)
	params.delete('mvfilter')
	history.replaceState(null, '', window.location.pathname + '?' + params.toString() + window.location.hash)

	updateViewSelectorLabel()
}

/**
 * Clear the active view: restore default column visibility and clear filter/sort state.
 */
function clearView() {
	activeView = null
	const fi = getFilterInstance()
	if (fi) {
		fi.reset()
	}

	// Restore full column visibility
	_applyViewColumns(null)

	// Clear sort
	currentSort = null
	updateSortIndicators()

	// Update URL: remove mvview and mvfilter
	const params = new URLSearchParams(window.location.search)
	params.delete('mvview')
	params.delete('mvfilter')
	const search = params.toString()
	history.replaceState(null, '', window.location.pathname + (search ? '?' + search : '') + window.location.hash)

	updateViewSelectorLabel()
}

/**
 * Apply column visibility based on a view's column config.
 * If view is null, restore all columns from fullColumnConfigs.
 * @param {Object|null} view
 */
function _applyViewColumns(view) {
	if (!fullColumnConfigs.length) return

	if (!view || !view.columns || view.columns.length === 0) {
		// Restore all columns that have show_as_column
		activeColumnConfigs = fullColumnConfigs.filter(c => c.show_as_column)
	} else {
		// View columns are stored as [{field_id, field_label, visible}]
		// Resolve field_id -> field_name and filter by visible flag
		const visibleFieldNames = new Set()
		for (const vc of view.columns) {
			const visible = vc.visible !== false && vc.show_as_column !== false
			if (!visible) continue
			// Try field_name directly first, then resolve via field_id
			if (vc.field_name) {
				visibleFieldNames.add(vc.field_name)
			} else if (vc.field_id) {
				const cfg = fullColumnConfigs.find(c => String(c.field_id) === String(vc.field_id))
				if (cfg) visibleFieldNames.add(cfg.field_name)
			}
		}
		activeColumnConfigs = fullColumnConfigs.filter(c => visibleFieldNames.has(c.field_name))
		// Fallback: if nothing resolved, show all
		if (activeColumnConfigs.length === 0) {
			activeColumnConfigs = fullColumnConfigs.filter(c => c.show_as_column)
		}
	}

	// Re-inject header/footer/row columns with updated config
	injectHeaderColumns()
	injectFooterColumns()

	// Remove existing data cells and re-inject
	document.querySelectorAll('tr[data-cy-files-list-row]').forEach(row => {
		row.querySelectorAll('.' + MARKER_CLASS).forEach(el => el.remove())
		injectRowColumns(row)
	})
}

/**
 * Check URL for ?mvview= and apply that view if found.
 * @param {Array<Object>} views
 * @param {Object} filterInstance
 */
function restoreViewFromUrl(views, filterInstance) {
	const params = new URLSearchParams(window.location.search)
	const viewId = params.get('mvview')
	if (!viewId) return

	const view = views.find(v => String(v.id) === String(viewId))
	if (view) {
		applyView(view, filterInstance)
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
		removeViewSelector()
		stopRowObserver()
		columnsActive = false
	}

	if (!groupfolderId) {
		activeGroupfolderId = null
		activeColumnConfigs = []
		fullColumnConfigs = []
		activeViews = []
		activeView = null
		metadataCache.clear()
		currentSort = null
		return
	}

	activeGroupfolderId = groupfolderId

	// Fetch column config and views in parallel
	const [configs, views] = await Promise.all([
		fetchColumnConfig(groupfolderId),
		fetchViews(groupfolderId),
	])

	fullColumnConfigs = configs
	activeViews = views
	activeView = null
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

	// Inject view-selector and restore view/filter state from URL
	const filterInstance = getFilterInstance()
	if (activeViews.length > 0) {
		// Defer injection slightly so the filter bar DOM is ready
		setTimeout(() => {
			injectViewSelector(activeViews)
			if (filterInstance) {
				const params = new URLSearchParams(window.location.search)
				if (params.has('mvview')) {
					restoreViewFromUrl(activeViews, filterInstance)
				} else if (params.has('mvfilter')) {
					filterInstance.loadFromUrl(fullColumnConfigs)
				}
			}
		}, 200)
	} else if (filterInstance) {
		// No views, but still restore filter state from URL
		const params = new URLSearchParams(window.location.search)
		if (params.has('mvfilter')) {
			setTimeout(() => {
				filterInstance.loadFromUrl(fullColumnConfigs)
			}, 200)
		}
	}
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
	// Track only the `dir` param — our own URL updates (mvfilter/mvview) must not
	// trigger a full column teardown.
	const getDirParam = () => new URLSearchParams(window.location.search).get('dir') || ''
	let lastDir = getDirParam()

	const checkNavigation = () => {
		const currentDir = getDirParam()
		if (currentDir !== lastDir) {
			lastDir = currentDir
			if (columnsActive) {
				removeAllInjectedColumns()
				removeColumnStyles()
				removeFilters()
				removeViewSelector()
				stopRowObserver()
				columnsActive = false
				activeGroupfolderId = null
			}
			metadataCache.clear()
			currentSort = null
			activeViews = []
			activeView = null
			fullColumnConfigs = []
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
