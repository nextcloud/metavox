import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/filesplugin/columns/ListingSync.js', () => ({ getListingNodes: vi.fn() }))
vi.mock('../../src/filesplugin/columns/MetadataLoader.js', () => ({ queueMetadataLoad: vi.fn() }))
vi.mock('../../src/filesplugin/columns/MetadataStore.js', () => ({ hasValues: vi.fn(), isTruncated: vi.fn() }))
vi.mock('../../src/filesplugin/columns/MetaVoxState.js', () => ({ metadataCache: new Map(), columnWidths: {} }))
vi.mock('../../src/filesplugin/columns/FilelinkNames.js', () => ({
	requestFilelinkName: vi.fn(),
	filelinkLabel: (token) => ({ label: { 12: 'Offer.pdf' }[token.fileId] ?? 'Unavailable file' }),
}))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))

let options
let listing
let loader
let store
let state
const status = { field_name: 'klant', field_type: 'text' }

beforeEach(async () => {
	vi.resetModules()
	listing = await import('../../src/filesplugin/columns/ListingSync.js')
	loader = await import('../../src/filesplugin/columns/MetadataLoader.js')
	store = await import('../../src/filesplugin/columns/MetadataStore.js')
	state = await import('../../src/filesplugin/columns/MetaVoxState.js')
	options = await import('../../src/filesplugin/columns/FilterOptions.js')
	state.metadataCache.clear()
	listing.getListingNodes.mockReset().mockReturnValue([{ fileid: 1 }, { fileid: 2 }])
	loader.queueMetadataLoad.mockReset().mockResolvedValue(undefined)
	store.hasValues.mockReset().mockReturnValue(true)
	store.isTruncated.mockReset().mockReturnValue(false)
})

describe('optionLabel', () => {
	it('reads a checkbox as yes or no', () => {
		expect(options.optionLabel('1', 'checkbox')).toBe('Yes')
		expect(options.optionLabel('0', 'checkbox')).toBe('No')
	})

	it('shows a linked file by name and asks for the name, never showing the id', async () => {
		const names = await import('../../src/filesplugin/columns/FilelinkNames.js')
		expect(options.optionLabel('12:', 'filelink')).toBe('Offer.pdf')
		expect(options.optionLabel('13:', 'filelink')).toBe('Unavailable file')
		expect(names.requestFilelinkName).toHaveBeenCalledWith(12)
	})

	it('leaves other values as they are', () => {
		expect(options.optionLabel('Acme', 'text')).toBe('Acme')
	})
})

describe('folderFilterValues', () => {
	it('takes the options from the listing, without a request', async () => {
		state.metadataCache.set(1, { klant: 'Beta' })
		state.metadataCache.set(2, { klant: 'Alpha' })

		expect(await options.folderFilterValues([status])).toEqual({ klant: ['Alpha', 'Beta'] })
		expect(loader.queueMetadataLoad).not.toHaveBeenCalled()
	})

	it('loads only the files whose values are missing', async () => {
		store.hasValues.mockImplementation((id) => id !== 2)
		loader.queueMetadataLoad.mockImplementation(async () => { state.metadataCache.set(2, { klant: 'Alpha' }) })
		state.metadataCache.set(1, { klant: 'Beta' })

		expect(await options.folderFilterValues([status])).toEqual({ klant: ['Alpha', 'Beta'] })
		expect(loader.queueMetadataLoad).toHaveBeenCalledWith([2])
	})

	it('does not load a cut-off value again: it counts as the grid shows it', async () => {
		store.isTruncated.mockImplementation((id) => id === 2)
		state.metadataCache.set(1, { klant: 'Beta' })
		state.metadataCache.set(2, { klant: 'Alpha, a long start' })

		expect(await options.folderFilterValues([status])).toEqual({ klant: ['Alpha, a long start', 'Beta'] })
		expect(loader.queueMetadataLoad).not.toHaveBeenCalled()
		expect([...options.folderCutOffValues([status]).klant]).toEqual(['Alpha, a long start'])
	})

	it('still gives the options it has when loading fails', async () => {
		store.hasValues.mockImplementation((id) => id !== 2)
		loader.queueMetadataLoad.mockRejectedValue(new Error('network'))
		state.metadataCache.set(1, { klant: 'Beta' })

		expect(await options.folderFilterValues([status])).toEqual({ klant: ['Beta'] })
	})
})
