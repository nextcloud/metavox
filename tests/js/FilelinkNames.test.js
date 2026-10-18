import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/files/dav', () => ({ getClient: vi.fn(), getRootPath: () => '/files/admin' }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url, params) => url.replace(/\{(\w+)\}/g, (m, k) => params[k]) }))

let names
let search
const idsIn = (call) => [...call[1].data.matchAll(/<d:literal>(\d+)<\/d:literal>/g)].map((m) => Number(m[1]))
const answer = (found) => ({ data: { results: Object.entries(found).map(([id, name]) => ({ basename: name, props: { fileid: Number(id) } })) } })

beforeEach(async () => {
	vi.resetModules()
	vi.useFakeTimers()
	vi.spyOn(console, 'error').mockImplementation(() => {})
	search = vi.fn().mockResolvedValue(answer({}))
	const dav = await import('@nextcloud/files/dav')
	dav.getClient.mockReset().mockReturnValue({ search })
	names = await import('../../src/filesplugin/columns/FilelinkNames.js')
})

afterEach(() => {
	vi.useRealTimers()
	vi.restoreAllMocks()
})

describe('buildSearch', () => {
	it('searches the user\'s files on file id, one condition per id', () => {
		const xml = names.buildSearch('/files/admin', [12, 34])

		expect(xml).toContain('<d:href>/files/admin/</d:href><d:depth>infinity</d:depth>')
		expect(xml).toContain('<d:or><d:eq><d:prop><oc:fileid/></d:prop><d:literal>12</d:literal></d:eq>')
		expect(xml).toContain('<d:literal>34</d:literal>')
	})

	it('needs no <d:or> for one id and only writes numbers', () => {
		const xml = names.buildSearch('/files/admin', ['7</d:literal><x>'])

		expect(xml).not.toContain('<d:or>')
		expect(xml).toContain('<d:literal>NaN</d:literal>')
		expect(xml).not.toContain('<x>')
	})
})

describe('requestFilelinkName', () => {
	it('bundles the cells drawn at the same moment into one search', async () => {
		names.requestFilelinkName(1)
		names.requestFilelinkName('2')
		names.requestFilelinkName(3)
		await vi.runAllTimersAsync()

		expect(search).toHaveBeenCalledOnce()
		expect(idsIn(search.mock.calls[0])).toEqual([1, 2, 3])
	})

	it('splits into batches of at most 90 ids', async () => {
		for (let id = 1; id <= 200; id++) names.requestFilelinkName(id)
		await vi.runAllTimersAsync()

		expect(search.mock.calls.map((call) => idsIn(call).length)).toEqual([90, 90, 20])
	})

	it('looks every id up once per page session, found or not', async () => {
		names.requestFilelinkName(5)
		names.requestFilelinkName(5)
		await vi.runAllTimersAsync()
		names.requestFilelinkName(5)
		await vi.runAllTimersAsync()

		expect(search).toHaveBeenCalledOnce()
	})

	it('ignores cells without a file id', async () => {
		names.requestFilelinkName(null)
		names.requestFilelinkName(0)
		await vi.runAllTimersAsync()

		expect(search).not.toHaveBeenCalled()
	})

	it('keeps the found names, marks the others missing and redraws both', async () => {
		const redraw = vi.fn()
		names.onFilelinkNames(redraw)
		search.mockResolvedValue(answer({ 1: 'Offer v2.pdf', 99: 'not asked.txt' }))
		names.requestFilelinkName(1)
		names.requestFilelinkName(2)
		await vi.runAllTimersAsync()

		expect(names.getFilelinkName(1)).toBe('Offer v2.pdf')
		expect(names.isFilelinkMissing(1)).toBe(false)
		// Not found: gone or hidden to this user.
		expect(names.getFilelinkName(2)).toBeUndefined()
		expect(names.isFilelinkMissing(2)).toBe(true)
		expect(names.getFilelinkName(99)).toBeUndefined()
		expect(redraw).toHaveBeenCalledWith([1, 2])
	})

	it('is not missing while the lookup still runs', () => {
		names.requestFilelinkName(4)

		expect(names.isFilelinkMissing(4)).toBe(false)
	})

	it('shows the links as unavailable and does not retry when the search fails', async () => {
		const redraw = vi.fn()
		names.onFilelinkNames(redraw)
		search.mockRejectedValue(new Error('network'))
		names.requestFilelinkName(8)
		await vi.runAllTimersAsync()
		names.requestFilelinkName(8)
		await vi.runAllTimersAsync()

		expect(search).toHaveBeenCalledOnce()
		expect(names.getFilelinkName(8)).toBeUndefined()
		expect(names.isFilelinkMissing(8)).toBe(true)
		expect(redraw).toHaveBeenCalledWith([8])
	})
})

describe('filelinkLabel', () => {
	it('shows the name once it is found, and links to the file', async () => {
		search.mockResolvedValue(answer({ 12: 'Offer.pdf' }))
		names.requestFilelinkName(12)
		expect(names.filelinkLabel({ fileId: 12, path: '' })).toEqual({ label: '\u2026', title: '', missing: false })
		await vi.runAllTimersAsync()

		expect(names.filelinkLabel({ fileId: 12, path: '' })).toEqual({ label: 'Offer.pdf', title: 'Offer.pdf', missing: false })
		expect(names.filelinkHref({ fileId: 12, path: '' })).toBe('/f/12')
	})

	it('says a file is unavailable when Nextcloud does not find it for you', async () => {
		names.requestFilelinkName(13)
		await vi.runAllTimersAsync()

		expect(names.filelinkLabel({ fileId: 13, path: '' })).toEqual({ label: 'Unavailable file', title: 'Unavailable file', missing: true })
	})

	it('shows the text of a link without id', () => {
		expect(names.filelinkLabel({ fileId: null, path: '/Team/Docs/a.pdf' })).toEqual({ label: 'a.pdf', title: '/Team/Docs/a.pdf', missing: false })
	})
})
