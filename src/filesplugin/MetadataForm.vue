<template>
	<div class="metadata-form">
		<div
			v-for="field in fields"
			:key="field.id"
			class="field-container">
			<component
				:is="isSupported(field) ? 'label' : 'span'"
				v-if="!isSelfLabelling(field)"
				:id="`field-${field.id}-label`"
				:for="isSupported(field) ? `field-${field.id}` : undefined"
				class="field-label">
				{{ field.field_label }}
				<span v-if="isRequired(field)" class="field-label__required" aria-hidden="true">*</span>
				<!-- TRANSLATORS Screen-reader text after a field label, read instead of the asterisk that marks a required field -->
				<span v-if="isRequired(field)" class="hidden-visually">{{ t('metavox', '(required)') }}</span>
			</component>

			<!-- Text Field -->
			<NcTextField
				v-if="field.field_type === 'text'"
				:id="`field-${field.id}`"
				:model-value="values[field.field_name] || ''"
				:disabled="readonly"
				:label="field.field_label"
				label-outside
				:aria-describedby="describedBy(field)"
				@update:model-value="handleUpdate(field.field_name, $event)" />

			<!-- Number Field -->
			<NcTextField
				v-else-if="field.field_type === 'number'"
				:id="`field-${field.id}`"
				:model-value="values[field.field_name] || ''"
				:disabled="readonly"
				:label="field.field_label"
				label-outside
				type="number"
				:aria-describedby="describedBy(field)"
				@update:model-value="handleNumberUpdate(field.field_name, $event)" />

			<!-- Textarea Field -->
			<NcTextArea
				v-else-if="field.field_type === 'textarea'"
				:id="`field-${field.id}`"
				:model-value="values[field.field_name] || ''"
				:disabled="readonly"
				:label="field.field_label"
				label-outside
				resize="vertical"
				:aria-describedby="describedBy(field)"
				@update:model-value="handleUpdate(field.field_name, $event)" />

			<!-- Date Field (optionally datetime-local) -->
			<NcDateTimePickerNative
				v-else-if="field.field_type === 'date'"
				:id="`field-${field.id}`"
				:model-value="toDate(values[field.field_name])"
				:disabled="readonly"
				:type="dateFieldIncludesTime(field) ? 'datetime-local' : 'date'"
				:label="field.field_label"
				hide-label
				:aria-describedby="describedBy(field)"
				@update:model-value="handleDateUpdate(field, $event)" />

			<!-- Select Field -->
			<!-- TRANSLATORS Placeholder of a dropdown field where one option can be chosen -->
			<NcSelect :placeholder="t('metavox', 'Choose an option')"
				v-else-if="field.field_type === 'select'"
				:key="`select-${field.field_name}-${selectKey}`"
				v-model="selectValues[field.field_name]"
				:options="getFieldOptions(field)"
				:disabled="readonly"
				label-outside
				:aria-label-combobox="field.field_label"
				:input-id="`field-${field.id}`"
				:reduce="option => option.value"
				label="label"
				:aria-describedby="describedBy(field)"
				@update:model-value="handleSelectChange(field.field_name, $event)" />

			<!-- Multi-Select Field -->
			<!-- TRANSLATORS Placeholder of a dropdown field where several options can be chosen -->
			<NcSelect :placeholder="t('metavox', 'Choose one or more options')"
				v-else-if="field.field_type === 'multiselect' || field.field_type === 'multi_select'"
				:key="`multiselect-${field.field_name}-${selectKey}`"
				v-model="multiSelectValues[field.field_name]"
				:options="getFieldOptions(field)"
				:disabled="readonly"
				:multiple="true"
				label-outside
				:aria-label-combobox="field.field_label"
				:input-id="`field-${field.id}`"
				:reduce="option => option.value"
				label="label"
				:aria-describedby="describedBy(field)"
				@update:model-value="handleMultiSelectChange(field.field_name, $event)" />

			<!-- Checkbox Field -->
			<NcCheckboxRadioSwitch
				v-else-if="field.field_type === 'checkbox'"
				:id="`field-${field.id}`"
				:model-value="values[field.field_name] === '1' || values[field.field_name] === true"
				:disabled="readonly"
				:aria-describedby="describedBy(field)"
				@update:model-value="handleCheckboxUpdate(field.field_name, $event)">
				{{ field.field_label }}
				<span v-if="isRequired(field)" class="field-label__required" aria-hidden="true">*</span>
				<!-- TRANSLATORS Screen-reader text after a field label, read instead of the asterisk that marks a required field -->
				<span v-if="isRequired(field)" class="hidden-visually">{{ t('metavox', '(required)') }}</span>
			</NcCheckboxRadioSwitch>

			<!-- URL Field -->
			<UrlFieldInput
				v-else-if="field.field_type === 'url'"
				:model-value="values[field.field_name] || ''"
				:field="field"
				:disabled="readonly"
				:input-id="`field-${field.id}`"
				:aria-describedby="describedBy(field)"
				@input="handleUpdate(field.field_name, $event)" />

			<!-- User Field -->
			<UserGroupFieldInput
				v-else-if="field.field_type === 'user'"
				:model-value="values[field.field_name] || ''"
				:field="field"
				:field-label="field.field_label"
				:disabled="readonly"
				:input-id="`field-${field.id}`"
				:aria-describedby="describedBy(field)"
				@input="handleUpdate(field.field_name, $event)" />

			<!-- File Link Field (one or more files) -->
			<FileLinkFieldInput
				v-else-if="field.field_type === 'filelink'"
				:model-value="values[field.field_name] || ''"
				:field="field"
				:resolved="field.resolved || []"
				:base-path="fileLinkBasePath"
				:self-path="selfPath"
				:disabled="readonly"
				:input-id="`field-${field.id}`"
				:aria-describedby="describedBy(field)"
				@input="handleUpdate(field.field_name, $event)" />

			<!-- Fallback for unknown types. Read-only on purpose: an editable box
			     here would let a plain string be saved over whatever the real
			     field type holds, and this view cannot render that type well
			     enough to show what is being replaced. -->
			<div
				v-else
				role="group"
				:aria-labelledby="`field-${field.id}-label`"
				class="unsupported-field">
				<p class="unsupported-field__value">
					<!-- TRANSLATORS Adjective: shown as the value of a field that has no value -->
					{{ values[field.field_name] || t('metavox', 'Empty') }}
				</p>
				<p :id="`field-${field.id}-unsupported`" class="unsupported-field__note">
					{{ t('metavox', 'This value can only be changed by an administrator.') }}
				</p>
			</div>

			<!-- Helper text for the field types whose component has no
			     helper-text prop of its own. It sits after the control, where a
			     helper text belongs, so the label stays bound to its input. -->
			<!-- The dialog has room to keep the description in view, which is
			     what a helper text is for. It sits under the control so the
			     label stays bound to its own field. -->
			<p
				v-if="errors[field.field_name]"
				:id="`field-${field.id}-error`"
				class="field-error">
				{{ errors[field.field_name] }}
			</p>
			<p
				v-if="field.field_description"
				:id="`field-${field.id}-description`"
				class="field-description">
				{{ field.field_description }}
			</p>

			<!-- AI Suggestion -->
			<div v-if="aiSuggestions[field.field_name]" class="ai-suggestion">
				<div class="ai-suggestion__header">
					<CreationIcon :size="16" aria-hidden="true" />
					<!-- TRANSLATORS Label above a value that AI proposes for a field; AI = artificial intelligence -->
					<span>{{ t('metavox', 'AI suggests:') }}</span>
				</div>
				<div class="ai-suggestion__value">{{ formatSuggestion(field, aiSuggestions[field.field_name]) }}</div>
				<div class="ai-suggestion__actions">
					<NcButton
						variant="primary"
						size="small"
						:aria-label="acceptLabel(field)"
						:title="acceptLabel(field)"
						@click="$emit('accept-suggestion', field.field_name, aiSuggestions[field.field_name])">
						<template #icon>
							<CheckIcon :size="20" />
						</template>
					</NcButton>
					<NcButton
						variant="secondary"
						size="small"
						:aria-label="regenerateLabel(field)"
						:title="regenerateLabel(field)"
						:disabled="regeneratingField === field.field_name"
						@click="$emit('regenerate-suggestion', field.field_name)">
						<template #icon>
							<NcLoadingIcon v-if="regeneratingField === field.field_name" :size="20" />
							<RefreshIcon v-else :size="20" />
						</template>
					</NcButton>
					<NcButton
						variant="tertiary"
						size="small"
						:aria-label="dismissLabel(field)"
						:title="dismissLabel(field)"
						@click="$emit('dismiss-suggestion', field.field_name)">
						<template #icon>
							<CloseIcon :size="20" />
						</template>
					</NcButton>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { translate as t } from '@nextcloud/l10n'
