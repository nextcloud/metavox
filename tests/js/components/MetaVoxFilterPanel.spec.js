import { describe, it, expect, vi, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

/**
 * The filter panel with thousands of values: it lists at most a hundred, the
 * search finds the rest, and a checked value never drops out of sight.
 */
const VALUES = Array.from({ length: 5000 }, (_, i) => `Klant ${String(i).padStart(4, '0')}`)
let values = VALUES

vi.mock('@/filesplugin/columns/FilterOptions.js', () => ({
	folderFilterValues: vi.fn(async (configs) => Object.fromEntries(configs.map((c) => [c.field_name, values]))),
	folderCutOffValues: () => ({}),
	optionLabel: (value) => value,
}))
vi.mock('@/filesplugin/columns/FilelinkNames.js', () => ({ onFilelinkNames: () => () => {} }))

const { default: MetaVoxFilterPanel } = await import('@/filesplugin/columns/MetaVoxFilterPanel.vue')

function filterWith(active = []) {
	const chosen = new Set(active)
	return {
		getFilterableConfigs: () => [{ field_name: 'file_gf_klant', field_label: 'Klant', field_type: 'text' }],
		getActiveFilters: () => new Map(chosen.size ? [['file_gf_klant', chosen]] : []),
		toggleFilterValue: vi.fn((field, value) => (chosen.has(value) ? chosen.delete(value) : chosen.add(value))),
	}
}

async function open(filter) {
	const wrapper = mount(MetaVoxFilterPanel, { props: { filter } })
	await flushPromises()
	if (!wrapper.find('.options').exists()) await wrapper.find('.filter-summary').trigger('click')
	await flushPromises()
	return wrapper
}

const listed = (wrapper) => wrapper.findAll('.options > [data-stub="nc"]:not(.options-search)').map((el) => el.text())
const note = (wrapper) => wrapper.find('.options-note').exists() ? wrapper.find('.options-note').text() : ''

afterEach(() => {
	values = VALUES
	document.body.innerHTML = ''
})

describe('MetaVoxFilterPanel with many values', () => {
	it('lists the first hundred and says how many there are', async () => {
		const wrapper = await open(filterWith())

		expect(listed(wrapper)).toHaveLength(100)
		expect(listed(wrapper)[0]).toBe('Klant 0000')
		expect(note(wrapper)).toBe('Showing 100 of 5000 values. Search to find the rest.')
	})

	it('searches all values, not only the listed ones', async () => {
		const wrapper = await open(filterWith())

		await wrapper.find('.options-search input').setValue('4321')

		expect(listed(wrapper)).toEqual(['Klant 4321'])
		expect(note(wrapper)).toBe('')
	})

	it('caps the matches too, and says nothing matches when nothing does', async () => {
		const wrapper = await open(filterWith())

		await wrapper.find('.options-search input').setValue('klant 4')
		expect(listed(wrapper)).toHaveLength(100)
		expect(note(wrapper)).toBe('Showing 100 of 1000 matches. Type more to narrow them down.')

		await wrapper.find('.options-search input').setValue('nobody')
		expect(listed(wrapper)).toEqual([])
		expect(note(wrapper)).toBe('No matching values in this folder')
	})

	it('keeps a checked value in sight, past the hundred', async () => {
		const wrapper = await open(filterWith(['Klant 4999']))

		expect(listed(wrapper)).toHaveLength(101)
		expect(listed(wrapper).at(-1)).toBe('Klant 4999')
	})

	it('filters on what is clicked', async () => {
		const filter = filterWith()
		const wrapper = await open(filter)
		await wrapper.find('.options-search input').setValue('4321')

		await wrapper.find('.options > [data-stub="nc"]:not(.options-search) input').trigger('input')

		expect(filter.toggleFilterValue).toHaveBeenCalledWith('file_gf_klant', 'Klant 4321')
	})

	it('has no search field for a short list', async () => {
		values = ['Draft', 'Final', 'Archived']
		const wrapper = await open(filterWith())

		expect(wrapper.find('.options-search').exists()).toBe(false)
		expect(listed(wrapper)).toEqual(['Draft', 'Final', 'Archived'])
		expect(note(wrapper)).toBe('')
	})
})
