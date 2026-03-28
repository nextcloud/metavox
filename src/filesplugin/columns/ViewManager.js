/**
 * MetaVox View Manager — view tabs, view editor, view application and URL restore.
 *
 * Extracted from MetaVoxColumns.js
 */

import axios from '@nextcloud/axios'
import { translate } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { createApp, h } from 'vue'
import ViewEditorPanel from './ViewEditorPanel.vue'

import {
	getActiveColumnConfigs,
	setActiveColumnConfigs,
	getActiveGroupfolderId,
	getActiveView,
	setActiveView,
	getActiveViews,
	setActiveViews,
	getCanManageViews,
	setCanManageViews,
	getAvailableFields,
	getViewTabsEl,
	setViewTabsEl,
	getPrefetchedFilterValues,
	setPrefetchedFilterValues,
	metadataCache,
	permissionCache,
} from './MetaVoxState.js'

import { registerMetaVoxFilter, removeFilters, getFilterInstance } from './MetadataFilter.js'
import {
	injectHeaderColumns,
	injectFooterColumns,
	removeAllInjectedColumns,
	updateTableMinWidth,
	updateAllRowCells,
	injectRowColumns,
} from './ColumnDOM.js'
import { injectColumnStyles, MARKER_CLASS } from './ColumnStyles.js'
import { fetchViews, fetchDirectoryMetadata, fetchAllFilterValues } from './MetaVoxAPI.js'
import { handleSort, updateSortIndicators, ensureSortBypass, _findFilesList } from './Sorting.js'

// ========================================
// Constants
// ========================================

const VIEW_TABS_ID = 'metavox-view-tabs'
const VIEW_EDITOR_ID = 'metavox-view-editor'
const VIEW_STYLE_ID = 'metavox-view-styles'
let draggedViewId = null

// Need FILTER_ID from MetadataFilter — re-declare locally for DOM lookup
const FILTER_ID = 'metavox-metadata'

// ========================================
// View Styles (CSS)
// ========================================

