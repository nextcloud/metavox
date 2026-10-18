import { describe, expect, it } from 'vitest'
import { reactive } from 'vue'
import { cloneRules, columnsForNewView, columnsWithoutRules, sortFieldType, viewColumnConfigs, viewColumnsPayload, viewToOpen } from '../../src/filesplugin/columns/ColumnUtils.js'

const FIELDS = [
	{ id: 7, field_name: 'deadline', field_label: 'Deadline', field_type: 'date', field_options: { includeTime: true } },
	{ id: 8, field_name: 'status', field_label: 'Status', field_type: 'select', field_options: ['Concept', 'Klaar'] },
]

describe('the columns of a view', () => {
	it('take the type and options of the field, not the copy in the view', () => {
		// Saved while Deadline was still a text field.
		const view = { columns: [{ field_id: 7, field_name: 'deadline', field_label: 'Deadline', field_type: 'text', field_options: [] }] }

		const [deadline] = viewColumnConfigs(view, FIELDS)

		expect(deadline.field_type).toBe('date')
		expect(deadline.field_options).toEqual({ includeTime: true })
	})

	it('leave the stored view as it is', () => {
		const view = { columns: [{ field_id: 8, field_name: 'status' }] }

		viewColumnConfigs(view, FIELDS)

		expect(view.columns[0]).toEqual({ field_id: 8, field_name: 'status' })
	})

	it('take the label of the field, so a renamed field shows its new name', () => {
		const view = { columns: [{ field_id: 8, field_name: 'status', field_label: 'Oude naam', field_type: 'select' }] }

		expect(viewColumnConfigs(view, FIELDS)[0].field_label).toBe('Status')
	})

	it('keep the copy of a field that is gone', () => {
		const view = { columns: [{ field_id: 99, field_name: 'oud', field_label: 'Oud', field_type: 'number' }] }

		expect(viewColumnConfigs(view, FIELDS)[0].field_type).toBe('number')
	})

	it('keep their copy while the fields have not loaded', () => {
		const view = { columns: [{ field_name: 'status', field_type: 'select' }] }

		expect(viewColumnConfigs(view, null)[0].field_type).toBe('select')
	})

	it('keep the view order, skip hidden and repeated columns', () => {
		const view = { columns: [
			{ field_name: 'status' },
			{ field_name: 'deadline', visible: false },
			{ field_name: 'status' },
			{ field_name: 'deadline' },
		] }

		expect(viewColumnConfigs(view, FIELDS).map((c) => c.field_name)).toEqual(['status', 'deadline'])
	})
})

describe('saving filters into a view', () => {
	it('leaves the highlight rules out, so the server keeps its own', () => {
		const columns = [
			{ field_id: 8, field_name: 'status', visible: true, format: [{ op: 'empty', tone: 'warning' }] },
			{ field_id: 7, field_name: 'deadline', filterable: false },
		]

		expect(columnsWithoutRules(columns)).toEqual([
			{ field_id: 8, field_name: 'status', visible: true },
			{ field_id: 7, field_name: 'deadline', filterable: false },
		])
		expect(columns[0].format).toHaveLength(1)
	})
})

