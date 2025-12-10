<template>
	<div class="metadata-form">
		<div
			v-for="field in fields"
			:key="field.id"
			class="field-container">
			<label
				:for="`field-${field.id}`"
				class="field-label">
				{{ field.field_label }}
				<span v-if="field.is_required" class="required-indicator">*</span>
			</label>

			<p v-if="field.field_description" class="field-description">
				{{ field.field_description }}
			</p>

			<!-- Text Field -->
			<NcTextField
				v-if="field.field_type === 'text'"
				:id="`field-${field.id}`"
				:value="values[field.field_name] || ''"
				:disabled="readonly"
				:placeholder="field.field_label"
				@update:value="handleUpdate(field.field_name, $event)" />

			<!-- Number Field -->
			<NcTextField
				v-else-if="field.field_type === 'number'"
				:id="`field-${field.id}`"
				:value.number="values[field.field_name] || ''"
				:disabled="readonly"
				:placeholder="field.field_label"
				type="number"
				@update:value="handleNumberUpdate(field.field_name, $event)" />

			<!-- Textarea Field -->
			<textarea
				v-else-if="field.field_type === 'textarea'"
				:id="`field-${field.id}`"
				:value="values[field.field_name] || ''"
				:disabled="readonly"
				:placeholder="field.field_label"
				class="textarea-field"
				rows="6"
				@input="handleUpdate(field.field_name, $event.target.value)" />

			<!-- Date Field -->
			<input
				v-else-if="field.field_type === 'date'"
				:id="`field-${field.id}`"
				type="date"
				:value="values[field.field_name] || ''"
				:disabled="readonly"
				:required="field.is_required"
				class="date-input"
				@input="handleUpdate(field.field_name, $event.target.value)" />

			<!-- Select Field -->
			<NcSelect
				v-else-if="field.field_type === 'select'"
				:id="`field-${field.id}`"
				:key="`select-${field.field_name}-${selectKey}`"
				v-model="selectValues[field.field_name]"
				:options="getFieldOptions(field)"
				:disabled="readonly"
				:placeholder="field.field_label"
				:reduce="option => option.value"
				label="label"
				@input="handleSelectChange(field.field_name, $event)" />

			<!-- Multi-Select Field -->
			<NcSelect
				v-else-if="field.field_type === 'multiselect' || field.field_type === 'multi_select'"
				:id="`field-${field.id}`"
				:key="`multiselect-${field.field_name}-${selectKey}`"
				v-model="multiSelectValues[field.field_name]"
				:options="getFieldOptions(field)"
				:disabled="readonly"
				:multiple="true"
				:placeholder="field.field_label"
				:reduce="option => option.value"
				label="label"
				@input="handleMultiSelectChange(field.field_name, $event)" />

			<!-- Checkbox Field -->
			<NcCheckboxRadioSwitch
				v-else-if="field.field_type === 'checkbox'"
				:id="`field-${field.id}`"
				:checked="values[field.field_name] === '1' || values[field.field_name] === true"
				:disabled="readonly"
				@update:checked="handleCheckboxUpdate(field.field_name, $event)">
				{{ field.field_label }}
			</NcCheckboxRadioSwitch>

			<!-- Fallback for unknown types -->
			<NcTextField
				v-else
				:id="`field-${field.id}`"
				:value="values[field.field_name] || ''"
				:disabled="readonly"
				:placeholder="`Unknown field type: ${field.field_type}`"
				@update:value="handleUpdate(field.field_name, $event)" />
		</div>
	</div>
</template>

<script>
import {
	NcTextField,
	NcSelect,
	NcCheckboxRadioSwitch,
} from '@nextcloud/vue'

