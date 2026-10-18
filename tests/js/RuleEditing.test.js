import { readFileSync } from 'node:fs'
import { describe, expect, it, vi } from 'vitest'

let locale = 'en-US'
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, k) => vars[k]),
	translatePlural: (app, s, p, n) => (n === 1 ? s : p).replace('%n', n),
	getCanonicalLocale: () => locale,
}))

const rules = await import('../../src/utils/valueRules.js')
const ops = await import('../../src/utils/valueOperators.js')
const TYPES = Object.keys(JSON.parse(readFileSync('tests/fixtures/value-operators.json', 'utf8')).types)

const draft = (condition, value, extra = {}) => ({ condition, value, tone: 'info', label: '', preset: null, ...extra })
const valueFor = (input) => ({ days: '14', number: '1.5', date: '2026-03-01', text: 'Concept', option: 'Concept', options: ['A', 'B'] })[input] ?? ''

describe('a rule from the form', () => {
	it.each(TYPES)('every condition of %s makes a rule the grid uses, and reads back the same', (type) => {
		for (const locale_ of ['en-US', 'nl-NL']) {
			locale = locale_
			for (const condition of ops.conditionsFor(type)) {
				const made = rules.ruleFromDraft(draft(condition.id, valueFor(condition.input)), type)
				expect(made.rule, `${type} ${condition.id}`).toBeDefined()
				expect(rules.compileRules([made.rule], type)).not.toBeNull()
				expect(rules.ruleFromDraft(rules.ruleDraft(made.rule, type), type)).toEqual(made)
			}
		}
		locale = 'en-US'
	})

	it('takes days as a whole number from 1 to 3650', () => {
		expect(rules.ruleFromDraft(draft('nextDays', ' 7 '), 'date').rule.value).toBe(7)
		for (const bad of ['7.5', '0', '3651', '', 'abc']) {
			expect(rules.ruleFromDraft(draft('nextDays', bad), 'date')).toMatchObject({ part: 'value' })
		}
	})

	it('takes a decimal comma only where the language writes one', () => {
		expect(rules.ruleFromDraft(draft('greater', ' 1.5 '), 'number').rule.value).toBe('1.5')
		expect(rules.ruleFromDraft(draft('greater', '1,5'), 'number')).toMatchObject({ part: 'value', message: 'Enter a number' })
		locale = 'nl-NL'
		expect(rules.ruleFromDraft(draft('greater', '1,5'), 'number').rule.value).toBe('1.5')
		// And shows it back that way.
		expect(rules.ruleDraft({ op: 'greater', value: '1.5', tone: 'info' }, 'number').value).toBe('1,5')
		locale = 'en-US'
	})

	it('checks dates, text and option lists', () => {
		expect(rules.ruleFromDraft(draft('onDate', '2026-02-30'), 'date')).toMatchObject({ part: 'value' })
		expect(rules.ruleFromDraft(draft('contains', '  draft  '), 'text').rule.value).toBe('draft')
		expect(rules.ruleFromDraft(draft('is', ''), 'select')).toMatchObject({ part: 'value', message: 'Choose an option' })
		expect(rules.ruleFromDraft(draft('oneOf', []), 'select')).toMatchObject({ part: 'value', message: 'Choose at least one option' })
		expect(rules.ruleFromDraft(draft('oneOf', Array.from({ length: 51 }, (_, i) => `o${i}`)), 'select')).toMatchObject({ part: 'value' })
	})

	it('stores today and the person viewing in their exact shapes', () => {
		expect(rules.ruleFromDraft(draft('beforeToday', ''), 'date').rule).toEqual({ op: 'before', value: { today: 0 }, tone: 'info' })
		expect(rules.ruleFromDraft(draft('isViewer', ''), 'user').rule).toEqual({ op: 'is', value: { me: true }, tone: 'info' })
		expect(rules.ruleFromDraft(draft('empty', 'left over'), 'text').rule).toEqual({ op: 'empty', tone: 'info' })
	})

	it('refuses a name over 40 characters instead of cutting it, and strips hidden characters', () => {
		const forty = '👩‍💻' + 'x'.repeat(37) // 3 code points + 37
		expect(rules.ruleFromDraft(draft('empty', '', { label: forty }), 'text').rule.label).toBe(forty)
		expect(rules.ruleFromDraft(draft('empty', '', { label: 'x'.repeat(41) }), 'text')).toMatchObject({ part: 'label' })
		expect(rules.ruleFromDraft(draft('empty', '', { label: '‮ab​ ' }), 'text').rule.label).toBe('ab')
		expect('label' in rules.ruleFromDraft(draft('empty', '', { label: '  ' }), 'text').rule).toBe(false)
	})

	it('keeps a preset while it still is that preset', () => {
		const soon = rules.presetDraft('dueSoon', 'date')
		expect(rules.ruleFromDraft({ ...soon, tone: 'error', label: 'Bijna' }, 'date').rule.preset).toBe('dueSoon')
		expect(rules.ruleFromDraft({ ...soon, value: '14' }, 'date').rule.preset).toBeUndefined()
	})

	it('starts a rule of one\'s own on the first condition, in blue', () => {
		expect(rules.customDraft('date')).toMatchObject({ condition: 'beforeToday', tone: 'info' })
		expect(rules.switchCondition(rules.customDraft('date'), 'nextDays', 'date').value).toBe('7')
	})
})

