/**
 * Highlight rules: does a value match, and how to say what a rule means.
 *
 * Pure: "today", the viewer and the state of the row come in through ctx,
 * so the same rules give the same answer in a test as in the grid.
 * ctx = { today: 'YYYY-MM-DD', me: uid|null, mine: [uid, group:<id>, team:<id>]|undefined, truncated: bool }
 */

import { getCanonicalLocale, translate as t, translatePlural as n } from '@nextcloud/l10n'
import { addDays, datePart, isDay } from './dateField.js'
import { PLAIN_TEXT, formatFieldValue, isChecked, isUnchecked, splitMulti } from './fieldValue.js'
import { OPERAND_KINDS, PRESETS, PRESET_ORDER, TONES, conditionOf, conditionsFor, isMe, isToday, operatorsFor, presetName } from './valueOperators.js'

export const MAX_RULES = 10
export const MAX_LIST = 50
export const MAX_TEXT = 200
export const MAX_LABEL = 40
export const MAX_DAYS = 3650

const MULTI_TYPES = new Set(['multiselect', 'multi_select'])

// PHP is_numeric: its own whitespace around (not a no-break space), a sign,
// .5 and 1e3; no 1,5, no 12abc, no hex.
const NUMERIC = /^[ \t\n\r\v\f]*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?[ \t\n\r\v\f]*$/

/** A number written the way PHP's is_numeric takes it, else null. */
export function parseNumber(value) {
	if (typeof value === 'number') return Number.isFinite(value) ? value : null
	if (typeof value !== 'string' || !NUMERIC.test(value)) return null
	// "1e400" is written like a number, but is none.
	const number = Number(value)
	return Number.isFinite(number) ? number : null
}

/** No value: nothing stored, or a list without items. "0" is a value. */
export function isEmptyValue(value, type) {
	if (value === undefined || value === null || value === '') return true
	if (MULTI_TYPES.has(type) || type === 'filelink' || type === 'user') return splitMulti(value).length === 0
	return false
}

/**
 * A label without control and format characters (bidi overrides, zero-width
 * ones; the emoji joiner stays), trimmed. Never cut: the editor refuses a
 * longer one, as the server does.
 */
export function plainLabel(label) {
	if (typeof label !== 'string') return ''
	return label.replace(/(?!\u200D)[\p{Cc}\p{Cf}]/gu, '').trim()
}

/** A label as it may be shown: plain, at most MAX_LABEL characters. */
export function cleanLabel(label) {
	return [...plainLabel(label)].slice(0, MAX_LABEL).join('')
}

const lower = (value) => String(value).toLowerCase()
const validText = (value) => typeof value === 'string' && value.trim() !== '' && [...value.trim()].length <= MAX_TEXT

/** Whether a rule's value has the shape its operator asks for, for this type. */
function validOperand(rule, type) {
	const value = rule.value
	switch (OPERAND_KINDS[rule.op]) {
	case 'none': return value === undefined
	case 'days': return Number.isInteger(value) && value >= 1 && value <= MAX_DAYS
	case 'number': return parseNumber(value) !== null
	case 'date': return isToday(value) || isDay(value)
	case 'equal':
		if (isToday(value)) return type === 'date'
		if (isMe(value)) return type === 'user'
		if (type === 'date') return isDay(value)
		if (type === 'number') return parseNumber(value) !== null
		return validText(value)
	case 'text': return type === 'number' ? parseNumber(value) !== null : validText(value)
	case 'list': return Array.isArray(value) && value.length >= 1 && value.length <= MAX_LIST && value.every(validText)
	default: return false
	}
}

/**
 * The rules of a column, ready to match. Rules that do not fit the field type
 * (any more) are left out; they colour nothing.
 *
 * @param {object[]} rules stored rules, in order
 * @param {string} type field type
 * @return {{rule: object, index: number}[]|null} null when nothing is left
 */
export function compileRules(rules, type) {
	if (!Array.isArray(rules)) return null
	const allowed = operatorsFor(type)
	const compiled = []
	rules.slice(0, MAX_RULES).forEach((rule, index) => {
		if (rule && allowed.includes(rule.op) && TONES.includes(rule.tone) && validOperand(rule, type)) {
			compiled.push({ rule, index })
		}
	})
	return compiled.length ? compiled : null
}

/**
 * The first rule that matches. Unknown stops the search: a rule further down
 * must not colour a cell that a rule above it might have matched.
 *
 * @param {{rule: object, index: number}[]|null} compiled
 * @param {*} value stored value
 * @param {string} type field type
 * @param {object} ctx
 * @return {{rule: object, index: number}|null}
 */
