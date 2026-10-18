/**
 * MetaVox — Pure helper / utility functions for column rendering.
 */

import { columnWidths } from './MetaVoxState.js'
import { formatFieldValue, isChecked, splitMulti } from '../../utils/fieldValue.js'
import { optionLabel } from '../../utils/fieldOptions.js'

// ========================================
// Value Formatting
// ========================================

/**
 * Format a metadata value for display in a table cell.
 *
 * @param {*}      value     Raw metadata value
 * @param {string} fieldType Field type identifier
 * @param {Object} [field]   Optional field definition (for date includeTime)
 * @return {string}
 */
export function formatValue(value, fieldType, field = null) {
	if (value === null || value === undefined || value === '') return ''

	switch (fieldType) {
	case 'checkbox':
	case 'boolean':
		return isChecked(value) ? '\u2713' : ''
	case 'date':
		// Read the way the sidebar and the filter chips read it.
		return formatFieldValue(value, fieldType, field)
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

// ========================================
// View Columns
// ========================================

/**
 * The visible columns of a view, in its order. Label, type, options and required come
 * from the field itself: the copy a view keeps can be from before a change.
 *
 * @param {Object} view
 * @param {Object[]} availableFields
 * @return {Object[]} new column objects; the view is left as it is
 */
export function viewColumnConfigs(view, availableFields) {
	const ordered = []
	const usedNames = new Set()
	for (const vc of view?.columns ?? []) {
		const visible = vc.visible !== false && vc.show_as_column !== false
		if (!visible || !vc.field_name || usedNames.has(vc.field_name)) continue
		const field = (availableFields ?? []).find(f => f.field_name === vc.field_name || String(f.id) === String(vc.field_id))
		ordered.push(field ? { ...vc, field_label: field.field_label, field_type: field.field_type, field_options: field.field_options, is_required: field.is_required } : { ...vc })
		usedNames.add(vc.field_name)
	}
	return ordered
}

/**
 * A deep copy of a column's highlight rules; [] for anything else. Through
 * JSON, which also reads Vue's reactive proxies (structuredClone throws on them).
 *
 * @param {*} rules
 * @return {Object[]}
 */
export const cloneRules = (rules) => (Array.isArray(rules) ? JSON.parse(JSON.stringify(rules)) : [])

/**
 * The columns the view editor sends. Rules go along only for a column whose
 * rules differ from what the editor loaded: a column sent without "format"
 * keeps what the server has, so a save can't undo rules set elsewhere in the
 * meantime. Columns the editor could not show (their field did not load) go
 * back as they came, after the others.
 *
 * @param {Object[]} columns editor columns, each with rules: [{key, rule}]
 * @param {Map<*, ?string>} baseline field id → JSON of the rules as loaded; null always sends
 * @param {Object[]} carried stored columns the editor could not show
 * @return {{columns: Object[], sent: {fieldId: *, keys: ?Array}[]}} sent: per sent column, the rule keys in order, or null without format
 */
export function viewColumnsPayload(columns, baseline, carried = []) {
	const out = []
	const sent = []
	for (const col of columns) {
		const column = {
			field_id: col.field_id,
			field_name: col.field_name,
			field_label: col.field_label,
			visible: col.visible,
			filterable: col.filterable,
		}
		const rules = (col.rules ?? []).map((r) => r.rule)
		if (baseline.get(col.field_id) !== JSON.stringify(rules)) {
			column.format = cloneRules(rules)
			sent.push({ fieldId: col.field_id, keys: (col.rules ?? []).map((r) => r.key) })
		} else {
			sent.push({ fieldId: col.field_id, keys: null })
		}
		out.push(column)
	}
	for (const column of carried) {
		const { format, ...rest } = column
		out.push(rest)
		sent.push({ fieldId: column.field_id, keys: null })
	}
	return { columns: out, sent }
}

/**
 * The columns of a new view made from the grid ("Save filters"): what is on
 * screen, its highlight rules included.
 *
 * @param {Object[]} configs active column configs
 * @return {Object[]}
 */
export function columnsForNewView(configs) {
	return (configs ?? []).map((c) => {
		const column = {
			field_id: c.field_id,
			field_name: c.field_name,
			field_label: c.field_label,
			visible: c.visible !== false,
			filterable: c.filterable !== false,
		}
		const rules = cloneRules(c.format)
		if (rules.length) column.format = rules
		return column
	})
}

/**
 * A view's columns without their highlight rules. A column sent without
 * "format" keeps the rules the server has, so a save that is not about rules
 * can't undo a change made elsewhere since the page loaded.
 *
 * @param {Object[]} columns
 * @return {Object[]} new column objects
 */
export const columnsWithoutRules = (columns) => columns.map(({ format, ...column }) => column)

/**
 * The type a view's sort field sorts by: the field's own, as for the columns.
 *
 * @param {Object} view
 * @param {Object[]} availableFields
 * @return {string}
 */
export function sortFieldType(view, availableFields) {
	const col = (availableFields ?? []).find(c => c.field_name === view.sort_field)
		?? (view.columns ?? []).find(c => c.field_name === view.sort_field)
	return col?.field_type || 'text'
}

/** mvview for "Default": no view, chosen on purpose. View ids are numbers. */
export const DEFAULT_VIEW = 'default'

/**
 * The view a folder opens in. The link first: its view, or none for
 * mvview=default. Within the same team folder, the view you were in stays,
 * and so does "Default" when you chose it. Otherwise the default view.
 *
 * @param {Object[]} views the team folder's views
 * @param {Object} from
 * @param {?string} from.linkView mvview in the URL
 * @param {?{viewId: *}} from.stayed the view you were in, when the team folder stays the same
 * @return {?Object} the view, or null for none
 */
export function viewToOpen(views, { linkView = null, stayed = null } = {}) {
	const byId = (id) => views.find((v) => String(v.id) === String(id)) ?? null
	// No view has the id "default", so that one opens none.
	if (linkView !== null) return byId(linkView)
	if (stayed) {
		if (stayed.viewId === null) return null
		// A view deleted meanwhile falls back to the default one.
		const view = byId(stayed.viewId)
		if (view) return view
	}
	return views.find((v) => v.is_default) ?? null
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
 * @param {number} [reserve] extra room in the head, e.g. for the highlight sign
 * @return {number}
 */
export function getColWidth(fieldName, fieldType, fieldLabel, reserve = 0) {
	const persisted = columnWidths.get(fieldName)
	if (persisted) return persisted
	const typeDefault = getDefaultColWidth(fieldType)
	if (!fieldLabel) return typeDefault
	// Ensure header text fits: ~7.5px per char at 13px font + 48px for sort icon + padding
	const labelMin = Math.ceil(fieldLabel.length * 7.5) + 48 + reserve
	return Math.max(typeDefault, labelMin)
}
