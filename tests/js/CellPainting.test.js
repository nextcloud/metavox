// @vitest-environment happy-dom
import { beforeEach, describe, expect, it, vi } from 'vitest'

// The real @nextcloud/l10n: what a screen reader hears depends on how it escapes.
vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn(() => Promise.resolve({ data: {} })) } }))
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
	getListingGroupfolderId: () => 2, getListingNode: () => null, isListingLoading: () => true,
}))
vi.mock('../../src/filesplugin/columns/MetadataStore.js', () => ({ isTruncated: vi.fn(() => false), seedFromNode: vi.fn() }))

const KLANT = { field_name: 'klant', field_label: 'Klant', field_type: 'text' }
const STATUS = { field_name: 'status', field_label: 'Status', field_type: 'text' }

let dom
let editor
let state
let store

beforeEach(async () => {
	vi.resetModules()
	vi.clearAllMocks()
	document.body.innerHTML = '<table class="files-list"><tbody></tbody></table>'
	navigator.sendBeacon = vi.fn(() => true)
	state = await import('../../src/filesplugin/columns/MetaVoxState.js')
	store = await import('../../src/filesplugin/columns/MetadataStore.js')
	store.isTruncated.mockReturnValue(false)
	editor = await import('../../src/filesplugin/columns/InlineEditor.js')
	dom = await import('../../src/filesplugin/columns/ColumnDOM.js')
	state.metadataCache.clear()
	state.permissionCache.clear()
	state.setActiveGroupfolderId(2)
	state.setActiveColumnConfigs([KLANT, STATUS])
	// The wiring MetaVoxColumns does at startup.
	dom.setDependencies({
		setupCellEditing: editor.setupCellEditing,
		updateCellAccessibleName: editor.updateCellAccessibleName,
		getActiveEditorCell: editor.getActiveEditorCell,
		closeInlineEditor: editor.closeInlineEditor,
	})
	editor.setSetCellValue(dom.setCellValue)
	editor.setGetActiveColumnConfigs(() => state.getActiveColumnConfigs())
	editor.setUpdateAllRowCells(dom.updateAllRowCells)
})

/** A file list row for one file, with the MetaVox cells in it. */
function row(fileId, meta, editable = true) {
	if (meta) state.metadataCache.set(fileId, meta)
	if (editable) state.permissionCache.set(fileId, true)
	const tr = document.createElement('tr')
	tr.setAttribute('data-cy-files-list-row', '')
	tr.setAttribute('data-cy-files-list-row-fileid', String(fileId))
	document.querySelector('tbody').appendChild(tr)
	dom.injectRowColumns(tr)
	return tr
}
const cell = (tr, field) => tr.querySelector(`td[data-metavox-field="${field}"]`)

describe('the name a screen reader hears', () => {
	it('follows the value when it is emptied', () => {
		const td = cell(row(10, { klant: 'Klant 013' }), 'klant')

		dom.setCellValue(td, '', KLANT)

		expect(td.getAttribute('aria-label')).not.toContain('Klant 013')
		expect(td.getAttribute('aria-label')).toContain('Empty')
	})

	it('follows a cut-off value', () => {
		const td = cell(row(10, { klant: 'Klant 013' }), 'klant')
		store.isTruncated.mockReturnValue(true)

		dom.setCellValue(td, 'Een lange toelichting', KLANT)

		expect(td.getAttribute('aria-label')).toContain('Een lange toelichting')
	})

	it('reads special characters as they are', () => {
		const td = cell(row(10, { klant: "R&D <x> 'y'" }), 'klant')

		expect(td.getAttribute('aria-label')).toBe("Klant: R&D <x> 'y'. Press Enter to edit.")
	})

	it('offers no editing to who may not edit', () => {
		const td = cell(row(10, { klant: 'Klant 013' }, false), 'klant')

		expect(td.getAttribute('aria-label')).toBe('Klant: Klant 013.')
	})
})

describe('a required field without its value', () => {
	const REQUIRED = { ...KLANT, is_required: true }
	const OK = { field_name: 'ok', field_label: 'Akkoord', field_type: 'checkbox', is_required: true }

	it('is marked, with a tooltip and a name that say so', () => {
		const td = cell(row(10, { klant: 'Klant 013' }), 'klant')

		dom.setCellValue(td, '', REQUIRED)

		expect(td.dataset.mvRequired).toBe('1')
		expect(td.title).toBe('Required, no value yet')
		expect(td.getAttribute('aria-label')).toBe('Klant: Required, no value yet. Press Enter to edit.')
	})

	it('loses the mark once it has a value', () => {
		const td = cell(row(10, { klant: '' }), 'klant')
		dom.setCellValue(td, '', REQUIRED)

		dom.setCellValue(td, 'Klant 014', REQUIRED)

		expect(td.dataset.mvRequired).toBeUndefined()
		expect(td.getAttribute('aria-label')).toContain('Klant 014')
	})

	it('never marks a checkbox, even one flagged required', () => {
		const td = cell(row(10, { klant: 'x' }), 'klant')

		for (const value of ['0', '', '1']) {
			dom.setCellValue(td, value, OK)
			expect(td.dataset.mvRequired).toBeUndefined()
		}
	})

	it('is not marked when the field is not required', () => {
		const td = cell(row(10, { klant: 'x' }), 'klant')

		dom.setCellValue(td, '', KLANT)

		expect(td.dataset.mvRequired).toBeUndefined()
	})
})

