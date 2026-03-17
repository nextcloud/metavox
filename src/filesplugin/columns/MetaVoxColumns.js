/**
 * MetaVox File List Columns — DOM Injection Approach
 *
 * Injects metadata columns directly into the NC33 Files app DOM via MutationObserver.
 * Features: resizable columns, click-to-sort, metadata bulk loading.
 */

import axios from '@nextcloud/axios'
import { translate } from '@nextcloud/l10n'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import { createApp, h } from 'vue'
import { registerMetaVoxFilter, removeFilters, updateFilterCache, getFilterInstance } from './MetadataFilter.js'
import ViewEditorPanel from './ViewEditorPanel.vue'

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

/** @type {HTMLElement|null} The view-tabs container element */
let viewTabsEl = null

/** @type {boolean} Whether current user can manage views */
let canManageViews = false

/** @type {Array<Object>} File-level fields assigned to the current groupfolder (from /file-fields endpoint) */
let availableFields = []

/** @type {Object|null} Prefetched filter values: { field_name: [val1, val2, ...] } */
let prefetchedFilterValues = null

// ========================================
// Value Formatting
// ========================================

function formatValue(value, fieldType) {
	if (value === null || value === undefined || value === '') return ''

	switch (fieldType) {
	case 'checkbox':
	case 'boolean':
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
	case 'boolean':
		return (aVal === '1' ? 0 : 1) - (bVal === '1' ? 0 : 1)
	default:
		return String(aVal).localeCompare(String(bVal))
	}
}

// ========================================
// API
// ========================================

async function fetchAvailableFields(groupfolderId) {
	try {
		const url = generateOcsUrl(
			'/apps/metavox/api/v1/groupfolders/{groupfolderId}/file-fields',
			{ groupfolderId },
		)
		const resp = await axios.get(url)
		return resp.data?.ocs?.data || resp.data || []
	} catch (e) {
		console.error('MetaVox: Failed to fetch available fields', e)
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
		// Response: { views: [...], can_manage: bool }
		const data = resp.data || {}
		return {
			views: data.views || [],
			canManage: data.can_manage === true,
		}
	} catch (e) {
		console.error('MetaVox: Failed to fetch views', e)
		return { views: [], canManage: false }
	}
}

