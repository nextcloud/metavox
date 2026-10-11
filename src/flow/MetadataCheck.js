/**
 * MetaVox Flow Check Component (Vanilla JS / Vue 2 compatible)
 *
 * Renders the metadata check configuration UI for NC's WorkflowEngine.
 * Uses Vue 2 render function (createElement) — no template compilation needed.
 */
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { dateFieldIncludesTime, padDatetimeLocal } from '../utils/dateField.js'
import { PLAIN_TEXT } from '../utils/fieldValue.js'

// Operators per field type, as the ids MetadataCheck.php evaluates.
const OPERATORS_BY_TYPE = {
	text: ['is', 'contains', '!contains', '!empty', 'empty'],
	textarea: ['is', 'contains', '!empty', 'empty'],
	date: ['is', 'before', 'after', '!empty', 'empty'],
	number: ['is', 'greater', 'less', '!empty', 'empty'],
	select: ['is', 'oneOf', '!empty', 'empty'],
	dropdown: ['is', 'oneOf', '!empty', 'empty'],
	multiselect: ['contains', 'containsAll', '!empty', 'empty'],
	checkbox: ['is', '!empty', 'empty'],
	boolean: ['is', '!empty', 'empty'],
	url: ['is', 'contains', '!empty', 'empty'],
	user: ['is', '!empty', 'empty'],
}

const DEFAULT_OPERATORS = ['is', 'contains', '!empty', 'empty']

/**
 * The label of an operator. One literal t() per id, so the Transifex bot
 * extracts every label.
 *
 * @param {string} operator
 * @return {string}
 */
function operatorName(operator) {
	switch (operator) {
	// TRANSLATORS: Flow check operator, read as "<field> equals <value>".
	case 'is': return t('metavox', 'equals')
	// TRANSLATORS: Flow check operator, read as "<field> is not <value>".
	case '!is': return t('metavox', 'is not')
	// TRANSLATORS: Flow check operator, read as "<field> contains <value>".
	case 'contains': return t('metavox', 'contains')
	// TRANSLATORS: Flow check operator, read as "<field> does not contain <value>".
	case '!contains': return t('metavox', 'does not contain')
	// TRANSLATORS: Flow check operator for a multi-select field, read as "<field> contains all <values>".
	case 'containsAll': return t('metavox', 'contains all')
	// TRANSLATORS: Flow check operator for a dropdown field, read as "<field> is one of <values>".
	case 'oneOf': return t('metavox', 'is one of')
	// TRANSLATORS: Flow check operator for a date field, read as "<field> is before <date>".
	case 'before': return t('metavox', 'is before')
	// TRANSLATORS: Flow check operator for a date field, read as "<field> is after <date>".
	case 'after': return t('metavox', 'is after')
	// TRANSLATORS: Flow check operator for a number field, read as "<field> greater than <number>".
	case 'greater': return t('metavox', 'greater than')
	// TRANSLATORS: Flow check operator for a number field, read as "<field> less than <number>".
	case 'less': return t('metavox', 'less than')
	// TRANSLATORS: Flow check operator for a number field, read as "<field> greater or equal <number>"; keep it short.
	case 'greaterOrEqual': return t('metavox', 'greater or equal')
	// TRANSLATORS: Flow check operator for a number field, read as "<field> less or equal <number>"; keep it short.
	case 'lessOrEqual': return t('metavox', 'less or equal')
	// TRANSLATORS: Flow check operator without a value, read as "<field> is empty".
	case 'empty': return t('metavox', 'is empty')
	// TRANSLATORS: Flow check operator without a value, read as "<field> is not empty".
	case '!empty': return t('metavox', 'is not empty')
	default: return operator
	}
}

const CSS = `
.metavox-check { padding: 0; width: 100%; display: flex; flex-wrap: wrap; gap: 6px; align-items: flex-end; }
.metavox-check .check-field { min-width: 160px; flex: 1; }
.metavox-check label { display: block; font-size: 11px; color: var(--color-text-maxcontrast); margin-bottom: 2px; font-weight: 500; }
.metavox-check select, .metavox-check input {
	width: 100%; height: 36px; padding: 0 10px;
	border: 2px solid var(--color-border-dark); border-radius: var(--border-radius-large);
	background: var(--color-main-background); color: var(--color-main-text);
	font-size: 14px; box-sizing: border-box;
}
.metavox-check select { cursor: pointer; appearance: none;
	background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24'%3E%3Cpath fill='%23969696' d='M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z'/%3E%3C/svg%3E");
	background-repeat: no-repeat; background-position: right 8px center; background-size: 16px;
	padding-right: 28px;
}
.metavox-check select:hover, .metavox-check input:hover { border-color: var(--color-primary-element); }
.metavox-check select:focus, .metavox-check input:focus {
	border-color: var(--color-primary-element); outline: none;
	box-shadow: 0 0 0 2px var(--color-primary-element-light);
}
.metavox-check .hint { font-size: 11px; color: var(--color-text-lighter); margin: 2px 0 0 0; }
.metavox-check .check-preview {
	background: var(--color-background-dark); border-radius: var(--border-radius);
	padding: 6px 10px; margin-top: 4px; flex-basis: 100%;
}
.metavox-check .preview-label { font-size: 11px; color: var(--color-text-lighter); display: block; margin-bottom: 2px; }
.metavox-check code { font-family: monospace; font-size: 13px; }
`

