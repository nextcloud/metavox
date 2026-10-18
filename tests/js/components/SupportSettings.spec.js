import { describe, it, expect, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import SupportSettings from '@/components/SupportSettings.vue'
import axios from '@nextcloud/axios'
import { readFileSync } from 'node:fs'

/**
 * The Support pane carries the case for a subscription: what this
 * installation runs on MetaVox, where to write to buy it, and what is sent
 * back. Each of those has a way of quietly breaking that a render test
 * catches and a click test does not.
 */
describe('SupportSettings', () => {
	afterEach(() => vi.restoreAllMocks())

	const LICENCE = {
		totalFields: 60,
		teamFoldersWithFields: 4,
		totalEntries: 561,
		hasLicense: false,
		licenseValid: false,
		licenseKeyMasked: '',
	}

	const mountPane = async (stats = LICENCE) => {
		vi.spyOn(axios, 'get').mockImplementation((url) => {
			if (url.includes('telemetry/status')) {
				return Promise.resolve({ data: { success: true, enabled: true, lastReport: null } })
			}
			return Promise.resolve({ data: stats ? { success: true, stats } : { success: false } })
		})
		const wrapper = mount(SupportSettings)
		await flushPromises()
		return wrapper
	}

	describe('the installation counts', () => {
		it('shows all three from the licence call', async () => {
			const w = await mountPane()
			expect(w.vm.installationStats).toEqual({
				totalFields: 60,
				teamFolders: 4,
				totalEntries: 561,
			})
		})

		it('reads no second endpoint for them', async () => {
			// They used to come from /api/telemetry/stats on another pane while
			// the subscription state came from /api/license/stats, so the page
			// stated the same numbers twice from two sources that can disagree.
			const w = await mountPane()
			const urls = axios.get.mock.calls.map((c) => c[0])
			expect(urls.filter((u) => u.includes('/stats'))).toEqual(
				urls.filter((u) => u.includes('license/stats')),
			)
			w.unmount()
		})

		it('shows zeroes rather than throwing before the call lands', async () => {
			const w = await mountPane(null)
			expect(w.vm.installationStats).toEqual({
				totalFields: 0,
				teamFolders: 0,
				totalEntries: 0,
			})
		})
	})

	describe('the telemetry switch', () => {
		it('puts the switch back when the save fails', async () => {
			// Uncontrolled for the moment it is flipped, so without this the UI
			// claims the opposite of the server until a reload.
			const w = await mountPane()
			vi.spyOn(axios, 'post').mockRejectedValue(new Error('nope'))
			vi.spyOn(console, 'error').mockImplementation(() => {})
			expect(w.vm.telemetryEnabled).toBe(true)
			await w.vm.toggleTelemetry(false)
			await flushPromises()
			expect(w.vm.telemetryEnabled).toBe(true)
		})

		it('keeps the new value when the save succeeds', async () => {
			const w = await mountPane()
			vi.spyOn(axios, 'post').mockResolvedValue({ data: { success: true } })
			await w.vm.toggleTelemetry(false)
			await flushPromises()
			expect(w.vm.telemetryEnabled).toBe(false)
		})
	})

	describe('the disclosure', () => {
		const source = readFileSync('src/components/SupportSettings.vue', 'utf8')

		it('starts closed', async () => {
			const w = await mountPane()
			const details = w.find('details.telemetry-details')
			expect(details.exists()).toBe(true)
			expect(details.attributes('open')).toBeUndefined()
		})

		it('carries a chevron, because display:flex removes the native marker', async () => {
			// This is the bug that shipped: `display: flex` on a <summary>
			// deletes the marker box in Chromium, so the row renders as
			// stranded text with nothing saying it opens. The element is the
			// only affordance left, so it has to be there.
			const w = await mountPane()
			const summary = w.find('details.telemetry-details summary')
			expect(summary.exists()).toBe(true)
			expect(summary.find('.telemetry-details__chevron').exists()).toBe(true)
		})

		it('turns off the native marker so there are not two', () => {
			expect(source).toMatch(/list-style:\s*none/)
			expect(source).toMatch(/::-webkit-details-marker/)
		})

		it('gives the summary a full-size target', () => {
			// A bare <summary> is text-height; SC 2.5.8 wants a real target.
			expect(source).toMatch(/min-height:\s*var\(--default-clickable-area\)/)
			expect(source).toMatch(/focus-visible/)
		})
	})

	describe('where to write', () => {
		it('sends money questions to Nextcloud and app questions to us', async () => {
			// Nextcloud sell and invoice the subscription under the ISV
			// agreement; routing a buyer past their own account manager to us
			// contradicts the notice at the top of the page.
			const w = await mountPane()
			const hrefs = w.findAll('.contact-routes a').map((a) => a.attributes('href'))
			expect(hrefs).toContain('mailto:sales@nextcloud.com')
			expect(hrefs).toContain('mailto:info@voxcloud.nl')
		})

		it('does not offer a pricing link of our own', async () => {
			const w = await mountPane()
			expect(w.html()).not.toMatch(/voxcloud\.nl\/(pricing|prijzen)/)
		})

		it('does not use a <dl>, which core styles as a fixed label column', async () => {
			// server.css has `dt { width: 130px; white-space: nowrap; text-align:
			// end }` plus 12px padding and inline-block on dt/dd. A 23-character
			// term overflowed that 130px box and painted over the answer beside
			// it. Borrowing the element borrows core's layout.
			const w = await mountPane()
			expect(w.find('.contact-routes').element.tagName).toBe('DIV')
			expect(w.findAll('.contact-routes dt')).toHaveLength(0)
			expect(w.findAll('.contact-routes dd')).toHaveLength(0)
			expect(w.findAll('.contact-routes p').length).toBeGreaterThan(0)
		})

		it('keeps a space between the lead-in and the address', async () => {
			// Vue strips whitespace between two adjacent elements that sit on
			// separate template lines, which ran the colon straight into the
			// address: "Questions about the app:info@voxcloud.nl". The
			// rendered HTML is what shows it — test-utils' .text() normalises
			// whitespace and reports the broken markup as fine.
			const w = await mountPane()
			const html = w.find('.contact-routes').html()
			expect(html).not.toMatch(/<\/strong><a/)
		})

		it('does not style the routing as a footnote', () => {
			// It was --font-size-small in --color-text-maxcontrast, the
			// treatment for a hint under a field, which made the answer to
			// "how do I buy this" the smallest text in the block (§5: colour
			// follows the role, not the size).
			const source = readFileSync('src/components/SupportSettings.vue', 'utf8')
			const block = source.slice(source.indexOf('.contact-routes'))
			const rules = block.slice(0, block.indexOf('\n}\n\n'))
			expect(rules).not.toMatch(/--color-text-maxcontrast/)
		})
	})
})
