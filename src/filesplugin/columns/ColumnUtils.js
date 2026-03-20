/**
 * MetaVox — Pure helper / utility functions for column rendering.
 */

import { columnWidths } from './MetaVoxState.js'

// ========================================
// Value Formatting
// ========================================

/**
 * Format a metadata value for display in a table cell.
 *
 * @param {*}      value     Raw metadata value
 * @param {string} fieldType Field type identifier
 * @return {string}
 */
export function formatValue(value, fieldType) {
	if (value === null || value === undefined || value === '') return ''

	switch (fieldType) {
	case 'checkbox':
	case 'boolean':
		if (value === '1' || value === 'true' || value === true) return '\u2713'
		return ''
	case 'date':
		try {
			const d = new Date(value)
			if (!isNaN(d.getTime())) return d.toLocaleDateString()
		} catch (e) { /* fall through */ }
		return value
	case 'multiselect':
		return value.split(';#').filter(v => v.trim()).join(', ')
	default:
		return String(value)
	}
}

// ========================================
// Field Options Parsing
// ========================================

/**
 * Parse a field's options value into an array of strings.
 *
 * Handles:
 *   - Already an array  -> returned as-is
 *   - JSON string       -> parsed
 *   - Newline-separated -> split
 *
 * @param {*} options
 * @return {string[]}
 */
export function parseFieldOptions(options) {
	if (!options) return []
	if (Array.isArray(options)) return options
	try {
		const parsed = JSON.parse(options)
		if (Array.isArray(parsed)) return parsed
	} catch (e) { /* not JSON */ }
	return options.split('\n').filter(v => v.trim() !== '')
}

// ========================================
// Column Width Helpers
// ========================================

/**
 * Return the default pixel width for a given field type.
 *
 * @param {string} fieldType
 * @return {number}
 */
export function getDefaultColWidth(fieldType) {
	switch (fieldType) {
	case 'checkbox': return 90
	case 'number': return 100
	case 'date': return 120
	case 'select': return 120
	case 'user': return 140
	case 'text': return 150
	case 'multiselect': return 160
	case 'url': return 160
	case 'textarea': return 180
	case 'filelink': return 160
	default: return 150
	}
}

/**
 * Return the resolved column width for a field, taking persisted widths
 * and minimum label widths into account.
 *
 * @param {string} fieldName
 * @param {string} fieldType
 * @param {string} fieldLabel
 * @return {number}
 */
export function getColWidth(fieldName, fieldType, fieldLabel) {
	const persisted = columnWidths.get(fieldName)
	if (persisted) return persisted
	const typeDefault = getDefaultColWidth(fieldType)
	if (!fieldLabel) return typeDefault
	// Ensure header text fits: ~7.5px per char at 13px font + 48px for sort icon + padding
	const labelMin = Math.ceil(fieldLabel.length * 7.5) + 48
	return Math.max(typeDefault, labelMin)
}
