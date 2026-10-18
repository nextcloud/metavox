// @vitest-environment happy-dom
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: {} }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
// Placeholders are filled in, as the real library does: a chip reads "Status: Draft".
vi.mock('@nextcloud/l10n', () => {
	const fill = (text, vars) => text.replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? vars[k] : m))
	return {
		translate: (app, text, vars) => fill(text, vars),
		translatePlural: (app, one, other, count, vars) => fill(count === 1 ? one : other, vars).replace(/%n/g, count),
	}
})
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/files', () => ({ getFileListFilters: () => [], registerFileListFilter: vi.fn(), unregisterFileListFilter: vi.fn() }))
vi.mock('vue', () => ({ createApp: vi.fn(), h: vi.fn() }))
vi.mock('../../src/filesplugin/columns/ViewEditorPanel.vue', () => ({ default: {} }))
vi.mock('../../src/filesplugin/columns/MetaVoxFilterElement.js', () => ({}))
vi.mock('../../src/filesplugin/columns/MetaVoxFilterPanel.vue', () => ({ default: {} }))
vi.mock('../../src/filesplugin/columns/ColumnDOM.js', () => ({
	injectHeaderColumns: vi.fn(),
	injectFooterColumns: vi.fn(),
	removeAllInjectedColumns: vi.fn(),
	updateTableMinWidth: vi.fn(),
	injectRowColumns: vi.fn(),
}))
vi.mock('../../src/filesplugin/columns/ColumnStyles.js', () => ({ injectColumnStyles: vi.fn(), MARKER_CLASS: 'metavox-cell' }))
vi.mock('../../src/filesplugin/columns/MetaVoxAPI.js', () => ({ fetchViews: vi.fn() }))
// A chip value reads by its field type; without one it shows raw.
vi.mock('../../src/filesplugin/columns/FilterOptions.js', () => ({ folderFilterValues: vi.fn(), optionLabel: (value, type) => (type ? value : `raw ${value}`) }))
vi.mock('../../src/filesplugin/columns/SaveFilters.js', () => ({ openSaveFiltersDialog: vi.fn() }))
vi.mock('../../src/filesplugin/columns/Sorting.js', () => ({
	handleSort: vi.fn(),
	updateSortIndicators: vi.fn(),
	ensureSortBypass: vi.fn(() => true),
	reorderDomRows: vi.fn(),
	setSortPending: vi.fn(),
}))
vi.mock('../../src/filesplugin/columns/MetadataLoader.js', () => ({ queueMetadataLoad: vi.fn() }))
vi.mock('../../src/filesplugin/columns/ListingSync.js', () => ({ getListingNodes: vi.fn(() => []), isListingLoading: vi.fn(() => false), listingGeneration: vi.fn(() => 1) }))

const STATUS = { id: 5, field_name: 'status', field_label: 'Status', field_type: 'select' }
const BUDGET = { id: 6, field_name: 'budget', field_label: 'Budget', field_type: 'number' }
const column = (field) => ({ field_id: field.id, field_name: field.field_name, visible: true, filterable: true })
const drafts = { id: 1, name: 'Drafts', columns: [column(STATUS), column(BUDGET)], filters: { 5: 'Draft, Review' } }
const byBudget = { id: 2, name: 'By budget', columns: [column(BUDGET)], filters: {}, sort_field: 'budget', sort_order: 'desc' }
const plain = { id: 3, name: 'Plain', columns: [column(STATUS)], filters: {} }

let views
let filter
let sorting
let files
let state

const current = () => filter.getFilterInstance()
const values = (field) => [...(current().getActiveFilters().get(field) ?? [])]

beforeEach(async () => {
	vi.resetModules()
	// The Files list's filter bar the view tabs go before; without it they
	// try again 150 ms later, after the test is gone.
	document.body.innerHTML = '<div class="files-list__filters"></div>'
	state = await import('../../src/filesplugin/columns/MetaVoxState.js')
	filter = await import('../../src/filesplugin/columns/MetadataFilter.js')
	sorting = await import('../../src/filesplugin/columns/Sorting.js')
	views = await import('../../src/filesplugin/columns/ViewManager.js')
	files = await import('@nextcloud/files')
	sorting.updateSortIndicators.mockReset()
	files.registerFileListFilter.mockReset()
	files.unregisterFileListFilter.mockReset()
	state.setActiveGroupfolderId(2)
	state.setAvailableFields([STATUS, BUDGET])
	// What opening the folder does.
	filter.registerMetaVoxFilter(views.buildDefaultFilterConfigs(), 2, state.metadataCache)
})

describe('"Default"', () => {
	it('leaves the filter as opening the folder does: registered, empty, over every field', () => {
		const before = current()
		views.applyView(byBudget)

		views.clearView()

		expect(current()).toBe(before)
		expect(files.unregisterFileListFilter).not.toHaveBeenCalled()
		expect(current().getActiveFilters().size).toBe(0)
		expect(current().getSortState()).toBeNull()
		expect(current().getFilterableConfigs().map((c) => c.field_name)).toEqual(['status', 'budget'])
	})

	it('goes into the URL without filters, and is remembered as a choice', () => {
		history.replaceState(null, '', '/apps/files/files?dir=/A&mvview=2&mvfilter=status:Draft')
		views.applyView(drafts)
		expect(state.getDefaultChosen()).toBe(false)

		views.clearView()

		const params = new URLSearchParams(location.search)
		expect([params.get('dir'), params.get('mvview'), params.has('mvfilter')]).toEqual(['/A', 'default', false])
		expect(state.getDefaultChosen()).toBe(true)
		views.applyView(plain)
		expect(state.getDefaultChosen()).toBe(false)
	})

	it('tells the Files app and clears the chips', () => {
		views.applyView(drafts)
		let told = 0
		let chips = null
		current().addEventListener('update:filter', () => told++)
		current().addEventListener('update:chips', (e) => { chips = e.detail })

		views.clearView()

		expect(told).toBeGreaterThan(0)
		expect(chips).toEqual([])
	})
})

