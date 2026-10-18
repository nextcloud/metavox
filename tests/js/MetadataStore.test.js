import { beforeEach, describe, expect, it, vi } from 'vitest'

const GF = 2
let store
let state
let time

// A listed node as the Files app gives it, with the nc:metavox property.
function node(fileid, doc) {
	return { fileid, attributes: { metavox: JSON.stringify({ v: 1, g: GF, ...doc }) } }
}

beforeEach(async () => {
	vi.resetModules()
	store = await import('../../src/filesplugin/columns/MetadataStore.js')
	state = await import('../../src/filesplugin/columns/MetaVoxState.js')
	time = 1000000
	store._setClock(() => time)
	store.switchGroupfolder(GF)
})

describe('seedFromNode', () => {
	it('stores values and the edit right from a listed node', () => {
		expect(store.seedFromNode(node(10, { e: 1, f: { status: 'Draft' } }), GF)).toBe('seeded')

		expect(state.metadataCache.get(10)).toEqual({ status: 'Draft' })
		expect(state.permissionCache.get(10)).toBe(true)
		expect(store.hasValues(10)).toBe(true)
		expect(store.isTruncated(10, 'status')).toBe(false)
	})

	it('stores a read-only node without values as an empty, complete entry', () => {
		store.seedFromNode(node(11, {}), GF)

		expect(state.metadataCache.get(11)).toEqual({})
		expect(state.permissionCache.get(11)).toBe(false)
	})

	it('applies the same node object only once', () => {
		const listed = node(10, { f: { status: 'Draft' } })
		store.seedFromNode(listed, GF)
		store.fillFromApi({ 10: { status: 'Final' } }, [10], store.now(), store.currentEpoch())

		expect(store.seedFromNode(listed, GF)).toBe('seen')
		expect(state.metadataCache.get(10)).toEqual({ status: 'Final' })
	})

	it('leaves nodes of another team folder for later, without marking them seen', () => {
		const listed = node(10, { g: 7, f: { status: 'Draft' } })

		expect(store.seedFromNode(listed, 7)).toBe('foreign')
		expect(state.metadataCache.has(10)).toBe(false)

		store.switchGroupfolder(7)
		expect(store.seedFromNode(listed, 7)).toBe('seeded')
	})

	it('treats a node without the property, or of another team folder, as absent', () => {
		expect(store.seedFromNode({ fileid: 10, attributes: {} }, GF)).toBe('absent')
		expect(store.seedFromNode(node(11, { g: 99 }), GF)).toBe('absent')
		expect(state.metadataCache.has(10)).toBe(false)
		expect(state.metadataCache.has(11)).toBe(false)
	})

	it('shows a nested mount root as an empty read-only row', () => {
		store.seedFromNode({ fileid: 12, attributes: { metavox: '{"v":1}' } }, GF)

		expect(state.metadataCache.get(12)).toEqual({})
		expect(state.permissionCache.get(12)).toBe(false)
	})
})

describe('switchGroupfolder', () => {
	it('is idempotent', () => {
		const epoch = store.currentEpoch()

		expect(store.switchGroupfolder(GF)).toBe(false)
		expect(store.currentEpoch()).toBe(epoch)
	})

	it('clears the caches but still never re-applies an old node object', () => {
		const listed = node(10, { f: { status: 'Draft' } })
		store.seedFromNode(listed, GF)

		store.switchGroupfolder(7)
		expect(state.metadataCache.size).toBe(0)
		store.switchGroupfolder(GF)

		expect(store.seedFromNode(listed, GF)).toBe('seen')
		expect(state.metadataCache.has(10)).toBe(false)
	})
})

describe('edits and pushes', () => {
	it('keep a recent edit over a node for PROTECT_MS, then the node wins', () => {
		store.seedFromNode(node(10, { f: { status: 'Draft' } }), GF)
		store.applyMutation(10, { status: 'Final' })

		expect(store.seedFromNode(node(10, { f: { status: 'Draft' } }), GF)).toBe('protected')
		expect(state.metadataCache.get(10)).toEqual({ status: 'Final' })

		time += store.PROTECT_MS + 1
		expect(store.seedFromNode(node(10, { f: { status: 'Approved' } }), GF)).toBe('seeded')
		expect(state.metadataCache.get(10)).toEqual({ status: 'Approved' })
	})

	it('keep a push that arrives before the file is cached, merged at the seed', () => {
		store.applyMutation(10, { status: 'Final' })
		expect(state.metadataCache.has(10)).toBe(false)

		store.seedFromNode(node(10, { f: { status: 'Draft', owner: 'alice' } }), GF)

		expect(state.metadataCache.get(10)).toEqual({ status: 'Final', owner: 'alice' })
	})

	it('only clear the cut-off flag of the edited field', () => {
		store.seedFromNode(node(10, { f: { a: 'short…', b: 'long…' }, t: ['a', 'b'] }), GF)

		store.applyMutation(10, { a: 'new' })

		expect(store.isTruncated(10, 'a')).toBe(false)
		expect(store.isTruncated(10, 'b')).toBe(true)
	})
})

