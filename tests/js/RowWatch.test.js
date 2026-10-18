// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

// The file list's rows: new ones and reused ones come through; what changes
// inside a row (MetaVox's own cells too) costs the observers nothing.
let calls = 0
const RealObserver = globalThis.MutationObserver
class CountingObserver extends RealObserver {
	constructor(callback) {
		super((records, observer) => {
			calls++
			callback(records, observer)
		})
	}
}

let watchRows
let tbody
let on

const tick = () => new Promise((resolve) => setTimeout(resolve, 0))
function row(fileId) {
	const tr = document.createElement('tr')
	tr.setAttribute('data-cy-files-list-row', '')
	tr.setAttribute('data-cy-files-list-row-fileid', String(fileId))
	tr.appendChild(document.createElement('td'))
	return tr
}

beforeEach(async () => {
	vi.resetModules()
	calls = 0
	vi.stubGlobal('MutationObserver', CountingObserver)
	document.body.innerHTML = '<table class="files-list__table"><tbody></tbody></table>'
	tbody = document.querySelector('tbody')
	tbody.appendChild(row(1))
	;({ watchRows } = await import('../../src/filesplugin/columns/RowWatch.js'))
	on = { added: vi.fn(), reused: vi.fn() }
})

afterEach(() => vi.unstubAllGlobals())

describe('watching the rows', () => {
	it('hears of a new row', async () => {
		watchRows(tbody, on)
		const tr = row(2)

		tbody.appendChild(tr)
		await tick()

		expect(on.added).toHaveBeenCalledWith(tr)
		expect(on.reused).not.toHaveBeenCalled()
	})

	it('hears of a row reused for another file', async () => {
		watchRows(tbody, on)
		const tr = tbody.firstElementChild

		tr.setAttribute('data-cy-files-list-row-fileid', '7')
		await tick()

		expect(on.reused).toHaveBeenCalledWith(tr)
	})

	it('is not woken by what changes inside a row', async () => {
		watchRows(tbody, on)
		const td = tbody.querySelector('td')

		for (let i = 0; i < 100; i++) {
			td.textContent = `value ${i}`
			td.title = `title ${i}`
			td.appendChild(document.createElement('span'))
		}
		tbody.firstElementChild.setAttribute('aria-label', 'other attribute')
		await tick()

		expect(calls).toBe(0)
		expect(on.added).not.toHaveBeenCalled()
		expect(on.reused).not.toHaveBeenCalled()
	})

	it('hears nothing once disconnected', async () => {
		watchRows(tbody, on).disconnect()

		tbody.appendChild(row(3))
		tbody.firstElementChild.setAttribute('data-cy-files-list-row-fileid', '8')
		await tick()

		expect(on.added).not.toHaveBeenCalled()
		expect(on.reused).not.toHaveBeenCalled()
	})
})
