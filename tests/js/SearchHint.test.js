// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

// The provider's "too many matches" line links to a hash; following it, by
// click or Enter, opens the filter picker here.
const picked = { field: 'file_gf_status', value: 'Concept', label: 'Status: Concept', groupfolderId: 2 }
const openFilterPicker = vi.fn(() => Promise.resolve(picked))
const applyFilter = vi.fn()
vi.mock('@nextcloud/router', () => ({ imagePath: () => '/filter.svg' }))
vi.mock('../../src/search/FilterPicker.js', () => ({ openFilterPicker: (...a) => openFilterPicker(...a) }))
vi.mock('../../src/search/searchQuery.js', () => ({ FILTER_ID: 'metavox_metadata', applyFilter: (...a) => applyFilter(...a) }))

let main

beforeEach(async () => {
	vi.resetModules()
	openFilterPicker.mockClear()
	applyFilter.mockClear()
	window.OCA = { UnifiedSearch: { registerFilterAction: vi.fn() } }
	window.history.replaceState(null, '', '/apps/files/?dir=/HR')
	main = await import('../../src/search/search-main.js')
})

afterEach(() => {
	window.removeEventListener('hashchange', main.onNarrowLink)
	delete window.OCA
})

const follow = async (hash) => {
	window.location.hash = hash
	main.onNarrowLink()
	await Promise.resolve()
	await Promise.resolve()
}

describe('the hint below too common words', () => {
	it('links to the hash the provider sends', () => {
		expect(main.NARROW_LINK).toBe('#metavox-filter-by-field')
	})

	it('opens the picker, and its choice becomes the filter', async () => {
		await follow(main.NARROW_LINK)

		// The picker loads on demand.
		await vi.waitFor(() => expect(applyFilter).toHaveBeenCalledWith(picked))
		expect(openFilterPicker).toHaveBeenCalledTimes(1)
	})

	it('leaves the page as it was, without the hash', async () => {
		await follow(main.NARROW_LINK)

		expect(window.location.pathname + window.location.search + window.location.hash).toBe('/apps/files/?dir=/HR')
	})

	it('ignores any other hash', async () => {
		await follow('#section-2')

		expect(openFilterPicker).not.toHaveBeenCalled()
	})

	it('is the same picker as the filter in the search bar', async () => {
		window.OCA.UnifiedSearch.registerFilterAction.mock.calls[0][0].callback()

		await vi.waitFor(() => expect(openFilterPicker).toHaveBeenCalledTimes(1))
	})
})
