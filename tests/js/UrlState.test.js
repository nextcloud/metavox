// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

let url
let rawReplace
let rawPush
let rawAdd
let listeners

const query = () => new URLSearchParams(location.search)
const ours = () => [query().get('mvview'), query().get('mvfilter')]

// The Files app's router (vue-router 3): it reads the URL only on load and on
// back/forward, and navigates with its own copy of the query.
function filesRouter() {
	const router = {
		query: {},
		load(search) {
			rawReplace({ key: 'k0' }, '', '/apps/files/files' + search)
			router.query = Object.fromEntries(new URLSearchParams(search))
		},
		go(next, replace = false) {
			router.query = next
			history[replace ? 'replaceState' : 'pushState']({ key: 'k' + Object.keys(next).length }, '', '/apps/files/files?' + new URLSearchParams(next))
		},
		// openFolderAction: a fresh { dir }.
		click(dir) { router.go({ dir }) },
		// useHotKeys: { ...route.query, dir }, a step up, which the parent intercept makes a replace.
		altUp() { router.go({ ...router.query, dir: router.query.dir.replace(/\/[^/]+$/, '') || '/' }, true) },
		// The Viewer: { ...Router.query, openfile }.
		viewer() { router.go({ ...router.query, openfile: 'true' }, true) },
		// Back/forward to an entry: the browser restores its URL and state and
		// hands the state over in the event.
		back(search, { state = null, routerFirst = true } = {}) {
			rawReplace(state, '', '/apps/files/files' + search)
			if (routerFirst) router.query = Object.fromEntries(new URLSearchParams(search))
			window.dispatchEvent(new PopStateEvent('popstate', { state }))
			if (!routerFirst) router.query = Object.fromEntries(new URLSearchParams(location.search))
		},
	}
	return router
}

beforeEach(async () => {
	vi.useFakeTimers()
	vi.resetModules()
	rawReplace = history.replaceState.bind(history)
	rawPush = history.pushState.bind(history)
	// A test's module must not hear the next test's back/forward.
	listeners = []
	rawAdd = window.addEventListener
	window.addEventListener = function(type, fn, options) {
		if (type === 'popstate') listeners.push(fn)
		return rawAdd.call(window, type, fn, options)
	}
	url = await import('../../src/filesplugin/columns/UrlState.js')
})

afterEach(() => {
	history.replaceState = rawReplace
	history.pushState = rawPush
	for (const fn of listeners) window.removeEventListener('popstate', fn)
	window.addEventListener = rawAdd
	vi.useRealTimers()
})

describe('writing', () => {
	it('sets only our view and filters, and keeps the rest', () => {
		rawReplace({ key: 'files' }, '', '/apps/files/files/123?dir=/A&openfile=true&opendetails=true#top')

		url.writeUrl({ view: 4, filters: 'status:Draft' })

		expect(location.pathname).toBe('/apps/files/files/123')
		expect([query().get('dir'), query().get('openfile'), query().get('opendetails'), location.hash]).toEqual(['/A', 'true', 'true', '#top'])
		expect(ours()).toEqual(['4', 'status:Draft'])
		expect(history.state).toEqual({ key: 'files', metavox: { view: '4', filters: 'status:Draft', dir: '/A' } })

		url.writeUrl({ filters: null })
		expect(ours()).toEqual(['4', null])
	})

	it('starts empty in a folder not written yet, so an old view goes', () => {
		rawReplace(null, '', '/apps/files/files?dir=/A&mvview=1')
		url.writeUrl({ view: 2 })
		rawReplace(null, '', '/apps/files/files?dir=/A/sub&mvview=1')

		url.writeUrl({ filters: null })

		expect(ours()).toEqual([null, null])
	})
})

describe('putting ours back', () => {
	it('after the Files app wrote its own copy in the same folder', () => {
		rawReplace(null, '', '/apps/files/files?dir=/A')
		url.writeUrl({ view: 3, filters: 'status:Draft' })
		rawReplace(null, '', '/apps/files/files?dir=/A&mvview=1&openfile=true&editing=false')

		url.restoreUrl()

		expect(ours()).toEqual(['3', 'status:Draft'])
		expect([query().get('openfile'), query().get('editing')]).toEqual(['true', 'false'])
	})

	it('writes nothing more once the URL holds ours, so it settles', () => {
		rawReplace(null, '', '/apps/files/files?dir=/A')
		url.writeUrl({ view: 2, filters: 'status:Draft' })
		const writes = vi.spyOn(history, 'replaceState')

		url.restoreUrl()
		url.restoreUrl()

		expect(writes).not.toHaveBeenCalled()
		writes.mockRestore()
	})

	it('not in another folder, and not before we wrote anything', () => {
		rawReplace(null, '', '/apps/files/files?dir=/A&mvview=1')
		url.restoreUrl()
		expect(ours()).toEqual(['1', null])

		url.writeUrl({ view: 3 })
		rawReplace(null, '', '/apps/files/files?dir=/B&mvview=1')
		url.restoreUrl()
		expect(ours()).toEqual(['1', null])
	})
})

