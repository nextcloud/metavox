import { afterEach, describe, expect, it, vi } from 'vitest'
import { DAV_PROP, decodeNode, registerMetaVoxDavProperty } from '../../src/filesplugin/columns/DavMetadata.js'

const node = (metavox) => ({ attributes: { metavox } })

describe('registerMetaVoxDavProperty', () => {
	afterEach(() => vi.unstubAllGlobals())

	it('adds the property to the v3 list (NC 31/32)', () => {
		const v3 = ['d:getetag']
		vi.stubGlobal('window', { _nc_dav_properties: v3 })

		expect(registerMetaVoxDavProperty()).toBe(true)
		expect(v3).toEqual(['d:getetag', DAV_PROP])
	})

	it('adds the property to the v4 list (NC 33+)', () => {
		const v4 = ['d:getetag']
		vi.stubGlobal('window', { _nc_files_scope: { v4_0: { davProperties: v4 } } })

		expect(registerMetaVoxDavProperty()).toBe(true)
		expect(v4).toEqual(['d:getetag', DAV_PROP])
	})

	it('adds the property to both lists when both exist', () => {
		const v3 = []
		const v4 = []
		vi.stubGlobal('window', { _nc_dav_properties: v3, _nc_files_scope: { v4_0: { davProperties: v4 } } })

		registerMetaVoxDavProperty()

		expect(v3).toEqual([DAV_PROP])
		expect(v4).toEqual([DAV_PROP])
	})

	it('never creates a list', () => {
		const win = { _nc_files_scope: { v4_0: {} } }
		vi.stubGlobal('window', win)

		expect(registerMetaVoxDavProperty()).toBe(false)
		expect(win).toEqual({ _nc_files_scope: { v4_0: {} } })
	})

	it('adds the property only once', () => {
		const v4 = []
		vi.stubGlobal('window', { _nc_files_scope: { v4_0: { davProperties: v4 } } })

		registerMetaVoxDavProperty()
		registerMetaVoxDavProperty()

		expect(v4).toEqual([DAV_PROP])
	})
})

describe('decodeNode', () => {
	it('reads team folder, edit right, values and cut-off fields', () => {
		const decoded = decodeNode(node('{"v":1,"g":12,"e":1,"f":{"status":"Draft","note":"Long…"},"t":["note"]}'))

		expect(decoded).toEqual({ gf: 12, canWrite: true, values: { status: 'Draft', note: 'Long…' }, truncated: ['note'], names: {} })
	})

	it('reads the names of the people in the values', () => {
		const decoded = decodeNode(node('{"v":1,"g":12,"f":{"owner":"anna;#group:staff"},"n":{"anna":"Anna de Vries","group:staff":"Staff"}}'))

		expect(decoded.names).toEqual({ anna: 'Anna de Vries', 'group:staff': 'Staff' })
		expect(decodeNode(node('{"v":1,"g":12,"n":["anna"]}')).names).toEqual({})
	})

	it('is read-only without e', () => {
		expect(decodeNode(node('{"v":1,"g":12,"f":{"status":"Draft"}}')).canWrite).toBe(false)
	})

	it('reads a folder or mount root without values', () => {
		expect(decodeNode(node('{"v":1,"g":12}'))).toEqual({ gf: 12, canWrite: false, values: {}, truncated: [], names: {} })
		expect(decodeNode(node('{"v":1}'))).toEqual({ gf: null, canWrite: false, values: {}, truncated: [], names: {} })
	})

	it('keeps values as strings, including a field named 0 and the value 0', () => {
		const decoded = decodeNode(node('{"v":1,"g":12,"f":{"0":"x","version":"2024.10","approved":"0"}}'))

		expect(decoded.values).toEqual({ 0: 'x', version: '2024.10', approved: '0' })
	})

	it('ignores values that are not non-empty strings', () => {
		expect(decodeNode(node('{"v":1,"g":12,"f":{"a":"","b":3,"c":null,"d":"ok"}}')).values).toEqual({ d: 'ok' })
	})

	it('ignores a cut-off field without a value', () => {
		expect(decodeNode(node('{"v":1,"g":12,"f":{"a":"x"},"t":["a","gone"]}')).truncated).toEqual(['a'])
	})

	it('is null when the property was not served or is not understood', () => {
		for (const metavox of [undefined, 42, true, '', 'text', ' {"v":1}', '{broken', '{"v":2,"g":12}', '{"g":12}', 'null']) {
			expect(decodeNode(node(metavox)), String(metavox)).toBeNull()
		}
		expect(decodeNode(null)).toBeNull()
		expect(decodeNode({})).toBeNull()
	})
})
