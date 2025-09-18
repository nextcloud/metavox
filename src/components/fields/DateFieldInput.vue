<template>
  <NcDatetimePicker
    :value="dateValue"
    @update:value="onUpdate"
    type="date"
    :required="required"
    :placeholder="field.field_label" />
</template>

<script>
import { NcDatetimePicker } from '@nextcloud/vue'

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
    dateValue() {
      if (!this.modelValue) return null
      return new Date(this.modelValue)
    }
  },
  methods: {
    onUpdate(value) {
      if (!value) {
        this.$emit('update:modelValue', '')
      } else {
        const date = new Date(value)
        const dateString = date.toISOString().split('T')[0]
        this.$emit('update:modelValue', dateString)
      }
    }
  }
}
</script>