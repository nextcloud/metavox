<template>
  <NcSelect
    :value="selectedOption"
    :options="selectOptions"
    label="label"
    track-by="value"
    :searchable="false"
    :required="required"
    :clearable="!required"
    @input="onSelectChange" />
</template>

<script>
import { NcSelect } from '@nextcloud/vue'

export default {
  name: 'SelectFieldInput',
  components: { NcSelect },
  props: {
    modelValue: String,
    field: Object,
    required: Boolean
  },
  emits: ['update:modelValue'],
  computed: {
    selectOptions() {
      if (!this.field.field_options) return []
      
      const options = typeof this.field.field_options === 'string'
        ? this.field.field_options.split('\n').filter(o => o.trim())
        : this.field.field_options
      
      return options.map(opt => ({
        value: opt,
        label: opt
      }))
    },
    selectedOption() {
      if (!this.modelValue) return null
      return this.selectOptions.find(opt => opt.value === this.modelValue)
    }
  },
  methods: {
    onSelectChange(option) {
      this.$emit('update:modelValue', option ? option.value : '')
    }
  }
}
</script>