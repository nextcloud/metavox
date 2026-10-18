import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { describe, expect, it } from 'vitest'

const require = createRequire(import.meta.url)
const config = require('../../webpack.config.js')

// A lazy chunk is loaded by the bundle, not by Nextcloud: it needs a version
// of its own, or a browser keeps the old one for six months after an update.
describe('the build', () => {
	it('versions every lazy chunk by its content', () => {
		expect(config.output.chunkFilename).toMatch(/\?v=\[contenthash(:\d+)?\]$/)
	})

	it('leaves no chunks of earlier builds behind', () => {
		expect(config.output.clean).toBe(true)
	})

	it('is what the committed bundles were built with', () => {
		for (const entry of ['filesplugin', 'metavox-init']) {
			expect(readFileSync(`js/${entry}.js`, 'utf8').includes('".js?v="'), `js/${entry}.js loads its chunks with a version`).toBe(true)
		}
	})

	// Every Nextcloud page loads the search script, the login page too: the
	// filter picker and Vue load when someone opens it.
	it('keeps the script every page loads small', () => {
		const search = readFileSync('js/metavox-search.js', 'utf8')
		expect(search.length).toBeLessThan(20 * 1024)
		expect(search.includes('createApp')).toBe(false)
	})

	// Messages go through Nextcloud's own toasts (src/utils/toast.js): the
	// 200 KB of @nextcloud/dialogs only loads with what opens a dialog.
	it('keeps @nextcloud/dialogs out of the Files script', () => {
		const files = readFileSync('js/filesplugin.js', 'utf8')
		expect(files.includes('Please provide a valid onUndo method')).toBe(false)
	})

	// Compiled for the browsers Nextcloud supports, not for Internet Explorer.
	it('targets the browsers Nextcloud supports', () => {
		const pkg = JSON.parse(readFileSync('package.json', 'utf8'))
		expect(pkg.browserslist).toEqual(['extends @nextcloud/browserslist-config'])
		const babel = require('../../babel.config.js')
		expect(babel.presets.flat().some((p) => p?.targets)).toBe(false)
	})

	// Templates are compiled when building; Vue's compiler in the browser is
	// 100 KB in every bundle for nothing. Nextcloud's own config does the same.
	it('uses Vue without its template compiler', () => {
		expect(config.resolve.alias?.vue$).toBeUndefined()
		for (const entry of ['filesplugin', 'metavox-search', 'admin']) {
			expect(/\bbaseCompile\b|compileToFunction/.test(readFileSync(`js/${entry}.js`, 'utf8')), `js/${entry}.js has no template compiler`).toBe(false)
		}
	})
})
