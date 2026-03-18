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

		// Pre-compute filter entries once (avoid Map iteration overhead per node)
		const filters = []
		for (const [fieldName, valueSet] of this._activeFilters) {
			if (valueSet.size === 0) continue
			// Build a set of positive values (excluding '0' which means "empty/no")
			const positiveValues = new Set()
			for (const v of valueSet) {
				if (v !== '0') positiveValues.add(v)
			}
			filters.push({ fieldName, valueSet, positiveValues, matchEmpty: valueSet.has('0') })
		}
		if (filters.length === 0) return nodes

		return nodes.filter(node => {
			const fileId = node.fileid
			if (!fileId) return true

			const meta = this._metadataCache.get(fileId) || {}

			// AND between fields, OR within a field (multi-select)
			for (const f of filters) {
				const cellValue = meta[f.fieldName]
				const cellEmpty = !cellValue || cellValue === '0' || cellValue === 'false'

				if (cellEmpty) {
					if (f.matchEmpty) continue
					return false
				}

				// File has a value — check if any selected positive value matches
				if (f.positiveValues.size === 0) return false
				const cellStr = String(cellValue)
				// Fast path: no semicolons (single value) — direct Set lookup
				if (!cellStr.includes(';#')) {
					if (!f.positiveValues.has(cellStr)) return false
				} else {
					// Multi-value cell: split on ;# separator
					const parts = cellStr.split(';#')
					let matched = false
					for (let i = 0; i < parts.length; i++) {
						const part = parts[i].trim()
						if (part && f.positiveValues.has(part)) {
							matched = true
							break
						}
					}
					if (!matched) return false
				}
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
		window._nc_event_bus?.emit('files:filters:changed')
		this._syncToUrl()
	}

	_syncToUrl() {
		const params = new URLSearchParams(window.location.search)
		if (this._activeFilters.size > 0) {
			// Encode: field1:val1,val2;field2:val3
			const parts = []
			for (const [fieldName, valueSet] of this._activeFilters) {
				const shortName = fieldName.replace('file_gf_', '')
				parts.push(`${shortName}:${[...valueSet].join(',')}`)
			}
			params.set('mvfilter', parts.join(';'))
		} else {
			params.delete('mvfilter')
		}
		// Don't navigate, just update URL silently
		const newUrl = window.location.pathname + '?' + params.toString() + window.location.hash
		history.replaceState(null, '', newUrl)
	}

	/**
	 * Load filters from the URL ?mvfilter= parameter.
	 * Reads and applies the encoded filter state without triggering navigation.
	 * @param {Array<Object>} columnConfigs - Column configs to resolve field names from
	 */
	loadFromUrl(columnConfigs) {
		const params = new URLSearchParams(window.location.search)
		const raw = params.get('mvfilter')
		if (!raw) return

		this._activeFilters.clear()

		// Decode: field1:val1,val2;field2:val3
		for (const part of raw.split(';')) {
			const colonIdx = part.indexOf(':')
			if (colonIdx === -1) continue
			const shortName = part.substring(0, colonIdx).trim()
			const valuesStr = part.substring(colonIdx + 1).trim()
			if (!shortName || !valuesStr) continue

			// Resolve full field name: try exact match first, then with file_gf_ prefix
			const configs = columnConfigs || this._columnConfigs
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
	}

	_emitChips() {
		const chips = []
		for (const [fieldName, valueSet] of this._activeFilters) {
			if (valueSet.size === 0) continue
			const config = this._columnConfigs.find(c => c.field_name === fieldName)
			const label = config?.field_label || fieldName.replace('file_gf_', '')
			const values = [...valueSet]
			const MAX_DISPLAY = 2
			let valuesText
			if (values.length <= MAX_DISPLAY) {
				valuesText = values.join(', ')
			} else {
				valuesText = values.slice(0, MAX_DISPLAY).join(', ') + ` (+${values.length - MAX_DISPLAY})`
			}
			chips.push({
				text: `${label}: ${valuesText}`,
				onclick: () => {
					this._activeFilters.delete(fieldName)
					this._emitChips()
					this._emitFilterUpdate()
				},
			})
		}
		// Add "Clear all" chip when multiple fields are active
		if (chips.length > 1) {
			chips.push({
				text: '✕ Clear all',
				onclick: () => this.reset(),
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
	// Always create the instance so getFilterInstance() is non-null for tab click handlers
	if (!filterInstance) {
		filterInstance = new MetaVoxMetadataFilter()
	}

	// Update instance data
	filterInstance.setColumnConfigs(columnConfigs)
	filterInstance.setGroupfolderId(groupfolderId)
	filterInstance.setMetadataCache(metadataCache)

	// Only register in NC33 filter bar if there are filterable fields
	const filterableConfigs = columnConfigs.filter(c => c.filterable)
	if (filterableConfigs.length > 0 && !registered) {
		_registerDirect()
	}
}

let _registerRetries = 0
const _MAX_REGISTER_RETRIES = 10

function _registerDirect() {
	// NC33 uses the scoped fileListFilters map for its internal store.
	// Writing there + emitting 'files:filters:changed' triggers NC33 to
	// pick up the filter and wire the reactive chip listener via its store.
	const scope = window._nc_files_scope?.v4_0
	if (!scope) {
		_scheduleRetry('scoped globals not found')
		return
	}

	scope.fileListFilters ??= new Map()
	if (scope.fileListFilters.has(FILTER_ID)) {
		registered = true
		_registerRetries = 0
		return
	}

	scope.fileListFilters.set(FILTER_ID, filterInstance)

	// Wire chip listener via NC33 store directly so chips are reactive
	const container = document.querySelector('[class*="fileListFilters"]')
	const store = container?.__vue__?._setupState?.filterStore
	if (!store?.filters) {
		// Filter bar DOM not ready yet — retry
		scope.fileListFilters.delete(FILTER_ID)
		_scheduleRetry('filter bar DOM not ready')
		return
	}

	if (!store.filters.some(f => f.id === FILTER_ID)) {
		// Use NC33's internal registerFilter action if available, else push
		if (typeof store.registerFilter === 'function') {
			store.registerFilter(filterInstance)
		} else {
			store.filters.push(filterInstance)
			// Wire chip listener manually (mirrors NC33's internal a(filter))
			filterInstance.addEventListener('update:chips', (e) => {
				store.$patch(state => {
					const chips = e.detail || []
					const next = { ...state.chips }
					if (chips.length > 0) {
						next[FILTER_ID] = chips
					} else {
						delete next[FILTER_ID]
					}
					state.chips = next
				})
			})
		}
	}

	registered = true
	_registerRetries = 0
	console.info('MetaVox: Filter registered in NC33 filter bar')
}

function _scheduleRetry(reason) {
	if (_registerRetries >= _MAX_REGISTER_RETRIES) {
		console.warn(`MetaVox: Filter registration failed after ${_MAX_REGISTER_RETRIES} retries (${reason})`)
		return
	}
	_registerRetries++
	const delay = Math.min(200 * _registerRetries, 2000)
	setTimeout(() => {
		if (!registered) _registerDirect()
	}, delay)
}

/**
 * Remove the MetaVox filter from NC33's filter bar.
 */
export function removeFilters() {
	if (!registered || !filterInstance) return

	// Remove from NC33 scoped map
	const scope = window._nc_files_scope?.v4_0
	if (scope?.fileListFilters) {
		scope.fileListFilters.delete(FILTER_ID)
	}

	// Remove from store filters array
	const container = document.querySelector('[class*="fileListFilters"]')
	const store = container?.__vue__?._setupState?.filterStore
	if (store?.filters) {
		const idx = store.filters.findIndex(f => f.id === FILTER_ID)
		if (idx !== -1) store.filters.splice(idx, 1)
	}
	if (store?.chips && store.chips[FILTER_ID]) {
		store.$patch(state => { delete state.chips[FILTER_ID] })
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

/**
 * Get the current filter instance.
 * Returns null if no filter is currently registered.
 * @returns {MetaVoxMetadataFilter|null}
 */
export function getFilterInstance() {
	return filterInstance
}
