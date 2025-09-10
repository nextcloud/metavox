<template>
  <NcTextArea
    :value="modelValue"
    @update:value="$emit('update:modelValue', $event)"
    :required="required"
    :placeholder="field.field_label"
    :rows="3" />
</template>

<script>
import { NcTextArea } from '@nextcloud/vue'

export default {
  name: 'TextAreaInput',
  components: { NcTextArea },
  props: {
    modelValue: String,
    field: Object,
    required: Boolean
  },
  emits: ['update:modelValue']
}
</script>
