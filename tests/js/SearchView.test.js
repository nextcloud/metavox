import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/files', () => {
	class Node {
		constructor(data) {
			Object.assign(this, data)
			this.fileid = data.id
			this.attributes = data.attributes ?? {}
		}
	}
	return {
		View: class { constructor(view) { Object.assign(this, view) } },
		Folder: class extends Node { get type() { return 'folder' } },
		Permission: { NONE: 0, READ: 1 },
		FileType: { Folder: 'folder', File: 'file' },
		DefaultType: { DEFAULT: 'default', HIDDEN: 'hidden' },
		getNavigation: vi.fn(),
		registerFileAction: vi.fn(),
	}
})
vi.mock('@nextcloud/files/dav', () => ({
	getClient: () => ({ getDirectoryContents: (...args) => globalThis.report(...args) }),
	getDavNameSpaces: () => 'xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns" xmlns:oc="http://owncloud.org/ns"',
	getDavProperties: () => '<d:getetag /><oc:fileid />',
	getDefaultPropfind: () => '<d:propfind/>',
	getRemoteURL: () => 'https://cloud.example/remote.php/dav',
	getRootPath: () => '/files/alice',
	resultToNode: (stat) => ({ stat }),
}))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() } }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'alice' }) }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => vars[k]) }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('../../src/filesplugin/searchView.svg', () => ({ default: '<svg/>' }))
vi.mock('@nextcloud/initial-state', () => ({ loadState: (app, key, fallback) => (app === 'metavox' && key === 'search-folders' ? globalThis.foldersOn : fallback) }))

const SEARCHES = [
	{ id: 3, name: 'Status: Concept', field: 'file_gf_status', value: 'Concept', groupfolderId: 7 },
	{ id: 9, name: 'Tags: Contract', field: 'file_gf_tags', value: 'Contract', groupfolderId: null },
]
const BASE = 2 ** 52

let files
let axios
let toast
let bus
let navigation
let view
let service

function fakeNavigation() {
	return {
		views: [],
		register(...views) {
			for (const v of views) {
				if (this.views.some((w) => w.id === v.id)) throw new Error(`IView id ${v.id} is already registered`)
				this.views.push(v)
			}
		},
		remove(id) {
			this.views = this.views.filter((v) => v.id !== id)
		},
	}
}

beforeEach(async () => {
	vi.resetModules()
	vi.stubGlobal('window', { OC: { config: { version: '35.0.0.10' } } })
	globalThis.foldersOn = true
	files = await import('@nextcloud/files')
	axios = (await import('@nextcloud/axios')).default
	bus = await import('@nextcloud/event-bus')
	for (const mock of [files.registerFileAction, axios.get, axios.post, axios.delete, bus.emit]) mock.mockReset()
	toast = vi.fn()
	window.OCP = { Toast: { info: toast } }
	navigation = fakeNavigation()
	files.getNavigation.mockReturnValue(navigation)
	axios.get.mockResolvedValue({ data: { searches: SEARCHES } })
	globalThis.report = vi.fn().mockResolvedValue({ data: [{ filename: '/HR/a.pdf' }, { filename: '/Sales/b.txt' }], headers: {} })
	view = await import('../../src/filesplugin/searchView.js')
	service = await import('../../src/filesplugin/searchService.js')
})

afterEach(() => {
	vi.unstubAllGlobals()
	delete globalThis.report
	delete globalThis.foldersOn
})

const ourView = () => navigation.views.find((v) => v.id === 'metavox-searches')
const action = (id) => files.registerFileAction.mock.calls.map((c) => c[0]).find((a) => a.id === id)

