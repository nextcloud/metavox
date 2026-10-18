/**
 * Required fields, by the rule the server keeps (FieldValue::isRequired and
 * requireFilled): a required field has a value. The screens check it before
 * saving, so the reason shows under the field instead of after a round trip;
 * the server checks it again either way.
 */

import { translate as t } from '@nextcloud/l10n'

const CHECKBOX = new Set(['checkbox', 'boolean'])

/** Whether a field of this type can be required: not a checkbox, it always has an answer, ticked or not. */
export const canBeRequired = (type) => !CHECKBOX.has(type)

/** Whether the field is required, whichever form the flag came in. */
export const isRequired = (field) => [true, 1, '1', 'true'].includes(field?.is_required)
	&& canBeRequired(field?.field_type)

/**
 * Why this value is not enough for the field, or '' when it is.
 *
 * @param {object} field field definition (field_type, is_required)
 * @param {*} value the value as it would be saved
 * @return {string}
 */
export function missingReason(field, value) {
	if (!isRequired(field)) return ''
	// TRANSLATORS Error under a required metadata field that was left empty
	return String(value ?? '').trim() === '' ? t('metavox', 'This field is required') : ''
}

/**
 * The required fields these values leave empty, with why.
 *
 * @param {object[]} fields field definitions
 * @param {Object<string, *>} values field name => value
 * @return {Object<string, string>} field name => reason; empty when all is there
 */
export function missingRequired(fields, values) {
	const missing = {}
	for (const field of fields ?? []) {
		const reason = missingReason(field, values?.[field.field_name])
		if (reason) missing[field.field_name] = reason
	}
	return missing
}
