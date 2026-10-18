/**
 * The rows of the editor's preview: a few values for a column, each shown and
 * coloured the way the grid does it. Each rule first gets a value it matches,
 * then come the values just across its edge, then the field's own; so every
 * rule has a row, as far as five go.
 */

import { translate as t } from '@nextcloud/l10n'
import { addDays, isDay } from '../../utils/dateField.js'
import { MULTI_DELIM, fieldIncludesTime } from '../../utils/fieldValue.js'
import { isMe, isToday } from '../../utils/valueOperators.js'
import { cellState, compileRules, parseNumber } from '../../utils/valueRules.js'
import { formatValue } from './ColumnUtils.js'
import { optionValues as valuesOf } from '../../utils/fieldOptions.js'

/** Rows with a value; an empty one comes after them. */
export const MAX_VALUES = 5

const lower = (value) => String(value).trim().toLowerCase()

// The first of each value, by what makes two equal: for text the way the
// rules compare, case and spaces aside.
function unique(values, by = lower) {
	const seen = new Set()
	return values.filter((value) => !seen.has(by(value)) && seen.add(by(value)))
}

// The texts a rule names, trimmed.
const textsOf = (rule) => [].concat(rule.value ?? [])
	.filter((value) => typeof value === 'string' && value.trim() !== '')
	.map((value) => value.trim())

/**
 * A value of the field that none of the rules names, and that "is not" and
 * "does not contain" let through, so those have a row too.
 *
 * @param {string} type field type
 * @param {{rule: object}[]} compiled the column's working rules
 * @return {string}
 */
export function otherValue(type, compiled = []) {
	const candidates = {
		url: ['https://example.com', 'http://example.org'],
		filelink: ['example.pdf', 'report.docx'],
		user: [t('metavox', 'Someone else')],
	}[type] ?? [t('metavox', 'Some text'), t('metavox', 'Something else')]
	const named = new Set(compiled.flatMap(({ rule }) => textsOf(rule)).map(lower))
	const passes = (value) => !named.has(lower(value))
		&& compiled.every(({ rule }) => rule.op !== '!contains' || !lower(value).includes(lower(rule.value)))
	return candidates.find(passes) ?? candidates[0]
}

// A day each rule matches, the day across its edge, then around today.
function dateValues(compiled, today, field) {
	const hits = []
	const edges = []
	for (const { rule } of compiled) {
		const day = isToday(rule.value) ? today : isDay(rule.value) ? rule.value : null
		if (day && rule.op === 'is') {
			hits.push(day)
			edges.push(addDays(day, 1))
		} else if (day && (rule.op === 'before' || rule.op === 'after')) {
			hits.push(addDays(day, rule.op === 'before' ? -1 : 1))
			edges.push(day)
		} else if (rule.op === 'nextDays') {
			hits.push(addDays(today, rule.value))
			edges.push(addDays(today, rule.value + 1))
		} else if (rule.op === 'olderThanDays') {
			hits.push(addDays(today, -rule.value - 1))
			edges.push(addDays(today, -rule.value))
		}
	}
	const around = [-3, 0, 3, 14].map((n) => addDays(today, n))
	const days = unique([...hits, ...edges, ...around]).slice(0, MAX_VALUES).sort()
	return fieldIncludesTime(field) ? days.map((day) => `${day}T09:00:00`) : days
}

// The decimals a number has, also where JavaScript would write 1e-7.
function decimalsOf(number) {
	const [digits, exponent] = number.toExponential().split('e')
	return Math.min(100, Math.max(0, (digits.split('.')[1] ?? '').length - Number(exponent)))
}

// A number each rule matches, then the one across its edge.
function numberValues(compiled) {
	const hits = []
	const edges = []
	for (const { rule } of compiled) {
		const number = parseNumber(rule.value)
		if (number === null) continue
		const decimals = decimalsOf(number)
		// Past 2^53 a step of one gets lost; a relative one does not.
		const step = Math.max(10 ** -decimals, Math.abs(number) * 2 ** -50)
		const at = number.toFixed(decimals)
		const up = (number + step).toFixed(decimals)
		const down = (number - step).toFixed(decimals)
		const [hit, edge] = {
			'!is': [up, at], greater: [up, at], less: [down, at], greaterOrEqual: [at, down], lessOrEqual: [at, up],
		}[rule.op] ?? [at, up]
		hits.push(hit)
		edges.push(edge)
	}
	const numbers = hits.length ? [...hits, ...edges] : ['1', '10', '100']
	return unique(numbers, Number).slice(0, MAX_VALUES).sort((a, b) => Number(a) - Number(b))
}