export function matchRules(compiled, value, type, ctx) {
	for (const entry of compiled ?? []) {
		const result = evaluate(entry.rule, value, type, ctx)
		if (result === null) return null
		if (result) return entry
	}
	return null
}

/**
 * The state a value gives its cell: tone and name of the first rule it
 * matches, or null. The grid and the editor's preview both ask this.
 *
 * @param {{rule: object, index: number}[]|null} compiled
 * @param {*} value stored value
 * @param {object} field field definition (field_type, for the name)
 * @param {object} ctx
 * @return {{tone: string, note: string}|null}
 */
export function cellState(compiled, value, field, ctx) {
	const hit = matchRules(compiled, value, field.field_type, ctx)
	return hit ? { tone: hit.rule.tone, note: stateName(hit.rule, field) } : null
}

/**
 * @param {object} rule
 * @param {*} value stored value
 * @param {string} type field type
 * @param {object} ctx
 * @return {boolean|null} null when it cannot be known
 */
export function evaluate(rule, value, type, ctx = {}) {
	// A folder carries its own metadata and counts like a file, empty values too.
	const empty = isEmptyValue(value, type)
	if (rule.op === 'empty') return empty
	if (rule.op === '!empty') return !empty
	// Everything below reads the value itself, which a cut-off one does not show.
	if (ctx.truncated) return null

	if (rule.op === 'isTrue') return isChecked(value)
	if (rule.op === 'isFalse') return empty || isUnchecked(value)
	if (type === 'date') return compareDate(rule, value, empty, ctx)
	if (type === 'number') return compareNumber(rule, value, empty)
	if (MULTI_TYPES.has(type)) {
		if (empty) return false
		const items = new Set(splitMulti(value).map(lower))
		const wanted = rule.value.map((v) => lower(v.trim()))
		return rule.op === 'oneOf' ? wanted.some((v) => items.has(v)) : wanted.every((v) => items.has(v))
	}
	if (type === 'user') {
		// One or several people; "you" also counts through your groups and teams.
		if (isMe(rule.value) && !ctx.me) return null
		const items = new Set(splitMulti(value).map(lower))
		const has = (item) => items.has(lower(String(item).trim()))
		if (isMe(rule.value)) return (ctx.mine ?? [ctx.me]).some(has)
		switch (rule.op) {
		case 'is': return has(rule.value)
		case '!is': return !has(rule.value)
		case 'oneOf': return rule.value.some(has)
		default: return null
		}
	}
	switch (rule.op) {
	case 'is': return !empty && lower(value) === lower(rule.value.trim())
	case '!is': return empty || lower(value) !== lower(rule.value.trim())
	case 'contains': return !empty && lower(value).includes(lower(rule.value.trim()))
	case '!contains': return empty || !lower(value).includes(lower(rule.value.trim()))
	case 'oneOf': return !empty && rule.value.some((v) => lower(v.trim()) === lower(value))
	default: return null
	}
}

function compareDate(rule, value, empty, ctx) {
	if (empty) return false
	const day = datePart(value)
	if (day === null) return null
	if (!ctx.today) return null
	const operand = isToday(rule.value) ? ctx.today : rule.value
	switch (rule.op) {
	case 'before': return day < operand
	case 'after': return day > operand
	case 'is': return day === operand
	case 'nextDays': return day >= ctx.today && day <= addDays(ctx.today, rule.value)
	case 'olderThanDays': return day < addDays(ctx.today, -rule.value)
	default: return null
	}
}

function compareNumber(rule, value, empty) {
	if (empty) return rule.op === '!is'
	const number = parseNumber(value)
	if (number === null) return null
	const operand = parseNumber(rule.value)
	if (operand === null) return null
	switch (rule.op) {
	case 'is': return number === operand
	case '!is': return number !== operand
	case 'greater': return number > operand
	case 'less': return number < operand
	case 'greaterOrEqual': return number >= operand
	case 'lessOrEqual': return number <= operand
	default: return null
	}
}

// Every digit the rule has: the default would round to three decimals.
const formatNumber = (number) => new Intl.NumberFormat(getCanonicalLocale(), { maximumFractionDigits: 20 }).format(number)

const NUMBER_OPS = new Set(['greater', 'less', 'greaterOrEqual', 'lessOrEqual'])

