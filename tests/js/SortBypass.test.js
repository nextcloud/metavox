// @vitest-environment happy-dom
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/filesplugin/columns/ColumnStyles.js', () => ({ HEADER_MARKER: 'metavox-header' }))
vi.mock('../../src/filesplugin/columns/FilesListRef.js', () => ({ _findFilesList: vi.fn() }))
vi.mock('../../src/filesplugin/columns/MetadataFilter.js', () => ({ getFilterInstance: vi.fn() }))

const NC_ORDER = ['a', 'b', 'c']
const OUR_ORDER = ['c', 'a', 'b']
let sorting
let list
let fi
let heldByNextFilter

// A FilesList with the Vue 2 computed watcher the bypass patches, and the
// Files app's own sort setting it watches.
function fakeFilesList() {
	const ncSort = vi.fn(() => NC_ORDER)
	const watchers = []
	const fake = {
		sortingMode: 'mtime',
		isAscSorting: false,
		// Like NC: reading the filtered list runs filter(), which decides whether it held back.
		get dirContentsFiltered() {
			fi.held = heldByNextFilter
			return OUR_ORDER
		},
		$forceUpdate: vi.fn(),
		$watch(fn, cb) {
			const w = { fn, cb, last: fn(), active: true }
			watchers.push(w)
			return () => { w.active = false }
		},
		// What Vue does when the Files app changes its sort setting.
		setNcSort(mode, asc) {
			fake.sortingMode = mode
			fake.isAscSorting = asc
			for (const w of watchers.filter((x) => x.active)) {
				const next = w.fn()
				if (next !== w.last) { w.last = next; w.cb() }
			}
		},
		ncSort,
	}
	fake._computedWatchers = {
		dirContentsSorted: { getter: ncSort, dirty: false, evaluate() { this.value = this.getter.call(fake); this.dirty = false } },
	}
	return fake
}

const sorted = () => list._computedWatchers.dirContentsSorted
// What the screen shows: Vue reads the computed when it draws, and computes it then if it is stale.
const drawn = () => {
	if (sorted().dirty || !('value' in sorted())) sorted().evaluate()
	return sorted().value
}
const shownOrder = () => {
	sorting.ensureSortBypass()
	return drawn()
}

beforeEach(async () => {
	vi.resetModules()
	sorting = await import('../../src/filesplugin/columns/Sorting.js')
	const ref = await import('../../src/filesplugin/columns/FilesListRef.js')
	const filter = await import('../../src/filesplugin/columns/MetadataFilter.js')
	// Starts held from an earlier filter() run, so a stale flag would show.
	fi = {
		sort: null,
		held: true,
		getSortState() { return this.sort },
		isHeld() { return this.held },
		setSortState: vi.fn(function (state) { this.sort = state }),
		reapply: vi.fn(() => sorting.ensureSortBypass()),
	}
	heldByNextFilter = false
	list = fakeFilesList()
	filter.getFilterInstance.mockReturnValue(fi)
	ref._findFilesList.mockReturnValue(list)
})

describe('sort bypass', () => {
	it('shows our order when the MetaVox sort was applied', () => {
		fi.sort = { fieldName: 'budget', direction: 'asc' }

		expect(shownOrder()).toEqual(OUR_ORDER)
	})

	it('hands the rows a plain copy, never the reactive filtered list itself', () => {
		fi.sort = { fieldName: 'budget', direction: 'asc' }
		// Like Vue 2 does with reactive data: an observer on the array.
		Object.defineProperty(OUR_ORDER, '__ob__', { value: {}, configurable: true })

		const shown = shownOrder()

		expect(shown).not.toBe(OUR_ORDER)
		expect(shown.__ob__).toBeUndefined()
		delete OUR_ORDER.__ob__
	})

	it('keeps the order of the Files app while values are still loading', () => {
		fi.sort = { fieldName: 'budget', direction: 'asc' }
		heldByNextFilter = true

		expect(shownOrder()).toBe(NC_ORDER)
	})

	it('keeps the order of the Files app without a MetaVox sort', () => {
		expect(shownOrder()).toBe(NC_ORDER)
	})
})

