import { readFileSync } from 'node:fs'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, translatePlural: (app, s, p, n) => (n === 1 ? s : p), getCanonicalLocale: () => 'en-US' }))

const ops = await import('../../src/utils/valueOperators.js')
const fixture = JSON.parse(readFileSync('tests/fixtures/value-operators.json', 'utf8'))
// The operator list of the Flow check, not the whole file: a name in a comment does not count.
const php = readFileSync('lib/Flow/MetadataCheck.php', 'utf8')
const flowBlock = php.match(/VALID_OPERATORS = \[([\s\S]*?)\];/)[1].replace(/\/\/.*$/gm, '')
const flowOperators = [...flowBlock.matchAll(/'([^']+)'/g)].map((m) => m[1])

describe('the operators of highlight rules', () => {
	it('are the ones in the shared fixture, per type', () => {
		for (const [type, list] of Object.entries(fixture.types)) expect(ops.operatorsFor(type)).toEqual(list)
		expect(ops.operatorsFor('filelink')).toEqual(fixture.fallback)
		expect(ops.operatorsFor('something new')).toEqual(fixture.fallback)
		expect(ops.operatorsFor('constructor')).toEqual(fixture.fallback)
		expect(ops.operatorsFor('__proto__')).toEqual(fixture.fallback)
		expect({ ...ops.OPERAND_KINDS }).toEqual(fixture.operandKinds)
	})

	it('use the tones, presets and limits the server checks', async () => {
		const rules = await import('../../src/utils/valueRules.js')
		expect([...ops.TONES]).toEqual(fixture.tones)
		expect(Object.keys(ops.PRESETS)).toEqual(fixture.presets)
		expect([rules.MAX_RULES, rules.MAX_LIST, rules.MAX_TEXT, rules.MAX_LABEL, rules.MAX_DAYS])
			.toEqual([fixture.limits.rules, fixture.limits.list, fixture.limits.text, fixture.limits.label, fixture.limits.days])
	})

	it('carry the names of the Flow check, except the two new ones', () => {
		expect([...ops.NOT_IN_FLOW]).toEqual(fixture.notInFlow)
		for (const op of Object.keys(fixture.operandKinds)) {
			if (fixture.notInFlow.includes(op)) expect(flowOperators).not.toContain(op)
			else expect(flowOperators).toContain(op)
		}
	})

	it('are what the Flow editor offers, without the two new ones', () => {
		expect(ops.flowOperatorsFor('multiselect')).toEqual(['oneOf', 'containsAll', 'empty', '!empty'])
		expect(ops.flowOperatorsFor('date')).toEqual(['before', 'after', 'is', 'empty', '!empty'])
		expect(ops.flowOperatorsFor('filelink')).toEqual(fixture.fallback)
	})
})

describe('the conditions of the editor', () => {
	it('stand for an operator the type allows', () => {
		for (const type of [...Object.keys(fixture.types), 'filelink']) {
			for (const c of ops.conditionsFor(type)) expect(ops.operatorsFor(type)).toContain(c.op)
		}
	})

	it('read back from the rule they make', () => {
		expect(ops.conditionOf({ op: 'before', value: { today: 0 } }, 'date')).toBe('beforeToday')
		expect(ops.conditionOf({ op: 'is', value: { today: 0 } }, 'date')).toBe('today')
		expect(ops.conditionOf({ op: 'is', value: '2026-01-02' }, 'date')).toBe('onDate')
		expect(ops.conditionOf({ op: 'nextDays', value: 7 }, 'date')).toBe('nextDays')
		expect(ops.conditionOf({ op: 'is', value: { me: true } }, 'user')).toBe('isViewer')
		// A named person is not offered: such a rule works, but has no condition in the editor.
		expect(ops.conditionOf({ op: 'is', value: 'bob' }, 'user')).toBeNull()
		expect(ops.conditionOf({ op: 'oneOf', value: ['a'] }, 'select')).toBe('oneOf')
		expect(ops.conditionOf({ op: 'oneOf', value: ['a'] }, 'multiselect')).toBe('containsOneOf')
		expect(ops.conditionOf({ op: 'empty' }, 'filelink')).toBe('empty')
	})

	it('know no condition for a rule that does not fit', () => {
		expect(ops.conditionOf({ op: 'contains', value: 'x' }, 'date')).toBeNull()
		expect(ops.conditionOf({ op: 'before', value: { today: 3 } }, 'date')).toBeNull()
		expect(ops.conditionOf({ op: 'empty', value: 'x' }, 'text')).toBeNull()
		expect(ops.conditionOf({ op: 'is', value: '2026-02-30' }, 'date')).toBeNull()
	})
})

describe('the presets', () => {
	it('fit their types, missing never a checkbox', () => {
		expect(ops.presetsFor('date')).toEqual(['overdue', 'dueToday', 'dueSoon', 'missing'])
		expect(ops.presetsFor('checkbox')).toEqual(['checked', 'unchecked'])
		expect(ops.presetsFor('user')).toEqual(['missing', 'assignedToViewer'])
		expect(ops.presetsFor('text')).toEqual(['missing'])
	})

	it('labels "one of" for a list as "contains"', () => {
		expect(ops.conditionLabel('containsOneOf')).toBe('Contains one of')
		expect(ops.conditionLabel('oneOf')).toBe('Is one of')
	})

	it('keep "due soon" below "due today"', () => {
		expect(ops.PRESET_ORDER.indexOf('dueToday')).toBeLessThan(ops.PRESET_ORDER.indexOf('dueSoon'))
	})

	it('name the viewer as "you" only for who looks', () => {
		expect(ops.presetName('assignedToViewer')).toBe('Assigned to the person viewing')
		expect(ops.presetName('assignedToViewer', true)).toBe('Assigned to you')
	})
})