/**
 * What a rule means, as one translated phrase: "before today",
 * "within the next 7 days", "is one of Rejected, Expired".
 *
 * Read from the rule itself, so a rule that no longer fits its field (the
 * type changed) is still described as what it says.
 *
 * @param {object} rule
 * @param {object} field field definition (field_type, field_options)
 * @return {string}
 */
export function describeRule(rule, field) {
	const type = field?.field_type
	const op = rule?.op
	const value = rule?.value
	switch (op) {
	// TRANSLATORS Highlight rule summary, read as "the cell value …"; lowercase phrase
	case 'empty': return t('metavox', 'is empty')
	// TRANSLATORS Highlight rule summary, read as "the cell value …"; lowercase phrase
	case '!empty': return t('metavox', 'is not empty')
	// TRANSLATORS Highlight rule summary for a checkbox field: the box is ticked; lowercase phrase
	case 'isTrue': return t('metavox', 'is checked')
	// TRANSLATORS Highlight rule summary for a checkbox field: the box is not ticked; lowercase phrase
	case 'isFalse': return t('metavox', 'is not checked')
	}
	if (Number.isInteger(value) && op === 'nextDays') {
		// TRANSLATORS Highlight rule summary for a date; %n is the number of days from today, e.g. "within the next 7 days"
		return n('metavox', 'within the next day', 'within the next %n days', value, {}, PLAIN_TEXT)
	}
	if (Number.isInteger(value) && op === 'olderThanDays') {
		// TRANSLATORS Highlight rule summary for a date; %n is the number of days before today, e.g. "more than 30 days ago"
		return n('metavox', 'more than a day ago', 'more than %n days ago', value, {}, PLAIN_TEXT)
	}
	if (Array.isArray(value) && value.every((v) => typeof v === 'string')) {
		const values = value.map((v) => v.trim()).join(', ')
		// TRANSLATORS Highlight rule summary; {values} is a comma-separated list of options, e.g. "contains all of Legal, Finance"
		if (op === 'containsAll') return t('metavox', 'contains all of {values}', { values }, undefined, PLAIN_TEXT)
		if (op === 'oneOf') {
			return MULTI_TYPES.has(type)
				// TRANSLATORS Highlight rule summary; {values} is a comma-separated list of options, e.g. "contains one of Legal, Finance"
				? t('metavox', 'contains one of {values}', { values }, undefined, PLAIN_TEXT)
				// TRANSLATORS Highlight rule summary; {values} is a comma-separated list of options, e.g. "is one of Rejected, Expired"
				: t('metavox', 'is one of {values}', { values }, undefined, PLAIN_TEXT)
		}
	}
	if (isToday(value)) {
		// TRANSLATORS Highlight rule summary for a date: the date lies before today; lowercase phrase
		if (op === 'before') return t('metavox', 'before today')
		// TRANSLATORS Highlight rule summary for a date: the date lies after today; lowercase phrase
		if (op === 'after') return t('metavox', 'after today')
		// TRANSLATORS Highlight rule summary for a date: the date is today; lowercase phrase
		if (op === 'is') return t('metavox', 'today')
	}
	// TRANSLATORS Highlight rule summary for a person field: the person is whoever is looking at the files
	if (isMe(value) && op === 'is') return t('metavox', 'is the person viewing')
	if (isDay(value) && (op === 'before' || op === 'after' || (op === 'is' && type === 'date'))) {
		const date = formatFieldValue(value, 'date')
		// TRANSLATORS Highlight rule summary; {date} is a formatted date, e.g. "before 3 March 2026"
		if (op === 'before') return t('metavox', 'before {date}', { date }, undefined, PLAIN_TEXT)
		// TRANSLATORS Highlight rule summary; {date} is a formatted date, e.g. "after 3 March 2026"
		if (op === 'after') return t('metavox', 'after {date}', { date }, undefined, PLAIN_TEXT)
		// TRANSLATORS Highlight rule summary; {date} is a formatted date, e.g. "on 3 March 2026"
		return t('metavox', 'on {date}', { date }, undefined, PLAIN_TEXT)
	}
	if (typeof value === 'string' || typeof value === 'number') {
		const number = (type === 'number' || NUMBER_OPS.has(op)) ? parseNumber(value) : null
		const shown = number === null ? String(value).trim() : formatNumber(number)
		switch (op) {
		// TRANSLATORS Highlight rule summary; {value} is the value the cell is compared with, e.g. "is Approved"
		case 'is': return t('metavox', 'is {value}', { value: shown }, undefined, PLAIN_TEXT)
		// TRANSLATORS Highlight rule summary; {value} is the value the cell is compared with, e.g. "is not Approved"
		case '!is': return t('metavox', 'is not {value}', { value: shown }, undefined, PLAIN_TEXT)
		// TRANSLATORS Highlight rule summary; {value} is the text the cell is searched for, e.g. "contains invoice"
		case 'contains': return t('metavox', 'contains {value}', { value: shown }, undefined, PLAIN_TEXT)
		// TRANSLATORS Highlight rule summary; {value} is the text the cell is searched for, e.g. "does not contain invoice"
		case '!contains': return t('metavox', 'does not contain {value}', { value: shown }, undefined, PLAIN_TEXT)
		// TRANSLATORS Highlight rule summary for a number; {value} is a number, e.g. "greater than 10,000"
		case 'greater': return t('metavox', 'greater than {value}', { value: shown }, undefined, PLAIN_TEXT)
		// TRANSLATORS Highlight rule summary for a number; {value} is a number, e.g. "less than 10,000"
		case 'less': return t('metavox', 'less than {value}', { value: shown }, undefined, PLAIN_TEXT)
		// TRANSLATORS Highlight rule summary for a number; {value} is a number, e.g. "at least 10,000" (equal or greater)
		case 'greaterOrEqual': return t('metavox', 'at least {value}', { value: shown }, undefined, PLAIN_TEXT)
		// TRANSLATORS Highlight rule summary for a number; {value} is a number, e.g. "at most 10,000" (equal or less)
		case 'lessOrEqual': return t('metavox', 'at most {value}', { value: shown }, undefined, PLAIN_TEXT)
		}
	}
	// Something a later version or the API stored that this one does not know.
	// TRANSLATORS Highlight rule summary shown for a rule saved by a newer version of the app
	return t('metavox', 'a condition this version does not know')
}

