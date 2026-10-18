/**
 * The vocabulary of highlight rules: which conditions fit a field type, the
 * four tones, and the presets. The operator ids are those of the Flow check,
 * plus nextDays and olderThanDays.
 */

import { translate as t } from '@nextcloud/l10n'
import { isDay } from './dateField.js'

/** What the value of a rule is, per operator. */
export const OPERAND_KINDS = Object.freeze({
	empty: 'none',
	'!empty': 'none',
	isTrue: 'none',
	isFalse: 'none',
	nextDays: 'days',
	olderThanDays: 'days',
	greater: 'number',
	less: 'number',
	greaterOrEqual: 'number',
	lessOrEqual: 'number',
	before: 'date',
	after: 'date',
	is: 'equal',
	'!is': 'text',
	contains: 'text',
	'!contains': 'text',
	oneOf: 'list',
	containsAll: 'list',
})

const TEXT = ['is', '!is', 'contains', '!contains', 'empty', '!empty']
const SELECT = ['is', '!is', 'oneOf', 'empty', '!empty']
const MULTI = ['oneOf', 'containsAll', 'empty', '!empty']
const CHECKBOX = ['isTrue', 'isFalse']

/** The operators per field type. */
export const OPERATORS = Object.freeze({
	text: TEXT,
	textarea: TEXT,
	url: TEXT,
	number: ['is', '!is', 'greater', 'less', 'greaterOrEqual', 'lessOrEqual', 'empty', '!empty'],
	date: ['before', 'after', 'is', 'nextDays', 'olderThanDays', 'empty', '!empty'],
	select: SELECT,
	dropdown: SELECT,
	multiselect: MULTI,
	multi_select: MULTI,
	checkbox: CHECKBOX,
	boolean: CHECKBOX,
	user: ['is', '!is', 'empty', '!empty'],
})

/** Any other type, a File link included: only whether there is a value. */
export const FALLBACK_OPERATORS = Object.freeze(['empty', '!empty'])

/**
 * @param {string} type field type
 * @return {string[]} operator ids
 */
export function operatorsFor(type) {
	return typeof type === 'string' && Object.hasOwn(OPERATORS, type) ? OPERATORS[type] : FALLBACK_OPERATORS
}

/** The two the Flow check does not have: a rule there is checked once, not against today. */
export const NOT_IN_FLOW = Object.freeze(['nextDays', 'olderThanDays'])

/**
 * @param {string} type field type
 * @return {string[]} the operators a Flow check offers for it
 */
export function flowOperatorsFor(type) {
	return operatorsFor(type).filter((op) => !NOT_IN_FLOW.includes(op))
}

export const isCheckboxType = (type) => type === 'checkbox' || type === 'boolean'

/** Semantic ids, never colours: the theme decides how they look. */
export const TONES = Object.freeze(['error', 'warning', 'success', 'info'])

/**
 * The colour a tone shows, as people name it in the editor.
 *
 * @param {string} tone
 * @return {string}
 */
export function toneName(tone) {
	switch (tone) {
	// TRANSLATORS Name of a highlight colour, shown in the colour picker of a highlight rule and read out for the coloured mark
	case 'error': return t('metavox', 'Red')
	// TRANSLATORS Name of a highlight colour, shown in the colour picker of a highlight rule and read out for the coloured mark
	case 'warning': return t('metavox', 'Orange')
	// TRANSLATORS Name of a highlight colour, shown in the colour picker of a highlight rule and read out for the coloured mark
	case 'success': return t('metavox', 'Green')
	// TRANSLATORS Name of a highlight colour, shown in the colour picker of a highlight rule and read out for the coloured mark
	case 'info': return t('metavox', 'Blue')
	default: return tone
	}
}

/** Presets go in this order, so "Due soon" (today included) never ends up above "Due today". */
export const PRESET_ORDER = Object.freeze(['overdue', 'dueToday', 'dueSoon', 'missing'])

/** Each preset: the field types it fits, and the rule it fills in. */
export const PRESETS = Object.freeze({
	overdue: { fits: (type) => type === 'date', rule: { op: 'before', value: { today: 0 }, tone: 'error' } },
	dueToday: { fits: (type) => type === 'date', rule: { op: 'is', value: { today: 0 }, tone: 'warning' } },
	dueSoon: { fits: (type) => type === 'date', rule: { op: 'nextDays', value: 7, tone: 'warning' } },
	missing: { fits: (type) => !isCheckboxType(type), rule: { op: 'empty', tone: 'warning' } },
	checked: { fits: isCheckboxType, rule: { op: 'isTrue', tone: 'success' } },
	unchecked: { fits: isCheckboxType, rule: { op: 'isFalse', tone: 'warning' } },
	assignedToViewer: { fits: (type) => type === 'user', rule: { op: 'is', value: { me: true }, tone: 'info' } },
})