describe('applying a view', () => {
	it('brings its filters after "Default"', () => {
		views.clearView()

		views.applyView(drafts)

		expect(values('status')).toEqual(['Draft', 'Review'])
	})

	it('filters on a field it does not show, after "Default" too, with a readable chip', () => {
		// As "Save filters as view" stores it when no view was open.
		const saved = { id: 4, name: 'Saved', columns: [], filters: { 5: 'Draft' } }
		views.clearView()
		let chips = []
		current().addEventListener('update:chips', (e) => { chips = e.detail })

		views.applyView(saved)

		expect(values('status')).toEqual(['Draft'])
		expect(chips.map((c) => c.text)).toEqual(['Status: Draft'])
		expect(files.unregisterFileListFilter).not.toHaveBeenCalled()
	})

	it('registers a filter of its own when there is none, then fills it', () => {
		filter.removeFilters()

		views.applyView(drafts)

		expect(values('status')).toEqual(['Draft', 'Review'])
	})

	it('starts from its own filters and sort, not those of the view before', () => {
		views.applyView(drafts)
		views.applyView(byBudget)

		expect(current().getActiveFilters().size).toBe(0)
		expect(current().getSortState()).toEqual({ fieldName: 'budget', fieldType: 'number', direction: 'desc' })

		views.applyView(plain)
		expect(current().getSortState()).toBeNull()
	})

	it('redraws the arrows, also for a view without a sort', () => {
		const shown = []
		sorting.updateSortIndicators.mockImplementation(() => shown.push(current()?.getSortState()?.fieldName ?? null))

		views.applyView(byBudget)
		expect(shown.at(-1)).toBe('budget')

		views.applyView(plain)
		expect(shown.at(-1)).toBeNull()
	})
})

describe('the URL of a view', () => {
	const query = () => new URLSearchParams(location.search)

	beforeEach(async () => {
		(await import('../../src/filesplugin/columns/UrlState.js'))._reset()
		history.replaceState({ key: 'files-router' }, '', '/apps/files/files?dir=/A')
	})

	it('keeps the Files router\'s own history state', () => {
		views.applyView(plain)
		expect(history.state).toMatchObject({ key: 'files-router', metavox: { view: '3', filters: null } })

		views.clearView()
		expect(history.state).toMatchObject({ key: 'files-router', metavox: { view: 'default', filters: null } })
	})

	it('comes back with a filter after the Files app dropped it', () => {
		views.applyView(plain)
		// The Viewer writes the router's own query, without ours.
		history.replaceState(history.state, '', '/apps/files/files?dir=/A&openfile=true')

		current().toggleFilterValue('status', 'Draft')

		expect([query().get('mvview'), query().get('mvfilter'), query().get('openfile')]).toEqual(['3', 'status:Draft', 'true'])
	})
})

describe('opening a team folder', () => {
	const query = () => new URLSearchParams(location.search)
	const starred = { ...drafts, is_default: true }
	const result = (list) => ({ views: list, canManage: false })

	beforeEach(async () => {
		(await import('../../src/filesplugin/columns/UrlState.js'))._reset()
		history.replaceState(null, '', '/apps/files/files?dir=/A&mvview=9')
	})

	it('follows a link to a view, before the view you were in', () => {
		views.openFolderView(result([starred, byBudget, plain]), { link: { view: '2', filters: null }, stayed: { viewId: 3 } })

		expect(state.getActiveView().id).toBe(2)
		expect(query().get('mvview')).toBe('2')
	})

	it('follows a link to "Default"', () => {
		views.openFolderView(result([starred, plain]), { link: { view: 'default', filters: null } })

		expect(state.getActiveView()).toBeNull()
		expect(state.getDefaultChosen()).toBe(true)
		expect(query().get('mvview')).toBe('default')
	})

	it('keeps "Default" chosen in the team folder', () => {
		views.openFolderView(result([starred, plain]), { stayed: { viewId: null } })

		expect(state.getActiveView()).toBeNull()
		expect(query().get('mvview')).toBe('default')
	})

	it('drops an old view from the URL when it opens none', () => {
		views.openFolderView(result([plain]), {})

		expect(state.getActiveView()).toBeNull()
		expect(query().has('mvview')).toBe(false)
	})

	it('opens a link with only filters in the starred view, with those filters', () => {
		views.openFolderView(result([starred, plain]), { link: { view: null, filters: 'status:Final' } })

		expect(state.getActiveView().id).toBe(1)
		expect(values('status')).toEqual(['Final'])
		expect([query().get('mvview'), query().get('mvfilter')]).toEqual(['1', 'status:Final'])
	})
})