describe('redrawing every cell', () => {
	it('leaves the cell someone is typing in alone', async () => {
		const tr = row(10, { klant: 'Klant 013', status: 'Concept' })
		await editor.openInlineEditor(cell(tr, 'klant'), KLANT)
		const input = cell(tr, 'klant').querySelector('input')
		input.value = 'Klant 013 half getypt'

		state.metadataCache.set(10, { klant: 'Klant 013', status: 'Goedgekeurd' })
		dom.updateAllRowCells()

		expect(cell(tr, 'klant').querySelector('input')?.value).toBe('Klant 013 half getypt')
		expect(cell(tr, 'status').textContent).toBe('Goedgekeurd')
	})
})

describe('a row the file list reuses for another file', () => {
	it('drops the lock of the file before', () => {
		const tr = row(10, { klant: 'Klant 010' })
		row(11, { klant: 'Klant 011' }).remove()
		const td = cell(tr, 'klant')
		// What the push handler puts on a cell someone else is editing.
		td.style.backgroundColor = 'rgba(233, 163, 28, 0.15)'
		td.style.boxShadow = 'inset 0 0 0 2px #e9a31c'
		td.dataset.lockedBy = 'bob'
		td._metavoxLocked = true
		const badge = document.body.appendChild(document.createElement('div'))
		td._lockBadge = badge

		tr.setAttribute('data-cy-files-list-row-fileid', '11')
		dom._refreshRowCells(tr)

		expect(td.dataset.fileId).toBe('11')
		expect(td.textContent).toBe('Klant 011')
		expect(td._metavoxLocked).toBeFalsy()
		expect(td.dataset.lockedBy).toBeUndefined()
		expect(td.style.boxShadow).toBe('')
		expect(document.body.contains(badge)).toBe(false)
	})

	it('closes the editor that was open on it, unlocking the file before', async () => {
		const tr = row(10, { klant: 'Klant 010' })
		row(11, { klant: 'Klant 011' }).remove()
		await editor.openInlineEditor(cell(tr, 'klant'), KLANT)

		tr.setAttribute('data-cy-files-list-row-fileid', '11')
		dom._refreshRowCells(tr)

		expect(cell(tr, 'klant').querySelector('input')).toBeNull()
		expect(cell(tr, 'klant').textContent).toBe('Klant 011')
		expect(navigator.sendBeacon).toHaveBeenCalledWith(expect.stringContaining('/files/10/unlock'), expect.anything())
	})

	it('says it is loading while the new file has no values yet', () => {
		const tr = row(10, { klant: 'Klant 010' })

		tr.setAttribute('data-cy-files-list-row-fileid', '12')
		dom._refreshRowCells(tr)

		expect(cell(tr, 'klant').textContent).toBe('…')
		expect(cell(tr, 'klant').getAttribute('aria-label')).not.toContain('Klant 010')
	})
})

describe('closing the editor', () => {
	it('shows a value someone else saved while it was open, after Escape', async () => {
		const tr = row(10, { klant: 'Klant 010' })
		await editor.openInlineEditor(cell(tr, 'klant'), KLANT)
		state.metadataCache.set(10, { klant: 'Klant 010 van een ander' })
		dom.updateAllRowCells()

		editor.closeInlineEditor(true)

		expect(cell(tr, 'klant').textContent).toBe('Klant 010 van een ander')
	})
})

describe('opening the editor while the row is reused', () => {
	it('gives up, and hands back a lock it already took', async () => {
		const tr = row(10, { klant: 'Klant 010' })
		row(11, { klant: 'Klant 011' }).remove()
		const axios = (await import('@nextcloud/axios')).default
		let lockTaken
		axios.post.mockImplementationOnce(() => new Promise((resolve) => { lockTaken = resolve }))

		const opening = editor.openInlineEditor(cell(tr, 'klant'), KLANT)
		await vi.waitFor(() => expect(lockTaken).toBeTypeOf('function'))
		tr.setAttribute('data-cy-files-list-row-fileid', '11')
		dom._refreshRowCells(tr)
		lockTaken({ data: {} })
		await opening

		expect(editor.getActiveEditorCell()).toBeNull()
		expect(cell(tr, 'klant').textContent).toBe('Klant 011')
		expect(navigator.sendBeacon).toHaveBeenCalledWith(expect.stringContaining('/files/10/unlock'), expect.anything())
	})
})

