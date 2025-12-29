<template>
  <!-- Native textarea for textarea type -->
  <textarea
    v-if="type === 'textarea'"
    :id="inputId"
    :value="modelValue || ''"
    :disabled="disabled"
    :required="required"
    :placeholder="field.field_label"
    class="textarea-field"
    rows="6"
    @input="$emit('update:modelValue', $event.target.value)" />

  <!-- Native date input for date type -->
  <input
    v-else-if="type === 'date'"
    :id="inputId"
    type="date"
    :value="modelValue || ''"
    :disabled="disabled"
    :required="required"
    class="date-input"
    @input="$emit('update:modelValue', $event.target.value)" />

  <!-- URL field type -->
  <UrlFieldInput
    v-else-if="type === 'url'"
    :model-value="modelValue"
    :field="field"
    :required="required"
    :disabled="disabled"
    :input-id="inputId"
    @update:model-value="$emit('update:modelValue', $event)" />

  <!-- User picker field type -->
  <UserGroupFieldInput
    v-else-if="type === 'user'"
    :model-value="modelValue"
    :field="field"
    :required="required"
    :disabled="disabled"
    :input-id="inputId"
    @update:model-value="$emit('update:modelValue', $event)" />

  <!-- File/Folder link field type -->
  <FileLinkFieldInput
    v-else-if="type === 'file' || type === 'filelink'"
    :model-value="modelValue"
    :field="field"
    :required="required"
    :disabled="disabled"
    :input-id="inputId"
    @update:model-value="$emit('update:modelValue', $event)" />

  <!-- Dynamic Nextcloud components for other types -->
  <component
    v-else
    :is="fieldComponent"
    v-bind="fieldProps"
    v-on="fieldEvents">
    <template v-if="type === 'checkbox'">
      {{ field.field_label }}
    </template>
  </component>
</template>

<script>
import { NcTextField, NcSelect, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import UrlFieldInput from './UrlFieldInput.vue'
import UserGroupFieldInput from './UserGroupFieldInput.vue'
import FileLinkFieldInput from './FileLinkFieldInput.vue'

export default {
  name: 'DynamicFieldInput',
  components: {
    NcTextField,
    NcSelect,
    NcCheckboxRadioSwitch,
    UrlFieldInput,
    UserGroupFieldInput,
    FileLinkFieldInput
  },
  props: {
    type: {
      type: String,
      required: true,
      validator: (value) => ['text', 'textarea', 'number', 'date', 'select', 'multiselect', 'multi_select', 'checkbox', 'url', 'user', 'file', 'filelink'].includes(value)
    },
    modelValue: {
      type: [String, Number, Boolean, Array],
      default: ''
    },
    field: {
      type: Object,
      required: true
    },
    required: {
      type: Boolean,
      default: false
    },
    disabled: {
      type: Boolean,
      default: false
    },
    options: {
      type: Array,
      default: () => []
    },
    inputId: {
      type: String,
      default: ''
    }
  },
  emits: ['update:modelValue'],
  computed: {
    fieldComponent() {
      const componentMap = {
        text: 'NcTextField',
        number: 'NcTextField',
        select: 'NcSelect',
        multiselect: 'NcSelect',
        multi_select: 'NcSelect',
        checkbox: 'NcCheckboxRadioSwitch'
      }
      return componentMap[this.type] || 'NcTextField'
    },
    isMultiSelect() {
      return this.type === 'multiselect' || this.type === 'multi_select'
    },
    fieldProps() {
      const baseProps = {}

      switch (this.type) {
        case 'text':
          return {
            id: this.inputId,
            value: this.modelValue || '',
            required: this.required,
            disabled: this.disabled,
            placeholder: this.field.field_label
          }

        case 'number':
          return {
            id: this.inputId,
            value: String(this.modelValue || ''),
            type: 'number',
            required: this.required,
            disabled: this.disabled,
            placeholder: this.field.field_label
          }

        case 'select':
        case 'multiselect':
        case 'multi_select':
          return {
            id: this.inputId,
            value: this.selectValue,
            options: this.selectOptions,
            disabled: this.disabled,
            placeholder: this.field.field_label,
            reduce: option => option.value,
            label: 'label',
            multiple: this.isMultiSelect
          }

        case 'checkbox':
          return {
            id: this.inputId,
            checked: this.checkboxValue,
            disabled: this.disabled,
            type: 'checkbox'
          }

        default:
          return baseProps
      }
    },
    fieldEvents() {
      const events = {}

      switch (this.type) {
        case 'text':
          events['update:value'] = (value) => {
            this.$emit('update:modelValue', value)
          }
          break

        case 'number':
          events['update:value'] = (value) => {
            // Keep as string for consistency with backend
            this.$emit('update:modelValue', value !== '' && value !== null ? String(value) : '')
          }
          break

        case 'select':
          events['input'] = (value) => {
            // With reduce, value is already the raw value
            this.$emit('update:modelValue', value || '')
          }
          break

        case 'multiselect':
        case 'multi_select':
          events['input'] = (values) => {
            // With reduce, values is already an array of raw values
            // Join with ;# for backend compatibility
            const joinedValue = Array.isArray(values) ? values.join(';#') : ''
            this.$emit('update:modelValue', joinedValue)
          }
          break

        case 'checkbox':
          events['update:checked'] = (checked) => {
            this.$emit('update:modelValue', checked ? '1' : '0')
          }
          break
      }

      return events
    },
    selectOptions() {
      if (this.type !== 'select' && !this.isMultiSelect) return []

      // Use provided options prop if available
      if (this.options && this.options.length > 0) {
        return this.options
      }

      // Fall back to field.field_options
      if (!this.field.field_options) return []

      const fieldOptions = typeof this.field.field_options === 'string'
        ? this.field.field_options.split('\n').filter(o => o.trim())
        : this.field.field_options

      return fieldOptions.map(opt => ({
        value: typeof opt === 'string' ? opt.trim() : (opt.value || opt),
        label: typeof opt === 'string' ? opt.trim() : (opt.label || opt.value || opt)
      }))
    },
    selectValue() {
      if (this.type === 'select') {
        // With reduce, we return the raw value directly
        return this.modelValue || null
      }

      if (this.isMultiSelect) {
        // Parse ;# separated string back to array
        if (!this.modelValue) return []
        if (Array.isArray(this.modelValue)) return this.modelValue
        return this.modelValue.split(';#').filter(v => v)
      }

      return null
    },
    checkboxValue() {
      if (this.type !== 'checkbox') return false
      return this.modelValue === '1' ||
             this.modelValue === 'true' ||
             this.modelValue === true ||
             this.modelValue === 1
    }
  }
}
</script>

<style scoped>
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
</style>