describe('registerSearchView', () => {
	it('adds the entry right after Tags, with its two actions', () => {
		expect(view.registerSearchView()).toBe(true)

		expect(ourView()).toMatchObject({ name: 'Metadata searches', order: 26 })
		expect(ourView().columns.map((c) => c.id)).toEqual(['location'])
		expect(files.registerFileAction.mock.calls.map((c) => c[0].id)).toEqual(['metavox:searches-open-in-files', 'metavox:delete-saved-search'])
	})

	it('only once', () => {
		view.registerSearchView()
		expect(view.registerSearchView()).toBe(true)

		expect(navigation.views).toHaveLength(1)
		expect(files.registerFileAction).toHaveBeenCalledTimes(2)
	})

	it.each([false, undefined, 'true'])('stays out when the admin did not turn folders on (%s)', (on) => {
		globalThis.foldersOn = on

		expect(view.registerSearchView()).toBe(false)
		expect(navigation.views).toEqual([])
		expect(files.registerFileAction).not.toHaveBeenCalled()
	})

	it.each(['32.0.5.1', '31.0.9', ''])('stays out on Nextcloud %s', (version) => {
		window.OC.config.version = version

		expect(view.registerSearchView()).toBe(false)
		expect(navigation.views).toEqual([])
		expect(files.registerFileAction).not.toHaveBeenCalled()
	})
})

describe('the saved searches', () => {
	it('are folders with ids above every real file', async () => {
		view.registerSearchView()
		const { folder, contents } = await ourView().getContents('/')

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/metavox/api/user/metadata-searches', { signal: undefined })
		expect(folder.fileid).toBe(0)
		expect(contents.map((n) => [n.fileid, n.displayname, n.permissions, n.attributes['metavox-search']]))
			.toEqual([[BASE + 3, 'Status: Concept', 1, 3], [BASE + 9, 'Tags: Contract', 1, 9]])
		expect(contents[0].source).toBe('https://cloud.example/remote.php/dav/metavox-searches/3')
		expect(globalThis.report).not.toHaveBeenCalled()
	})

	it('each get an entry under ours, and a removed one goes', async () => {
		view.registerSearchView()
		await ourView().getContents('/')

		const children = () => navigation.views.filter((v) => v.parent === 'metavox-searches')
		expect(children().map((v) => [v.id, v.name, v.params])).toEqual([
			['metavox-searches-3', 'Status: Concept', { view: 'metavox-searches', dir: '/3' }],
			['metavox-searches-9', 'Tags: Contract', { view: 'metavox-searches', dir: '/9' }],
		])

		axios.get.mockResolvedValue({ data: { searches: [SEARCHES[1]] } })
		await ourView().getContents('/')
		expect(children().map((v) => v.id)).toEqual(['metavox-searches-9'])
	})

	it('load as entries when ours is opened in the navigation', async () => {
		view.registerSearchView()
		await ourView().loadChildViews()

		expect(navigation.views.filter((v) => v.parent === 'metavox-searches')).toHaveLength(2)
	})
})

describe('a saved search', () => {
	it('asks the server for its files with a REPORT on the user\'s files', async () => {
		view.registerSearchView()
		const { folder, contents } = await ourView().getContents('/3', { signal: 'stop' })

		const [path, options] = globalThis.report.mock.calls[0]
		expect(path).toBe('/files/alice')
		expect(options).toMatchObject({ details: true, headers: { method: 'REPORT' }, signal: 'stop' })
		expect(options.data).toContain('<nc:metavox-search xmlns:d="DAV:"')
		expect(options.data).toContain('<d:prop><d:getetag /><oc:fileid /></d:prop>')
		expect(options.data).toContain('<nc:search-id>3</nc:search-id>')
		expect(folder.fileid).toBe(BASE + 3)
		expect(folder.displayname).toBe('Status: Concept')
		expect(contents).toEqual([{ stat: { filename: '/HR/a.pdf' } }, { stat: { filename: '/Sales/b.txt' } }])
	})

	it('says once when it shows only the newest files', async () => {
		globalThis.report.mockResolvedValue({ data: [{ filename: '/HR/a.pdf' }], headers: { 'x-metavox-more': '1' } })
		await service.getContents('/3')
		await service.getContents('/3')

		expect(toast).toHaveBeenCalledOnce()
		expect(toast.mock.calls[0][0]).toBe('Showing the newest 1 files. Make the search narrower to see the rest.')
	})

	it('lets Nextcloud drop a fetch it cancelled, as with its own views', async () => {
		const controller = new AbortController()
		axios.get.mockImplementation(() => {
			controller.abort()
			return Promise.reject(Object.assign(new Error('canceled'), { name: 'CanceledError' }))
		})

		await expect(service.getContents('/3', { signal: controller.signal })).rejects.toMatchObject({ name: 'AbortError' })
		expect(globalThis.report).not.toHaveBeenCalled()
	})

	it('keeps other failures as they are', async () => {
		axios.get.mockRejectedValue(Object.assign(new Error('Request failed with status code 500'), { name: 'AxiosError' }))

		await expect(service.getContents('/3', { signal: new AbortController().signal })).rejects.toMatchObject({ name: 'AxiosError' })
	})

	it('lists a real folder among the results, for a drop on it', async () => {
		globalThis.report.mockResolvedValue({ data: [{ filename: '/HR/Projects' }, { filename: '/HR/Projects/a.pdf' }] })
		const { folder, contents } = await service.getContents('/HR/Projects', { signal: 'stop' })

		const [path, options] = globalThis.report.mock.calls[0]
		expect(path).toBe('/files/alice/HR/Projects')
		expect(options).toMatchObject({ details: true, includeSelf: true, data: '<d:propfind/>', signal: 'stop' })
		expect(folder).toEqual({ stat: { filename: '/HR/Projects' } })
		expect(contents).toEqual([{ stat: { filename: '/HR/Projects/a.pdf' } }])
	})

	it.each(['/4', '/abc', '/3 '])('that is not theirs or not a search: %s', async (path) => {
		await expect(service.getContents(path)).rejects.toThrow('This saved search does not exist.')
		expect(globalThis.report).not.toHaveBeenCalled()
	})
})

