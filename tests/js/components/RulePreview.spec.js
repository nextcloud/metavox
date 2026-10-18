import { describe, it, expect, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import ColumnFormatEditor from '@/filesplugin/columns/ColumnFormatEditor.vue'

/**
 * The preview beside the highlight rules: the column's cells as the grid
 * colours them, following the open rule while it is being edited.
 */
const status = (rules) => ({
	field_id: 2,
	field_name: 'status',
	field_label: 'Status',
	field_type: 'text',
	field_options: null,
	visible: true,
	rules: rules.map((rule, i) => ({ key: `r${i}`, rule })),
})
const CONCEPT = { op: 'is', value: 'Concept', tone: 'warning' }

function mountEditor(columns, props = {}) {
	return mount(ColumnFormatEditor, { props: { columns, ...props }, attachTo: document.body })
}

// Each row's value cell: its text and its tone.
const cells = (wrapper) => wrapper.findAll('.mv-grid-preview tbody tr')
	.map((tr) => tr.findAll('td')[1])
	.map((td) => [td.text(), td.attributes('data-mv-tone') ?? null])

afterEach(() => {
	document.body.innerHTML = ''
})

describe('the preview of a column\'s rules', () => {
	it('shows the cells as the grid colours them, the state named on the icon', () => {
		const wrapper = mountEditor([status([CONCEPT])])

		// Headed by NcFormGroup, as Talk heads its chat preview.
		expect(wrapper.find('.mv-grid-preview').attributes('label')).toBe('In the file list')
		expect(wrapper.findAll('.mv-grid-preview th').map((th) => th.text())).toEqual(['Name', 'Status'])
		expect(cells(wrapper)).toEqual([['Concept', 'warning'], ['Some text', null], ['—', null]])
		const icon = wrapper.find('.mv-grid-preview .mv-tone-icon')
		expect(icon.attributes()).toMatchObject({ role: 'img', 'aria-label': 'is Concept', title: 'is Concept' })
	})

	it('follows the open rule before it is done, and leaves the stored one alone', async () => {
		const columns = [status([CONCEPT])]
		const wrapper = mountEditor(columns)
		await wrapper.find('.mv-fmt-edit').trigger('click')

		await wrapper.find('.mv-rule-tones input').setValue('error')
		expect(cells(wrapper)[0]).toEqual(['Concept', 'error'])
		expect(columns[0].rules[0].rule.tone).toBe('warning')

		// Not valid yet: it colours nothing, as it would not in the grid.
		await wrapper.find('[id$="-value"] input').setValue('')
		expect(cells(wrapper).every(([, tone]) => tone === null)).toBe(true)
	})

	it('puts a new rule in its place, below the one that wins', async () => {
		const wrapper = mountEditor([status([CONCEPT])])
		const custom = wrapper.findAll('[data-stub="nc"]').find((el) => el.text() === 'Custom rule')
		await custom.trigger('click')
		expect(cells(wrapper)).toEqual([['Concept', 'warning'], ['Some text', null], ['—', null]])

		await wrapper.find('[id$="-value"] input').setValue('Some text')
		// The other value is now one that no rule names.
		expect(cells(wrapper)).toEqual([['Concept', 'warning'], ['Some text', 'info'], ['Something else', null], ['—', null]])
	})

	it('puts a preset where Done will put it, above the rules it comes before', async () => {
		const column = { ...status([{ op: 'nextDays', value: 7, tone: 'warning', preset: 'dueSoon' }]), field_type: 'date', field_label: 'Deadline' }
		const wrapper = mountEditor([column])
		const today = wrapper.findAll('[data-stub="nc"]').find((el) => el.text() === 'Due today')
		await today.trigger('click')

		// Appended, "Due soon" would win today's row and "Due today" show nowhere.
		const names = wrapper.findAll('.mv-grid-preview .mv-tone-icon').map((icon) => icon.attributes('aria-label'))
		expect(names).toContain('Due today')
		expect(names).toContain('Due soon')
	})

	it('shows for who only reads the rules', () => {
		const wrapper = mountEditor([status([CONCEPT]), { ...status([]), field_id: 3, field_label: 'Owner' }], { readonly: true })

		// The column without rules is no legend, so it has no preview.
		expect(wrapper.findAll('.mv-grid-preview')).toHaveLength(1)
		expect(cells(wrapper)[0]).toEqual(['Concept', 'warning'])
	})

	it('has no preview for a column without rules', () => {
		const wrapper = mountEditor([status([])])

		expect(wrapper.find('.mv-grid-preview').exists()).toBe(false)
	})
})