export default {
	name: 'MetadataForm',

	components: {
		NcTextField,
		NcSelect,
		NcCheckboxRadioSwitch,
	},

	props: {
		fields: {
			type: Array,
			required: true,
		},
		values: {
			type: Object,
			default: () => ({}),
		},
		readonly: {
			type: Boolean,
			default: false,
		},
		selectValues: {
			type: Object,
			default: () => ({}),
		},
		multiSelectValues: {
			type: Object,
			default: () => ({}),
		},
		selectKey: {
			type: Number,
			default: 0,
		},
	},

	emits: ['update'],

	methods: {
		handleUpdate(fieldName, value) {
			this.$emit('update', fieldName, value)
		},

		handleNumberUpdate(fieldName, value) {
			// Convert to number or empty string
			const numberValue = value !== '' && value !== null ? String(value) : ''
			this.$emit('update', fieldName, numberValue)
		},

		handleCheckboxUpdate(fieldName, checked) {
			this.$emit('update', fieldName, checked ? '1' : '0')
		},

		handleSelectChange(fieldName, value) {
			// Single select change handler
			this.$emit('update', fieldName, value || '')
		},

		handleMultiSelectChange(fieldName, values) {
			// Multi-select change handler (join with ;#)
			const joinedValue = Array.isArray(values) ? values.join(';#') : ''
			this.$emit('update', fieldName, joinedValue)
		},

		getFieldOptions(field) {
			let options = field.field_options || []

			// Handle string format (newline-separated)
			if (typeof options === 'string') {
				options = options.split('\n').filter((opt) => opt.trim() !== '')
			}

			// Ensure array
			if (!Array.isArray(options)) {
				options = []
			}

			// Convert to NcSelect format: { value, label }
			return options.map((option) => {
				if (typeof option === 'string') {
					return { value: option.trim(), label: option.trim() }
				}
				return {
					value: option.value || option.id || option,
					label: option.label || option.value || option.id || option,
				}
			})
		},

		getSelectValue(fieldName, isMultiple) {
			const rawValue = this.values[fieldName]

			if (!rawValue) {
				return isMultiple ? [] : null
			}

			if (isMultiple) {
				// Multi-select: parse JSON array
				try {
					const values = typeof rawValue === 'string' ? JSON.parse(rawValue) : rawValue
					return Array.isArray(values)
						? values.map((v) => ({ id: v, label: v }))
						: []
				} catch (e) {
					return []
				}
			} else {
				// Single select: return as object
				return { id: rawValue, label: rawValue }
			}
		},
	},
}
</script>

<style scoped>
.metadata-form {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.field-container {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.field-label {
	font-weight: 600;
	font-size: 14px;
	color: var(--color-main-text);
}

.required-indicator {
	color: var(--color-error);
	margin-left: 4px;
}

.field-description {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

/* Date input styling */
.date-input {
	width: 100%;
	padding: 8px 12px;
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	font-family: var(--font-face);
	font-size: 14px;
	color: var(--color-main-text);
	background-color: var(--color-main-background);
	transition: border-color 0.2s ease;
}

.date-input:hover:not(:disabled) {
	border-color: var(--color-primary-element);
}

.date-input:focus {
	outline: none;
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 2px var(--color-primary-element-light);
}

.date-input:disabled {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	cursor: not-allowed;
	opacity: 0.7;
}

/* Textarea styling */
.textarea-field {
	width: 100%;
	min-height: 120px;
	padding: 8px 12px;
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	font-family: var(--font-face);
	font-size: 14px;
	color: var(--color-main-text);
	background-color: var(--color-main-background);
	resize: vertical;
	transition: border-color 0.2s ease;
}

.textarea-field:hover:not(:disabled) {
	border-color: var(--color-primary-element);
}

.textarea-field:focus {
	outline: none;
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 2px var(--color-primary-element-light);
}

.textarea-field:disabled {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	cursor: not-allowed;
	opacity: 0.7;
}

.textarea-field::placeholder {
	color: var(--color-text-maxcontrast);
}

/* Add hover effect to Nextcloud Vue components */
/* NcTextField hover effect */
.field-container :deep(.input-field__input),
.field-container :deep(input[type="text"]),
.field-container :deep(input[type="number"]),
.field-container :deep(textarea) {
	transition: border-color 0.2s ease;
}

.field-container :deep(.input-field__input:hover:not(:disabled)),
.field-container :deep(input[type="text"]:hover:not(:disabled)),
.field-container :deep(input[type="number"]:hover:not(:disabled)),
.field-container :deep(textarea:hover:not(:disabled)) {
	border-color: var(--color-primary-element) !important;
}

/* NcSelect hover effect */
.field-container :deep(.vs__dropdown-toggle) {
	transition: border-color 0.2s ease;
}

.field-container :deep(.vs__dropdown-toggle:hover) {
	border-color: var(--color-primary-element) !important;
}

/* NcCheckboxRadioSwitch - subtle hover on label */
.field-container :deep(.checkbox-radio-switch__label) {
	transition: color 0.2s ease;
}

.field-container :deep(.checkbox-radio-switch:hover .checkbox-radio-switch__label) {
	color: var(--color-primary-element);
}
</style>
