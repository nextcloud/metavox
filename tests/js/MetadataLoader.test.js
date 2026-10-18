import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/filesplugin/columns/MetaVoxAPI.js', () => ({ fetchDirectoryMetadata: vi.fn() }))

const GF = 2
let api
let loader
let store
let state

// A fetch that answers when the test says so.
function deferredFetch() {
	const calls = []
	api.fetchDirectoryMetadata.mockImplementation((gf, ids) => new Promise((resolve) => {
		calls.push({ gf, ids, answer: (data = {}, okIds = ids) => resolve({ data, okIds }) })
	}))
	return calls
}

beforeEach(async () => {
	vi.resetModules()
	vi.useFakeTimers()
	api = await import('../../src/filesplugin/columns/MetaVoxAPI.js')
	api.fetchDirectoryMetadata.mockReset()
	state = await import('../../src/filesplugin/columns/MetaVoxState.js')
	store = await import('../../src/filesplugin/columns/MetadataStore.js')
	loader = await import('../../src/filesplugin/columns/MetadataLoader.js')
	state.setActiveGroupfolderId(GF)
	store.switchGroupfolder(GF)
})

afterEach(() => vi.useRealTimers())

describe('queueMetadataLoad', () => {
	it('bundles 100 calls within 200 ms into one request', async () => {
		const calls = deferredFetch()
		for (let id = 1; id <= 100; id++) {
			loader.queueMetadataLoad(id)
			await vi.advanceTimersByTimeAsync(1)
		}
		await vi.advanceTimersByTimeAsync(200)

		expect(calls).toHaveLength(1)
		expect(calls[0].ids).toHaveLength(100)
	})

	it('resolves only after the load is done', async () => {
		const calls = deferredFetch()
		let done = false
		loader.queueMetadataLoad([10, 11]).then(() => { done = true })

		await vi.advanceTimersByTimeAsync(60)
		expect(calls).toHaveLength(1)
		expect(done).toBe(false)

		calls[0].answer({ 10: { status: 'Draft' } })
		await vi.advanceTimersByTimeAsync(0)
		expect(done).toBe(true)
		expect(state.metadataCache.get(10)).toEqual({ status: 'Draft' })
	})
})

describe('loadViaApi', () => {
	it('waits for a request already running for the same file', async () => {
		const calls = deferredFetch()
		loader.loadViaApi([10])
		let done = false
		loader.loadViaApi([10]).then(() => { done = true })
		await vi.advanceTimersByTimeAsync(0)

		expect(calls).toHaveLength(1)
		expect(done).toBe(false)
		calls[0].answer()
		await vi.advanceTimersByTimeAsync(0)
		expect(done).toBe(true)
	})

	it('requests again with force', async () => {
		const calls = deferredFetch()
		loader.loadViaApi([10])
		loader.loadViaApi([10], { force: true })
		await vi.advanceTimersByTimeAsync(0)

		expect(calls).toHaveLength(2)
	})

	it('does nothing without an active team folder', async () => {
		state.setActiveGroupfolderId(null)

		expect(await loader.loadViaApi([10])).toEqual({ started: false })
		expect(api.fetchDirectoryMetadata).not.toHaveBeenCalled()
	})

	it('leaves the files of a failed chunk missing', async () => {
		api.fetchDirectoryMetadata.mockResolvedValue({ data: { 10: { status: 'Draft' } }, okIds: [10] })

		await loader.loadViaApi([10, 11])

		expect(state.metadataCache.has(10)).toBe(true)
		expect(state.metadataCache.has(11)).toBe(false)
	})

	it('loads big sets per chunk of 200, one after the other, showing each as it arrives', async () => {
		const calls = deferredFetch()
		const ids = Array.from({ length: 450 }, (_, i) => i + 1)
		loader.loadViaApi(ids)
		await vi.advanceTimersByTimeAsync(0)
		expect(calls.map((c) => c.ids.length)).toEqual([200])

		calls[0].answer({ 1: { status: 'Draft' } })
		await vi.advanceTimersByTimeAsync(0)
		expect(state.metadataCache.get(1)).toEqual({ status: 'Draft' })
		expect(state.metadataCache.has(201)).toBe(false)
		expect(calls.map((c) => c.ids.length)).toEqual([200, 200])

		calls[1].answer()
		await vi.advanceTimersByTimeAsync(0)
		calls[2].answer()
		await vi.advanceTimersByTimeAsync(0)
		expect(calls.map((c) => c.ids.length)).toEqual([200, 200, 50])
		expect(state.metadataCache.has(450)).toBe(true)
	})

	it('goes on with the next chunk after a failed one', async () => {
		api.fetchDirectoryMetadata
			.mockRejectedValueOnce(new Error('network'))
			.mockResolvedValueOnce({ data: {}, okIds: [201] })
		const ids = Array.from({ length: 201 }, (_, i) => i + 1)

		await loader.loadViaApi(ids)

		expect(state.metadataCache.has(1)).toBe(false)
		expect(state.metadataCache.has(201)).toBe(true)
	})

	it('redraws after a load', async () => {
		const afterLoad = vi.fn()
		loader.setAfterLoad(afterLoad)
		api.fetchDirectoryMetadata.mockResolvedValue({ data: {}, okIds: [10] })

		await loader.loadViaApi([10])

		expect(afterLoad).toHaveBeenCalledOnce()
	})
})

describe('requireFull', () => {
	function seedCutOff() {
		store.seedFromNode({ fileid: 10, attributes: { metavox: JSON.stringify({ v: 1, g: GF, f: { note: 'x'.repeat(512) }, t: ['note'] }) } }, GF)
	}

	it('is true right away when nothing is cut off', async () => {
		expect(await loader.requireFull([10], 'note')).toBe(true)
		expect(api.fetchDirectoryMetadata).not.toHaveBeenCalled()
	})

	it('is true once the full value is loaded', async () => {
		seedCutOff()
		api.fetchDirectoryMetadata.mockResolvedValue({ data: { 10: { note: 'x'.repeat(600) } }, okIds: [10] })

		expect(await loader.requireFull([10], 'note')).toBe(true)
		expect(state.metadataCache.get(10).note).toHaveLength(600)
	})

	it('is false when the load failed', async () => {
		seedCutOff()
		api.fetchDirectoryMetadata.mockResolvedValue({ data: {}, okIds: [] })

		expect(await loader.requireFull([10], 'note')).toBe(false)
	})

	it('is false when the answer is from before invalidateAll', async () => {
		seedCutOff()
		const calls = deferredFetch()
		const result = loader.requireFull([10], 'note')
		await vi.advanceTimersByTimeAsync(10)
		store.invalidateAll()

		calls[0].answer({ 10: { note: 'x'.repeat(600) } })

		expect(await result).toBe(false)
	})
})
