import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const page = await import('../../src/utils/updatedPage.js')

const chunkError = () => Object.assign(new Error('Loading chunk 7414 failed.'), { name: 'ChunkLoadError' })

// Nextcloud's own translation and toast, as on every page.
beforeEach(() => {
	page._reset()
	globalThis.window = { t: vi.fn((app, text) => text), OCP: { Toast: { error: vi.fn() } } }
})

afterEach(() => {
	delete globalThis.window
	vi.restoreAllMocks()
})

const toasts = () => window.OCP.Toast.error.mock.calls.map(([text]) => text)

describe('a page that stayed open through an update', () => {
	it('knows a part that failed to load', () => {
		expect(page.isLoadFailure(chunkError())).toBe(true)
		// An async component's loader: Vue's info in development, its link in production.
		expect(page.isLoadFailure(new TypeError('x'), 'async component loader')).toBe(true)
		expect(page.isLoadFailure(new TypeError('x'), 'https://vuejs.org/error-reference/#runtime-13')).toBe(true)
		expect(page.isLoadFailure(new TypeError('x'), 'https://vuejs.org/error-reference/#runtime-1')).toBe(false)
		expect(page.isLoadFailure(new Error('Request failed'))).toBe(false)
	})

	it('says to reload when a part does not load, once, and still fails', async () => {
		await expect(page.loadPart(() => Promise.reject(chunkError()))).rejects.toThrow('Loading chunk')
		// A changed file holds other code: the import fails some other way.
		await expect(page.loadPart(() => Promise.reject(new TypeError('Cannot read properties of undefined (reading \'call\')')))).rejects.toThrow(TypeError)

		expect(toasts()).toEqual(['MetaVox was updated. Reload the page to continue.'])
		expect(window.OCP.Toast.error.mock.calls[0][1]).toEqual({ timeout: -1 })
		expect(window.t).toHaveBeenCalledWith('metavox', 'MetaVox was updated. Reload the page to continue.')
	})

	it('says nothing when the part loads', async () => {
		await expect(page.loadPart(() => Promise.resolve({ open: 1 }))).resolves.toEqual({ open: 1 })
		expect(toasts()).toEqual([])
	})

	it('has a Vue app say it for an async component, and pass every error on as before', () => {
		const before = vi.fn()
		const app = page.watchLoading({ config: { errorHandler: before } })
		app.config.errorHandler(new TypeError('x'), null, 'https://vuejs.org/error-reference/#runtime-13')
		app.config.errorHandler(new Error('render failed'), null, 'https://vuejs.org/error-reference/#runtime-1')

		expect(toasts()).toHaveLength(1)
		expect(before).toHaveBeenCalledTimes(2)
	})

	it('logs other errors the way Vue does when there was no handler', () => {
		const log = vi.spyOn(console, 'error').mockImplementation(() => {})
		const error = new Error('render failed')
		page.watchLoading({ config: {} }).config.errorHandler(error, null, 'https://vuejs.org/error-reference/#runtime-1')

		expect(log).toHaveBeenCalledWith(error)
		expect(toasts()).toEqual([])
	})
})

// Every Vue app MetaVox mounts goes through watchLoading.
describe('the Vue apps', () => {
	const files = (dir) => readdirSync(dir).flatMap((name) => {
		const path = join(dir, name)
		return statSync(path).isDirectory() ? files(path) : /\.(js|vue)$/.test(name) ? [path] : []
	})
	const withApps = files('src').filter((path) => /\bcreateApp\(/.test(readFileSync(path, 'utf8')))

	it('are found', () => {
		expect(withApps.length).toBeGreaterThanOrEqual(11)
	})

	it.each(withApps)('%s mounts each one watched', (path) => {
		const mounts = [...readFileSync(path, 'utf8').matchAll(/(\S+)\.mount\(/g)].map((m) => m[1])
		expect(mounts.length).toBeGreaterThan(0)
		for (const target of mounts) expect(target, path).toMatch(/^watchLoading\(/)
	})
})
