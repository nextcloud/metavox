/**
 * MetaVox Flow Check Component (Vanilla JS / Vue 2 compatible)
 *
 * Renders the metadata check configuration UI for NC's WorkflowEngine.
 * Uses Vue 2 render function (createElement) — no template compilation needed.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

// Operators per field type
const OPERATORS_BY_TYPE = {
	text: [
		{ operator: 'is', name: 'equals' },
		{ operator: 'contains', name: 'contains' },
		{ operator: '!contains', name: 'does not contain' },
		{ operator: '!empty', name: 'is not empty' },
		{ operator: 'empty', name: 'is empty' },
	],
	textarea: [
		{ operator: 'is', name: 'equals' },
		{ operator: 'contains', name: 'contains' },
		{ operator: '!empty', name: 'is not empty' },
		{ operator: 'empty', name: 'is empty' },
	],
	date: [
		{ operator: 'is', name: 'equals' },
		{ operator: 'before', name: 'is before' },
		{ operator: 'after', name: 'is after' },
		{ operator: '!empty', name: 'is not empty' },
		{ operator: 'empty', name: 'is empty' },
	],
	number: [
		{ operator: 'is', name: 'equals' },
		{ operator: 'greater', name: 'greater than' },
		{ operator: 'less', name: 'less than' },
		{ operator: '!empty', name: 'is not empty' },
		{ operator: 'empty', name: 'is empty' },
	],
	select: [
		{ operator: 'is', name: 'equals' },
		{ operator: 'oneOf', name: 'is one of' },
		{ operator: '!empty', name: 'is not empty' },
		{ operator: 'empty', name: 'is empty' },
	],
	dropdown: [
		{ operator: 'is', name: 'equals' },
		{ operator: 'oneOf', name: 'is one of' },
		{ operator: '!empty', name: 'is not empty' },
		{ operator: 'empty', name: 'is empty' },
	],
	multiselect: [
		{ operator: 'contains', name: 'contains' },
		{ operator: 'containsAll', name: 'contains all' },
		{ operator: '!empty', name: 'is not empty' },
		{ operator: 'empty', name: 'is empty' },
	],
	checkbox: [
		{ operator: 'is', name: 'equals' },
		{ operator: '!empty', name: 'is not empty' },
		{ operator: 'empty', name: 'is empty' },
	],
	boolean: [
		{ operator: 'is', name: 'equals' },
		{ operator: '!empty', name: 'is not empty' },
		{ operator: 'empty', name: 'is empty' },
	],
	url: [
		{ operator: 'is', name: 'equals' },
		{ operator: 'contains', name: 'contains' },
		{ operator: '!empty', name: 'is not empty' },
		{ operator: 'empty', name: 'is empty' },
	],
	user: [
		{ operator: 'is', name: 'equals' },
		{ operator: '!empty', name: 'is not empty' },
		{ operator: 'empty', name: 'is empty' },
	],
}

const DEFAULT_OPERATORS = [
	{ operator: 'is', name: 'equals' },
	{ operator: 'contains', name: 'contains' },
	{ operator: '!empty', name: 'is not empty' },
	{ operator: 'empty', name: 'is empty' },
]

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
		const operators = OPERATORS_BY_TYPE[field?.type] || DEFAULT_OPERATORS
		const noValueOps = ['empty', '!empty']
		const showValue = !noValueOps.includes(this.selectedOperator)
		const isCheckbox = field?.type === 'checkbox' || field?.type === 'boolean'
		const isSelect = field?.type === 'select' || field?.type === 'dropdown'
		const isMultiselect = field?.type === 'multiselect'

		const children = []

		// Field selector
		children.push(h('div', { class: 'check-field' }, [
			h('label', 'Metadata field'),
			h('select', {
				domProps: { value: this.selectedFieldName },
				on: { change(e) { self.selectedFieldName = e.target.value; self.onFieldChange(operators) } },
			}, [
				h('option', { attrs: { value: '', disabled: true } }, 'Select a metadata field'),
				...this.fields.map(f =>
					h('option', { attrs: { value: f.name }, key: f.name }, f.label)
				),
			]),
			this.loadingFields ? h('span', { style: 'font-size:12px;color:var(--color-text-lighter);margin-left:8px' }, 'Loading...') : null,
		]))

		// Operator selector
		if (field) {
			children.push(h('div', { class: 'check-field' }, [
				h('label', 'Operator'),
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
					h('option', { attrs: { value: '', disabled: true } }, 'Select a value'),
					h('option', { attrs: { value: '1' } }, 'Yes (checked)'),
					h('option', { attrs: { value: '0' } }, 'No (unchecked)'),
				])
			} else if ((isSelect || isMultiselect) && field.options?.length > 0) {
				valueEl = h('select', {
					domProps: { value: this.checkValue },
					on: { change(e) { self.checkValue = e.target.value; self.updateValue() } },
				}, [
					h('option', { attrs: { value: '', disabled: true } }, 'Select a value'),
					...field.options.map(opt => {
						const val = typeof opt === 'string' ? opt : (opt.value || opt.label)
						const label = typeof opt === 'string' ? opt : (opt.label || opt.value)
						return h('option', { attrs: { value: val }, key: val }, label)
					}),
				])
			} else if (field.type === 'date') {
				valueEl = h('input', {
					attrs: { type: 'date' },
					domProps: { value: this.checkValue },
					on: { change(e) { self.checkValue = e.target.value; self.updateValue() } },
				})
			} else if (field.type === 'number') {
				valueEl = h('input', {
					attrs: { type: 'number', placeholder: 'Enter a number' },
					domProps: { value: this.checkValue },
					on: { input(e) { self.checkValue = e.target.value; self.updateValue() } },
				})
			} else {
				valueEl = h('input', {
					attrs: { type: 'text', placeholder: 'Enter expected value' },
					domProps: { value: this.checkValue },
					on: { input(e) { self.checkValue = e.target.value; self.updateValue() } },
				})
			}
			children.push(h('div', { class: 'check-field' }, [
				h('label', 'Value to check'),
				valueEl,
			]))
		}

		// Groupfolder selector
		if (field) {
			children.push(h('div', { class: 'check-field' }, [
				h('label', 'Team folder (optional)'),
				h('select', {
					domProps: { value: this.selectedGroupfolderId },
					on: { change(e) { self.selectedGroupfolderId = e.target.value; self.updateValue() } },
				}, [
					h('option', { attrs: { value: '' } }, 'Auto-detect'),
					...this.groupfolders.map(gf =>
						h('option', { attrs: { value: gf.id }, key: gf.id }, gf.label)
					),
				]),
				h('p', { class: 'hint' }, 'Leave empty to auto-detect from file location'),
			]))
		}

		// Preview
		if (field && (this.checkValue || !showValue)) {
			const opLabel = operators.find(o => o.operator === this.selectedOperator)?.name || this.selectedOperator
			children.push(h('div', { class: 'check-preview' }, [
				h('span', { class: 'preview-label' }, 'Check configuration:'),
				h('code', `${field.label} ${opLabel}${showValue ? ` "${this.checkValue}"` : ''}`),
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
					label: gf.mount_point || `Team folder ${gf.id}`,
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
