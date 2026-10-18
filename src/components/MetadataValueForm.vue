<template>
	<div class="metadata-form">
		<div
			v-for="field in fields"
			:key="field.id"
			class="field-item">

			<label :for="'field-' + field.id" class="field-label">
				{{ field.field_label }}
				<span v-if="isRequired(field)" class="required">*</span>
			</label>

			<!-- Text input -->
			<NcTextField
				v-if="field.field_type === 'text'"
				:id="'field-' + field.id"
				:model-value="getFieldValue(field)"
				@update:model-value="updateMetadataValue(field, $event)"
				:label="field.field_label"
				label-outside
				:required="isRequired(field)"
				class="field-input" />

			<!-- Textarea input -->
			<!-- Was a raw <textarea>: no focus ring, no border, none of the
					height the components beside it have. -->
			<NcTextArea
				v-else-if="field.field_type === 'textarea'"
				:id="'field-' + field.id"
				:model-value="getFieldValue(field)"
				:label="field.field_label"
				label-outside
				:required="isRequired(field)"
				:rows="4"
				class="field-input textarea-input"
				@update:model-value="updateMetadataValue(field, $event)" />

			<!-- Select input -->
			<!-- TRANSLATORS Placeholder of a dropdown field before a value is picked -->
			<NcSelect :placeholder="t('metavox', 'Choose an option …')"
				v-else-if="field.field_type === 'select'"
				:input-id="'field-' + field.id"
				:key="`select-${field.field_name}-${selectKey}`"
				:model-value="selectValues[field.field_name]"
				:options="getFieldOptions(field)"
				class="field-input select-field"
				:clearable="!isRequired(field)"
				:reduce="option => option.value"
				label="label"
				@update:model-value="handleSelectChange(field.field_name, $event)" />

			<!-- MultiSelect input -->
			<!-- TRANSLATORS Placeholder of a multi-select field before any value is picked -->
			<NcSelect :placeholder="t('metavox', 'Choose options …')"
				v-else-if="field.field_type === 'multiselect'"
				:input-id="'field-' + field.id"
				:key="`multiselect-${field.field_name}-${selectKey}`"
				:model-value="multiSelectValues[field.field_name]"
				:options="getFieldOptions(field)"
				:multiple="true"
				class="field-input select-field"
				:clearable="!isRequired(field)"
				:reduce="option => option.value"
				label="label"
				@update:model-value="handleMultiSelectChange(field.field_name, $event)" />

			<!-- Date input (optionally datetime-local) -->
			<NcTextField
				v-else-if="field.field_type === 'date'"
				:id="'field-' + field.id"
				:model-value="getFieldValue(field)"
				:label="field.field_label"
				label-outside
				:type="dateFieldIncludesTime(field) ? 'datetime-local' : 'date'"
				:step="dateFieldIncludesTime(field) ? 1 : undefined"
				:required="isRequired(field)"
				class="field-input date-input"
				@update:model-value="updateMetadataValue(field, dateFieldIncludesTime(field) ? padDatetimeLocal($event) : $event)" />

			<!-- Number input -->
			<NcTextField
				v-else-if="field.field_type === 'number'"
				:id="'field-' + field.id"
				:model-value="getFieldValue(field)"
				:label="field.field_label"
				label-outside
				type="number"
				:required="isRequired(field)"
				class="field-input number-input"
				@update:model-value="updateMetadataValue(field, $event)" />

			<!-- Checkbox input -->
			<NcCheckboxRadioSwitch
				v-else-if="field.field_type === 'checkbox'"
				:id="'field-' + field.id"
				:model-value="isCheckboxChecked(field)"
				@update:model-value="updateCheckboxValue(field, $event)"
				type="checkbox">
				{{ field.field_label }}
			</NcCheckboxRadioSwitch>

			<!-- URL, user and file link were falling through to the plain
			     text input below, because this form was extracted from two
			     pages that only ever showed the first seven types. The three
			     components already exist and are what the other two metadata
			     forms use for these types; a url field in particular loses
			     its type="url" — and with it the keyboard a phone shows and
			     the browser's own validation — when rendered as text. -->
			<UrlFieldInput
				v-else-if="field.field_type === 'url'"
				:input-id="'field-' + field.id"
				:model-value="getFieldValue(field)"
				:field="field"
				:required="isRequired(field)"
				class="field-input"
				@input="updateMetadataValue(field, $event)" />

			<UserGroupFieldInput
				v-else-if="field.field_type === 'user'"
				:input-id="'field-' + field.id"
				:model-value="getFieldValue(field)"
				:field="field"
				:required="isRequired(field)"
				class="field-input"
				@input="updateMetadataValue(field, $event)" />

			<!-- Stored as ids; the names come from the server, as the user sees the files. -->
			<FileLinkFieldInput
				v-else-if="field.field_type === 'filelink'"
				:input-id="'field-' + field.id"
				:model-value="getFieldValue(field)"
				:field="field"
				:resolved="field.resolved || []"
				:base-path="basePath"
				:required="isRequired(field)"
				class="field-input"
				@input="updateMetadataValue(field, $event)" />

			<!-- Default text input for unknown types -->
			<NcTextField
				v-else
				:id="'field-' + field.id"
				:model-value="getFieldValue(field)"
				@update:model-value="updateMetadataValue(field, $event)"
				:placeholder="field.field_label"
				:required="isRequired(field)"
				class="field-input" />

			<p v-if="errors[field.field_name]" class="field-error">
				{{ errors[field.field_name] }}
			</p>
			<div v-if="field.field_description" class="field-description">
				{{ field.field_description }}
			</div>
		</div>

	</div>
