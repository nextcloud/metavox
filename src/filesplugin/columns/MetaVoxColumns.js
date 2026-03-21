/**
 * MetaVox File List Columns — Orchestrator
 *
 * Thin orchestrator that imports from extracted modules and wires them together.
 * Contains only: dependency wiring, scheduleInjection, row observer,
 * updateColumnsForCurrentFolder, startColumnWatcher, and re-exports.
 */

import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'

// ── Module imports ──────────────────────────────────────────────

import {
	getActiveColumnConfigs,
	setActiveColumnConfigs,
	getActiveGroupfolderId,
	setActiveGroupfolderId,
	metadataCache,
	getColumnsActive,
	setColumnsActive,
	getRowObserver,
	setRowObserver,
	columnWidths,
	setActiveViews,
	setActiveView,
	setCanManageViews,
	setAvailableFields,
	getPrefetchedFilterValues,
	setPrefetchedFilterValues,
	setCachedActionsWidth,
	getInitialStateConsumed,
	setInitialStateConsumed,
	setMetavoxGroupfolders,
	getMetavoxGroupfolders,
	setMetavoxAllGfData,
	getMetavoxAllGfData,
} from './MetaVoxState.js'

import { formatValue, getColWidth } from './ColumnUtils.js'

import { setUpdateAllRowCells as setUndoUpdateAllRowCells } from './UndoSupport.js'

import {
	loadGroupfolders,
	detectCurrentGroupfolder,
	fetchAvailableFields,
	fetchDirectoryMetadata,
	fetchViews,
	fetchAllFilterValues,
	setUpdateAllRowCells as setApiUpdateAllRowCells,
} from './MetaVoxAPI.js'

import {
	queueMetadataLoad,
	loadAllMetadata,
	setFindFilesList,
	setEnsureSortBypass,
} from './MetadataLoader.js'

import { injectColumnStyles, removeColumnStyles } from './ColumnStyles.js'

import {
	setDependencies as setColumnDOMDeps,
	injectHeaderColumns,
	injectFooterColumns,
	injectAllExistingRows,
	removeAllInjectedColumns,
	updateAllRowCells,
	injectRowColumns,
	_refreshRowCells,
} from './ColumnDOM.js'

import {
	setUpdateAllRowCells as setInlineEditorUpdateAllRowCells,
	setGetActiveColumnConfigs,
	setSetCellValue,
	setupCellEditing,
} from './InlineEditor.js'

import {
	setDependencies as setResizeDeps,
	startResize,
	loadPersistedWidths,
} from './ColumnResize.js'

import {
	handleSort,
	ensureSortBypass,
	uninstallSortBypass,
	_findFilesList,
} from './Sorting.js'

import {
	injectViewTabs,
	removeViewTabs,
	closeViewEditor,
	applyView,
	restoreViewFromUrl,
	buildDefaultFilterConfigs,
} from './ViewManager.js'

import {
	registerMetaVoxFilter,
	removeFilters,
	getFilterInstance,
} from './MetadataFilter.js'

import { setCellValue } from './ColumnDOM.js'

// ========================================
// Dependency Wiring
// ========================================

/**
 * Wire all cross-module dependencies. Called once at startup.
 */
function wireDependencies() {
	// ColumnDOM needs: activeColumnConfigs (getter pattern), metadataCache,
	// _cachedActionsWidth, formatValue, getColWidth, queueMetadataLoad,
	// setupCellEditing, startResize, handleSort
	setColumnDOMDeps({
		get activeColumnConfigs() { return getActiveColumnConfigs() },
		metadataCache,
		_cachedActionsWidth: null, // will be read via getter set below
		formatValue,
		getColWidth,
		queueMetadataLoad,
		setupCellEditing,
		startResize,
		handleSort,
	})

	// ColumnResize needs columnWidths
	setResizeDeps({ columnWidths })

	// UndoSupport needs updateAllRowCells callback
	setUndoUpdateAllRowCells(updateAllRowCells)

	// MetaVoxAPI needs updateAllRowCells callback
	setApiUpdateAllRowCells(updateAllRowCells)

	// InlineEditor needs updateAllRowCells, getActiveColumnConfigs, setCellValue
	setInlineEditorUpdateAllRowCells(updateAllRowCells)
	setGetActiveColumnConfigs(() => getActiveColumnConfigs())
	setSetCellValue(setCellValue)

	// MetadataLoader needs _findFilesList and ensureSortBypass
	setFindFilesList(_findFilesList)
	setEnsureSortBypass(ensureSortBypass)
}

