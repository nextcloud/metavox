<template>
	<div class="metavox-check">
		<div class="check-field">
			<!-- TRANSLATORS Noun: label of the dropdown that picks the metadata field the Flow rule checks -->
			<label :for="ids.field">{{ t('metavox', 'Metadata field') }}</label>
			<select
				:id="ids.field"
				v-model="selectedFieldName"
				class="nc-select-input"
				@change="onFieldChange"
			>
				<!-- TRANSLATORS Placeholder entry of the field dropdown in the Flow rule -->
				<option value="" disabled>{{ t('metavox', 'Select a metadata field') }}</option>
				<!-- optgroup, not disabled options drawn as headers: it names the
				     group for a screen reader and needs no made-up separators. -->
				<optgroup v-for="group in fieldGroups" :key="group.label" :label="group.label">
					<option v-for="field in group.fields" :key="field.name" :value="field.name">
						{{ field.label }}
					</option>
				</optgroup>
			</select>
			<!-- TRANSLATORS Shown next to the field dropdown while the fields load -->
			<span v-if="loadingFields" class="loading-indicator">{{ t('metavox', 'Loading …') }}</span>
		</div>

		<!-- Operator selection based on field type -->
		<div v-if="selectedField" class="check-field">
			<!-- TRANSLATORS Noun: label of the dropdown that picks how the field is compared, e.g. equals, contains -->
			<label :for="ids.operator">{{ t('metavox', 'Operator') }}</label>
			<select
				:id="ids.operator"
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
			v-if="selectedField && showValueInput"
			:key="'value-' + selectedFieldName + '-' + (selectedField.type || 'text') + '-' + selectedOperator"
			class="check-field"
		>
			<!-- TRANSLATORS Label of the input for the value the field is compared with in the Flow rule -->
			<label :for="ids.value">{{ t('metavox', 'Value to check') }}</label>

			<!-- Checkbox/Boolean field - Yes/No dropdown -->
			<select
				:id="ids.value"
				v-if="isCheckboxField"
				v-model="checkValue"
				class="nc-select-input"
				@change="updateValue"
			>
				<!-- TRANSLATORS Placeholder entry of the value dropdown in the Flow rule -->
				<option value="" disabled>{{ t('metavox', 'Select a value') }}</option>
				<!-- TRANSLATORS Value choice for a checkbox field: the box is ticked -->
				<option value="1">{{ t('metavox', 'Yes (checked)') }}</option>
				<!-- TRANSLATORS Value choice for a checkbox field: the box is not ticked -->
				<option value="0">{{ t('metavox', 'No (unchecked)') }}</option>
			</select>

			<!-- Select/Dropdown field with oneOf support (multi-select) -->
			<select
				:id="ids.value"
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
				:id="ids.value"
				v-else-if="isSelectField"
				v-model="checkValue"
				class="nc-select-input"
				@change="updateValue"
			>
				<!-- TRANSLATORS Placeholder entry of the value dropdown in the Flow rule -->
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
				:id="ids.value"
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

			<!-- Date field (optionally datetime-local) -->
			<input
				:id="ids.value"
				v-else-if="selectedField.type === 'date'"
				v-model="checkValue"
				:type="dateFieldIncludesTime(selectedField) ? 'datetime-local' : 'date'"
				:step="dateFieldIncludesTime(selectedField) ? 1 : undefined"
				class="nc-input-field"
				@change="onDateChange"
			>

			<!-- Number field -->
			<!-- TRANSLATORS Placeholder of the value box for a number field in the Flow rule -->
			<input :placeholder="t('metavox', 'Enter a number')"
				:id="ids.value"
				v-else-if="selectedField.type === 'number'"
				v-model="checkValue"
				type="number"
				class="nc-input-field"
				@input="updateValue"
			>

			<!-- Default: text input -->
			<!-- TRANSLATORS Placeholder of the value box for a text field in the Flow rule -->
			<input :placeholder="t('metavox', 'Enter expected value')"
				:id="ids.value"
				v-else
				v-model="checkValue"
				type="text"
				class="nc-input-field"
				@input="updateValue"
			>
		</div>

		<div v-if="selectedField" class="check-field">
			<!-- TRANSLATORS Label of the dropdown that limits the Flow rule to one Team folder; it may stay empty -->
			<label :for="ids.folder">{{ t('metavox', 'Team folder (optional)') }}</label>
			<select
				:id="ids.folder"
				v-model="selectedGroupfolderId"
				class="nc-select-input"
				@change="updateValue"
			>
				<!-- TRANSLATORS Entry of the Team folder dropdown: the Team folder is found from where the file is -->
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
			<!-- TRANSLATORS Label before a preview of the Flow check, e.g. Status equals "Approved" -->
			<span class="preview-label">{{ t('metavox', 'Check configuration:') }}</span>
			<code>{{ previewText }}</code>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { dateFieldIncludesTime, padDatetimeLocal } from '../utils/dateField.js'
