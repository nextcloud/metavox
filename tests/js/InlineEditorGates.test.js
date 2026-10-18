import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('../../src/utils/toast.js', () => ({ showError: vi.fn(), showWarning: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('../../src/filesplugin/columns/MetaVoxAPI.js', () => ({ saveSingleField: vi.fn(), saveBulkFields: vi.fn() }))
vi.mock('../../src/filesplugin/columns/MetadataLoader.js', () => ({ requireFull: vi.fn() }))
vi.mock('../../src/filesplugin/columns/UndoSupport.js', () => ({ pushUndo: vi.fn() }))
vi.mock('../../src/filesplugin/columns/FilelinkNames.js', () => ({ getFilelinkName: vi.fn() }))

let editor
let axios
let dialogs
let loader
let api
let documentListeners
let state

beforeEach(async () => {
	vi.resetModules()
	documentListeners = {}
	vi.stubGlobal('document', {
		addEventListener: (type, fn) => { documentListeners[type] = fn },
		removeEventListener: () => {},
	})
	axios = (await import('@nextcloud/axios')).default
	dialogs = await import('../../src/utils/toast.js')
	loader = await import('../../src/filesplugin/columns/MetadataLoader.js')
	api = await import('../../src/filesplugin/columns/MetaVoxAPI.js')
	for (const mock of [axios.post, dialogs.showError, loader.requireFull, api.saveBulkFields]) mock.mockReset()
	state = await import('../../src/filesplugin/columns/MetaVoxState.js')
	state.permissionCache.clear()
	state.setActiveGroupfolderId(2)
	editor = await import('../../src/filesplugin/columns/InlineEditor.js')
})

afterEach(() => vi.unstubAllGlobals())

describe('opening the editor', () => {
	const cell = () => ({ dataset: { fileId: '10', metavoxField: 'note' }, style: {} })

	it('refuses a value that stays cut off, before locking', async () => {
		loader.requireFull.mockResolvedValue(false)

		await editor.openInlineEditor(cell(), { field_name: 'note', field_type: 'textarea' })

		expect(loader.requireFull).toHaveBeenCalledWith([10], 'note')
		expect(dialogs.showError).toHaveBeenCalledOnce()
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('locks the cell once the full value is there', async () => {
		loader.requireFull.mockResolvedValue(true)
		axios.post.mockRejectedValue({ response: { status: 409, data: { lockedBy: 'bob' } } })

		await editor.openInlineEditor(cell(), { field_name: 'note', field_type: 'textarea' })

		expect(axios.post).toHaveBeenCalledWith(expect.stringContaining('/lock'), { field_name: 'note' })
		expect(dialogs.showError).not.toHaveBeenCalled()
	})
})

describe('the keyboard on a cell', () => {
	// A cell set up for editing, with its listeners kept to call.
	function editableCell() {
		const listeners = {}
		const td = {
			dataset: { fileId: '10', metavoxField: 'note' },
			style: {},
			classList: { add: vi.fn() },
			textContent: 'Concept',
			setAttribute: vi.fn(),
			removeAttribute: vi.fn(),
			addEventListener: (type, fn) => { listeners[type] = fn },
		}
		editor.setupCellEditing(td, { field_name: 'note', field_label: 'Note', field_type: 'text' })
		return { td, listeners }
	}
	const key = (target, k) => ({ key: k, target, preventDefault: vi.fn(), stopPropagation: vi.fn() })

	it('opens the editor with Enter on the cell itself', () => {
		const { td, listeners } = editableCell()
		const press = key(td, 'Enter')

		listeners.keydown(press)

		expect(press.preventDefault).toHaveBeenCalled()
		expect(press.stopPropagation).toHaveBeenCalled()
	})

	it('leaves Enter in the open editor to the editor, so it saves', () => {
		const { listeners } = editableCell()
		const press = key({ tagName: 'INPUT' }, 'Enter')

		listeners.keydown(press)

		expect(press.stopPropagation).not.toHaveBeenCalled()
		expect(press.preventDefault).not.toHaveBeenCalled()
	})
})

describe('the fill handle', () => {
	// A source cell with one editable cell below it.
	function dragOneCellDown() {
		const target = { dataset: { fileId: '11' }, getBoundingClientRect: () => ({ top: 10 }), classList: { add: vi.fn(), remove: vi.fn() } }
		const row = { querySelector: () => target, nextElementSibling: null }
		const source = { dataset: { metavoxField: 'note' }, closest: () => ({ nextElementSibling: row }), querySelector: () => ({ value: 'new value' }) }
		let mousedown
		const handle = { addEventListener: (type, fn) => { mousedown = fn } }
		editor.setupFillHandle(handle, source, { field_name: 'note' })
		mousedown({ preventDefault() {}, stopPropagation() {} })
		documentListeners.mousemove({ preventDefault() {}, clientY: 100 })
		return { target, mouseup: () => documentListeners.mouseup({}) }
	}

	// The server said the cell below may be changed.
	beforeEach(() => state.permissionCache.set(11, true))

	it('fills nothing when the targets cannot be loaded in full', async () => {
		loader.requireFull.mockResolvedValue(false)
		const { target, mouseup } = dragOneCellDown()

		await mouseup()

		expect(loader.requireFull).toHaveBeenCalledWith([11], 'note')
		expect(api.saveBulkFields).not.toHaveBeenCalled()
		expect(dialogs.showError).toHaveBeenCalledOnce()
		expect(target.classList.remove).toHaveBeenCalledWith('metavox-fill-highlight')
	})

	it('fills once the targets are complete', async () => {
		loader.requireFull.mockResolvedValue(true)
		const { mouseup } = dragOneCellDown()

		await mouseup()

		expect(api.saveBulkFields).toHaveBeenCalledWith([11], 'note', 'new value')
	})

	it('leaves a cell alone while its right is not known', async () => {
		state.permissionCache.delete(11)
		loader.requireFull.mockResolvedValue(true)
		const { mouseup } = dragOneCellDown()

		await mouseup()

		expect(api.saveBulkFields).not.toHaveBeenCalled()
	})
})
