// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn(() => Promise.resolve({ data: {} })), get: vi.fn(() => Promise.resolve({ data: [] })) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path, params = {}) => path.replace(/{(\w+)}/g, (_, k) => params[k]) }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showWarning: vi.fn() }))
vi.mock('../../src/filesplugin/columns/MetaVoxAPI.js', () => ({ saveSingleField: vi.fn(), saveBulkFields: vi.fn() }))
vi.mock('../../src/filesplugin/columns/MetadataLoader.js', () => ({ requireFull: vi.fn(() => Promise.resolve(true)) }))
vi.mock('../../src/filesplugin/columns/UndoSupport.js', () => ({ pushUndo: vi.fn() }))
vi.mock('../../src/filesplugin/columns/FilelinkNames.js', () => ({
	filelinkHref: vi.fn(), filelinkLabel: vi.fn(() => ({})), requestFilelinkName: vi.fn(), getFilelinkName: vi.fn(),
}))
vi.mock('../../src/filesplugin/columns/MetadataFilter.js', () => ({ getFilterInstance: () => null }))
vi.mock('../../src/filesplugin/columns/ListingSync.js', () => ({
	getListingGroupfolderId: () => 2, getListingNode: vi.fn(() => null), isListingLoading: () => true,
}))
vi.mock('../../src/filesplugin/columns/ViewEditorPanel.vue', () => ({ default: {} }))
vi.mock('../../src/filesplugin/columns/SaveFilters.js', () => ({ openSaveFiltersDialog: vi.fn() }))
vi.mock('../../src/filesplugin/columns/MetadataStore.js', () => ({
	isTruncated: vi.fn(() => false), hasNoTeamFolder: vi.fn(() => false), seedFromNode: vi.fn(),
}))

// Every kind of cell editor, with a value, so a cancel has something to restore.
const FIELDS = {
	select: [{ field_name: 'status', field_label: 'Status', field_type: 'select', field_options: ['Concept', 'Klaar'] }, 'Concept'],
	checkbox: [{ field_name: 'done', field_label: 'Done', field_type: 'checkbox' }, '1'],
	boolean: [{ field_name: 'ok', field_label: 'OK', field_type: 'boolean' }, '0'],
	multiselect: [{ field_name: 'tags', field_label: 'Tags', field_type: 'multiselect', field_options: ['A', 'B'] }, 'A'],
	filelink: [{ field_name: 'link', field_label: 'Link', field_type: 'filelink' }, '12:'],
	text: [{ field_name: 'klant', field_label: 'Klant', field_type: 'text' }, 'Acme'],
	number: [{ field_name: 'budget', field_label: 'Budget', field_type: 'number' }, '42'],
	date: [{ field_name: 'deadline', field_label: 'Deadline', field_type: 'date' }, '2026-10-06'],
	url: [{ field_name: 'site', field_label: 'Site', field_type: 'url' }, 'https://example.org'],
	user: [{ field_name: 'owner', field_label: 'Owner', field_type: 'user' }, 'alice'],
}
const CONFIGS = Object.values(FIELDS).map(([config]) => config)

let dom
let editor
let state
let api
let windowKeys

beforeEach(async () => {
	vi.resetModules()
	vi.clearAllMocks()
	document.body.innerHTML = '<div class="files-list__filters"></div><table class="files-list"><thead><tr></tr></thead><tbody></tbody></table>'
	navigator.sendBeacon = vi.fn(() => true)
	state = await import('../../src/filesplugin/columns/MetaVoxState.js')
	api = await import('../../src/filesplugin/columns/MetaVoxAPI.js')
	editor = await import('../../src/filesplugin/columns/InlineEditor.js')
	dom = await import('../../src/filesplugin/columns/ColumnDOM.js')
	const format = await import('../../src/filesplugin/columns/CellFormat.js')
	state.metadataCache.clear()
	state.permissionCache.clear()
	state.setActiveGroupfolderId(2)
	state.setActiveColumnConfigs(CONFIGS)
	dom.setDependencies({
		setupCellEditing: editor.setupCellEditing,
		updateCellAccessibleName: editor.updateCellAccessibleName,
		getActiveEditorCell: editor.getActiveEditorCell,
		closeInlineEditor: editor.closeInlineEditor,
	})
	format.setDependencies({
		getActiveEditorCell: editor.getActiveEditorCell,
		updateCellAccessibleName: editor.updateCellAccessibleName,
		currentUserId: () => 'alice',
	})
	editor.setSetCellValue(dom.setCellValue)
	editor.setGetActiveColumnConfigs(() => state.getActiveColumnConfigs())
	editor.setUpdateAllRowCells(dom.updateAllRowCells)
	// What the Files app hears: its shortcuts listen on window.
	windowKeys = vi.fn()
	window.addEventListener('keydown', windowKeys)
	return () => window.removeEventListener('keydown', windowKeys)
})