// One option per rule first, so a long list does not crowd out the next
// rule; then the rest the rules name; then the field's others. In the
// field's order.
function optionValues(field, compiled, first = []) {
	const options = valuesOf(field.field_options)
	// "concept" in a rule is the option "Concept".
	const asOption = (value) => options.find((o) => lower(o) === lower(value)) ?? value
	const place = (value) => {
		const index = options.indexOf(value)
		return index === -1 ? options.length : index
	}
	const hits = compiled.map(({ rule }) => textsOf(rule)[0]).filter(Boolean)
	const named = compiled.flatMap(({ rule }) => textsOf(rule))
	return unique([...first, ...[...hits, ...named].map(asOption), ...options]).slice(0, MAX_VALUES)
		.sort((a, b) => place(a) - place(b))
}

// A file with all of "contains all of" has them together.
function multiValues(field, compiled) {
	const together = compiled.filter(({ rule }) => rule.op === 'containsAll' && rule.value.length > 1)
		.map(({ rule }) => rule.value.map((v) => v.trim()).join(MULTI_DELIM))
	return optionValues(field, compiled, together)
}

// A text each rule matches, then the rest they name, then another one.
function textValues(compiled, other, me = null) {
	const hits = compiled.map(({ rule }) => {
		if (isMe(rule.value)) return me
		return rule.op === '!is' || rule.op === '!contains' ? other : textsOf(rule)[0]
	}).filter(Boolean)
	const named = compiled.flatMap(({ rule }) => textsOf(rule))
	return unique([...hits, ...(me ? [me] : []), ...named, other]).slice(0, MAX_VALUES)
}

/**
 * The values the preview shows, as they are stored, the empty one last.
 *
 * @param {object} field the column (field_type, field_options)
 * @param {{rule: object}[]} compiled the column's working rules
 * @param {{today: string, me: ?string}} ctx
 * @return {string[]}
 */
export function previewValues(field, compiled, ctx) {
	const type = field.field_type
	const other = otherValue(type, compiled)
	let values
	switch (type) {
	case 'checkbox':
	case 'boolean':
		return ['1', '0']
	case 'date':
		values = dateValues(compiled, ctx.today, field)
		break
	case 'number':
		values = numberValues(compiled)
		break
	case 'select':
	case 'dropdown':
		values = optionValues(field, compiled)
		break
	case 'multiselect':
	case 'multi_select':
		values = multiValues(field, compiled)
		break
	case 'user':
		values = textValues(compiled, other, ctx.me)
		break
	default:
		values = textValues(compiled, other)
	}
	if (values.length === 0) values = [other]
	return [...values, '']
}

/**
 * The preview's rows: each value as the grid shows it, and the state the
 * rules give it there.
 *
 * @param {object} field the column (field_type, field_options, field_label)
 * @param {object[]} rules the column's rules, in order
 * @param {{today: string, me: ?string}} ctx
 * @return {{key: string, value: string, text: string, empty: boolean, person: boolean, state: ?{tone: string, note: string}}[]}
 */
export function previewRows(field, rules, ctx) {
	const type = field.field_type
	const compiled = compileRules(rules, type) ?? []
	const other = otherValue(type, compiled)
	const match = { ...ctx, truncated: false }
	return previewValues(field, compiled, ctx).map((value, index) => {
		const text = formatValue(value, type, field)
		return {
			key: `${index}:${value}`,
			value,
			text: text || '—',
			empty: !text,
			// A person has an avatar; "someone else" is nobody.
			person: type === 'user' && text !== '' && value !== other,
			state: cellState(compiled, value, field, match),
		}
	})
}
