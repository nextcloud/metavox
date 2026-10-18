import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path, params) => path.replace('{groupfolderId}', params.groupfolderId) }))
vi.mock('@nextcloud/event-bus', () => ({ subscribe: vi.fn() }))
vi.mock('../../src/filesplugin/columns/UndoSupport.js', () => ({ pushUndo: vi.fn() }))

let axios
let api

beforeEach(async () => {
	vi.resetModules()
	axios = (await import('@nextcloud/axios')).default
	axios.get.mockReset()
	api = await import('../../src/filesplugin/columns/MetaVoxAPI.js')
})

describe('fetchDirectoryMetadata', () => {
	it('asks for all fields, so entries are complete for every view', async () => {
		axios.get.mockResolvedValue({ data: { 10: { status: 'Draft', owner: 'alice' } } })

		const result = await api.fetchDirectoryMetadata(2, [10])

		expect(axios.get).toHaveBeenCalledWith('/apps/metavox/api/groupfolders/2/directory-metadata', { params: { file_ids: '10' } })
		expect(result).toEqual({ data: { 10: { status: 'Draft', owner: 'alice' } }, okIds: [10] })
	})

	it('requests at most 200 files at a time', async () => {
		axios.get.mockResolvedValue({ data: {} })
		const ids = Array.from({ length: 450 }, (_, i) => i + 1)

		await api.fetchDirectoryMetadata(2, ids)

		expect(axios.get.mock.calls.map(([, { params }]) => params.file_ids.split(',').length)).toEqual([200, 200, 50])
	})

	it('leaves the files of a failed chunk out of okIds', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		axios.get
			.mockRejectedValueOnce(new Error('network'))
			.mockResolvedValueOnce({ data: { 201: { status: 'Final' } } })
		const ids = Array.from({ length: 201 }, (_, i) => i + 1)

		const { data, okIds } = await api.fetchDirectoryMetadata(2, ids)

		expect(okIds).toEqual([201])
		expect(data).toEqual({ 201: { status: 'Final' } })
	})

	it('does nothing without files', async () => {
		expect(await api.fetchDirectoryMetadata(2, [])).toEqual({ data: {}, okIds: [] })
		expect(axios.get).not.toHaveBeenCalled()
	})
})
