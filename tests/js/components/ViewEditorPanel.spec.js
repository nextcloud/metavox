import { describe, it, expect, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ViewEditorPanel from '@/filesplugin/columns/ViewEditorPanel.vue'

/**
 * Saving a view from the editor: highlight rules survive, only changed
 * columns carry them, and a refused save keeps the dialog and the edits.
 */
const OVERDUE = { op: 'before', value: { today: 0 }, tone: 'error', preset: 'overdue' }
const SOON = { op: 'nextDays', value: 7, tone: 'warning' }
const FIELDS = [
	{ id: 1, field_name: 'deadline', field_label: 'Deadline', field_type: 'date' },
	{ id: 2, field_name: 'status', field_label: 'Status', field_type: 'text' },
	{ id: 3, field_name: 'owner', field_label: 'Owner', field_type: 'user' },
]
const VIEW = {
	id: 5,
	name: 'Werk',
	is_default: false,
	columns: [
		{ field_id: 1, field_name: 'deadline', visible: true, filterable: true, format: [OVERDUE, SOON] },
		{ field_id: 2, field_name: 'status', visible: false, filterable: false, format: [{ op: '!empty', tone: 'info' }] },
	],
	filters: {},
	sort_field: null,
	sort_order: null,
}

// Messages go through Nextcloud's own toasts (src/utils/toast.js).
const toastError = () => {
	const error = vi.fn()
	window.OCP = { Toast: { error } }
	return error
}

const failed = (status, data) => Object.assign(new Error('Request failed'), { response: { status, data } })

function mountPanel(props = {}) {
	const submit = props.submit ?? vi.fn(() => Promise.resolve({}))
	const wrapper = mount(ViewEditorPanel, {
		props: {
			view: JSON.parse(JSON.stringify(VIEW)),
			availableFields: FIELDS,
			fetchFilterValuesFn: () => Promise.resolve([]),
			...props,
			submit,
		},
		attachTo: document.body,
	})
	return { wrapper, submit }
}

// NcDialog takes its footer as data; the stub keeps the list it was given.
const buttons = (wrapper) => wrapper.findComponent({ name: 'NcComponentStub' }).props('buttons')
const save = (wrapper) => buttons(wrapper).find((b) => /Save|Saving/.test(b.label)).callback()
const sentColumns = (submit) => submit.mock.calls.at(-1)[0].columns

afterEach(() => {
	delete window.OCP
	vi.restoreAllMocks()
	document.body.innerHTML = ''
})

describe('ViewEditorPanel saving', () => {
	it('sends no rules when none changed', async () => {
		const { wrapper, submit } = mountPanel()
		await save(wrapper)

		const columns = sentColumns(submit)
		expect(columns.map((c) => c.field_id)).toEqual([1, 2, 3])
		expect(columns.some((c) => 'format' in c)).toBe(false)
		// A hidden column stays hidden, its rules untouched on the server.
		expect(columns[1].visible).toBe(false)
	})

	it('sends no rules for a rename alone', async () => {
		const { wrapper, submit } = mountPanel()
		await wrapper.find('#mv-view-name input, input').setValue('Ander werk')
		await save(wrapper)

		expect(submit.mock.calls[0][0].name).toBe('Ander werk')
		expect(sentColumns(submit).some((c) => 'format' in c)).toBe(false)
	})

	it('leaves the view it was given as it was', async () => {
		const view = JSON.parse(JSON.stringify(VIEW))
		const before = JSON.stringify(view)
		const { wrapper } = mountPanel({ view })
		await save(wrapper)

		expect(JSON.stringify(view)).toBe(before)
	})

	it('shows a field listed twice once, the copy the grid shows, and sends its rules', async () => {
		const view = {
			...JSON.parse(JSON.stringify(VIEW)),
			columns: [
				{ field_id: 1, field_name: 'deadline', visible: false, format: [SOON] },
				{ field_id: 1, field_name: 'deadline', visible: true, filterable: true, format: [OVERDUE] },
			],
		}
		const { wrapper, submit } = mountPanel({ view })
		await save(wrapper)

		const deadline = sentColumns(submit).filter((c) => c.field_id === 1)
		expect(deadline).toHaveLength(1)
		expect(deadline[0].visible).toBe(true)
		expect(deadline[0].format).toEqual([OVERDUE])
	})

	it('sends every column back as it came when the fields did not load', async () => {
		const { wrapper, submit } = mountPanel({ availableFields: [] })
		await save(wrapper)

		const columns = sentColumns(submit)
		expect(columns.map((c) => c.field_id)).toEqual([1, 2])
		// Without format, so the server keeps their rules.
		expect(columns.some((c) => 'format' in c)).toBe(false)
	})

	it('refuses a name over 100 characters before sending', async () => {
		const { wrapper, submit } = mountPanel({ view: { ...JSON.parse(JSON.stringify(VIEW)), name: 'é'.repeat(101) } })
		await save(wrapper)

		expect(submit).not.toHaveBeenCalled()
		expect(wrapper.html()).toContain('A view name can have at most 100 characters')
	})

	it('shows it is busy while saving, and is usable again after a refusal', async () => {
		let reject
		const submit = vi.fn(() => new Promise((resolve, r) => { reject = r }))
		const error = toastError()
		const { wrapper } = mountPanel({ submit })

		const pending = save(wrapper)
		await flushPromises()
		const busy = buttons(wrapper).find((b) => /Saving/.test(b.label))
		expect(busy.disabled).toBe(true)
		expect(wrapper.find('.mv-editor-body').attributes('inert')).toBeDefined()

		reject(failed(500, { error: 'Internal server error' }))
		expect(await pending).toBe(false)
		await flushPromises()

		const again = buttons(wrapper).find((b) => b.label === 'Save')
		expect(again.disabled).toBe(false)
		expect(wrapper.find('.mv-editor-body').attributes('inert')).toBeUndefined()
		expect(error).toHaveBeenCalledTimes(1)
		expect(error.mock.calls[0][0]).toContain('Internal server error')
	})

	it('puts a refused name on the name field, without a toast', async () => {
		const error = toastError()
		const submit = vi.fn(() => Promise.reject(failed(400, { error: 'Validation failed', reason: 'invalid_input', fields: { name: 'This value is not valid' } })))
		const { wrapper } = mountPanel({ submit })

		await save(wrapper)
		await flushPromises()

		expect(error).not.toHaveBeenCalled()
		expect(wrapper.html()).toContain('This value is not valid')
	})

	it('gives one toast for any other refusal, and keeps the edits', async () => {
		const error = toastError()
		const submit = vi.fn(() => Promise.reject(failed(400, { error: 'Validation failed', reason: 'invalid_input', fields: { sort_field: 'This value is not valid' } })))
		const { wrapper } = mountPanel({ submit })
		await wrapper.find('input').setValue('Ander werk')

		await save(wrapper)
		await flushPromises()

		expect(error).toHaveBeenCalledTimes(1)
		// What the field takes, not the generic "Validation failed".
		expect(error.mock.calls[0][0]).toBe('Could not save the view: This value is not valid')
		await save(wrapper)
		expect(submit.mock.calls.at(-1)[0].name).toBe('Ander werk')
	})
})
