/**
 * MetaVox File List Filters — NC33 Native Filter Bar Integration
 *
 * Registers a MetaVox metadata filter in NC33's native filter bar
 * (alongside Type, Modified, People) using the registerFileListFilter() API.
 */

import './MetaVoxFilterElement.js'
import { createApp, h } from 'vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import MetaVoxFilterPanel from './MetaVoxFilterPanel.vue'
import { ensureSortBypass, reorderDomRows } from './Sorting.js'

const METAVOX_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="-4 -3 32 30"><polygon fill="currentColor" points="6.4,0.9 2,10.1 11,10.1"/><polygon fill="currentColor" points="17.5,0.9 13,10.1 22,10.1"/><rect x="2" y="10.1" width="20" height="13.1" rx="0.5" fill="none" stroke="currentColor" stroke-width="1.8"/><line x1="4.9" y1="15" x2="18.8" y2="15" stroke="currentColor" stroke-width="1"/><line x1="4.9" y1="17.4" x2="18.8" y2="17.4" stroke="currentColor" stroke-width="1"/><line x1="4.9" y1="19.8" x2="13" y2="19.8" stroke="currentColor" stroke-width="1"/></svg>'

const FILTER_ID = 'metavox-metadata'

function _compareValues(a, b, fieldType) {
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
		this.order = 100
		this.displayName = 'MetaVox'
		this.iconSvgInline = METAVOX_ICON_SVG
		this.tagName = 'metavox-metadata-filter'

		this._activeFilters = new Map() // fieldName -> Set<value>
		this._metadataCache = null
		this._columnConfigs = []
		this._groupfolderId = null
		this._sortState = null // { fieldName, fieldType, direction } or null

		// Server-side sorted/filtered file IDs
		this._serverFileIds = null // ordered file IDs from server
		this._serverFileIdSet = null // Set for O(1) membership check
		this._serverFileIdMap = null // Map<fileId, index> for O(1) sort position
	}

	// ========================================
	// IFileListFilter interface
	// ========================================

	/**
	 * Mount the filter UI into the given element.
	 * On NC32, this is called by onUpdated for every filter — must not crash.
	 * The real UI is handled by our own injected button popover.
	 */
	async mount(el) {
		if (!el) return
		this.currentInstance = { $el: el, $destroy: () => {} }
	}

	filter(nodes) {
		// Server-side: use pre-computed sorted/filtered file IDs
		if (this._serverFileIds && this._serverFileIds.length > 0) {
			const idSet = this._serverFileIdSet
			const orderMap = this._serverFileIdMap
			const hasActiveFilters = this._activeFilters.size > 0

			// Filter: if filters are active, remove non-matching nodes.
			// If only sorting (no filters), keep ALL nodes.
			let result = hasActiveFilters
				? nodes.filter(node => !node.fileid || idSet.has(node.fileid))
				: [...nodes]

			// Sort by server-provided order (unmatched nodes go to bottom)
			if (this._sortState) {
				result.sort((a, b) => {
					const posA = orderMap.get(a.fileid) ?? Number.MAX_SAFE_INTEGER
					const posB = orderMap.get(b.fileid) ?? Number.MAX_SAFE_INTEGER
					return posA - posB
				})
			}

			return result
		}

		// Fallback: client-side filtering (when server call hasn't completed yet)
		let result = nodes

		if (this._activeFilters.size > 0 && this._metadataCache) {
			const filters = []
			for (const [fieldName, valueSet] of this._activeFilters) {
				if (valueSet.size === 0) continue
				const positiveValues = new Set()
				for (const v of valueSet) {
					if (v !== '0') positiveValues.add(v)
				}
				filters.push({ fieldName, valueSet, positiveValues, matchEmpty: valueSet.has('0') })
			}

			if (filters.length > 0) {
				result = result.filter(node => {
					const fileId = node.fileid
					if (!fileId) return true
					const meta = this._metadataCache.get(fileId) || {}
					for (const f of filters) {
						const cellValue = meta[f.fieldName]
						const cellEmpty = !cellValue || cellValue === '0' || cellValue === 'false'
						if (cellEmpty) {
							if (f.matchEmpty) continue
							return false
						}
						if (f.positiveValues.size === 0) return false
						const cellStr = String(cellValue)
						if (!cellStr.includes(';#')) {
							if (!f.positiveValues.has(cellStr)) return false
						} else {
							const parts = cellStr.split(';#')
							let matched = false
							for (let i = 0; i < parts.length; i++) {
								const part = parts[i].trim()
								if (part && f.positiveValues.has(part)) { matched = true; break }
							}
							if (!matched) return false
						}
					}
					return true
				})
			}
		}

		if (this._sortState && this._metadataCache) {
			const { fieldName, fieldType, direction } = this._sortState
			const multiplier = direction === 'asc' ? 1 : -1
			result = [...result].sort((a, b) => {
				const metaA = this._metadataCache.get(a.fileid) || {}
				const metaB = this._metadataCache.get(b.fileid) || {}
				return multiplier * _compareValues(metaA[fieldName], metaB[fieldName], fieldType)
			})
		}

		return result
	}

	reset() {
		this._activeFilters.clear()
		this._sortState = null
		this.clearServerState()
		this._emitChips()
		this._emitFilterUpdate()
		// NC32 fallback: restore all DOM rows
		reorderDomRows(null)
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

	/**
	 * Trigger NC33 to re-run filter() which includes sorting.
	 */
	triggerResort() {
		this._emitFilterUpdate()
	}

	// ========================================
	// Server-side sort/filter
	// ========================================

	/**
	 * Apply filters and sort client-side using the metadataCache.
	 * Zero server calls — instant filtering and sorting.
	 */
	applyClientSideFilterSort() {
		if (!this._metadataCache) return

		// Deselect all files — the visible set is about to change
		const headerCheckbox = document.querySelector('.files-list__row-head .files-list__row-checkbox input[type="checkbox"]')
		if (headerCheckbox && (headerCheckbox.checked || headerCheckbox.indeterminate)) {
			headerCheckbox.click()
		}

		// Nothing to do — clear server IDs so NC shows all files
		if (!this._sortState && this._activeFilters.size === 0) {
			this._serverFileIds = null
			this._serverFileIdSet = null
			this._serverFileIdMap = null
			this._emitFilterUpdate()
			// NC32 fallback: restore all DOM rows
			reorderDomRows(null)
			return
		}

		// Start with all cached file IDs
		let ids = [...this._metadataCache.keys()]

		// Apply filters (AND between fields, OR within a field)
		if (this._activeFilters.size > 0) {
			ids = ids.filter(fileId => {
				const meta = this._metadataCache.get(fileId) || {}
				for (const [fieldName, valueSet] of this._activeFilters) {
					const cellValue = meta[fieldName]
					const cellEmpty = !cellValue || cellValue === '0' || cellValue === 'false'

					const hasEmptyFilter = valueSet.has('0')
					const realValues = new Set([...valueSet].filter(v => v !== '0'))

					if (cellEmpty) {
						if (hasEmptyFilter) continue
						return false
					}

					if (realValues.size === 0 && hasEmptyFilter) return false

					if (realValues.size > 0) {
						const cellStr = String(cellValue)
						if (!cellStr.includes(';#')) {
							if (!realValues.has(cellStr)) return false
						} else {
							const parts = cellStr.split(';#').map(p => p.trim())
							if (!parts.some(p => realValues.has(p))) return false
						}
					}
				}
				return true
			})
		}

		// Apply sort
		if (this._sortState) {
			const { fieldName, fieldType, direction } = this._sortState
			ids.sort((a, b) => {
				const metaA = this._metadataCache.get(a) || {}
				const metaB = this._metadataCache.get(b) || {}
				const valA = metaA[fieldName] ?? ''
				const valB = metaB[fieldName] ?? ''

				if (!valA && valB) return 1
				if (valA && !valB) return -1
				if (!valA && !valB) return 0

				let cmp = 0
				if (fieldType === 'number' || fieldType === 'integer' || fieldType === 'float') {
					cmp = parseFloat(valA) - parseFloat(valB)
				} else if (fieldType === 'date') {
					cmp = new Date(valA).getTime() - new Date(valB).getTime()
				} else if (fieldType === 'checkbox' || fieldType === 'boolean') {
					cmp = (valA === '1' ? 0 : 1) - (valB === '1' ? 0 : 1)
				} else {
					cmp = String(valA).localeCompare(String(valB))
				}
				return direction === 'desc' ? -cmp : cmp
			})
		}

		this._serverFileIds = ids
		this._serverFileIdSet = new Set(ids)
		this._serverFileIdMap = new Map()
		for (let i = 0; i < ids.length; i++) {
			this._serverFileIdMap.set(ids[i], i)
		}

		// NC33: sort bypass patches Vue computed; NC32: reorder DOM rows directly
		const bypassOk = ensureSortBypass()
		this._emitFilterUpdate()
		if (!bypassOk) {
			reorderDomRows(ids)
		}
	}

	/**
	 * Clear server-side state (e.g. on directory change or view clear).
	 */
	clearServerState() {
		this._serverFileIds = null
		this._serverFileIdSet = null
		this._serverFileIdMap = null
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
					this.applyClientSideFilterSort()
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
	// Find the global filter map: NC33 uses _nc_files_scope.v4_0.fileListFilters,
	// NC32 uses _nc_filelist_filters directly
	let filtersMap = window._nc_files_scope?.v4_0?.fileListFilters
		|| window._nc_filelist_filters
	if (!filtersMap) {
		_scheduleRetry('filter map not found')
		return
	}

	if (filtersMap.has(FILTER_ID)) {
		registered = true
		_registerRetries = 0
		return
	}

	// Register in the global map first — NC32's onUpdated reads from filtersWithUI
	// which is a computed from the store, so we need to be in the store before the DOM renders
	filtersMap.set(FILTER_ID, filterInstance)

	// Find the filter store via the filter bar DOM
	const container = document.querySelector('[class*="fileListFilters"], .file-list-filters')
	const store = container?.__vue__?._setupState?.filterStore
	if (!store?.filters) {
		// Keep in filtersMap but retry store registration
		_scheduleRetry('filter bar DOM not ready')
		return
	}

	if (!store.filters.some(f => f.id === FILTER_ID)) {
		if (window._nc_files_scope?.v4_0) {
			// NC33: register with full UI support
			if (typeof store.registerFilter === 'function') {
				store.registerFilter(filterInstance)
			} else {
				store.filters.push(filterInstance)
			}
		} else {
			// NC32: register in store for filter() to be called, but mount() is a no-op.
			// The UI is handled by our own injected button + popover.
			try {
				if (typeof store.addFilter === 'function') {
					store.addFilter(filterInstance)
				} else if (Array.isArray(store.filters)) {
					store.filters.push(filterInstance)
				}
			} catch (e) {
				// Store registration failed — filter will work client-side only
			}
		}
		// Wire chip listener (NC33 only — NC32 doesn't support store.$patch for filters)
		if (window._nc_files_scope?.v4_0 && typeof store.$patch === 'function') {
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

	// NC32: inject our own filter button since we can't use store.addFilter
	if (!window._nc_files_scope?.v4_0) {
		_injectNC32FilterButton(container)
	}

	registered = true
	_registerRetries = 0
	console.info('MetaVox: Filter registered in NC filter bar')
}

function _injectNC32FilterButton(filterBar) {
	if (!filterBar || document.getElementById('metavox-filter-btn')) return

	const btnContainer = filterBar.querySelector('.file-list-filters__filter')
	if (!btnContainer) return

	const btn = document.createElement('button')
	btn.id = 'metavox-filter-btn'
	btn.className = 'action-item action-item--default-popover action-item--tertiary'
	btn.style.cssText = 'display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border:none;background:transparent;cursor:pointer;border-radius:var(--border-radius-pill,20px);font:inherit;color:var(--color-main-text);height:var(--default-clickable-area,44px);vertical-align:middle;'
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
			render: () => h(MetaVoxFilterPanel, { filter: filterInstance }),
		})
		_popoverApp.config.globalProperties.t = translate
		_popoverApp.config.globalProperties.n = translatePlural
		_popoverApp.mount(mountEl)

		document.body.appendChild(popover)

		// Close on outside click
		const closeHandler = (ev) => {
			if (!popover?.contains(ev.target) && !btn.contains(ev.target)) {
				_popoverApp?.unmount()
				_popoverApp = null
				popover?.remove()
				popover = null
				document.removeEventListener('click', closeHandler)
			}
		}
		setTimeout(() => document.addEventListener('click', closeHandler), 0)
	})

	btnContainer.appendChild(btn)
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
 * Remove the MetaVox filter from the filter bar.
 */
export function removeFilters() {
	if (!registered || !filterInstance) return

	// Remove from global filter map (NC33 or NC32)
	const filtersMap = window._nc_files_scope?.v4_0?.fileListFilters
		|| window._nc_filelist_filters
	if (filtersMap) {
		filtersMap.delete(FILTER_ID)
	}

	// Remove from store filters array
	const container = document.querySelector('[class*="fileListFilters"], .file-list-filters')
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
