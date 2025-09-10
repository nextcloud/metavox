<template>
  <NcCheckboxRadioSwitch
    :checked="isChecked"
    @update:checked="onUpdate"
    type="checkbox">
    {{ field.field_label }}
  </NcCheckboxRadioSwitch>
</template>

<script>
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'

export default {
  name: 'CheckboxFieldInput',
  components: { NcCheckboxRadioSwitch },
  props: {
    modelValue: [Boolean, String, Number],
    field: Object,
    required: Boolean
  },
  emits: ['update:modelValue'],
  computed: {
    isChecked() {
      return this.modelValue === '1' || 
             this.modelValue === 'true' || 
             this.modelValue === true ||
             this.modelValue === 1
    }
  },
  methods: {
    onUpdate(checked) {
      this.$emit('update:modelValue', checked ? '1' : '0')
    }
  }
}
</script>