// Wire immediately on module load
wireDependencies()

// ========================================
// Row Observer
// ========================================

function startRowObserver() {
	if (getRowObserver()) return

	const tbody = document.querySelector('.files-list__table tbody')
	if (!tbody) {
		setTimeout(startRowObserver, 300)
		return
	}

	// FilesList is now guaranteed to be mounted — install sort bypass
	ensureSortBypass()

	const observer = new MutationObserver((mutations) => {
		if (!getColumnsActive()) return

		for (const mutation of mutations) {
			// New rows added to tbody
			if (mutation.type === 'childList') {
				for (const node of mutation.addedNodes) {
					if (node.nodeType === 1 && node.matches?.('tr[data-cy-files-list-row]')) {
						injectRowColumns(node)
					}
				}
			}
			// Virtual scroll: NC33 recycles rows by changing the fileid attribute
			if (mutation.type === 'attributes' && mutation.attributeName === 'data-cy-files-list-row-fileid') {
				const row = mutation.target
				if (row.matches?.('tr[data-cy-files-list-row]')) {
					_refreshRowCells(row)
				}
			}
		}
	})

	observer.observe(tbody, {
		childList: true,
		subtree: true,
		attributes: true,
		attributeFilter: ['data-cy-files-list-row-fileid'],
	})

	setRowObserver(observer)

	// Dynamically compute sticky offsets for the stacked headers:
	// tabs (top:0) -> filters (top: tabs height) -> thead (top: tabs + filters height)
	const VIEW_TABS_ID = 'metavox-view-tabs'

	function updateStickyOffsets() {
		const tabs = document.querySelector('#' + VIEW_TABS_ID)
		const filters = document.querySelector('.files-list__filters')
		const filesList = document.querySelector('.files-list')
		if (!filesList) return
		const tabsH = tabs ? Math.round(tabs.getBoundingClientRect().height) : 0
		const filtersH = filters ? Math.round(filters.getBoundingClientRect().height) : 0
		filesList.style.setProperty('--mv-filters-top', tabsH + 'px')
		filesList.style.setProperty('--mv-thead-top', (tabsH + filtersH) + 'px')
	}
	updateStickyOffsets()
	const filtersEl = document.querySelector('.files-list__filters')
	if (filtersEl) {
		new ResizeObserver(updateStickyOffsets).observe(filtersEl)
	}
	const tabsEl = document.querySelector('#' + VIEW_TABS_ID)
	if (tabsEl) {
		new ResizeObserver(updateStickyOffsets).observe(tabsEl)
	}
}

function stopRowObserver() {
	const observer = getRowObserver()
	if (observer) {
		observer.disconnect()
		setRowObserver(null)
	}
}

// ========================================
// Main Flow
// ========================================

