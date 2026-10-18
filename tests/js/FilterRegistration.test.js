import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/filesplugin/columns/MetaVoxFilterElement.js', () => ({}))
vi.mock('../../src/filesplugin/columns/MetaVoxFilterPanel.vue', () => ({ default: {} }))
vi.mock('vue', () => ({ createApp: vi.fn(), h: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, translatePlural: (app, one) => one }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/files', () => ({ getFileListFilters: vi.fn(), registerFileListFilter: vi.fn(), unregisterFileListFilter: vi.fn() }))
vi.mock('../../src/filesplugin/columns/Sorting.js', () => ({ ensureSortBypass: vi.fn(() => true), reorderDomRows: vi.fn(), setSortPending: vi.fn() }))
vi.mock('../../src/filesplugin/columns/MetadataLoader.js', () => ({ queueMetadataLoad: vi.fn() }))
vi.mock('../../src/filesplugin/columns/ColumnDOM.js', () => ({ updateTableMinWidth: vi.fn() }))
globalThis.requestAnimationFrame ??= (fn) => fn()
vi.mock('../../src/filesplugin/columns/FilterOptions.js', () => ({ optionLabel: (value) => value }))
vi.mock('../../src/filesplugin/columns/ListingSync.js', () => ({ getListingNodes: vi.fn(() => []), isListingLoading: vi.fn(() => false), listingGeneration: vi.fn(() => 1) }))

const filterable = [{ field_name: 'status', filterable: true }]
let filter
let files
let bus
let v4

const fakeElement = () => ({ style: {}, addEventListener: vi.fn(), appendChild: vi.fn(), replaceChildren: vi.fn() })

beforeEach(async () => {
	vi.resetModules()
	vi.stubGlobal('window', { location: { search: '', pathname: '/apps/files', hash: '' } })
	vi.stubGlobal('document', { createElement: fakeElement, querySelector: () => null, querySelectorAll: () => [] })
	vi.stubGlobal('history', { replaceState: vi.fn() })
	files = await import('@nextcloud/files')
	bus = await import('@nextcloud/event-bus')
	// A small stand-in for the @nextcloud/files 4 registry.
	v4 = new Map()
	files.getFileListFilters.mockReset().mockImplementation(() => [...v4.values()])
	files.registerFileListFilter.mockReset().mockImplementation((f) => v4.set(f.id, f))
	files.unregisterFileListFilter.mockReset().mockImplementation((id) => v4.delete(id))
	bus.emit.mockReset()
	filter = await import('../../src/filesplugin/columns/MetadataFilter.js')
})

afterEach(() => vi.unstubAllGlobals())

describe('registering the MetaVox filter', () => {
	it('registers at both official entry points', () => {
		filter.registerMetaVoxFilter(filterable, 2, new Map())
		const instance = filter.getFilterInstance()

		// NC33+: @nextcloud/files 4
		expect(files.registerFileListFilter).toHaveBeenCalledWith(instance)
		// NC31/32: the map and event of @nextcloud/files 3
		expect(window._nc_filelist_filters.get('metavox-metadata')).toBe(instance)
		expect(bus.emit).toHaveBeenCalledWith('files:filter:added', instance)
	})

	it('registers once, also when a view updates the columns', () => {
		filter.registerMetaVoxFilter(filterable, 2, new Map())
		filter.registerMetaVoxFilter([{ field_name: 'budget', filterable: true }], 2, new Map())

		expect(files.registerFileListFilter).toHaveBeenCalledOnce()
		expect(bus.emit.mock.calls.filter(([name]) => name === 'files:filter:added')).toHaveLength(1)
		expect(filter.getFilterInstance()._columnConfigs).toEqual([{ field_name: 'budget', filterable: true }])
	})

	it('never registers twice when the filter is already known', () => {
		filter.registerMetaVoxFilter([], 2, new Map())
		const instance = filter.getFilterInstance()
		v4.set(instance.id, instance)
		window._nc_filelist_filters = new Map([[instance.id, instance]])

		filter.registerMetaVoxFilter(filterable, 2, new Map())

		expect(files.registerFileListFilter).not.toHaveBeenCalled()
		expect(bus.emit).not.toHaveBeenCalledWith('files:filter:added', expect.anything())
	})

	it('registers nothing without filterable fields', () => {
		filter.registerMetaVoxFilter([{ field_name: 'status', filterable: false }], 2, new Map())

		expect(files.registerFileListFilter).not.toHaveBeenCalled()
		expect(window._nc_filelist_filters).toBeUndefined()
	})

	it('clears its chips first, then unregisters at both entry points', () => {
		filter.registerMetaVoxFilter(filterable, 2, new Map())
		const instance = filter.getFilterInstance()
		const order = []
		instance.addEventListener('update:chips', () => order.push('chips'))
		files.unregisterFileListFilter.mockImplementation((id) => { order.push('unregister'); v4.delete(id) })

		filter.removeFilters()

		expect(order).toEqual(['chips', 'unregister'])
		expect(files.unregisterFileListFilter).toHaveBeenCalledWith('metavox-metadata')
		expect(window._nc_filelist_filters.has('metavox-metadata')).toBe(false)
		expect(bus.emit).toHaveBeenCalledWith('files:filter:removed', 'metavox-metadata')
		expect(filter.getFilterInstance()).toBeNull()
	})

	it('also drops a filter that was never registered, with its values', () => {
		filter.registerMetaVoxFilter([{ field_name: 'status', filterable: false }], 2, new Map())
		const instance = filter.getFilterInstance()
		instance._activeFilters.set('status', new Set(['Draft']))

		filter.removeFilters()

		expect(filter.getFilterInstance()).toBeNull()
		expect(instance.getActiveFilters().size).toBe(0)
		expect(files.unregisterFileListFilter).not.toHaveBeenCalled()
	})
})

describe('the filter button on NC31/32', () => {
	it('comes after Nextcloud\'s own filters, so its slot is added at the end', () => {
		filter.registerMetaVoxFilter(filterable, 2, new Map())

		// NC's highest is "Search everywhere" (999); inserting before it fails on NC31/32.
		expect(filter.getFilterInstance().order).toBeGreaterThan(999)
	})

	it('mounts the button into the slot Nextcloud gives, once per slot', () => {
		filter.registerMetaVoxFilter(filterable, 2, new Map())
		const instance = filter.getFilterInstance()
		const slot = fakeElement()
		const newSlot = fakeElement()

		instance.mount(slot)
		instance.mount(slot)
		instance.mount(newSlot)

		expect(slot.replaceChildren).toHaveBeenCalledOnce()
		expect(slot.replaceChildren.mock.calls[0][0].className).toBe('metavox-filter-btn')
		expect(newSlot.replaceChildren).toHaveBeenCalledOnce()
	})
})
