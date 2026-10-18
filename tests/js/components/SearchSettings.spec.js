import { describe, it, expect, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import SearchSettings from '@/components/SearchSettings.vue'

/**
 * The admin's two switches for searching metadata: off until turned on, the
 * folders only with the filter, and a failed save puts the switch back.
 */
async function mounted(settings) {
	vi.spyOn(axios, 'get').mockResolvedValue({ data: { success: true, settings } })
	const wrapper = mount(SearchSettings)
	await flushPromises()
	return wrapper
}
const switches = (wrapper) => wrapper.findAllComponents({ name: 'NcComponentStub' })

afterEach(() => {
	vi.restoreAllMocks()
})

describe('SearchSettings', () => {
	it('shows the switches as the admin left them', async () => {
		const wrapper = await mounted({ search_filter: true, search_folders: false })

		expect(switches(wrapper).map((s) => s.props('modelValue'))).toEqual([true, false])
	})

	it('shows them off when the settings can not be read', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		vi.spyOn(axios, 'get').mockRejectedValue(new Error('500'))
		const wrapper = mount(SearchSettings)
		await flushPromises()

		expect([wrapper.vm.filter, wrapper.vm.folders]).toEqual([false, false])
	})

	it('lets the folders be turned on only with the filter', async () => {
		const wrapper = await mounted({ search_filter: false, search_folders: false })

		expect(switches(wrapper)[1].attributes('disabled')).toBe('true')

		vi.spyOn(axios, 'post').mockResolvedValue({ data: { success: true } })
		await wrapper.vm.save('search_filter', true)
		await wrapper.vm.$nextTick()
		expect(switches(wrapper)[1].attributes('disabled')).toBe('false')
	})

	it('lets the folders be turned off without the filter, so they do not come back with it', async () => {
		const wrapper = await mounted({ search_filter: false, search_folders: true })

		expect(switches(wrapper)[1].attributes('disabled')).toBe('false')

		vi.spyOn(axios, 'post').mockResolvedValue({ data: { success: true } })
		await wrapper.vm.save('search_folders', false)
		await wrapper.vm.$nextTick()
		expect(switches(wrapper)[1].attributes('disabled')).toBe('true')
	})

	it('saves one switch at a time', async () => {
		const wrapper = await mounted({ search_filter: true, search_folders: false })
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { success: true } })

		switches(wrapper)[1].vm.$emit('update:model-value', true)
		await flushPromises()

		expect(post).toHaveBeenCalledWith('/apps/metavox/api/settings', { search_folders: true })
		expect(wrapper.vm.folders).toBe(true)
	})

	it('puts the switch back when saving fails', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		const wrapper = await mounted({ search_filter: true, search_folders: false })
		vi.spyOn(axios, 'post').mockRejectedValue(new Error('403'))

		await wrapper.vm.save('search_folders', true)

		expect(wrapper.vm.folders).toBe(false)
		expect(wrapper.vm.saving).toBe(false)
	})
})
