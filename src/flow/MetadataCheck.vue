<template>
	<div class="metavox-check">
		<div class="check-field">
			<label>{{ t('metavox', 'Metadata field') }}</label>
			<select
				v-model="selectedFieldName"
				class="nc-select-input"
				@change="onFieldChange"
			>
				<option value="" disabled>{{ t('metavox', 'Select a metadata field') }}</option>
				<option
					v-for="field in groupedFields"
					:key="field.name"
					:value="field.isHeader ? '' : field.name"
					:disabled="field.isHeader"
					:class="{ 'field-group-header': field.isHeader }"
				>
					{{ field.label }}
				</option>
			</select>
			<span v-if="loadingFields" class="loading-indicator">{{ t('metavox', 'Loading...') }}</span>
		</div>

		<!-- Operator selection based on field type -->
		<div v-if="selectedField && !selectedField.isHeader" class="check-field">
			<label>{{ t('metavox', 'Operator') }}</label>
			<select
				v-model="selectedOperator"
				class="nc-select-input"
				@change="onOperatorChange"
			>
				<option
					v-for="op in availableOperators"
					:key="op.operator"
					:value="op.operator"
				>
					{{ op.name }}
				</option>
			</select>
		</div>

		<!-- Value input - hidden for empty checks -->
		<div
			v-if="selectedField && !selectedField.isHeader && showValueInput"
			:key="'value-' + selectedFieldName + '-' + (selectedField.type || 'text') + '-' + selectedOperator"
			class="check-field"
		>
			<label>{{ t('metavox', 'Value to check') }}</label>

			<!-- Checkbox/Boolean field - Yes/No dropdown -->
			<select
				v-if="isCheckboxField"
				v-model="checkValue"
				class="nc-select-input"
				@change="updateValue"
			>
				<option value="" disabled>{{ t('metavox', 'Select a value') }}</option>
				<option value="1">{{ t('metavox', 'Yes (checked)') }}</option>
				<option value="0">{{ t('metavox', 'No (unchecked)') }}</option>
			</select>

			<!-- Select/Dropdown field with oneOf support (multi-select) -->
			<select
				v-else-if="isSelectField && selectedOperator === 'oneOf'"
				v-model="selectedMultipleValues"
				class="nc-select-input nc-select-multiple"
				multiple
				@change="onMultiSelectChange"
			>
				<option
					v-for="opt in fieldOptions"
					:key="opt.value"
					:value="opt.value"
				>
					{{ opt.label }}
				</option>
			</select>

			<!-- Select/Dropdown field single value -->
			<select
				v-else-if="isSelectField"
				v-model="checkValue"
				class="nc-select-input"
				@change="updateValue"
			>
				<option value="" disabled>{{ t('metavox', 'Select a value') }}</option>
				<option
					v-for="opt in fieldOptions"
					:key="opt.value"
					:value="opt.value"
				>
					{{ opt.label }}
				</option>
			</select>

			<!-- Multiselect field with containsAll support -->
			<select
				v-else-if="isMultiselectField"
				v-model="selectedMultipleValues"
				class="nc-select-input nc-select-multiple"
				multiple
				@change="onMultiSelectChange"
			>
				<option
					v-for="opt in fieldOptions"
					:key="opt.value"
					:value="opt.value"
				>
					{{ opt.label }}
				</option>
			</select>

			<!-- Date field -->
			<input
				v-else-if="selectedField.type === 'date'"
				v-model="checkValue"
				type="date"
				class="nc-input-field"
				@change="updateValue"
			>

			<!-- Number field -->
			<input
				v-else-if="selectedField.type === 'number'"
				v-model="checkValue"
				type="number"
				class="nc-input-field"
				:placeholder="t('metavox', 'Enter a number')"
				@input="updateValue"
			>

			<!-- Default: text input -->
			<input
				v-else
				v-model="checkValue"
				type="text"
				class="nc-input-field"
				:placeholder="t('metavox', 'Enter expected value')"
				@input="updateValue"
			>
		</div>

		<div v-if="selectedField" class="check-field">
			<label>{{ t('metavox', 'Team folder (optional)') }}</label>
			<select
				v-model="selectedGroupfolderId"
				class="nc-select-input"
				@change="updateValue"
			>
				<option value="">{{ t('metavox', 'Auto-detect') }}</option>
				<option
					v-for="gf in groupfolders"
					:key="gf.id"
					:value="gf.id"
				>
					{{ gf.label }}
				</option>
			</select>
			<p class="hint">{{ t('metavox', 'Leave empty to auto-detect from file location') }}</p>
		</div>

		<div v-if="selectedField && (checkValue || !showValueInput)" class="check-preview">
			<span class="preview-label">{{ t('metavox', 'Check configuration:') }}</span>
			<code>{{ selectedField.label }} {{ operatorLabel }}<span v-if="showValueInput"> "{{ displayValue }}"</span></code>
		</div>
	</div>
