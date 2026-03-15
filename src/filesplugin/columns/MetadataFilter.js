/**
 * MetaVox File List Filters — NC33 Native Filter Bar Integration
 *
 * Registers a MetaVox metadata filter in NC33's native filter bar
 * (alongside Type, Modified, People) using the registerFileListFilter() API.
 */

import './MetaVoxFilterElement.js'

const METAVOX_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="-1 0 23 24"><path d="M0 0 C2.45685425 0.42359556 3.6964912 0.65510363 5.375 2.5625 C7.01309099 4.27469613 7.01309099 4.27469613 9.9375 4.4375 C13.65445502 3.90650643 14.5402558 2.7142005 17 0 C17.99 0 18.98 0 20 0 C20.93405225 4.35891051 20.81144268 7.63849557 20 12 C20.33 12.33 20.66 12.66 21 13 C21.04080783 14.99958364 21.04254356 17.00045254 21 19 C19.576875 19.680625 19.576875 19.680625 18.125 20.375 C14.99686321 21.80238254 14.99686321 21.80238254 13 24 C9.12472131 24.51670383 7.52342441 24.37735248 4.3125 22.0625 C3.549375 21.381875 2.78625 20.70125 2 20 C1.01 19.67 0.02 19.34 -1 19 C-1.125 13.25 -1.125 13.25 0 11 C-0.25556108 8.98745646 -0.51448107 6.97446167 -0.84375 4.97265625 C-1 3 -1 3 0 0 Z" fill="currentColor" transform="translate(2,0)"/></svg>'

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
		this.order = 50
		this.displayName = 'MetaVox'
		this.iconSvgInline = METAVOX_ICON_SVG
		this.tagName = 'metavox-metadata-filter'

		this._activeFilters = new Map() // fieldName -> Set<value>
		this._metadataCache = null
		this._columnConfigs = []
		this._groupfolderId = null
	}

	// ========================================
	// IFileListFilter interface
	// ========================================

	filter(nodes) {
		if (this._activeFilters.size === 0) return nodes
		if (!this._metadataCache) return nodes

		return nodes.filter(node => {
			const fileId = node.fileid
			if (!fileId) return true

			const meta = this._metadataCache.get(fileId) || {}

			// AND between fields, OR within a field (multi-select)
			for (const [fieldName, valueSet] of this._activeFilters) {
				if (valueSet.size === 0) continue
				const cellValue = meta[fieldName]
				if (!cellValue) return false
				const cellValues = String(cellValue).split(/;\s*/).filter(Boolean)
				const matches = [...valueSet].some(v => cellValues.includes(v))
				if (!matches) return false
			}
			return true
		})
	}

	reset() {
		this._activeFilters.clear()
		this._emitChips()
		this._emitFilterUpdate()
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
		this._emitFilterUpdate()
	}

	/**
	 * Clear all selected values for a field.
	 * @param {string} fieldName
	 */
	clearFieldFilter(fieldName) {
		this._activeFilters.delete(fieldName)
		this._emitChips()
		this._emitFilterUpdate()
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
	 * Re-trigger filter (e.g., after metadata cache is updated)
	 */
	triggerRefilter() {
		if (this._activeFilters.size > 0) {
			this._emitFilterUpdate()
		}
	}

	// ========================================
	// Internal
	// ========================================

	_emitFilterUpdate() {
		this.dispatchEvent(new CustomEvent('update:filter'))
		// NC33 doesn't wire up listeners on dynamically-added filters,
		// so also notify via the global event bus to trigger re-filtering.
		window._nc_event_bus?.emit('files:filters:changed')
	}

	_emitChips() {
		const chips = []
		for (const [fieldName, valueSet] of this._activeFilters) {
			if (valueSet.size === 0) continue
			const config = this._columnConfigs.find(c => c.field_name === fieldName)
			const label = config?.field_label || fieldName.replace('file_gf_', '')
			const valuesText = [...valueSet].join(', ')
			chips.push({
				text: `${label}: ${valuesText}`,
				onclick: () => {
					this._activeFilters.delete(fieldName)
					this._emitChips()
					this._emitFilterUpdate()
				},
			})
		}
		this.dispatchEvent(new CustomEvent('update:chips', { detail: chips }))
	}
}