export async function updateColumnsForCurrentFolder(prefetched = null) {
	await loadGroupfolders()

	const groupfolderId = prefetched?.gfId ?? detectCurrentGroupfolder()

	if (groupfolderId === getActiveGroupfolderId() && getColumnsActive()) {
		return
	}

	// Clean up
	if (getColumnsActive()) {
		uninstallSortBypass()
		removeAllInjectedColumns()
		removeColumnStyles()
		removeFilters()
		removeViewTabs()
		closeViewEditor()
		stopRowObserver()
		setColumnsActive(false)
		setCachedActionsWidth(null)
	}

	if (!groupfolderId) {
		setActiveGroupfolderId(null)
		setActiveColumnConfigs([])
		setAvailableFields([])
		setActiveViews([])
		setActiveView(null)
		metadataCache.clear()
		setPrefetchedFilterValues(null)
		const _fi = getFilterInstance()
		if (_fi) _fi.setSortState(null)
		setCachedActionsWidth(null)
		document.querySelector('.files-list')?.classList.remove('metavox-loading')
		return
	}

	setActiveGroupfolderId(groupfolderId)

	// Use prefetched data if available, otherwise fetch fields + views
	let fields, viewsResult
	if (prefetched && prefetched.gfId === groupfolderId) {
		fields = prefetched.fields
		viewsResult = prefetched.viewsResult
		const fv = prefetched.filterValues
		setPrefetchedFilterValues((fv && Object.keys(fv).length > 0) ? fv : null)
	} else {
		[fields, viewsResult] = await Promise.all([
			fetchAvailableFields(groupfolderId),
			fetchViews(groupfolderId),
		])
		setPrefetchedFilterValues(null) // Will be lazy-loaded when needed
	}

	setAvailableFields(fields)
	setActiveViews(viewsResult.views)
	setCanManageViews(viewsResult.canManage)
	setActiveView(null)
	setActiveColumnConfigs([])

	if (fields.length === 0 && !viewsResult.canManage) {
		return
	}

	setColumnsActive(true)
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
	if (viewsResult.views.length > 0 || viewsResult.canManage) {
		injectViewTabs(viewsResult.views)
		const params = new URLSearchParams(window.location.search)
		if (params.has('mvview')) {
			restoreViewFromUrl(viewsResult.views, filterInstance)
		} else {
			const defaultView = viewsResult.views.find(v => v.is_default)
			if (defaultView) {
				applyView(defaultView, filterInstance)
			}
		}
	}

	// Load metadata for ALL files in the folder
	loadAllMetadata(groupfolderId)

	// Watch dirContents for changes — NC33 populates it asynchronously.
	// When it grows, load any new uncached files in one batch.
	const fl = _findFilesList()
	if (fl) {
		let lastCount = fl.dirContents?.length || 0
		const unwatchTimer = setInterval(() => {
			if (!getColumnsActive() || getActiveGroupfolderId() !== groupfolderId) {
				clearInterval(unwatchTimer)
				return
			}
			const currentCount = fl.dirContents?.length || 0
			if (currentCount > lastCount) {
				lastCount = currentCount
				loadAllMetadata(groupfolderId)
			}
		}, 500)
		// Stop after 10s — dirContents should be stable by then
		setTimeout(() => clearInterval(unwatchTimer), 10000)
	}

	// Background: prefetch filter values scoped to current directory
	if (!getPrefetchedFilterValues() && groupfolderId) {
		// Delay slightly so dirContents is populated
		setTimeout(() => {
			const fl2 = _findFilesList()
			const ids = fl2?.dirContents?.map(n => n.fileid).filter(Boolean) || [...metadataCache.keys()]
			fetchAllFilterValues(groupfolderId, ids).then(values => {
				if (values && Object.keys(values).length > 0) {
					setPrefetchedFilterValues(values)
				}
			}).catch(() => {})
		}, 2000)
	}

	// Clear loading state — everything is injected, metadata loads in background
	document.querySelector('.files-list')?.classList.remove('metavox-loading')
}

// ========================================
// Schedule Injection (IInitialState + DOM wait)
// ========================================

