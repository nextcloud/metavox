/**
 * A field's options as one list, whatever shape they were stored in: JSON text,
 * text with one option per line, a list of texts, a list of {value, label}, or
 * the {includeTime} of a date field. Reads them as lib/Validation/FieldOptions.php
 * does; tests/fixtures/field-options.json holds the cases both must pass.
 */

const isScalar = (value) => typeof value === 'string' || (typeof value === 'number' && Number.isFinite(value))

/** The options as a list; an object such as {includeTime} has none. */
function decode(options) {
	if (typeof options === 'string') {
		let decoded
		try {
			decoded = JSON.parse(options)
		} catch (e) {
			decoded = undefined
		}
		// A JSON string holds the lines; text that is no JSON at all is the lines itself.
		options = decoded !== null && typeof decoded === 'object'
			? decoded
			: (typeof decoded === 'string' ? decoded : options).split(/\r?\n/)
	}
	return Array.isArray(options) ? options : []
}

/**
 * The options of a select or multiselect field, each with the value that is
 * stored and the label that is shown. An item with only a label uses it as its
 * value; empty and repeated values are left out.
 *
 * @param {*} options field_options as stored or as sent
 * @return {{value: string, label: string}[]}
 */
export function optionItems(options) {
	const items = new Map()
	for (const option of decode(options)) {
		let value
		let label
		if (option !== null && typeof option === 'object') {
			value = option.value ?? option.label ?? null
			label = option.label ?? value
		} else {
			value = label = option
		}
		if (!isScalar(value)) continue
		value = String(value).trim()
		if (value === '' || items.has(value)) continue
		items.set(value, { value, label: isScalar(label) ? String(label).trim() : value })
	}
	return [...items.values()]
}

/** @return {string[]} the values an option can be stored as */
export function optionValues(options) {
	return optionItems(options).map((item) => item.value)
}

/**
 * The text to show for a stored value: its option's label, or the value itself.
 *
 * @param {*} options field_options
 * @param {string} value one stored value
 * @return {string}
 */
export function optionLabel(options, value) {
	const text = String(value ?? '')
	return labelsOf(options).get(text) || text
}

// Every cell of a column asks for its label: read a column's options once.
const labelMaps = new WeakMap()
function labelsOf(options) {
	if (options === null || typeof options !== 'object') {
		return new Map(optionItems(options).map((item) => [item.value, item.label]))
	}
	let labels = labelMaps.get(options)
	if (!labels) {
		labels = new Map(optionItems(options).map((item) => [item.value, item.label]))
		labelMaps.set(options, labels)
	}
	return labels
}

/**
 * Whether a date field holds a time as well.
 *
 * @param {*} options field_options
 * @return {boolean}
 */
export function includesTime(options) {
	let decoded = options
	if (typeof options === 'string') {
		try {
			decoded = JSON.parse(options)
		} catch (e) {
			return false
		}
	}
	if (decoded === null || typeof decoded !== 'object' || Array.isArray(decoded)) return false
	// As PHP's empty(): 0, '0', '' and false are off.
	const on = decoded.includeTime
	return !(on === undefined || on === null || on === false || on === 0 || on === '0' || on === '' || (Array.isArray(on) && on.length === 0))
}