// An editor a test leaves open would keep its listeners on the shared document.
afterEach(() => editor?.closeInlineEditor(false))

function cellOf(type) {
	const [config, value] = FIELDS[type]
	const meta = Object.fromEntries(Object.values(FIELDS).map(([c, v]) => [c.field_name, v]))
	state.metadataCache.set(10, meta)
	state.permissionCache.set(10, true)
	const tr = document.createElement('tr')
	tr.setAttribute('data-cy-files-list-row', '')
	tr.setAttribute('data-cy-files-list-row-fileid', '10')
	document.querySelector('tbody').appendChild(tr)
	dom.injectRowColumns(tr)
	const td = tr.querySelector(`td[data-metavox-field="${config.field_name}"]`)
	return { td, config, value }
}

async function open(type) {
	const cell = cellOf(type)
	cell.td.focus()
	cell.text = cell.td.textContent
	await editor.openInlineEditor(cell.td, cell.config)
	return cell
}

const press = (key, target = document.activeElement, init = {}) => {
	const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...init })
	target.dispatchEvent(event)
	return event
}
const unlocks = () => navigator.sendBeacon.mock.calls.filter(([url]) => url.includes('/unlock')).length

describe('Escape in a cell editor', () => {
	it.each(Object.keys(FIELDS))('%s: the editor has the focus once open', async (type) => {
		const { td } = await open(type)
		const inside = td._portalEditor ?? td
		expect(document.activeElement).not.toBe(td)
		expect(inside.contains(document.activeElement)).toBe(true)
	})

	it.each(Object.keys(FIELDS))('%s: Escape from where the focus is cancels the edit', async (type) => {
		const { td, text } = await open(type)

		press('Escape')

		expect(editor.getActiveEditorCell()).toBeNull()
		expect(document.querySelector('body > .metavox-inline-editor')).toBeNull()
		expect(api.saveSingleField).not.toHaveBeenCalled()
		expect(unlocks()).toBe(1)
		expect(td.textContent).toBe(text)
		expect(document.activeElement).toBe(td)
		expect(windowKeys).not.toHaveBeenCalled()
	})

	it('keeps other keys in the editor, away from the file list', async () => {
		await open('select')

		press('F2')
		press('Delete')

		expect(windowKeys).not.toHaveBeenCalled()
		expect(editor.getActiveEditorCell()).not.toBeNull()
	})

	it('leaves Escape to a dialog opened from the editor', async () => {
		await open('filelink')
		const dialog = document.createElement('div')
		dialog.setAttribute('role', 'dialog')
		const button = document.createElement('button')
		dialog.appendChild(button)
		document.body.appendChild(dialog)
		button.focus()

		press('Escape')

		expect(editor.getActiveEditorCell()).not.toBeNull()
		expect(windowKeys).toHaveBeenCalledOnce()
	})

	it('leaves Escape to the file list when no editor is open', async () => {
		const { td } = await open('select')
		press('Escape')

		press('Escape', td)

		expect(windowKeys).toHaveBeenCalledOnce()
	})

	it('opens again with Enter on the cell it went back to', async () => {
		const { td } = await open('select')
		press('Escape')
		expect(editor.getActiveEditorCell()).toBeNull()

		press('Enter')
		await vi.waitFor(() => expect(editor.getActiveEditorCell()).toBe(td))
	})

	it('drops a tick that was not saved', async () => {
		const { td, text } = await open('multiselect')
		document.activeElement.click()

		press('Escape')

		expect(editor.getActiveEditorCell()).toBeNull()
		expect(unlocks()).toBe(1)
		expect(api.saveSingleField).not.toHaveBeenCalled()
		expect(td.textContent).toBe(text)
	})

	it('closes from the page too, when a click left the focus on nothing', async () => {
		const { td } = await open('user')
		document.activeElement.blur()

		press('Escape', document.body)

		expect(editor.getActiveEditorCell()).toBeNull()
		expect(unlocks()).toBe(1)
		expect(document.activeElement).toBe(td)
	})

	it('leaves keys outside the editor to the file list', async () => {
		await open('select')

		press('F2', document.body)

		expect(windowKeys).toHaveBeenCalledOnce()
	})

	it('keeps the focus in the editor while the view menu is open', async () => {
		const views = await import('../../src/filesplugin/columns/ViewManager.js')
		views.injectViewTabs([{ id: 1, name: 'V1' }])
		document.querySelector('.mv-view-picker-label').click()
		expect(document.querySelector('.mv-tab-menu')).not.toBeNull()
		const { td, text } = await open('url')
		document.activeElement.value = 'https://typed.example'

		press('Escape')

		expect(api.saveSingleField).not.toHaveBeenCalled()
		expect(unlocks()).toBe(1)
		expect(td.textContent).toBe(text)
		expect(document.activeElement).toBe(td)
	})

	it('waits while a character is still being composed', async () => {
		await open('text')

		press('Escape', document.activeElement, { isComposing: true })
		expect(editor.getActiveEditorCell()).not.toBeNull()

		press('Escape')
		expect(editor.getActiveEditorCell()).toBeNull()
	})
})

