/**
 * MetaVox View Manager — view tabs, view editor, view application and URL restore.
 *
 * Extracted from MetaVoxColumns.js
 */

import axios from '@nextcloud/axios'
import { showError } from '../../utils/toast.js'
import { translate, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { createApp, h } from 'vue'
import { loadPart, watchLoading } from '../../utils/updatedPage.js'

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
	setDefaultChosen,
	getDefaultChosen,
	metadataCache,
} from './MetaVoxState.js'

import { registerMetaVoxFilter, getFilterInstance } from './MetadataFilter.js'
import {
	injectHeaderColumns,
	injectFooterColumns,
	removeAllInjectedColumns,
	updateTableMinWidth,
	injectRowColumns,
} from './ColumnDOM.js'
import { injectColumnStyles, MARKER_CLASS } from './ColumnStyles.js'
import { DEFAULT_VIEW, sortFieldType, viewColumnConfigs, viewToOpen } from './ColumnUtils.js'
import { writeUrl } from './UrlState.js'
import { fetchViews } from './MetaVoxAPI.js'
import { folderFilterValues } from './FilterOptions.js'
import { errorText } from '../../utils/errorText.js'
import { openSaveFiltersDialog } from './SaveFilters.js'
import { handleSort, updateSortIndicators } from './Sorting.js'
import { watchStickyOffsets } from './StickyOffsets.js'

// ========================================
// Constants
// ========================================

