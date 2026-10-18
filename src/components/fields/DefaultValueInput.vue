<template>
  <div class="default-value-input" :class="`dv--${field.field_type}`">
    <label v-if="field.field_type !== 'checkbox'" :for="inputId" class="default-value-label">
      <!-- TRANSLATORS Noun: label of the input that sets the default value of a field for new files in a Team folder -->
      {{ t('metavox', 'Default value') }}
    </label>

    <!-- Select -->
    <!-- TRANSLATORS Placeholder of a dropdown field before a value is picked -->
    <NcSelect :placeholder="t('metavox', 'Choose an option …')"
      v-if="field.field_type === 'select'"
      :id="inputId"
      :model-value="modelValue"
      :options="options"
      class="field-input select-field"
      :clearable="true"
      :reduce="option => option.value"
      label="label"
      @update:model-value="emitValue($event)" />

    <!-- MultiSelect -->
    <!-- TRANSLATORS Placeholder of a multi-select field before any value is picked -->
    <NcSelect :placeholder="t('metavox', 'Choose options …')"
      v-else-if="field.field_type === 'multiselect'"
      :id="inputId"
      :model-value="multiSelectValue"
      :options="options"
      :multiple="true"
      class="field-input select-field"
      :clearable="true"
      :reduce="option => option.value"
      label="label"
      @update:model-value="emitMultiSelect($event)" />

    <!-- The three below were raw <textarea> and <input> while every other
         branch used the component library, so they carried none of its
         focus ring, border or height and sat a few pixels off the rest of
         the row. NcTextArea and NcTextField take the same native types. -->

    <!-- Textarea -->
    <!-- TRANSLATORS Noun: label of the input that sets the default value of a field for new files in a Team folder -->
    <NcTextArea :label="t('metavox', 'Default value')"
      v-else-if="field.field_type === 'textarea'"
      :id="inputId"
      :model-value="modelValue"
      :placeholder="field.field_label"
      hide-label
      :rows="2"
      class="field-input textarea-input"
      @update:model-value="emitValue($event)" />

    <!-- Date (optionally datetime-local) -->
    <!-- TRANSLATORS Noun: label of the input that sets the default value of a field for new files in a Team folder -->
    <NcTextField :label="t('metavox', 'Default value')"
      v-else-if="field.field_type === 'date'"
      :id="inputId"
      :model-value="modelValue"
      hide-label
      :type="includesTime ? 'datetime-local' : 'date'"
      :step="includesTime ? 1 : undefined"
      class="field-input date-input"
      @update:model-value="emitValue(includesTime ? padDatetimeLocal($event) : $event)" />

    <!-- Number -->
    <!-- TRANSLATORS Noun: label of the input that sets the default value of a field for new files in a Team folder -->
    <NcTextField :label="t('metavox', 'Default value')"
      v-else-if="field.field_type === 'number'"
      :id="inputId"
      :model-value="modelValue"
      hide-label
      type="number"
      class="field-input number-input"
      @update:model-value="emitValue($event)" />

    <!-- Checkbox -->
    <NcCheckboxRadioSwitch
      v-else-if="field.field_type === 'checkbox'"
      :id="inputId"
      :model-value="checkboxChecked"
      type="checkbox"
      @update:model-value="emitValue($event ? '1' : '0')">
      <!-- TRANSLATORS Noun: label of the input that sets the default value of a field for new files in a Team folder -->
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
      :resolved="resolved"
      class="field-input"
      @input="emitValue($event)" />

    <!-- Text / fallback -->
    <!-- TRANSLATORS Noun: label of the input that sets the default value of a field for new files in a Team folder -->
    <NcTextField :label="t('metavox', 'Default value')"
      v-else
      :id="inputId"
      :model-value="modelValue"
      :placeholder="field.field_label"
      class="field-input"
      @update:model-value="emitValue($event)" />
  </div>
</template>

<script>
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { translate as t } from '@nextcloud/l10n'
import { dateFieldIncludesTime, padDatetimeLocal } from '../../utils/dateField.js'
import { optionItems } from '../../utils/fieldOptions.js'
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
    NcTextArea,
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
    // File links: the linked files as you see them, from the server.
    resolved: {
      type: Array,
      default: () => [],
    },
  },
  emits: ['update:modelValue'],
  computed: {
    options() {
      return optionItems(this.field.field_options)
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
  font-size: var(--font-size-small);
  color: var(--color-text-maxcontrast);
  margin-bottom: 4px;
}

/* No min-height: the components own their height and it is the platform's
   34px. A hardcoded 44px made these three controls taller than every other
   control in the same row (§6, "Every control in the form measures the same
   height"). */
.field-input {
  width: 100%;
  max-width: 480px;
}

/* These three are NcTextArea/NcTextField now. The old rules styled a raw
   input and would land on the component wrapper, and the :focus rule set
   `outline: none` — which on a component that draws its own ring removes
   the ring and replaces it with nothing (§8). */

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
