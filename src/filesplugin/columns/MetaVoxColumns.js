/**
 * MetaVox File List Columns — Orchestrator
 *
 * Thin orchestrator that imports from extracted modules and wires them together.
 * Contains only: dependency wiring, scheduleInjection, row observer,
 * updateColumnsForCurrentFolder, startColumnWatcher, and re-exports.
 */

import axios from '@nextcloud/axios'
import { getCurrentUser } from '@nextcloud/auth'
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
	getActiveView,
	setActiveView,
	getDefaultChosen,
	setDefaultChosen,
	setCanManageViews,
	setAvailableFields,
	getInitialStateConsumed,
	setInitialStateConsumed,
	setMetavoxGroupfolders,
	getMetavoxGroupfolders,
	setMetavoxAllGfData,
	getMetavoxAllGfData,
} from './MetaVoxState.js'
import { onFilelinkNames } from './FilelinkNames.js'
import { onAccountNames, setViewerItems } from '../../utils/accountNames.js'
import { leaveOnClose, showingTeamFolder } from './Presence.js'

import { formatValue, getColWidth } from './ColumnUtils.js'


import {
	loadGroupfolders,
	detectCurrentGroupfolder,
	fetchAvailableFields,
	fetchViews,
	setUpdateAllRowCells as setApiUpdateAllRowCells,
} from './MetaVoxAPI.js'

import {
	queueMetadataLoad,
	loadViaApi,
	setAfterLoad,
} from './MetadataLoader.js'
import { invalidateAll, switchGroupfolder, touch } from './MetadataStore.js'
import {
	catchUp,
	getListingIds,
	getListingNode,
	isListingLoading,
	refreshListing,
	reseedListing,
	setListingHandler,
} from './ListingSync.js'
import { decideMetadataPush, shouldRefreshOnReturn } from './SyncRules.js'

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
	clearCellLock,
} from './ColumnDOM.js'

import {
	setUpdateAllRowCells as setInlineEditorUpdateAllRowCells,
	setGetActiveColumnConfigs,
	setSetCellValue,
	setupCellEditing,
	updateCellAccessibleName,
	closeInlineEditor,
	getActiveEditorCell,
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
} from './Sorting.js'
import { _findFilesList } from './FilesListRef.js'

import {
	removeViewTabs,
	closeViewEditor,
	buildDefaultFilterConfigs,
	openFolderView,
} from './ViewManager.js'
import { takeLink, watchFolder } from './UrlState.js'

import {
	registerMetaVoxFilter,
	removeFilters,
	getFilterInstance,
	updateFilterCache,
} from './MetadataFilter.js'

import { setCellValue } from './ColumnDOM.js'
import { setDependencies as setCellFormatDeps, watchDayChange } from './CellFormat.js'
import { listenForPushes } from './PushListener.js'
import { stopStickyOffsets, watchStickyOffsets } from './StickyOffsets.js'
import { watchRows } from './RowWatch.js'

// ========================================
// Dependency Wiring
// ========================================

/**
 * Wire all cross-module dependencies. Called once at startup.
 */
function wireDependencies() {
	// ColumnDOM needs: activeColumnConfigs (getter pattern), metadataCache,
	// formatValue, getColWidth, queueMetadataLoad, setupCellEditing,
	// startResize, handleSort
	setColumnDOMDeps({
		get activeColumnConfigs() { return getActiveColumnConfigs() },
		metadataCache,
		formatValue,
		getColWidth,
		queueMetadataLoad,
		setupCellEditing,
		updateCellAccessibleName,
		startResize,
		handleSort,
		getActiveEditorCell,
		closeInlineEditor,
	})

	setCellFormatDeps({ getActiveEditorCell, updateCellAccessibleName, currentUserId: () => getCurrentUser()?.uid ?? null })

	// ColumnResize needs columnWidths
	setResizeDeps({ columnWidths })

	// MetaVoxAPI needs updateAllRowCells callback
	setApiUpdateAllRowCells(updateAllRowCells)

	// Current names of linked files arrive after the cells are drawn
	onFilelinkNames(() => updateAllRowCells())
	// Names mostly come with the values; one that comes later redraws the People columns.
	onAccountNames(() => {
		if (getActiveColumnConfigs().some((config) => config.field_type === 'user')) updateAllRowCells()
	})

	// InlineEditor needs updateAllRowCells, getActiveColumnConfigs, setCellValue
	setInlineEditorUpdateAllRowCells(updateAllRowCells)
	setGetActiveColumnConfigs(() => getActiveColumnConfigs())
	setSetCellValue(setCellValue)

	// After an API load: redraw the cells, re-apply sorting and filters.
	setAfterLoad(() => {
		updateAllRowCells()
		ensureSortBypass()
		updateFilterCache(metadataCache)
	})

	// Every listing: its values are in the store already (nc:metavox). Redraw,
	// and load through the API only what the listing did not deliver.
	setListingHandler(({ gf, loading }) => {
		if (!loading) {
			document.querySelector('.files-list')?.classList.remove('metavox-loading')
		}
		if (!getColumnsActive()) {
			return
		}
		if (gf !== null && gf !== getActiveGroupfolderId()) {
			// The server says this listing is in another team folder: detect again.
			scheduleInjection()
			return
		}
		updateAllRowCells()
		updateFilterCache(metadataCache)
		if (loading) {
			return
		}
		const missing = getListingIds().filter((id) => !metadataCache.has(id))
		if (missing.length) {
			queueMetadataLoad(missing)
		}
	})
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

	const observer = watchRows(tbody, {
		added: (row) => { if (getColumnsActive()) injectRowColumns(row) },
		// Virtual scroll: NC33 recycles rows by changing the fileid attribute
		reused: (row) => { if (getColumnsActive()) _refreshRowCells(row) },
	})

	setRowObserver(observer)

	watchStickyOffsets()
}

