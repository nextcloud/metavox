import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/filesplugin/columns/MetaVoxFilterElement.js', () => ({}))
vi.mock('../../src/filesplugin/columns/MetaVoxFilterPanel.vue', () => ({ default: {} }))
vi.mock('vue', () => ({ createApp: vi.fn(), h: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, translatePlural: (app, one) => one }))
vi.mock('../../src/utils/toast.js', () => ({ showError: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/files', () => ({ getFileListFilters: () => [], registerFileListFilter: vi.fn(), unregisterFileListFilter: vi.fn() }))
vi.mock('../../src/filesplugin/columns/Sorting.js', () => ({ ensureSortBypass: vi.fn(() => true), reorderDomRows: vi.fn(), setSortPending: vi.fn() }))
vi.mock('../../src/filesplugin/columns/MetadataLoader.js', () => ({ queueMetadataLoad: vi.fn() }))
vi.mock('../../src/filesplugin/columns/ColumnDOM.js', () => ({ updateTableMinWidth: vi.fn() }))
globalThis.requestAnimationFrame ??= (fn) => fn()
vi.mock('../../src/filesplugin/columns/FilterOptions.js', () => ({ optionLabel: (value) => value }))
vi.mock('../../src/filesplugin/columns/ListingSync.js', () => ({
	getListingNodes: vi.fn(() => []),
	isListingLoading: vi.fn(() => false),
	listingGeneration: vi.fn(() => 1),
}))

const GF = 2
let fi
let emits
let loader
let listing
let dialogs
let store
let state
let sorting
let filters

const node = (fileid, fields, truncated) => ({ fileid, attributes: { metavox: JSON.stringify({ v: 1, g: GF, f: fields, ...(truncated ? { t: truncated } : {}) }) } })
const ids = (nodes) => nodes.map((n) => n.fileid)
const settle = async () => { for (let i = 0; i < 5; i++) await Promise.resolve() }

beforeEach(async () => {
	vi.resetModules()
	vi.useFakeTimers()
	vi.stubGlobal('document', { querySelector: () => null, querySelectorAll: () => [] })
	loader = await import('../../src/filesplugin/columns/MetadataLoader.js')
	listing = await import('../../src/filesplugin/columns/ListingSync.js')
	dialogs = await import('../../src/utils/toast.js')
	loader.queueMetadataLoad.mockReset()
	listing.isListingLoading.mockReset().mockReturnValue(false)
	listing.listingGeneration.mockReset().mockReturnValue(1)
	dialogs.showError.mockReset()
	sorting = await import('../../src/filesplugin/columns/Sorting.js')
	sorting.ensureSortBypass.mockReset().mockReturnValue(true)
	sorting.reorderDomRows.mockReset()
	sorting.setSortPending.mockReset()
	state = await import('../../src/filesplugin/columns/MetaVoxState.js')
	store = await import('../../src/filesplugin/columns/MetadataStore.js')
	filters = await import('../../src/filesplugin/columns/MetadataFilter.js')
	state.setActiveGroupfolderId(GF)
	store.switchGroupfolder(GF)
	filters.registerMetaVoxFilter([], GF, state.metadataCache)
	fi = filters.getFilterInstance()
	emits = vi.spyOn(fi, '_emitFilterUpdate').mockImplementation(() => {})
})

afterEach(() => {
	vi.useRealTimers()
	vi.unstubAllGlobals()
})

describe('filtering and sorting through the gate', () => {
	it('sorts and filters complete data', () => {
		const nodes = [node(1, { status: 'Draft', budget: '100' }), node(2, { status: 'Final', budget: '300' }), node(3, { status: 'Draft', budget: '200' })]
		nodes.forEach((n) => store.seedFromNode(n, GF))
		fi.toggleFilterValue('status', 'Draft')
		fi.setSortState({ fieldName: 'budget', fieldType: 'number', direction: 'desc' })

		expect(ids(fi.filter(nodes))).toEqual([3, 1])
		expect(fi.isHeld()).toBe(false)
		expect(loader.queueMetadataLoad).not.toHaveBeenCalled()
	})

	it('changes nothing and loads once while values are missing, without re-emitting right away', async () => {
		let finish
		loader.queueMetadataLoad.mockImplementation(() => new Promise((resolve) => { finish = resolve }))
		const nodes = [node(1, { budget: '100' }), node(2, { budget: '300' })]
		store.seedFromNode(nodes[0], GF) // file 2 is not cached
		fi.setSortState({ fieldName: 'budget', fieldType: 'number', direction: 'desc' })
		emits.mockClear()

		expect(fi.filter(nodes)).toBe(nodes)
		expect(fi.filter(nodes)).toBe(nodes)
		expect(fi.isHeld()).toBe(true)
		await settle()

		expect(loader.queueMetadataLoad).toHaveBeenCalledOnce()
		expect(loader.queueMetadataLoad).toHaveBeenCalledWith([2])
		expect(emits).not.toHaveBeenCalled()
		finish()
	})

	it('re-applies exactly once, after a timer, when the load made progress', async () => {
		const nodes = [node(1, { budget: '100' }), node(2, { budget: '300' })]
		store.seedFromNode(nodes[0], GF)
		loader.queueMetadataLoad.mockImplementation(async () => { store.seedFromNode(nodes[1], GF) })
		fi.setSortState({ fieldName: 'budget', fieldType: 'number', direction: 'asc' })
		emits.mockClear()

		fi.filter(nodes)
		await settle()
		expect(emits).not.toHaveBeenCalled()

		vi.runAllTimers()
		expect(emits).toHaveBeenCalledOnce()
		expect(ids(fi.filter(nodes))).toEqual([1, 2])
	})

	it('pauses with one message until the next listing when a load makes no progress', async () => {
		const nodes = [node(1, { budget: '100' }), node(2, { budget: '300' })]
		store.seedFromNode(nodes[0], GF)
		loader.queueMetadataLoad.mockResolvedValue(undefined)
		fi.setSortState({ fieldName: 'budget', fieldType: 'number', direction: 'asc' })

		fi.filter(nodes)
		await settle()
		fi.filter(nodes)
		await settle()
		vi.runAllTimers()

		expect(loader.queueMetadataLoad).toHaveBeenCalledOnce()
		expect(dialogs.showError).toHaveBeenCalledOnce()

		listing.listingGeneration.mockReturnValue(2)
		fi.filter(nodes)
		expect(loader.queueMetadataLoad).toHaveBeenCalledTimes(2)
	})

	it('sorts a cut-off field on the value the grid shows, without loading', () => {
		const nodes = [node(1, { note: 'y'.repeat(512) }, ['note']), node(2, { note: 'x' })]
		nodes.forEach((n) => store.seedFromNode(n, GF))
		fi.setSortState({ fieldName: 'note', fieldType: 'text', direction: 'asc' })

		expect(fi.filter(nodes).map((n) => n.fileid)).toEqual([2, 1])
		expect(loader.queueMetadataLoad).not.toHaveBeenCalled()
	})

	it('filters a cut-off value on what the grid shows', () => {
		const long = 'z'.repeat(512)
		const nodes = [node(1, { note: long }, ['note']), node(2, { note: 'x' })]
		nodes.forEach((n) => store.seedFromNode(n, GF))
		fi.toggleFilterValue('note', long)

		expect(fi.filter(nodes).map((n) => n.fileid)).toEqual([1])
		expect(loader.queueMetadataLoad).not.toHaveBeenCalled()
	})

	it('leaves the rows alone without the sort bypass until the values are there', () => {
		const nodes = [node(1, { budget: '100' }), node(2, { budget: '300' })]
		store.seedFromNode(nodes[0], GF)
		listing.getListingNodes.mockReturnValue(nodes)
		sorting.ensureSortBypass.mockReturnValue(false)
		loader.queueMetadataLoad.mockReturnValue(new Promise(() => {}))
		fi.setSortState({ fieldName: 'budget', fieldType: 'number', direction: 'desc' })

		fi.reapply()
		expect(sorting.reorderDomRows).not.toHaveBeenCalled()

		store.seedFromNode(nodes[1], GF)
		fi.reapply()
		expect(sorting.reorderDomRows).toHaveBeenCalledWith([2, 1])
	})

	it('loads nothing without an active team folder or while the listing loads', async () => {
		const nodes = [node(2, { budget: '300' })]
		fi.setSortState({ fieldName: 'budget', fieldType: 'number', direction: 'asc' })

		state.setActiveGroupfolderId(null)
		expect(fi.filter(nodes)).toBe(nodes)
		state.setActiveGroupfolderId(GF)
		listing.isListingLoading.mockReturnValue(true)
		expect(fi.filter(nodes)).toBe(nodes)
		await settle()
		vi.runAllTimers()

		expect(loader.queueMetadataLoad).not.toHaveBeenCalled()
		expect(emits).not.toHaveBeenCalled()
		expect(dialogs.showError).not.toHaveBeenCalled()
	})
})

describe('telling the Files app', () => {
	const nodes = [node(1, { status: 'Draft' }), node(2, { status: 'Final' })]
	let url
	beforeEach(() => {
		nodes.forEach((n) => store.seedFromNode(n, GF))
		url = vi.spyOn(fi, '_syncToUrl').mockImplementation(() => {})
	})

	it('says nothing when nothing filters or sorts, before or now', () => {
		fi.reapply()

		expect(emits).not.toHaveBeenCalled()
		// The URL still follows.
		expect(url).toHaveBeenCalled()
	})

	it('says it whenever a filter or a sort is on', () => {
		fi.setSortState({ fieldName: 'status', fieldType: 'text', direction: 'asc' })
		fi.reapply()
		expect(emits).toHaveBeenCalledTimes(1)

		fi.setSortState(null)
		fi._activeFilters.set('status', new Set(['Draft']))
		fi.reapply()
		expect(emits).toHaveBeenCalledTimes(2)
	})

	it('says it once more when filtering stops, so every row comes back', () => {
		fi._activeFilters.set('status', new Set(['Draft']))
		expect(ids(fi.filter(nodes))).toEqual([1]) // the Files app ran the filter

		fi._activeFilters.clear()
		fi.reapply()
		expect(emits).toHaveBeenCalledTimes(1)

		expect(ids(fi.filter(nodes))).toEqual([1, 2])
		fi.reapply()
		expect(emits).toHaveBeenCalledTimes(1)
	})

	it('says it after a run that held the rows back for missing values', () => {
		fi.setSortState({ fieldName: 'budget', fieldType: 'number', direction: 'asc' })
		loader.queueMetadataLoad.mockImplementation(() => new Promise(() => {}))
		fi.filter([...nodes, node(3, { budget: '5' })]) // file 3 is not cached: held

		fi.setSortState(null)
		fi.reapply()

		expect(emits).toHaveBeenCalledTimes(1)
	})
})

describe('a filter that was replaced while it loaded', () => {
	const nodes = [node(1, { budget: '100' }), node(2, { budget: '300' })]

	it.each([
		['the load made progress', true],
		['the load failed', false],
	])('leaves the new folder alone when %s', async (_, progress) => {
		let finish
		loader.queueMetadataLoad.mockImplementation(() => new Promise((resolve) => { finish = resolve }))
		// NC32: without the sort bypass a reapply moves the rows in the DOM.
		sorting.ensureSortBypass.mockReturnValue(false)
		store.seedFromNode(nodes[0], GF)
		fi.setSortState({ fieldName: 'budget', fieldType: 'number', direction: 'asc' })
		fi.filter(nodes)
		await settle()

		// Another folder: the old filter goes, a new one comes.
		vi.stubGlobal('window', { location: { search: '', pathname: '/apps/files', hash: '' } })
		const configs = [{ field_name: 'budget', filterable: true }]
		filters.registerMetaVoxFilter(configs, GF, state.metadataCache)
		filters.removeFilters()
		filters.registerMetaVoxFilter(configs, GF, state.metadataCache)
		sorting.setSortPending.mockClear()
		sorting.reorderDomRows.mockClear()
		emits.mockClear()

		if (progress) store.seedFromNode(nodes[1], GF)
		finish()
		await settle()
		vi.runAllTimers()

		expect(sorting.setSortPending).not.toHaveBeenCalled()
		expect(dialogs.showError).not.toHaveBeenCalled()
		expect(emits).not.toHaveBeenCalled()
		expect(sorting.reorderDomRows).not.toHaveBeenCalled()
	})
})

describe('a reset while values load', () => {
	it('ends the load: no message, and the next sort loads again, with its sign', async () => {
		const resolvers = []
		loader.queueMetadataLoad.mockImplementation(() => new Promise((resolve) => resolvers.push(resolve)))
		const nodes = [node(1, { budget: '100' }), node(2, { budget: '300' })]
		store.seedFromNode(nodes[0], GF)
		fi.setSortState({ fieldName: 'budget', fieldType: 'number', direction: 'asc' })
		fi.filter(nodes)
		await settle()

		// "Default", then a sorted view while the first load still runs.
		fi.reset()
		fi.setSortState({ fieldName: 'budget', fieldType: 'number', direction: 'desc' })
		sorting.setSortPending.mockClear()
		fi.filter(nodes)
		expect(loader.queueMetadataLoad).toHaveBeenCalledTimes(2)
		expect(sorting.setSortPending).toHaveBeenCalledWith(true)

		// The first load ends with nothing new: it was ended, so it says nothing.
		sorting.setSortPending.mockClear()
		emits.mockClear()
		resolvers[0]()
		await settle()
		vi.runAllTimers()
		expect(dialogs.showError).not.toHaveBeenCalled()
		expect(sorting.setSortPending).not.toHaveBeenCalled()
		expect(emits).not.toHaveBeenCalled()
		expect(fi.isHeld()).toBe(true)
	})

	it('lifts the pause of a failed load, so the next sort loads again', async () => {
		const nodes = [node(1, { budget: '100' }), node(2, { budget: '300' })]
		store.seedFromNode(nodes[0], GF)
		loader.queueMetadataLoad.mockResolvedValue(undefined)
		const sort = { fieldName: 'budget', fieldType: 'number', direction: 'asc' }
		fi.setSortState(sort)
		fi.filter(nodes)
		await settle()
		fi.filter(nodes)
		expect(loader.queueMetadataLoad).toHaveBeenCalledOnce()

		fi.reset()
		fi.setSortState(sort)
		fi.filter(nodes)

		expect(loader.queueMetadataLoad).toHaveBeenCalledTimes(2)
	})
})

describe('a link with filters', () => {
	it('finds the field by the short name in the link, also when no column shows it', () => {
		state.setAvailableFields([{ id: 5, field_name: 'file_gf_status', field_label: 'Status', field_type: 'select' }])

		fi.loadFromEncoded('status:Open', [])

		expect([...fi.getActiveFilters().keys()]).toEqual(['file_gf_status'])
	})
})

describe('clearing', () => {
	const sort = { fieldName: 'budget', fieldType: 'number', direction: 'asc' }

	it('"Clear all" clears the filters and keeps the sort', () => {
		let chips = []
		fi.addEventListener('update:chips', (e) => { chips = e.detail })
		fi.toggleFilterValue('status', 'Draft')
		fi.toggleFilterValue('budget', '100')
		fi.setSortState(sort)
		emits.mockClear()

		chips.at(-1).onclick()

		expect(fi.getActiveFilters().size).toBe(0)
		expect(chips).toEqual([])
		expect(fi.getSortState()).toBe(sort)
		expect(emits).toHaveBeenCalledOnce()
	})

	it('a reset ends the sort, with its arrow and loading sign', () => {
		fi.setSortState(sort)

		fi.reset()

		expect(fi.getSortState()).toBeNull()
		// Which redraws the arrows as well.
		expect(sorting.setSortPending).toHaveBeenCalledWith(false)
	})
})
