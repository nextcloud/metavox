/**
 * Rendering a stored metadata value as the user knows it.
 *
 * Several field types store something other than what they display: a
 * checkbox holds "0"/"1", a multiselect joins its values with ";#", a date
 * holds an ISO string, a File link holds "<id>:<path>" tokens. Four places
 * used to each carry their own copy of that knowledge — the sidebar, the
 * edit form, the grid's filter chips and its filter dropdown — and they had
 * already drifted: only one of them knew a checkbox could arrive as the
 * boolean `false`, and only two honoured a date field's includeTime option.
 *
 * Anything that turns a stored value into text for a person belongs here.
 */

import { getCanonicalLocale, translate as t } from '@nextcloud/l10n'
import { dateFieldIncludesTime, parseLocalDateValue } from './dateField.js'
import { optionLabel } from './fieldOptions.js'

/** Values a checkbox may arrive as, from the API, a form, or a filter. */
const TRUTHY = new Set(['1', 'true', true, 1])
const FALSY = new Set(['0', 'false', false, 0])

/** A ticked checkbox, in any form it arrives in. */
export const isChecked = (value) => TRUTHY.has(value)

/** An explicitly unticked checkbox. Never set is neither ticked nor unticked. */
export const isUnchecked = (value) => FALSY.has(value)

// Defined beside the page's t(); many parts read it from here.
export { PLAIN_TEXT } from './pageL10n.js'

/** The separator a multiselect and a File link use to join their values. */
export const MULTI_DELIM = ';#'

/** Whether a date field carries a time component. */
export const fieldIncludesTime = dateFieldIncludesTime

/**
 * Render one stored value as text.
 *
 * This is the reading of a value, not its rendering in a table cell: a
 * checkbox reads "Yes"/"No" here, where a dense grid shows a tick.
 *
 * @param {*}      value     the stored value
 * @param {string} fieldType field type identifier
 * @param {Object} [field]   field definition, for a date's includeTime
 * @return {string} the value as the user knows it, or '' when there is none
 */
export function formatFieldValue(value, fieldType, field = null) {
	if (value === null || value === undefined || value === '') return ''

	switch (fieldType) {
	case 'checkbox':
	case 'boolean':
		// TRANSLATORS Value of a checkbox field that is ticked, shown in cells and lists
		if (TRUTHY.has(value)) return t('metavox', 'Yes')
		// TRANSLATORS Value of a checkbox field that is not ticked, shown in cells and lists
		if (FALSY.has(value)) return t('metavox', 'No')
		return String(value)

	case 'date': {
		// A stored date has no zone: read as UTC it is the day before in the Americas.
		const date = parseLocalDateValue(String(value))
		if (date === null) return String(value)
		// In the user's Nextcloud locale, not the browser's: the same date then
		// reads the same here as in the edit dialog.
		return fieldIncludesTime(field)
			? date.toLocaleString(getCanonicalLocale())
			: date.toLocaleDateString(getCanonicalLocale())
	}

	// An option shows its label; a value no option has shows as it is.
	case 'select':
	case 'dropdown':
		return optionLabel(field?.field_options, value)

	case 'multiselect':
	case 'multi_select':
		return splitMulti(value).map((item) => optionLabel(field?.field_options, item)).join(', ')

	default:
		return String(value)
	}
}

/**
 * Split a ";#"-joined stored value into its parts, dropping the empties.
 *
 * @param {*} value the stored value
 * @return {string[]}
 */
export function splitMulti(value) {
	if (value === null || value === undefined || value === '') return []
	return String(value)
		.split(MULTI_DELIM)
		.map((v) => v.trim())
		.filter(Boolean)
}
