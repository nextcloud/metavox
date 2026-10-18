import { describe, expect, it } from 'vitest'
import { decideMetadataPush, shouldRefreshOnReturn } from '../../src/filesplugin/columns/SyncRules.js'

describe('decideMetadataPush', () => {
	it('never applies a value from a push: it loads the file again', () => {
		expect(decideMetadataPush({ gfId: 2, fileId: 10, fieldName: 'status', value: 'Final' }, 2))
			.toEqual({ type: 'reload', ids: [10] })
	})

	it('reloads the files of a batch', () => {
		expect(decideMetadataPush({ gfId: 2, fileIds: [10, '11', 0] }, 2)).toEqual({ type: 'reload', ids: [10, 11] })
	})

	it('reloads one file when the push names no field', () => {
		expect(decideMetadataPush({ gfId: 2, fileId: 10 }, 2)).toEqual({ type: 'reload', ids: [10] })
	})

	it('refreshes the listing when the push names no file', () => {
		expect(decideMetadataPush({ gfId: 2 }, 2)).toEqual({ type: 'refresh' })
	})

	it('ignores other team folders and pushes without an active one', () => {
		expect(decideMetadataPush({ gfId: 3, fileId: 10, fieldName: 'status' }, 2)).toEqual({ type: 'ignore' })
		expect(decideMetadataPush({ gfId: 2, fileId: 10, fieldName: 'status' }, null)).toEqual({ type: 'ignore' })
	})
})

describe('shouldRefreshOnReturn', () => {
	const base = { wsOpen: false, wsWasOpen: true, hiddenMs: 30000, sinceRefreshMs: 60000 }

	it('refreshes when notify_push dropped while the tab was away long enough', () => {
		expect(shouldRefreshOnReturn(base)).toBe(true)
	})

	it('does not refresh while notify_push is connected', () => {
		expect(shouldRefreshOnReturn({ ...base, wsOpen: true })).toBe(false)
	})

	it('does not refresh without notify_push', () => {
		expect(shouldRefreshOnReturn({ ...base, wsWasOpen: false })).toBe(false)
	})

	it('does not refresh after a short switch or right after the last refresh', () => {
		expect(shouldRefreshOnReturn({ ...base, hiddenMs: 29999 })).toBe(false)
		expect(shouldRefreshOnReturn({ ...base, sinceRefreshMs: 59999 })).toBe(false)
	})
})
