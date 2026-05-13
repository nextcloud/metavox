<template>
  <NcDatetimePicker
    :model-value="dateValue"
    @update:model-value="onUpdate"
    :type="includeTime ? 'datetime' : 'date'"
    :required="required"
    :placeholder="field.field_label" />
</template>

<script>
import { NcDatetimePicker } from '@nextcloud/vue'
import { dateFieldIncludesTime, formatLocalDatetime } from '../../utils/dateField.js'

export default {
  name: 'DateFieldInput',
  components: { NcDatetimePicker },
  props: {
    modelValue: String,
    field: Object,
    required: Boolean
  },
  emits: ['update:modelValue'],
  computed: {
    includeTime() {
      return dateFieldIncludesTime(this.field)
    },
    dateValue() {
      if (!this.modelValue) return null
      return new Date(this.modelValue)
    }
  },
  methods: {
    onUpdate(value) {
      if (!value) {
        this.$emit('update:modelValue', '')
        return
      }
      const date = new Date(value)
      if (this.includeTime) {
        this.$emit('update:modelValue', formatLocalDatetime(date))
      } else {
        this.$emit('update:modelValue', date.toISOString().split('T')[0])
      }
    }
  }
}
</script>