describe('following the folder', () => {
	let router
	let onFolderChange

	beforeEach(() => {
		router = filesRouter()
		onFolderChange = vi.fn()
	})

	it('Alt+Up does not follow the router\'s old copy of the view', () => {
		router.load('?dir=/A/sub&mvview=1')
		url.watchFolder(onFolderChange)
		url.takeLink()
		url.writeUrl({ view: 2 })

		router.altUp()
		vi.advanceTimersByTime(100)

		expect(query().get('dir')).toBe('/A')
		expect(onFolderChange).toHaveBeenCalledOnce()
		expect(url.takeLink()).toBeNull()
	})

	it('a click into a folder is no link', () => {
		router.load('?dir=/A&mvview=1')
		url.watchFolder(onFolderChange)

		router.click('/A/sub')
		vi.advanceTimersByTime(100)

		expect(onFolderChange).toHaveBeenCalledOnce()
		expect(url.takeLink()).toBeNull()
	})

	it.each([true, false])('back to another folder brings that page\'s view and filters (router first: %s)', (routerFirst) => {
		router.load('?dir=/A&mvview=3&mvfilter=status:Draft')
		// On popstate the router writes the route it parsed, without our parameters, before or after us.
		const routerWrite = () => history.replaceState({ key: 'r' }, '', '/apps/files/files?' + new URLSearchParams({ dir: new URLSearchParams(location.search).get('dir') }))
		if (routerFirst) window.addEventListener('popstate', routerWrite)
		const checkNow = url.watchFolder(() => {
			onFolderChange()
			// The teardown: the filter is reset, which writes the new folder empty.
			url.writeUrl({ filters: null })
		})
		if (!routerFirst) window.addEventListener('popstate', routerWrite)
		url.takeLink()
		url.writeUrl({ view: 3, filters: 'status:Draft' })
		const left = history.state
		router.click('/A/sub')
		vi.advanceTimersByTime(100)
		expect(onFolderChange).toHaveBeenCalledOnce()

		router.back('?dir=/A&mvview=3&mvfilter=status:Draft', { state: left, routerFirst })
		vi.advanceTimersByTime(100)
		checkNow()

		expect(onFolderChange).toHaveBeenCalledTimes(2)
		expect(url.takeLink()).toEqual({ view: '3', filters: 'status:Draft' })
	})

	it('reads where back/forward landed before the Files app reacts to it (NC32)', () => {
		router.load('?dir=/A&mvview=3&mvfilter=status:Draft')
		// The router hears popstate first and our filter, still on the old
		// folder's filters, writes them into the URL of the new one.
		window.addEventListener('popstate', () => url.writeUrl({ filters: 'status:Draft' }))
		url.watchFolder(onFolderChange)
		url.takeLink()
		url.writeUrl({ view: 3, filters: 'status:Draft' })
		// The subfolder's entry, as we left it: view 3, no filters.
		const sub = { key: 'k', metavox: { view: '3', filters: null, dir: '/A/sub' } }

		router.back('?dir=/A/sub&mvview=3', { state: sub })

		expect(onFolderChange).toHaveBeenCalledOnce()
		expect(url.takeLink()).toEqual({ view: '3', filters: null })
	})

	it('back or forward in the same folder keeps what you have, and the URL follows', () => {
		router.load('?dir=/A')
		url.watchFolder(onFolderChange)
		url.writeUrl({ view: 2, filters: 'status:Final' })

		router.back('?dir=/A&mvview=1')

		expect(onFolderChange).not.toHaveBeenCalled()
		expect(ours()).toEqual(['2', 'status:Final'])
	})

	it('keeps the page\'s link until a team folder takes it, once', () => {
		router.load('?dir=/A&mvview=3&mvfilter=status:Draft')
		url.holdLink()
		const check = url.watchFolder(onFolderChange)

		check()
		check()

		expect(url.takeLink()).toEqual({ view: '3', filters: 'status:Draft' })
		expect(url.takeLink()).toBeNull()
	})

	it('hands the page\'s link only to the folder it was for', () => {
		router.load('?dir=/A&mvview=3')
		url.holdLink()
		// Another folder, before the watcher started.
		rawReplace(null, '', '/apps/files/files?dir=/B')

		expect(url.takeLink()).toBeNull()
	})

	it('drops the page\'s link when the router changes folder before it is taken', () => {
		router.load('?dir=/A&mvview=3')
		url.holdLink()
		url.watchFolder(onFolderChange)

		router.click('/B')
		vi.advanceTimersByTime(100)

		expect(url.takeLink()).toBeNull()
	})

	it('puts ours back after the Viewer, so a reload opens the same', () => {
		router.load('?dir=/A&mvview=1')
		url.watchFolder(onFolderChange)
		url.writeUrl({ view: 2, filters: 'status:Draft' })

		router.viewer()
		expect(ours()).toEqual(['1', null])
		vi.advanceTimersByTime(100)

		expect(onFolderChange).not.toHaveBeenCalled()
		expect(query().get('openfile')).toBe('true')
		expect(url.readLink()).toEqual({ view: '2', filters: 'status:Draft' })
	})
})