function stopRowObserver() {
	const observer = getRowObserver()
	if (observer) {
		observer.disconnect()
		setRowObserver(null)
	}
	stopStickyOffsets()
}

// ========================================
// Main Flow
// ========================================

export async function updateColumnsForCurrentFolder(prefetched = null) {
	try {
		await activateColumns(prefetched)
	} finally {
		document.querySelector('.files-list')?.classList.remove('metavox-loading')
	}
}

async function activateColumns(prefetched) {
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
	}

	if (!groupfolderId) {
		setActiveGroupfolderId(null)
		setActiveColumnConfigs([])
		setAvailableFields([])
		setActiveViews([])
		setActiveView(null)
		switchGroupfolder(null)
		showingTeamFolder(null)
		const _fi = getFilterInstance()
		if (_fi) _fi.setSortState(null)
		document.querySelector('.files-list')?.classList.remove('metavox-loading')
		return
	}
	// A link from the page or from back/forward waits for a team folder.
	const link = takeLink()

	// Opening a subfolder keeps the view you were in, and "Default" when you
	// chose it; another team folder opens in its own default view.
	const sameFolder = groupfolderId === getActiveGroupfolderId()
	const stayed = !sameFolder ? null
		: getActiveView() ? { viewId: getActiveView().id }
			: getDefaultChosen() ? { viewId: null } : null
	if (!sameFolder) setDefaultChosen(false)

	setActiveGroupfolderId(groupfolderId)
	switchGroupfolder(groupfolderId)
	showingTeamFolder(groupfolderId)

	// Use prefetched data if available, otherwise fetch fields + views
	let fields, viewsResult
	if (prefetched && prefetched.gfId === groupfolderId) {
		fields = prefetched.fields
		viewsResult = prefetched.viewsResult
	} else {
		[fields, viewsResult] = await Promise.all([
			fetchAvailableFields(groupfolderId),
			fetchViews(groupfolderId),
		])
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

	// The view tabs, and the view and filters to open with.
	openFolderView(viewsResult, { link, stayed })

	// Values come with the listing (nc:metavox). Take over a listing that came
	// before we subscribed, seed it now that its team folder is active, and
	// load through the API only what it did not deliver.
	if (getListingIds().length === 0) {
		catchUp(_findFilesList())
	}
	reseedListing()
	updateAllRowCells()
	updateFilterCache(metadataCache)
	if (!isListingLoading()) {
		const missing = getListingIds().filter((id) => !metadataCache.has(id))
		if (missing.length) {
			queueMetadataLoad(missing)
		}
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
					setViewerItems(data.viewer_items)
				}
			} catch (e) { /* not available */ }
		}

		// Ensure groupfolders are loaded (always call — handles undefined and null)
		await loadGroupfolders()

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
			}

			return {
				gfId,
				fields: data.fields || [],
				viewsResult: { views: data.views || [], canManage: data.can_manage === true },
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
	// Only a change of folder tears the columns down; our own URL updates
	// (mvview, mvfilter) do not.
	const onFolderChange = () => {
		// Show loading state while new folder loads
		document.querySelector('.files-list')?.classList.add('metavox-loading')

		// Detect if we're switching groupfolders or navigating within the same one
		const newGfId = detectCurrentGroupfolder()
		const prevGfId = getActiveGroupfolderId()
		const switchingGroupfolder = newGfId !== prevGfId

		if (getColumnsActive()) {
			uninstallSortBypass()
			removeAllInjectedColumns()
			removeColumnStyles()
			removeFilters()
			removeViewTabs()
			closeViewEditor()
			stopRowObserver()
			setColumnsActive(false)
		}
		// Only clear groupfolder ID when switching teamfolders, also when there
		// were no columns to tear down. Keep it set during submap navigation so
		// push events keep working.
		if (switchingGroupfolder) {
			setActiveGroupfolderId(null)
		}

		// Only clear metadata cache when switching groupfolders.
		// Within the same teamfolder, keep cached metadata (notify_push keeps it fresh).
		if (switchingGroupfolder) {
			switchGroupfolder(newGfId)
		}

		const _fi2 = getFilterInstance()
		if (_fi2) _fi2.setSortState(null)
		if (switchingGroupfolder) {
			setActiveViews([])
			setActiveView(null)
			setAvailableFields([])
		}

		scheduleInjection()
	}
	watchFolder(onFolderChange)

	// Cancel active editor when user leaves — covers tab switch, window switch, and alt-tab
	const cancelEditorOnLeave = () => closeInlineEditor(true)
	document.addEventListener('visibilitychange', () => {
		if (document.visibilityState === 'hidden') cancelEditorOnLeave()
	})
	window.addEventListener('blur', cancelEditorOnLeave)

	watchDayChange()

	// Back on the tab: changes can only have been missed when notify_push was
	// connected and dropped while we were away. Then reload the listing once.
	let hiddenAt = 0
	let wsWasOpen = false
	let lastRefresh = 0
	document.addEventListener('visibilitychange', () => {
		const wsOpen = window._notify_push_ws?.readyState === 1
		if (wsOpen) {
			wsWasOpen = true
		}
		if (document.visibilityState === 'hidden') {
			hiddenAt = Date.now()
			return
		}
		const time = Date.now()
		if (!getActiveGroupfolderId()
			|| !shouldRefreshOnReturn({ wsOpen, wsWasOpen, hiddenMs: time - hiddenAt, sinceRefreshMs: time - lastRefresh })) {
			return
		}
		lastRefresh = time
		invalidateAll()
		if (!refreshListing()) {
			queueMetadataLoad(getListingIds())
		}
	})

	// Leave the team folder's presence when the tab closes.
	window.addEventListener('pagehide', leaveOnClose)

	// Real-time sync via notify_push, when the server has it.
	try { listenForPushes(_handlePushEvent) } catch (e) { /* push not available */ }

	scheduleInjection()
}

