<template>
  <div class="url-field-wrapper">
    <div class="url-input-container">
      <NcTextField
        :id="inputId"
        :value="modelValue || ''"
        :disabled="disabled"
        :required="required"
        :placeholder="placeholder || t('metavox', 'Enter URL (https://...)')"
        :error="!isValidUrl && modelValue !== ''"
        type="url"
        @update:value="onInput" />
      <a
        v-if="modelValue && isValidUrl"
        :href="normalizedUrl"
        target="_blank"
        rel="noopener noreferrer"
        class="url-open-button"
        :title="t('metavox', 'Open link in new tab')">
        <OpenInNew :size="20" />
      </a>
    </div>
    <p v-if="!isValidUrl && modelValue !== ''" class="url-error">
      {{ t('metavox', 'Please enter a valid URL') }}
    </p>
    <a
      v-else-if="modelValue && isValidUrl"
      :href="normalizedUrl"
      target="_blank"
      rel="noopener noreferrer"
      class="url-preview">
      {{ normalizedUrl }}
    </a>
  </div>
</template>

<script>
import { NcTextField } from '@nextcloud/vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'

export default {
  name: 'UrlFieldInput',
  components: {
    NcTextField,
    OpenInNew
  },
  props: {
    modelValue: {
      type: String,
      default: ''
    },
    field: {
      type: Object,
      default: () => ({})
    },
    required: {
      type: Boolean,
      default: false
    },
    disabled: {
      type: Boolean,
      default: false
    },
    inputId: {
      type: String,
      default: ''
    },
    placeholder: {
      type: String,
      default: ''
    }
  },
  emits: ['update:modelValue', 'input'],
  computed: {
    isValidUrl() {
      if (!this.modelValue) return true
      const urlPattern = /^(https?:\/\/)?([\da-z.-]+)\.([a-z.]{2,6})([/\w .-]*)*\/?(\?[^\s]*)?$/i
      return urlPattern.test(this.modelValue)
    },
    normalizedUrl() {
      if (!this.modelValue) return ''
      // Add https:// if no protocol specified
      if (!/^https?:\/\//i.test(this.modelValue)) {
        return 'https://' + this.modelValue
      }
      return this.modelValue
    }
  },
  methods: {
    t(app, text) {
      return window.t ? window.t(app, text) : text
    },
    onInput(value) {
      console.log('UrlFieldInput onInput called with:', value)
      this.$emit('update:modelValue', value)
      this.$emit('input', value)
    }
  }
}
</script>

<style scoped>
.url-field-wrapper {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.url-input-container {
  display: flex;
  align-items: center;
  gap: 8px;
}

.url-input-container :deep(.input-field) {
  flex: 1;
}

.url-open-button {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: var(--border-radius);
  color: var(--color-primary-element);
  background: var(--color-background-hover);
  transition: background-color 0.2s ease;
}

.url-open-button:hover {
  background: var(--color-primary-element-light);
}

.url-preview {
  font-size: 12px;
  color: var(--color-primary-element);
  text-decoration: none;
  word-break: break-all;
}

.url-preview:hover {
  text-decoration: underline;
}

.url-error {
  font-size: 12px;
  color: var(--color-error);
  margin: 0;
}
</style>
