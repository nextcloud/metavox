<template>
  <div class="view-config-section">
    <h4>{{ labels.views }}</h4>
    <p class="description">
      {{ t('metavox', 'Create predefined filter, column and sort combinations for this Team folder.') }}
    </p>

    <div v-if="loading" class="loading-container">
      <div class="icon-loading"></div>
    </div>

    <div v-else>
      <!-- Views list -->
      <div v-if="views.length > 0" class="view-list">
        <div
          v-for="view in views"
          :key="view.id"
          class="view-item"
        >
          <div class="view-item-left">
            <StarIcon v-if="view.is_default" :size="16" class="default-icon" />
            <span class="view-name">{{ view.name }}</span>
            <span class="view-meta">
              {{ columnCountLabel(view) }}
            </span>
            <span class="view-meta">
              {{ filterCountLabel(view) }}
            </span>
          </div>
          <div class="view-item-right">
            <NcButton
              v-if="!view.is_default"
              type="tertiary"
              :aria-label="labels.setDefaultButton"
              @click="setDefaultView(view)"
            >
              <template #icon>
                <StarOutlineIcon :size="20" />
              </template>
            </NcButton>
            <NcButton
              type="tertiary"
              :aria-label="labels.editView"
              @click="startEdit(view)"
            >
              <template #icon>
                <PencilIcon :size="20" />
              </template>
            </NcButton>
            <NcButton
              type="tertiary"
              :aria-label="labels.deleteView"
              @click="deleteView(view.id)"
            >
              <template #icon>
                <DeleteIcon :size="20" />
              </template>
            </NcButton>
          </div>
        </div>
      </div>

      <div v-else-if="!showForm" class="empty-state">
        <p>{{ t('metavox', 'No views configured yet.') }}</p>
      </div>

      <!-- Inline form -->
      <div v-if="showForm" class="view-form">
        <h5>{{ editingView ? labels.editView : labels.newView }}</h5>

        <!-- Name -->
        <div class="form-row">
          <label class="form-label">{{ labels.name }} <span class="required">*</span></label>
          <input
            v-model="form.name"
            type="text"
            class="nc-input"
            :placeholder="labels.namePlaceholder"
          />
        </div>

        <!-- Default -->
        <div class="form-row">
          <NcCheckboxRadioSwitch
            v-model="form.is_default"
            type="checkbox"
          >
            {{ labels.setDefaultCheckbox }}
          </NcCheckboxRadioSwitch>
        </div>

        <!-- Columns -->
        <div class="form-section">
          <h6>{{ labels.columns }}</h6>
          <p class="form-section-description">
            {{ t('metavox', 'Choose which columns to show and in what order.') }}
          </p>
          <div v-if="loadingColumns" class="loading-container">
            <div class="icon-loading"></div>
          </div>
          <div v-else-if="availableColumns.length === 0" class="empty-state">
            <p>{{ t('metavox', 'No columns configured. Configure columns first.') }}</p>
          </div>
          <div v-else class="column-list">
            <div
              v-for="(col, index) in form.columns"
              :key="col.field_id"
              class="column-item"
            >
              <div class="column-item-left">
                <NcCheckboxRadioSwitch
                  :model-value="col.visible"
                  @update:model-value="col.visible = $event"
                  type="checkbox"
                >
                  {{ col.field_label }}
                </NcCheckboxRadioSwitch>
              </div>
              <div class="column-item-right">
                <NcButton
                  type="tertiary"
                  :aria-label="labels.moveUp"
                  :disabled="index === 0"
                  @click="moveColumnUp(index)"
                >
                  <template #icon>
                    <ChevronUpIcon :size="20" />
                  </template>
                </NcButton>
                <NcButton
                  type="tertiary"
                  :aria-label="labels.moveDown"
                  :disabled="index === form.columns.length - 1"
                  @click="moveColumnDown(index)"
                >
                  <template #icon>
                    <ChevronDownIcon :size="20" />
                  </template>
                </NcButton>
              </div>
            </div>
          </div>
        </div>

        <!-- Filters -->
        <div class="form-section">
          <h6>{{ labels.filters }}</h6>
          <p class="form-section-description">
            {{ t('metavox', 'Set preset filter values (comma-separated) per field.') }}
          </p>
          <div v-if="filterableColumns.length === 0" class="empty-state">
            <p>{{ t('metavox', 'No filterable columns available. Mark columns as filterable in the column settings.') }}</p>
          </div>
          <div v-else class="filter-list">
            <div
              v-for="col in filterableColumns"
              :key="col.field_id"
              class="filter-item"
            >
              <label class="filter-label">{{ col.field_label }}</label>
              <input
                v-model="form.filters[col.field_id]"
                type="text"
                class="nc-input"
                :placeholder="labels.filterPlaceholder"
              />
            </div>
          </div>
        </div>

        <!-- Sort -->
        <div class="form-section">
          <h6>{{ labels.sort }}</h6>
          <div class="sort-row">
            <select v-model="form.sort_field" class="nc-select">
              <option value="">{{ labels.noSorting }}</option>
              <option
                v-for="col in availableColumns"
                :key="col.field_id"
                :value="col.field_id"
              >
                {{ col.field_label }}
              </option>
            </select>
            <div v-if="form.sort_field" class="sort-order-group">
              <NcCheckboxRadioSwitch
                v-model="form.sort_order"
                value="asc"
                type="radio"
                name="sort_order"
              >
                {{ labels.ascending }}
              </NcCheckboxRadioSwitch>
              <NcCheckboxRadioSwitch
                v-model="form.sort_order"
                value="desc"
                type="radio"
                name="sort_order"
              >
                {{ labels.descending }}
              </NcCheckboxRadioSwitch>
            </div>
          </div>
        </div>

        <!-- Form feedback -->
        <div v-if="formMessage" :class="['save-message', formSuccess ? 'success' : 'error']">
          {{ formMessage }}
        </div>

        <!-- Form actions -->
        <div class="form-actions">
          <NcButton type="primary" :disabled="saving" @click="saveView">
            {{ saving ? labels.saving : labels.saveView }}
          </NcButton>
          <NcButton type="secondary" @click="cancelForm">
            {{ labels.cancel }}
          </NcButton>
        </div>
      </div>

      <!-- Add button — only show when form is hidden -->
      <div v-if="!showForm" class="add-view-action">
        <NcButton type="secondary" @click="startCreate">
          <template #icon>
            <PlusIcon :size="20" />
          </template>
          {{ labels.createView }}
        </NcButton>
      </div>
    </div>
  </div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import ChevronUpIcon from 'vue-material-design-icons/ChevronUp.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import StarIcon from 'vue-material-design-icons/Star.vue'