/** What a push from another user changes in the grid. */
function _handlePushEvent(eventName, body) {
	const gfId = body.gfId || 0
	const fileId = body.fileId || 0

	if (eventName === 'metavox_metadata_changed') {
		const action = decideMetadataPush(body, getActiveGroupfolderId())
		if (action.type === 'reload') {
			// Only files we show; older answers for them lose from now on.
			const known = action.ids.filter((id) => getListingNode(id) || metadataCache.has(id))
			touch(known)
			loadViaApi(known, { force: true })
		} else if (action.type === 'refresh') {
			invalidateAll()
			if (!refreshListing()) {
				queueMetadataLoad(getListingIds())
			}
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

			// Show user badge portaled to body (avoids table overflow clipping)
			if (cell._lockBadge) cell._lockBadge.remove()
			const badge = document.createElement('div')
			badge.className = 'metavox-lock-badge'
			badge.style.cssText = 'position:fixed;background:#e9a31c;color:#fff;padding:1px 8px;border-radius:8px;font-size:11px;font-weight:600;white-space:nowrap;z-index:10000;pointer-events:none;line-height:16px;box-shadow:0 1px 3px rgba(0,0,0,.2);'
			badge.textContent = '🔒 ' + userId
			document.body.appendChild(badge)
			cell._lockBadge = badge

			// Position badge above the cell
			const positionBadge = () => {
				if (!cell._lockBadge) return
				const rect = cell.getBoundingClientRect()
				badge.style.top = (rect.top - 14) + 'px'
				badge.style.left = (rect.right - badge.offsetWidth) + 'px'
			}
			positionBadge()
			// Reposition on scroll
			cell._lockScrollHandler = () => positionBadge()
			document.addEventListener('scroll', cell._lockScrollHandler, { capture: true })
		} else {
			clearCellLock(cell)
		}
	}
}

// ========================================
// Re-exports for external consumers
// ========================================

// Re-export state getters used by ViewEditorPanel.vue, MetaVoxFilterPanel.vue, etc.
export {
	getNcVersion,
	getActiveColumnConfigs,
	getActiveGroupfolderId,
} from './MetaVoxState.js'
