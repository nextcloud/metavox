/**
 * MetaVox File List Filters — the MetaVox filter in the Files filter bar.
 *
 * Registered through Nextcloud's official filter API. NC31/32 (@nextcloud/files 3)
 * and NC33+ (@nextcloud/files 4) each read their own registry, so we use both.
 */

import './MetaVoxFilterElement.js'
import { createApp, h } from 'vue'
import { translate, translate as t, translatePlural, translatePlural as n } from '@nextcloud/l10n'
import MetaVoxFilterPanel from './MetaVoxFilterPanel.vue'
import { watchLoading } from '../../utils/updatedPage.js'
import { showError } from '../../utils/toast.js'
import { emit } from '@nextcloud/event-bus'
import { getFileListFilters, registerFileListFilter, unregisterFileListFilter } from '@nextcloud/files'
import { ensureSortBypass, reorderDomRows, setSortPending } from './Sorting.js'
import { applyFilterSort, compileFilters, missingIds } from './FilterSortCore.js'
import { optionLabel } from './FilterOptions.js'
import { writeUrl } from './UrlState.js'
import { getListingNodes, isListingLoading, listingGeneration } from './ListingSync.js'
import { queueMetadataLoad } from './MetadataLoader.js'
import { hasValues } from './MetadataStore.js'
import { getActiveGroupfolderId, getAvailableFields } from './MetaVoxState.js'
import { PLAIN_TEXT } from '../../utils/fieldValue.js'
import { updateTableMinWidth } from './ColumnDOM.js'
import { METAVOX_ICON_SVG } from '../../utils/metavoxIcon.js'

const FILTER_ID = 'metavox-metadata'

/** @type {Object|null} The registered filter instance */
let filterInstance = null

/** @type {boolean} Whether the filter is currently registered */
let registered = false

/**
 * MetaVox Metadata Filter
 *
 * Implements the IFileListFilterWithUi interface from @nextcloud/files.
 * Uses an EventTarget to dispatch update:filter and update:chips events.
 */
class MetaVoxMetadataFilter extends EventTarget {

	constructor() {
		super()
		this.id = FILTER_ID
		// After Nextcloud's own filters (the highest is 999): NC31/32 can only add
		// a filter bar slot at the end once theirs are mounted.
		this.order = 1000
		this.displayName = 'MetaVox'
		this.iconSvgInline = METAVOX_ICON_SVG
		this.tagName = 'metavox-metadata-filter'

		this._activeFilters = new Map() // fieldName -> Set<value>
		this._metadataCache = null
		this._columnConfigs = []
		this._groupfolderId = null
		this._sortState = null // { fieldName, fieldType, direction } or null

		// The gate: never filter or sort on values that are missing or cut off.
		this._lastNodes = []
		this._gatePending = null
		this._gateFailedGeneration = null
		this._held = false // the last filter() kept the rows as they were
		// The last filter() let every row through as it came: no filter, no sort.
		this._passedAll = true
	}

	// ========================================
	// IFileListFilter interface
	// ========================================

	/**
	 * NC31/32 give each filter a slot in the filter bar and call mount(el) on it,
	 * possibly more than once. NC33+ render tagName instead.
	 */
	mount(el) {
		if (!el || el === this._mountEl) return
		this._mountEl = el
		el.replaceChildren(_filterButton(this))
	}

	filter(nodes) {
		this._lastNodes = nodes
		this._held = false
		const filters = compileFilters(this._activeFilters)
		const sort = this._sortState
		this._passedAll = filters.length === 0 && !sort
		if (this._passedAll) return nodes

		// A cut-off value counts as it is shown; only missing values are loaded first.
		const missing = missingIds(nodes, hasValues)
		if (missing.length > 0) {
			// Never hide or reorder rows on partial data: load what is missing first.
			this._gate(missing)
			this._held = true
			return nodes
		}
		return applyFilterSort(nodes, filters, sort, (id) => this._metadataCache?.get(id))
	}

	reset() {
		this._activeFilters.clear()
		this._sortState = null
		// A load still running ends here, pause and all; the next filter() starts its own.
		this._gatePending = null
		this._gateFailedGeneration = null
		this._emitChips()
		this._emitFilterUpdate()
		// NC32 fallback: restore all DOM rows
		reorderDomRows(null)
		// The sort ended too: no arrow, no loading sign.
		setSortPending(false)
	}

	// ========================================
	// MetaVox-specific methods (used by web component)
	// ========================================

	setMetadataCache(cache) {
		this._metadataCache = cache
	}

	setColumnConfigs(configs) {
		this._columnConfigs = configs
	}

	setGroupfolderId(id) {
		this._groupfolderId = id
	}