/**
 * @param {string} type field type
 * @return {string[]} preset ids that fit, in menu order
 */
export function presetsFor(type) {
	return Object.keys(PRESETS).filter((id) => PRESETS[id].fits(type))
}

/**
 * The name of a preset: in the menu, or as the state someone sees.
 *
 * @param {string} id preset id
 * @param {boolean} [forViewer] the name for who looks at the grid
 * @return {string|null}
 */
export function presetName(id, forViewer = false) {
	switch (id) {
	// TRANSLATORS Name of a ready-made highlight rule for a date: the date has passed. Shown in the add-rule menu and as the cell state
	case 'overdue': return t('metavox', 'Overdue')
	// TRANSLATORS Name of a ready-made highlight rule for a date: the date is today. Shown in the add-rule menu and as the cell state
	case 'dueToday': return t('metavox', 'Due today')
	// TRANSLATORS Name of a ready-made highlight rule for a date: due within 7 days. Shown in the add-rule menu and as the cell state
	case 'dueSoon': return t('metavox', 'Due soon')
	// TRANSLATORS Name of a ready-made highlight rule: the cell has no value. Shown in the add-rule menu and as the cell state
	case 'missing': return t('metavox', 'Missing')
	// TRANSLATORS Name of a ready-made highlight rule for a checkbox field: ticked. Shown in the add-rule menu and as the cell state
	case 'checked': return t('metavox', 'Checked')
	// TRANSLATORS Name of a ready-made highlight rule for a checkbox field: not ticked. Shown in the add-rule menu and as the cell state
	case 'unchecked': return t('metavox', 'Not checked')
	case 'assignedToViewer': return forViewer
		// TRANSLATORS Cell state for a person field when it holds the person who is looking at the files
		? t('metavox', 'Assigned to you')
		// TRANSLATORS Name of a ready-made highlight rule for a person field, in the add-rule menu: the field holds whoever is looking
		: t('metavox', 'Assigned to the person viewing')
	default: return null
	}
}

/**
 * The conditions the editor offers per type. Each one is an operator plus
 * the kind of value it asks for:
 * none, today, me, days, date, number, text, option, options, user.
 */
const DATE_CONDITIONS = [
	{ id: 'beforeToday', op: 'before', input: 'today' },
	{ id: 'today', op: 'is', input: 'today' },
	{ id: 'afterToday', op: 'after', input: 'today' },
	{ id: 'nextDays', op: 'nextDays', input: 'days' },
	{ id: 'olderThanDays', op: 'olderThanDays', input: 'days' },
	{ id: 'beforeDate', op: 'before', input: 'date' },
	{ id: 'onDate', op: 'is', input: 'date' },
	{ id: 'afterDate', op: 'after', input: 'date' },
]
const EMPTY_CONDITIONS = [
	{ id: 'empty', op: 'empty', input: 'none' },
	{ id: 'notEmpty', op: '!empty', input: 'none' },
]

/**
 * @param {string} type field type
 * @return {{id: string, op: string, input: string}[]}
 */
export function conditionsFor(type) {
	switch (type) {
	case 'date':
		return [...DATE_CONDITIONS, ...EMPTY_CONDITIONS]
	case 'number':
		return [
			{ id: 'is', op: 'is', input: 'number' },
			{ id: 'isNot', op: '!is', input: 'number' },
			{ id: 'greater', op: 'greater', input: 'number' },
			{ id: 'less', op: 'less', input: 'number' },
			{ id: 'greaterOrEqual', op: 'greaterOrEqual', input: 'number' },
			{ id: 'lessOrEqual', op: 'lessOrEqual', input: 'number' },
			...EMPTY_CONDITIONS,
		]
	case 'select':
	case 'dropdown':
		return [
			{ id: 'is', op: 'is', input: 'option' },
			{ id: 'isNot', op: '!is', input: 'option' },
			{ id: 'oneOf', op: 'oneOf', input: 'options' },
			...EMPTY_CONDITIONS,
		]
	case 'multiselect':
	case 'multi_select':
		return [
			{ id: 'containsOneOf', op: 'oneOf', input: 'options' },
			{ id: 'containsAll', op: 'containsAll', input: 'options' },
			...EMPTY_CONDITIONS,
		]
	case 'checkbox':
	case 'boolean':
		return [
			{ id: 'checked', op: 'isTrue', input: 'none' },
			{ id: 'notChecked', op: 'isFalse', input: 'none' },
		]
	case 'user':
		// A named person is not offered yet; such a stored rule still works.
		return [
			{ id: 'isViewer', op: 'is', input: 'me' },
			...EMPTY_CONDITIONS,
		]
	case 'text':
	case 'textarea':
	case 'url':
		return [
			{ id: 'is', op: 'is', input: 'text' },
			{ id: 'isNot', op: '!is', input: 'text' },
			{ id: 'contains', op: 'contains', input: 'text' },
			{ id: 'notContains', op: '!contains', input: 'text' },
			...EMPTY_CONDITIONS,
		]
	default:
		return [...EMPTY_CONDITIONS]
	}
}

