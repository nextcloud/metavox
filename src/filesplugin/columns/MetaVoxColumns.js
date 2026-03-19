/**
 * MetaVox File List Columns — DOM Injection Approach
 *
 * Injects metadata columns directly into the NC33 Files app DOM via MutationObserver.
 * Features: resizable columns, click-to-sort, metadata bulk loading.
 */

import axios from '@nextcloud/axios'
import { showUndo } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
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

/** @type {{ entries: Array<{fileId: number, fieldName: string, oldValue: any}>, toast: object|null } | null} */
let pendingUndo = null

// ========================================
// Undo Support
// ========================================

function pushUndo(entries) {
	if (pendingUndo?.toast) pendingUndo.toast.hideToast()

	const t = translate
	const text = entries.length === 1
		? t('metavox', 'Metadata updated')
		: t('metavox', '{count} cells updated', { count: entries.length })

	const toast = showUndo(text, () => {
		for (const { fileId, fieldName, oldValue } of entries) {
			revertField(fileId, fieldName, oldValue)
		}
		pendingUndo = null
	})
	pendingUndo = { entries, toast }
}

async function revertField(fileId, fieldName, oldValue) {
	const meta = metadataCache.get(fileId) || {}
	meta[fieldName] = oldValue
	metadataCache.set(fileId, meta)
	updateAllRowCells()

	try {
		await axios.post(
			generateUrl(`/apps/metavox/api/groupfolders/${activeGroupfolderId}/files/${fileId}/metadata`),
			{ metadata: { [fieldName]: oldValue } },
		)
		window.dispatchEvent(new CustomEvent('metavox:metadata:saved', {
			detail: { fileId, metadata: { ...meta } },
		}))
	} catch (e) {
		console.error('MetaVox: Failed to undo', e)
	}
}

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
		return value.split(';#').filter(v => v.trim()).join(', ')
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

	// Send only visible field names to reduce query size
	const visibleFields = activeColumnConfigs.map(c => c.field_name).filter(Boolean)
	const params = { file_ids: fileIds.join(',') }
	if (visibleFields.length > 0) {
		params.field_names = visibleFields.join(',')
	}

	try {
		const url = generateOcsUrl(
			'/apps/metavox/api/v1/groupfolders/{groupfolderId}/directory-metadata',
			{ groupfolderId },
		)
		const resp = await axios.get(url, { params })
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

	// Use localStorage cache for instant startup, refresh in background
	const LS_KEY = 'metavox-groupfolders'
	try {
		const cached = localStorage.getItem(LS_KEY)
		if (cached) {
			window._metavoxGroupfolders = JSON.parse(cached)
		}
	} catch (e) { /* ignore */ }

	try {
		const url = generateOcsUrl('/apps/metavox/api/v1/groupfolders')
		const resp = await axios.get(url)
		const data = resp.data?.ocs?.data || resp.data || []
		window._metavoxGroupfolders = data
		try { localStorage.setItem(LS_KEY, JSON.stringify(data)) } catch (e) { /* ignore */ }
	} catch (e) {
		console.error('MetaVox: Failed to load groupfolders', e)
		if (!window._metavoxGroupfolders) {
			window._metavoxGroupfolders = []
		}
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
		const id = Number(fileId)
		// Extract and cache permissions separately
		if (fields._permissions !== undefined) {
			const NC_PERMISSION_UPDATE = 2
			permissionCache.set(id, (fields._permissions & NC_PERMISSION_UPDATE) !== 0)
			delete fields._permissions
		}
		metadataCache.set(id, fields)
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
			overflow-y: auto !important;
		}
		.files-list__table {
			table-layout: auto !important;
		}
		.files-list__row-name {
			min-width: 200px !important;
		}
		/* Data cells */
		.${MARKER_CLASS} {
			flex: 0 0 auto !important;
			padding: 0 8px !important;
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
			box-sizing: border-box;
		}
		/* Header cells */
		.${HEADER_MARKER} {
			flex: 0 0 auto !important;
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
		/* Inline editor styles */
		.metavox-inline-editor {
			width: 100%;
			box-sizing: border-box;
			font: inherit;
			font-size: 13px;
			color: var(--color-main-text);
			background: var(--color-main-background);
			border: 2px solid var(--color-primary-element);
			border-radius: var(--border-radius);
			outline: none;
		}
		.metavox-inline-input,
		.metavox-inline-date {
			padding: 4px 8px;
			height: 32px;
		}
		/* Shared dropdown base (NcSelect-style) */
		.metavox-inline-select,
		.metavox-inline-multiselect {
			padding: 4px 0;
			overflow-y: auto;
			border: none;
			border-radius: var(--border-radius-large, 10px);
			box-shadow: 0 2px 6px var(--color-box-shadow, rgba(0,0,0,.15));
			background: var(--color-main-background);
			cursor: default;
		}
		/* Shared option base */
		.metavox-select-option,
		.metavox-ms-option {
			display: flex;
			align-items: center;
			padding: 0 8px;
			min-height: 40px;
			cursor: pointer;
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
			font-size: var(--default-font-size, 15px);
			line-height: 20px;
			color: var(--color-main-text);
			border-radius: 0;
		}
		.metavox-select-option:hover,
		.metavox-ms-option:hover {
			background: var(--color-background-hover);
		}
		/* Select: selected state */
		.metavox-select-option--selected {
			background: var(--color-primary-element-light, rgba(0,130,201,.1));
			font-weight: 600;
		}
		/* Multiselect: checkbox + gap */
		.metavox-inline-multiselect {
			display: flex;
			flex-direction: column;
		}
		.metavox-ms-option {
			gap: 8px;
		}
		.metavox-ms-option input[type="checkbox"] {
			width: 18px;
			height: 18px;
			min-width: 18px;
			margin: 0;
			accent-color: var(--color-primary-element);
			cursor: pointer;
			border-radius: var(--border-radius, 3px);
		}
		/* Multiselect: action buttons */
		.metavox-ms-actions {
			display: flex;
			gap: 4px;
			padding: 4px 8px;
			border-top: 1px solid var(--color-border);
			margin-top: 2px;
		}
		.metavox-ms-save,
		.metavox-ms-cancel {
			flex: 1;
			min-height: 34px;
			border: none;
			border-radius: var(--border-radius-pill, 20px);
			cursor: pointer;
			font-size: var(--default-font-size, 15px);
			font-weight: 600;
		}
		.metavox-ms-save {
			background: var(--color-primary-element);
			color: var(--color-primary-element-text, #fff);
		}
		.metavox-ms-save:hover {
			background: var(--color-primary-element-hover);
		}
		.metavox-ms-cancel {
			background: var(--color-background-hover);
			color: var(--color-main-text);
		}
		.metavox-ms-cancel:hover {
			background: var(--color-background-dark);
		}
		.${MARKER_CLASS}:hover {
			background: var(--color-background-hover);
		}
		/* Fill handle (Excel-style drag to copy) */
		.metavox-fill-handle {
			position: absolute;
			right: -1px;
			bottom: -1px;
			width: 8px;
			height: 8px;
			background: var(--color-primary-element);
			cursor: crosshair;
			z-index: 10;
			border: 1px solid var(--color-main-background);
		}
		.metavox-fill-highlight {
			outline: 2px solid var(--color-primary-element);
			outline-offset: -2px;
			background: color-mix(in srgb, var(--color-primary-element) 8%, transparent) !important;
		}
	`
	document.head.appendChild(style)
}

function removeColumnStyles() {
	document.getElementById(STYLE_ID)?.remove()
}

function getDefaultColWidth(fieldType) {
	switch (fieldType) {
	case 'checkbox': return 80
	case 'number': return 100
	case 'date': return 120
	case 'select': return 120
	case 'user': return 140
	case 'text': return 150
	case 'multiselect': return 160
	case 'url': return 160
	case 'textarea': return 180
	case 'filelink': return 160
	default: return 150
	}
}

function getColWidth(fieldName, fieldType, fieldLabel) {
	const persisted = columnWidths.get(fieldName)
	if (persisted) return persisted
	const typeDefault = getDefaultColWidth(fieldType)
	if (!fieldLabel) return typeDefault
	// Ensure header text fits: ~7.5px per char at 13px font + 48px for sort icon + padding
	const labelMin = Math.ceil(fieldLabel.length * 7.5) + 48
	return Math.max(typeDefault, labelMin)
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
		const w = getColWidth(config.field_name, config.field_type, config.field_label)
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

// ========================================
// Inline Cell Editing (SharePoint-style)
// ========================================

/** @type {HTMLElement|null} Currently active inline editor */
let activeEditor = null

/** @type {Map<number, boolean>} Cache of file write permissions: fileId -> canEdit */
const permissionCache = new Map()

function canEditFile(fileId) {
	const canEdit = permissionCache.get(fileId)
	// If permissions were loaded from the API, use them; otherwise deny
	return canEdit === true
}

function setupCellEditing(td, config) {
	td.style.cursor = 'pointer'
	td.addEventListener('dblclick', (e) => {
		e.preventDefault()
		e.stopPropagation()

		// Check write permission (loaded from API response)
		const fileId = Number(td.dataset.fileId)

		if (!canEditFile(fileId)) {
			td.style.cursor = 'default'
			return
		}

		openInlineEditor(td, config)
	})
}

function openInlineEditor(td, config) {
	// Don't open if already editing
	if (activeEditor) {
		closeInlineEditor(false)
	}

	const fileId = Number(td.dataset.fileId)
	const fieldName = td.dataset.metavoxField
	const meta = metadataCache.get(fileId) || {}
	const currentValue = meta[fieldName] || ''

	// Store original content for cancel
	td._originalContent = td.textContent
	td._originalValue = currentValue

	let editor

	switch (config.field_type) {
		case 'checkbox':
			// Toggle immediately, no editor needed
			const newVal = currentValue === '1' ? '0' : '1'
			saveSingleField(fileId, fieldName, newVal)
			return

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
				saveSingleField(fileId, fieldName, '')
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
					saveSingleField(fileId, fieldName, opt)
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
			saveBtn.textContent = '✓'
			saveBtn.className = 'metavox-ms-save'
			saveBtn.addEventListener('click', () => {
				const checked = Array.from(container.querySelectorAll('input:checked')).map(c => c.value)
				saveSingleField(fileId, fieldName, checked.join(';#'))
				closeInlineEditor(false)
			})
			const cancelBtn = document.createElement('button')
			cancelBtn.textContent = '✕'
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
				saveSingleField(fileId, fieldName, editor.value)
				closeInlineEditor(false)
			})
			editor.addEventListener('keydown', (e) => {
				if (e.key === 'Escape') closeInlineEditor(true)
				if (e.key === 'Enter') {
					saveSingleField(fileId, fieldName, editor.value)
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
					saveSingleField(fileId, fieldName, editor.value)
					closeInlineEditor(false)
				}
			})
			editor.addEventListener('blur', () => {
				if (activeEditor === td) {
					saveSingleField(fileId, fieldName, editor.value)
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
					saveSingleField(fileId, fieldName, editor.value)
					closeInlineEditor(false)
				}
			})
			editor.addEventListener('blur', () => {
				if (activeEditor === td) {
					saveSingleField(fileId, fieldName, editor.value)
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
	const isDropdown = config.field_type === 'multiselect' || config.field_type === 'select' || config.field_type === 'dropdown'
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
		placeholder.textContent = '…'
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

function handleEditorClickOutside(e) {
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

function closeInlineEditor(cancel) {
	if (!activeEditor) return
	const td = activeEditor
	activeEditor = null

	document.removeEventListener('mousedown', handleEditorClickOutside)

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
		const config = activeColumnConfigs.find(c => c.field_name === fieldName)
		const meta = metadataCache.get(fileId) || {}
		if (config) setCellValue(td, meta[fieldName], config)
	}

	delete td._originalContent
	delete td._originalValue
	td.style.position = ''
}

/**
 * Fill handle: drag down from active editor cell to copy value to cells below.
 * Works like Excel's fill handle — drag the blue square at the bottom-right corner.
 */
function setupFillHandle(handle, sourceTd, config) {
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
		const undoEntries = []
		for (const cell of highlightedCells) {
			cell.classList.remove('metavox-fill-highlight')
			const fileId = Number(cell.dataset.fileId)
			const meta = metadataCache.get(fileId) || {}
			undoEntries.push({ fileId, fieldName, oldValue: meta[fieldName] })
			saveSingleField(fileId, fieldName, value, { skipUndo: true })
			const cellConfig = activeColumnConfigs.find(c => c.field_name === fieldName)
			if (cellConfig) setCellValue(cell, value, cellConfig)
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

async function saveSingleField(fileId, fieldName, newValue, options = {}) {
	// Update cache immediately
	const meta = metadataCache.get(fileId) || {}
	const oldValue = meta[fieldName]

	// Skip if value didn't change
	if (oldValue === newValue) return

	meta[fieldName] = newValue
	metadataCache.set(fileId, meta)

	// Update the cell display
	updateAllRowCells()

	// Save via API
	try {
		await axios.post(
			generateUrl('/apps/metavox/api/groupfolders/{groupfolderId}/files/{fileId}/metadata', {
				groupfolderId: activeGroupfolderId,
				fileId,
			}),
			{ metadata: { [fieldName]: newValue } },
		)

		// Dispatch event so sidebar stays in sync
		window.dispatchEvent(new CustomEvent('metavox:metadata:saved', {
			detail: { fileId, metadata: { ...meta } },
		}))

		// Show undo toast (unless suppressed by fill-handle)
		if (!options.skipUndo) {
			pushUndo([{ fileId, fieldName, oldValue }])
		}
	} catch (e) {
		console.error('MetaVox: Failed to save inline edit', e)
		// Revert on error
		meta[fieldName] = oldValue
		metadataCache.set(fileId, meta)
		updateAllRowCells()
	}
}

function parseFieldOptions(options) {
	if (!options) return []
	if (Array.isArray(options)) return options
	try {
		const parsed = JSON.parse(options)
		if (Array.isArray(parsed)) return parsed
	} catch (e) { /* not JSON */ }
	return options.split('\n').filter(v => v.trim() !== '')
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

// ========================================
// View Tabs + Editor
// ========================================

const VIEW_TABS_ID = 'metavox-view-tabs'
const VIEW_EDITOR_ID = 'metavox-view-editor'
const VIEW_STYLE_ID = 'metavox-view-styles'
let draggedViewId = null

// Need FILTER_ID from MetadataFilter — re-declare locally for DOM lookup
const FILTER_ID = 'metavox-metadata'

function injectViewStyles() {
	if (document.getElementById(VIEW_STYLE_ID)) return
	const style = document.createElement('style')
	style.id = VIEW_STYLE_ID
	style.textContent = `
		/* ── Tab bar container (sticky All + scrollable tabs) ── */
		#${VIEW_TABS_ID} {
			display: flex;
			align-items: center;
			gap: 0;
			padding: var(--default-grid-baseline, 4px) 0;
			border-bottom: 1px solid var(--color-border);
			background: var(--color-main-background);
			position: relative;
		}
		/* Sticky "All" tab */
		#${VIEW_TABS_ID} > .mv-tab-all {
			flex-shrink: 0;
			margin-left: calc(var(--default-grid-baseline, 4px) * 2);
			margin-right: calc(var(--default-grid-baseline, 4px) / 2);
			z-index: 1;
		}
		/* Sticky default view tab */
		#${VIEW_TABS_ID} > .mv-tab-default {
			flex-shrink: 0;
			margin-right: calc(var(--default-grid-baseline, 4px) / 2);
			z-index: 1;
		}
		.mv-tab-default-icon {
			font-size: 11px;
			opacity: 0.6;
			margin-right: 2px;
		}
		/* Scrollable area for view tabs */
		.mv-tabs-scroll {
			display: flex;
			align-items: center;
			gap: calc(var(--default-grid-baseline, 4px) / 2);
			overflow-x: auto;
			overflow-y: hidden;
			flex: 1;
			min-width: 0;
			padding-right: calc(var(--default-grid-baseline, 4px) * 2);
			scrollbar-width: thin;
			scrollbar-color: var(--color-border-dark, #ccc) transparent;
		}
		/* Fade indicators for scroll overflow */
		.mv-tabs-scroll-wrap {
			position: relative;
			display: flex;
			flex: 1;
			min-width: 0;
		}
		.mv-tabs-scroll-wrap::before,
		.mv-tabs-scroll-wrap::after {
			content: '';
			position: absolute;
			top: 0;
			bottom: 0;
			width: 24px;
			pointer-events: none;
			z-index: 1;
			opacity: 0;
			transition: opacity 0.2s ease;
		}
		.mv-tabs-scroll-wrap::before {
			left: 0;
			background: linear-gradient(to right, var(--color-main-background), transparent);
		}
		.mv-tabs-scroll-wrap::after {
			right: 0;
			background: linear-gradient(to left, var(--color-main-background), transparent);
		}
		.mv-tabs-scroll-wrap.can-scroll-left::before { opacity: 1; }
		.mv-tabs-scroll-wrap.can-scroll-right::after { opacity: 1; }
		.mv-tab {
			display: inline-flex;
			align-items: center;
			gap: 4px;
			height: 30px;
			padding: 0 10px;
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
			top: var(--header-height, 50px);
			right: -480px;
			width: 460px;
			max-width: 95vw;
			height: calc(100vh - var(--header-height, 50px));
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
			inset: var(--header-height, 50px) 0 0 0;
			z-index: 1999;
			background: rgba(0, 0, 0, 0.15);
		}
		/* ── Action icon on active tab (pencil for admin, eye for viewer) ── */
		.mv-tab-action-icon {
			cursor: pointer;
			line-height: 1;
			flex-shrink: 0;
			display: inline-flex;
			align-items: center;
			opacity: 0.6;
		}
		.mv-tab-action-icon.mv-tab-edit-icon {
			color: var(--color-primary-element);
		}
		.mv-tab-action-icon.mv-tab-view-icon {
			color: var(--color-text-maxcontrast);
		}
		.mv-tab-action-icon:hover { opacity: 1; }

		/* ── Tab drag-and-drop ── */
		.mv-tab[draggable="true"] { cursor: grab; }
		.mv-tab[draggable="true"]:active { cursor: grabbing; }
		.mv-tab.mv-tab-dragging { opacity: 0.4; }
		.mv-tab.mv-tab-drop-left { box-shadow: -2px 0 0 0 var(--color-primary-element); }
		.mv-tab.mv-tab-drop-right { box-shadow: 2px 0 0 0 var(--color-primary-element); }

		/* ── Responsive (NC33 breakpoints) ── */
		@media only screen and (max-width: 1024px) {
			#${VIEW_TABS_ID} {
				padding: var(--default-grid-baseline, 4px) 0;
			}
			#${VIEW_TABS_ID} > .mv-tab-all,
			#${VIEW_TABS_ID} > .mv-tab-default {
				margin-left: var(--default-grid-baseline, 4px);
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

	// "All" tab — sticky, always visible
	const allTab = document.createElement('button')
	allTab.type = 'button'
	allTab.className = 'mv-tab mv-tab-all' + (!activeView ? ' mv-tab-active' : '')
	allTab.textContent = translate('metavox', 'All')
	allTab.addEventListener('click', () => clearView())
	container.appendChild(allTab)

	// Default view tab — sticky, next to "All"
	const defaultView = views.find(v => v.is_default)
	if (defaultView) {
		const defTab = _makeViewTab(defaultView)
		defTab.classList.add('mv-tab-default')
		// Add star icon before the name
		const star = document.createElement('span')
		star.className = 'mv-tab-default-icon'
		star.textContent = '★'
		defTab.insertBefore(star, defTab.firstChild)
		container.appendChild(defTab)
	}

	// Scrollable wrapper for view tabs
	const scrollWrap = document.createElement('div')
	scrollWrap.className = 'mv-tabs-scroll-wrap'
	const scrollArea = document.createElement('div')
	scrollArea.className = 'mv-tabs-scroll'

	// View tabs (skip default — already rendered as sticky)
	for (const view of views) {
		if (view.is_default) continue
		const tab = _makeViewTab(view)
		scrollArea.appendChild(tab)
	}

	// "+ Add" button
	if (canManageViews) {
		const addBtn = document.createElement('button')
		addBtn.type = 'button'
		addBtn.className = 'mv-tab mv-tab-add'
		addBtn.title = translate('metavox', 'New view')
		addBtn.textContent = '+'
		addBtn.addEventListener('click', () => openViewEditor(null))
		scrollArea.appendChild(addBtn)
	}

	scrollWrap.appendChild(scrollArea)
	container.appendChild(scrollWrap)

	// Update fade indicators on scroll
	const updateFades = () => {
		const sl = scrollArea.scrollLeft
		const maxScroll = scrollArea.scrollWidth - scrollArea.clientWidth
		scrollWrap.classList.toggle('can-scroll-left', sl > 2)
		scrollWrap.classList.toggle('can-scroll-right', maxScroll - sl > 2)
	}
	scrollArea.addEventListener('scroll', updateFades, { passive: true })
	// Initial fade check after DOM insertion
	requestAnimationFrame(updateFades)

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

	const nameSpan = document.createElement('span')
	nameSpan.textContent = view.name
	tab.appendChild(nameSpan)

	if (isActive) {
		const icon = document.createElement('span')
		if (canManageViews) {
			icon.className = 'mv-tab-action-icon mv-tab-edit-icon'
			icon.title = translate('metavox', 'Edit view')
			icon.innerHTML = '<svg viewBox="0 0 24 24" width="11" height="11" fill="currentColor"><path d="M20.71,7.04C21.1,6.65 21.1,6 20.71,5.63L18.37,3.29C18,2.9 17.35,2.9 16.96,3.29L15.12,5.12L18.87,8.87M3,17.25V21H6.75L17.81,9.93L14.06,6.18L3,17.25Z"/></svg>'
			icon.addEventListener('click', (e) => { e.stopPropagation(); openViewEditor(view) })
		} else {
			icon.className = 'mv-tab-action-icon mv-tab-view-icon'
			icon.title = translate('metavox', 'View details')
			icon.innerHTML = '<svg viewBox="0 0 24 24" width="11" height="11" fill="currentColor"><path d="M12,9A3,3 0 0,1 15,12A3,3 0 0,1 12,15A3,3 0 0,1 9,12A3,3 0 0,1 12,9M12,4.5C17,4.5 21.27,7.61 23,12C21.27,16.39 17,19.5 12,19.5C7,19.5 2.73,16.39 1,12C2.73,7.61 7,4.5 12,4.5M3.18,12C4.83,15.36 8.24,17.5 12,17.5C15.76,17.5 19.17,15.36 20.82,12C19.17,8.64 15.76,6.5 12,6.5C8.24,6.5 4.83,8.64 3.18,12Z"/></svg>'
			icon.addEventListener('click', (e) => { e.stopPropagation(); openViewEditor(view, true) })
		}
		tab.appendChild(icon)
	}

	tab.addEventListener('click', () => {
		const fi = getFilterInstance()
		if (fi) applyView(view, fi)
	})

	// Drag-and-drop for reordering (non-default tabs only, when user can manage)
	if (canManageViews && !view.is_default) {
		tab.draggable = true
		tab.addEventListener('dragstart', (e) => {
			draggedViewId = view.id
			tab.classList.add('mv-tab-dragging')
			e.dataTransfer.effectAllowed = 'move'
		})
		tab.addEventListener('dragend', () => {
			draggedViewId = null
			tab.classList.remove('mv-tab-dragging')
			// Clear all drop indicators
			document.querySelectorAll('.mv-tab-drop-left, .mv-tab-drop-right').forEach(el => {
				el.classList.remove('mv-tab-drop-left', 'mv-tab-drop-right')
			})
		})
		tab.addEventListener('dragover', (e) => {
			if (!draggedViewId || draggedViewId === view.id) return
			e.preventDefault()
			e.dataTransfer.dropEffect = 'move'
			// Show drop indicator based on mouse position
			const rect = tab.getBoundingClientRect()
			const mid = rect.left + rect.width / 2
			document.querySelectorAll('.mv-tab-drop-left, .mv-tab-drop-right').forEach(el => {
				el.classList.remove('mv-tab-drop-left', 'mv-tab-drop-right')
			})
			tab.classList.add(e.clientX < mid ? 'mv-tab-drop-left' : 'mv-tab-drop-right')
		})
		tab.addEventListener('dragleave', () => {
			tab.classList.remove('mv-tab-drop-left', 'mv-tab-drop-right')
		})
		tab.addEventListener('drop', (e) => {
			e.preventDefault()
			tab.classList.remove('mv-tab-drop-left', 'mv-tab-drop-right')
			if (!draggedViewId || draggedViewId === view.id) return
			const rect = tab.getBoundingClientRect()
			const mid = rect.left + rect.width / 2
			const dropAfter = e.clientX >= mid
			_handleTabDrop(draggedViewId, view.id, dropAfter)
		})
	} else {
		tab.draggable = false
	}

	return tab
}