	getFilterableConfigs() {
		return this._columnConfigs.filter(c => c.filterable)
	}

	getGroupfolderId() {
		return this._groupfolderId
	}

	getActiveFilters() {
		return new Map(this._activeFilters)
	}

	/**
	 * Toggle a single value for a field (multi-select). Called by the web component.
	 * @param {string} fieldName
	 * @param {string} value
	 */
	toggleFilterValue(fieldName, value) {
		if (!this._activeFilters.has(fieldName)) {
			this._activeFilters.set(fieldName, new Set())
		}
		const set = this._activeFilters.get(fieldName)
		if (set.has(value)) {
			set.delete(value)
			if (set.size === 0) this._activeFilters.delete(fieldName)
		} else {
			set.add(value)
		}
		this._emitChips()
		this.applyClientSideFilterSort()
	}

	/**
	 * Clear all selected values for a field.
	 * @param {string} fieldName
	 */
	clearFieldFilter(fieldName) {
		this._activeFilters.delete(fieldName)
		this._emitChips()
		this.applyClientSideFilterSort()
	}

	/** Clear every filter; the sort stays, as with the ✕ on a single chip. */
	clearFilters() {
		this._activeFilters.clear()
		this._emitChips()
		this.applyClientSideFilterSort()
	}

	/**
	 * Check if a specific value is active for a field.
	 * @param {string} fieldName
	 * @param {string} value
	 * @returns {boolean}
	 */
	isFieldValueActive(fieldName, value) {
		return this._activeFilters.get(fieldName)?.has(value) ?? false
	}

	/**
	 * Get the number of active values for a field.
	 * @param {string} fieldName
	 * @returns {number}
	 */
	getFieldActiveCount(fieldName) {
		return this._activeFilters.get(fieldName)?.size ?? 0
	}

	/**
	 * Re-trigger filter (e.g., after metadata cache is updated or sort needs refresh)
	 */
	triggerRefilter() {
		if (this._activeFilters.size > 0 || this._sortState) {
			this._emitFilterUpdate()
		}
	}

	// ========================================
	// Sorting
	// ========================================

	setSortState(sort) {
		this._sortState = sort
	}

	getSortState() {
		return this._sortState
	}

	/** True while missing values keep the filter and sort from being applied. */
	isHeld() {
		return this._held
	}

	/**
	 * Apply filters and sort after they changed. Filtering and sorting happen
	 * in the browser, on the cached values (no server call).
	 */
	applyClientSideFilterSort() {
		// Deselect all files — the visible set is about to change
		const headerCheckbox = document.querySelector('.files-list__row-head .files-list__row-checkbox input[type="checkbox"]')
		if (headerCheckbox && (headerCheckbox.checked || headerCheckbox.indeterminate)) {
			headerCheckbox.click()
		}
		this.reapply()
	}

	/**
	 * Let the Files app run filter() again. With the sort bypass NC keeps our
	 * order; without it the visible rows are reordered in the DOM.
	 */
	reapply() {
		const bypassOk = ensureSortBypass()
		const active = this._activeFilters.size > 0 || !!this._sortState
		// Nothing filters or sorts, now or at the last run: telling the Files app
		// would only make it filter and sort every row again for the same list.
		if (active || !this._passedAll) {
			this._emitFilterUpdate()
		} else {
			this._syncToUrl()
		}
		if (!bypassOk) {
			const shown = active ? this.filter(getListingNodes()) : null
			// While values are still loading the rows stay as they are.
			if (!active || !this._held) reorderDomRows(shown && shown.map((node) => node.fileid))
		}

		// Filtering can empty the list, and the column width we force on the
		// table then has nothing to size — it only shoves Nextcloud's empty
		// state off to the right. Re-measure once the rows have gone.
		requestAnimationFrame(() => updateTableMinWidth())
	}

	/**
	 * Load the missing values once, then reapply. Without progress it pauses
	 * until the next listing, so a failing load can never loop.
	 */
	_gate(ids) {
		if (this._gatePending || this._gateFailedGeneration === listingGeneration()) return
		// Nothing to load, or the Files app still shows the previous folder.
		if (!getActiveGroupfolderId() || isListingLoading()) return
		const before = ids.length
		setSortPending(true)
		const gate = queueMetadataLoad(ids)
			.catch(() => {})
			.then(() => {
				// "Default" or another folder ended this load with a reset.
				if (this._gatePending !== gate) return
				this._gatePending = null
				setSortPending(false)
				const after = missingIds(this._lastNodes, hasValues).length
				if (after < before) {
					setTimeout(() => this.reapply(), 0)
					return
				}
				this._gateFailedGeneration = listingGeneration()
				// TRANSLATORS Error message; MetaVox is the app name, do not translate it
				showError(t('metavox', 'Sorting and filtering on MetaVox columns is paused: not all values could be loaded.'))
			})
		this._gatePending = gate
	}