import { optionItems } from '../utils/fieldOptions.js'
import { OPERAND_KINDS, flowOperatorsFor } from '../utils/valueOperators.js'
import { formatFieldValue, PLAIN_TEXT } from '../utils/fieldValue.js'

// The operators per field type are the highlight rules' (valueOperators.js),
// which MetadataCheck.php evaluates the same way.
const needsNoValue = (operator) => OPERAND_KINDS[operator] === 'none'

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
	// TRANSLATORS Flow check operator for a checkbox field, read as "<field> is checked"
	case 'isTrue': return t('metavox', 'is checked')
	// TRANSLATORS Flow check operator for a checkbox field, read as "<field> is not checked"
	case 'isFalse': return t('metavox', 'is not checked')
	default: return operator
	}
}

// Ids for the label/control pairs; a rule can hold more than one check.
let instanceCounter = 0

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
		const n = ++instanceCounter
		return {
			ids: { field: `mv-flow-field-${n}`, operator: `mv-flow-operator-${n}`, value: `mv-flow-value-${n}`, folder: `mv-flow-folder-${n}` },
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
		// Read the way the grid and the sidebar read a value.
		displayValue() {
			// Several values (is one of, contains all) are kept as a JSON list.
			if (String(this.checkValue).startsWith('[')) {
				try {
					return JSON.parse(this.checkValue).join(', ')
				} catch {
					// a plain value after all
				}
			}
			const field = this.selectedField
			return formatFieldValue(this.checkValue, field?.type, { field_options: field?.options }) || this.checkValue
		},
		availableOperators() {
			return this.getOperatorsForFieldType(this.selectedField?.type)
		},
		operatorLabel() {
			return operatorName(this.selectedOperator)
		},
		// The rule read as a sentence, e.g. Status equals "Approved".
		previewText() {
			const vars = { field: this.selectedField.label, operator: this.operatorLabel, value: this.displayValue }
			return this.showValueInput
				// TRANSLATORS: Preview of a Flow check, e.g. Status equals "Approved".
				? t('metavox', '{field} {operator} "{value}"', vars, undefined, PLAIN_TEXT)
				// TRANSLATORS: Preview of a Flow check without a value, e.g. Status is empty.
				: t('metavox', '{field} {operator}', vars, undefined, PLAIN_TEXT)
		},
		showValueInput() {
			return !this.selectedOperator || !needsNoValue(this.selectedOperator)
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
			return optionItems(this.selectedField?.options)
		},
		fieldGroups() {
			return [
				// TRANSLATORS Group heading in the field dropdown: fields set per file
				{ label: this.t('metavox', 'File metadata fields'), fields: this.fields.filter(f => !f.appliesToGroupfolder) },
				// TRANSLATORS Group heading in the field dropdown: fields set once for the whole Team folder
				{ label: this.t('metavox', 'Team folder metadata fields'), fields: this.fields.filter(f => f.appliesToGroupfolder) },
			].filter(group => group.fields.length > 0)
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
		t: translate,

		dateFieldIncludesTime,

		onDateChange() {
			if (this.selectedField && dateFieldIncludesTime(this.selectedField)) {
				this.checkValue = padDatetimeLocal(this.checkValue)
			}
			this.updateValue()
		},

		getOperatorsForFieldType(fieldType) {
			const operators = flowOperatorsFor(fieldType)
			// A rule saved with an operator the type no longer lists keeps showing it.
			const kept = this.selectedField?.type === fieldType && this.selectedOperator && !operators.includes(this.selectedOperator)
				? [this.selectedOperator]
				: []
			return [...operators, ...kept].map(operator => ({ operator, name: operatorName(operator) }))
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
					appliesToGroupfolder: f.applies_to_groupfolder === true || Number(f.applies_to_groupfolder) === 1,
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
					label: gf.mount_point || gf.label
						// TRANSLATORS Name of a Team folder that has no name; {id} is its number, e.g. "Team folder 4"
						|| t('metavox', 'Team folder {id}', { id: gf.id }, undefined, PLAIN_TEXT),
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

				const field = this.fields.find(f => f.name === config.field_name)
				if (config.operator) {
					// "contains" on a multi-select was "has one of these"; it is called that now.
					this.selectedOperator = config.operator === 'contains' && field?.type === 'multiselect' ? 'oneOf' : config.operator
				} else {
					// Default to first available operator for field type
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
			if (needsNoValue(this.selectedOperator)) {
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
	font-size: var(--font-size-small);
	color: var(--color-text-maxcontrast);
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
	color: var(--color-text-maxcontrast);
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
	color: var(--color-text-maxcontrast);
}

.check-preview {
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 8px 12px;
	margin-top: 12px;
}

.preview-label {
	font-size: var(--font-size-small);
	color: var(--color-text-maxcontrast);
	display: block;
	margin-bottom: 4px;
}

.check-preview code {
	font-family: monospace;
	font-size: 13px;
	color: var(--color-main-text);
}

.loading-indicator {
	font-size: var(--font-size-small);
	color: var(--color-text-maxcontrast);
	margin-inline-start: 8px;
}
</style>
