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

/** @type {HTMLElement|null} The view-tabs container element */
let viewTabsEl = null

/** @type {boolean} Whether current user can manage views */
let canManageViews = false

/** @type {Array<Object>} File-level fields assigned to the current groupfolder (from /file-fields endpoint) */
let availableFields = []

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

async function fetchFilterValues(groupfolderId, fieldName) {
	try {
		const url = generateOcsUrl(
			'/apps/metavox/api/v1/groupfolders/{gfId}/filter-values',
			{ gfId: groupfolderId },
		)
		const resp = await axios.get(url, { params: { field_name: fieldName } })
		return resp.data?.ocs?.data || resp.data || []
	} catch (e) {
		console.error('MetaVox: Failed to fetch filter values', e)
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
			align-items: center;
			gap: 2px;
			padding: 4px 8px;
			border-bottom: 1px solid var(--color-border);
			background: var(--color-main-background);
			flex-wrap: wrap;
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
		.mv-tab-active {
			background: var(--color-primary-element-light, #e8f0fe);
			color: var(--color-primary-element);
			font-weight: 600;
		}
		.mv-tab-active:hover {
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
		.mv-editor-header {
			display: flex;
			align-items: center;
			justify-content: space-between;
			padding: 16px 20px 12px;
			border-bottom: 1px solid var(--color-border);
			flex-shrink: 0;
		}
		.mv-editor-header h3 {
			margin: 0;
			font-size: 16px;
			font-weight: 600;
		}
		.mv-editor-close {
			background: none;
			border: none;
			cursor: pointer;
			padding: 4px;
			border-radius: var(--border-radius);
			color: var(--color-text-maxcontrast);
			font-size: 20px;
			line-height: 1;
		}
		.mv-editor-close:hover { color: var(--color-main-text); background: var(--color-background-hover); }
		.mv-editor-body {
			flex: 1;
			overflow-y: auto;
			padding: 16px 20px;
		}
		.mv-editor-row {
			display: flex;
			align-items: center;
			gap: 12px;
			margin-bottom: 16px;
		}
		.mv-editor-row label {
			font-size: 13px;
			font-weight: 600;
			min-width: 60px;
			flex-shrink: 0;
		}
		.mv-editor-row input[type="text"] {
			flex: 1;
			height: 34px;
			padding: 0 10px;
			border: 1px solid var(--color-border);
			border-radius: var(--border-radius-element, 32px);
			background: var(--color-main-background);
			color: var(--color-main-text);
			font: inherit;
			font-size: 13px;
		}
		.mv-editor-section-title {
			font-size: 11px;
			font-weight: 700;
			letter-spacing: 0.08em;
			text-transform: uppercase;
			color: var(--color-text-maxcontrast);
			margin: 16px 0 8px;
		}
		.mv-col-row {
			display: flex;
			align-items: center;
			padding: 6px 4px;
			border-radius: var(--border-radius);
			gap: 8px;
		}
		.mv-col-row:hover { background: var(--color-background-hover); }
		.mv-col-drag {
			cursor: grab;
			color: var(--color-text-maxcontrast);
			font-size: 16px;
			flex-shrink: 0;
			width: 20px;
			text-align: center;
		}
		.mv-col-name {
			flex: 1;
			font-size: 13px;
		}
		.mv-col-check {
			width: 70px;
			display: flex;
			justify-content: center;
		}
		.mv-col-check input[type="checkbox"] {
			width: 16px;
			height: 16px;
			cursor: pointer;
		}
		.mv-disabled {
			opacity: 0.4;
			pointer-events: none;
		}
		.mv-col-header-row {
			display: flex;
			align-items: center;
			gap: 8px;
			padding: 0 4px 4px;
			border-bottom: 1px solid var(--color-border);
			margin-bottom: 4px;
		}
		.mv-col-header-row .mv-col-drag { visibility: hidden; }
		.mv-col-header-label {
			font-size: 11px;
			font-weight: 600;
			text-transform: uppercase;
			color: var(--color-text-maxcontrast);
		}
		.mv-filter-row {
			border-bottom: 1px solid var(--color-border);
		}
		.mv-filter-row:last-of-type {
			border-bottom: none;
		}
		.mv-filter-summary {
			display: flex;
			align-items: center;
			justify-content: space-between;
			padding: 10px 4px;
			cursor: pointer;
			user-select: none;
			list-style: none;
			min-height: 40px;
			font-size: 13px;
			font-weight: 600;
			color: var(--color-main-text);
		}
		.mv-filter-summary::-webkit-details-marker { display: none; }
		.mv-filter-summary:hover { background: var(--color-background-hover); border-radius: var(--border-radius, 4px); }
		.mv-filter-summary-text { flex: 1; }
		.mv-filter-badge {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			min-width: 18px;
			height: 18px;
			padding: 0 4px;
			border-radius: 9px;
			background: var(--color-primary-element, #0082c9);
			color: #fff;
			font-size: 11px;
			font-weight: 600;
			margin-right: 6px;
		}
		.mv-filter-chevron {
			width: 14px;
			height: 14px;
			transition: transform 0.15s;
			color: var(--color-text-maxcontrast, #767676);
			flex-shrink: 0;
		}
		details.mv-filter-row[open] .mv-filter-chevron {
			transform: rotate(90deg);
		}
		.mv-filter-body {
			padding: 4px 0 8px 0;
		}
		.mv-filter-tags {
			display: flex;
			flex-wrap: wrap;
			gap: 4px;
			padding: 6px 8px;
			border: 1px solid var(--color-border);
			border-radius: var(--border-radius-element, 32px);
			min-height: 34px;
			align-items: center;
			cursor: text;
		}
		.mv-filter-tag {
			display: inline-flex;
			align-items: center;
			gap: 4px;
			padding: 2px 8px;
			background: var(--color-primary-element-light);
			color: var(--color-primary-element);
			border-radius: 12px;
			font-size: 12px;
		}
		.mv-filter-tag-remove {
			background: none;
			border: none;
			cursor: pointer;
			padding: 0;
			font-size: 14px;
			line-height: 1;
			color: inherit;
			opacity: 0.7;
		}
		.mv-filter-tag-remove:hover { opacity: 1; }
		.mv-filter-input {
			border: none;
			outline: none;
			background: transparent;
			font: inherit;
			font-size: 12px;
			min-width: 80px;
			flex: 1;
			color: var(--color-main-text);
		}
		.mv-sort-row {
			display: flex;
			align-items: center;
			gap: 8px;
		}
		.mv-sort-row select {
			height: 34px;
			padding: 0 8px;
			border: 1px solid var(--color-border);
			border-radius: var(--border-radius-element, 32px);
			background: var(--color-main-background);
			color: var(--color-main-text);
			font: inherit;
			font-size: 13px;
			cursor: pointer;
		}
		.mv-editor-footer {
			display: flex;
			align-items: center;
			justify-content: flex-end;
			gap: 8px;
			padding: 12px 20px;
			border-top: 1px solid var(--color-border);
			flex-shrink: 0;
		}
		.mv-btn {
			height: 34px;
			padding: 0 16px;
			border: none;
			border-radius: var(--border-radius-element, 32px);
			font: inherit;
			font-size: 13px;
			cursor: pointer;
		}
		.mv-btn-primary {
			background: var(--color-primary-element);
			color: var(--color-primary-element-text);
		}
		.mv-btn-primary:hover { opacity: 0.9; }
		.mv-btn-primary.loading { opacity: 0.6; cursor: wait; }
		.mv-btn-secondary {
			background: var(--color-background-hover);
			color: var(--color-main-text);
		}
		.mv-btn-secondary:hover { background: var(--color-border); }
		.mv-btn-danger {
			background: transparent;
			color: var(--color-error);
			border: 1px solid var(--color-error);
			margin-right: auto;
		}
		.mv-btn-danger:hover { background: var(--color-error); color: #fff; }
		.mv-default-toggle {
			display: inline-flex;
			align-items: center;
			gap: 4px;
			padding: 4px 10px;
			border: 1px solid var(--color-border);
			border-radius: var(--border-radius-element, 32px);
			background: transparent;
			cursor: pointer;
			font: inherit;
			font-size: 13px;
			color: var(--color-text-maxcontrast);
			flex-shrink: 0;
		}
		.mv-default-toggle.active {
			border-color: var(--color-primary-element);
			color: var(--color-primary-element);
			background: var(--color-primary-element-light);
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
		/* ── Select-filter aanvinklijst ── */
		.mv-select-filter-list {
			max-height: 160px;
			overflow-y: auto;
			border: 1px solid var(--color-border);
			border-radius: var(--border-radius-large, 8px);
			padding: 4px 0;
			background: var(--color-main-background);
		}
		.mv-select-filter-item {
			display: flex;
			align-items: center;
			gap: 8px;
			padding: 5px 10px;
			cursor: pointer;
			font-size: 13px;
			user-select: none;
		}
		.mv-select-filter-item:hover { background: var(--color-background-hover); }
		.mv-select-filter-item input[type="checkbox"] { width: 15px; height: 15px; cursor: pointer; flex-shrink: 0; }
		/* ── Autocomplete wrapper (relatief voor dropdown positionering) ── */
		.mv-autocomplete-wrap {
			position: relative;
		}
		.mv-autocomplete-dropdown {
			position: absolute;
			left: 0;
			right: 0;
			top: calc(100% + 2px);
			background: var(--color-main-background);
			border: 1px solid var(--color-border);
			border-radius: var(--border-radius-large, 8px);
			box-shadow: 0 4px 16px rgba(0,0,0,0.12);
			z-index: 3000;
			max-height: 180px;
			overflow-y: auto;
		}
		.mv-autocomplete-item {
			padding: 6px 12px;
			font-size: 13px;
			cursor: pointer;
		}
		.mv-autocomplete-item:hover { background: var(--color-background-hover); }
		.mv-autocomplete-item.mv-item-selected { opacity: 0.4; cursor: default; }
		.mv-autocomplete-item.mv-item-selected:hover { background: transparent; }
		.mv-autocomplete-empty {
			padding: 8px 12px;
			font-size: 12px;
			color: var(--color-text-maxcontrast);
			font-style: italic;
		}
		/* ── Checkbox-filter toggle knoppen ── */
		.mv-checkbox-filter {
			display: flex;
			gap: 8px;
		}
		.mv-checkbox-filter-btn {
			height: 30px;
			padding: 0 14px;
			border: 1px solid var(--color-border);
			border-radius: var(--border-radius-element, 32px);
			background: transparent;
			cursor: pointer;
			font: inherit;
			font-size: 13px;
			color: var(--color-text-maxcontrast);
		}
		.mv-checkbox-filter-btn.active {
			border-color: var(--color-primary-element);
			background: var(--color-primary-element-light, #e8f0fe);
			color: var(--color-primary-element);
			font-weight: 600;
		}
		.mv-checkbox-filter-btn:hover:not(.active) { background: var(--color-background-hover); color: var(--color-main-text); }
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
	allTab.textContent = 'Alle bestanden'
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
		addBtn.title = 'Nieuwe weergave'
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
		if (table) table.insertAdjacentElement('beforebegin', container)
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
		editBtn.title = 'Weergave bewerken'
		editBtn.innerHTML = '✎'
		editBtn.addEventListener('click', (e) => {
			e.stopPropagation()
			openViewEditor(view)
		})
		tab.appendChild(editBtn)
	}

	tab.addEventListener('click', () => {
		if (activeView?.id === view.id) {
			if (canManageViews) openViewEditor(view)
		} else {
			const fi = getFilterInstance()
			if (fi) applyView(view, fi)
		}
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
					editBtn.title = 'Weergave bewerken'
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

let _editorDragState = null

/** Cache voor filter-waarden per veld (fieldName → string[]), per editor-sessie */
const _filterValuesCache = {}

async function _getFilterValues(fieldName) {
	if (_filterValuesCache[fieldName]) return _filterValuesCache[fieldName]
	const values = await fetchFilterValues(activeGroupfolderId, fieldName)
	_filterValuesCache[fieldName] = Array.isArray(values) ? values : []
	return _filterValuesCache[fieldName]
}

/**
 * Open the slide-over view editor.
 * @param {Object|null} view - Existing view to edit, or null for new
 */
async function openViewEditor(view) {
	// Remove existing editor
	closeViewEditor()

	injectViewStyles()

	// Overlay to close on outside click
	const overlay = document.createElement('div')
	overlay.className = 'mv-editor-overlay'
	overlay.addEventListener('click', closeViewEditor)
	document.body.appendChild(overlay)

	// Panel
	const panel = document.createElement('div')
	panel.id = VIEW_EDITOR_ID
	document.body.appendChild(panel)

	// Build editor state
	const isNew = !view
	const editorState = {
		name: view?.name || '',
		isDefault: view?.is_default || false,
		// columns: [{field_id, field_label, visible, filterable}]
		columns: _buildEditorColumns(view),
		// filters: { fieldId: Set<string> }
		filters: _buildEditorFilters(view),
		sortField: view?.sort_field || '',
		sortOrder: view?.sort_order || 'asc',
	}

	// Render
	_renderEditorContent(panel, view, editorState, isNew)

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

function closeViewEditor() {
	document.getElementById(VIEW_EDITOR_ID)?.remove()
	document.querySelector('.mv-editor-overlay')?.remove()
}

function _buildEditorColumns(view) {
	const viewCols = view?.columns || []

	if (viewCols.length > 0) {
		// Gebruik volgorde uit view.columns; kolommen niet in view komen achteraan
		const result = []
		const usedIds = new Set()

		viewCols.forEach(vc => {
			const cfg = availableFields.find(c =>
				String(c.id) === String(vc.field_id) || c.field_name === vc.field_name,
			)
			if (!cfg) return
			usedIds.add(cfg.id)
			result.push({
				field_id: cfg.id,
				field_name: cfg.field_name,
				field_label: cfg.field_label,
				field_type: cfg.field_type,
				visible: vc.visible !== false && vc.show_as_column !== false,
				filterable: vc.filterable !== false,
			})
		})

		// Voeg kolommen toe die niet in de view staan (bijv. nieuw toegevoegd)
		availableFields.forEach(cfg => {
			if (usedIds.has(cfg.id)) return
			result.push({
				field_id: cfg.id,
				field_name: cfg.field_name,
				field_label: cfg.field_label,
				field_type: cfg.field_type,
				visible: false,
				filterable: false,
			})
		})

		return result
	}

	// Geen view — alle beschikbare velden, standaard niet zichtbaar
	return availableFields.map(cfg => ({
		field_id: cfg.id,
		field_name: cfg.field_name,
		field_label: cfg.field_label,
		field_type: cfg.field_type,
		visible: false,
		filterable: false,
	}))
}

function _buildEditorFilters(view) {
	const filters = {}
	const raw = view?.filters || {}
	for (const [fieldId, valStr] of Object.entries(raw)) {
		if (!valStr) continue
		filters[fieldId] = new Set(String(valStr).split(',').map(v => v.trim()).filter(Boolean))
	}
	return filters
}

function _renderEditorContent(panel, view, editorState, isNew) {
	panel.innerHTML = ''

	// Header
	const header = document.createElement('div')
	header.className = 'mv-editor-header'
	const h3 = document.createElement('h3')
	h3.textContent = isNew ? 'Nieuwe weergave' : `Weergave bewerken`
	const closeBtn = document.createElement('button')
	closeBtn.className = 'mv-editor-close'
	closeBtn.innerHTML = '&times;'
	closeBtn.addEventListener('click', closeViewEditor)
	header.appendChild(h3)
	header.appendChild(closeBtn)
	panel.appendChild(header)

	// Body
	const body = document.createElement('div')
	body.className = 'mv-editor-body'
	panel.appendChild(body)

	// Name row
	const nameRow = document.createElement('div')
	nameRow.className = 'mv-editor-row'
	const nameLabel = document.createElement('label')
	nameLabel.textContent = 'Naam'
	const nameInput = document.createElement('input')
	nameInput.type = 'text'
	nameInput.value = editorState.name
	nameInput.placeholder = 'Naam van de weergave'
	nameInput.addEventListener('input', () => { editorState.name = nameInput.value })

	const defaultBtn = document.createElement('button')
	defaultBtn.type = 'button'
	defaultBtn.className = 'mv-default-toggle' + (editorState.isDefault ? ' active' : '')
	defaultBtn.innerHTML = (editorState.isDefault ? '&#9733;' : '&#9734;') + ' Standaard'
	defaultBtn.addEventListener('click', () => {
		editorState.isDefault = !editorState.isDefault
		defaultBtn.className = 'mv-default-toggle' + (editorState.isDefault ? ' active' : '')
		defaultBtn.innerHTML = (editorState.isDefault ? '&#9733;' : '&#9734;') + ' Standaard'
	})

	nameRow.appendChild(nameLabel)
	nameRow.appendChild(nameInput)
	nameRow.appendChild(defaultBtn)
	body.appendChild(nameRow)

	// Columns section
	const colTitle = document.createElement('div')
	colTitle.className = 'mv-editor-section-title'
	colTitle.textContent = 'Kolommen'
	body.appendChild(colTitle)

	// Column header row
	const colHeaderRow = document.createElement('div')
	colHeaderRow.className = 'mv-col-header-row'
	colHeaderRow.innerHTML = `
		<span class="mv-col-drag"></span>
		<span class="mv-col-name mv-col-header-label">Veld</span>
		<span class="mv-col-check mv-col-header-label">Zichtbaar</span>
		<span class="mv-col-check mv-col-header-label">Filterbaar</span>
	`
	body.appendChild(colHeaderRow)

	// Column rows (drag-to-reorder)
	const colList = document.createElement('div')
	colList.id = 'mv-col-list'
	editorState.columns.forEach((col, idx) => {
		colList.appendChild(_makeColRow(col, idx, editorState, colList))
	})
	body.appendChild(colList)

	// Filters section
	const filtTitle = document.createElement('div')
	filtTitle.className = 'mv-editor-section-title'
	filtTitle.textContent = 'Filters (preset waarden)'
	body.appendChild(filtTitle)

	// Only visible + filterable columns get a filter row (null returned for unsupported types)
	editorState.columns.forEach(col => {
		if (!col.visible || !col.filterable) return
		const filterRow = _makeFilterRow(col, editorState)
		if (!filterRow) return
		filterRow.dataset.filterFieldId = col.field_id
		body.appendChild(filterRow)
	})

	// Sort section
	const sortTitle = document.createElement('div')
	sortTitle.className = 'mv-editor-section-title'
	sortTitle.textContent = 'Sortering'
	body.appendChild(sortTitle)

	const sortRow = document.createElement('div')
	sortRow.className = 'mv-sort-row'

	const sortFieldSel = document.createElement('select')
	sortFieldSel.dataset.role = 'sort-field'
	const noSortOpt = document.createElement('option')
	noSortOpt.value = ''
	noSortOpt.textContent = '— geen sortering —'
	sortFieldSel.appendChild(noSortOpt)
	editorState.columns.forEach(col => {
		if (!col.visible) return
		const opt = document.createElement('option')
		opt.value = col.field_name
		opt.textContent = col.field_label
		if (editorState.sortField === col.field_name) opt.selected = true
		sortFieldSel.appendChild(opt)
	})
	sortFieldSel.addEventListener('change', () => { editorState.sortField = sortFieldSel.value })

	const sortOrderSel = document.createElement('select')
	;[['asc', 'Oplopend'], ['desc', 'Aflopend']].forEach(([val, label]) => {
		const opt = document.createElement('option')
		opt.value = val
		opt.textContent = label
		if (editorState.sortOrder === val) opt.selected = true
		sortOrderSel.appendChild(opt)
	})
	sortOrderSel.addEventListener('change', () => { editorState.sortOrder = sortOrderSel.value })

	sortRow.appendChild(sortFieldSel)
	sortRow.appendChild(sortOrderSel)
	body.appendChild(sortRow)

	// Footer
	const footer = document.createElement('div')
	footer.className = 'mv-editor-footer'

	if (!isNew) {
		const delBtn = document.createElement('button')
		delBtn.type = 'button'
		delBtn.className = 'mv-btn mv-btn-danger'
		delBtn.textContent = 'Verwijderen'
		delBtn.addEventListener('click', () => _confirmDeleteView(view))
		footer.appendChild(delBtn)
	}

	const cancelBtn = document.createElement('button')
	cancelBtn.type = 'button'
	cancelBtn.className = 'mv-btn mv-btn-secondary'
	cancelBtn.textContent = 'Annuleren'
	cancelBtn.addEventListener('click', closeViewEditor)

	const saveBtn = document.createElement('button')
	saveBtn.type = 'button'
	saveBtn.className = 'mv-btn mv-btn-primary'
	saveBtn.textContent = 'Opslaan'
	saveBtn.addEventListener('click', () => _saveViewFromEditor(view, editorState))

	footer.appendChild(cancelBtn)
	footer.appendChild(saveBtn)
	panel.appendChild(footer)
}

function _makeColRow(col, idx, editorState, colList) {
	const row = document.createElement('div')
	row.className = 'mv-col-row'
	row.draggable = true
	row.dataset.colIdx = idx

	const drag = document.createElement('span')
	drag.className = 'mv-col-drag'
	drag.textContent = '⠿'
	drag.title = 'Slepen om te herordenen'

	const name = document.createElement('span')
	name.className = 'mv-col-name'
	name.textContent = col.field_label

	const visCheck = document.createElement('span')
	visCheck.className = 'mv-col-check'
	const visInput = document.createElement('input')
	visInput.type = 'checkbox'
	visInput.checked = col.visible
	visInput.title = 'Zichtbaar'
	const filtCheck = document.createElement('span')
	filtCheck.className = 'mv-col-check'
	const filtInput = document.createElement('input')
	filtInput.type = 'checkbox'
	filtInput.checked = col.filterable
	filtInput.title = 'Filterbaar'
	filtInput.addEventListener('change', () => {
		col.filterable = filtInput.checked
		_refreshFilterRows(editorState)
	})
	filtCheck.appendChild(filtInput)

	visInput.addEventListener('change', () => {
		col.visible = visInput.checked
		// Koppel Filterbaar aan Zichtbaar
		if (!visInput.checked) {
			col.filterable = false
			filtInput.checked = false
			filtInput.disabled = true
			filtCheck.classList.add('mv-disabled')
		} else {
			filtInput.disabled = false
			filtCheck.classList.remove('mv-disabled')
		}
		_refreshFilterRows(editorState)
		_refreshSortSection(editorState)
	})
	visCheck.appendChild(visInput)

	// Initieel synchroniseren
	if (!col.visible) {
		filtInput.disabled = true
		filtCheck.classList.add('mv-disabled')
	}

	row.appendChild(drag)
	row.appendChild(name)
	row.appendChild(visCheck)
	row.appendChild(filtCheck)

	// Drag events
	row.addEventListener('dragstart', (e) => {
		_editorDragState = { from: parseInt(row.dataset.colIdx) }
		e.dataTransfer.effectAllowed = 'move'
		row.style.opacity = '0.4'
	})
	row.addEventListener('dragend', () => {
		row.style.opacity = ''
		_editorDragState = null
		// Re-index all rows
		colList.querySelectorAll('.mv-col-row').forEach((r, i) => { r.dataset.colIdx = i })
	})
	row.addEventListener('dragover', (e) => {
		e.preventDefault()
		e.dataTransfer.dropEffect = 'move'
	})
	row.addEventListener('drop', (e) => {
		e.preventDefault()
		if (!_editorDragState) return
		const fromIdx = _editorDragState.from
		const toIdx = parseInt(row.dataset.colIdx)
		if (fromIdx === toIdx) return

		// Reorder editorState.columns
		const [moved] = editorState.columns.splice(fromIdx, 1)
		editorState.columns.splice(toIdx, 0, moved)

		// Re-render col list
		colList.innerHTML = ''
		editorState.columns.forEach((c, i) => {
			colList.appendChild(_makeColRow(c, i, editorState, colList))
		})
	})

	return row
}

/**
 * Type-aware filter row factory.
 * Returns null for types where preset filters don't make sense (date, file, filelink).
 */
function _makeFilterRow(col, editorState) {
	const type = col.field_type

	// No preset filters for date/file types
	if (type === 'date' || type === 'file' || type === 'filelink') return null

	if (type === 'checkbox') return _makeCheckboxFilterRow(col, editorState)

	if (['select', 'multiselect', 'multi_select'].includes(type)) {
		return _makeSelectFilterRow(col, editorState)
	}

	// text, textarea, number, url, user, and anything else: autocomplete
	return _makeAutocompleteFilterRow(col, editorState)
}

/** Wraps a filter row as a collapsible <details> section with badge */
function _wrapFilterRow(col, content, editorState) {
	const activeCount = editorState?.filters[col.field_id]?.size || 0

	const details = document.createElement('details')
	details.className = 'mv-filter-row'
	if (activeCount > 0) details.open = true

	const summary = document.createElement('summary')
	summary.className = 'mv-filter-summary'

	const textSpan = document.createElement('span')
	textSpan.className = 'mv-filter-summary-text'
	textSpan.textContent = col.field_label
	summary.appendChild(textSpan)

	const badge = document.createElement('span')
	badge.className = 'mv-filter-badge'
	badge.dataset.badgeFor = col.field_id
	badge.textContent = String(activeCount)
	badge.style.display = activeCount > 0 ? 'inline-flex' : 'none'
	summary.appendChild(badge)

	summary.insertAdjacentHTML('beforeend', '<svg class="mv-filter-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>')

	details.appendChild(summary)

	const body = document.createElement('div')
	body.className = 'mv-filter-body'
	body.appendChild(content)
	details.appendChild(body)

	return details
}

/** Updates the badge count on a filter row's summary */
function _updateFilterBadge(detailsEl, count) {
	const badge = detailsEl?.querySelector('.mv-filter-badge')
	if (!badge) return
	badge.textContent = String(count)
	badge.style.display = count > 0 ? 'inline-flex' : 'none'
}

/**
 * Checkbox field: aanvinklijst met "Ja" / "Nee" — consistent met select-filter.
 * Stores "1" for Ja, "0" for Nee in the tagSet.
 */
function _makeCheckboxFilterRow(col, editorState) {
	const tagSet = editorState.filters[col.field_id] || new Set()
	editorState.filters[col.field_id] = tagSet

	const list = document.createElement('div')
	list.className = 'mv-select-filter-list'

	const detailsEl = _wrapFilterRow(col, list, editorState)

	;[['1', 'Ja'], ['0', 'Nee']].forEach(([val, label]) => {
		const item = document.createElement('label')
		item.className = 'mv-select-filter-item'

		const cb = document.createElement('input')
		cb.type = 'checkbox'
		cb.checked = tagSet.has(val)
		cb.addEventListener('change', () => {
			if (cb.checked) tagSet.add(val)
			else tagSet.delete(val)
			_updateFilterBadge(detailsEl, tagSet.size)
		})

		item.appendChild(cb)
		item.appendChild(document.createTextNode(label))
		list.appendChild(item)
	})

	return detailsEl
}

/**
 * Select/multiselect field: scrollbare aanvinklijst met vaste opties uit cfg.options.
 * cfg.options kan een string zijn ("opt1\nopt2") of array.
 */
function _makeSelectFilterRow(col, editorState) {
	const tagSet = editorState.filters[col.field_id] || new Set()
	editorState.filters[col.field_id] = tagSet

	// Parse options from column (field_options is enriched by the server in view.columns)
	const rawOptions = col.field_options ?? availableFields.find(c => String(c.id) === String(col.field_id))?.field_options
	let options = []
	if (rawOptions) {
		if (Array.isArray(rawOptions)) {
			options = rawOptions.map(o => (typeof o === 'object' ? (o.label || o.value || String(o)) : String(o)))
		} else {
			// Newline or comma separated string
			options = String(rawOptions).split(/[\n,]/).map(s => s.trim()).filter(Boolean)
		}
	}

	const list = document.createElement('div')
	list.className = 'mv-select-filter-list'

	const detailsEl = _wrapFilterRow(col, list, editorState)

	if (options.length === 0) {
		const empty = document.createElement('div')
		empty.className = 'mv-autocomplete-empty'
		empty.textContent = 'Geen opties beschikbaar'
		list.appendChild(empty)
	} else {
		options.forEach(opt => {
			const item = document.createElement('label')
			item.className = 'mv-select-filter-item'

			const cb = document.createElement('input')
			cb.type = 'checkbox'
			cb.checked = tagSet.has(opt)
			cb.addEventListener('change', () => {
				if (cb.checked) tagSet.add(opt)
				else tagSet.delete(opt)
				_updateFilterBadge(detailsEl, tagSet.size)
			})

			item.appendChild(cb)
			item.appendChild(document.createTextNode(opt))
			list.appendChild(item)
		})
	}

	return detailsEl
}

/**
 * Text/number/url/user/textarea field: tag-chips met autocomplete dropdown.
 * Lazy-laadt bestaande DB-waarden bij focus.
 */
function _makeAutocompleteFilterRow(col, editorState) {
	const tagSet = editorState.filters[col.field_id] || new Set()
	editorState.filters[col.field_id] = tagSet

	const wrap = document.createElement('div')
	wrap.className = 'mv-autocomplete-wrap'

	const tagsBox = document.createElement('div')
	tagsBox.className = 'mv-filter-tags'
	wrap.appendChild(tagsBox)

	let detailsEl = null
	let dropdownEl = null
	let allValues = []

	function removeDropdown() {
		dropdownEl?.remove()
		dropdownEl = null
	}

	function renderDropdown(query) {
		removeDropdown()
		const filtered = allValues.filter(v =>
			(!query || v.toLowerCase().includes(query.toLowerCase())),
		)
		if (filtered.length === 0 && !query) return

		dropdownEl = document.createElement('div')
		dropdownEl.className = 'mv-autocomplete-dropdown'

		if (filtered.length === 0) {
			const empty = document.createElement('div')
			empty.className = 'mv-autocomplete-empty'
			empty.textContent = 'Geen overeenkomsten'
			dropdownEl.appendChild(empty)
		} else {
			filtered.forEach(val => {
				const item = document.createElement('div')
				const alreadySelected = tagSet.has(val)
				item.className = 'mv-autocomplete-item' + (alreadySelected ? ' mv-item-selected' : '')
				item.textContent = val
				if (!alreadySelected) {
					item.addEventListener('mousedown', (e) => {
						e.preventDefault()
						tagSet.add(val)
						renderTags()
						removeDropdown()
					})
				}
				dropdownEl.appendChild(item)
			})
		}

		wrap.appendChild(dropdownEl)
	}

	function renderTags() {
		tagsBox.innerHTML = ''
		for (const val of tagSet) {
			const tag = document.createElement('span')
			tag.className = 'mv-filter-tag'
			tag.textContent = val
			const removeBtn = document.createElement('button')
			removeBtn.type = 'button'
			removeBtn.className = 'mv-filter-tag-remove'
			removeBtn.innerHTML = '&times;'
			removeBtn.addEventListener('click', () => { tagSet.delete(val); renderTags() })
			tag.appendChild(removeBtn)
			tagsBox.appendChild(tag)
		}

		const input = document.createElement('input')
		input.type = 'text'
		input.className = 'mv-filter-input'
		input.placeholder = '+ waarde toevoegen'

		input.addEventListener('focus', async () => {
			if (allValues.length === 0) {
				allValues = await _getFilterValues(col.field_name)
			}
			renderDropdown(input.value)
		})

		input.addEventListener('input', () => {
			renderDropdown(input.value)
		})

		input.addEventListener('keydown', (e) => {
			if ((e.key === 'Enter' || e.key === ',') && input.value.trim()) {
				e.preventDefault()
				tagSet.add(input.value.trim())
				renderTags()
				removeDropdown()
			} else if (e.key === 'Escape') {
				removeDropdown()
			} else if (e.key === 'Backspace' && !input.value && tagSet.size > 0) {
				const last = [...tagSet].pop()
				tagSet.delete(last)
				renderTags()
			}
		})

		input.addEventListener('blur', () => {
			// Slight delay so mousedown on dropdown item fires first
			setTimeout(() => {
				removeDropdown()
				if (input.value.trim()) { tagSet.add(input.value.trim()); renderTags() }
			}, 150)
		})

		tagsBox.appendChild(input)
		_updateFilterBadge(detailsEl, tagSet.size)
	}

	detailsEl = _wrapFilterRow(col, wrap, editorState)
	renderTags()
	return detailsEl
}

function _refreshFilterRows(editorState) {
	const body = document.querySelector(`#${VIEW_EDITOR_ID} .mv-editor-body`)
	if (!body) return

	// Remove existing filter rows
	body.querySelectorAll('.mv-filter-row').forEach(r => r.remove())

	// Find the sort section title to insert before it
	const sortTitle = [...body.querySelectorAll('.mv-editor-section-title')].find(el => el.textContent.includes('Sortering'))

	editorState.columns.forEach(col => {
		if (!col.visible || !col.filterable) return
		const filterRow = _makeFilterRow(col, editorState)
		if (!filterRow) return
		filterRow.dataset.filterFieldId = col.field_id
		if (sortTitle) {
			sortTitle.insertAdjacentElement('beforebegin', filterRow)
		} else {
			body.appendChild(filterRow)
		}
	})
}

function _refreshSortSection(editorState) {
	const sortFieldSel = document.querySelector(`#${VIEW_EDITOR_ID} [data-role="sort-field"]`)
	if (!sortFieldSel) return

	const currentVal = editorState.sortField
	sortFieldSel.innerHTML = ''

	const noOpt = document.createElement('option')
	noOpt.value = ''
	noOpt.textContent = '— geen sortering —'
	sortFieldSel.appendChild(noOpt)

	editorState.columns.forEach(col => {
		if (!col.visible) return
		const opt = document.createElement('option')
		opt.value = col.field_name
		opt.textContent = col.field_label
		if (editorState.sortField === col.field_name) opt.selected = true
		sortFieldSel.appendChild(opt)
	})

	// Reset sortField als het geselecteerde veld niet meer zichtbaar is
	const stillVisible = [...sortFieldSel.options].some(o => o.value === currentVal && currentVal !== '')
	if (!stillVisible) {
		editorState.sortField = ''
		sortFieldSel.value = ''
	}
}

async function _saveViewFromEditor(view, editorState) {
	const name = editorState.name.trim()
	if (!name) {
		alert('Vul een naam in voor de weergave')
		return
	}

	const saveBtn = document.querySelector(`#${VIEW_EDITOR_ID} .mv-btn-primary`)
	if (saveBtn) saveBtn.classList.add('loading')

	// Build columns payload: [{field_id, field_label, visible, filterable}]
	const columns = editorState.columns.map(col => ({
		field_id: col.field_id,
		field_name: col.field_name,
		field_label: col.field_label,
		visible: col.visible,
		filterable: col.filterable,
	}))

	// Build filters payload: { field_id: "val1,val2" }
	const filters = {}
	for (const [fieldId, tagSet] of Object.entries(editorState.filters)) {
		if (tagSet.size > 0) {
			filters[fieldId] = [...tagSet].join(',')
		}
	}

	const payload = {
		name,
		is_default: editorState.isDefault,
		columns,
		filters,
		sort_field: editorState.sortField || null,
		sort_order: editorState.sortOrder || null,
	}

	try {
		let savedView
		if (!view) {
			// Create
			const url = generateUrl('/apps/metavox/api/groupfolders/{gfId}/views', { gfId: activeGroupfolderId })
			const resp = await axios.post(url, payload)
			savedView = resp.data
		} else {
			// Update
			const url = generateUrl('/apps/metavox/api/groupfolders/{gfId}/views/{viewId}', {
				gfId: activeGroupfolderId,
				viewId: view.id,
			})
			const resp = await axios.put(url, payload)
			savedView = resp.data
		}

		closeViewEditor()

		// Reload views and rebuild tabs
		const result = await fetchViews(activeGroupfolderId)
		activeViews = result.views
		canManageViews = result.canManage
		injectViewTabs(activeViews)

		// Auto-apply the saved view
		if (savedView) {
			const fi = getFilterInstance()
			if (fi) applyView(savedView, fi)
		}
	} catch (e) {
		console.error('MetaVox: Failed to save view', e)
		const sb = document.querySelector(`#${VIEW_EDITOR_ID} .mv-btn-primary`)
		if (sb) sb.classList.remove('loading')
		alert('Opslaan mislukt: ' + (e.response?.data?.error || e.message))
	}
}

async function _confirmDeleteView(view) {
	if (!confirm(`Weergave "${view.name}" verwijderen?`)) return

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
		alert('Verwijderen mislukt: ' + (e.response?.data?.error || e.message))
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
 * Clear the active view: restore default column visibility and clear filter/sort state.
 */
function clearView() {
	activeView = null
	const fi = getFilterInstance()
	if (fi) {
		fi.reset()
	}

	// No view active — hide columns and update filter registration (reset to empty)
	_applyViewColumns(null)
	registerMetaVoxFilter([], activeGroupfolderId, metadataCache)

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
		// No view active — hide all MetaVox columns
		activeColumnConfigs = []
	} else {
		// Build activeColumnConfigs from visible view columns (they carry full field data)
		const ordered = []
		const usedNames = new Set()
		for (const vc of view.columns) {
			const visible = vc.visible !== false && vc.show_as_column !== false
			if (!visible) continue
			const fieldName = vc.field_name
			if (fieldName && !usedNames.has(fieldName)) {
				ordered.push(vc)
				usedNames.add(fieldName)
			}
		}
		activeColumnConfigs = ordered
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
		removeViewTabs()
		closeViewEditor()
		stopRowObserver()
		columnsActive = false
	}

	if (!groupfolderId) {
		activeGroupfolderId = null
		activeColumnConfigs = []
		availableFields = []
		activeViews = []
		activeView = null
		metadataCache.clear()
		currentSort = null
		return
	}

	activeGroupfolderId = groupfolderId

	// Fetch available fields and views in parallel
	const [fields, viewsResult] = await Promise.all([
		fetchAvailableFields(groupfolderId),
		fetchViews(groupfolderId),
	])

	availableFields = fields
	activeViews = viewsResult.views
	canManageViews = viewsResult.canManage
	activeView = null
	activeColumnConfigs = []

	if (availableFields.length === 0 && !canManageViews) {
		return
	}

	// Bulk load metadata (for all possible fields — view filtering happens in rendering)
	const rows = document.querySelectorAll('tr[data-cy-files-list-row]')
	const fileIds = [...rows].map(r => Number(r.getAttribute('data-cy-files-list-row-fileid'))).filter(Boolean)

	if (fileIds.length > 0) {
		const data = await fetchDirectoryMetadata(groupfolderId, fileIds)
		for (const [fileId, fields2] of Object.entries(data)) {
			metadataCache.set(Number(fileId), fields2)
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
	registerMetaVoxFilter([], groupfolderId, metadataCache)
	injectFooterColumns()
	injectAllExistingRows()
	startRowObserver()

	// Inject view tabs and restore view/filter state from URL
	const filterInstance = getFilterInstance()
	if (activeViews.length > 0 || canManageViews) {
		// Defer injection slightly so the filter bar DOM is ready
		setTimeout(() => {
			injectViewTabs(activeViews)
			if (filterInstance) {
				const params = new URLSearchParams(window.location.search)
				if (params.has('mvview')) {
					restoreViewFromUrl(activeViews, filterInstance)
				} else {
					// No URL state — auto-apply the default view if one is configured
					const defaultView = activeViews.find(v => v.is_default)
					if (defaultView) {
						applyView(defaultView, filterInstance)
					}
				}
			}
		}, 200)
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