	// ========================================
	// Internal
	// ========================================

	_emitFilterUpdate() {
		this.dispatchEvent(new CustomEvent('update:filter'))
		window._nc_event_bus?.emit('files:filters:changed')
		this._syncToUrl()
	}

	_syncToUrl() {
		// Encode: field1:val1,val2;field2:val3
		const parts = []
		for (const [fieldName, valueSet] of this._activeFilters) {
			parts.push(`${fieldName.replace('file_gf_', '')}:${[...valueSet].join(',')}`)
		}
		writeUrl({ filters: parts.join(';') || null })
	}

	/**
	 * Apply an encoded filter string: field1:val1,val2;field2:val3, as the
	 * link of a page holds it (UrlState).
	 *
	 * @param {string} raw - The encoded filter string
	 * @param {Array<Object>} columnConfigs - Column configs to resolve field names from
	 */
	loadFromEncoded(raw, columnConfigs) {
		if (!raw) return

		this._activeFilters.clear()

		// Decode: field1:val1,val2;field2:val3
		for (const part of raw.split(';')) {
			const colonIdx = part.indexOf(':')
			if (colonIdx === -1) continue
			const shortName = part.substring(0, colonIdx).trim()
			const valuesStr = part.substring(colonIdx + 1).trim()
			if (!shortName || !valuesStr) continue

			// Resolve full field name: try exact match first, then with file_gf_ prefix.
			// Any field of the team folder counts: a link without a view shows no columns.
			const configs = [...(columnConfigs || this._columnConfigs), ...getAvailableFields()]
			let fieldName = shortName
			const matchedConfig = configs.find(
				c => c.field_name === shortName || c.field_name === 'file_gf_' + shortName,
			)
			if (matchedConfig) {
				fieldName = matchedConfig.field_name
			}

			const values = valuesStr.split(',').filter(Boolean)
			if (values.length > 0) {
				this._activeFilters.set(fieldName, new Set(values))
			}
		}

		this._emitChips()
		this._emitFilterUpdate()
		// The event alone does not narrow the list: the rows are filtered
		// client-side, so the pass has to be run as well. Without it a link
		// showed its chips while the list still held every file.
		this.applyClientSideFilterSort()
	}

	_emitChips() {
		const chips = []
		for (const [fieldName, valueSet] of this._activeFilters) {
			if (valueSet.size === 0) continue
			// A view can filter on a field it does not show as a column.
			const config = this._columnConfigs.find(c => c.field_name === fieldName)
				?? getAvailableFields().find(f => f.field_name === fieldName)
			const label = config?.field_label || fieldName.replace('file_gf_', '')
			// The chip has to say what the dropdown said: a checkbox stores
			// "0" but the option the user ticked reads "No".
			const values = [...valueSet].map((v) => optionLabel(v, config?.field_type, config))
			const MAX_DISPLAY = 2
			let valuesText
			if (values.length <= MAX_DISPLAY) {
				valuesText = values.join(', ')
			} else {
				const more = values.length - MAX_DISPLAY
				// TRANSLATORS: A filter chip that lists two values, e.g. "Draft, Final and 3 others".
				valuesText = n('metavox', '{values} and %n other', '{values} and %n others', more, { values: values.slice(0, MAX_DISPLAY).join(', ') }, PLAIN_TEXT)
			}
			chips.push({
				// TRANSLATORS: Text of a filter chip, e.g. "Status: Approved".
				text: t('metavox', '{field}: {value}', { field: label, value: valuesText }, undefined, PLAIN_TEXT),
				onclick: () => {
					this._activeFilters.delete(fieldName)
					this._emitChips()
					this.applyClientSideFilterSort()
				},
			})
		}
		// Add "Clear all" chip when multiple fields are active
		if (chips.length > 1) {
			chips.push({
				// TRANSLATORS Button chip in the filter bar that removes all active filters; keep it short
				text: '✕ ' + t('metavox', 'Clear all'),
				onclick: () => this.clearFilters(),
			})
		}
		this.dispatchEvent(new CustomEvent('update:chips', { detail: chips }))
	}
}

// ========================================
// Public API
// ========================================

/**
 * Register the MetaVox metadata filter in the Files filter bar.
 * @param {Array<Object>} columnConfigs - Column configs with filterable flag
 * @param {number} groupfolderId - Current groupfolder ID
 * @param {Map<number, Object>} metadataCache - Metadata cache from MetaVoxColumns
 */
