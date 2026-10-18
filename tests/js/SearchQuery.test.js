// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { applyFilter, filterEvent, setSearchQuery } from '../../src/search/searchQuery.js'

// Nextcloud's search box per version, as its markup renders it.
const MARKUP = {
	'NC35': '<div class="unified-search-menu"><search class="unified-search-input"><input class="unified-search-input__input" role="combobox"></search></div>',
	'NC35, small screen': '<div class="unified-search-modal__mobile-input"><div class="input-field"><input type="text"></div></div>',
	'NC31–34': '<div id="unified-search" role="dialog"><div class="unified-search__input"><input type="text"></div></div>',
	'the search bar in an app': '<div class="local-unified-search__input"><input type="text"></div>',
}

const PICKED = { field: 'file_gf_tags', value: 'Rapport;#Notulen', label: 'Tags: Rapport, Notulen', groupfolderId: 2 }

// Nextcloud's own messages: OCP.Toast everywhere, OC.Notification only before NC34.
beforeEach(() => {
	document.body.innerHTML = ''
	// A new page: @nextcloud/initial-state 3 keeps what it read in this map.
	delete window._nc_initial_state
	window._nc_event_bus = { emit: vi.fn() }
	window.OCP = { Toast: { warning: vi.fn() } }
	window.OC = { Notification: { showTemporary: vi.fn() } }
})

afterEach(() => {
	delete window._nc_event_bus
	delete window.OCP
	delete window.OC
})

// An admin can raise the shortest search Nextcloud sends (unified-search/min-search-length).
const minimum = (n) => {
	const input = document.createElement('input')
	input.type = 'hidden'
	input.id = 'initial-state-unified-search-min-search-length'
	input.value = btoa(JSON.stringify(n))
	document.body.appendChild(input)
}

describe('the search box', () => {
	it.each(Object.entries(MARKUP))('is found and filled on %s', (version, html) => {
		document.body.innerHTML = html
		const input = document.querySelector('input')
		const typed = vi.fn()
		input.addEventListener('input', typed)

		expect(setSearchQuery('Rapport')).toBe(true)

		expect(input.value).toBe('Rapport')
		expect(typed).toHaveBeenCalledOnce()
	})

	it('is not guessed when there is none', () => {
		document.body.innerHTML = '<input type="text" class="something-else">'

		expect(setSearchQuery('Rapport')).toBe(false)
		expect(document.querySelector('input').value).toBe('')
	})
})

describe('applying a filter', () => {
	it('fills the box with the first value, then adds the filter for that team folder', () => {
		document.body.innerHTML = MARKUP.NC35

		applyFilter(PICKED)

		expect(document.querySelector('input').value).toBe('Rapport')
		expect(window._nc_event_bus.emit).toHaveBeenCalledWith('nextcloud:unified-search:add-filter', {
			id: 'metavox_metadata',
			filterUpdateText: 'Tags: Rapport, Notulen',
			filterParams: { metavox_field: 'file_gf_tags:Rapport;#Notulen', metavox_groupfolder: '2' },
		})
		expect(window.OCP.Toast.warning).not.toHaveBeenCalled()
	})

	it('says to type a word when the box cannot be filled, instead of showing nothing', () => {
		applyFilter(PICKED)

		expect(window._nc_event_bus.emit).toHaveBeenCalledOnce()
		expect(window.OCP.Toast.warning).toHaveBeenCalledWith('Type a word in the search box to see the results of the filter.')
	})

	it('says so through OC.Notification where there is no OCP.Toast', () => {
		delete window.OCP

		applyFilter(PICKED)

		expect(window.OC.Notification.showTemporary).toHaveBeenCalledOnce()
	})

	it('fills the box with the chip text when the first value is shorter than Nextcloud\'s minimum', () => {
		document.body.innerHTML = MARKUP.NC35
		minimum(9)

		applyFilter(PICKED)

		expect(document.querySelector('input:not([type="hidden"])').value).toBe('Tags: Rapport, Notulen')
		expect(window.OCP.Toast.warning).not.toHaveBeenCalled()
	})

	it('says to type a word when even that is too short', () => {
		document.body.innerHTML = MARKUP.NC35
		minimum(40)

		applyFilter(PICKED)

		expect(window.OCP.Toast.warning).toHaveBeenCalledOnce()
	})

	it('leaves the team folder out when none was picked', () => {
		expect(filterEvent({ ...PICKED, groupfolderId: null }).filterParams).toEqual({ metavox_field: 'file_gf_tags:Rapport;#Notulen' })
	})
})