// ========================================
// Public API
// ========================================

/**
 * Register the MetaVox metadata filter in NC33's filter bar.
 * @param {Array<Object>} columnConfigs - Column configs with filterable flag
 * @param {number} groupfolderId - Current groupfolder ID
 * @param {Map<number, Object>} metadataCache - Metadata cache from MetaVoxColumns
 */
export function registerMetaVoxFilter(columnConfigs, groupfolderId, metadataCache) {
	// Only register if there are filterable fields
	const filterableConfigs = columnConfigs.filter(c => c.filterable)
	if (filterableConfigs.length === 0) return

	// Create instance if not exists
	if (!filterInstance) {
		filterInstance = new MetaVoxMetadataFilter()
	}

	// Update instance data
	filterInstance.setColumnConfigs(columnConfigs)
	filterInstance.setGroupfolderId(groupfolderId)
	filterInstance.setMetadataCache(metadataCache)

	// Register directly in NC33's scoped globals (same pattern as sidebar tab)
	if (!registered) {
		_registerDirect()
	}
}

/**
 * Wire up chip events so NC33 displays active filter chips.
 * NC33 normally does this during store init, but our filter is added later.
 */
function _wireChipListener(store) {
	filterInstance.addEventListener('update:chips', (e) => {
		const chips = e.detail
		if (chips && chips.length > 0) {
			store.chips[FILTER_ID] = chips
		} else {
			delete store.chips[FILTER_ID]
		}
	})
}

function _registerDirect() {
	const scope = window._nc_files_scope?.v4_0
	if (!scope) {
		console.warn('MetaVox: No NC33 scoped globals found, cannot register filter')
		return
	}

	// Register in scoped Map
	scope.fileListFilters ??= new Map()
	if (!scope.fileListFilters.has(FILTER_ID)) {
		scope.fileListFilters.set(FILTER_ID, filterInstance)
	}

	// Also push into the Pinia store so the UI renders the button.
	// NC33's FileListFilters Vue component reads from a Pinia store,
	// not from the scoped Map. We access the store via the component instance.
	const filterContainer = document.querySelector('[class*="fileListFilters"]')
	const vm = filterContainer?.__vue__
	const store = vm?._setupState?.filterStore
	if (store?.filters && !store.filters.some(f => f.id === FILTER_ID)) {
		store.filters.push(filterInstance)
		_wireChipListener(store)
		registered = true
		window._nc_event_bus?.emit('files:filters:changed')
		console.info('MetaVox: Filter registered in NC33 filter bar')
	} else if (!store) {
		// Store not ready yet — retry after a short delay
		setTimeout(() => {
			const container2 = document.querySelector('[class*="fileListFilters"]')
			const vm2 = container2?.__vue__
			const store2 = vm2?._setupState?.filterStore
			if (store2?.filters && !store2.filters.some(f => f.id === FILTER_ID)) {
				store2.filters.push(filterInstance)
				_wireChipListener(store2)
				registered = true
				window._nc_event_bus?.emit('files:filters:changed')
				console.info('MetaVox: Filter registered in NC33 filter bar (delayed)')
			}
		}, 1000)
	} else {
		registered = true
	}
}

/**
 * Remove the MetaVox filter from NC33's filter bar.
 */
export function removeFilters() {
	if (!registered || !filterInstance) return

	// Remove from Pinia store
	const filterContainer = document.querySelector('[class*="fileListFilters"]')
	const vm = filterContainer?.__vue__
	const store = vm?._setupState?.filterStore
	if (store?.filters) {
		const idx = store.filters.findIndex(f => f.id === FILTER_ID)
		if (idx !== -1) store.filters.splice(idx, 1)
	}

	// Clean scoped Map
	const scope = window._nc_files_scope?.v4_0
	if (scope?.fileListFilters) {
		scope.fileListFilters.delete(FILTER_ID)
	}

	if (filterInstance) {
		filterInstance.reset()
	}

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