describe('clicking', () => {
	const click = (target) => target.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }))
	// The click-outside listener starts a tick after opening.
	const tick = () => new Promise((resolve) => setTimeout(resolve, 0))

	it('keeps the editor while you work in a dialog over the page, such as the file picker', async () => {
		await open('filelink')
		await tick()
		const dialog = document.createElement('div')
		dialog.setAttribute('role', 'dialog')
		const button = document.createElement('button')
		dialog.appendChild(button)
		document.body.appendChild(dialog)

		click(button)

		expect(editor.getActiveEditorCell()).not.toBeNull()
		expect(unlocks()).toBe(0)
	})

	it('cancels the edit on a click elsewhere, also inside the page\'s own regions', async () => {
		// As in the Files app: the list sits inside landmarks with roles.
		const main = document.createElement('main')
		main.setAttribute('role', 'main')
		main.appendChild(document.querySelector('table'))
		document.body.appendChild(main)
		const { td } = await open('filelink')
		await tick()

		click(td.parentElement.querySelector('td:not([data-metavox-field="link"])'))

		expect(editor.getActiveEditorCell()).toBeNull()
		expect(unlocks()).toBe(1)
	})
})

describe('scrolling', () => {
	it('keeps a dropdown open while its own list scrolls', async () => {
		const { td } = await open('select')

		td._portalEditor.dispatchEvent(new Event('scroll'))

		expect(editor.getActiveEditorCell()).toBe(td)
	})

	it('closes a dropdown when the page scrolls, with the focus back on the cell', async () => {
		const { td } = await open('select')

		document.dispatchEvent(new Event('scroll'))

		expect(editor.getActiveEditorCell()).toBeNull()
		expect(unlocks()).toBe(1)
		expect(document.activeElement).toBe(td)
	})
})

describe('closing without Escape', () => {
	it('saves with Enter and leaves the focus alone', async () => {
		const { td } = await open('text')
		document.activeElement.value = 'Nieuw'

		press('Enter')

		expect(api.saveSingleField).toHaveBeenCalledWith(10, 'klant', 'Nieuw', { unlock: true })
		expect(editor.getActiveEditorCell()).toBeNull()
		expect(document.activeElement).not.toBe(td)
	})

	it('does not take the focus when the window loses it', async () => {
		const { td } = await open('select')

		editor.closeInlineEditor(true)

		expect(editor.getActiveEditorCell()).toBeNull()
		expect(document.activeElement).not.toBe(td)
	})
})