import { PLAIN_TEXT } from '../utils/fieldValue.js'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import CreationIcon from 'vue-material-design-icons/Creation.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import UrlFieldInput from '../components/fields/UrlFieldInput.vue'
import UserGroupFieldInput from '../components/fields/UserGroupFieldInput.vue'
import FileLinkFieldInput from '../components/fields/FileLinkFieldInput.vue'
import { dateFieldIncludesTime, formatLocalDatetime, parseLocalDateValue } from '../utils/dateField.js'
import { formatFieldValue } from '../utils/fieldValue.js'
import { optionItems } from '../utils/fieldOptions.js'
import { isRequired } from '../utils/requiredFields.js'
import { getActiveFolderPath } from './columns/MetaVoxState.js'

export default {
	name: 'MetadataForm',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDateTimePickerNative,
		NcLoadingIcon,
		NcSelect,
		NcTextArea,
		NcTextField,
		CheckIcon,
		CloseIcon,
		CreationIcon,
		RefreshIcon,
		UrlFieldInput,
		UserGroupFieldInput,
		FileLinkFieldInput,
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
		aiSuggestions: {
			type: Object,
			default: () => ({}),
		},
		// Path of the file this form edits, so a File link cannot point at it.
		selfPath: {
			type: String,
			default: '',
		},
		regeneratingField: {
			type: String,
			default: null,
		},
		// Why a field was refused: field name => message, shown under the field.
		errors: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['update', 'accept-suggestion', 'dismiss-suggestion', 'regenerate-suggestion'],


	computed: {
		// Team-folder root path for File Link pickers: "/<mount_point>" of the
		// active groupfolder, so the picker opens there and links are constrained
		// to this folder. Empty when the folder can't be resolved (the server
		// still validates the boundary regardless).
		fileLinkBasePath() {
			return getActiveFolderPath()
		},
	},

	methods: {
		isRequired,
		t,
		dateFieldIncludesTime,

		// Each AI action button is icon-only, so its accessible name has to say
		// which field it acts on — three bare "Accept" buttons in one form are
		// indistinguishable in a screen reader's element list.
		acceptLabel(field) {
			// TRANSLATORS Accessible name and tooltip of the check-mark button that accepts the AI value; {field} is a field label, e.g. "Accept AI suggestion for Status"
			return t('metavox', 'Accept AI suggestion for {field}', { field: field.field_label }, undefined, PLAIN_TEXT)
		},

		regenerateLabel(field) {
			// TRANSLATORS Accessible name and tooltip of the button that asks AI for a new value; {field} is a field label, e.g. "Regenerate AI suggestion for Status"
			return t('metavox', 'Regenerate AI suggestion for {field}', { field: field.field_label }, undefined, PLAIN_TEXT)
		},

		dismissLabel(field) {
			// TRANSLATORS Accessible name and tooltip of the button that rejects the AI value; {field} is a field label, e.g. "Dismiss AI suggestion for Status"
			return t('metavox', 'Dismiss AI suggestion for {field}', { field: field.field_label }, undefined, PLAIN_TEXT)
		},

		// Field types whose component draws its own visible label beside the
		// control. Repeating it above would print the words twice and give the
		// input a second <label>, which is announced twice.
		isSelfLabelling(field) {
			return field.field_type === 'checkbox'
		},

		// The field types this view renders with a real control. Anything else
		// falls through to the read-only block, so the label must not claim to
		// label a control that is not there.
		isSupported(field) {
			return [
				'text', 'number', 'textarea', 'date', 'select', 'multiselect',
				'multi_select', 'checkbox', 'url', 'user', 'filelink',
			].includes(field.field_type)
		},

		// Types whose @nextcloud/vue component accepts helper-text and wires its
		// own aria-describedby. Everything else gets the paragraph above.
		describedBy(field) {
			const ids = [
				this.errors[field.field_name] ? `field-${field.id}-error` : null,
				field.field_description ? `field-${field.id}-description` : null,
			].filter(Boolean)
			return ids.length > 0 ? ids.join(' ') : undefined
		},

		/** Put the focus on the first field with a message. */
		focusFirstError() {
			const field = this.fields.find((f) => this.errors[f.field_name])
			const el = field && this.$el.querySelector(`#field-${field.id}`)
			const target = el?.matches('input, textarea, select') ? el : el?.querySelector('input, textarea, button')
			target?.focus()
		},

		// NcDateTimePickerNative models a Date; MetaVox stores a floating ISO
		// string. Both conversions stay local so a value never shifts by the
		// UTC offset on a round trip.
		toDate(value) {
			return parseLocalDateValue(value)
		},

		handleDateUpdate(field, date) {
			if (!date) {
				this.$emit('update', field.field_name, '')
				return
			}
			if (!dateFieldIncludesTime(field)) {
				this.$emit('update', field.field_name, formatLocalDatetime(date).slice(0, 10))
				return
			}
			// NcDateTimePickerNative formats to the minute — it has no `step` and
			// its formatter never reads getSeconds(). Re-picking a stored
			// 14:30:45 would silently save 14:30:00, so carry the original
			// seconds over unless the minute itself changed.
			const formatted = formatLocalDatetime(date)
			const previous = String(this.values[field.field_name] ?? '')
			const keptSeconds = previous.slice(0, 16) === formatted.slice(0, 16)
				? previous.slice(16, 19)
				: ''
			this.$emit('update', field.field_name, formatted.slice(0, 16) + (keptSeconds || ':00'))
		},

		formatSuggestion(field, value) {
			return formatFieldValue(value, field.field_type, field) || value
		},

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
			// Single select change handler - value is now extracted in template
			this.$emit('update', fieldName, value || '')
		},

		handleMultiSelectChange(fieldName, values) {
			// Multi-select change handler (join with ;#)
			const joinedValue = Array.isArray(values) ? values.join(';#') : ''
			this.$emit('update', fieldName, joinedValue)
		},

		getFieldOptions(field) {
			return optionItems(field.field_options)
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
/* No rule between fields — across all 92 component stylesheets the only
   per-sibling separator in @nextcloud/vue is on NcSettingsSection, i.e.
   between SECTIONS. Nextcloud's own Files sidebar (SharingDetailsTab) stacks a
   denser form than this one with no field separators at all.

   With the rules gone, proximity is the ONLY thing telling a reader which
   label belongs to which control, so the gap that binds must be clearly
   smaller than the gap that separates. NcFormGroup's own 8px is the gap
   between whole fields; its label-to-content distance is larger still (10px,
   2.5 x baseline) because there one label heads a whole group. Here each
   label heads a single control, so the relationship inverts: 4px inside the
   pair, 16px between pairs. Both are on the 4px grid; 2px is not. */
.metadata-form {
	display: flex;
	flex-direction: column;
	/* A field is three lines deep here (name, control, explanation), so the gap
	   between fields has to be clearly larger than the gaps inside one, or the
	   explanation of one field reads as the opening of the next. */
	gap: calc(6 * var(--default-grid-baseline));
}

.field-container {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

/* The label is supporting text for the control beneath it, so it takes the
   small size — matching the floated label inside NcInputField — rather than
   body size, which made every field read as a heading. */
/* Three layers stack up per field — name, control, explanation — and they were
   told apart by 100 units of weight alone: label and description were both 13px
   in --color-text-maxcontrast. The label names the field, which is content, so
   it takes the main text colour; the description stays the quieter layer. */
.field-label {
	font-weight: var(--font-weight-element, 500);
	font-size: var(--font-size-small);
	color: var(--color-main-text);
	padding-inline-start: calc(var(--border-radius-element) + var(--default-grid-baseline));
}

.field-label__required {
	color: var(--color-error-text);
	margin-inline-start: 4px;
}

/* Like the description, in the error colour: what is missing, under the field. */
.field-error {
	margin: var(--default-grid-baseline) 0 0 0;
	padding-inline-start: calc(var(--border-radius-element) + var(--default-grid-baseline));
	font-size: var(--font-size-small);
	line-height: 1.4;
	color: var(--color-error-text);
}

.hidden-visually {
	position: absolute;
	width: 1px;
	height: 1px;
	margin: -1px;
	padding: 0;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
	border: 0;
}

/* One row height for every control in the column. The library's components
   disagree out of the box — measured here: NcTextField and the date/number
   inputs at 30px, NcSelect at 34px, a multi-value NcSelect at 42px — which
   reads as ragged down a column of twelve even though each component is
   behaving correctly on its own. --default-clickable-area is the platform's
   row height, and a multi-value select is allowed to grow past it once it
   actually holds more than one chip. */
.field-container :deep(.input-field__main-wrapper),
.field-container :deep(.vs__dropdown-toggle),
.field-container :deep(.native-datetime-picker .input-field__main-wrapper) {
	min-height: var(--default-clickable-area);
}

.field-container :deep(.input-field__input),
.field-container :deep(.native-datetime-picker input) {
	min-height: var(--default-clickable-area);
}

/* A multi-value NcSelect adds padding-block-start to its toggle to make room
   for the floating label we do not use (label-outside), which stacked on top
   of the chips' own margins: 28px chip + 4px margins + 6px padding + 10px =
   48px against the 34px every other control uses. Centring the row instead
   keeps one chip on the same baseline as a text field; two or more still wrap
   and grow, which is the control doing its job. */
/* Upstream's rule is `.nc-select.v-select.select.vs--multiple
   .vs__dropdown-toggle`, four classes deep, so a plainer selector loses on
   specificity and the padding stays. Match it rather than reach for
   !important.

   This removes the label-reserve padding (48px -> 42px). The last 8px is the
   chips' own margins around a 28px chip, which is content: forcing the row to
   34px would clip them. A multi-value control is allowed to be taller than the
   row once it holds something. */
.field-container :deep(.nc-select.v-select.select.vs--multiple .vs__dropdown-toggle) {
	padding-block: 0;
	align-items: center;
}

.field-container :deep(.vs__selected-options) {
	padding: 0;
}

.field-container :deep(.vs__search) {
	margin: 0;
}

/* Upstream sets no font-size on the helper text, so it inherits body size and
   competes with the label it is meant to support (§6: supporting text is
   --font-size-small). */
.field-container :deep(.input-field__helper-text-message) {
	font-size: var(--font-size-small);
}

/* The label inset lives on the text elements, matching every other row in the
   panel; repeating it on the wrapper would indent this block past the rest. */
.unsupported-field {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.unsupported-field__value {
	margin: 0;
	padding-inline-start: calc(var(--border-radius-element) + var(--default-grid-baseline));
	font-size: var(--default-font-size);
	color: var(--color-main-text);
	overflow-wrap: anywhere;
}

.unsupported-field__note {
	margin: 0;
	padding-inline-start: calc(var(--border-radius-element) + var(--default-grid-baseline));
	font-size: var(--font-size-small);
	color: var(--color-text-maxcontrast);
}

.field-description {
	margin: var(--default-grid-baseline) 0 0 0;
	padding-inline-start: calc(var(--border-radius-element) + var(--default-grid-baseline));
	font-size: var(--font-size-small);
	font-weight: var(--font-weight-default, 400);
	color: var(--color-text-maxcontrast);
	line-height: 1.4;
}

/* AI Suggestion */
.ai-suggestion {
	margin-top: 8px;
	padding: 12px;
	background: var(--color-primary-element-light);
	border: 1px solid var(--color-primary-element);
	border-radius: var(--border-radius-element);
}

.ai-suggestion__header {
	display: flex;
	align-items: center;
	gap: 4px;
	font-size: var(--font-size-small);
	font-weight: var(--font-weight-element, 500);
	color: var(--color-primary-element);
	margin-bottom: 4px;
}

.ai-suggestion__value {
	font-size: var(--default-font-size);
	color: var(--color-main-text);
	margin-bottom: 8px;
	word-break: break-word;
}

.ai-suggestion__actions {
	display: flex;
	gap: 4px;
}

/* The date picker is a full-width form control like every other field here. */
.field-container :deep(.native-datetime-picker) {
	width: 100%;
}
</style>