describe('cut-off values', () => {
	it('keep a complete cached value that starts with the cut-off value', () => {
		const full = 'x'.repeat(600)
		store.fillFromApi({ 10: { note: full } }, [10], store.now(), store.currentEpoch())

		store.seedFromNode(node(10, { f: { note: full.slice(0, 512) }, t: ['note'] }), GF)

		expect(state.metadataCache.get(10).note).toBe(full)
		expect(store.isTruncated(10, 'note')).toBe(false)
	})

	it('take and flag the cut-off value when the cached one is different', () => {
		store.fillFromApi({ 10: { note: 'y'.repeat(600) } }, [10], store.now(), store.currentEpoch())

		store.seedFromNode(node(10, { f: { note: 'x'.repeat(512) }, t: ['note'] }), GF)

		expect(state.metadataCache.get(10).note).toBe('x'.repeat(512))
		expect(store.isTruncated(10, 'note')).toBe(true)
	})
})

describe('matchesStored', () => {
	beforeEach(() => {
		store.seedFromNode(node(10, { f: { status: 'Draft', budget: '5' } }), GF)
	})

	it('is true for the values the store holds, other fields left out', () => {
		expect(store.matchesStored(10, { status: 'Draft' })).toBe(true)
		expect(store.matchesStored(10, { status: 'Draft', budget: '5' })).toBe(true)
	})

	it('counts empty, null and a missing field alike, and 5 as "5"', () => {
		expect(store.matchesStored(10, { note: '', owner: null, budget: 5 })).toBe(true)
	})

	it('is false when a value differs, or the file is not held', () => {
		expect(store.matchesStored(10, { status: 'Final' })).toBe(false)
		expect(store.matchesStored(10, { status: 'Draft', budget: '6' })).toBe(false)
		expect(store.matchesStored(11, {})).toBe(false)
	})

	it('reads a cut-off value as the start of the whole one', () => {
		const full = 'x'.repeat(600)
		store.seedFromNode(node(12, { f: { note: full.slice(0, 512) }, t: ['note'] }), GF)

		expect(store.matchesStored(12, { note: full })).toBe(true)
		expect(store.matchesStored(12, { note: 'y' + full })).toBe(false)
	})
})

describe('fillFromApi', () => {
	it('stores values and the edit right from _permissions', () => {
		store.fillFromApi({ 10: { status: 'Draft', _permissions: 3 }, 11: { _permissions: 1 } }, [10, 11], store.now(), store.currentEpoch())

		expect(state.metadataCache.get(10)).toEqual({ status: 'Draft' })
		expect(state.permissionCache.get(10)).toBe(true)
		expect(state.metadataCache.get(11)).toEqual({})
		expect(state.permissionCache.get(11)).toBe(false)
	})

	it('only stores the ids of requests that succeeded', () => {
		store.fillFromApi({ 10: { status: 'Draft' }, 11: { status: 'Final' } }, [10], store.now(), store.currentEpoch())

		expect(state.metadataCache.has(10)).toBe(true)
		expect(state.metadataCache.has(11)).toBe(false)
	})

	it('only completes cut-off fields of a file changed during the request', () => {
		store.seedFromNode(node(10, { f: { status: 'Draft', note: 'x'.repeat(512) }, t: ['note'] }), GF)
		const startedAt = store.now()
		time += 10
		store.touch([10])

		store.fillFromApi({ 10: { status: 'Old', note: 'x'.repeat(600) } }, [10], startedAt, store.currentEpoch())

		expect(state.metadataCache.get(10)).toEqual({ status: 'Draft', note: 'x'.repeat(600) })
		expect(store.isTruncated(10, 'note')).toBe(false)
	})

	it('never changes the cached object in place', () => {
		store.seedFromNode(node(10, { f: { note: 'x'.repeat(512) }, t: ['note'] }), GF)
		const before = state.metadataCache.get(10)
		const startedAt = store.now()
		time += 10
		store.touch([10])

		store.fillFromApi({ 10: { note: 'x'.repeat(600) } }, [10], startedAt, store.currentEpoch())

		expect(before.note).toBe('x'.repeat(512))
	})

	it('ignores answers from before a team folder switch', () => {
		const epochAtStart = store.currentEpoch()
		store.switchGroupfolder(7)

		store.fillFromApi({ 10: { status: 'Draft' } }, [10], store.now(), epochAtStart)

		expect(state.metadataCache.has(10)).toBe(false)
	})

	it('ignores answers to requests started before invalidateAll', () => {
		const startedAt = store.now()
		time += 10
		store.invalidateAll()

		store.fillFromApi({ 10: { status: 'Draft' } }, [10], startedAt, store.currentEpoch())
		expect(state.metadataCache.has(10)).toBe(false)

		store.fillFromApi({ 10: { status: 'Draft' } }, [10], store.now(), store.currentEpoch())
		expect(state.metadataCache.has(10)).toBe(true)
	})
})