describe('the fill handle', () => {
	async function dragFrom(tr) {
		await editor.openInlineEditor(cell(tr, 'klant'), KLANT)
		cell(tr, 'klant').querySelector('input').value = 'Gevuld'
		const handle = cell(tr, 'klant').querySelector('.metavox-fill-handle')
		handle.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }))
		// happy-dom lays nothing out: every cell is at 0, so this reaches all rows below.
		document.dispatchEvent(new MouseEvent('mousemove', { clientY: 10 }))
	}
	const release = async () => {
		document.dispatchEvent(new MouseEvent('mouseup'))
		await new Promise((r) => setTimeout(r, 0))
	}

	it('fills the rows below with the value', async () => {
		const tr = row(10, { klant: 'Bron' })
		row(11, { klant: 'Klant 011' })
		const api = await import('../../src/filesplugin/columns/MetaVoxAPI.js')
		await dragFrom(tr)
		await release()

		expect(api.saveBulkFields).toHaveBeenCalledWith([11], 'klant', 'Gevuld')
	})

	it('fills nothing once its editor closed, such as when the source row is reused', async () => {
		const tr = row(10, { klant: 'Bron' })
		row(11, { klant: 'Klant 011' })
		row(20, { klant: 'Klant 020' }).remove()
		const api = await import('../../src/filesplugin/columns/MetaVoxAPI.js')
		await dragFrom(tr)
		tr.setAttribute('data-cy-files-list-row-fileid', '20')
		dom._refreshRowCells(tr)
		await release()

		expect(api.saveBulkFields).not.toHaveBeenCalled()
		expect(document.querySelector('.metavox-fill-highlight')).toBeNull()
	})

	it('leaves out a row handed to another file during the drag', async () => {
		const tr = row(10, { klant: 'Bron' })
		const target = row(11, { klant: 'Klant 011' })
		row(12, { klant: 'Klant 012' })
		row(30, { klant: 'Alleen lezen' }, false).remove()
		const api = await import('../../src/filesplugin/columns/MetaVoxAPI.js')
		await dragFrom(tr)
		target.setAttribute('data-cy-files-list-row-fileid', '30')
		dom._refreshRowCells(target)
		await release()

		expect(api.saveBulkFields).toHaveBeenCalledWith([12], 'klant', 'Gevuld')
	})
})

describe('a reused cell keeps what makes it editable', () => {
	it('keeps its pointer and its place in the tab order', () => {
		const tr = row(10, { klant: 'Alleen lezen' }, false)
		row(11, { klant: 'Klant 011' }).remove()
		const td = cell(tr, 'klant')
		// A read-only file takes the cell out of the tab order when someone tries.
		td.dispatchEvent(new MouseEvent('dblclick'))
		expect(td.hasAttribute('tabindex')).toBe(false)

		tr.setAttribute('data-cy-files-list-row-fileid', '11')
		dom._refreshRowCells(tr)

		expect(td.tabIndex).toBe(0)
		expect(td.style.cursor).toBe('pointer')
	})

	it('keeps the lock of its own file on a redraw', () => {
		const tr = row(10, { klant: 'Klant 010' })
		const td = cell(tr, 'klant')
		td.dataset.lockedBy = 'bob'
		td._metavoxLocked = true

		dom._refreshRowCells(tr)

		expect(td.dataset.lockedBy).toBe('bob')
		expect(td._metavoxLocked).toBe(true)
	})
})

describe('the dropdown in a cell', () => {
	const LAND = { field_name: 'klant', field_label: 'Land', field_type: 'select', field_options: [{ value: 'nl', label: 'Nederland' }, 'Belgie'] }
	const options = () => [...document.querySelectorAll('.metavox-select-option')].map((o) => [o.dataset.value, o.textContent])

	it('shows the labels and saves the values, with an empty choice', async () => {
		const tr = row(10, { klant: 'nl' })
		await editor.openInlineEditor(cell(tr, 'klant'), LAND)

		expect(options()).toEqual([['', '—'], ['nl', 'Nederland'], ['Belgie', 'Belgie']])
	})

	it('offers no empty choice for a required field', async () => {
		const tr = row(10, { klant: 'nl' })
		await editor.openInlineEditor(cell(tr, 'klant'), { ...LAND, is_required: true })

		expect(options()).toEqual([['nl', 'Nederland'], ['Belgie', 'Belgie']])
	})
})

describe('painting a new value over an old one', () => {
	const LINK = { field_name: 'link', field_label: 'Link', field_type: 'url' }

	it('leaves nothing of the old content', () => {
		state.setActiveColumnConfigs([KLANT, STATUS, LINK])
		const td = cell(row(10, { link: 'example.org' }), 'link')
		expect(td.querySelector('.metavox-cell-url')).not.toBeNull()

		dom.setCellValue(td, 'plain', KLANT)

		expect(td.childNodes).toHaveLength(1)
		expect(td.textContent).toBe('plain')
		dom.setCellValue(td, 'example.net', LINK)
		expect(td.querySelectorAll('.metavox-cell-url')).toHaveLength(1)
		expect(td.querySelector('.metavox-cell-url-text').textContent).toBe('example.net')
	})

	it('writes the new content at once, without clearing the cell first', () => {
		const td = cell(row(10, { klant: 'Klant 013' }), 'klant')
		let cleared = 0
		Object.defineProperty(td, 'innerHTML', { configurable: true, get: () => '', set: () => { cleared++ } })

		dom.setCellValue(td, 'Klant 014', KLANT)

		expect(cleared).toBe(0)
		expect(td.textContent).toBe('Klant 014')
	})
})
