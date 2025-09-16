<template>
  <NcTextField
    :value="String(modelValue)"
    @update:value="onUpdate"
    type="number"
    :required="required"
    :placeholder="field.field_label" />
</template>

<script>
import { NcTextField } from '@nextcloud/vue'

export default {
  name: 'NumberFieldInput',
  components: { NcTextField },
  props: {
    modelValue: [Number, String],
    field: Object,
    required: Boolean
  },
  emits: ['update:modelValue'],
  methods: {
    onUpdate(value) {
      const numValue = value === '' ? '' : Number(value)
      this.$emit('update:modelValue', numValue)
    }
  }
}
</script>