</template>

<script>
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
		{ operator: '!contains', name: 'does not contain' },
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
		{ operator: 'greaterOrEqual', name: 'greater or equal' },
		{ operator: 'lessOrEqual', name: 'less or equal' },
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
	file: [
		{ operator: '!empty', name: 'is not empty' },
		{ operator: 'empty', name: 'is empty' },
	],
}

// Default operators for unknown field types
const DEFAULT_OPERATORS = [
	{ operator: 'is', name: 'equals' },
	{ operator: '!is', name: 'is not' },
	{ operator: 'contains', name: 'contains' },
	{ operator: '!empty', name: 'is not empty' },
	{ operator: 'empty', name: 'is empty' },
]

export default {
	name: 'MetadataCheck',

	props: {
		value: {
			type: String,
			default: '',
		},
		check: {
			type: Object,
			default: () => ({}),
		},
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
			loadingGroupfolders: false,
			isParsingConfig: false,
		}
	},

	computed: {
		selectedField() {
			if (!this.selectedFieldName) return null
			return this.fields.find(f => f.name === this.selectedFieldName) || null
		},
		displayValue() {
			if (this.isCheckboxField) {
				return this.checkValue === '1' ? 'Yes' : 'No'
			}
			return this.checkValue
		},
		availableOperators() {
			return this.getOperatorsForFieldType(this.selectedField?.type)
		},
		operatorLabel() {
			const found = this.availableOperators.find(o => o.operator === this.selectedOperator)
			return found ? found.name : this.selectedOperator
		},
		showValueInput() {
			// Hide value input for empty checks
			const noValueOps = ['empty', '!empty']
			// Default to true for text fields etc.
			if (!this.selectedOperator || !noValueOps.includes(this.selectedOperator)) {
				return true
			}
			return false
		},
		isCheckboxField() {
			const type = this.selectedField?.type
			return type === 'checkbox' || type === 'boolean'
		},
		isSelectField() {
			const type = this.selectedField?.type
			return type === 'select' || type === 'dropdown'
		},
		isMultiselectField() {
			return this.selectedField?.type === 'multiselect'
		},
		fieldOptions() {
			if (!this.selectedField?.options) {
				return []
			}
			return this.selectedField.options.map(opt => {
				if (typeof opt === 'string') {
					return { label: opt, value: opt }
				}
				return { label: opt.label || opt.value, value: opt.value || opt.label }
			})
		},
		groupedFields() {
			const groupfolderFields = this.fields.filter(f => f.appliesToGroupfolder)
			const fileFields = this.fields.filter(f => !f.appliesToGroupfolder)

			const result = []

			if (fileFields.length > 0) {
				result.push({
					label: this.t('metavox', '── File fields ──'),
					name: '__header_file__',
					isHeader: true,
				})
				result.push(...fileFields)
			}

			if (groupfolderFields.length > 0) {
				result.push({
					label: this.t('metavox', '── Team folder fields ──'),
					name: '__header_groupfolder__',
					isHeader: true,
				})
				result.push(...groupfolderFields)
			}

			return result
		},
	},

	mounted() {
		this.loadFields()
		this.loadGroupfolders()
	},

	activated() {
		this.loadFields()
		this.loadGroupfolders()
	},

	methods: {
		getOperatorsForFieldType(fieldType) {
			return OPERATORS_BY_TYPE[fieldType] || DEFAULT_OPERATORS
		},

		async loadFields() {
			this.loadingFields = true
			try {
				const response = await axios.get(generateUrl('/apps/metavox/api/groupfolder-fields'))
				this.fields = (response.data || []).map(f => ({
					name: f.field_name,
					label: f.field_label || f.field_name,
					type: f.field_type,
					options: f.field_options || [],
					appliesToGroupfolder: f.applies_to_groupfolder === true || f.applies_to_groupfolder === 1,
				}))
				// Parse existing config after fields are loaded
				if (this.value) {
					this.$nextTick(() => {
						this.parseExistingConfig()
					})
				}
			} catch (error) {
				console.error('Failed to load metadata fields:', error)
				this.fields = []
			} finally {
				this.loadingFields = false
			}
		},

		async loadGroupfolders() {
			this.loadingGroupfolders = true
			try {
				const response = await axios.get(generateUrl('/apps/metavox/api/groupfolders'))
				this.groupfolders = (response.data || []).map(gf => ({
					id: gf.id,
					label: gf.mount_point || gf.label || `Team folder ${gf.id}`,
				}))
			} catch (error) {
				console.error('Failed to load groupfolders:', error)
				this.groupfolders = []
			} finally {
				this.loadingGroupfolders = false
			}
		},

		parseExistingConfig() {
			if (!this.value) {
				return
			}

			this.isParsingConfig = true

			try {
				const config = JSON.parse(this.value)

				if (config.field_name) {
					this.selectedFieldName = config.field_name
				}

				if (config.operator) {
					this.selectedOperator = config.operator
				} else {
					// Default to first available operator for field type
					const field = this.fields.find(f => f.name === config.field_name)
					const operators = this.getOperatorsForFieldType(field?.type)
					this.selectedOperator = operators.length > 0 ? operators[0].operator : 'is'
				}

				if (config.value !== undefined && config.value !== '') {
					// Handle multi-select values (JSON array)
					if (typeof config.value === 'string' && config.value.startsWith('[')) {
						try {
							this.selectedMultipleValues = JSON.parse(config.value)
							this.checkValue = config.value
						} catch {
							this.checkValue = config.value
						}
					} else {
						this.checkValue = config.value
					}
				}

				if (config.groupfolder_id) {
					this.selectedGroupfolderId = config.groupfolder_id
				}
			} catch (e) {
				console.warn('Failed to parse existing check config:', e)
			} finally {
				this.$nextTick(() => {
					this.isParsingConfig = false
				})
			}
		},

		onFieldChange() {
			if (!this.isParsingConfig) {
				this.checkValue = ''
				this.selectedMultipleValues = []
				// Reset operator to first available for this field type
				const operators = this.getOperatorsForFieldType(this.selectedField?.type)
				this.selectedOperator = operators.length > 0 ? operators[0].operator : 'is'
			}
			this.updateValue()
		},

		onOperatorChange() {
			// Clear value when switching to/from operators that don't need a value
			const noValueOps = ['empty', '!empty']
			if (noValueOps.includes(this.selectedOperator)) {
				this.checkValue = ''
				this.selectedMultipleValues = []
			}
			this.updateValue()
		},

		onMultiSelectChange() {
			this.checkValue = JSON.stringify(this.selectedMultipleValues)
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

		t(app, text, vars) {
			if (typeof window.t === 'function') {
				return window.t(app, text, vars)
			}
			if (vars) {
				return Object.keys(vars).reduce((result, key) => {
					return result.replace(`{${key}}`, vars[key])
				}, text)
			}
			return text
		},
	},

	watch: {
		value: {
			immediate: false,
			handler(newValue) {
				// Only parse if value changed externally (not from our own updateValue)
				// and fields are already loaded
				if (newValue && this.fields.length > 0 && !this.isParsingConfig) {
					this.parseExistingConfig()
				}
			},
		},
	},
}
</script>