export default {
	name: 'MetadataCheck',

	props: {
		value: { type: String, default: '' },
		check: { type: Object, default: () => ({}) },
	},

	data() {
		return {
			fields: [],
			groupfolders: [],
			selectedFieldName: '',
			selectedGroupfolderId: '',
			selectedMultipleValues: [],
			checkValue: '',
			selectedOperator: 'is',
			loadingFields: false,
		}
	},

	mounted() {
		// Inject CSS once
		if (!document.querySelector('#metavox-flow-css')) {
			const style = document.createElement('style')
			style.id = 'metavox-flow-css'
			style.textContent = CSS
			document.head.appendChild(style)
		}
		this.loadFields()
		this.loadGroupfolders()
	},

	watch: {
		value: {
			immediate: false,
			handler(v) {
				if (v && this.fields.length > 0) this.parseConfig()
			},
		},
	},

	render(h) {
		const self = this
		const field = this.fields.find(f => f.name === this.selectedFieldName)
		const operators = (OPERATORS_BY_TYPE[field?.type] || DEFAULT_OPERATORS)
			.map(operator => ({ operator, name: operatorName(operator) }))
		const noValueOps = ['empty', '!empty']
		const showValue = !noValueOps.includes(this.selectedOperator)
		const isCheckbox = field?.type === 'checkbox' || field?.type === 'boolean'
		const isSelect = field?.type === 'select' || field?.type === 'dropdown'
		const isMultiselect = field?.type === 'multiselect'

		const children = []

		// Field selector
		children.push(h('div', { class: 'check-field' }, [
			// TRANSLATORS Noun: label of the dropdown that picks the metadata field the Flow rule checks
			h('label', t('metavox', 'Metadata field')),
			h('select', {
				domProps: { value: this.selectedFieldName },
				on: { change(e) { self.selectedFieldName = e.target.value; self.onFieldChange(operators) } },
			}, [
				// TRANSLATORS Placeholder entry of the field dropdown in the Flow rule
				h('option', { attrs: { value: '', disabled: true } }, t('metavox', 'Select a metadata field')),
				...this.fields.map(f =>
					h('option', { attrs: { value: f.name }, key: f.name }, f.label)
				),
			]),
			// TRANSLATORS Shown next to the field dropdown while the fields load
			this.loadingFields ? h('span', { style: 'font-size:12px;color:var(--color-text-lighter);margin-left:8px' }, t('metavox', 'Loading …')) : null,
		]))

		// Operator selector
		if (field) {
			children.push(h('div', { class: 'check-field' }, [
				// TRANSLATORS Noun: label of the dropdown that picks how the field is compared, e.g. equals, contains
				h('label', t('metavox', 'Operator')),
				h('select', {
					domProps: { value: this.selectedOperator },
					on: { change(e) { self.selectedOperator = e.target.value; self.updateValue() } },
				}, operators.map(op =>
					h('option', { attrs: { value: op.operator }, key: op.operator }, op.name)
				)),
			]))
		}

		// Value input
		if (field && showValue) {
			let valueEl
			if (isCheckbox) {
				valueEl = h('select', {
					domProps: { value: this.checkValue },
					on: { change(e) { self.checkValue = e.target.value; self.updateValue() } },
				}, [
					// TRANSLATORS Placeholder entry of the value dropdown in the Flow rule
					h('option', { attrs: { value: '', disabled: true } }, t('metavox', 'Select a value')),
					// TRANSLATORS Value choice for a checkbox field: the box is ticked
					h('option', { attrs: { value: '1' } }, t('metavox', 'Yes (checked)')),
					// TRANSLATORS Value choice for a checkbox field: the box is not ticked
					h('option', { attrs: { value: '0' } }, t('metavox', 'No (unchecked)')),
				])
			} else if ((isSelect || isMultiselect) && field.options?.length > 0) {
				valueEl = h('select', {
					domProps: { value: this.checkValue },
					on: { change(e) { self.checkValue = e.target.value; self.updateValue() } },
				}, [
					// TRANSLATORS Placeholder entry of the value dropdown in the Flow rule
					h('option', { attrs: { value: '', disabled: true } }, t('metavox', 'Select a value')),
					...field.options.map(opt => {
						const val = typeof opt === 'string' ? opt : (opt.value || opt.label)
						const label = typeof opt === 'string' ? opt : (opt.label || opt.value)
						return h('option', { attrs: { value: val }, key: val }, label)
					}),
				])
			} else if (field.type === 'date') {
				const withTime = dateFieldIncludesTime(field)
				valueEl = h('input', {
					attrs: withTime ? { type: 'datetime-local', step: '1' } : { type: 'date' },
					domProps: { value: this.checkValue },
					on: { change(e) { self.checkValue = withTime ? padDatetimeLocal(e.target.value) : e.target.value; self.updateValue() } },
				})
			} else if (field.type === 'number') {
				valueEl = h('input', {
					// TRANSLATORS Placeholder of the value box for a number field in the Flow rule
					attrs: { type: 'number', placeholder: t('metavox', 'Enter a number') },
					domProps: { value: this.checkValue },
					on: { input(e) { self.checkValue = e.target.value; self.updateValue() } },
				})
			} else {
				valueEl = h('input', {
					// TRANSLATORS Placeholder of the value box for a text field in the Flow rule
					attrs: { type: 'text', placeholder: t('metavox', 'Enter expected value') },
					domProps: { value: this.checkValue },
					on: { input(e) { self.checkValue = e.target.value; self.updateValue() } },
				})
			}
			children.push(h('div', { class: 'check-field' }, [
				// TRANSLATORS Label of the input for the value the field is compared with in the Flow rule
				h('label', t('metavox', 'Value to check')),
				valueEl,
			]))
		}

		// Groupfolder selector
		if (field) {
			children.push(h('div', { class: 'check-field' }, [
				// TRANSLATORS Label of the dropdown that limits the Flow rule to one Team folder; it may stay empty
				h('label', t('metavox', 'Team folder (optional)')),
				h('select', {
					domProps: { value: this.selectedGroupfolderId },
					on: { change(e) { self.selectedGroupfolderId = e.target.value; self.updateValue() } },
				}, [
					// TRANSLATORS Entry of the Team folder dropdown: the Team folder is found from where the file is
					h('option', { attrs: { value: '' } }, t('metavox', 'Auto-detect')),
					...this.groupfolders.map(gf =>
						h('option', { attrs: { value: gf.id }, key: gf.id }, gf.label)
					),
				]),
				h('p', { class: 'hint' }, t('metavox', 'Leave empty to auto-detect from file location')),
			]))
		}

		// Preview
		if (field && (this.checkValue || !showValue)) {
			const vars = { field: field.label, operator: operatorName(this.selectedOperator), value: this.checkValue }
			children.push(h('div', { class: 'check-preview' }, [
				// TRANSLATORS Label before a preview of the Flow check, e.g. Status equals "Approved"
				h('span', { class: 'preview-label' }, t('metavox', 'Check configuration:')),
				// The rule read as a sentence, e.g. Status equals "Approved".
				h('code', showValue
					// TRANSLATORS: Preview of a Flow check, e.g. Status equals "Approved".
					? t('metavox', '{field} {operator} "{value}"', vars, undefined, PLAIN_TEXT)
					// TRANSLATORS: Preview of a Flow check without a value, e.g. Status is empty.
					: t('metavox', '{field} {operator}', vars, undefined, PLAIN_TEXT)),
			]))
		}

		return h('div', { class: 'metavox-check' }, children)
	},

	methods: {
		async loadFields() {
			this.loadingFields = true
			try {
				const resp = await axios.get(generateUrl('/apps/metavox/api/groupfolder-fields'))
				this.fields = (resp.data || []).map(f => ({
					name: f.field_name,
					label: f.field_label || f.field_name,
					type: f.field_type,
					options: f.field_options || [],
				}))
				if (this.value) this.$nextTick(() => this.parseConfig())
			} catch (e) {
				this.fields = []
			} finally {
				this.loadingFields = false
			}
		},

		async loadGroupfolders() {
			try {
				const resp = await axios.get(generateUrl('/apps/metavox/api/groupfolders'))
				this.groupfolders = (resp.data || []).map(gf => ({
					id: gf.id,
					// TRANSLATORS Name of a Team folder that has no name; {id} is its number, e.g. "Team folder 4"
					label: gf.mount_point || t('metavox', 'Team folder {id}', { id: gf.id }),
				}))
			} catch (e) {
				this.groupfolders = []
			}
		},

		parseConfig() {
			try {
				const config = JSON.parse(this.value)
				if (config.field_name) this.selectedFieldName = config.field_name
				if (config.operator) this.selectedOperator = config.operator
				if (config.value !== undefined) this.checkValue = config.value
				if (config.groupfolder_id) this.selectedGroupfolderId = config.groupfolder_id
			} catch (e) { /* ignore */ }
		},

		onFieldChange(operators) {
			this.checkValue = ''
			this.selectedOperator = operators.length > 0 ? operators[0].operator : 'is'
			this.updateValue()
		},

		updateValue() {
			const config = {
				field_name: this.selectedFieldName || '',
				value: this.checkValue || '',
				operator: this.selectedOperator || 'is',
			}
			if (this.selectedGroupfolderId) {
				config.groupfolder_id = this.selectedGroupfolderId
			}
			this.$emit('input', JSON.stringify(config))
		},
	},
}
