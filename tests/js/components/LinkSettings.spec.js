import { describe, it, expect, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import LinkSettings from '@/components/LinkSettings.vue'

/**
 * What URL fields take: the switch for web and email addresses only, and the
 * two lists of domains, saved together and shown as the server keeps them.
 */
async function mounted(settings) {
	vi.spyOn(axios, 'get').mockResolvedValue({ data: { success: true, settings } })
	const wrapper = mount(LinkSettings)
	await flushPromises()
	return wrapper
}

afterEach(() => {
	vi.restoreAllMocks()
})

describe('LinkSettings', () => {
	it('shows the lists one domain per line, a top-level domain with its dot', async () => {
		const wrapper = await mounted({ url_web_only: true, url_blocked_domains: ['ru', 'example.com'], url_allowed_domains: [] })

		expect(wrapper.vm.webOnly).toBe(true)
		expect(wrapper.vm.blocked).toBe('.ru\nexample.com')
		expect(wrapper.vm.allowed).toBe('')
		expect(wrapper.vm.changed).toBe(false)
	})

	it('saves both lists as typed, then shows them as the server keeps them', async () => {
		const wrapper = await mounted({ url_blocked_domains: [], url_allowed_domains: [] })
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { success: true } })
		wrapper.vm.blocked = '.RU\nhttps://example.com/x'
		expect(wrapper.vm.changed).toBe(true)

		vi.spyOn(axios, 'get').mockResolvedValue({ data: { success: true, settings: { url_blocked_domains: ['ru', 'example.com'], url_allowed_domains: [] } } })
		await wrapper.vm.saveLists()

		expect(post.mock.calls[0][1]).toEqual({ url_blocked_domains: '.RU\nhttps://example.com/x', url_allowed_domains: '' })
		expect(wrapper.vm.blocked).toBe('.ru\nexample.com')
		expect(wrapper.vm.changed).toBe(false)
	})

	it('shows a refused list under that list, and keeps what was typed', async () => {
		const wrapper = await mounted({ url_blocked_domains: [], url_allowed_domains: [] })
		vi.spyOn(axios, 'post').mockRejectedValue({ response: { status: 400, data: { error: 'Validation failed', reason: 'invalid_input', fields: { url_blocked_domains: 'Enter domains such as example.com or .ru, one per line' } } } })
		wrapper.vm.blocked = 'not a domain!'

		await wrapper.vm.saveLists()

		expect(wrapper.vm.errors.url_blocked_domains).toBe('Enter domains such as example.com or .ru, one per line')
		expect(wrapper.vm.blocked).toBe('not a domain!')
	})

	it('puts the switch back when saving it fails', async () => {
		const wrapper = await mounted({ url_web_only: false })
		vi.spyOn(axios, 'post').mockRejectedValue(new Error('500'))

		await wrapper.vm.saveWebOnly(true)

		expect(wrapper.vm.webOnly).toBe(false)
	})
})