export function injectViewStyles() {
	if (document.getElementById(VIEW_STYLE_ID)) return
	const style = document.createElement('style')
	style.id = VIEW_STYLE_ID
	style.textContent = `
		/* ── Tab bar container (sticky horizontally + vertically) ── */
		#${VIEW_TABS_ID} {
			display: flex;
			align-items: center;
			gap: calc(var(--default-grid-baseline, 4px) / 2);
			padding: var(--default-grid-baseline, 4px) 0;
			border-bottom: 1px solid var(--color-border);
			background: var(--color-main-background);
			position: sticky;
			top: 0;
			left: 44px;
			z-index: 50;
		}
		/* Sticky stacking: tabs (top:0) → filters (top:tabs) → thead (top:tabs+filters).
		   All offsets via CSS custom properties, computed by JS ResizeObserver. */
		#${VIEW_TABS_ID} ~ .files-list__filters {
			position: sticky !important;
			top: var(--mv-filters-top, 0px) !important;
			z-index: 49 !important;
			background: var(--color-main-background);
		}
		#${VIEW_TABS_ID} ~ .files-list__table thead {
			top: var(--mv-thead-top, 0px) !important;
			z-index: 48 !important;
		}
		/* Selection overlay ("X selected") must sit above the sticky tabs */
		#${VIEW_TABS_ID} ~ .files-list__thead-overlay {
			top: var(--mv-filters-top, 0px) !important;
			z-index: 51 !important;
		}
		/* Sticky "All" tab */
		#${VIEW_TABS_ID} > .mv-tab-all {
			flex-shrink: 0;
			margin-left: calc(var(--default-grid-baseline, 4px) * 2);
			z-index: 1;
		}
		/* Sticky default view tab */
		#${VIEW_TABS_ID} > .mv-tab-default {
			flex-shrink: 0;
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

export function removeViewStyles() {
	document.getElementById(VIEW_STYLE_ID)?.remove()
}

// ========================================
// View Tabs
// ========================================

/**
 * Inject tab-bar above the file list filters.
 */
export function injectViewTabs(views) {
	removeViewTabs()

	injectViewStyles()

	const activeView = getActiveView()
	const canManageViews = getCanManageViews()

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
		star.textContent = '\u2605'
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

	setViewTabsEl(container)
}

function _makeViewTab(view) {
	const activeView = getActiveView()
	const canManageViews = getCanManageViews()
	const activeViews = getActiveViews()

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
		applyView(view, getFilterInstance())
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

export function removeViewTabs() {
	const viewTabsEl = getViewTabsEl()
	viewTabsEl?.remove()
	setViewTabsEl(null)
}

async function _handleTabDrop(dragId, targetId, dropAfter) {
	const activeViews = getActiveViews()
	const activeGroupfolderId = getActiveGroupfolderId()

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
		setActiveViews(result.views)
		injectViewTabs(result.views)
	} catch (err) {
		console.error('MetaVox: Failed to reorder views', err)
	}
}

export function updateActiveTabs() {
	const viewTabsEl = getViewTabsEl()
	if (!viewTabsEl) return

	const activeView = getActiveView()
	const activeViews = getActiveViews()
	const canManageViews = getCanManageViews()

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

// ========================================
// View Editor Panel
// ========================================

/**
 * Open the slide-over view editor (Vue component).
 * @param {Object|null} view - Existing view to edit, or null for new
 * @param {boolean} readonly - Open in readonly mode (view details only)
 */
export function openViewEditor(view, readonly = false) {
	// Remove existing editor
	closeViewEditor()

	// Close NC Details sidebar if open
	if (window.OCA?.Files?.Sidebar?.close) {
		window.OCA.Files.Sidebar.close()
	}

	injectViewStyles()

	const activeViews = getActiveViews()
	const activeGroupfolderId = getActiveGroupfolderId()
	const availableFields = getAvailableFields()

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
			fetchFilterValuesFn: async (fieldName) => {
				// Lazy load: fetch filter values on demand when user opens filter dropdown
				let prefetchedFilterValues = getPrefetchedFilterValues()
				if (!prefetchedFilterValues && activeGroupfolderId) {
					prefetchedFilterValues = await fetchAllFilterValues(activeGroupfolderId)
					setPrefetchedFilterValues(prefetchedFilterValues)
				}
				const values = (prefetchedFilterValues || {})[fieldName]
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
	const activeGroupfolderId = getActiveGroupfolderId()

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
		setActiveViews(result.views)
		setCanManageViews(result.canManage)
		injectViewTabs(result.views)

		// Invalidate prefetch cache so navigation sees the updated views
		if (window._metavoxAllGfData?.[activeGroupfolderId]) {
			window._metavoxAllGfData[activeGroupfolderId].views = result.views
			window._metavoxAllGfData[activeGroupfolderId].can_manage = result.canManage
		}

		if (savedView) {
			applyView(savedView, getFilterInstance())
		}
	} catch (e) {
		console.error('MetaVox: Failed to save view', e)
		alert(translate('metavox', 'Save failed: ') + (e.response?.data?.error || e.message))
	}
}

export function closeViewEditor() {
	const panel = document.getElementById(VIEW_EDITOR_ID)
	if (panel?._vueApp) {
		panel._vueApp.unmount()
	}
	panel?.remove()
	document.querySelector('.mv-editor-overlay')?.remove()
}

async function _confirmDeleteView(view) {
	// Confirmation already handled by NcDialog in ViewEditorPanel.vue
	const activeGroupfolderId = getActiveGroupfolderId()

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
		setActiveViews(result.views)
		setCanManageViews(result.canManage)
		injectViewTabs(result.views)

		// Update prefetch cache
		if (window._metavoxAllGfData?.[activeGroupfolderId]) {
			window._metavoxAllGfData[activeGroupfolderId].views = result.views
			window._metavoxAllGfData[activeGroupfolderId].can_manage = result.canManage
		}
	} catch (e) {
		console.error('MetaVox: Failed to delete view', e)
		alert(translate('metavox', 'Delete failed: ') + (e.response?.data?.error || e.message))
	}
}

// ========================================
// View Application
// ========================================

/**
 * Apply a view: reset filters, apply view filters, apply column visibility, update URL.
 * @param {Object} view
 * @param {Object} filterInstance
 */
export function applyView(view, filterInstance) {
	setActiveView(view)

	const availableFields = getAvailableFields()

	// Reset horizontal scroll to show leftmost column
	const scrollContainer = document.querySelector('#app-content-vue')
	if (scrollContainer) scrollContainer.scrollLeft = 0

	// Deselect all files — the file set changes with the new view
	const headerCheckbox = document.querySelector('.files-list__row-head .files-list__row-checkbox input[type="checkbox"]')
	if (headerCheckbox && (headerCheckbox.checked || headerCheckbox.indeterminate)) {
		headerCheckbox.click()
	}

	// Close NC Details sidebar — the displayed file may not exist in the new view
	if (window.OCA?.Files?.Sidebar?.close) {
		window.OCA.Files.Sidebar.close()
	}

	// Reset existing filters (suppresses URL update; we'll set URL after)
	if (filterInstance) {
		filterInstance._activeFilters.clear()
		filterInstance._emitChips()
	}

	// Apply view filters — stored as { field_id: "val1, val2" } dict
	if (filterInstance) {
		const filtersDict = view.filters || {}
		for (const [fieldId, valuesStr] of Object.entries(filtersDict)) {
			if (!valuesStr) continue
			const col = (view.columns || []).find(c => String(c.field_id) === String(fieldId))
			const fieldName = col?.field_name
				?? availableFields.find(c => String(c.id) === String(fieldId))?.field_name
			if (!fieldName) continue
			const values = String(valuesStr).split(',').map(v => v.trim()).filter(Boolean)
			if (values.length > 0) {
				filterInstance._activeFilters.set(fieldName, new Set(values))
			}
		}

		// Emit filter update
		filterInstance._emitChips()
		filterInstance.dispatchEvent(new CustomEvent('update:filter'))
		window._nc_event_bus?.emit('files:filters:changed')
	}

	// Apply column visibility and update filter registration
	_applyViewColumns(view)
	const activeColumnConfigs = getActiveColumnConfigs()
	const activeGroupfolderId = getActiveGroupfolderId()
	registerMetaVoxFilter(activeColumnConfigs, activeGroupfolderId, metadataCache)

	// Apply sort if specified (data-level via filter)
	const fi = getFilterInstance()
	if (view.sort_field && fi) {
		const col = (view.columns || []).find(c => c.field_name === view.sort_field)
			?? availableFields.find(c => c.field_name === view.sort_field)
		fi.setSortState({
			fieldName: view.sort_field,
			fieldType: col?.field_type || 'text',
			direction: view.sort_order || 'asc',
		})
		ensureSortBypass()
		updateSortIndicators()
		fi.triggerResort()
	} else if (fi) {
		fi.setSortState(null)
		fi.clearServerState()
		fi.triggerResort()
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
export function buildDefaultFilterConfigs() {
	const availableFields = getAvailableFields()
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
export function clearView() {
	setActiveView(null)

	const activeGroupfolderId = getActiveGroupfolderId()

	// Reset horizontal scroll to show leftmost column
	const scrollContainer = document.querySelector('#app-content-vue')
	if (scrollContainer) scrollContainer.scrollLeft = 0

	// Deselect all files — the file set changes with the new view
	const headerCheckbox = document.querySelector('.files-list__row-head .files-list__row-checkbox input[type="checkbox"]')
	if (headerCheckbox && (headerCheckbox.checked || headerCheckbox.indeterminate)) {
		headerCheckbox.click()
	}

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

	// Clear sort and server state
	if (fi) {
		fi.setSortState(null)
		fi.clearServerState()
		fi.triggerResort()
	}
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
	const availableFields = getAvailableFields()
	const activeGroupfolderId = getActiveGroupfolderId()

	if (!view || !view.columns || view.columns.length === 0) {
		// No view active — hide all MetaVox columns and reset table widths
		setActiveColumnConfigs([])
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
		setActiveColumnConfigs(ordered)
	}

	// Reset table width before re-injecting so it shrinks when fewer columns are shown
	const table = document.querySelector('.files-list__table')
	if (table) table.style.minWidth = ''
	const filesList = document.querySelector('.files-list')
	if (filesList) filesList.style.minWidth = ''

	// Re-inject header/footer/row columns with updated config
	injectHeaderColumns()
	injectFooterColumns()

	// Check if cached metadata has all fields needed by the new view.
	// If not, clear cache so metadata is re-fetched with the correct field_names.
	const activeColumnConfigs = getActiveColumnConfigs()
	const newFieldNames = activeColumnConfigs.map(c => c.field_name).filter(Boolean)
	if (newFieldNames.length > 0 && metadataCache.size > 0) {
		const sample = metadataCache.values().next().value
		if (sample && newFieldNames.some(f => !(f in sample))) {
			metadataCache.clear()
			// Re-fetch metadata for all files with the new field set
			if (activeGroupfolderId) {
				const fl = _findFilesList()
				let ids = fl?.dirContents?.map(n => n.fileid).filter(Boolean) || []
				if (ids.length === 0) {
					ids = [...document.querySelectorAll('tr[data-cy-files-list-row]')]
						.map(r => Number(r.getAttribute('data-cy-files-list-row-fileid'))).filter(Boolean)
				}
				if (ids.length > 0) {
					fetchDirectoryMetadata(activeGroupfolderId, ids).then(data => {
						for (const [fileId, fields] of Object.entries(data)) {
							const id = Number(fileId)
							if (fields._permissions !== undefined) {
								permissionCache.set(id, (fields._permissions & 2) !== 0)
								delete fields._permissions
							}
							metadataCache.set(id, fields)
						}
						updateAllRowCells()
					})
				}
			}
		}
	}

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
export function restoreViewFromUrl(views, filterInstance) {
	const params = new URLSearchParams(window.location.search)
	const viewId = params.get('mvview')
	if (!viewId) return

	const view = views.find(v => String(v.id) === String(viewId))
	if (view) {
		applyView(view, filterInstance)
	}
}
