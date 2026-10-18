import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path, params = {}) => path.replace(/{(\w+)}/g, (m, key) => params[key]) }))
vi.mock('../../src/filesplugin/columns/UndoSupport.js', () => ({ pushUndo: vi.fn() }))
vi.mock('../../src/filesplugin/columns/ListingSync.js', () => ({ getListingFolderPath: vi.fn(), getListingGroupfolderId: vi.fn() }))

let api
let axios
let store
let state

beforeEach(async () => {
	vi.resetModules()
	axios = (await import('@nextcloud/axios')).default
	axios.get.mockReset()
	axios.post.mockReset()
	axios.post.mockResolvedValue({})
	state = await import('../../src/filesplugin/columns/MetaVoxState.js')
	store = await import('../../src/filesplugin/columns/MetadataStore.js')
	api = await import('../../src/filesplugin/columns/MetaVoxAPI.js')
	store.switchGroupfolder(2)
	store.fillFromApi({ 10: { status: 'Concept', klant: 'A', _permissions: 3 } }, [10], store.now(), store.currentEpoch())
})

describe('saving a file the grid shows', () => {
	it('sends the changed fields in one request and shows them at once', async () => {
		let finish
		axios.post.mockReturnValue(new Promise((resolve) => { finish = resolve }))

		const saving = api.saveFileFields(10, { status: 'Definitief', klant: 'B' })
		const shownWhileSaving = state.metadataCache.get(10).status
		finish({})
		await saving

		expect(axios.post).toHaveBeenCalledOnce()
		expect(axios.post).toHaveBeenCalledWith('/apps/metavox/api/files/10/metadata', { metadata: { status: 'Definitief', klant: 'B' } })
		expect(shownWhileSaving).toBe('Definitief')
		expect(state.metadataCache.get(10)).toMatchObject({ status: 'Definitief', klant: 'B' })
	})

	it('puts the old values back and throws when saving fails', async () => {
		axios.post.mockRejectedValue(new Error('500'))

		await expect(api.saveFileFields(10, { status: 'Definitief' })).rejects.toThrow('500')

		expect(state.metadataCache.get(10).status).toBe('Concept')
	})

	it('releases the cell lock in the same request', async () => {
		await api.saveFileFields(10, { status: 'Definitief' }, { unlockField: 'status' })

		expect(axios.post.mock.calls[0][1]).toEqual({ metadata: { status: 'Definitief' }, unlock: true, unlock_field: 'status' })
	})
})

describe('saving a file of another team folder', () => {
	it('leaves the grid alone', async () => {
		await api.saveFileFields(99, { status: 'Definitief' })

		expect(axios.post).toHaveBeenCalledOnce()
		expect(state.metadataCache.has(99)).toBe(false)
	})
})

describe('following the store', () => {
	it('tells listeners which files changed, until they stop', () => {
		const heard = []
		const stop = store.onStoreChange((ids) => heard.push(ids))

		store.applyMutation(10, { status: 'X' })
		store.touch([10, 11])
		stop()
		store.applyMutation(10, { status: 'Y' })

		expect(heard).toEqual([[10], [10, 11]])
	})
})

describe('loading for the sidebar', () => {
	it('gives the team folder, the server\'s verdict and both field lists', async () => {
		axios.get.mockResolvedValue({ data: { groupfolder_id: 2, editable: false, fields: [{ field_name: 'status' }], team_folder_fields: [{ field_name: 'owner' }] } })

		const loaded = await api.loadFileMetadata(10)

		expect(axios.get).toHaveBeenCalledWith('/apps/metavox/api/files/10/metadata', { signal: undefined })
		expect(loaded).toEqual({ groupfolderId: 2, editable: false, fields: [{ field_name: 'status' }], teamFolderFields: [{ field_name: 'owner' }] })
	})
})

describe('waiting for a save', () => {
	it('settles only when the save that changed the file is done', async () => {
		let finish
		axios.post.mockReturnValue(new Promise((resolve) => { finish = resolve }))
		const order = []
		const stop = store.onStoreChange(() => api.whenSaved(10).then(() => order.push('reload')))

		const saving = api.saveFileFields(10, { status: 'Definitief' })
		await Promise.resolve()
		order.push('server saved')
		finish({})
		await saving
		await new Promise((resolve) => setTimeout(resolve, 0))
		stop()

		expect(order).toEqual(['server saved', 'reload'])
	})

	it('does not wait when nothing is being saved', async () => {
		await expect(api.whenSaved(42)).resolves.toBeUndefined()
	})
})
