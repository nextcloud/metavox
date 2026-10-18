/**
 * MetaVox Sorting — sort handling, sort indicators, and NC33 sort bypass.
 *
 * Extracted from MetaVoxColumns.js
 */

import { HEADER_MARKER } from './ColumnStyles.js'
import { _findFilesList } from './FilesListRef.js'
import { getFilterInstance } from './MetadataFilter.js'

// ── Sort icon SVGs ──────────────────────────────────────────────
const SORT_ICON_ASC = '<svg fill="currentColor" width="24" height="24" viewBox="0 0 24 24"><path d="M7,15L12,10L17,15H7Z"></path></svg>'
const SORT_ICON_DESC = '<svg fill="currentColor" width="24" height="24" viewBox="0 0 24 24"><path d="M7,10L12,15L17,10H7Z"></path></svg>'

// Values for the sort are still loading.
let _sortPending = false

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

	// Sorting happens in the filter, in the browser, on the cached values.
	updateSortIndicators()
	fi.reapply()
}

export function updateSortIndicators() {
	const sortState = getFilterInstance()?.getSortState()
	document.querySelectorAll('.' + HEADER_MARKER).forEach(th => {
		const fieldName = th.dataset.metavoxField
		// The loading sign goes with the sort.
		th.classList.toggle('metavox-sort-pending', _sortPending && sortState?.fieldName === fieldName)
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

/**
 * Show on the sorted column that its values are still loading; the rows stay usable.
 */
export function setSortPending(pending) {
	_sortPending = pending
	updateSortIndicators()
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
let _unwatchNcSort = null
// Whether the list was last sorted in the MetaVox order: only then does the
// shown order differ from the one the Files app would show.
let _usedOurs = false

/**
 * @returns {boolean} true if the bypass was installed or already active
 */
export function ensureSortBypass(retries) {
	const filesList = _findFilesList()
	if (!filesList) {
		// FilesList may not be mounted yet — retry with backoff
		const attempt = retries || 0
		if (attempt < 10) {
			setTimeout(() => ensureSortBypass(attempt + 1), 200 * (attempt + 1))
		}
		return false
	}

	const watcher = filesList._computedWatchers?.dirContentsSorted
	if (!watcher?.getter) return false

	// Install the bypass if not yet patched on this instance
	if (_bypassedFilesList !== filesList) {
		_unwatchNcSort?.()
		_origSortGetter = watcher.getter
		_bypassedFilesList = filesList
		_usedOurs = false

		watcher.getter = function () {
			// Read the filtered list first: that runs filter(), which knows whether it sorted.
			const filtered = filesList.dirContentsFiltered
			const fi = getFilterInstance()
			if (fi?.getSortState() && !fi.isHeld()) {
				_usedOurs = true
				// A copy, like NC's own sort: the filtered list is reactive data in NC35, and
				// rows reading an observed array make Vue walk every node on each read.
				return filtered.slice()
			}
			// No MetaVox sort, or its values are still loading: NC keeps its own order.
			_usedOurs = false
			return _origSortGetter.call(filesList)
		}

		// Sorting on a column of the Files app ends the MetaVox sort; until
		// now the MetaVox order kept winning. A string, so only a real change
		// of column or direction counts.
		_unwatchNcSort = filesList.$watch?.(() => `${filesList.sortingMode}:${filesList.isAscSorting}`, () => {
			const fi = getFilterInstance()
			if (!fi?.getSortState()) return
			fi.setSortState(null)
			updateSortIndicators()
			fi.reapply()
		}) ?? null
	}

	// Only when the MetaVox order is or was in use can the order change.
	// Marked, not computed: Vue sorts once when it draws, with the filter as
	// it is by then (whether values are still loading included). Computing
	// here sorted all nodes for an order that was stale before it was shown.
	if (_usedOurs || getFilterInstance()?.getSortState()) {
		watcher.dirty = true
		filesList.$forceUpdate()
	}
	return true
}

export function uninstallSortBypass() {
	if (!_bypassedFilesList || !_origSortGetter) return

	_unwatchNcSort?.()
	_unwatchNcSort = null
	const watcher = _bypassedFilesList._computedWatchers?.dirContentsSorted
	if (watcher) {
		watcher.getter = _origSortGetter
		// Only an order of ours needs replacing.
		if (_usedOurs) {
			watcher.dirty = true
			_bypassedFilesList.$forceUpdate()
		}
	}
	_bypassedFilesList = null
	_origSortGetter = null
	_usedOurs = false
}

// ========================================
// NC32 DOM-based row reorder fallback
// ========================================

/**
 * Reorder (and optionally filter) tbody rows by sorted file IDs.
 * Used on NC32 where Vue internals are not accessible for sort bypass.
 * Rows whose file ID is not in sortedIds are hidden (filtered out).
 * Pass null to reset (show all rows in original order).
 */
export function reorderDomRows(sortedIds) {
	const tbody = document.querySelector('.files-list__table tbody')
	if (!tbody) return

	const rows = [...tbody.querySelectorAll('tr[data-cy-files-list-row]')]

	// Reset: show all rows
	if (!sortedIds) {
		for (const row of rows) {
			row.style.display = ''
		}
		return
	}

	const orderMap = new Map()
	for (let i = 0; i < sortedIds.length; i++) {
		orderMap.set(sortedIds[i], i)
	}

	// Hide rows not in the sorted set, show the rest
	for (const row of rows) {
		const id = Number(row.getAttribute('data-cy-files-list-row-fileid'))
		row.style.display = orderMap.has(id) ? '' : 'none'
	}

	// Sort visible rows
	rows.filter(r => r.style.display !== 'none').sort((a, b) => {
		const idA = Number(a.getAttribute('data-cy-files-list-row-fileid'))
		const idB = Number(b.getAttribute('data-cy-files-list-row-fileid'))
		return (orderMap.get(idA) ?? 0) - (orderMap.get(idB) ?? 0)
	}).forEach(row => tbody.appendChild(row))
}
