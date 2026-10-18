import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/files', () => ({ registerFileAction: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('../../src/filesplugin/BulkMetadataModalApp.js', () => ({ openBulkMetadataModal: vi.fn() }))

let files
let bulk

beforeEach(async () => {
	vi.resetModules()
	vi.stubGlobal('window', {})
	vi.spyOn(console, 'error').mockImplementation(() => {})
	files = await import('@nextcloud/files')
	files.registerFileAction.mockReset()
	bulk = await import('../../src/filesplugin/BulkMetadataAction.js')
})

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

describe('registerBulkMetadataAction', () => {
	it('registers at both official entry points', () => {
		bulk.registerBulkMetadataAction()

		// NC33+: @nextcloud/files 4
		expect(files.registerFileAction).toHaveBeenCalledOnce()
		const action = files.registerFileAction.mock.calls[0][0]
		expect(action.id).toBe('metavox-edit-metadata')
		// NC31/32: the list of @nextcloud/files 3
		expect(window._nc_fileactions).toEqual([action])
	})

	it('is not offered on saved searches, only on files and folders', () => {
		bulk.registerBulkMetadataAction()
		const action = files.registerFileAction.mock.calls[0][0]
		const file = { fileid: 1, type: 'file', attributes: {} }
		const search = { fileid: 2 ** 52 + 3, type: 'folder', attributes: { 'metavox-search': 3 } }

		expect(action.enabled({ nodes: [file, { ...file, fileid: 2 }] })).toBe(true)
		expect(action.enabled({ nodes: [search, { ...search, fileid: 2 ** 52 + 4 }] })).toBe(false)
		expect(action.enabled([file, search])).toBe(false)
	})

	it('adds the action to the NC31/32 list only once', () => {
		bulk.registerBulkMetadataAction()
		bulk.registerBulkMetadataAction()

		expect(window._nc_fileactions).toHaveLength(1)
	})

	it('loads the modal only when the action runs', async () => {
		const modal = await import('../../src/filesplugin/BulkMetadataModalApp.js')
		modal.openBulkMetadataModal.mockReset()
		bulk.registerBulkMetadataAction()
		const action = files.registerFileAction.mock.calls[0][0]
		const nodes = [{ fileid: 1, type: 'file' }, { fileid: 2, type: 'file' }]

		// NC33+ passes a context, NC31/32 the nodes themselves.
		expect(await action.execBatch({ nodes })).toEqual([null, null])
		expect(await action.execBatch(nodes)).toEqual([null, null])
		expect(modal.openBulkMetadataModal).toHaveBeenCalledTimes(2)
		expect(modal.openBulkMetadataModal).toHaveBeenCalledWith(nodes)
	})

	it('opens through the Files plugin when it is there: its Vue is loaded already', async () => {
		const open = vi.fn()
		window._metavoxLoadBulk = vi.fn(() => Promise.resolve({ openBulkMetadataModal: open }))
		const modal = await import('../../src/filesplugin/BulkMetadataModalApp.js')
		modal.openBulkMetadataModal.mockReset()
		bulk.registerBulkMetadataAction()
		const nodes = [{ fileid: 1, type: 'file' }, { fileid: 2, type: 'file' }]

		expect(await files.registerFileAction.mock.calls[0][0].execBatch({ nodes })).toEqual([null, null])
		expect(open).toHaveBeenCalledWith(nodes)
		expect(modal.openBulkMetadataModal).not.toHaveBeenCalled()
	})

	it('still registers for NC31/32 when the NC33+ route fails', () => {
		files.registerFileAction.mockImplementation(() => { throw new Error('Invalid exec function') })

		bulk.registerBulkMetadataAction()

		expect(window._nc_fileactions).toHaveLength(1)
		expect(console.error).toHaveBeenCalled()
	})
})