describe('the highlight rules the view editor sends', () => {
	const OVERDUE = { op: 'before', value: { today: 0 }, tone: 'error', preset: 'overdue' }
	const SOON = { op: 'nextDays', value: 7, tone: 'warning' }
	const column = (id, rules, extra = {}) => ({
		field_id: id, field_name: `f${id}`, field_label: `F${id}`, visible: true, filterable: true,
		rules: rules.map((rule, i) => ({ key: `${id}-${i}`, rule })), ...extra,
	})
	const baselineOf = (columns) => new Map(columns.map((c) => [c.field_id, JSON.stringify(c.rules.map((r) => r.rule))]))

	it('copies rules deeply, also from a reactive list', () => {
		const rules = reactive([OVERDUE])
		const copy = cloneRules(rules)
		copy[0].value.today = 5
		expect(rules[0].value.today).toBe(0)
		expect(cloneRules('x')).toEqual([])
		expect(cloneRules(undefined)).toEqual([])
	})

	it('leaves out the rules of a column that did not change', () => {
		const columns = [column(1, [OVERDUE, SOON]), column(2, [], { visible: false })]
		const { columns: sent, sent: map } = viewColumnsPayload(columns, baselineOf(columns))

		expect(sent.map((c) => 'format' in c)).toEqual([false, false])
		expect(sent[1]).toEqual({ field_id: 2, field_name: 'f2', field_label: 'F2', visible: false, filterable: true })
		expect(map).toEqual([{ fieldId: 1, keys: null }, { fieldId: 2, keys: null }])
	})

	it('sends all rules of a changed column, in order, and an emptied one as []', () => {
		const columns = [column(1, [OVERDUE, SOON]), column(2, [SOON])]
		const baseline = baselineOf(columns)
		columns[0].rules.reverse()
		columns[1].rules = []

		const { columns: sent, sent: map } = viewColumnsPayload(columns, baseline)

		expect(sent[0].format).toEqual([SOON, OVERDUE])
		expect(sent[1].format).toEqual([])
		expect(map[0]).toEqual({ fieldId: 1, keys: ['1-1', '1-0'] })
		expect(JSON.stringify(sent)).not.toContain('"key"')
	})

	it('does not send rules that were moved and moved back', () => {
		const columns = [column(1, [OVERDUE, SOON])]
		const baseline = baselineOf(columns)
		columns[0].rules.reverse()
		columns[0].rules.reverse()

		expect('format' in viewColumnsPayload(columns, baseline).columns[0]).toBe(false)
	})

	it('always sends a column whose baseline is unknown', () => {
		const columns = [column(1, [SOON])]
		expect(viewColumnsPayload(columns, new Map([[1, null]])).columns[0].format).toEqual([SOON])
	})

	it('sends the columns it could not show back as they came, last, without rules', () => {
		const carried = [{ field_id: 99, field_name: 'oud', visible: true, format: [SOON] }]
		const { columns: sent, sent: map } = viewColumnsPayload([column(1, [])], baselineOf([column(1, [])]), carried)

		expect(sent[1]).toEqual({ field_id: 99, field_name: 'oud', visible: true })
		expect(map[1]).toEqual({ fieldId: 99, keys: null })
		expect(carried[0].format).toEqual([SOON])
	})
})

describe('a new view made from the grid', () => {
	it('takes the columns on screen with their rules', () => {
		const configs = [
			{ field_id: 1, field_name: 'deadline', field_label: 'Deadline', field_type: 'date', format: [{ op: 'empty', tone: 'info' }] },
			{ field_id: 2, field_name: 'status', field_label: 'Status', visible: false, format: [] },
		]
		const columns = columnsForNewView(configs)

		expect(columns).toEqual([
			{ field_id: 1, field_name: 'deadline', field_label: 'Deadline', visible: true, filterable: true, format: [{ op: 'empty', tone: 'info' }] },
			{ field_id: 2, field_name: 'status', field_label: 'Status', visible: false, filterable: true },
		])
		columns[0].format[0].tone = 'error'
		expect(configs[0].format[0].tone).toBe('info')
	})
})

describe('sorting a view', () => {
	it("sorts by the field's own type", () => {
		const view = { sort_field: 'deadline', columns: [{ field_name: 'deadline', field_type: 'text' }] }

		expect(sortFieldType(view, FIELDS)).toBe('date')
	})

	it("falls back to the view's copy, then to text", () => {
		expect(sortFieldType({ sort_field: 'oud', columns: [{ field_name: 'oud', field_type: 'number' }] }, FIELDS)).toBe('number')
		expect(sortFieldType({ sort_field: 'nergens', columns: [] }, null)).toBe('text')
	})
})

describe('the view a folder opens in', () => {
	const views = [{ id: 1, name: 'A' }, { id: 2, name: 'Star', is_default: true }, { id: 3, name: 'C' }]
	const open = (from) => viewToOpen(views, from)?.id ?? null

	it('is the view in the link, none for "default", or none when that one is gone', () => {
		expect(open({ linkView: '3' })).toBe(3)
		expect(open({ linkView: 'default' })).toBeNull()
		expect(open({ linkView: 'default', stayed: { viewId: 1 } })).toBeNull()
		expect(open({ linkView: '9' })).toBeNull()
	})

	it('stays the view you were in within the team folder, "Default" too', () => {
		expect(open({ stayed: { viewId: 1 } })).toBe(1)
		expect(open({ stayed: { viewId: null } })).toBeNull()
		// The link goes first: back and forward in the browser.
		expect(open({ linkView: '3', stayed: { viewId: 1 } })).toBe(3)
	})

	it('falls back to the default view when the one you were in is gone', () => {
		expect(open({ stayed: { viewId: 9 } })).toBe(2)
	})

	it('is the default view otherwise, or none without one', () => {
		expect(open({})).toBe(2)
		expect(viewToOpen([{ id: 1 }], {})).toBeNull()
	})
})