/** Whether a rule still is the preset it names: the editor drops the id on a change, the API may not. */
export function isPresetRule(rule) {
	if (typeof rule.preset !== 'string' || !Object.hasOwn(PRESETS, rule.preset)) return false
	const preset = PRESETS[rule.preset]
	return preset.rule.op === rule.op && JSON.stringify(preset.rule.value) === JSON.stringify(rule.value)
}

/**
 * The name of the state a matching rule gives a cell: its own label, else
 * the preset's name, else what the rule means. Read out by screen readers
 * and shown in the legend, so it is never a bare colour.
 *
 * @param {object} rule
 * @param {object} field field definition
 * @return {string}
 */
export function stateName(rule, field) {
	return ownName(rule) || describeRule(rule, field)
}

/**
 * A rule's own name: its label, else the preset's name; empty when it has
 * neither, so the editor does not show what the rule means twice.
 *
 * @param {object} rule
 * @param {boolean} [forViewer] the preset's name for who looks at the grid
 * @return {string}
 */
export function ownName(rule, forViewer = true) {
	const label = cleanLabel(rule?.label)
	if (label) return label
	return rule && isPresetRule(rule) ? presetName(rule.preset, forViewer) : ''
}

// ========================================
// Editing: what the form holds, and the rule it makes
// ========================================

const decimalSign = () => new Intl.NumberFormat(getCanonicalLocale()).formatToParts(1.5).find((p) => p.type === 'decimal')?.value ?? '.'

/** A typed number with a point: in a locale that writes 1,5, that is taken as 1.5. */
function pointDecimal(text) {
	const value = String(text ?? '').trim()
	return decimalSign() === ',' && /^[+-]?\d+,\d+$/.test(value) ? value.replace(',', '.') : value
}

/** A stored number the way its reader writes it. */
function localDecimal(value) {
	const text = String(value ?? '')
	return decimalSign() === ',' && /^[+-]?\d+\.\d+$/.test(text) ? text.replace('.', ',') : text
}

/**
 * What the form edits for a rule: its condition, the value as typed, tone,
 * label and the preset it may still be.
 *
 * @param {object} rule stored rule
 * @param {string} type field type
 * @return {{condition: string, value: *, tone: string, label: string, preset: ?string}}
 */
export function ruleDraft(rule, type) {
	const conditions = conditionsFor(type)
	const condition = conditionOf(rule, type) ?? conditions[0]?.id
	const input = conditions.find((c) => c.id === condition)?.input
	let value = ''
	if (input === 'days' || input === 'text' || input === 'option' || input === 'date') value = rule?.value === undefined ? '' : String(rule.value)
	else if (input === 'number') value = localDecimal(rule?.value)
	else if (input === 'options') value = Array.isArray(rule?.value) ? [...rule.value] : []
	return { condition, value, tone: TONES.includes(rule?.tone) ? rule.tone : 'info', label: rule?.label ?? '', preset: rule?.preset ?? null }
}