import StarOutlineIcon from 'vue-material-design-icons/StarOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'

export default {
  name: 'ViewConfigSection',

  components: {
    NcButton,
    NcCheckboxRadioSwitch,
    ChevronUpIcon,
    ChevronDownIcon,
    PencilIcon,
    DeleteIcon,
    StarIcon,
    StarOutlineIcon,
    PlusIcon,
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
      views: [],
      availableColumns: [],
      loading: true,
      loadingColumns: false,
      saving: false,
      showForm: false,
      editingView: null,
      form: this.emptyForm(),
      formMessage: '',
      formSuccess: false,
    }
  },

  computed: {
    filterableColumns() {
      return this.availableColumns.filter(c => c.filterable)
    },
    labels() {
      return {
        // TRANSLATORS Noun: heading of the section that lists the saved views of this Team folder
        views: t('metavox', 'Views'),
        // TRANSLATORS Accessible name of the star button that makes this view the default view
        setDefaultButton: t('metavox', 'Set as default view'),
        // TRANSLATORS Title of the view editor when it changes an existing view
        editView: t('metavox', 'Edit view'),
        // TRANSLATORS Verb: button in the confirmation dialog that deletes the view
        deleteView: t('metavox', 'Delete view'),
        // TRANSLATORS Title of the view editor when it creates a new view; also a menu item in the view menu
        newView: t('metavox', 'New view'),
        // TRANSLATORS Noun: label of the field that holds the name of a view
        name: t('metavox', 'Name'),
        // TRANSLATORS Placeholder of the field that holds the name of a view
        namePlaceholder: t('metavox', 'View name'),
        // TRANSLATORS Label of the checkbox that makes this view the default view
        setDefaultCheckbox: t('metavox', 'Set as default view'),
        // TRANSLATORS Noun: heading of the column list section in the view editor
        columns: t('metavox', 'Columns'),
        // TRANSLATORS Verb: menu item that moves a highlight rule one place up
        moveUp: t('metavox', 'Move up'),
        // TRANSLATORS Verb: menu item that moves a highlight rule one place down
        moveDown: t('metavox', 'Move down'),
        // TRANSLATORS Noun: heading of the filter section in the view editor
        filters: t('metavox', 'Filters'),
        // TRANSLATORS Placeholder of a filter value field; example values separated by commas, keep the commas
        filterPlaceholder: t('metavox', 'e.g. Value 1, Value 2'),
        // TRANSLATORS Noun: heading of the sort section in the view editor
        sort: t('metavox', 'Sort'),
        // TRANSLATORS Placeholder of the sort field dropdown when the view does not sort
        noSorting: t('metavox', '— no sorting —'),
        // TRANSLATORS Radio option for the sort direction (A to Z, oldest first)
        ascending: t('metavox', 'Ascending'),
        // TRANSLATORS Radio option for the sort direction (Z to A, newest first)
        descending: t('metavox', 'Descending'),
        // TRANSLATORS Label of the save button while the view is being saved; keep it short
        saving: t('metavox', 'Saving …'),
        // TRANSLATORS Button that saves the view; keep it short
        saveView: t('metavox', 'Save view'),
        // TRANSLATORS Verb: dialog button that closes the view editor without saving
        cancel: t('metavox', 'Cancel'),
        // TRANSLATORS Button that opens the form for a new view; keep it short
        createView: t('metavox', 'Create new view'),
      }
    },
  },

  watch: {
    groupfolderId: {
      immediate: true,
      handler() {
        this.loadViews()
      },
    },
  },

  methods: {
    t,
    n,

    emptyForm() {
      return {
        name: '',
        is_default: false,
        columns: [],
        filters: {},
        sort_field: '',
        sort_order: 'asc',
      }
    },

    filterCount(view) {
      if (!view.filters) return 0
      return Object.values(view.filters).filter(v => v && String(v).trim() !== '').length
    },

    columnCountLabel(view) {
      // TRANSLATORS %n is the number of columns shown in this view
      return n('metavox', '%n column', '%n columns', (view.columns || []).length)
    },

    filterCountLabel(view) {
      // TRANSLATORS %n is the number of preset filters in this view
      return n('metavox', '%n filter', '%n filters', this.filterCount(view))
    },

    async loadViews() {
      this.loading = true
      try {
        const url = generateUrl(`${this.apiBasePath}/groupfolders/${this.groupfolderId}/views`)
        const response = await axios.get(url)
        this.views = response.data || []
      } catch (error) {
        console.error('MetaVox: Failed to load views', error)
        this.views = []
      } finally {
        this.loading = false
      }
    },

    async loadColumns() {
      this.loadingColumns = true
      try {
        const url = generateUrl(`${this.apiBasePath}/groupfolders/${this.groupfolderId}/file-fields`)
        const response = await axios.get(url)
        this.availableColumns = response.data || []
      } catch (error) {
        console.error('MetaVox: Failed to load available fields', error)
        this.availableColumns = []
      } finally {
        this.loadingColumns = false
      }
    },

    buildFormColumns(existingColumns) {
      // Merge availableColumns with any stored column config from the view
      const existingMap = {}
      ;(existingColumns || []).forEach(c => {
        existingMap[c.field_id] = c
      })

      return this.availableColumns.map(col => ({
        field_id: col.id,
        field_label: col.field_label,
        visible: existingMap[col.id] ? existingMap[col.id].visible !== false : false,
      }))
    },

    async startCreate() {
      if (this.availableColumns.length === 0) {
        await this.loadColumns()
      }
      this.editingView = null
      this.form = this.emptyForm()
      this.form.columns = this.buildFormColumns([])
      this.formMessage = ''
      this.showForm = true
    },

    async startEdit(view) {
      if (this.availableColumns.length === 0) {
        await this.loadColumns()
      }
      this.editingView = view
      this.form = {
        name: view.name,
        is_default: !!view.is_default,
        columns: this.buildFormColumns(view.columns || []),
        filters: Object.assign({}, view.filters || {}),
        sort_field: view.sort_field || '',
        sort_order: view.sort_order || 'asc',
      }
      this.formMessage = ''
      this.showForm = true
    },

    cancelForm() {
      this.showForm = false
      this.editingView = null
      this.form = this.emptyForm()
      this.formMessage = ''
    },

    moveColumnUp(index) {
      if (index === 0) return
      const item = this.form.columns.splice(index, 1)[0]
      this.form.columns.splice(index - 1, 0, item)
    },

    moveColumnDown(index) {
      if (index >= this.form.columns.length - 1) return
      const item = this.form.columns.splice(index, 1)[0]
      this.form.columns.splice(index + 1, 0, item)
    },

    async saveView() {
      if (!this.form.name.trim()) {
        // TRANSLATORS Error shown when the name field of the view is empty
        this.formMessage = t('metavox', 'Name is required')
        this.formSuccess = false
        return
      }

      this.saving = true
      this.formMessage = ''

      const payload = {
        name: this.form.name.trim(),
        is_default: this.form.is_default,
        columns: this.form.columns,
        filters: this.form.filters,
        sort_field: this.form.sort_field || null,
        sort_order: this.form.sort_order,
      }

      try {
        if (this.editingView) {
          const url = generateUrl(`${this.apiBasePath}/groupfolders/${this.groupfolderId}/views/${this.editingView.id}`)
          await axios.put(url, payload)
        } else {
          const url = generateUrl(`${this.apiBasePath}/groupfolders/${this.groupfolderId}/views`)
          await axios.post(url, payload)
        }

        await this.loadViews()
        this.cancelForm()
      } catch (error) {
        console.error('MetaVox: Failed to save view', error)
        this.formMessage = t('metavox', 'Failed to save view')
        this.formSuccess = false
      } finally {
        this.saving = false
      }
    },

    async setDefaultView(view) {
      try {
        const url = generateUrl(`${this.apiBasePath}/groupfolders/${this.groupfolderId}/views/${view.id}`)
        await axios.put(url, {
          name: view.name,
          is_default: true,
          columns: view.columns || [],
          filters: view.filters || {},
          sort_field: view.sort_field || null,
          sort_order: view.sort_order || 'asc',
        })
        await this.loadViews()
      } catch (error) {
        console.error('MetaVox: Failed to set default view', error)
      }
    },

    async deleteView(viewId) {
      // TRANSLATORS Question in the confirmation box shown before a view is deleted
      if (!confirm(t('metavox', 'Delete this view?'))) return
      try {
        const url = generateUrl(`${this.apiBasePath}/groupfolders/${this.groupfolderId}/views/${viewId}`)
        await axios.delete(url)
        this.views = this.views.filter(v => v.id !== viewId)
        if (this.editingView && this.editingView.id === viewId) {
          this.cancelForm()
        }
      } catch (error) {
        console.error('MetaVox: Failed to delete view', error)
      }
    },
  },
}
</script>