describe('sorting only when it is drawn', () => {
	it('marks the sorted list stale instead of sorting it at once', () => {
		fi.sort = { fieldName: 'budget', direction: 'asc' }

		sorting.ensureSortBypass()

		expect(sorted().dirty).toBe(true)
		expect(list.$forceUpdate).toHaveBeenCalledTimes(1)
		expect(list.ncSort).not.toHaveBeenCalled()
		expect('value' in sorted()).toBe(false)
	})

	it('leaves the Files app sorting alone without a MetaVox sort', () => {
		drawn()
		list.ncSort.mockClear()

		sorting.ensureSortBypass()
		sorting.ensureSortBypass()

		expect(sorted().dirty).toBe(false)
		expect(list.$forceUpdate).not.toHaveBeenCalled()
		expect(drawn()).toBe(NC_ORDER)
		expect(list.ncSort).not.toHaveBeenCalled()
	})

	it('brings the Files app order back once the MetaVox sort ends', () => {
		fi.sort = { fieldName: 'budget', direction: 'asc' }
		expect(shownOrder()).toEqual(OUR_ORDER)

		fi.sort = null
		expect(shownOrder()).toBe(NC_ORDER)
	})

	it('restores the Files app getter on leaving, and only redraws when our order was shown', () => {
		const original = list.ncSort
		drawn()
		sorting.ensureSortBypass()
		sorting.uninstallSortBypass()
		expect(sorted().getter).toBe(original)
		expect(sorted().dirty).toBe(false)

		sorting.ensureSortBypass()
		fi.sort = { fieldName: 'budget', direction: 'asc' }
		expect(shownOrder()).toEqual(OUR_ORDER)
		sorting.uninstallSortBypass()
		expect(sorted().dirty).toBe(true)
		expect(drawn()).toBe(NC_ORDER)
	})
})

describe('the loading sign', () => {
	const header = (field) => {
		const th = document.createElement('th')
		th.className = 'metavox-header'
		th.dataset.metavoxField = field
		th.innerHTML = '<span class="files-list__column-sort-button-icon"></span>'
		return document.body.appendChild(th)
	}
	const loading = (th) => th.classList.contains('metavox-sort-pending')

	it('moves with the sort, and goes when the values are in', () => {
		document.body.innerHTML = ''
		const budget = header('budget')
		const status = header('status')
		fi.sort = { fieldName: 'budget', direction: 'asc' }

		sorting.setSortPending(true)
		expect([loading(budget), loading(status)]).toEqual([true, false])

		fi.sort = { fieldName: 'status', direction: 'asc' }
		sorting.updateSortIndicators()
		expect([loading(budget), loading(status)]).toEqual([false, true])

		sorting.setSortPending(false)
		expect([loading(budget), loading(status)]).toEqual([false, false])
	})

	it('takes the arrow away when the sort ends, as a reset does', () => {
		document.body.innerHTML = ''
		const budget = header('budget')
		const arrow = () => budget.querySelector('.files-list__column-sort-button-icon').style.opacity
		fi.sort = { fieldName: 'budget', direction: 'asc' }
		sorting.updateSortIndicators()
		expect(arrow()).toBe('1')

		fi.sort = null
		sorting.setSortPending(false)
		expect(arrow()).toBe('0')
	})
})

describe('sorting on a column of the Files app', () => {
	it('ends the MetaVox sort, so the Files app order shows', () => {
		fi.sort = { fieldName: 'budget', direction: 'asc' }
		expect(shownOrder()).toEqual(OUR_ORDER)

		list.setNcSort('basename', true)

		expect(fi.setSortState).toHaveBeenCalledWith(null)
		expect(fi.reapply).toHaveBeenCalled()
		expect(drawn()).toBe(NC_ORDER)
	})

	it('does nothing without a MetaVox sort, or after leaving the folder', () => {
		sorting.ensureSortBypass()
		list.setNcSort('basename', true)
		expect(fi.setSortState).not.toHaveBeenCalled()

		fi.sort = { fieldName: 'budget', direction: 'asc' }
		sorting.uninstallSortBypass()
		list.setNcSort('size', false)
		expect(fi.setSortState).not.toHaveBeenCalled()
	})
})
