// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

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
	getListingGroupfolderId: () => 2, getListingNode: vi.fn(() => null), isListingLoading: () => true,
}))
vi.mock('../../src/filesplugin/columns/MetadataStore.js', () => ({
	isTruncated: vi.fn(() => false), hasNoTeamFolder: vi.fn(() => false), seedFromNode: vi.fn(),
}))

const DEADLINE = {
	field_name: 'deadline',
	field_label: 'Deadline',
	field_type: 'date',
	format: [
		{ op: 'before', value: { today: 0 }, tone: 'error', preset: 'overdue' },
		{ op: 'nextDays', value: 7, tone: 'warning', label: 'Binnenkort' },
		{ op: 'empty', tone: 'info' },
	],
}
const STATUS = { field_name: 'status', field_label: 'Status', field_type: 'text', format: [{ op: 'contains', value: 'geheim', tone: 'error' }, { op: '!empty', tone: 'success' }] }
const OWNER = { field_name: 'owner', field_label: 'Owner', field_type: 'user', format: [{ op: 'is', value: { me: true }, tone: 'info', preset: 'assignedToViewer' }] }
const PLAIN = { field_name: 'klant', field_label: 'Klant', field_type: 'text' }

let dom
let editor
let format
let state
let store
let listing
let dates

beforeEach(async () => {
	vi.resetModules()
	vi.clearAllMocks()
	document.body.innerHTML = '<table class="files-list"><thead><tr></tr></thead><tbody></tbody></table>'
	navigator.sendBeacon = vi.fn(() => true)
	dates = await import('../../src/utils/dateField.js')
	dates._setClock(() => new Date(2026, 9, 6, 12, 0))
	state = await import('../../src/filesplugin/columns/MetaVoxState.js')
	store = await import('../../src/filesplugin/columns/MetadataStore.js')
	listing = await import('../../src/filesplugin/columns/ListingSync.js')
	editor = await import('../../src/filesplugin/columns/InlineEditor.js')
	dom = await import('../../src/filesplugin/columns/ColumnDOM.js')
	format = await import('../../src/filesplugin/columns/CellFormat.js')
	state.metadataCache.clear()
	state.permissionCache.clear()
	state.setActiveGroupfolderId(2)
	state.setActiveColumnConfigs([DEADLINE, STATUS, OWNER, PLAIN])
	// The wiring MetaVoxColumns does at startup.
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
})

afterEach(() => {
	dates._setClock(null)
})

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
const icon = (td) => td.querySelector(':scope > .mv-tone-icon')

describe('a highlighted cell', () => {
	it('gets the tone of the first rule its value matches', () => {
		const late = cell(row(10, { deadline: '2026-10-01' }), 'deadline')
		const soon = cell(row(11, { deadline: '2026-10-08' }), 'deadline')
		const none = cell(row(12, { deadline: '2027-01-01' }), 'deadline')

		expect(late.dataset.mvTone).toBe('error')
		expect(soon.dataset.mvTone).toBe('warning')
		expect(none.dataset.mvTone).toBeUndefined()
		expect(icon(none)).toBeNull()
	})

	it('shows an icon with the name of the state, and keeps its text the value', () => {
		const td = cell(row(10, { deadline: '2026-10-08' }), 'deadline')

		expect(icon(td).getAttribute('role')).toBe('img')
		expect(icon(td).getAttribute('aria-label')).toBe('Binnenkort')
		// Pointing at the icon shows it too.
		expect(icon(td).title).toBe('Binnenkort')
		expect(icon(td).querySelector('svg').getAttribute('aria-hidden')).toBe('true')
		expect(icon(td).textContent).toBe('')
		expect(td.textContent).toMatch(/2026/)
		expect(td.textContent).not.toContain('Binnenkort')
	})

	it('names a preset by its name, and the viewer as "you"', () => {
		expect(cell(row(10, { deadline: '2026-10-01' }), 'deadline').dataset.mvNote).toBe('Overdue')
		expect(cell(row(11, { owner: 'alice' }), 'owner').dataset.mvNote).toBe('Assigned to you')
		expect(cell(row(12, { owner: 'bob' }), 'owner').dataset.mvTone).toBeUndefined()
	})

	it('tells a screen reader the state, with or without the edit hint', () => {
		const editable = cell(row(10, { deadline: '2026-10-01' }), 'deadline')
		const readOnly = cell(row(11, { deadline: '2026-10-01' }, false), 'deadline')

		expect(editable.getAttribute('aria-label')).toMatch(/^Deadline: .+, Overdue\. Press Enter to edit\.$/)
		expect(readOnly.getAttribute('aria-label')).toMatch(/^Deadline: .+, Overdue\.$/)
	})

	it('keeps a column without rules as it was', () => {
		const td = cell(row(10, { klant: 'Klant 013' }), 'klant')

		expect(td.dataset.mvTone).toBeUndefined()
		expect(td.getAttribute('aria-label')).toBe('Klant: Klant 013. Press Enter to edit.')
	})

	it('reads names with & and < as they are', () => {
		const config = { ...STATUS, format: [{ op: '!empty', tone: 'success', label: 'R&D <klaar>' }] }
		state.setActiveColumnConfigs([config])
		const td = cell(row(10, { status: 'x' }), 'status')

		expect(icon(td).getAttribute('aria-label')).toBe('R&D <klaar>')
		expect(icon(td).title).toBe('R&D <klaar>')
		expect(td.getAttribute('aria-label')).toContain('R&D <klaar>')
	})

	it('changes the text by the icon with the rule, in the same colour', () => {
		const config = { ...STATUS, format: [{ op: '!empty', tone: 'success', label: 'Nog goedkeuren' }] }
		state.setActiveColumnConfigs([config])
		const td = cell(row(10, { status: 'x' }), 'status')
		expect(icon(td).title).toBe('Nog goedkeuren')

		format.applyCellFormat(td, 'x', { ...config, format: [{ op: '!empty', tone: 'success', label: 'Klaar' }] })

		expect(icon(td).title).toBe('Klaar')
		expect(icon(td).getAttribute('aria-label')).toBe('Klaar')
	})
})