export function registerMetaVoxFilter(columnConfigs, groupfolderId, metadataCache) {
	// Always create the instance so getFilterInstance() is non-null for tab click handlers
	if (!filterInstance) {
		filterInstance = new MetaVoxMetadataFilter()
	}

	// Update instance data
	filterInstance.setColumnConfigs(columnConfigs)
	filterInstance.setGroupfolderId(groupfolderId)
	filterInstance.setMetadataCache(metadataCache)

	// Only register in the filter bar if there are filterable fields
	const filterableConfigs = columnConfigs.filter(c => c.filterable)
	if (filterableConfigs.length > 0 && !registered) {
		_register(filterInstance)
		registered = true
	}
}

/**
 * Register at both official entry points: NC33+ read the registry of @nextcloud/files 4,
 * NC31/32 the map and event of @nextcloud/files 3 (what its registerFileListFilter does).
 */
function _register(filter) {
	if (!getFileListFilters().some((f) => f.id === filter.id)) {
		registerFileListFilter(filter)
	}
	window._nc_filelist_filters ??= new Map()
	if (!window._nc_filelist_filters.has(filter.id)) {
		window._nc_filelist_filters.set(filter.id, filter)
		emit('files:filter:added', filter)
	}
}

function _unregister(filterId) {
	unregisterFileListFilter(filterId)
	if (window._nc_filelist_filters?.delete(filterId)) {
		emit('files:filter:removed', filterId)
	}
}

/** The MetaVox button with its panel, for the NC31/32 filter bar. */
function _filterButton(filter) {
	const btn = document.createElement('button')
	btn.className = 'metavox-filter-btn'
	btn.style.cssText = 'display:flex;align-items:center;gap:4px;padding:1px 8px 0 4px;margin:0;border:none;background:transparent;cursor:pointer;border-radius:var(--border-radius-pill,20px);font:inherit;color:var(--color-main-text);'
	btn.innerHTML = `<span style="display:flex;align-items:center;width:20px;height:20px">${METAVOX_ICON_SVG}</span><span>MetaVox</span>`

	let popover = null

	let _popoverApp = null

	btn.addEventListener('click', (e) => {
		e.stopPropagation()
		if (popover) {
			_popoverApp?.unmount()
			_popoverApp = null
			popover.remove()
			popover = null
			return
		}

		// Position relative to the button but mount on document.body
		// to escape will-change/overflow containers
		const btnRect = btn.getBoundingClientRect()

		popover = document.createElement('div')
		popover.style.cssText = `position:fixed;top:${btnRect.bottom + 4}px;left:${btnRect.left}px;z-index:99999;background:var(--color-main-background);border:1px solid var(--color-border);border-radius:var(--border-radius-large,8px);box-shadow:0 4px 16px rgba(0,0,0,.12);padding:8px 0;min-width:250px;max-height:400px;overflow-y:auto;`

		const mountEl = document.createElement('div')
		popover.appendChild(mountEl)

		_popoverApp = createApp({
			render: () => h(MetaVoxFilterPanel, { filter }),
		})
		_popoverApp.config.globalProperties.t = translate
		_popoverApp.config.globalProperties.n = translatePlural
		watchLoading(_popoverApp).mount(mountEl)

		document.body.appendChild(popover)

		// Close on outside click. Inside is known as the click starts: by the time
		// it reaches the page the target can be gone, like a cleared search or a
		// value that leaves the list.
		let clickInside = null
		popover.addEventListener('click', (ev) => { clickInside = ev }, true)
		const closeHandler = (ev) => {
			if (ev !== clickInside && !btn.contains(ev.target)) {
				_popoverApp?.unmount()
				_popoverApp = null
				popover?.remove()
				popover = null
				document.removeEventListener('click', closeHandler)
			}
		}
		setTimeout(() => document.addEventListener('click', closeHandler), 0)
	})

	return btn
}

/**
 * Remove the MetaVox filter from the filter bar.
 */
export function removeFilters() {
	if (!filterInstance) return

	// Clear chips and rows first, while Nextcloud still listens to the filter.
	filterInstance.reset()
	// Also a filter that was never registered goes: its values must not move on to the next folder.
	if (registered) _unregister(FILTER_ID)

	registered = false
	filterInstance = null
}

/**
 * Update the metadata cache reference and re-trigger filter.
 * Call this after metadata is loaded or updated.
 * @param {Map<number, Object>} metadataCache
 */
export function updateFilterCache(metadataCache) {
	if (filterInstance) {
		filterInstance.setMetadataCache(metadataCache)
		filterInstance.triggerRefilter()
	}
}

/**
 * Get the current filter instance.
 * Returns null if no filter is currently registered.
 * @returns {MetaVoxMetadataFilter|null}
 */
export function getFilterInstance() {
	return filterInstance
}