/** The form for a preset: filled in, the name left to the preset. */
export function presetDraft(id, type) {
	return ruleDraft({ ...PRESETS[id].rule, preset: id }, type)
}

/** The form for a rule of one's own: the first condition, blue, a week where days are asked. */
export function customDraft(type) {
	const first = conditionsFor(type)[0]
	return {
		condition: first?.id,
		value: first?.input === 'days' ? '7' : first?.input === 'options' ? [] : '',
		tone: 'info',
		label: '',
		preset: null,
	}
}

/** The value a condition asks for, when the form switches to it. */
export function switchCondition(draft, condition, type) {
	const was = conditionsFor(type).find((c) => c.id === draft.condition)?.input
	const input = conditionsFor(type).find((c) => c.id === condition)?.input
	if (was === input) return { ...draft, condition }
	return { ...draft, condition, value: input === 'days' ? '7' : input === 'options' ? [] : '' }
}

/**
 * The rule a draft makes, checked as the server checks it, or what is wrong.
 *
 * @param {object} draft see ruleDraft
 * @param {string} type field type
 * @return {{rule: object}|{part: string, message: string}}
 */
export function ruleFromDraft(draft, type) {
	const condition = conditionsFor(type).find((c) => c.id === draft?.condition)
	if (!condition) return { part: 'op', message: t('metavox', 'This value is not valid') }
	const rule = { op: condition.op }
	const text = String(draft.value ?? '').trim()
	switch (condition.input) {
	case 'today': rule.value = { today: 0 }; break
	case 'me': rule.value = { me: true }; break
	case 'days':
		if (!/^\d+$/.test(text) || Number(text) < 1 || Number(text) > MAX_DAYS) {
			return { part: 'value', message: t('metavox', 'Enter a whole number from 1 to 3650') }
		}
		rule.value = Number(text)
		break
	case 'number': {
		const number = pointDecimal(text)
		// TRANSLATORS Error below the value field of a highlight rule when the input is not a number
		if (parseNumber(number) === null) return { part: 'value', message: t('metavox', 'Enter a number') }
		rule.value = number
		break
	}
	case 'date':
		if (!isDay(text)) return { part: 'value', message: t('metavox', 'Enter a valid date') }
		rule.value = text
		break
	case 'text':
	case 'option':
		if (!validText(text)) {
			return {
				part: 'value',
				message: condition.input === 'option'
					// TRANSLATORS Error below the value field of a highlight rule when no option is chosen
					? t('metavox', 'Choose an option')
					: t('metavox', 'Enter 1 to 200 characters'),
			}
		}
		rule.value = text
		break
	case 'options': {
		const list = (Array.isArray(draft.value) ? draft.value : []).map((v) => String(v).trim()).filter(Boolean)
		if (list.length === 0) return { part: 'value', message: t('metavox', 'Choose at least one option') }
		if (list.length > MAX_LIST || !list.every(validText)) {
			return { part: 'value', message: t('metavox', 'Choose 1 to 50 options of at most 200 characters') }
		}
		rule.value = list
		break
	}
	}
	rule.tone = TONES.includes(draft.tone) ? draft.tone : 'info'
	const label = plainLabel(draft.label)
	if ([...label].length > MAX_LABEL) return { part: 'label', message: t('metavox', 'Use at most 40 characters') }
	if (label) rule.label = label
	if (draft.preset && isPresetRule({ ...rule, preset: draft.preset })) rule.preset = draft.preset
	return { rule }
}

/**
 * How the editor shows a stored rule: editable, working but not editable
 * here (made elsewhere), or not working with this field (any more).
 *
 * @param {object} rule
 * @param {string} type field type
 * @return {'edit'|'fixed'|'misfit'}
 */
export function ruleStatus(rule, type) {
	if (!compileRules([rule], type)) return 'misfit'
	return conditionOf(rule, type) === null ? 'fixed' : 'edit'
}

/**
 * Where a new preset goes: before the first rule that is still a preset
 * coming later in PRESET_ORDER, else at the end.
 *
 * @param {object[]} rules
 * @param {string} presetId
 * @return {number}
 */
export function presetInsertIndex(rules, presetId) {
	const place = PRESET_ORDER.indexOf(presetId)
	if (place === -1) return rules.length
	const index = rules.findIndex((rule) => rule && isPresetRule(rule) && PRESET_ORDER.indexOf(rule.preset) > place)
	return index === -1 ? rules.length : index
}