describe('what is not highlighted', () => {
	it('a folder without a value, like a file', () => {
		listing.getListingNode.mockImplementation((id) => (id === 20 ? { type: 'folder' } : null))

		expect(cell(row(20, {}), 'deadline').dataset.mvTone).toBe('info')
		expect(cell(row(21, {}), 'deadline').dataset.mvTone).toBe('info')
	})

	it('a mount of another storage, which has no values of ours', () => {
		store.hasNoTeamFolder.mockImplementation((id) => id === 30)

		expect(cell(row(30, {}), 'deadline').dataset.mvTone).toBeUndefined()
	})

	it('a cut-off value a rule cannot read, and no rule below it either', () => {
		store.isTruncated.mockImplementation((id, field) => id === 40 && field === 'status')

		const td = cell(row(40, { status: 'Lange tekst' }), 'status')

		expect(td.dataset.mvTone).toBeUndefined()
	})

	it('a cut-off checkbox, even one that starts like a tick', () => {
		const DONE = { field_name: 'done', field_label: 'Done', field_type: 'checkbox', format: [{ op: 'isTrue', tone: 'success' }, { op: 'isFalse', tone: 'warning' }] }
		state.setActiveColumnConfigs([DEADLINE, STATUS, OWNER, PLAIN, DONE])
		store.isTruncated.mockImplementation((id, field) => id === 41 && field === 'done')

		expect(cell(row(41, { done: '1' }), 'done').dataset.mvTone).toBeUndefined()
		expect(cell(row(42, { done: '1' }), 'done').dataset.mvTone).toBe('success')
	})

	it('a row still loading', () => {
		const td = cell(row(50, null), 'deadline')

		expect(td.textContent).toBe('…')
		expect(td.dataset.mvTone).toBeUndefined()
	})
})

describe('the highlight follows the cell', () => {
	it('when the value changes', () => {
		const tr = row(10, { deadline: '2026-10-01' })
		state.metadataCache.set(10, { deadline: '2026-12-01' })

		dom.updateAllRowCells()

		expect(cell(tr, 'deadline').dataset.mvTone).toBeUndefined()
		expect(icon(cell(tr, 'deadline'))).toBeNull()
	})

	it('when the row is reused for another file while scrolling', () => {
		const tr = row(10, { deadline: '2026-10-01' })
		state.metadataCache.set(11, { deadline: '2026-10-08' })
		tr.setAttribute('data-cy-files-list-row-fileid', '11')

		dom._refreshRowCells(tr)

		expect(cell(tr, 'deadline').dataset.mvTone).toBe('warning')
		expect(cell(tr, 'deadline').querySelectorAll('.mv-tone-icon')).toHaveLength(1)
	})

	it('when the reused row has no values yet', () => {
		const tr = row(10, { deadline: '2026-10-01' })
		tr.setAttribute('data-cy-files-list-row-fileid', '12')

		dom._refreshRowCells(tr)

		expect(cell(tr, 'deadline').dataset.mvTone).toBeUndefined()
		expect(cell(tr, 'deadline').getAttribute('aria-label')).not.toContain('Overdue')
	})

	it('is gone while the cell is edited, and back when the editor closes', async () => {
		const td = cell(row(10, { deadline: '2026-10-01' }), 'deadline')

		await editor.openInlineEditor(td, DEADLINE)
		expect(td.dataset.mvTone).toBeUndefined()
		expect(icon(td)).toBeNull()

		dom.updateAllRowCells()
		expect(td.dataset.mvTone).toBeUndefined()

		editor.closeInlineEditor(true)
		expect(td.dataset.mvTone).toBe('error')
	})

	it('moves with the day, without touching the values', () => {
		const tr = row(10, { deadline: '2026-10-07' })
		const td = cell(tr, 'deadline')
		const text = td.textContent
		expect(td.dataset.mvTone).toBe('warning')

		dates._setClock(() => new Date(2026, 9, 8, 0, 5))
		format.refreshCellFormats()

		expect(td.dataset.mvTone).toBe('error')
		expect(td.textContent).toBe(text)
		expect(td.getAttribute('aria-label')).toContain('Overdue')
	})
})

