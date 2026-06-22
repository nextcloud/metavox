<template>
  <div class="default-value-input" :class="`dv--${field.field_type}`">
    <label v-if="field.field_type !== 'checkbox'" :for="inputId" class="default-value-label">
      {{ t('metavox', 'Default value') }}
    </label>

    <!-- Select -->
    <NcSelect
      v-if="field.field_type === 'select'"
      :id="inputId"
      :model-value="modelValue"
      :options="options"
      :placeholder="t('metavox', 'Choose an option...')"
      class="field-input select-field"
      :clearable="true"
      :reduce="option => option.value"
      label="label"
      @update:model-value="emitValue($event)" />

    <!-- MultiSelect -->
    <NcSelect
      v-else-if="field.field_type === 'multiselect'"
      :id="inputId"
      :model-value="multiSelectValue"
      :options="options"
      :multiple="true"
      :placeholder="t('metavox', 'Choose options...')"
      class="field-input select-field"
      :clearable="true"
      :reduce="option => option.value"
      label="label"
      @update:model-value="emitMultiSelect($event)" />

    <!-- Textarea -->
    <textarea
      v-else-if="field.field_type === 'textarea'"
      :id="inputId"
      :value="modelValue"
      :placeholder="field.field_label"
      class="field-input textarea-input"
      rows="2"
      @input="emitValue($event.target.value)"></textarea>

    <!-- Date (optionally datetime-local) -->
    <input
      v-else-if="field.field_type === 'date'"
      :id="inputId"
      :type="includesTime ? 'datetime-local' : 'date'"
      :step="includesTime ? 1 : undefined"
      :value="modelValue"
      class="field-input date-input"
      @input="emitValue(includesTime ? padDatetimeLocal($event.target.value) : $event.target.value)" />

    <!-- Number -->
    <input
      v-else-if="field.field_type === 'number'"
      :id="inputId"
      type="number"
      :value="modelValue"
      class="field-input number-input"
      @input="emitValue($event.target.value)" />

    <!-- Checkbox -->
    <NcCheckboxRadioSwitch
      v-else-if="field.field_type === 'checkbox'"
      :id="inputId"
      :model-value="checkboxChecked"
      type="checkbox"
      @update:model-value="emitValue($event ? '1' : '0')">
      {{ t('metavox', 'Default value') }}
    </NcCheckboxRadioSwitch>

    <!-- URL -->
    <UrlFieldInput
      v-else-if="field.field_type === 'url'"
      :model-value="modelValue"
      :field="field"
      :input-id="inputId"
      class="field-input"
      @input="emitValue($event)" />

    <!-- User -->
    <UserGroupFieldInput
      v-else-if="field.field_type === 'user'"
      :model-value="modelValue"
      :field="field"
      :input-id="inputId"
      class="field-input"
      @input="emitValue($event)" />

    <!-- File link (one or more files) -->
    <FileLinkFieldInput
      v-else-if="field.field_type === 'filelink'"
      :model-value="modelValue"
      :field="field"
      :input-id="inputId"
      class="field-input"
      @input="emitValue($event)" />

    <!-- Text / fallback -->
    <NcTextField
      v-else
      :id="inputId"
      :model-value="modelValue"
      :label="t('metavox', 'Default value')"
      :placeholder="field.field_label"
      class="field-input"
      @update:model-value="emitValue($event)" />
  </div>
</template>

<script>
import { NcTextField, NcSelect, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { dateFieldIncludesTime, padDatetimeLocal } from '../../utils/dateField.js'
import UrlFieldInput from './UrlFieldInput.vue'
import UserGroupFieldInput from './UserGroupFieldInput.vue'
import FileLinkFieldInput from './FileLinkFieldInput.vue'

/**
 * Type-correct input for a per-folder DEFAULT value of a metadata field.
 * Self-contained: takes the field definition + current value, emits the new
 * value as a string (multiselect joined with ';#', checkbox as '0'/'1'). Shared
 * by the admin (ManageGroupfolders) and personal (MetaVoxPersonal) settings so
 * both stay in sync. Width is matched to the type via the `dv--<type>` class.
 */
export default {
  name: 'DefaultValueInput',
  components: {
    NcTextField,
    NcSelect,
    NcCheckboxRadioSwitch,
    UrlFieldInput,
    UserGroupFieldInput,
    FileLinkFieldInput,
  },
  props: {
    field: {
      type: Object,
      required: true,
    },
    modelValue: {
      type: String,
      default: '',
    },
    inputId: {
      type: String,
      default: '',
    },
  },
  emits: ['update:modelValue'],
  computed: {
    options() {
      const raw = this.field.field_options
      let list = []
      if (typeof raw === 'string') {
        list = raw.split('\n').filter(o => o.trim())
      } else if (Array.isArray(raw)) {
        list = raw.filter(o => o && String(o).trim())
      }
      return list.map(o => ({ label: String(o).trim(), value: String(o).trim() }))
    },
    multiSelectValue() {
      if (!this.modelValue) return []
      return String(this.modelValue).split(';#').filter(v => v.trim())
    },
    checkboxChecked() {
      const v = this.modelValue
      return v === '1' || v === 'true' || v === true || v === 1
    },
    includesTime() {
      return dateFieldIncludesTime(this.field)
    },
  },
  methods: {
    t,
    padDatetimeLocal,
    emitValue(value) {
      this.$emit('update:modelValue', value ?? '')
    },
    emitMultiSelect(values) {
      this.$emit('update:modelValue', Array.isArray(values) ? values.join(';#') : '')
    },
  },
}
</script>

<style scoped>
.default-value-input {
  margin-top: 8px;
  padding: 10px 12px;
  background: var(--color-background-hover);
  border-radius: var(--border-radius);
}

.default-value-label {
  display: block;
  font-size: 12px;
  color: var(--color-text-maxcontrast);
  margin-bottom: 4px;
}

.field-input {
  width: 100%;
  max-width: 480px;
  min-height: 44px;
}

.textarea-input,
.date-input,
.number-input {
  width: 100%;
  padding: 8px 12px;
  border: 2px solid var(--color-border);
  border-radius: var(--border-radius);
  background: var(--color-main-background);
  color: var(--color-text);
  font-size: 14px;
  font-family: var(--font-face);
  transition: border-color 0.2s ease;
  resize: vertical;
}

.textarea-input:focus,
.date-input:focus,
.number-input:focus {
  border-color: var(--color-primary);
  outline: none;
  box-shadow: 0 0 0 2px var(--color-primary-light);
}

/* Width matched to the field type so a year/date isn't absurdly wide. */
.dv--number .field-input,
.dv--number .number-input { max-width: 120px; }

.dv--date .field-input,
.dv--date .date-input { max-width: 220px; }

.dv--select .field-input,
.dv--multiselect .field-input,
.dv--user .field-input { max-width: 320px; }

.dv--text .field-input,
.dv--url .field-input,
.dv--filelink .field-input { max-width: 480px; }

.dv--textarea .field-input,
.dv--textarea .textarea-input { max-width: 100%; }

.dv--checkbox .field-input { max-width: none; min-height: 0; }
</style>