<style scoped>
.view-config-section {
  margin-top: 16px;
  padding: 12px 16px;
  background: var(--color-background-hover);
  border-radius: var(--border-radius-large);
}

.view-config-section h4 {
  margin: 0 0 4px 0;
  font-size: 14px;
  font-weight: 600;
}

.view-config-section h5 {
  margin: 0 0 8px 0;
  font-size: 13px;
  font-weight: 600;
}

.view-config-section h6 {
  margin: 0 0 4px 0;
  font-size: 12px;
  font-weight: 600;
  color: var(--color-text-maxcontrast);
}

.description {
  margin: 0 0 12px 0;
  color: var(--color-text-maxcontrast);
  font-size: 13px;
}

.empty-state {
  color: var(--color-text-maxcontrast);
  font-style: italic;
  font-size: 13px;
}

/* Views list */
.view-list {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-bottom: 8px;
}

.view-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 6px 8px;
  border-radius: var(--border-radius);
  background: var(--color-main-background);
}

.view-item-left {
  display: flex;
  align-items: center;
  gap: 8px;
}

.view-item-right {
  display: flex;
  align-items: center;
  gap: 2px;
}

.default-icon {
  color: var(--color-warning);
}

.view-name {
  font-size: 13px;
  font-weight: 500;
}