describe('a new day', () => {
	it('repaints once the day changed, and only then', () => {
		const td = cell(row(10, { deadline: '2026-10-07' }), 'deadline')
		expect(td.dataset.mvTone).toBe('warning')

		expect(format.checkDayChange()).toBe(false)

		dates._setClock(() => new Date(2026, 9, 8, 0, 1))
		expect(format.checkDayChange()).toBe(true)
		expect(td.dataset.mvTone).toBe('error')
		expect(format.checkDayChange()).toBe(false)
	})

	it('does nothing before anything was painted', () => {
		state.setActiveColumnConfigs([PLAIN])
		row(10, { klant: 'x' })
		dates._setClock(() => new Date(2026, 9, 9))

		expect(format.checkDayChange()).toBe(false)
	})

	describe('watched', () => {
		afterEach(() => vi.useRealTimers())

		it('by one timer, just after midnight', () => {
			vi.useFakeTimers({ now: new Date(2026, 9, 7, 23, 59, 30) })
			dates._setClock(() => new Date())
			const td = cell(row(10, { deadline: '2026-10-07' }), 'deadline')
			const before = vi.getTimerCount()

			format.watchDayChange()
			expect(vi.getTimerCount()).toBe(before + 1)
			vi.advanceTimersByTime(29_000)
			expect(td.dataset.mvTone).toBe('warning')
			vi.advanceTimersByTime(2_000)

			expect(td.dataset.mvTone).toBe('error')
			expect(vi.getTimerCount()).toBe(before + 1) // the next midnight
		})

		it('at the first click after a sleep, when that timer is late', () => {
			vi.useFakeTimers({ now: new Date(2026, 9, 7, 18, 0) })
			dates._setClock(() => new Date())
			const td = cell(row(10, { deadline: '2026-10-07' }), 'deadline')
			format.watchDayChange()

			// The clock moved on, the timer did not.
			vi.setSystemTime(new Date(2026, 9, 8, 9, 0))
			expect(td.dataset.mvTone).toBe('warning')
			document.body.dispatchEvent(new Event('pointerdown', { bubbles: true }))

			expect(td.dataset.mvTone).toBe('error')
		})
	})
})

describe('the column head', () => {
	it('marks a column with rules, and names them for a screen reader', () => {
		document.querySelector('table').classList.add('files-list__table')
		dom.injectHeaderColumns()

		const th = document.querySelector('th[data-metavox-field="deadline"]')
		const button = th.querySelector('button')
		const description = document.getElementById(button.getAttribute('aria-describedby'))
		expect(th.querySelector('.metavox-col-rules-icon').getAttribute('aria-hidden')).toBe('true')
		expect(description.textContent).toBe('Highlights: Overdue, Binnenkort, is empty')
		// Not part of the column's own name, and not built from the field name.
		expect(description.hidden).toBe(true)
		expect(description.id).not.toContain('deadline')
		expect(document.querySelector('th[data-metavox-field="klant"] .metavox-col-rules-icon')).toBeNull()
		expect(document.querySelector('th[data-metavox-field="klant"] button').hasAttribute('aria-describedby')).toBe(false)
	})

	it('describes a field with a space in its name', () => {
		state.setActiveColumnConfigs([{ ...STATUS, field_name: 'due date' }])
		document.querySelector('table').classList.add('files-list__table')
		dom.injectHeaderColumns()

		const button = document.querySelector('th[data-metavox-field="due date"] button')
		expect(document.getElementById(button.getAttribute('aria-describedby'))?.textContent).toContain('Highlights:')
	})

	it('makes room for the sign when the name sets the width, in the head and in every cell', () => {
		// A name this long is wider than the type's default width.
		const label = 'Datum einde contract'
		const ruled = { ...DEADLINE, field_label: label }
		const plain = { ...DEADLINE, field_name: 'plain', field_label: label, format: undefined }
		state.setActiveColumnConfigs([ruled, plain])
		document.querySelector('table').classList.add('files-list__table')
		dom.injectHeaderColumns()
		const tr = row(10, { deadline: '2026-10-01', plain: '2026-10-01' })

		const width = (el) => parseInt(el.style.width, 10)
		const head = (field) => document.querySelector(`th[data-metavox-field="${field}"]`)
		expect(width(head('deadline')) - width(head('plain'))).toBe(20)
		expect(width(cell(tr, 'deadline'))).toBe(width(head('deadline')))
	})
})