function removeViewTabs() {
	viewTabsEl?.remove()
	viewTabsEl = null
}

async function _handleTabDrop(dragId, targetId, dropAfter) {
	// Build new order from current non-default views
	const ids = activeViews.filter(v => !v.is_default).map(v => v.id)
	const fromIdx = ids.indexOf(dragId)
	if (fromIdx === -1) return

	ids.splice(fromIdx, 1)
	let toIdx = ids.indexOf(targetId)
	if (toIdx === -1) return
	if (dropAfter) toIdx++
	ids.splice(toIdx, 0, dragId)

	// Prepend default view id if exists
	const defaultView = activeViews.find(v => v.is_default)
	const allIds = defaultView ? [defaultView.id, ...ids] : ids

	try {
		const reorderUrl = generateUrl('/apps/metavox/api/groupfolders/{gfId}/views/reorder', { gfId: activeGroupfolderId })
		await axios.put(reorderUrl, { view_ids: allIds })
		// Refresh views and re-render tabs
		const result = await fetchViews(activeGroupfolderId)
		activeViews = result.views
		injectViewTabs(activeViews)
	} catch (err) {
		console.error('MetaVox: Failed to reorder views', err)
	}
}

function updateActiveTabs() {
	if (!viewTabsEl) return

	viewTabsEl.querySelectorAll('.mv-tab').forEach(tab => {
		const viewId = tab.dataset.viewId
		if (!viewId) {
			// "All" tab — preserve mv-tab-all class
			const isAll = tab.classList.contains('mv-tab-all')
			tab.className = 'mv-tab' + (isAll ? ' mv-tab-all' : '') + (!activeView ? ' mv-tab-active' : '')
			return
		}

		const isDefault = tab.classList.contains('mv-tab-default')
		const isActive = activeView && String(activeView.id) === String(viewId)
		if (isActive) {
			tab.className = 'mv-tab mv-tab-active' + (isDefault ? ' mv-tab-default' : '')
			tab.querySelector('.mv-tab-dot')?.remove()
			// Add action icon if not already present
			if (!tab.querySelector('.mv-tab-action-icon')) {
				const view = activeViews.find(v => String(v.id) === String(viewId))
				if (view) {
					const icon = document.createElement('span')
					if (canManageViews) {
						icon.className = 'mv-tab-action-icon mv-tab-edit-icon'
						icon.title = translate('metavox', 'Edit view')
						icon.innerHTML = '<svg viewBox="0 0 24 24" width="11" height="11" fill="currentColor"><path d="M20.71,7.04C21.1,6.65 21.1,6 20.71,5.63L18.37,3.29C18,2.9 17.35,2.9 16.96,3.29L15.12,5.12L18.87,8.87M3,17.25V21H6.75L17.81,9.93L14.06,6.18L3,17.25Z"/></svg>'
						icon.addEventListener('click', (e) => { e.stopPropagation(); openViewEditor(view) })
					} else {
						icon.className = 'mv-tab-action-icon mv-tab-view-icon'
						icon.title = translate('metavox', 'View details')
						icon.innerHTML = '<svg viewBox="0 0 24 24" width="11" height="11" fill="currentColor"><path d="M12,9A3,3 0 0,1 15,12A3,3 0 0,1 12,15A3,3 0 0,1 9,12A3,3 0 0,1 12,9M12,4.5C17,4.5 21.27,7.61 23,12C21.27,16.39 17,19.5 12,19.5C7,19.5 2.73,16.39 1,12C2.73,7.61 7,4.5 12,4.5M3.18,12C4.83,15.36 8.24,17.5 12,17.5C15.76,17.5 19.17,15.36 20.82,12C19.17,8.64 15.76,6.5 12,6.5C8.24,6.5 4.83,8.64 3.18,12Z"/></svg>'
						icon.addEventListener('click', (e) => { e.stopPropagation(); openViewEditor(view, true) })
					}
					tab.appendChild(icon)
				}
			}
		} else {
			tab.className = 'mv-tab' + (isDefault ? ' mv-tab-default' : '')
			tab.querySelector('.mv-tab-dot')?.remove()
			tab.querySelector('.mv-tab-action-icon')?.remove()
		}
	})

	// Scroll active tab into view within the scroll area
	const activeTabEl = viewTabsEl.querySelector('.mv-tab-active[data-view-id]')
	if (activeTabEl) {
		activeTabEl.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' })
	}
}