<style scoped>
.metavox-check {
	padding: 8px 0;
	min-width: 300px;
	max-width: 350px;
}

.check-field {
	margin-bottom: 12px;
}

.check-field label {
	display: block;
	font-weight: 600;
	margin-bottom: 4px;
	font-size: 13px;
	color: var(--color-main-text);
}

.check-field .hint {
	font-size: 12px;
	color: var(--color-text-lighter);
	margin: 4px 0 0 0;
}

/* Nextcloud-style select input */
.nc-select-input {
	width: 100%;
	min-height: 34px;
	padding: 6px 28px 6px 12px;
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 14px;
	cursor: pointer;
	appearance: none;
	background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24'%3E%3Cpath fill='%23969696' d='M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z'/%3E%3C/svg%3E");
	background-repeat: no-repeat;
	background-position: right 8px center;
	background-size: 16px;
	box-sizing: border-box;
}

.nc-select-input:hover {
	border-color: var(--color-primary-element);
}

.nc-select-input:focus {
	border-color: var(--color-primary-element);
	outline: none;
	box-shadow: 0 0 0 2px var(--color-primary-element-light);
}

.nc-select-input option:disabled {
	color: var(--color-text-lighter);
	font-weight: 600;
}

.nc-select-multiple {
	min-height: 80px;
	padding: 6px 12px;
	background-image: none;
}

/* Nextcloud-style input field */
.nc-input-field {
	width: 100%;
	min-height: 34px;
	padding: 6px 12px;
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 14px;
	box-sizing: border-box;
}

.nc-input-field:hover {
	border-color: var(--color-primary-element);
}

.nc-input-field:focus {
	border-color: var(--color-primary-element);
	outline: none;
	box-shadow: 0 0 0 2px var(--color-primary-element-light);
}

.nc-input-field::placeholder {
	color: var(--color-text-lighter);
}

.check-preview {
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 8px 12px;
	margin-top: 12px;
}

.preview-label {
	font-size: 11px;
	color: var(--color-text-lighter);
	display: block;
	margin-bottom: 4px;
}

.check-preview code {
	font-family: monospace;
	font-size: 13px;
	color: var(--color-main-text);
}

.field-group-header {
	font-weight: 600;
	font-size: 11px;
	color: var(--color-text-lighter);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.loading-indicator {
	font-size: 12px;
	color: var(--color-text-lighter);
	margin-left: 8px;
}
</style>