function scheduleInjection() {
	// Skip entirely if not logged in
	if (!document.querySelector('head[data-user]')?.dataset?.user) return

	const prefetchPromise = (async () => {
		// First load: use server-inlined init data (instant)
		if (!getInitialStateConsumed()) {
			setInitialStateConsumed(true)
			try {
				const data = loadState('metavox', 'init', null)
				if (data) {
					setMetavoxGroupfolders(data.groupfolders || [])
					setMetavoxAllGfData(data.all_gf_data || {})
				}
			} catch (e) { /* not available */ }
		}

		// Ensure groupfolders are loaded
		if (!getMetavoxGroupfolders()) {
			await loadGroupfolders()
		}

		// Detect groupfolder from URL
		const gfId = detectCurrentGroupfolder()
		if (!gfId) return null

		// Use cached data if available (inline or background prefetch)
		const cached = getMetavoxAllGfData()?.[gfId]
		if (cached) {
			return {
				gfId,
				fields: cached.fields || [],
				viewsResult: { views: cached.views || [], canManage: cached.can_manage === true },
				filterValues: cached.filter_values || {},
			}
		}

		// Fallback: fetch via /api/init
		try {
			const url = generateUrl('/apps/metavox/api/init')
			const dir = new URLSearchParams(window.location.search).get('dir') || ''
			const params = { dir }
			if (gfId) params.gf_id = gfId
			const resp = await axios.get(url, { params })
			const data = resp.data || {}

			// Cache for future navigation
			let allGfData = getMetavoxAllGfData()
			if (!allGfData) {
				allGfData = {}
				setMetavoxAllGfData(allGfData)
			}
			allGfData[gfId] = {
				fields: data.fields || [],
				views: data.views || [],
				can_manage: data.can_manage === true,
				filter_values: data.filter_values || {},
			}

			return {
				gfId,
				fields: data.fields || [],
				viewsResult: { views: data.views || [], canManage: data.can_manage === true },
				filterValues: data.filter_values || {},
			}
		} catch (e) {
			console.error('MetaVox: init failed', e)
			return null
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

// ========================================
// Column Watcher (URL change detection)
// ========================================

export function startColumnWatcher() {
	// Track only the `dir` param — our own URL updates (mvfilter/mvview) must not
	// trigger a full column teardown.
	const getDirParam = () => new URLSearchParams(window.location.search).get('dir') || ''
	let lastDir = getDirParam()

	const checkNavigation = () => {
		const currentDir = getDirParam()
		if (currentDir !== lastDir) {
			lastDir = currentDir
			// Show loading state while new folder loads
			document.querySelector('.files-list')?.classList.add('metavox-loading')
			if (getColumnsActive()) {
				uninstallSortBypass()
				removeAllInjectedColumns()
				removeColumnStyles()
				removeFilters()
				removeViewTabs()
				closeViewEditor()
				stopRowObserver()
				setColumnsActive(false)
				setActiveGroupfolderId(null)
			}
			metadataCache.clear()
			const _fi2 = getFilterInstance()
			if (_fi2) _fi2.setSortState(null)
			setActiveViews([])
			setActiveView(null)
			setAvailableFields([])
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

	// Real-time metadata sync via notify_push
	_startPushListener()

	scheduleInjection()
}

/**
 * Listen for push notifications when other users change metadata.
 * Uses Nextcloud's global _notify_push_listeners to register a callback.
 * Polls for availability since notify_push may load after MetaVox.
 */
function _handlePushEvent(eventName, body) {
	const gfId = body.gfId || 0
	const fileId = body.fileId || 0

	if (eventName === 'metavox_metadata_changed') {
		if (gfId && gfId !== getActiveGroupfolderId()) return
		if (fileId && getActiveGroupfolderId()) {
			fetchDirectoryMetadata(getActiveGroupfolderId(), [fileId]).then(data => {
				for (const [fid, fields] of Object.entries(data)) {
					const id = Number(fid)
					if (fields._permissions !== undefined) delete fields._permissions
					metadataCache.set(id, fields)
				}
				updateAllRowCells()
			})
		} else {
			metadataCache.clear()
			loadAllMetadata(getActiveGroupfolderId())
		}
	} else if (eventName === 'metavox_cell_locked' || eventName === 'metavox_cell_unlocked') {
		if (gfId && gfId !== getActiveGroupfolderId()) return
		const fieldName = body.fieldName || ''
		const userId = body.userId || ''
		const cell = document.querySelector(`.metavox-col[data-file-id="${fileId}"][data-metavox-field="${fieldName}"]`)
		if (!cell) return

		// Don't show lock indicator for own edits
		const currentUser = document.querySelector('head[data-user]')?.dataset?.user
		if (eventName === 'metavox_cell_locked' && userId === currentUser) return

		if (eventName === 'metavox_cell_locked') {
			cell.style.backgroundColor = 'rgba(233, 163, 28, 0.15)'
			cell.style.boxShadow = 'inset 0 0 0 2px #e9a31c'
			cell.style.cursor = 'not-allowed'
			cell.dataset.lockedBy = userId
			cell._metavoxLocked = true

			// Show persistent tooltip on hover
			let tooltip = cell.querySelector('.metavox-lock-tooltip')
			if (!tooltip) {
				tooltip = document.createElement('div')
				tooltip.className = 'metavox-lock-tooltip'
				tooltip.style.cssText = 'display:none;position:absolute;bottom:100%;left:50%;transform:translateX(-50%);background:#333;color:#fff;padding:4px 10px;border-radius:6px;font-size:12px;white-space:nowrap;z-index:9999;pointer-events:none;margin-bottom:4px;'
				cell.style.position = 'relative'
				cell.style.overflow = 'visible'
				cell.appendChild(tooltip)
				cell.addEventListener('mouseenter', () => { if (cell._metavoxLocked && tooltip) tooltip.style.display = 'block' })
				cell.addEventListener('mouseleave', () => { if (tooltip) tooltip.style.display = 'none' })
			}
			tooltip.textContent = `🔒 ${userId}`
		} else {
			cell.style.backgroundColor = ''
			cell.style.boxShadow = ''
			cell.style.cursor = ''
			cell.style.position = ''
			cell.style.overflow = ''
			delete cell.dataset.lockedBy
			delete cell._metavoxLocked
			const tooltip = cell.querySelector('.metavox-lock-tooltip')
			if (tooltip) tooltip.remove()
		}
	}
}

function _startPushListener() {
	const patchWs = (ws) => {
		if (!ws || ws._metavoxPatched) return
		ws._metavoxPatched = true
		const origOnMessage = ws.onmessage
		ws.onmessage = (event) => {
			const raw = event.data || ''
			if (typeof raw === 'string' && raw.startsWith('metavox_')) {
				const spaceIdx = raw.indexOf(' ')
				const evtName = spaceIdx > 0 ? raw.substring(0, spaceIdx) : raw
				let body = {}
				if (spaceIdx > 0) {
					try { body = JSON.parse(raw.substring(spaceIdx + 1)) } catch (e) { /* */ }
				}
				_handlePushEvent(evtName, body)
			}
			if (origOnMessage) origOnMessage.call(ws, event)
		}
	}

	const register = () => {
		const ws = window._notify_push_ws
		if (!ws) return false
		patchWs(ws)

		// Also watch for WebSocket replacements (reconnects)
		if (!window._metavoxWsWatcher) {
			window._metavoxWsWatcher = true
			let currentWs = ws
			setInterval(() => {
				if (window._notify_push_ws && window._notify_push_ws !== currentWs) {
					currentWs = window._notify_push_ws
					patchWs(currentWs)
				}
			}, 2000)
		}

		return true
	}

	if (register()) return

	// Poll until notify_push is available (loads async)
	let attempts = 0
	const poll = setInterval(() => {
		attempts++
		if (register() || attempts > 30) {
			clearInterval(poll)
		}
	}, 1000)
}

// ========================================
// Re-exports for external consumers
// ========================================

// Re-export state getters used by ViewEditorPanel.vue, MetaVoxFilterPanel.vue, etc.
export {
	getNcVersion,
	getActiveColumnConfigs,
	getActiveGroupfolderId,
	getPrefetchedFilterValues,
} from './MetaVoxState.js'

export async function ensureFilterValues() {
	if (!getPrefetchedFilterValues() && getActiveGroupfolderId()) {
		const fl = _findFilesList()
		const fileIds = fl?.dirContents?.map(n => n.fileid).filter(Boolean) || [...metadataCache.keys()]
		const values = await fetchAllFilterValues(getActiveGroupfolderId(), fileIds)
		setPrefetchedFilterValues(values)
	}
	return getPrefetchedFilterValues()
}
