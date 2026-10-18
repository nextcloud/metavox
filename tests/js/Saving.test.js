import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path, params = {}) => path.replace(/{(\w+)}/g, (_, k) => params[k]) }))
vi.mock('@nextcloud/event-bus', () => ({ subscribe: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => ({ showUndo: vi.fn(() => ({ hideToast: vi.fn() })) }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, k) => vars[k]) }))

const GF = 2
let axios
let api
let undo
let store
let state

// A listed node, as the Files app would deliver it.
const node = (fileid, fields) => ({ fileid, attributes: { metavox: JSON.stringify({ v: 1, g: GF, e: 1, f: fields }) } })

beforeEach(async () => {
	vi.resetModules()
	axios = (await import('@nextcloud/axios')).default
	axios.post.mockReset()
	state = await import('../../src/filesplugin/columns/MetaVoxState.js')
	store = await import('../../src/filesplugin/columns/MetadataStore.js')
	api = await import('../../src/filesplugin/columns/MetaVoxAPI.js')
	undo = await import('../../src/filesplugin/columns/UndoSupport.js')
	state.setActiveGroupfolderId(GF)
	store.switchGroupfolder(GF)
	store.seedFromNode(node(10, { status: 'Draft', owner: 'alice' }), GF)
	store.seedFromNode(node(11, { status: 'Draft' }), GF)
	vi.spyOn(console, 'error').mockImplementation(() => {})
	globalThis.window = { OCP: { Toast: { error: vi.fn() } } }
})

afterEach(() => {
	delete globalThis.window
})

// What the server answers for a value it does not take.
const refused = (field, message) => Object.assign(new Error('400'), {
	response: { status: 400, data: { error: 'Validation failed', reason: 'invalid_input', fields: { [field]: message } } },
})

describe('saveSingleField', () => {
	it('writes through the store and keeps the edit over an older listing', async () => {
		axios.post.mockResolvedValue({})
		const before = state.metadataCache.get(10)

		await api.saveSingleField(10, 'status', 'Final', { skipUndo: true })

		expect(state.metadataCache.get(10)).toEqual({ status: 'Final', owner: 'alice' })
		expect(before).toEqual({ status: 'Draft', owner: 'alice' })
		expect(store.seedFromNode(node(10, { status: 'Draft', owner: 'alice' }), GF)).toBe('protected')
		expect(state.metadataCache.get(10).status).toBe('Final')
	})

	it('puts the old value back when saving fails', async () => {
		axios.post.mockRejectedValue(new Error('offline'))

		await api.saveSingleField(10, 'status', 'Final')

		expect(state.metadataCache.get(10).status).toBe('Draft')
		expect(window.OCP.Toast.error).toHaveBeenCalledWith('status not saved: Could not save metadata. Try again.', undefined)
	})

	it('says why the server refused the value, under the column name', async () => {
		state.setActiveColumnConfigs([{ field_name: 'status', field_label: 'Status' }])
		axios.post.mockRejectedValue(refused('status', 'This field is required'))

		await api.saveSingleField(10, 'status', '')

		expect(state.metadataCache.get(10).status).toBe('Draft')
		expect(window.OCP.Toast.error).toHaveBeenCalledWith('Status not saved: This field is required', undefined)
	})

	it('does not save an unchanged value', async () => {
		await api.saveSingleField(10, 'status', 'Draft')
		expect(axios.post).not.toHaveBeenCalled()
	})
})

describe('saveBulkFields', () => {
	it('writes every file through the store', async () => {
		axios.post.mockResolvedValue({})

		await api.saveBulkFields([10, 11], 'status', 'Final')

		expect(state.metadataCache.get(10).status).toBe('Final')
		expect(state.metadataCache.get(11).status).toBe('Final')
		expect(axios.post).toHaveBeenCalledOnce()
	})

	it('puts the old values back when saving fails', async () => {
		axios.post.mockRejectedValue(new Error('offline'))

		await api.saveBulkFields([10, 11], 'status', 'Final')

		expect(state.metadataCache.get(10).status).toBe('Draft')
		expect(state.metadataCache.get(11).status).toBe('Draft')
		expect(window.OCP.Toast.error).toHaveBeenCalledOnce()
	})
})

describe('revertField', () => {
	it('restores the old value through the store', async () => {
		axios.post.mockResolvedValue({})
		store.applyMutation(10, { status: 'Final' })

		await undo.revertField(10, 'status', 'Draft')

		expect(state.metadataCache.get(10)).toEqual({ status: 'Draft', owner: 'alice' })
		expect(axios.post).toHaveBeenCalledWith('/apps/metavox/api/files/10/metadata', { metadata: { status: 'Draft' } })
	})

	it('empties a field that had no value before', async () => {
		axios.post.mockResolvedValue({})

		await undo.revertField(11, 'owner', undefined)

		expect(axios.post).toHaveBeenCalledWith('/apps/metavox/api/files/11/metadata', { metadata: { owner: '' } })
	})

	it('shows the edit again when undoing fails', async () => {
		axios.post.mockRejectedValue(new Error('offline'))
		store.applyMutation(10, { status: 'Final' })

		await undo.revertField(10, 'status', 'Draft')

		expect(state.metadataCache.get(10).status).toBe('Final')
	})
})