const VIEW_TABS_ID = 'metavox-view-tabs'
const VIEW_EDITOR_ID = 'metavox-view-editor'
const VIEW_STYLE_ID = 'metavox-view-styles'

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
		/* ── View bar container (sticky) ── */
		#${VIEW_TABS_ID} {
			display: flex;
			align-items: center;
			gap: calc(var(--default-grid-baseline, 4px) / 2);
			padding-block: var(--default-grid-baseline, 4px);
			border-bottom: 1px solid var(--color-border);
			background: var(--color-main-background);
			position: sticky;
			top: 0;
			left: 0;
			z-index: 50;
			/* Hold the bar at the viewport width. Without this it stretches to
			   the width of the column table below it — 3997px against a 900px
			   screen — which pushed its bottom edge beneath the horizontal
			   scrollbar of #app-content-vue. */
			width: 100%;
			max-width: 100vw;
			box-sizing: border-box;
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
		/* ── The view picker ─────────────────────────────────────────────────
		   One control naming the view you are in. It replaced a row of tabs,
		   which only worked while the tabs fitted — eight views overflowed a
		   1583px viewport, and whenever the row scrolled the active view could
		   sit off screen with nothing on the bar naming it. */
		/* Secondary, not tertiary. NcActions makes exactly this call itself:
		   a menu WITH a name renders secondary, one without renders tertiary
		   (NcActions.mjs:258). That is the library's own answer to "how does a
		   dropdown showing a value differ from plain chrome", and without it
		   this control was pixel-identical to the breadcrumb above it —
		   15px/500/--color-main-text, no background, a chevron — while doing
		   something entirely different. A breadcrumb can never carry a fill at
		   rest (NcBreadcrumb.css:19-24), so the fill alone separates them. */
		#${VIEW_TABS_ID} .mv-view-picker {
			display: inline-flex;
			align-items: center;
			gap: var(--default-grid-baseline, 4px);
			max-width: 100%;
			min-width: 0;
			margin-inline-start: calc(var(--default-grid-baseline, 4px) * 2);
			min-height: var(--default-clickable-area, 34px);
			padding-inline: calc(2 * var(--default-grid-baseline, 4px));
			border-radius: var(--border-radius-element, 8px);
			background-color: var(--color-primary-element-light);
			color: var(--color-primary-element-light-text);
			font-size: var(--default-font-size, 15px);
			font-weight: var(--font-weight-element, 500);
			transition: background-color var(--animation-quick, 100ms);
		}
		#${VIEW_TABS_ID} .mv-view-picker:hover,
		#${VIEW_TABS_ID} .mv-view-picker.mv-tab-menu-open {
			background-color: var(--color-primary-element-light-hover);
		}
		/* Every Files filter button carries an icon saying what kind of thing it
		   selects (FileListFilters.vue:36-38). This one says: a view. */
		#${VIEW_TABS_ID} .mv-view-picker-icon {
			display: inline-flex;
			align-items: center;
			flex-shrink: 0;
		}
		/* Core styles bare buttons — font-weight: bold, a background and its own
		   padding — so every one of those is restated here. */
		#${VIEW_TABS_ID} .mv-view-picker-label {
			display: inline-flex;
			align-items: center;
			gap: var(--default-grid-baseline, 4px);
			min-width: 0;
			/* 24px is the floor (SC 2.5.8). min-height: 0 with no padding
			   measured 23px — one pixel under, which no reviewer spots and
			   a conformance test does. */
			min-height: 24px;
			margin: 0;
			padding: 0;
			border: none;
			background: transparent;
			color: inherit;
			font: inherit;
			cursor: pointer;
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}
		#${VIEW_TABS_ID} .mv-view-picker-label:focus-visible {
			outline: 2px solid var(--color-main-text);
			outline-offset: 2px;
			border-radius: var(--border-radius-element, 8px);
		}
		#${VIEW_TABS_ID} .mv-view-picker-label:focus:not(:focus-visible) {
			background: transparent;
		}
		#${VIEW_TABS_ID} .mv-view-picker-name {
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}
		#${VIEW_TABS_ID} .mv-view-picker-star,
		#${VIEW_TABS_ID} .mv-view-picker-caret {
			display: inline-flex;
			align-items: center;
			flex-shrink: 0;
			color: var(--color-text-maxcontrast);
		}

		/* ── Actions menu ────────────────────────────────────────────────────
		   Matched to NcActions: the popover shell from NcPopover, the rows
		   from NcActionButton. Values are taken from those stylesheets rather
		   than approximated, so this menu sits next to the file-row menu in
		   the same list without looking like a different app.

		   Fixed and attached to <body>: the tab scroll area clips on
		   overflow-y, which would cut the menu off. */
		.mv-tab-menu {
			position: fixed;
			/* NcPopover: no border, a soft shadow on the theme token. */
			padding: 4px;
			box-sizing: border-box;
			border-radius: var(--border-radius-element, 8px);
			background: var(--color-main-background);
			box-shadow: 0 1px 10px var(--color-box-shadow);
			z-index: 60;
			white-space: nowrap;
			/* No overflow, so the menu does not become a scroll container.
			   overflow-y: auto alone was enough to make one, and a scroll
			   container measures its trailing padding differently: a menu
			   whose content fitted exactly reported scrollWidth 3px over
			   clientWidth and drew a horizontal scrollbar for those 3px.
			   A tab menu holds a handful of rows, so it never needs to
			   scroll; the taller picker below sets its own max-height. */
			max-height: none;
		}
		/* The list scrolls, the actions under it stay put. Only a long list
		   needs this; the max-height is set in JS against the space actually
		   available, because the placement maths has to run against the
		   capped size. */
		.mv-tab-menu.mv-tab-menu-scrollable {
			display: flex;
			flex-direction: column;
			overflow: hidden;
		}
		.mv-tab-menu.mv-tab-menu-scrollable .mv-tab-menu-list {
			overflow-x: hidden;
			overflow-y: auto;
			min-height: 0;
		}
		/* A rule rather than a gap: this is a section boundary, which is the
		   one place Nextcloud does use a separator. */
		.mv-tab-menu-actions:not(:first-child) {
			border-block-start: 1px solid var(--color-border);
			margin-block-start: var(--default-grid-baseline, 4px);
			padding-block-start: var(--default-grid-baseline, 4px);
		}
		.mv-tab-menu-actions {
			flex-shrink: 0;
		}
		/* A long name must wrap or ellipsise rather than widen the menu past
		   the screen: at 58 views it measured 452px against a 390px phone. */
		.mv-tab-menu .mv-tab-menu-item {
			max-width: 100%;
		}
		.mv-tab-menu .mv-tab-menu-label {
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
			min-width: 0;
		}
		/* NcActionButton: the icon box is a full clickable-area square and the
		   row's height comes from line-height, not padding. */
		/* Every property core sets on a bare button is restated here, or its
		   bold weight, grey fill and focus ring leak through — the same trap
		   the tabs fell into. */
		.mv-tab-menu .mv-tab-menu-item {
			display: flex;
			align-items: center;
			width: 100%;
			/* border-box, so the trailing padding is inside the 100% rather
			   than added to it. */
			box-sizing: border-box;
			min-height: 0;
			margin: 0;
			padding: 0;
			padding-inline-end: calc((var(--default-clickable-area, 34px) - 16px) / 2);
			border: 0;
			border-radius: 6px;
			background-color: transparent;
			box-shadow: none;
			color: var(--color-main-text);
			font-size: var(--default-font-size, 15px);
			font-weight: var(--font-weight-element, 500);
			line-height: var(--default-clickable-area, 34px);
			white-space: nowrap;
			text-align: start;
			cursor: pointer;
		}
		.mv-tab-menu .mv-tab-menu-item:hover,
		.mv-tab-menu .mv-tab-menu-item:focus,
		.mv-tab-menu .mv-tab-menu-item:active {
			background-color: var(--color-background-hover);
			box-shadow: none;
		}
		.mv-tab-menu-item:focus-visible {
			outline: 2px solid var(--color-main-text);
			outline-offset: -2px;
		}
		/* A 20px glyph centred in a clickable-area-wide box. That box is what
		   gives NC menus their consistent text indent, so it is the box and
		   not the glyph that carries the width. */
		.mv-tab-menu .mv-tab-menu-icon {
			display: flex;
			align-items: center;
			justify-content: center;
			flex-shrink: 0;
			inline-size: var(--default-clickable-area, 34px);
			block-size: var(--default-clickable-area, 34px);
		}
		/* Pushed to the trailing edge: it marks the row, it is not part of
		   the name. */
		.mv-tab-menu .mv-tab-menu-star {
			margin-inline-start: auto;
			padding-inline-start: 12px;
			font-size: 12px;
			color: var(--color-text-maxcontrast);
		}
		.mv-tab-menu .mv-tab-menu-item-danger {
			color: var(--color-error, #c9326b);
		}

		/* ── Responsive ── */
		@media only screen and (max-width: 512px) {
			#${VIEW_TABS_ID} .mv-view-picker {
				margin-inline-start: var(--default-grid-baseline, 4px);
				padding-inline: calc(var(--default-grid-baseline, 4px) * 1.5);
			}
		}
	`
	document.head.appendChild(style)
}

// ========================================
// View Picker
// ========================================

/**
 * Inject the view bar above the file list filters.
 */
export function injectViewTabs(views) {
	removeViewTabs()

	injectViewStyles()

	const container = document.createElement('div')
	container.id = VIEW_TABS_ID

	_renderViewPicker(container, views)

	// Insert before .files-list__filters
	const filterBar = document.querySelector('.files-list__filters')
	if (filterBar) {
		filterBar.insertAdjacentElement('beforebegin', container)
		// A new bar: the sticky offsets follow this one, not the bar it replaced.
		watchStickyOffsets()
	} else {
		// Fallback: insert before the files-list table
		const table = document.querySelector('.files-list__table')
		if (table) {
			table.insertAdjacentElement('beforebegin', container)
			watchStickyOffsets()
		} else {
			// DOM not ready yet — retry shortly
			setTimeout(() => {
				const fb = document.querySelector('.files-list__filters')
				const tbl = document.querySelector('.files-list__table')
				const target = fb || tbl
				if (target) {
					target.insertAdjacentElement('beforebegin', container)
					watchStickyOffsets()
				}
			}, 150)
		}
	}

	setViewTabsEl(container)
}

/** Gap between the menu and the edge of the viewport. */
const MENU_MARGIN = 8

/**
 * Put the menu under its trigger and keep it inside the viewport.
 *
 * The menu is fixed and attached to <body>, so nothing constrains it for us.
 * With a long list this bit twice: at 58 views the menu measured 452px wide
 * and 940px tall, running 70px past the right edge of a 390px phone and 72px
 * below the fold, with the rows scrolling sideways as well.
 *
 * Width and height are therefore capped against the viewport before the menu
 * is positioned — capping in CSS alone is too late, because the placement
 * maths has already run against the unconstrained size.
 *
 * @param {HTMLElement} menu the menu element, already in the document
 * @param {HTMLElement} trigger the element it hangs from
 */
function _placeMenu(menu, trigger) {
	const anchor = trigger.getBoundingClientRect()
	const vw = window.innerWidth
	const vh = window.innerHeight

	// Width first: a narrow screen decides it, and it changes the height.
	menu.style.maxWidth = (vw - 2 * MENU_MARGIN) + 'px'

	// Prefer below the trigger; flip above when there is more room there.
	const spaceBelow = vh - anchor.bottom - MENU_MARGIN
	const spaceAbove = anchor.top - MENU_MARGIN
	const below = spaceBelow >= spaceAbove
	const room = Math.max(below ? spaceBelow : spaceAbove, 120)

	const natural = menu.getBoundingClientRect().height
	if (natural > room) {
		menu.style.maxHeight = room + 'px'
		menu.classList.add('mv-tab-menu-scrollable')
		// Cap the list, not the menu: the actions keep their full height and
		// the list gives up whatever is left, so they stay reachable without
		// scrolling past every view.
		const list = menu.querySelector('.mv-tab-menu-list')
		const actions = menu.querySelector('.mv-tab-menu-actions')
		if (list) {
			const actionsHeight = actions ? actions.getBoundingClientRect().height : 0
			list.style.maxHeight = Math.max(room - actionsHeight - 8, 80) + 'px'
		}
	}

	const size = menu.getBoundingClientRect()
	menu.style.left = Math.max(MENU_MARGIN, Math.min(anchor.left, vw - size.width - MENU_MARGIN)) + 'px'
	menu.style.top = (below ? anchor.bottom : anchor.top - size.height) + 'px'
}

/**
 * The filters a view stores, as fieldName -> Set(values).
 *
 * The same resolution applyView does when it puts them into effect, so the
 * two are comparable: a view keys its filters by field_id, while the filter
 * instance keys them by field name.
 *
 * @param {object|null} view the view to read
 * @return {Map<string, Set<string>>} its stored filters
 */
function _viewFiltersAsMap(view) {
	const out = new Map()
	if (!view) return out
	const availableFields = getAvailableFields() || []
	for (const [fieldId, valuesStr] of Object.entries(view.filters || {})) {
		if (!valuesStr) continue
		const col = (view.columns || []).find(c => String(c.field_id) === String(fieldId))
		const fieldName = col?.field_name
			?? availableFields.find(c => String(c.id) === String(fieldId))?.field_name
		if (!fieldName) continue
		const values = String(valuesStr).split(',').map(v => v.trim()).filter(Boolean)
		if (values.length > 0) out.set(fieldName, new Set(values))
	}
	return out
}

/**
 * Whether the filters in effect differ from what the active view stores.
 *
 * Counting active filters was not enough: applying a view writes its own
 * filters into the very same set that filtering by hand fills, so a view
 * that carries filters looked exactly like a view someone had filtered on
 * top of — and the menu offered to save a state that was already saved.
 *
 * @param {object|null} view the active view, or null outside one
 * @param {object|null} filterInstance the filter instance
 * @return {boolean} true when there is something worth saving
 */
function _filtersDifferFromView(view, filterInstance) {
	const active = filterInstance?.getActiveFilters?.() ?? new Map()
	const stored = _viewFiltersAsMap(view)
	if (active.size !== stored.size) return true
	for (const [field, values] of active) {
		const other = stored.get(field)
		if (!other || other.size !== values.size) return true
		for (const v of values) {
			if (!other.has(v)) return true
		}
	}
	return false
}

/**
 * The view picker: one control naming the view you are in.
 *
 * A row of tabs only works while the tabs fit, and these never reliably do
 * — eight views overflowed a 1583px viewport, so a width breakpoint moved
 * the failure rather than removing it. Whenever the row scrolled, the
 * active view could sit off screen with nothing on the bar naming it, and
 * the bar spent its width on views nobody was using.
 *
 * A picker inverts that: the name of the current view is always on the bar
 * at any width and any number of views, and the rest stay one click away.
 * It is also what Nextcloud reaches for when a row does not fit — the Files
 * app collapses its own filter row into a popover — and the only difference
 * here is that this row never fits, so there is nothing to collapse from.
 *
 * @param {HTMLElement} container the tab bar
 * @param {Array<object>} views all views for this folder
 */
function _renderViewPicker(container, views) {
	container.querySelector('.mv-view-picker')?.remove()

	const activeView = getActiveView()
	const canManageViews = getCanManageViews()

	const picker = document.createElement('span')
	picker.className = 'mv-view-picker'

	const label = document.createElement('button')
	label.type = 'button'
	label.className = 'mv-view-picker-label'
	label.setAttribute('aria-haspopup', 'menu')
	label.setAttribute('aria-expanded', 'false')
	// The button shows the active view's name, which is its value, not its
	// purpose; the purpose belongs in an aria-label rather than a title (§4).
	// TRANSLATORS Accessible name of the view picker button in the file list toolbar, which opens the list of views
	label.setAttribute('aria-label', t('metavox', 'Switch view'))

	// A category icon, as every Files filter trigger carries one. Columns:
	// a view is a choice of which columns the list shows, and how.
	const icon = document.createElement('span')
	icon.className = 'mv-view-picker-icon'
	icon.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="' + ICON_VIEW_COLUMNS + '"/></svg>'
	label.appendChild(icon)

	// A star when the view you are in is the folder's default, matching the
	// mark the menu puts beside it.
	if (activeView?.is_default) {
		const star = document.createElement('span')
		star.className = 'mv-view-picker-star'
		star.textContent = '\u2605'
		label.appendChild(star)
	}

	// The button carries the current view's name, so the answer to "which
	// view am I looking at" is always on the bar, whatever the list length.
	const name = document.createElement('span')
	name.className = 'mv-view-picker-name'
	// TRANSLATORS Name of the built-in view without saved settings, shown on the view picker button; keep it short
	name.textContent = activeView ? activeView.name : t('metavox', 'Default')
	label.appendChild(name)

	const caret = document.createElement('span')
	caret.className = 'mv-view-picker-caret'
	caret.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M7.41,8.58L12,13.17L16.59,8.58L18,10L12,16L6,10L7.41,8.58Z"/></svg>'
	label.appendChild(caret)

	label.addEventListener('click', (e) => {
		e.stopPropagation()
		if (openTabMenu && openTabMenu.trigger === label) {
			closeTabMenu(false)
			return
		}
		closeTabMenu(false)

		const menu = document.createElement('div')
		menu.className = 'mv-tab-menu'
		menu.setAttribute('role', 'menu')
		menu.addEventListener('click', (ev) => ev.stopPropagation())

		// The list scrolls; the actions below it do not. With 58 views the
		// actions sat past the end of a long scroll, so reaching "Edit" meant
		// paging through every view first.
		const list = document.createElement('div')
		list.className = 'mv-tab-menu-list'

		// Every view, the current one marked — a radio group, not a list of
		// commands, so the checkmark carries the state.
		list.appendChild(_makeMenuItem(
			// TRANSLATORS Name of the built-in view without saved settings, shown as the first item in the view menu; keep it short
			t('metavox', 'Default'),
			activeView ? '' : ICON_CHECK,
			() => clearView(), false, !activeView))
		for (const view of views) {
			const isActive = String(activeView?.id) === String(view.id)
			const item = _makeMenuItem(
				view.name,
				isActive ? ICON_CHECK : '',
				() => applyView(view), false, isActive)
			// The folder's default view is marked, so "which one opens by
			// default" is answerable without opening the editor.
			if (view.is_default) {
				const star = document.createElement('span')
				star.className = 'mv-tab-menu-star'
				star.textContent = '\u2605'
				// TRANSLATORS Tooltip on the star next to the view that opens by default in this folder
				star.title = t('metavox', 'Standard view — loads automatically when opening this folder')
				item.appendChild(star)
			}
			list.appendChild(item)
		}
		menu.appendChild(list)

		// Filters set by hand can be kept, but only while there are any. The
		// action sits here rather than beside the filter chips: every other
		// thing you can do to a view is in this menu, and a chip means "an
		// active filter you can clear" — Nextcloud gives each one a remove
		// cross, which made the action read as something to dismiss.
		const filterInstance = getFilterInstance()
		// Views are shared per Team folder and creating one needs manage
		// rights, so offering this without them produced a 403 after the user
		// had already named the view.
		const hasUnsavedFilters = canManageViews
			&& _filtersDifferFromView(activeView, filterInstance)

		const actions = document.createElement('div')
		actions.className = 'mv-tab-menu-actions'
		if (hasUnsavedFilters) {
			actions.appendChild(_makeMenuItem(
				// TRANSLATORS Menu item in the view menu that saves the current filters as a view; the dots mean a dialog opens
				t('metavox', 'Save filters as view …'), ICON_CONTENT_SAVE,
				() => openSaveFiltersDialog(filterInstance)))
		}
		if (activeView) {
			actions.appendChild(_makeMenuItem(
				canManageViews
					// TRANSLATORS Verb: menu item in the view menu that opens the editor of the current view
					? t('metavox', 'Edit …')
					// TRANSLATORS Menu item in the view menu that shows the settings of the current view read-only; also the title of that panel
					: t('metavox', 'View details'),
				canManageViews ? ICON_PENCIL : ICON_EYE,
				() => openViewEditor(activeView, !canManageViews)))
		}
		if (canManageViews) {
			actions.appendChild(_makeMenuItem(
				// TRANSLATORS Menu item in the view menu that opens the editor for a new view; also the title of that panel
				t('metavox', 'New view'), ICON_PLUS,
				() => openViewEditor(null)))
		}
		if (actions.childElementCount > 0) {
			menu.appendChild(actions)
		}

		document.body.appendChild(menu)
		label.setAttribute('aria-expanded', 'true')
		picker.classList.add('mv-tab-menu-open')
		openTabMenu = { menu, trigger: label, tab: picker }

		_placeMenu(menu, picker)
		menu.querySelector('.mv-tab-menu-item')?.focus()
	})

	picker.appendChild(label)
	container.insertBefore(picker, container.firstChild)
}

// Material Design icon paths, as used throughout Nextcloud.
// Columns: a view is a choice of which columns the list shows.
const ICON_VIEW_COLUMNS = 'M3,5V19H21V5H3M5,7H9V17H5V7M11,7H13V17H11V7M15,7H19V17H15V7Z'
const ICON_CHECK = 'M21,7L9,19L3.5,13.5L4.91,12.09L9,16.17L19.59,5.59L21,7Z'
const ICON_PLUS = 'M19,13H13V19H11V13H5V11H11V5H13V11H19V13Z'
const ICON_CONTENT_SAVE = 'M15,9H5V5H15M12,19A3,3 0 0,1 9,16A3,3 0 0,1 12,13A3,3 0 0,1 15,16A3,3 0 0,1 12,19M17,3H5C3.89,3 3,3.9 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V7L17,3Z'
const ICON_DOTS = 'M16,12A2,2 0 0,1 18,10A2,2 0 0,1 20,12A2,2 0 0,1 18,14A2,2 0 0,1 16,12M10,12A2,2 0 0,1 12,10A2,2 0 0,1 14,12A2,2 0 0,1 12,14A2,2 0 0,1 10,12M4,12A2,2 0 0,1 6,10A2,2 0 0,1 8,12A2,2 0 0,1 6,14A2,2 0 0,1 4,12Z'
const ICON_PENCIL = 'M20.71,7.04C21.1,6.65 21.1,6 20.71,5.63L18.37,3.29C18,2.9 17.35,2.9 16.96,3.29L15.12,5.12L18.87,8.87M3,17.25V21H6.75L17.81,9.93L14.06,6.18L3,17.25Z'
const ICON_EYE = 'M12,9A3,3 0 0,1 15,12A3,3 0 0,1 12,15A3,3 0 0,1 9,12A3,3 0 0,1 12,9M12,4.5C17,4.5 21.27,7.61 23,12C21.27,16.39 17,19.5 12,19.5C7,19.5 2.73,16.39 1,12C2.73,7.61 7,4.5 12,4.5M3.18,12C4.83,15.36 8.24,17.5 12,17.5C15.76,17.5 19.17,15.36 20.82,12C19.17,8.64 15.76,6.5 12,6.5C8.24,6.5 4.83,8.64 3.18,12Z'

/** The open tab menu, so a second click elsewhere can close it. */
let openTabMenu = null

/**
 * Close whichever tab menu is open, and restore focus if asked.
 *
 * @param {boolean} restoreFocus whether to focus the trigger again
 */
function closeTabMenu(restoreFocus = false) {
	if (!openTabMenu) return
	const { menu, trigger, tab } = openTabMenu
	menu.remove()
	trigger.setAttribute('aria-expanded', 'false')
	tab.classList.remove('mv-tab-menu-open')
	openTabMenu = null
	if (restoreFocus) trigger.focus()
}

// One document-level listener, not one per menu: re-registering on every
// rebuild of the tab bar leaked handlers.
document.addEventListener('click', () => closeTabMenu(false))
document.addEventListener('keydown', (e) => {
	if (e.key !== 'Escape' || !openTabMenu) return
	// Back to the menu button only from the menu itself: an Escape in a cell
	// editor must not pull the focus away from it.
	closeTabMenu(openTabMenu.menu.contains(e.target) || e.target === openTabMenu.trigger)
})

/**
 * Build one row in the tab actions menu.
 *
 * @param {string} label the visible text
 * @param {string} iconPath Material Design path data
 * @param {Function} onClick invoked after the menu closes
 * @param {boolean} danger whether to style it as destructive
 * @return {HTMLButtonElement} the menu item
 */
function _makeMenuItem(label, iconPath, onClick, danger = false, checked = null) {
	const item = document.createElement('button')
	item.type = 'button'
	item.className = 'mv-tab-menu-item' + (danger ? ' mv-tab-menu-item-danger' : '')
	// A row that selects one of several views is a radio, not a command, so
	// a screen reader announces which one is current rather than reading a
	// list of identical-sounding commands.
	if (checked === null) {
		item.setAttribute('role', 'menuitem')
	} else {
		item.setAttribute('role', 'menuitemradio')
		item.setAttribute('aria-checked', String(checked))
	}
	// The icon box is kept even when empty, so labels line up whether or not
	// a row carries a checkmark.
	item.innerHTML = '<span class="mv-tab-menu-icon">'
		+ (iconPath ? '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="' + iconPath + '"/></svg>' : '')
		+ '</span>'
	// The label is its own element so it can ellipsise; as a bare text node it
	// widened the menu past the screen on a phone.
	const text = document.createElement('span')
	text.className = 'mv-tab-menu-label'
	text.textContent = label
	item.appendChild(text)
	item.addEventListener('click', (e) => {
		e.stopPropagation()
		closeTabMenu(false)
		onClick()
	})
	return item
}


export function removeViewTabs() {
	// The menu lives on <body>, not inside the bar, so removing the bar would
	// otherwise strand it: it stayed on screen listing the views from before
	// the switch, with its trigger already gone.
	closeTabMenu(false)
	const viewTabsEl = getViewTabsEl()
	viewTabsEl?.remove()
	setViewTabsEl(null)
}


export function updateActiveTabs() {
	const viewTabsEl = getViewTabsEl()
	if (!viewTabsEl) return

	// The bar holds one picker, not a row of tabs, so "update the active tab"
	// is now just: relabel the picker with the view we are in.
	_renderViewPicker(viewTabsEl, getActiveViews())
}

// ========================================
// View Editor Panel
// ========================================

// The editor, with its rules and preview (about 100 KB), loads when it opens.
let editorPanel = null
async function loadEditorPanel() {
	editorPanel ??= (await loadPart(() => import(/* webpackChunkName: "metavox-view-editor" */ './ViewEditorPanel.vue'))).default
	return editorPanel
}

/**
 * Open the slide-over view editor (Vue component).
 * @param {Object|null} view - Existing view to edit, or null for new
 * @param {boolean} readonly - Open in readonly mode (view details only)
 */
export async function openViewEditor(view, readonly = false) {
	// When it does not load, loadPart says to reload and nothing changes.
	const ViewEditorPanel = await loadEditorPanel().catch(() => null)
	if (!ViewEditorPanel) return

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

	// A bare mount point: NcDialog teleports itself to the body and brings its
	// own overlay, focus trap and Escape handling, so none of that is built
	// here any more.
	const panel = document.createElement('div')
	panel.id = VIEW_EDITOR_ID
	document.body.appendChild(panel)

	const mountEl = document.createElement('div')
	panel.appendChild(mountEl)

	const vueApp = createApp({
		render: () => h(ViewEditorPanel, {
			view,
			readonly,
			availableFields,
			totalViews: activeViews.length,
			fetchFilterValuesFn: async (fieldName) => {
				// The values of the folder you are in, like the filter panel.
				const field = availableFields.find((f) => f.field_name === fieldName)
				return field ? (await folderFilterValues([field]))[fieldName] ?? [] : []
			},
			onClose: () => closeViewEditor(),
			submit: (payload) => _submitView(view, payload),
			onDelete: (v) => _confirmDeleteView(v),
		}),
	})

	vueApp.config.globalProperties.t = translate
	watchLoading(vueApp).mount(mountEl)
	panel._vueApp = vueApp
}

/**
 * Save from the view editor. A refusal goes back to the editor, which keeps
 * the dialog and the edits and says what went wrong.
 */
async function _submitView(view, payload) {
	const gfId = getActiveGroupfolderId()
	const resp = view
		? await axios.put(generateUrl('/apps/metavox/api/groupfolders/{gfId}/views/{viewId}', { gfId, viewId: view.id }), payload)
		: await axios.post(generateUrl('/apps/metavox/api/groupfolders/{gfId}/views', { gfId }), payload)
	closeViewEditor()
	// Saved is saved: a refresh that fails afterwards is no failed save.
	_afterViewSaved(resp.data, payload.position).catch((e) => console.error('MetaVox: Failed to refresh the views after saving', e))
	return resp.data
}

/** After a save: the order, the tabs and the grid follow. */
async function _afterViewSaved(savedView, desiredPos) {
	const activeGroupfolderId = getActiveGroupfolderId()

	// If position was changed, reorder all views
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
		applyView(savedView)
	}
}

export function closeViewEditor() {
	const panel = document.getElementById(VIEW_EDITOR_ID)
	if (panel?._vueApp) {
		panel._vueApp.unmount()
	}
	panel?.remove()
}

async function _confirmDeleteView(view) {
	// Confirmation already handled by NcDialog in ViewEditorPanel.vue
	const activeGroupfolderId = getActiveGroupfolderId()
	// Where we stood matters: only leave the current view if it is the one
	// being deleted. Deleting another view used to drop you out of yours too.
	const wasViewing = String(getActiveView()?.id) === String(view.id)

	try {
		const url = generateUrl('/apps/metavox/api/groupfolders/{gfId}/views/{viewId}', {
			gfId: activeGroupfolderId,
			viewId: view.id,
		})
		await axios.delete(url)

		closeViewEditor()

		// Reload views and rebuild tabs
		const result = await fetchViews(activeGroupfolderId)
		setActiveViews(result.views)
		setCanManageViews(result.canManage)

		if (wasViewing) {
			// Fall back to the starred default view when there is one, so the
			// folder keeps its intended columns; only drop to the plain
			// Nextcloud listing when no default exists.
			const fallback = result.views.find(v => v.is_default)
			if (fallback) {
				applyView(fallback)
			} else {
				clearView()
				injectViewTabs(result.views)
			}
		} else {
			injectViewTabs(result.views)
		}

		// Update prefetch cache
		if (window._metavoxAllGfData?.[activeGroupfolderId]) {
			window._metavoxAllGfData[activeGroupfolderId].views = result.views
			window._metavoxAllGfData[activeGroupfolderId].can_manage = result.canManage
		}
	} catch (e) {
		console.error('MetaVox: Failed to delete view', e)
		// TRANSLATORS Error message; {reason} is the server's error text, e.g. "Could not delete the view: Internal server error"
		showError(t('metavox', 'Could not delete the view: {reason}', {
			// TRANSLATORS Fallback error reason when the server gives none; shown as {reason} in an error message, e.g. "Could not save the view: {reason}"
			reason: errorText(e, t('metavox', 'Internal server error')),
		}))
	}
}

// ========================================
// View Application
// ========================================

/**
 * Apply a view: its columns, filters and sort, and the URL.
 * @param {Object} view
 */
export function applyView(view) {
	setActiveView(view)
	setDefaultChosen(false)

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

	// Columns and filter first, so there is always a registered filter to fill.
	_applyViewColumns(view)
	registerMetaVoxFilter(getActiveColumnConfigs(), getActiveGroupfolderId(), metadataCache)
	const fi = getFilterInstance()

	// The view's filters, stored as { field_id: "val1, val2" }
	fi._activeFilters.clear()
	for (const [fieldId, valuesStr] of Object.entries(view.filters || {})) {
		if (!valuesStr) continue
		const col = (view.columns || []).find(c => String(c.field_id) === String(fieldId))
		const fieldName = col?.field_name
			?? availableFields.find(c => String(c.id) === String(fieldId))?.field_name
		if (!fieldName) continue
		const values = String(valuesStr).split(',').map(v => v.trim()).filter(Boolean)
		if (values.length > 0) {
			fi._activeFilters.set(fieldName, new Set(values))
		}
	}
	// The chips now; the Files app hears it from reapply() below, once.
	fi._emitChips()

	fi.setSortState(view.sort_field
		? { fieldName: view.sort_field, fieldType: sortFieldType(view, availableFields), direction: view.sort_order || 'asc' }
		: null)
	updateSortIndicators()
	fi.reapply()

	// The view's own filters: none from before in the URL.
	writeUrl({ view: view.id, filters: null })

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
	setDefaultChosen(true)

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
	// As the folder opens without a view: no MetaVox columns, and the filter over
	// every field, still registered, so the view picked next works as on opening.
	_applyViewColumns(null)
	registerMetaVoxFilter(buildDefaultFilterConfigs(), activeGroupfolderId, metadataCache)
	getFilterInstance().reset()

	// "Default" in the URL, so a reload or a shared link opens it again
	writeUrl({ view: DEFAULT_VIEW, filters: null })

	updateActiveTabs()
}

/**
 * Open a team folder in a view: a link first, then the view you were in,
 * then the starred one. The URL follows, so a reload opens the same.
 *
 * @param {{views: object[], canManage: boolean}} viewsResult
 * @param {object} [options]
 * @param {?{view: ?string, filters: ?string}} [options.link] from the page or back/forward
 * @param {?{viewId: ?number}} [options.stayed] the view of the same team folder
 */
export function openFolderView({ views, canManage }, { link = null, stayed = null } = {}) {
	if (views.length > 0 || canManage) {
		injectViewTabs(views)
		const view = viewToOpen(views, { linkView: link?.view ?? null, stayed })
		if (view) {
			applyView(view)
		} else if (link?.view === DEFAULT_VIEW || stayed?.viewId === null) {
			// "Default" on purpose: a subfolder keeps it, and so does a reload.
			setDefaultChosen(true)
		}
	}
	// No view: the URL says so too, so an old mvview cannot come back.
	if (!getActiveView()) {
		writeUrl({ view: getDefaultChosen() ? DEFAULT_VIEW : null, filters: null })
	}
	if (link?.filters) {
		getFilterInstance()?.loadFromEncoded(link.filters, getActiveColumnConfigs())
	}
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
		setActiveColumnConfigs(viewColumnConfigs(view, availableFields))
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

