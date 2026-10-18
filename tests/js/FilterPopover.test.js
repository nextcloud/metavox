// @vitest-environment happy-dom
import { beforeEach, describe, expect, it, vi } from 'vitest'

// The filter popover on NC31/32: a click inside keeps it open, also on
// something that is gone by the time the click reaches the page, like the
// search's clear button or a value that leaves the list.
vi.mock('../../src/filesplugin/columns/MetaVoxFilterElement.js', () => ({}))
vi.mock('../../src/filesplugin/columns/MetaVoxFilterPanel.vue', () => ({ default: {} }))
vi.mock('vue', () => ({
	h: vi.fn(),
	createApp: vi.fn(() => ({
		config: { globalProperties: {} },
		// The panel: a button that removes itself when clicked.
		mount(el) {
			const vanishing = document.createElement('button')
			vanishing.id = 'vanishing'
			vanishing.addEventListener('click', () => vanishing.remove())
			el.appendChild(vanishing)
		},
		unmount: vi.fn(),
	})),
}))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, translatePlural: (app, one) => one }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/files', () => ({ getFileListFilters: vi.fn(() => []), registerFileListFilter: vi.fn(), unregisterFileListFilter: vi.fn() }))
vi.mock('../../src/filesplugin/columns/Sorting.js', () => ({ ensureSortBypass: vi.fn(() => true), reorderDomRows: vi.fn(), setSortPending: vi.fn() }))
vi.mock('../../src/filesplugin/columns/MetadataLoader.js', () => ({ queueMetadataLoad: vi.fn() }))
vi.mock('../../src/filesplugin/columns/ColumnDOM.js', () => ({ updateTableMinWidth: vi.fn() }))
vi.mock('../../src/filesplugin/columns/FilterOptions.js', () => ({ optionLabel: (value) => value }))
vi.mock('../../src/filesplugin/columns/ListingSync.js', () => ({ getListingNodes: vi.fn(() => []), isListingLoading: vi.fn(() => false), listingGeneration: vi.fn(() => 1) }))

let open

beforeEach(async () => {
	vi.resetModules()
	document.body.innerHTML = ''
	const filter = await import('../../src/filesplugin/columns/MetadataFilter.js')
	filter.registerMetaVoxFilter([{ field_name: 'status', filterable: true }], 2, new Map())
	const slot = document.createElement('div')
	document.body.appendChild(slot)
	filter.getFilterInstance().mount(slot)
	open = async () => {
		slot.querySelector('.metavox-filter-btn').click()
		// The outside-click listener is added after the opening click.
		await new Promise((resolve) => setTimeout(resolve, 0))
		return document.getElementById('vanishing').closest('div[style*="position:fixed"], div[style*="position: fixed"]')
	}
})

describe('the filter popover on NC31/32', () => {
	it('stays open on a click inside, also when the clicked element is gone by then', async () => {
		const popover = await open()

		document.getElementById('vanishing').click()

		expect(document.getElementById('vanishing')).toBeNull()
		expect(document.body.contains(popover)).toBe(true)
	})

	it('closes on a click outside', async () => {
		const popover = await open()

		document.body.click()

		expect(document.body.contains(popover)).toBe(false)
	})
})
