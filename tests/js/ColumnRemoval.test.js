// @vitest-environment happy-dom
import { beforeEach, describe, expect, it, vi } from 'vitest'

// Leaving a team folder takes MetaVox's cells away, and with them what hangs
// outside the table: a lock badge on the body and its scroll listener.
vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn(() => Promise.resolve({ data: {} })) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showWarning: vi.fn() }))
vi.mock('../../src/filesplugin/columns/MetadataFilter.js', () => ({ getFilterInstance: () => null }))
vi.mock('../../src/filesplugin/columns/FilelinkNames.js', () => ({
	filelinkHref: vi.fn(), filelinkLabel: vi.fn(() => ({})), requestFilelinkName: vi.fn(), getFilelinkName: vi.fn(),
}))
vi.mock('../../src/filesplugin/columns/ListingSync.js', () => ({
	getListingGroupfolderId: () => 2, getListingNode: vi.fn(() => null), isListingLoading: () => false,
}))
vi.mock('../../src/filesplugin/columns/MetadataStore.js', () => ({
	isTruncated: vi.fn(() => false), hasNoTeamFolder: vi.fn(() => false), seedFromNode: vi.fn(),
}))

let dom
let MARKER_CLASS

beforeEach(async () => {
	vi.resetModules()
	document.body.innerHTML = '<table class="files-list__table"><tbody><tr data-cy-files-list-row></tr></tbody></table>'
	;({ MARKER_CLASS } = await import('../../src/filesplugin/columns/ColumnStyles.js'))
	dom = await import('../../src/filesplugin/columns/ColumnDOM.js')
})

function lockedCell() {
	const td = document.createElement('td')
	td.className = MARKER_CLASS
	document.querySelector('tr').appendChild(td)
	const badge = document.createElement('div')
	badge.className = 'metavox-lock-badge'
	document.body.appendChild(badge)
	const onScroll = vi.fn()
	document.addEventListener('scroll', onScroll, { capture: true })
	Object.assign(td, { _metavoxLocked: true, _lockBadge: badge, _lockScrollHandler: onScroll })
	td.dataset.lockedBy = 'alice'
	return { td, badge, onScroll }
}

describe('leaving a team folder', () => {
	it('takes the cells away, with the lock badge and its scroll listener', () => {
		const { badge, onScroll } = lockedCell()

		dom.removeAllInjectedColumns()
		document.dispatchEvent(new Event('scroll'))

		expect(document.querySelector('.' + MARKER_CLASS)).toBeNull()
		expect(document.body.contains(badge)).toBe(false)
		expect(document.querySelector('.metavox-lock-badge')).toBeNull()
		expect(onScroll).not.toHaveBeenCalled()
	})

	it('leaves cells without a lock as they were, apart from removing them', () => {
		const td = document.createElement('td')
		td.className = MARKER_CLASS
		document.querySelector('tr').appendChild(td)

		dom.removeAllInjectedColumns()

		expect(document.querySelector('.' + MARKER_CLASS)).toBeNull()
	})
})
