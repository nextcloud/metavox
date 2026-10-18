<template>
  <div class="url-field-wrapper">
    <div class="url-input-container">
      <!-- TRANSLATORS Placeholder of a web address field. {example} is a sample address, e.g. "https://example.com"; URL is a web address -->
      <NcTextField :placeholder="placeholder || t('metavox', 'Enter URL ({example})', { example: 'https://example.com' }, undefined, PLAIN_TEXT)"
        :id="inputId"
        :model-value="modelValue || ''"
        :disabled="disabled"
        :required="required"
        :error="!isValidUrl && modelValue !== ''"
        type="url"
        @update:model-value="onInput" />
      <!-- TRANSLATORS Tooltip of the icon button that opens the web address in a new browser tab -->
      <a :title="t('metavox', 'Open link in new tab')"
        v-if="modelValue && isValidUrl"
        :href="normalizedUrl"
        target="_blank"
        rel="noopener noreferrer"
        class="url-open-button">
        <OpenInNew :size="20" />
      </a>
    </div>
    <p v-if="!isValidUrl && modelValue !== ''" class="url-error">
      <!-- TRANSLATORS Error under a web address field; URL is a web address -->
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
import { translate } from '@nextcloud/l10n'
import { PLAIN_TEXT } from '../../utils/fieldValue.js'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'

export default {
  name: 'UrlFieldInput',
  // Templates read the t() options from the component.
  setup() {
    return { PLAIN_TEXT }
  },
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
    t: translate,
    onInput(value) {
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
  font-size: var(--font-size-small);
  color: var(--color-primary-element);
  text-decoration: none;
  word-break: break-all;
}

.url-preview:hover {
  text-decoration: underline;
}

/* --color-error is the pale background tint; on text it is near-invisible.
   --color-error-text is the readable tier. 12px is below the platform's
   smallest size and no theme setting can reach it. */
.url-error {
  font-size: var(--font-size-small);
  color: var(--color-error-text);
  margin: 0;
}
</style>
