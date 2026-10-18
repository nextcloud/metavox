import { readFileSync } from 'node:fs'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, k) => vars[k]),
	translatePlural: (app, s, p, n) => (n === 1 ? s : p).replace('%n', n),
	getCanonicalLocale: () => 'nl-NL',
}))

const rules = await import('../../src/utils/valueRules.js')
const { vectors } = JSON.parse(readFileSync('tests/fixtures/value-rules.json', 'utf8'))
const blind = JSON.parse(readFileSync('tests/fixtures/value-rules-blind.json', 'utf8')).vectors

describe('a rule against a value', () => {
	it.each(vectors.map((v) => [v.name, v]))('%s', (name, v) => {
		expect(rules.evaluate(v.rule, v.value, v.type, v.ctx)).toBe(v.expected)
	})
})

describe('a rule against a value, written without the code', () => {
	it.each(blind.map((v) => [v.name, v]))('%s', (name, v) => {
		expect(rules.compileRules([v.rule], v.type)).not.toBeNull()
		expect(rules.evaluate(v.rule, v.value, v.type, v.ctx)).toBe(v.expected)
	})
})

describe('a number', () => {
	it('is read the way PHP reads it', () => {
		expect(rules.parseNumber(' 5\n')).toBe(5)
		expect(rules.parseNumber('.5')).toBe(0.5)
		expect(rules.parseNumber('1e3')).toBe(1000)
		for (const value of ['\u00a05', '5\u00a0', '1,5', '12abc', '0x1A', '', ' ']) expect(rules.parseNumber(value)).toBeNull()
	})

	it('is finite', () => {
		for (const value of ['1e400', '-1e400', '9'.repeat(400), Infinity]) expect(rules.parseNumber(value)).toBeNull()
		expect(rules.compileRules([{ op: 'greater', value: '1e400', tone: 'info' }], 'number')).toBeNull()
		expect(rules.evaluate({ op: 'is', value: '1e400' }, '5', 'number', CTX)).toBeNull()
		expect(rules.evaluate({ op: 'greater', value: '5' }, '1e400', 'number', CTX)).toBeNull()
	})
})

const CTX = { today: '2026-10-06', me: 'alice', truncated: false, isFolder: false }
const DEADLINE = [
	{ op: 'before', value: { today: 0 }, tone: 'error', preset: 'overdue' },
	{ op: 'nextDays', value: 7, tone: 'warning', label: 'Binnenkort' },
	{ op: 'empty', tone: 'info' },
]

describe('the rules of a column', () => {
	it('give the first one that matches', () => {
		const compiled = rules.compileRules(DEADLINE, 'date')
		expect(rules.matchRules(compiled, '2026-10-01', 'date', CTX).index).toBe(0)
		expect(rules.matchRules(compiled, '2026-10-08', 'date', CTX).index).toBe(1)
		expect(rules.matchRules(compiled, '', 'date', CTX).index).toBe(2)
		expect(rules.matchRules(compiled, '2027-01-01', 'date', CTX)).toBeNull()
	})

	it('stop at a rule that cannot be known, so one below never colours instead', () => {
		const compiled = rules.compileRules([
			{ op: 'contains', value: 'geheim', tone: 'error' },
			{ op: '!empty', tone: 'info' },
		], 'textarea')
		expect(rules.matchRules(compiled, 'Lang', 'textarea', { ...CTX, truncated: true })).toBeNull()
	})

	it('do not read a cut-off checkbox, not even one that looks ticked', () => {
		// "1;#…" over 512 characters arrives cut back to "1".
		const compiled = rules.compileRules([
			{ op: 'isTrue', tone: 'success' },
			{ op: 'isFalse', tone: 'warning' },
		], 'checkbox')
		expect(rules.matchRules(compiled, '1', 'checkbox', { ...CTX, truncated: true })).toBeNull()
		expect(rules.matchRules(compiled, '0', 'checkbox', { ...CTX, truncated: true })).toBeNull()
		expect(rules.matchRules(compiled, '1', 'checkbox', CTX).index).toBe(0)
	})

	it('follow "today" as it moves, without compiling again', () => {
		const compiled = rules.compileRules(DEADLINE, 'date')
		expect(rules.matchRules(compiled, '2026-10-06', 'date', CTX).index).toBe(1)
		expect(rules.matchRules(compiled, '2026-10-06', 'date', { ...CTX, today: '2026-10-07' }).index).toBe(0)
	})

	it('leave out what does not fit the type or is malformed', () => {
		const compiled = rules.compileRules([
			{ op: 'contains', value: 'x', tone: 'error' },
			{ op: 'before', value: { today: 1 }, tone: 'error' },
			{ op: 'nextDays', value: 0, tone: 'error' },
			{ op: 'nextDays', value: 3651, tone: 'error' },
			{ op: 'before', value: '2026-02-30', tone: 'error' },
			{ op: 'empty', tone: 'purple' },
			{ op: 'empty', value: 'x', tone: 'info' },
			null,
			{ op: 'after', value: '2026-01-01', tone: 'success' },
		], 'date')
		expect(compiled.map((c) => c.index)).toEqual([8])
		expect(rules.compileRules([{ op: 'is', value: { me: true }, tone: 'info' }], 'text')).toBeNull()
		expect(rules.compileRules([{ op: '!is', value: { me: true }, tone: 'info' }], 'user')).toBeNull()
		expect(rules.compileRules([{ op: 'greater', value: '1,5', tone: 'info' }], 'number')).toBeNull()
		expect(rules.compileRules([{ op: 'oneOf', value: [], tone: 'info' }], 'select')).toBeNull()
		for (const op of ['before', 'after', 'is']) expect(rules.compileRules([{ op, value: null, tone: 'info' }], 'date')).toBeNull()
		expect(rules.compileRules([{ op: 'before', value: '2026-01-14T08:00', tone: 'info' }], 'date')).toBeNull()
		expect(rules.compileRules('nope', 'text')).toBeNull()
	})

	it('take at most ten rules', () => {
		const many = Array.from({ length: 12 }, () => ({ op: '!empty', tone: 'info' }))
		expect(rules.compileRules(many, 'text')).toHaveLength(10)
	})
})

