import { beforeEach, describe, expect, it, vi } from 'vitest'

const listen = vi.fn(() => true)
vi.mock('@nextcloud/notify_push', () => ({ listen }))

const { PUSH_EVENTS, listenForPushes } = await import('../../src/filesplugin/columns/PushListener.js')

beforeEach(() => listen.mockClear())

describe('the push events', () => {
	it('are the three the server sends, each through listen()', () => {
		listenForPushes(vi.fn())

		expect(PUSH_EVENTS).toEqual(['metavox_metadata_changed', 'metavox_cell_locked', 'metavox_cell_unlocked'])
		expect(listen.mock.calls.map(([name]) => name)).toEqual(PUSH_EVENTS)
	})

	it('reach the handler with their body, or an empty one', () => {
		const handle = vi.fn()
		listenForPushes(handle)
		const [, callback] = listen.mock.calls[0]

		callback('metavox_metadata_changed', { gfId: 2, fileIds: [7] })
		callback('metavox_cell_unlocked', null)

		expect(handle).toHaveBeenNthCalledWith(1, 'metavox_metadata_changed', { gfId: 2, fileIds: [7] })
		expect(handle).toHaveBeenNthCalledWith(2, 'metavox_cell_unlocked', {})
	})

	it('never break the socket when the handler fails', () => {
		listenForPushes(() => { throw new Error('boom') })
		const [, callback] = listen.mock.calls[1]

		expect(() => callback('metavox_cell_locked', { fileId: 1 })).not.toThrow()
	})
})