describe('the rules of a column', () => {
	const preset = (id) => ({ ...ops.PRESETS[id].rule, preset: id })

	it('puts a preset before the presets that come after it', () => {
		expect(rules.presetInsertIndex([preset('dueSoon')], 'overdue')).toBe(0)
		expect(rules.presetInsertIndex([{ op: 'empty', tone: 'info' }, preset('dueSoon')], 'overdue')).toBe(1)
		expect(rules.presetInsertIndex([{ op: 'empty', tone: 'info' }], 'missing')).toBe(1)
		expect(rules.presetInsertIndex([preset('dueSoon')], 'checked')).toBe(1)
		// A preset id that no longer fits its rule counts as a rule of one's own.
		expect(rules.presetInsertIndex([{ ...preset('dueSoon'), value: 30 }], 'overdue')).toBe(1)
	})

	it('tells editable, working and broken rules apart', () => {
		expect(rules.ruleStatus({ op: 'before', value: { today: 0 }, tone: 'error' }, 'date')).toBe('edit')
		expect(rules.ruleStatus({ op: 'is', value: 'bob', tone: 'info' }, 'user')).toBe('fixed')
		expect(rules.ruleStatus({ op: 'is', value: 5, tone: 'info' }, 'number')).toBe('fixed')
		expect(rules.ruleStatus({ op: 'before', value: { today: 0 }, tone: 'error' }, 'text')).toBe('misfit')
		expect(rules.ruleStatus({ op: 'nope', tone: 'error' }, 'text')).toBe('misfit')
		expect(rules.ruleStatus({ op: 'empty', tone: 'purple' }, 'text')).toBe('misfit')
	})

	it('gives a rule its own name only when it has one', () => {
		expect(rules.ownName({ op: 'empty', tone: 'info', label: 'Nog goedkeuren' })).toBe('Nog goedkeuren')
		expect(rules.ownName({ ...ops.PRESETS.overdue.rule, preset: 'overdue' })).toBe('Overdue')
		expect(rules.ownName({ ...ops.PRESETS.assignedToViewer.rule, preset: 'assignedToViewer' }, false)).toBe('Assigned to the person viewing')
		// Without one the editor shows what the rule means once, not twice.
		expect(rules.ownName({ op: 'isFalse', tone: 'warning' })).toBe('')
		expect(rules.stateName({ op: 'isFalse', tone: 'warning' }, { field_type: 'checkbox' })).toBe('is not checked')
	})

	it('names the colours', () => {
		expect(ops.TONES.map(ops.toneName)).toEqual(['Red', 'Orange', 'Green', 'Blue'])
	})
})

