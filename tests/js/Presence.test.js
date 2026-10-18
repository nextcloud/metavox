import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))

let presence
let beacons

beforeEach(async () => {
	vi.resetModules()
	beacons = []
	vi.stubGlobal('navigator', { sendBeacon: (url, data) => beacons.push({ url, gfId: data.get('gf_id'), token: data.get('requesttoken') }) })
	vi.stubGlobal('document', { querySelector: () => ({ dataset: { requesttoken: 'tok' } }) })
	presence = await import('../../src/filesplugin/columns/Presence.js')
})

describe('presence', () => {
	it('sends nothing on arrival: listing the folder registers you', () => {
		presence.showingTeamFolder(5)

		expect(beacons).toEqual([])
	})

	it('leaves the team folder before when the grid switches', () => {
		presence.showingTeamFolder(5)
		presence.showingTeamFolder(6)

		expect(beacons).toEqual([{ url: '/apps/metavox/api/presence/leave', gfId: '5', token: 'tok' }])
	})

	it('leaves when the grid shows no team folder any more, once', () => {
		presence.showingTeamFolder(5)
		presence.showingTeamFolder(null)
		presence.showingTeamFolder(null)

		expect(beacons.map((b) => b.gfId)).toEqual(['5'])
	})

	it('stays when moving around inside the same team folder', () => {
		presence.showingTeamFolder(5)
		presence.showingTeamFolder(5)

		expect(beacons).toEqual([])
	})

	it('leaves the open team folder when the tab closes', () => {
		presence.showingTeamFolder(7)
		presence.leaveOnClose()

		expect(beacons.map((b) => b.gfId)).toEqual(['7'])
	})

	it('has nothing to leave outside a team folder', () => {
		presence.leaveOnClose()

		expect(beacons).toEqual([])
	})
})