async function fetchAllFilterValues(groupfolderId) {
	try {
		const url = generateOcsUrl(
			'/apps/metavox/api/v1/groupfolders/{gfId}/all-filter-values',
			{ gfId: groupfolderId },
		)
		const resp = await axios.get(url)
		return resp.data?.ocs?.data || resp.data || {}
	} catch (e) {
		console.error('MetaVox: Failed to fetch filter values', e)
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
/** @type {number|null} Cached actions column width — reset on folder change */
let _cachedActionsWidth = null

function _syncActionsWidth(theadTr) {
	const hActions = theadTr.querySelector('.files-list__row-actions')
	if (!hActions) return

	if (_cachedActionsWidth !== null) {
		hActions.style.minWidth = _cachedActionsWidth + 'px'
		hActions.style.width = _cachedActionsWidth + 'px'
		hActions.style.flexShrink = '0'
		return
	}

	const dataRow = document.querySelector('.files-list__table tbody tr')
	if (!dataRow) return

	const dActions = dataRow.querySelector('.files-list__row-actions')
	if (!dActions) return

	const dWidth = dActions.getBoundingClientRect().width
	if (dWidth > 0) {
		_cachedActionsWidth = dWidth
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

	const headerFrag = document.createDocumentFragment()
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

		headerFrag.appendChild(th)
	}
	theadTr.appendChild(headerFrag)

	// Defer layout read to after DOM writes complete
	requestAnimationFrame(() => updateTableMinWidth())
}

function injectFooterColumns() {
	const tfootTr = document.querySelector('.files-list__table tfoot tr')
	if (!tfootTr) return

	tfootTr.querySelectorAll('.' + MARKER_CLASS).forEach(el => el.remove())

	const footerFrag = document.createDocumentFragment()
	for (const config of activeColumnConfigs) {
		const td = document.createElement('td')
		td.className = MARKER_CLASS
		const w = getColWidth(config.field_name)
		td.style.width = w + 'px'
		td.style.minWidth = w + 'px'
		td.style.maxWidth = w + 'px'
		footerFrag.appendChild(td)
	}
	tfootTr.appendChild(footerFrag)
}

function injectRowColumns(row) {
	if (row.querySelector('.' + MARKER_CLASS)) return

	const fileId = Number(row.getAttribute('data-cy-files-list-row-fileid'))
	const meta = metadataCache.get(fileId)

	const rowFrag = document.createDocumentFragment()
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

		rowFrag.appendChild(td)
	}
	row.appendChild(rowFrag)
}

function setCellValue(td, value, config) {
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
// View Tabs + Editor
// ========================================

const VIEW_TABS_ID = 'metavox-view-tabs'
const VIEW_EDITOR_ID = 'metavox-view-editor'
const VIEW_STYLE_ID = 'metavox-view-styles'

// Need FILTER_ID from MetadataFilter — re-declare locally for DOM lookup
const FILTER_ID = 'metavox-metadata'

function injectViewStyles() {
	if (document.getElementById(VIEW_STYLE_ID)) return
	const style = document.createElement('style')
	style.id = VIEW_STYLE_ID
	style.textContent = `
		/* ── Tab bar ── */
		#${VIEW_TABS_ID} {
			display: flex;
			flex-wrap: wrap;
			align-items: center;
			gap: calc(var(--default-grid-baseline, 4px) / 2);
			padding: var(--default-grid-baseline, 4px) calc(var(--default-grid-baseline, 4px) * 2);
			border-bottom: 1px solid var(--color-border);
			background: var(--color-main-background);
		}
		.mv-tab {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			height: 30px;
			padding: 0 12px;
			border: none;
			border-radius: var(--border-radius-element, 32px);
			background: transparent;
			color: var(--color-text-maxcontrast);
			font: inherit;
			font-size: 13px;
			cursor: pointer;
			white-space: nowrap;
			flex-shrink: 0;
			position: relative;
		}
		.mv-tab:hover {
			background: var(--color-background-hover);
			color: var(--color-main-text);
		}
		#${VIEW_TABS_ID} .mv-tab-active {
			background: var(--color-primary-element-light, #e8f0fe);
			color: var(--color-primary-element);
			font-weight: 600;
		}
		#${VIEW_TABS_ID} .mv-tab-active:hover {
			background: var(--color-primary-element-light, #e8f0fe);
		}
		.mv-tab-dot {
			width: 6px;
			height: 6px;
			border-radius: 50%;
			background: var(--color-primary-element);
			flex-shrink: 0;
		}
		.mv-tab-edit-hint {
			font-size: 11px;
			opacity: 0.6;
		}
		.mv-tab-add {
			margin-left: 4px;
			font-size: 18px;
			font-weight: 400;
			line-height: 1;
		}

		/* ── Slide-over editor panel ── */
		#${VIEW_EDITOR_ID} {
			position: fixed;
			top: 0;
			right: -480px;
			width: 460px;
			max-width: 95vw;
			height: 100vh;
			background: var(--color-main-background);
			border-left: 1px solid var(--color-border);
			box-shadow: -4px 0 24px rgba(0,0,0,0.15);
			z-index: 2000;
			display: flex;
			flex-direction: column;
			transition: right 0.25s ease;
			overflow: hidden;
		}
		#${VIEW_EDITOR_ID}.open {
			right: 0;
		}
		.mv-editor-overlay {
			position: fixed;
			inset: 0;
			z-index: 1999;
			background: transparent;
		}
		/* ── Potlood-knop op actieve tab ── */
		.mv-tab-edit-btn {
			background: none;
			border: none;
			cursor: pointer;
			padding: 0 0 0 2px;
			font-size: 13px;
			color: var(--color-primary-element);
			opacity: 0.55;
			line-height: 1;
			flex-shrink: 0;
		}
		.mv-tab-edit-btn:hover { opacity: 1; }

		/* ── Responsive (NC33 breakpoints) ── */
		@media only screen and (max-width: 1024px) {
			#${VIEW_TABS_ID} {
				padding: var(--default-grid-baseline, 4px);
				gap: calc(var(--default-grid-baseline, 4px) / 2);
			}
			.mv-tab {
				height: 28px;
				padding: 0 calc(var(--default-grid-baseline, 4px) * 2);
				font-size: 12px;
			}
		}

		@media only screen and (max-width: 512px) {
			.mv-tab {
				height: 26px;
				padding: 0 calc(var(--default-grid-baseline, 4px) * 1.5);
				font-size: 11px;
			}
			.mv-tab-add {
				font-size: 16px;
			}
		}
	`
	document.head.appendChild(style)
}

function removeViewStyles() {
	document.getElementById(VIEW_STYLE_ID)?.remove()
}

/**
 * Inject tab-bar above the file list filters.
 */
function injectViewTabs(views) {
	removeViewTabs()

	injectViewStyles()

	const container = document.createElement('div')
	container.id = VIEW_TABS_ID

	// "Alle bestanden" tab
	const allTab = document.createElement('button')
	allTab.type = 'button'
	allTab.className = 'mv-tab' + (!activeView ? ' mv-tab-active' : '')
	allTab.textContent = translate('metavox', 'All files')
	allTab.addEventListener('click', () => clearView())
	container.appendChild(allTab)

	// View tabs
	for (const view of views) {
		const tab = _makeViewTab(view)
		container.appendChild(tab)
	}

	// "+ Add" button
	if (canManageViews) {
		const addBtn = document.createElement('button')
		addBtn.type = 'button'
		addBtn.className = 'mv-tab mv-tab-add'
		addBtn.title = translate('metavox', 'New view')
		addBtn.textContent = '+'
		addBtn.addEventListener('click', () => openViewEditor(null))
		container.appendChild(addBtn)
	}

	// Insert before .files-list__filters
	const filterBar = document.querySelector('.files-list__filters')
	if (filterBar) {
		filterBar.insertAdjacentElement('beforebegin', container)
	} else {
		// Fallback: insert before the files-list table
		const table = document.querySelector('.files-list__table')
		if (table) {
			table.insertAdjacentElement('beforebegin', container)
		} else {
			// DOM not ready yet — retry shortly
			setTimeout(() => {
				const fb = document.querySelector('.files-list__filters')
				const tbl = document.querySelector('.files-list__table')
				const target = fb || tbl
				if (target) target.insertAdjacentElement('beforebegin', container)
			}, 150)
		}
	}

	viewTabsEl = container
}

function _makeViewTab(view) {
	const tab = document.createElement('button')
	tab.type = 'button'
	tab.dataset.viewId = view.id
	const isActive = activeView?.id === view.id
	tab.className = 'mv-tab' + (isActive ? ' mv-tab-active' : '')

	if (isActive) {
		const dot = document.createElement('span')
		dot.className = 'mv-tab-dot'
		tab.appendChild(dot)
	}

	const nameSpan = document.createElement('span')
	nameSpan.textContent = view.name
	tab.appendChild(nameSpan)

	if (isActive && canManageViews) {
		const editBtn = document.createElement('button')
		editBtn.type = 'button'
		editBtn.className = 'mv-tab-edit-btn'
		editBtn.title = translate('metavox', 'Edit view')
		editBtn.innerHTML = '✎'
		editBtn.addEventListener('click', (e) => {
			e.stopPropagation()
			openViewEditor(view)
		})
		tab.appendChild(editBtn)
	}

	tab.addEventListener('click', () => {
		const fi = getFilterInstance()
		if (fi) applyView(view, fi)
	})

	return tab
}

function removeViewTabs() {
	viewTabsEl?.remove()
	viewTabsEl = null
}

function updateActiveTabs() {
	if (!viewTabsEl) return

	viewTabsEl.querySelectorAll('.mv-tab').forEach(tab => {
		const viewId = tab.dataset.viewId
		if (!viewId) {
			// "Alle bestanden" tab
			tab.className = 'mv-tab' + (!activeView ? ' mv-tab-active' : '')
			return
		}

		const isActive = activeView && String(activeView.id) === String(viewId)
		if (isActive) {
			tab.className = 'mv-tab mv-tab-active'
			if (!tab.querySelector('.mv-tab-dot')) {
				const dot = document.createElement('span')
				dot.className = 'mv-tab-dot'
				tab.prepend(dot)
			}
			// Add pencil if can manage and not already present
			if (canManageViews && !tab.querySelector('.mv-tab-edit-btn')) {
				const view = activeViews.find(v => String(v.id) === String(viewId))
				if (view) {
					const editBtn = document.createElement('button')
					editBtn.type = 'button'
					editBtn.className = 'mv-tab-edit-btn'
					editBtn.title = translate('metavox', 'Edit view')
					editBtn.innerHTML = '✎'
					editBtn.addEventListener('click', (e) => { e.stopPropagation(); openViewEditor(view) })
					tab.appendChild(editBtn)
				}
			}
		} else {
			tab.className = 'mv-tab'
			tab.querySelector('.mv-tab-dot')?.remove()
			tab.querySelector('.mv-tab-edit-btn')?.remove()
		}
	})

}

// ── View Editor Panel ──────────────────────────────────────────

/**
 * Open the slide-over view editor (Vue component).
 * @param {Object|null} view - Existing view to edit, or null for new
 */
function openViewEditor(view) {
	// Remove existing editor
	closeViewEditor()

	injectViewStyles()

	// Overlay to close on outside click
	const overlay = document.createElement('div')
	overlay.className = 'mv-editor-overlay'
	overlay.addEventListener('click', closeViewEditor)
	document.body.appendChild(overlay)

	// Panel container
	const panel = document.createElement('div')
	panel.id = VIEW_EDITOR_ID
	document.body.appendChild(panel)

	const mountEl = document.createElement('div')
	mountEl.style.height = '100%'
	panel.appendChild(mountEl)

	const vueApp = createApp({
		render: () => h(ViewEditorPanel, {
			view,
			availableFields,
			fetchFilterValuesFn: (fieldName) => {
				// Use prefetched filter values (already loaded in parallel on directory init)
				const allValues = prefetchedFilterValues || {}
				const values = allValues[fieldName]
				return Array.isArray(values) ? values : []
			},
			onClose: () => closeViewEditor(),
			onSave: (payload) => _handleEditorSave(view, payload),
			onDelete: (v) => _confirmDeleteView(v),
		}),
	})

	vueApp.config.globalProperties.t = translate
	vueApp.mount(mountEl)
	panel._vueApp = vueApp

	// Trigger animation
	requestAnimationFrame(() => {
		requestAnimationFrame(() => panel.classList.add('open'))
	})

	// Escape key
	const onKeyDown = (e) => {
		if (e.key === 'Escape') { closeViewEditor(); document.removeEventListener('keydown', onKeyDown) }
	}
	document.addEventListener('keydown', onKeyDown)
}

/** Handle save from Vue editor component */
async function _handleEditorSave(view, payload) {
	try {
		let savedView
		if (!view) {
			const url = generateUrl('/apps/metavox/api/groupfolders/{gfId}/views', { gfId: activeGroupfolderId })
			const resp = await axios.post(url, payload)
			savedView = resp.data
		} else {
			const url = generateUrl('/apps/metavox/api/groupfolders/{gfId}/views/{viewId}', {
				gfId: activeGroupfolderId,
				viewId: view.id,
			})
			const resp = await axios.put(url, payload)
			savedView = resp.data
		}

		closeViewEditor()

		const result = await fetchViews(activeGroupfolderId)
		activeViews = result.views
		canManageViews = result.canManage
		injectViewTabs(activeViews)

		if (savedView) {
			const fi = getFilterInstance()
			if (fi) applyView(savedView, fi)
		}
	} catch (e) {
		console.error('MetaVox: Failed to save view', e)
		alert(translate('metavox', 'Save failed: ') + (e.response?.data?.error || e.message))
	}
}

function closeViewEditor() {
	const panel = document.getElementById(VIEW_EDITOR_ID)
	if (panel?._vueApp) {
		panel._vueApp.unmount()
	}
	panel?.remove()
	document.querySelector('.mv-editor-overlay')?.remove()
}

async function _confirmDeleteView(view) {
	if (!confirm(translate('metavox', 'Delete view "{name}"?', { name: view.name }))) return

	try {
		const url = generateUrl('/apps/metavox/api/groupfolders/{gfId}/views/{viewId}', {
			gfId: activeGroupfolderId,
			viewId: view.id,
		})
		await axios.delete(url)

		closeViewEditor()
		clearView()

		// Reload views and rebuild tabs
		const result = await fetchViews(activeGroupfolderId)
		activeViews = result.views
		canManageViews = result.canManage
		injectViewTabs(activeViews)
	} catch (e) {
		console.error('MetaVox: Failed to delete view', e)
		alert(translate('metavox', 'Delete failed: ') + (e.response?.data?.error || e.message))
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
		// Resolve field_id -> field_name from the view's own columns (enriched by server)
		const col = (view.columns || []).find(c => String(c.field_id) === String(fieldId))
		const fieldName = col?.field_name
			?? availableFields.find(c => String(c.id) === String(fieldId))?.field_name
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

	// Apply column visibility and update filter registration
	_applyViewColumns(view)
	registerMetaVoxFilter(activeColumnConfigs, activeGroupfolderId, metadataCache)

	// Apply sort if specified
	if (view.sort_field) {
		const col = (view.columns || []).find(c => c.field_name === view.sort_field)
			?? availableFields.find(c => c.field_name === view.sort_field)
		currentSort = {
			fieldName: view.sort_field,
			fieldType: col?.field_type || 'text',
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

	updateActiveTabs()
}

/**
 * Build default filterable configs from all available fields.
 * Used when no view is active — all fields are filterable by default.
 */
function buildDefaultFilterConfigs() {
	return availableFields.map(field => ({
		field_name: field.field_name,
		field_label: field.field_label,
		field_type: field.field_type,
		field_options: field.field_options,
		visible: false,
		filterable: true,
	}))
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

	// No view active — hide columns but keep all fields filterable
	_applyViewColumns(null)
	registerMetaVoxFilter(buildDefaultFilterConfigs(), activeGroupfolderId, metadataCache)

	// Clear sort
	currentSort = null
	updateSortIndicators()

	// Update URL: remove mvview and mvfilter
	const params = new URLSearchParams(window.location.search)
	params.delete('mvview')
	params.delete('mvfilter')
	const search = params.toString()
	history.replaceState(null, '', window.location.pathname + (search ? '?' + search : '') + window.location.hash)

	updateActiveTabs()
}

/**
 * Apply column visibility based on a view's column config.
 * If view is null, hide all MetaVox columns (no active view = "Alle bestanden").
 * @param {Object|null} view
 */
function _applyViewColumns(view) {
	if (!view || !view.columns || view.columns.length === 0) {
		// No view active — hide all MetaVox columns and reset table widths
		activeColumnConfigs = []
		const table = document.querySelector('.files-list__table')
		if (table) table.style.minWidth = ''
		const filesList = document.querySelector('.files-list')
		if (filesList) filesList.style.minWidth = ''
	} else {
		// Build activeColumnConfigs from visible view columns, enriched with availableFields data
		const ordered = []
		const usedNames = new Set()
		for (const vc of view.columns) {
			const visible = vc.visible !== false && vc.show_as_column !== false
			if (!visible) continue
			const fieldName = vc.field_name
			if (fieldName && !usedNames.has(fieldName)) {
				// Enrich with field metadata from availableFields if missing
				if (!vc.field_type) {
					const af = availableFields.find(f => f.field_name === fieldName || String(f.id) === String(vc.field_id))
					if (af) {
						vc.field_type = af.field_type
						if (!vc.field_options) vc.field_options = af.field_options
					}
				}
				ordered.push(vc)
				usedNames.add(fieldName)
			}
		}
		activeColumnConfigs = ordered
	}

	// Re-inject header/footer/row columns with updated config
	injectHeaderColumns()
	injectFooterColumns()

	// Batch remove all existing data cells in one query, then re-inject per row
	document.querySelectorAll('tr[data-cy-files-list-row] .' + MARKER_CLASS).forEach(el => el.remove())
	document.querySelectorAll('tr[data-cy-files-list-row]').forEach(row => {
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

	// Re-append in sorted order via DocumentFragment (single reflow)
	const sortFrag = document.createDocumentFragment()
	for (const row of rows) {
		sortFrag.appendChild(row)
	}
	tbody.appendChild(sortFrag)
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
		removeViewTabs()
		closeViewEditor()
		stopRowObserver()
		columnsActive = false
		_cachedActionsWidth = null
	}

	if (!groupfolderId) {
		activeGroupfolderId = null
		activeColumnConfigs = []
		availableFields = []
		activeViews = []
		activeView = null
		metadataCache.clear()
		prefetchedFilterValues = null
		currentSort = null
		_cachedActionsWidth = null
		return
	}

	activeGroupfolderId = groupfolderId

	// Fetch available fields, views, and filter values in parallel
	const [fields, viewsResult, filterValues] = await Promise.all([
		fetchAvailableFields(groupfolderId),
		fetchViews(groupfolderId),
		fetchAllFilterValues(groupfolderId),
	])

	prefetchedFilterValues = filterValues

	availableFields = fields
	activeViews = viewsResult.views
	canManageViews = viewsResult.canManage
	activeView = null
	activeColumnConfigs = []

	if (availableFields.length === 0 && !canManageViews) {
		return
	}

	columnsActive = true
	loadPersistedWidths()

	// Inject UI immediately (don't wait for metadata)
	injectColumnStyles()
	injectHeaderColumns()
	registerMetaVoxFilter(buildDefaultFilterConfigs(), groupfolderId, metadataCache)
	injectFooterColumns()
	injectAllExistingRows()
	startRowObserver()

	// Inject view tabs and restore view/filter state from URL immediately
	const filterInstance = getFilterInstance()
	if (activeViews.length > 0 || canManageViews) {
		injectViewTabs(activeViews)
		if (filterInstance) {
			const params = new URLSearchParams(window.location.search)
			if (params.has('mvview')) {
				restoreViewFromUrl(activeViews, filterInstance)
			} else {
				const defaultView = activeViews.find(v => v.is_default)
				if (defaultView) {
					applyView(defaultView, filterInstance)
				}
			}
		}
	}

	// Load metadata in the background (non-blocking)
	const rows = document.querySelectorAll('tr[data-cy-files-list-row]')
	const fileIds = [...rows].map(r => Number(r.getAttribute('data-cy-files-list-row-fileid'))).filter(Boolean)

	if (fileIds.length > 0) {
		fetchDirectoryMetadata(groupfolderId, fileIds).then(data => {
			for (const [fileId, fields2] of Object.entries(data)) {
				metadataCache.set(Number(fileId), fields2)
			}
			for (const id of fileIds) {
				if (!metadataCache.has(id)) {
					metadataCache.set(id, {})
				}
			}
			updateAllRowCells()
			updateFilterCache(metadataCache)
		})
	}
}

function scheduleInjection() {
	// Use MutationObserver to reliably detect when the file list table is populated.
	// NC33's Vue app renders the table asynchronously — a simple polling loop with
	// a fixed number of attempts is too fragile.
	const check = () => {
		const table = document.querySelector('.files-list__table tbody')
		if (table && table.children.length > 0) {
			updateColumnsForCurrentFolder()
			return true
		}
		return false
	}

	// Try immediately
	if (check()) return

	// Otherwise observe the DOM until the table appears
	const observer = new MutationObserver(() => {
		if (check()) {
			observer.disconnect()
		}
	})

	observer.observe(document.body, { childList: true, subtree: true })

	// Safety timeout: disconnect after 30 seconds to avoid leaks
	setTimeout(() => observer.disconnect(), 30000)
}

export function getActiveColumnConfigs() {
	return activeColumnConfigs
}

export function getActiveGroupfolderId() {
	return activeGroupfolderId
}

export function getPrefetchedFilterValues() {
	return prefetchedFilterValues
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
				removeViewTabs()
				closeViewEditor()
				stopRowObserver()
				columnsActive = false
				activeGroupfolderId = null
			}
			metadataCache.clear()
			currentSort = null
			activeViews = []
			activeView = null
			availableFields = []
			scheduleInjection()
		}
	}

	window.addEventListener('popstate', checkNavigation)

	window.addEventListener('metavox:metadata:saved', (e) => {
		const { fileId, metadata } = e.detail
		metadataCache.set(Number(fileId), metadata)
		updateAllRowCells()
	})

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
