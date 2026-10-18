// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

// The bars above the grid follow each other's heights with one ResizeObserver,
// and none is left once the grid goes.
const live = new Set()
class FakeResizeObserver {
	constructor(callback) {
		this.callback = callback
		this.watched = []
		live.add(this)
	}

	observe(el, options) {
		this.watched.push(el)
		this.options = options
	}
	disconnect() { live.delete(this) }
}

let sticky

beforeEach(async () => {
	vi.resetModules()
	live.clear()
	vi.stubGlobal('ResizeObserver', FakeResizeObserver)
	document.body.innerHTML = '<div id="metavox-view-tabs"></div><div class="files-list"><div class="files-list__filters"></div></div>'
	document.getElementById('metavox-view-tabs').getBoundingClientRect = () => ({ height: 34.4 })
	document.querySelector('.files-list__filters').getBoundingClientRect = () => ({ height: 50 })
	sticky = await import('../../src/filesplugin/columns/StickyOffsets.js')
})

afterEach(() => vi.unstubAllGlobals())

const offset = (name) => document.querySelector('.files-list').style.getPropertyValue(name)

describe('the sticky bars above the grid', () => {
	it('stack under each other', () => {
		sticky.watchStickyOffsets()

		expect(offset('--mv-filters-top')).toBe('34px')
		expect(offset('--mv-thead-top')).toBe('84px')
	})

	it('follow both bars with one observer, however often a folder is opened', () => {
		for (let i = 0; i < 5; i++) sticky.watchStickyOffsets()

		expect(live.size).toBe(1)
		expect([...live][0].watched.map((el) => el.id || el.className)).toEqual(['files-list__filters', 'metavox-view-tabs'])
		// What update() measures: padding and border included.
		expect([...live][0].options).toEqual({ box: 'border-box' })
	})

	it('leave no observer behind when the grid goes', () => {
		sticky.watchStickyOffsets()
		sticky.stopStickyOffsets()
		sticky.stopStickyOffsets()

		expect(live.size).toBe(0)
	})

	it('follow a bar that grows', () => {
		sticky.watchStickyOffsets()
		document.querySelector('.files-list__filters').getBoundingClientRect = () => ({ height: 90 })

		;[...live][0].callback()

		expect(offset('--mv-thead-top')).toBe('124px')
	})
})

describe('a new view tabs bar', () => {
	it('is followed instead of the one it replaced', () => {
		sticky.watchStickyOffsets()
		const old = document.getElementById('metavox-view-tabs')
		const bar = document.createElement('div')
		old.replaceWith(bar)
		bar.id = 'metavox-view-tabs'
		bar.getBoundingClientRect = () => ({ height: 70 })

		sticky.watchStickyOffsets()

		expect(live.size).toBe(1)
		expect([...live][0].watched).toContain(bar)
		expect([...live][0].watched).not.toContain(old)
		expect(offset('--mv-thead-top')).toBe('120px')
	})
})