</template>

<script>
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import FileLinkFieldInput from './fields/FileLinkFieldInput.vue'
import UrlFieldInput from './fields/UrlFieldInput.vue'
import UserGroupFieldInput from './fields/UserGroupFieldInput.vue'
import { translate } from '@nextcloud/l10n'
import { dateFieldIncludesTime, padDatetimeLocal } from '../utils/dateField.js'
import { optionItems } from '../utils/fieldOptions.js'
import { isRequired } from '../utils/requiredFields.js'

/**
 * The per-folder metadata value form.
 *
 * Extracted from ManageGroupfolders and MetaVoxPersonal, which carried the
 * same 117 lines of template differing on four — two of those being
 * `v-model` where the other used `:model-value`, which is the kind of
 * divergence that produced the `split(';#'.filter(...))` bug: the correct
 * line existed in one twin and not the other.
 *
 * The parent owns the values and passes them in; this component reports
 * every change through update:modelValue and never mutates the prop.
 * Multiselect values are joined with ';#', which is the storage convention
 * and now lives in exactly one place.
 */
export default {
	name: 'MetadataValueForm',

	components: {
		FileLinkFieldInput,
		NcCheckboxRadioSwitch,
		NcSelect,
		NcTextArea,
		NcTextField,
		UrlFieldInput,
		UserGroupFieldInput,
	},

	props: {
		/** Field definitions to render, in order. */
		fields: {
			type: Array,
			required: true,
		},
		/** field_name -> stored string value. */
		modelValue: {
			type: Object,
			default: () => ({}),
		},
		/** Where the file picker starts, the team folder in the user's files. */
		basePath: {
			type: String,
			default: '',
		},
		/** Why a field was refused: field name => message, shown under the field. */
		errors: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['update:modelValue'],

	data() {
		return {
			selectValues: {},
			multiSelectValues: {},
		}
	},

	watch: {
		fields: {
			immediate: true,
			handler() { this.syncSelectState() },
		},
		modelValue: {
			immediate: true,
			handler() { this.syncSelectState() },
		},
	},

	methods: {
		isRequired,
		t: translate,
		dateFieldIncludesTime,
		padDatetimeLocal,

		/**
		 * NcSelect needs its own shape, so the select and multiselect
		 * values are mirrored out of the flat string map the parent holds.
		 */
		syncSelectState() {
			for (const field of this.fields) {
				const raw = this.modelValue[field.field_name]
				if (field.field_type === 'select') {
					this.selectValues[field.field_name] = raw || null
				} else if (field.field_type === 'multiselect') {
					this.multiSelectValues[field.field_name] = raw
						? String(raw).split(';#').filter(v => v.trim())
						: []
				}
			}
		},

		emitValue(fieldName, value) {
			this.$emit('update:modelValue', { ...this.modelValue, [fieldName]: value })
		},

		getFieldValue(field) {
			const value = this.modelValue[field.field_name]
			return value !== undefined ? value : ''
		},

		updateMetadataValue(field, value) {
			this.emitValue(field.field_name, value)
		},

		handleSelectChange(fieldName, value) {
			this.selectValues[fieldName] = value
			this.emitValue(fieldName, value || '')
		},

		handleMultiSelectChange(fieldName, values) {
			this.multiSelectValues[fieldName] = values || []
			this.emitValue(fieldName, Array.isArray(values) ? values.join(';#') : '')
		},

		updateCheckboxValue(field, checked) {
			this.updateMetadataValue(field, checked ? '1' : '0')
		},

		isCheckboxChecked(field) {
			const value = this.getFieldValue(field)
			return value === '1' || value === 'true' || value === true || value === 1
		},

		getFieldOptions(field) {
			return optionItems(field.field_options)
		},
	},
}
</script>

<style scoped>
.metadata-form {
	display: flex;
	flex-direction: column;
	/* NcAppSettingsSection's distance between groups (§6). */
	gap: 24px;
}

.field-item {
	display: flex;
	flex-direction: column;
	/* Binds a label to its control; must stay smaller than the gap above. */
	gap: 4px;
}

.field-label {
	font-weight: 500;
	color: var(--color-main-text);
	font-size: var(--font-size-small);
	display: block;
}

.required {
	color: var(--color-error-text);
	margin-inline-start: 2px;
}

.field-input {
	width: 100%;
	max-width: 480px;
}

.field-description {
	font-size: var(--font-size-small);
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
	line-height: 1.4;
}

.field-error {
	font-size: var(--font-size-small);
	color: var(--color-error-text);
	margin: 4px 0 0;
	line-height: 1.4;
}
</style>