/**
 * The label of a condition in the editor.
 *
 * @param {string} id condition id
 * @return {string}
 */
export function conditionLabel(id) {
	switch (id) {
	// TRANSLATORS Option in the condition menu of a highlight rule for a date field
	case 'beforeToday': return t('metavox', 'Before today')
	// TRANSLATORS Option in the condition menu of a highlight rule for a date field: the date is today
	case 'today': return t('metavox', 'Today')
	// TRANSLATORS Option in the condition menu of a highlight rule for a date field
	case 'afterToday': return t('metavox', 'After today')
	// TRANSLATORS Option in the condition menu of a highlight rule; the number of days is entered in a separate field
	case 'nextDays': return t('metavox', 'Within the next … days')
	// TRANSLATORS Option in the condition menu of a highlight rule; the number of days is entered in a separate field
	case 'olderThanDays': return t('metavox', 'More than … days ago')
	// TRANSLATORS Option in the condition menu of a highlight rule; the date is picked in a separate field
	case 'beforeDate': return t('metavox', 'Before a date')
	// TRANSLATORS Option in the condition menu of a highlight rule; the date is picked in a separate field
	case 'onDate': return t('metavox', 'On a date')
	// TRANSLATORS Option in the condition menu of a highlight rule; the date is picked in a separate field
	case 'afterDate': return t('metavox', 'After a date')
	// TRANSLATORS Option in the condition menu of a highlight rule: the cell has no value
	case 'empty': return t('metavox', 'Is empty')
	// TRANSLATORS Option in the condition menu of a highlight rule: the cell has a value
	case 'notEmpty': return t('metavox', 'Is not empty')
	// TRANSLATORS Option in the condition menu of a highlight rule: the cell equals a value
	case 'is': return t('metavox', 'Is')
	// TRANSLATORS Option in the condition menu of a highlight rule: the cell does not equal a value
	case 'isNot': return t('metavox', 'Is not')
	// TRANSLATORS Option in the condition menu of a highlight rule: the cell text contains some text
	case 'contains': return t('metavox', 'Contains')
	// TRANSLATORS Option in the condition menu of a highlight rule: the cell text does not contain some text
	case 'notContains': return t('metavox', 'Does not contain')
	// TRANSLATORS Option in the condition menu of a highlight rule for a number field
	case 'greater': return t('metavox', 'Greater than')
	// TRANSLATORS Option in the condition menu of a highlight rule for a number field
	case 'less': return t('metavox', 'Less than')
	// TRANSLATORS Option in the condition menu of a highlight rule for a number field: equal or greater
	case 'greaterOrEqual': return t('metavox', 'At least')
	// TRANSLATORS Option in the condition menu of a highlight rule for a number field: equal or less
	case 'lessOrEqual': return t('metavox', 'At most')
	// TRANSLATORS Option in the condition menu of a highlight rule: the cell is one of several chosen options
	case 'oneOf': return t('metavox', 'Is one of')
	// TRANSLATORS Option in the condition menu of a highlight rule for a multi-select field: the cell has at least one of the chosen options
	case 'containsOneOf': return t('metavox', 'Contains one of')
	// TRANSLATORS Option in the condition menu of a highlight rule for a multi-select field: the cell has every chosen option
	case 'containsAll': return t('metavox', 'Contains all of')
	// TRANSLATORS Option in the condition menu of a highlight rule for a checkbox field: the box is ticked
	case 'checked': return t('metavox', 'Is checked')
	// TRANSLATORS Option in the condition menu of a highlight rule for a checkbox field: the box is not ticked
	case 'notChecked': return t('metavox', 'Is not checked')
	// TRANSLATORS Option in the condition menu of a highlight rule for a person field: the field holds whoever is looking at the files
	case 'isViewer': return t('metavox', 'Is the person viewing')
	default: return id
	}
}

const isToday = (value) => value !== null && typeof value === 'object' && !Array.isArray(value) && value.today === 0
const isMe = (value) => value !== null && typeof value === 'object' && !Array.isArray(value) && value.me === true

/**
 * The editor condition a stored rule stands for.
 *
 * @param {{op: string, value: *}} rule
 * @param {string} type field type
 * @return {string|null} null for a rule the editor cannot show as a condition
 */
export function conditionOf(rule, type) {
	const value = rule?.value
	const found = conditionsFor(type).find((c) => {
		if (c.op !== rule?.op) return false
		switch (c.input) {
		case 'none': return value === undefined
		case 'today': return isToday(value)
		case 'me': return isMe(value)
		case 'days': return Number.isInteger(value)
		case 'date': return isDay(value)
		case 'options': return Array.isArray(value)
		default: return typeof value === 'string'
		}
	})
	return found?.id ?? null
}

export { isToday, isMe }
