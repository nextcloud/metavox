/**
 * MetaVox Sorting — sort handling, sort indicators, and NC33 sort bypass.
 *
 * Extracted from MetaVoxColumns.js
 */

import { HEADER_MARKER } from './ColumnStyles.js'
import { getFilterInstance } from './MetadataFilter.js'
import { metadataCache } from './MetaVoxState.js'

// ── Sort icon SVGs ──────────────────────────────────────────────
const SORT_ICON_ASC = '<svg fill="currentColor" width="24" height="24" viewBox="0 0 24 24"><path d="M7,15L12,10L17,15H7Z"></path></svg>'
const SORT_ICON_DESC = '<svg fill="currentColor" width="24" height="24" viewBox="0 0 24 24"><path d="M7,10L12,15L17,10H7Z"></path></svg>'

// ── Current sort state ──────────────────────────────────────────
let currentSort = null

export function getCurrentSort() {
	return currentSort
}

export function setCurrentSort(v) {
	currentSort = v
}

// ========================================
// Sorting
// ========================================

export function handleSort(fieldName, fieldType) {
	const fi = getFilterInstance()
	if (!fi) return

	const current = fi.getSortState()
	if (current?.fieldName === fieldName) {
		fi.setSortState({ fieldName, fieldType, direction: current.direction === 'asc' ? 'desc' : 'asc' })
	} else {
		fi.setSortState({ fieldName, fieldType, direction: 'asc' })
	}

	// Client-side sort using metadataCache — no server call needed.
	// Build sorted file IDs from the cache.
	const sortState = fi.getSortState()
	const ids = [...metadataCache.keys()]
	ids.sort((a, b) => {
		const metaA = metadataCache.get(a) || {}
		const metaB = metadataCache.get(b) || {}
		const valA = metaA[fieldName] ?? ''
		const valB = metaB[fieldName] ?? ''

		// Push empty values to bottom
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
		return sortState.direction === 'desc' ? -cmp : cmp
	})

	// Set the sorted IDs on the filter instance so NC uses our order
	fi._serverFileIds = ids
	fi._serverFileIdSet = new Set(ids)
	fi._serverFileIdMap = new Map()
	for (let i = 0; i < ids.length; i++) {
		fi._serverFileIdMap.set(ids[i], i)
	}

	ensureSortBypass()
	updateSortIndicators()
	fi._emitFilterUpdate()
}

export function updateSortIndicators() {
	const sortState = getFilterInstance()?.getSortState()
	document.querySelectorAll('.' + HEADER_MARKER).forEach(th => {
		const fieldName = th.dataset.metavoxField
		const iconEl = th.querySelector('.files-list__column-sort-button-icon')
		if (!iconEl) return

		if (sortState?.fieldName === fieldName) {
			iconEl.innerHTML = sortState.direction === 'asc' ? SORT_ICON_ASC : SORT_ICON_DESC
			iconEl.style.opacity = '1'
		} else {
			iconEl.innerHTML = SORT_ICON_ASC
			iconEl.style.opacity = '0'
		}
	})
}

// ========================================
// NC33 Sort Bypass
// ========================================

/**
 * Override NC33's internal dirContentsSorted computed so that when MetaVox sort
 * is active, NC33 skips its own re-sort and uses the already-sorted filter output.
 *
 * NC33 pipeline: filter(nodes) → dirContentsFiltered → dirContentsSorted (re-sort) → render
 * With override:  filter(nodes) → dirContentsFiltered (already sorted) → passthrough → render
 */
let _origSortGetter = null
let _bypassedFilesList = null // track which instance we patched

export function _findFilesList() {
	const filesListEl = document.querySelector('.files-list')
	if (!filesListEl) return null
	const vm = filesListEl['__vue__']
	if (!vm) return null

	let current = vm
	for (let i = 0; i < 8 && current; i++) {
		if (current.$options && current.$options.name === 'FilesList') return current
		current = current.$parent
	}
	return null
}

export function ensureSortBypass(retries) {
	const filesList = _findFilesList()
	if (!filesList) {
		// FilesList may not be mounted yet — retry with backoff
		const attempt = retries || 0
		if (attempt < 10) {
			setTimeout(() => ensureSortBypass(attempt + 1), 200 * (attempt + 1))
		}
		return
	}

	const watcher = filesList._computedWatchers?.dirContentsSorted
	if (!watcher?.getter) return

	// Install the bypass if not yet patched on this instance
	if (_bypassedFilesList !== filesList) {
		_origSortGetter = watcher.getter
		_bypassedFilesList = filesList

		watcher.getter = function () {
			const fi = getFilterInstance()
			if (fi?.getSortState()) {
				return filesList.dirContentsFiltered
			}
			return _origSortGetter.call(filesList)
		}
	}

	// Force the computed to re-evaluate with the (potentially new) sort state
	watcher.dirty = true
	watcher.evaluate()
	filesList.$forceUpdate()
}

export function uninstallSortBypass() {
	if (!_bypassedFilesList || !_origSortGetter) return

	const watcher = _bypassedFilesList._computedWatchers?.dirContentsSorted
	if (watcher) {
		watcher.getter = _origSortGetter
		watcher.dirty = true
		watcher.evaluate()
		_bypassedFilesList.$forceUpdate()
	}
	_bypassedFilesList = null
	_origSortGetter = null
}
