import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/event-bus', () => ({ subscribe: vi.fn() }))
vi.mock('../../src/filesplugin/columns/FilesListRef.js', () => ({ _findFilesList: vi.fn(() => null) }))

const GF = 2
let sync
let store
let state
let filesListRef
let emit // the 'files:list:updated' handler ListingSync subscribed

const node = (fileid, doc) => ({ fileid, attributes: { metavox: JSON.stringify({ v: 1, g: GF, ...doc }) } })
const folder = (doc = {}) => ({ path: '/Team/Sub', attributes: { metavox: JSON.stringify({ v: 1, g: GF, ...doc }) } })

beforeEach(async () => {
	vi.resetModules()
	vi.stubGlobal('window', {})
	const bus = await import('@nextcloud/event-bus')
	bus.subscribe.mockReset()
	filesListRef = await import('../../src/filesplugin/columns/FilesListRef.js')
	filesListRef._findFilesList.mockReset().mockReturnValue(null)
	state = await import('../../src/filesplugin/columns/MetaVoxState.js')
	store = await import('../../src/filesplugin/columns/MetadataStore.js')
	sync = await import('../../src/filesplugin/columns/ListingSync.js')
	store.switchGroupfolder(GF)
	sync.startListingSync()
	expect(bus.subscribe).toHaveBeenCalledWith('files:list:updated', expect.any(Function))
	emit = bus.subscribe.mock.calls[0][1]
})

afterEach(() => vi.unstubAllGlobals())

describe('ListingSync', () => {
	it('seeds every listed node before calling the handler', () => {
		const seenByHandler = []
		sync.setListingHandler(() => seenByHandler.push(state.metadataCache.get(10), state.metadataCache.get(11)))

		emit({ folder: folder(), contents: [node(10, { e: 1, f: { status: 'Draft' } }), node(11, {})] })

		expect(seenByHandler).toEqual([{ status: 'Draft' }, {}])
		expect(sync.getListingIds()).toEqual([10, 11])
		expect(sync.getListingNode(10).fileid).toBe(10)
	})

	it('passes the team folder and loading state to the handler', () => {
		const handler = vi.fn()
		sync.setListingHandler(handler)
		filesListRef._findFilesList.mockReturnValue({ loading: true })

		emit({ folder: folder(), contents: [] })

		expect(handler).toHaveBeenCalledWith({ gf: GF, loading: true })
		expect(sync.getListingGroupfolderId()).toBe(GF)
		expect(sync.getListingFolderPath()).toBe('/Team/Sub')
	})

	it('reports no team folder for a listing outside one', () => {
		const handler = vi.fn()
		sync.setListingHandler(handler)

		emit({ folder: { path: '/Personal', attributes: {} }, contents: [node(10, {})] })

		expect(handler).toHaveBeenCalledWith({ gf: null, loading: false })
		expect(state.metadataCache.has(10)).toBe(false)
	})

	it('counts listings', () => {
		const before = sync.listingGeneration()
		emit({ folder: folder(), contents: [] })
		emit({ folder: folder(), contents: [] })

		expect(sync.listingGeneration()).toBe(before + 2)
	})

	it('seeds a listing of a not yet active team folder once it is active', () => {
		store.switchGroupfolder(null)
		emit({ folder: folder(), contents: [node(10, { f: { status: 'Draft' } })] })
		expect(state.metadataCache.has(10)).toBe(false)

		store.switchGroupfolder(GF)
		sync.reseedListing()

		expect(state.metadataCache.get(10)).toEqual({ status: 'Draft' })
	})

	it('takes over a listing that came before it subscribed', () => {
		sync.catchUp({ currentFolder: folder(), dirContents: [node(10, { f: { status: 'Draft' } })] })

		expect(state.metadataCache.get(10)).toEqual({ status: 'Draft' })
		expect(sync.getListingIds()).toEqual([10])
	})

	it('registers the property again on each listing', () => {
		const list = []
		window._nc_files_scope = { v4_0: { davProperties: list } }

		emit({ folder: folder(), contents: [] })

		expect(list).toEqual(['nc:metavox'])
	})

	it('never lets a handler error reach the Files app', () => {
		sync.setListingHandler(() => { throw new Error('boom') })

		expect(() => emit({ folder: folder(), contents: [node(10, {})] })).not.toThrow()
	})
})

describe('refreshListing', () => {
	it('lets the Files app load the folder again', () => {
		const fetchContent = vi.fn()
		filesListRef._findFilesList.mockReturnValue({ fetchContent })
		emit({ folder: folder(), contents: [] })

		expect(sync.refreshListing()).toBe(true)
		expect(fetchContent).toHaveBeenCalledOnce()
	})

	it('does nothing without the Files list or outside a team folder', () => {
		emit({ folder: folder(), contents: [] })
		expect(sync.refreshListing()).toBe(false)

		const fetchContent = vi.fn()
		filesListRef._findFilesList.mockReturnValue({ fetchContent })
		emit({ folder: { path: '/Personal', attributes: {} }, contents: [] })
		expect(sync.refreshListing()).toBe(false)
		expect(fetchContent).not.toHaveBeenCalled()
	})
})