// ── View Editor Panel ──────────────────────────────────────────

/**
 * Open the slide-over view editor (Vue component).
 * @param {Object|null} view - Existing view to edit, or null for new
 * @param {boolean} readonly - Open in readonly mode (view details only)
 */
function openViewEditor(view, readonly = false) {
	// Remove existing editor
	closeViewEditor()

	// Close NC Details sidebar if open
	if (window.OCA?.Files?.Sidebar?.close) {
		window.OCA.Files.Sidebar.close()
	}

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
			readonly,
			availableFields,
			totalViews: activeViews.length,
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

		// If position was changed, reorder all views
		const desiredPos = payload.position
		if (desiredPos !== undefined && desiredPos !== null) {
			// Fetch current view list to compute new order
			const currentResult = await fetchViews(activeGroupfolderId)
			const currentViews = currentResult.views || []
			const savedId = savedView?.id
			if (savedId && currentViews.length > 1) {
				// Build ordered ID list, then move savedId to desiredPos
				const ids = currentViews.map(v => v.id)
				const fromIdx = ids.indexOf(savedId)
				if (fromIdx !== -1 && fromIdx !== desiredPos) {
					ids.splice(fromIdx, 1)
					ids.splice(desiredPos, 0, savedId)
					try {
						const reorderUrl = generateUrl('/apps/metavox/api/groupfolders/{gfId}/views/reorder', { gfId: activeGroupfolderId })
						await axios.put(reorderUrl, { view_ids: ids })
					} catch (reorderErr) {
						console.error('MetaVox: Failed to reorder views', reorderErr)
					}
				}
			}
		}

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

	// Close NC Details sidebar — the displayed file may not exist in the new view
	if (window.OCA?.Files?.Sidebar?.close) {
		window.OCA.Files.Sidebar.close()
	}

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

	// Close NC Details sidebar — view context is changing
	if (window.OCA?.Files?.Sidebar?.close) {
		window.OCA.Files.Sidebar.close()
	}
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

	// Reset table width before re-injecting so it shrinks when fewer columns are shown
	const table = document.querySelector('.files-list__table')
	if (table) table.style.minWidth = ''
	const filesList = document.querySelector('.files-list')
	if (filesList) filesList.style.minWidth = ''

	// Re-inject header/footer/row columns with updated config
	injectHeaderColumns()
	injectFooterColumns()

	// Batch remove all existing data cells in one query, then re-inject per row
	document.querySelectorAll('tr[data-cy-files-list-row] .' + MARKER_CLASS).forEach(el => el.remove())
	document.querySelectorAll('tr[data-cy-files-list-row]').forEach(row => {
		injectRowColumns(row)
	})

	// Recalculate table min-width based on new column layout
	requestAnimationFrame(() => updateTableMinWidth())
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

export async function updateColumnsForCurrentFolder(prefetched = null) {
	await loadGroupfolders()

	const groupfolderId = prefetched?.gfId ?? detectCurrentGroupfolder()

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

	// Use prefetched data if available, otherwise fetch in parallel
	let fields, viewsResult, filterValues
	if (prefetched && prefetched.gfId === groupfolderId) {
		fields = prefetched.fields
		viewsResult = prefetched.viewsResult
		filterValues = prefetched.filterValues
	} else {
		[fields, viewsResult, filterValues] = await Promise.all([
			fetchAvailableFields(groupfolderId),
			fetchViews(groupfolderId),
			fetchAllFilterValues(groupfolderId),
		])
	}

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
			const NC_PERMISSION_UPDATE = 2
			for (const [fileId, fields2] of Object.entries(data)) {
				const id = Number(fileId)
				if (fields2._permissions !== undefined) {
					permissionCache.set(id, (fields2._permissions & NC_PERMISSION_UPDATE) !== 0)
					delete fields2._permissions
				}
				metadataCache.set(id, fields2)
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
	// Start prefetching data immediately — don't wait for the table.
	// This fires API calls in parallel with Nextcloud's Vue rendering,
	// so views/fields/filters are ready the moment the table appears.
	// Try to use inline initial state (zero latency), fall back to API call
	const prefetchPromise = (async () => {
		let data = null

		// Check for server-inlined init data (no API call needed)
		try {
			data = loadState('metavox', 'init', null)
		} catch (e) { /* not available */ }

		// Fallback: single init API call
		if (!data) {
			try {
				const dir = new URLSearchParams(window.location.search).get('dir') || ''
				const url = generateUrl('/apps/metavox/api/init')
				const resp = await axios.get(url, { params: { dir } })
				data = resp.data || {}
			} catch (e) {
				console.error('MetaVox: init failed', e)
				return null
			}
		}

		window._metavoxGroupfolders = data.groupfolders || []
		if (!data.groupfolder_id) return null
		return {
			gfId: data.groupfolder_id,
			fields: data.fields || [],
			viewsResult: { views: data.views || [], canManage: data.can_manage === true },
			filterValues: data.filter_values || {},
		}
	})()

	const check = () => {
		const table = document.querySelector('.files-list__table tbody')
		if (table && table.children.length > 0) {
			prefetchPromise.then((prefetched) => {
				updateColumnsForCurrentFolder(prefetched)
			})
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
