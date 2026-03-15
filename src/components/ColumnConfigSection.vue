<template>
  <div class="column-config-section">
    <h4>{{ t('metavox', 'File List Columns') }}</h4>

    <p class="description">
      {{ t('metavox', 'Select which metadata fields appear as columns in the Files app when browsing this team folder.') }}
    </p>

    <div v-if="loading" class="loading-container">
      <div class="icon-loading"></div>
    </div>

    <div v-else-if="columns.length === 0" class="empty-state">
      <p>{{ t('metavox', 'No file-level fields are assigned to this team folder. Assign fields first to configure columns.') }}</p>
    </div>

    <div v-else class="column-list">
      <div
        v-for="(col, index) in columns"
        :key="col.field_id"
        class="column-item"
      >
        <div class="column-item-left">
          <NcCheckboxRadioSwitch
            :model-value="col.show_as_column"
            @update:model-value="toggleColumn(index, $event)"
            type="checkbox"
          >
            {{ col.field_label }}
          </NcCheckboxRadioSwitch>
          <span class="field-type-badge">{{ col.field_type }}</span>
        </div>

        <div v-if="col.show_as_column" class="column-item-right">
          <NcCheckboxRadioSwitch
            :model-value="col.filterable"
            @update:model-value="toggleFilterable(index, $event)"
            type="checkbox"
          >
            {{ t('metavox', 'Filterable') }}
          </NcCheckboxRadioSwitch>

          <div class="order-buttons">
            <NcButton
              type="tertiary"
              :aria-label="t('metavox', 'Move up')"
              :disabled="index === 0"
              @click="moveUp(index)"
            >
              <template #icon>
                <ChevronUpIcon :size="20" />
              </template>
            </NcButton>
            <NcButton
              type="tertiary"
              :aria-label="t('metavox', 'Move down')"
              :disabled="index === columns.length - 1"
              @click="moveDown(index)"
            >
              <template #icon>
                <ChevronDownIcon :size="20" />
              </template>
            </NcButton>
          </div>
        </div>
      </div>
    </div>

    <div v-if="columns.length > 0 && hasChanges" class="save-actions">
      <NcButton type="primary" :disabled="saving" @click="saveConfig">
        {{ saving ? t('metavox', 'Saving...') : t('metavox', 'Save column settings') }}
      </NcButton>
    </div>

    <div v-if="saveMessage" :class="['save-message', saveSuccess ? 'success' : 'error']">
      {{ saveMessage }}
    </div>

    <ViewConfigSection
      :groupfolder-id="groupfolderId"
      :api-base-path="apiBasePath"
    />
  </div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import ChevronUpIcon from 'vue-material-design-icons/ChevronUp.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import ViewConfigSection from './ViewConfigSection.vue'

export default {
  name: 'ColumnConfigSection',

  components: {
    NcButton,
    NcCheckboxRadioSwitch,
    ChevronUpIcon,
    ChevronDownIcon,
    ViewConfigSection,
  },

  props: {
    groupfolderId: {
      type: Number,
      required: true,
    },
    apiBasePath: {
      type: String,
      default: '/apps/metavox/api',
    },
  },

  data() {
    return {
      columns: [],
      originalColumns: [],
      loading: true,
      saving: false,
      saveMessage: '',
      saveSuccess: false,
    }
  },

  computed: {
    hasChanges() {
      return JSON.stringify(this.columns) !== JSON.stringify(this.originalColumns)
    },
  },

  watch: {
    groupfolderId: {
      immediate: true,
      handler() {
        this.loadConfig()
      },
    },
  },

  methods: {
    t,

    async loadConfig() {
      this.loading = true
      try {
        const url = generateUrl(`${this.apiBasePath}/groupfolders/${this.groupfolderId}/columns`)
        const response = await axios.get(url)
        this.columns = response.data || []
        this.originalColumns = JSON.parse(JSON.stringify(this.columns))
      } catch (error) {
        console.error('MetaVox: Failed to load column config', error)
        this.columns = []
      } finally {
        this.loading = false
      }
    },

    toggleColumn(index, value) {
      this.columns[index].show_as_column = value
    },

    toggleFilterable(index, value) {
      this.columns[index].filterable = value
    },

    moveUp(index) {
      if (index === 0) return
      const item = this.columns.splice(index, 1)[0]
      this.columns.splice(index - 1, 0, item)
      this.updateColumnOrders()
    },

    moveDown(index) {
      if (index >= this.columns.length - 1) return
      const item = this.columns.splice(index, 1)[0]
      this.columns.splice(index + 1, 0, item)
      this.updateColumnOrders()
    },

    updateColumnOrders() {
      this.columns.forEach((col, i) => {
        col.column_order = i
      })
    },

    async saveConfig() {
      this.saving = true
      this.saveMessage = ''
      try {
        const url = generateUrl(`${this.apiBasePath}/groupfolders/${this.groupfolderId}/columns`)
        await axios.post(url, { columns: this.columns })
        this.originalColumns = JSON.parse(JSON.stringify(this.columns))
        this.saveMessage = t('metavox', 'Column settings saved successfully')
        this.saveSuccess = true
        setTimeout(() => { this.saveMessage = '' }, 3000)
      } catch (error) {
        console.error('MetaVox: Failed to save column config', error)
        this.saveMessage = t('metavox', 'Failed to save column settings')
        this.saveSuccess = false
      } finally {
        this.saving = false
      }
    },
  },
}
</script>

<style scoped>
.column-config-section {
  margin-top: 16px;
  padding: 12px 16px;
  background: var(--color-background-hover);
  border-radius: var(--border-radius-large);
}

.column-config-section h4 {
  margin: 0 0 4px 0;
  font-size: 14px;
  font-weight: 600;
}

.column-config-section .description {
  margin: 0 0 12px 0;
  color: var(--color-text-maxcontrast);
  font-size: 13px;
}

.empty-state {
  color: var(--color-text-maxcontrast);
  font-style: italic;
  font-size: 13px;
}

.column-list {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.column-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 4px 8px;
  border-radius: var(--border-radius);
  background: var(--color-main-background);
}

.column-item-left {
  display: flex;
  align-items: center;
  gap: 8px;
}

.column-item-right {
  display: flex;
  align-items: center;
  gap: 8px;
}

.field-type-badge {
  font-size: 11px;
  padding: 1px 6px;
  border-radius: 10px;
  background: var(--color-primary-element-light);
  color: var(--color-primary-element);
}

.order-buttons {
  display: flex;
  gap: 2px;
}

.save-actions {
  margin-top: 12px;
}

.save-message {
  margin-top: 8px;
  font-size: 13px;
}

.save-message.success {
  color: var(--color-success);
}

.save-message.error {
  color: var(--color-error);
}

.loading-container {
  padding: 20px;
  text-align: center;
}
</style>