describe('the actions', () => {
	beforeEach(() => view.registerSearchView())

	const inView = (node, id = 'metavox-searches') => ({ nodes: [node], view: { id } })

	it('delete a saved search, its entry too', async () => {
		await ourView().getContents('/')
		const node = service.searchToNode(SEARCHES[0])
		const del = action('metavox:delete-saved-search')

		expect(del.enabled(inView(node))).toBe(true)
		expect(await del.exec(inView(node))).toBe(true)
		expect(axios.delete).toHaveBeenCalledWith('/index.php/apps/metavox/api/user/metadata-searches/3')
		expect(bus.emit).toHaveBeenCalledWith('files:node:deleted', node)
		expect(navigation.views.map((v) => v.id)).not.toContain('metavox-searches-3')
	})

	it('keep it when the server says no', async () => {
		axios.delete.mockRejectedValue(new Error('404'))
		const node = service.searchToNode(SEARCHES[0])

		expect(await action('metavox:delete-saved-search').exec(inView(node))).toBe(false)
		expect(bus.emit).not.toHaveBeenCalled()
	})

	it('delete only saved searches, only here', () => {
		const del = action('metavox:delete-saved-search')
		const result = { type: 'folder', attributes: {} }

		expect(del.enabled(inView(result))).toBe(false)
		expect(del.enabled(inView(service.searchToNode(SEARCHES[0]), 'files'))).toBe(false)
	})

	it('open a found folder where it is, a saved search not', () => {
		const open = action('metavox:searches-open-in-files')
		const found = { type: 'folder', attributes: {} }

		expect(open.enabled(inView(found))).toBe(true)
		expect(open.enabled(inView({ type: 'file', attributes: {} }))).toBe(false)
		expect(open.enabled(inView(service.searchToNode(SEARCHES[0])))).toBe(false)
		expect(open.enabled(inView(found, 'files'))).toBe(false)
	})
})

describe('the location column', () => {
	it('shows where a file is, nothing for a saved search', () => {
		vi.stubGlobal('document', { createElement: () => ({ textContent: '' }) })
		view.registerSearchView()
		const column = ourView().columns[0]

		expect(column.render({ dirname: '/HR/Contracten', attributes: {} }).textContent).toBe('/HR/Contracten')
		// Nextcloud 33.0.0 to 33.0.3 pass a context.
		expect(column.render({ nodes: [{ dirname: '/HR/Oud', attributes: {} }], view: {} }).textContent).toBe('/HR/Oud')
		expect(column.render(service.searchToNode(SEARCHES[0])).textContent).toBe('')
		expect(column.sort({ dirname: '/B' }, { dirname: '/A' })).toBeGreaterThan(0)
	})
})
