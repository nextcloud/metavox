import { readFileSync } from 'node:fs'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, k) => vars[k]),
	translatePlural: (app, s, p, n) => (n === 1 ? s : p).replace('%n', n),
	getCanonicalLocale: () => 'en-US',
}))

const { previewRows, MAX_VALUES } = await import('../../src/filesplugin/columns/RulePreviewRows.js')
const ops = await import('../../src/utils/valueOperators.js')
const rules = await import('../../src/utils/valueRules.js')
const TYPES = Object.keys(JSON.parse(readFileSync('tests/fixtures/value-operators.json', 'utf8')).types)

const CTX = { today: '2026-10-12', me: 'sam' }
const OPTIONS = ['Concept', 'Review', 'Final', 'Archived', 'A', 'B', 'Rejected']
const field = (type, extra = {}) => ({ field_label: 'Column', field_type: type, field_options: OPTIONS, ...extra })
const preset = (id) => ({ ...ops.PRESETS[id].rule, preset: id })
const rows = (type, list, extra) => previewRows(field(type, extra), list, CTX)
const shown = (list) => list.map((r) => [r.value, r.state?.tone ?? null])

describe('the preview of a column', () => {
	it('shows the date presets on the days around today, the empty one last', () => {
		const list = rows('date', [preset('overdue'), preset('dueToday'), preset('dueSoon'), preset('missing')])

		expect(shown(list)).toEqual([
			['2026-10-11', 'error'],
			['2026-10-12', 'warning'],
			['2026-10-13', 'warning'],
			['2026-10-19', 'warning'],
			['2026-10-20', null],
			['', 'warning'],
		])
		expect(list.map((r) => r.state?.note ?? null)).toEqual(['Overdue', 'Due today', 'Due soon', 'Due soon', null, 'Missing'])
		expect(list.at(-1)).toMatchObject({ text: '—', empty: true })
	})

	it.each(TYPES)('has a row for every condition of %s on its own', (type) => {
		const value = (input) => ({ days: '14', number: '1.5', date: '2026-03-01', text: 'Concept', option: 'Concept', options: ['A', 'B'] })[input] ?? ''
		for (const condition of ops.conditionsFor(type)) {
			const { rule } = rules.ruleFromDraft({ condition: condition.id, value: value(condition.input), tone: 'error', label: '', preset: null }, type)
			expect(rows(type, [rule]).some((r) => r.state), `${type} ${condition.id}`).toBe(true)
		}
	})

	// What the review found: a rule further down lost its row to the ones above.
	it.each([
		['date', [{ op: 'before', value: '2026-01-01', tone: 'info' }, { op: 'after', value: '2027-01-01', tone: 'success' }, preset('dueToday')]],
		['number', [{ op: 'less', value: '0', tone: 'error' }, { op: 'less', value: '10', tone: 'warning' }, { op: 'greater', value: '100', tone: 'success' }]],
		['number', [{ op: 'is', value: '0.0000001', tone: 'error' }, { op: 'greaterOrEqual', value: '0.00000015', tone: 'info' }]],
		['number', [{ op: 'greater', value: '1e21', tone: 'error' }, { op: 'less', value: '-9007199254740993', tone: 'info' }]],
		['select', [{ op: 'oneOf', value: ['Concept', 'Review', 'Final', 'Archived', 'A', 'B'], tone: 'info' }, { op: 'is', value: 'Rejected', tone: 'error' }]],
		['multiselect', [{ op: 'containsAll', value: ['A', 'B'], tone: 'success' }, { op: 'oneOf', value: ['Concept', 'Review'], tone: 'info' }, { op: 'oneOf', value: ['Rejected'], tone: 'error' }]],
		['text', [...['a', 'b', 'c', 'd', 'e'].map((v) => ({ op: 'is', value: v, tone: 'info' })), { op: 'contains', value: 'zzz', tone: 'error' }]],
		['url', [{ op: '!contains', value: 'https', tone: 'warning' }]],
		['text', [{ op: '!is', value: 'Some text', tone: 'warning' }]],
	])('gives every rule a row of its own (%s)', (type, list) => {
		const tones = new Set(rows(type, list).filter((r) => r.value !== '').map((r) => r.state?.tone))

		for (const rule of list.slice(0, MAX_VALUES)) expect(tones, JSON.stringify(rule)).toContain(rule.tone)
	})

	it('shows a number once, however it is written', () => {
		const values = rows('number', [{ op: 'lessOrEqual', value: '2', tone: 'info' }, { op: 'greater', value: '2.9', tone: 'error' }]).map((r) => r.value)

		expect(values.filter(Boolean).map(Number)).toEqual([...new Set(values.filter(Boolean).map(Number))])
	})

	it('lets the top rule win, as the grid does', () => {
		const list = rows('text', [{ op: 'contains', value: 'secret', tone: 'error' }, { op: '!empty', tone: 'success' }])

		expect(shown(list)).toEqual([['secret', 'error'], ['Some text', 'success'], ['', null]])
	})

	it('shows at most five values, the options a rule names among them, in the field\'s order', () => {
		const list = rows('select', [{ op: 'is', value: 'rejected', tone: 'error' }])

		expect(list).toHaveLength(MAX_VALUES + 1)
		// "rejected" is the option "Rejected", last in the field.
		expect(shown(list)).toEqual([['Concept', null], ['Review', null], ['Final', null], ['Archived', null], ['Rejected', 'error'], ['', null]])
	})

	it('puts the options of "contains all of" together in one value', () => {
		const list = rows('multiselect', [{ op: 'containsAll', value: ['A', 'B'], tone: 'success' }])

		expect(list.find((r) => r.state)).toMatchObject({ value: 'A;#B', text: 'A, B' })
	})

	it('shows a number rule on its number and just across it', () => {
		expect(shown(rows('number', [{ op: 'greater', value: '100', tone: 'error' }]))).toEqual([['100', null], ['101', 'error'], ['', null]])
		expect(shown(rows('number', [{ op: 'lessOrEqual', value: '2.50', tone: 'info' }]))).toEqual([['2.5', 'info'], ['2.6', null], ['', null]])
		expect(rows('number', []).map((r) => r.value)).toEqual(['1', '10', '100', ''])
	})

	it('shows a ticked and an unticked box, as the grid writes them', () => {
		const list = rows('checkbox', [preset('checked')])

		expect(list.map((r) => [r.text, r.state?.tone ?? null])).toEqual([['✓', 'success'], ['—', null]])
	})

	it('shows you and someone else, an avatar for you only', () => {
		const list = rows('user', [preset('assignedToViewer')])

		expect(list.map((r) => [r.text, r.person, r.state?.note ?? null])).toEqual([
			['sam', true, 'Assigned to you'],
			['Someone else', false, null],
			['—', false, null],
		])
	})

	it('gives a date with a time a time, and still matches by the day', () => {
		const list = rows('date', [preset('dueToday')], { field_options: { includeTime: true } })

		expect(list.find((r) => r.state)?.value).toBe('2026-10-12T09:00:00')
	})

	it('takes nothing from a rule that does not fit the field', () => {
		const list = rows('text', [{ op: 'greater', value: '5', tone: 'error' }])

		expect(shown(list)).toEqual([['Some text', null], ['', null]])
	})

	it('shows the same state as the grid', () => {
		const list = [preset('overdue'), { op: 'nextDays', value: 7, tone: 'warning', label: 'Soon' }]
		const compiled = rules.compileRules(list, 'date')
		for (const row of rows('date', list)) {
			expect(row.state).toEqual(rules.cellState(compiled, row.value, field('date'), { ...CTX, truncated: false }))
		}
	})
})