.view-meta {
  font-size: 11px;
  color: var(--color-text-maxcontrast);
  padding: 1px 6px;
  border-radius: 10px;
  background: var(--color-primary-element-light);
  color: var(--color-primary-element);
}

/* Add button */
.add-view-action {
  margin-top: 8px;
}

/* Inline form */
.view-form {
  margin-top: 12px;
  padding: 12px;
  background: var(--color-main-background);
  border-radius: var(--border-radius);
  border: 1px solid var(--color-border);
}

.form-row {
  margin-bottom: 10px;
}

.form-label {
  display: block;
  font-size: 13px;
  font-weight: 500;
  margin-bottom: 4px;
}

.required {
  color: var(--color-error);
}

.nc-input {
  width: 100%;
  max-width: 360px;
  height: 36px;
  padding: 0 8px;
  border: 1px solid var(--color-border-maxcontrast);
  border-radius: var(--border-radius);
  background: var(--color-main-background);
  color: var(--color-main-text);
  font-size: 13px;
  box-sizing: border-box;
}

.nc-input:focus {
  outline: none;
  border-color: var(--color-primary-element);
  box-shadow: 0 0 0 2px var(--color-primary-element-light);
}

.nc-select {
  height: 36px;
  padding: 0 8px;
  border: 1px solid var(--color-border-maxcontrast);
  border-radius: var(--border-radius);
  background: var(--color-main-background);
  color: var(--color-main-text);
  font-size: 13px;
  min-width: 200px;
}

.nc-select:focus {
  outline: none;
  border-color: var(--color-primary-element);
}

/* Form sections */
.form-section {
  margin-top: 14px;
  padding-top: 14px;
  border-top: 1px solid var(--color-border);
}

.form-section-description {
  font-size: 12px;
  color: var(--color-text-maxcontrast);
  margin: 0 0 8px 0;
}

/* Column list inside form */
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
  background: var(--color-background-hover);
}

.column-item-left {
  display: flex;
  align-items: center;
}

.column-item-right {
  display: flex;
  gap: 2px;
}

/* Filter list */
.filter-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.filter-item {
  display: flex;
  align-items: center;
  gap: 10px;
}

.filter-label {
  font-size: 13px;
  min-width: 120px;
  flex-shrink: 0;
}

/* Sort row */
.sort-row {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.sort-order-group {
  display: flex;
  gap: 8px;
  align-items: center;
}

/* Form actions */
.form-actions {
  margin-top: 14px;
  display: flex;
  gap: 8px;
}

/* Feedback */
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
