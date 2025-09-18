<template>
  <NcTextField
    :value="modelValue"
    @update:value="$emit('update:modelValue', $event)"
    :required="required"
    :placeholder="field.field_label" />
</template>

<script>
import { NcTextField } from '@nextcloud/vue'

export default {
  name: 'TextFieldInput',
  components: { NcTextField },
  props: {
    modelValue: String,
    field: Object,
    required: Boolean
  },
  emits: ['update:modelValue']
}
</script>