describe('what a rule means', () => {
	const date = { field_type: 'date' }
	it('reads as one phrase per condition', () => {
		expect(rules.describeRule({ op: 'before', value: { today: 0 } }, date)).toBe('before today')
		expect(rules.describeRule({ op: 'is', value: { today: 0 } }, date)).toBe('today')
		expect(rules.describeRule({ op: 'nextDays', value: 1 }, date)).toBe('within the next day')
		expect(rules.describeRule({ op: 'nextDays', value: 7 }, date)).toBe('within the next 7 days')
		expect(rules.describeRule({ op: 'oneOf', value: ['Afgewezen', 'Verlopen'] }, { field_type: 'select' })).toBe('is one of Afgewezen, Verlopen')
		expect(rules.describeRule({ op: 'oneOf', value: ['Rood'] }, { field_type: 'multiselect' })).toBe('contains one of Rood')
		expect(rules.describeRule({ op: 'is', value: { me: true } }, { field_type: 'user' })).toBe('is the person viewing')
	})

	it('shows a date and a number the way the user writes them', () => {
		expect(rules.describeRule({ op: 'before', value: '2026-01-14' }, date)).toBe('before 14-1-2026')
		expect(rules.describeRule({ op: 'greater', value: '10000' }, { field_type: 'number' })).toBe('greater than 10.000')
		expect(rules.describeRule({ op: 'less', value: '0.12345' }, { field_type: 'number' })).toBe('less than 0,12345')
	})

	it('still says what a rule means once the field changed type', () => {
		const text = { field_type: 'text' }
		expect(rules.describeRule({ op: 'before', value: { today: 0 } }, text)).toBe('before today')
		expect(rules.describeRule({ op: 'nextDays', value: 7 }, text)).toBe('within the next 7 days')
		expect(rules.describeRule({ op: 'greater', value: '5' }, text)).toBe('greater than 5')
		expect(rules.describeRule({ op: 'is', value: '2026-01-14' }, text)).toBe('is 2026-01-14')
	})

	it('has a phrase for a rule it does not know', () => {
		const unknown = 'a condition this version does not know'
		expect(rules.describeRule({ op: 'matches', value: '/x/' }, { field_type: 'text' })).toBe(unknown)
		expect(rules.describeRule({ op: 'oneOf', value: 'x' }, { field_type: 'select' })).toBe(unknown)
		expect(rules.describeRule({ op: 'before', value: { today: 2 } }, date)).toBe(unknown)
		expect(rules.describeRule(null, date)).toBe(unknown)
	})
})

describe('the name of the state', () => {
	it('is the own label first, then the preset, then the meaning', () => {
		expect(rules.stateName(DEADLINE[1], { field_type: 'date' })).toBe('Binnenkort')
		expect(rules.stateName(DEADLINE[0], { field_type: 'date' })).toBe('Overdue')
		expect(rules.stateName(DEADLINE[2], { field_type: 'date' })).toBe('is empty')
	})

	it('drops a preset name that no longer fits its rule', () => {
		expect(rules.stateName({ op: 'nextDays', value: 3, tone: 'warning', preset: 'overdue' }, { field_type: 'date' })).toBe('within the next 3 days')
		expect(rules.stateName({ op: 'empty', tone: 'info', preset: 'constructor' }, { field_type: 'text' })).toBe('is empty')
		expect(rules.stateName({ op: 'empty', tone: 'info', preset: ['missing'] }, { field_type: 'text' })).toBe('is empty')
	})

	it('cleans a label of hidden characters and keeps it short', () => {
		expect(rules.cleanLabel('‮Klaar​ ')).toBe('Klaar')
		expect(rules.cleanLabel('👩‍💻 Werk')).toBe('👩‍💻 Werk')
		expect([...rules.cleanLabel('x'.repeat(60))]).toHaveLength(40)
		expect(rules.stateName({ op: 'empty', tone: 'info', label: '​' }, { field_type: 'text' })).toBe('is empty')
	})
})
