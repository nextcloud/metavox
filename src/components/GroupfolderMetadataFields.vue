<template>
  <div class="groupfolder-metadata">
    <!-- Loading state -->
    <div v-if="loading" class="loading-container">
      <div class="icon-loading"></div>
      <p>{{ t('metavox', 'Loading metadata fields...') }}</p>
    </div>

    <div v-else>
      <!-- Page Header -->
      <div class="page-header">
        <h2>{{ t('metavox', 'Team folder Metadata Fields') }}</h2>
        <p class="page-description">
          {{ t('metavox', 'Configure custom metadata fields that will be available for all team folders. These fields help organize and categorize your team folders.') }}
        </p>
      </div>

      <!-- Import/Export Section -->
      <div class="settings-section">
        <div class="section-header">
          <h3>{{ t('metavox', 'Import & Export') }}</h3>
        </div>

        <div class="import-export-container">
          <div class="import-export-row">
            <div class="import-section">
              <h4>{{ t('metavox', 'Import Fields from JSON') }}</h4>
              <p class="import-description">
                {{ t('metavox', 'Upload a JSON file to import metadata fields.') }}
              </p>
              
              <div class="file-input-container">
                <input
                  ref="jsonFileInput"
                  type="file"
                  accept=".json"
                  @change="handleFileSelect"
                  class="file-input"
                  :disabled="importing"
                />
                <NcButton 
                  @click="triggerFileInput"
                  type="secondary"
                  :disabled="importing">
                  <template #icon>
                    <UploadIcon :size="20" />
                  </template>
                  {{ t('metavox', 'Select JSON File') }}
                </NcButton>
                
                <span v-if="selectedFile" class="selected-file">
                  {{ selectedFile.name }}
                </span>
              </div>
              
              <NcButton 
                v-if="selectedFile"
                @click="importFields"
                type="primary"
                :disabled="importing"
                class="import-button">
                <template #icon>
                  <div v-if="importing" class="icon-loading-small"></div>
                  <ImportIcon v-else :size="20" />
                </template>
                {{ importing ? t('metavox', 'Importing...') : t('metavox', 'Import Fields') }}
              </NcButton>
            </div>
            
            <div class="export-section">
              <h4>{{ t('metavox', 'Export Fields to JSON') }}</h4>
              <p class="export-description">
                {{ t('metavox', 'Download all metadata fields as a JSON file.') }}
              </p>
              
              <NcButton 
                @click="exportFields"
                type="primary"
                :disabled="fields.length === 0 || exporting">
                <template #icon>
                  <div v-if="exporting" class="icon-loading-small"></div>
                  <ExportIcon v-else :size="20" />
                </template>
                {{ exporting ? t('metavox', 'Exporting...') : t('metavox', 'Export Fields') }}
              </NcButton>
            </div>
          </div>
        </div>
      </div>

      <!-- Add New Field Section -->
      <div class="settings-section">
        <div class="section-header">
          <h3>{{ t('metavox', 'Add New Metadata Field') }}</h3>
          <NcButton 
            v-if="!showAddForm"
            @click="showAddForm = true" 
            type="primary">
            <template #icon>
              <PlusIcon :size="20" />
            </template>
            {{ t('metavox', 'Add Field') }}
          </NcButton>
        </div>

        <!-- Add Field Form -->
        <div v-if="showAddForm" class="add-field-form">
          <!-- Field Name (Internal) -->
          <div class="form-row">
            <NcTextField
              :value="formData.name"
              @update:value="formData.name = $event"
              :label="t('metavox', 'Field Name')"
              :placeholder="t('metavox', 'e.g., department, project_type, priority')"
              :helper-text="t('metavox', 'Internal name (no spaces, lowercase)')"
              :show-trailing-button="!!formData.name"
              trailing-button-icon="check"
              required />
          </div>

          <!-- Field Label (Display Name) -->
          <div class="form-row">
            <NcTextField
              :value="formData.label"
              @update:value="formData.label = $event"
              :label="t('metavox', 'Field Label')"
              :placeholder="t('metavox', 'e.g., Department, Project Type, Priority')"
              :helper-text="t('metavox', 'Display name shown to users')"
              :show-trailing-button="!!formData.label"
              trailing-button-icon="check"
              required />
          </div>

          <!-- Field Type - SIMPLIFIED APPROACH -->
          <div class="form-row">
            <label class="field-label">{{ t('metavox', 'Field Type') }}</label>
            <NcSelect
              :value="selectedFieldType"
              :options="selectOptions"
              label="label"
              track-by="id"
              :searchable="false"
              :clearable="false"
              @input="onFieldTypeChange" />
          </div>

          <!-- Description -->
          <div class="form-row">
            <NcTextArea
              :value="formData.description"
              @update:value="formData.description = $event"
              :label="t('metavox', 'Description')"
              :placeholder="t('metavox', 'Optional description for this field')"
              :rows="2" />
          </div>

          <!-- Required Field Checkbox -->
          <div class="form-row">
            <NcCheckboxRadioSwitch
              :checked="formData.required"
              @update:checked="formData.required = $event"
              type="checkbox">
              {{ t('metavox', 'Required field') }}
            </NcCheckboxRadioSwitch>
          </div>

          <!-- Options for select/multiselect fields -->
          <div v-if="showOptionsField" class="form-row">
            <label class="field-label">{{ t('metavox', 'Dropdown Options') }}</label>
            <div class="options-container">
              <div v-for="(option, index) in formData.options" :key="index" class="option-row">
                <NcTextField
                  :value="option.value"
                  @update:value="option.value = $event"
                  :placeholder="t('metavox', 'Option value')" />
                <NcButton
                  type="tertiary"
                  @click="removeOption(index)"
                  :aria-label="t('metavox', 'Remove option')">
                  <template #icon>
                    <CloseIcon :size="20" />
                  </template>
                </NcButton>
              </div>
              <NcButton 
                @click="addOption" 
                type="secondary">
                <template #icon>
                  <PlusIcon :size="20" />
                </template>
                {{ t('metavox', 'Add Option') }}
              </NcButton>
            </div>
          </div>

          <!-- Form Actions -->
          <div class="form-actions">
            <NcButton 
              @click="addField" 
              type="primary" 
              :disabled="!isFormValid || saving">
              <template #icon>
                <div v-if="saving" class="icon-loading-small"></div>
                <CheckIcon v-else :size="20" />
              </template>
              {{ saving ? t('metavox', 'Saving...') : t('metavox', 'Add Field') }}
            </NcButton>
            <NcButton @click="cancelAdd" type="secondary" :disabled="saving">
              {{ t('metavox', 'Cancel') }}
            </NcButton>
          </div>

          <!-- Debug Info -->
          <div v-if="debugMode" class="debug-info">
            <h4>Debug Info:</h4>
            <p><strong>Selected Type:</strong> {{ selectedFieldType ? selectedFieldType.id : 'none' }}</p>
            <p><strong>Form Type:</strong> {{ formData.type }}</p>
            <p><strong>Show Options:</strong> {{ showOptionsField }}</p>
            <p><strong>Is Valid:</strong> {{ isFormValid }}</p>
            <pre>{{ JSON.stringify(formData, null, 2) }}</pre>
          </div>
        </div>
      </div>

      <!-- Existing Fields List -->
      <div class="settings-section">
        <div class="section-header">
          <h3>{{ t('metavox', 'Existing Metadata Fields') }}</h3>
          <span class="field-count">{{ filteredFieldsCount }}</span>
        </div>
        
        <!-- Search Field -->
        <div class="search-container">
          <NcTextField
            :value="searchQuery"
            @update:value="searchQuery = $event"
            :placeholder="t('metavox', 'Search fields...')"
            :show-trailing-button="!!searchQuery"
            trailing-button-icon="close"
            @trailing-button-click="searchQuery = ''">
            <template #prefix>
              <MagnifyIcon :size="20" />
            </template>
          </NcTextField>
        </div>
        
        <!-- Search Results Info -->
        <div v-if="searchQuery && filteredFields.length > 0" class="search-results-info">
          <p>{{ t('metavox', 'Found {count} fields matching "{query}"', { 
            count: filteredFields.length, 
            query: searchQuery 
          }) }}</p>
        </div>
        
        <!-- Empty Search Results -->
        <div v-if="searchQuery && filteredFields.length === 0" class="empty-search">
          <p>{{ t('metavox', 'No fields found matching "{query}"', { query: searchQuery }) }}</p>
          <NcButton @click="searchQuery = ''" type="secondary">
            {{ t('metavox', 'Clear search') }}
          </NcButton>
        </div>

        <!-- Empty State -->
        <div v-if="fields.length === 0 && !searchQuery" class="empty-content">
          <FolderIcon :size="64" class="empty-icon" />
          <h4>{{ t('metavox', 'No metadata fields configured') }}</h4>
          <p>{{ t('metavox', 'Add your first metadata field to get started organizing your team folders.') }}</p>
          <NcButton @click="showAddForm = true" type="primary">
            {{ t('metavox', 'Add First Field') }}
          </NcButton>
        </div>

        <!-- Fields List -->
        <div v-else-if="(!searchQuery && fields.length > 0) || (searchQuery && filteredFields.length > 0)" class="fields-list">
          <div v-for="field in filteredFields" :key="field.id" class="field-item">
            
            <div class="field-main">
              <div class="field-icon">
                <component :is="getFieldIcon(field.field_type || field.type)" :size="24" />
              </div>
              
              <div class="field-content">
                <div class="field-header">
                  <h4 class="field-name">{{ getDisplayName(field) }}</h4>
                  <div class="field-badges">
                    <span class="field-type-badge">{{ getFieldTypeLabel(field.field_type || field.type) }}</span>
                    <span v-if="field.is_required || field.required" class="required-badge">{{ t('metavox', 'Required') }}</span>
                  </div>
                </div>
                
                <p class="field-internal-name">
                  <strong>{{ t('metavox', 'Internal Name:') }}</strong> {{ field.field_name || field.name }}
                </p>
                
                <p v-if="field.field_description || field.description" class="field-description">
                  <strong>{{ t('metavox', 'Description:') }}</strong> {{ field.field_description || field.description }}
                </p>
                
                <div v-if="hasFieldOptions(field)" class="field-options">
                  <strong>{{ t('metavox', 'Options:') }}</strong>
                  <span class="options-preview">{{ formatFieldOptions(field.field_options || field.options) }}</span>
                </div>
                
                <div class="field-meta">
                  <span class="field-usage">
                    {{ t('metavox', 'Used in {count} team folders', { count: getUsageCount(field) }) }}
                  </span>
                </div>
              </div>
              
              <div class="field-actions">
                <NcActions :force-menu="true">
                  <NcActionButton @click="editField(field)">
                    <template #icon>
                      <EditIcon :size="20" />
                    </template>
                    {{ t('metavox', 'Edit field') }}
                  </NcActionButton>
                  
                  <NcActionButton 
                    @click="deleteField(field.id)" 
                    :disabled="getUsageCount(field) > 0 || deleting === field.id">
                    <template #icon>
                      <div v-if="deleting === field.id" class="icon-loading-small"></div>
                      <DeleteIcon v-else :size="20" />
                    </template>
                    {{ getUsageCount(field) > 0 ? t('metavox', 'Cannot delete (in use)') : t('metavox', 'Delete field') }}
                  </NcActionButton>
                </NcActions>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Edit Field Modal -->
    <NcModal 
      v-if="editingField"
      @close="cancelEdit"
      :name="t('metavox', 'Edit Field: {name}', { name: getDisplayName(editingField) })"
      :can-close="!saving"
      size="normal">
      
      <div class="modal-content">
        <div class="modal-form">
          <!-- Field Name (Internal) -->
          <div class="form-row">
            <NcTextField
              :value="editData.name"
              @update:value="editData.name = $event"
              :label="t('metavox', 'Internal Field Name')"
              :placeholder="t('metavox', 'e.g., department, project_type, priority')"
              :helper-text="t('metavox', 'Internal identifier used in the system')"
              required
              :disabled="getUsageCount(editingField) > 0" />
            
            <p v-if="getUsageCount(editingField) > 0" class="field-help-text warning">
              {{ t('metavox', 'Internal name cannot be changed when field is in use') }}
            </p>
          </div>

          <!-- Field Label (Display Name) -->
          <div class="form-row">
            <NcTextField
              :value="editData.label"
              @update:value="editData.label = $event"
              :label="t('metavox', 'Display Name')"
              :placeholder="t('metavox', 'e.g., Department, Project Type, Priority')"
              :helper-text="t('metavox', 'Name shown to users')"
              required />
          </div>

          <!-- Description -->
          <div class="form-row">
            <NcTextArea
              :value="editData.description"
              @update:value="editData.description = $event"
              :label="t('metavox', 'Description')"
              :rows="3" />
          </div>

          <!-- Required Field -->
          <div class="form-row">
            <NcCheckboxRadioSwitch
              :checked="editData.required"
              @update:checked="editData.required = $event"
              type="checkbox"
              :disabled="getUsageCount(editingField) > 0">
              {{ t('metavox', 'Required field') }}
            </NcCheckboxRadioSwitch>
          </div>
          
          <!-- Edit Options for select/multiselect fields -->
          <div v-if="(editingField.field_type || editingField.type) === 'select' || (editingField.field_type || editingField.type) === 'multiselect'" class="form-row">
            <label class="field-label">{{ t('metavox', 'Dropdown Options') }}</label>
            <div class="options-container">
              <div v-for="(option, index) in editData.options" :key="index" class="option-row">
                <NcTextField
                  :value="option.value"
                  @update:value="option.value = $event"
                  :placeholder="t('metavox', 'Option value')" />
                <NcButton
                  type="tertiary"
                  @click="removeEditOption(index)"
                  :aria-label="t('metavox', 'Remove option')">
                  <template #icon>
                    <CloseIcon :size="20" />
                  </template>
                </NcButton>
              </div>
              <NcButton 
                @click="addEditOption" 
                type="secondary">
                <template #icon>
                  <PlusIcon :size="20" />
                </template>
                {{ t('metavox', 'Add Option') }}
              </NcButton>
            </div>
          </div>
          
          <div v-if="getUsageCount(editingField) > 0" class="warning-box">
            <p>
              <strong>{{ t('metavox', 'Warning:') }}</strong> 
              {{ t('metavox', 'This field is used in {count} team folders. Some changes may be restricted.', { count: getUsageCount(editingField) }) }}
            </p>
          </div>
        </div>
        
        <div class="modal-actions">
          <NcButton 
            @click="saveEdit" 
            type="primary" 
            :disabled="saving || !isEditFormValid">
            <template #icon>
              <div v-if="saving" class="icon-loading-small"></div>
              <CheckIcon v-else :size="20" />
            </template>
            {{ saving ? t('metavox', 'Saving...') : t('metavox', 'Save Changes') }}
          </NcButton>
          <NcButton @click="cancelEdit" type="secondary" :disabled="saving">
            {{ t('metavox', 'Cancel') }}
          </NcButton>
        </div>
      </div>
    </NcModal>

    <!-- Import Preview Modal -->
    <NcModal 
      v-if="importPreviewData"
      @close="cancelImportPreview"
      :name="t('metavox', 'Import Preview')"
      size="large">
      
      <div class="modal-content">
        <div class="import-preview-header">
          <h3>{{ t('metavox', 'Import Preview') }}</h3>
          <p>{{ t('metavox', 'Found {count} fields to import', { count: importPreviewData.length }) }}</p>
        </div>
        
        <div class="import-preview-list">
          <div v-for="(field, index) in importPreviewData" :key="index" class="import-preview-item">
            <div class="preview-field-icon">
              <component :is="getFieldIcon(field.field_type || 'text')" :size="20" />
            </div>
            <div class="preview-field-info">
              <h4>{{ field.field_label || field.name || t('metavox', 'Unnamed Field') }}</h4>
              <p>
                <strong>{{ t('metavox', 'Internal Name:') }}</strong> {{ field.field_name || field.name }}
              </p>
              <p>
                <span class="preview-field-type">{{ getFieldTypeLabel(field.field_type) }}</span>
                <span v-if="field.is_required" class="preview-required-badge">{{ t('metavox', 'Required') }}</span>
              </p>
              <p v-if="field.field_description" class="preview-description">
                <strong>{{ t('metavox', 'Description:') }}</strong> {{ field.field_description }}
              </p>
            </div>
          </div>
        </div>
        
        <div class="modal-actions">
          <NcButton 
            @click="confirmImport" 
            type="primary"
            :disabled="importing">
            <template #icon>
              <div v-if="importing" class="icon-loading-small"></div>
              <ImportIcon v-else :size="20" />
            </template>
            {{ importing ? t('metavox', 'Importing...') : t('metavox', 'Confirm Import') }}
          </NcButton>
          <NcButton 
            @click="cancelImportPreview" 
            type="secondary"
            :disabled="importing">
            {{ t('metavox', 'Cancel') }}
          </NcButton>
        </div>
      </div>
    </NcModal>
  </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

// Simple notification helpers
const showSuccess = (message) => {
  if (typeof OC !== 'undefined' && OC.Notification) {
    OC.Notification.showTemporary(message)
  } else {
    console.log('SUCCESS:', message)
  }
}

const showError = (message) => {
  if (typeof OC !== 'undefined' && OC.Notification) {
    OC.Notification.showTemporary(message)
  } else {
    console.error('ERROR:', message)
  }
}

// Stable Nextcloud Vue 7.12.0 components
import { 
  NcButton, 
  NcTextField, 
  NcTextArea, 
  NcSelect, 
  NcCheckboxRadioSwitch, 
  NcModal, 
  NcActions, 
  NcActionButton
} from '@nextcloud/vue'

// Icons
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import EditIcon from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import TextIcon from 'vue-material-design-icons/Text.vue'
import NumericIcon from 'vue-material-design-icons/Numeric.vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import MenuDownIcon from 'vue-material-design-icons/MenuDown.vue'
import CheckboxIcon from 'vue-material-design-icons/CheckboxMarked.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import UploadIcon from 'vue-material-design-icons/Upload.vue'
import ImportIcon from 'vue-material-design-icons/Import.vue'
import ExportIcon from 'vue-material-design-icons/Export.vue'

export default {
  name: 'GroupfolderMetadataFields',
  
  components: {
    NcButton,
    NcTextField,
    NcTextArea,
    NcSelect,
    NcCheckboxRadioSwitch,
    NcModal,
    NcActions,
    NcActionButton,
    PlusIcon,
    CloseIcon,
    EditIcon,
    DeleteIcon,
    FolderIcon,
    TextIcon,
    NumericIcon,
    CalendarIcon,
    MenuDownIcon,
    CheckboxIcon,
    CheckIcon,
    MagnifyIcon,
    UploadIcon,
    ImportIcon,
    ExportIcon,
  },
  
  data() {
    return {
      loading: false,
      saving: false,
      deleting: null,
      importing: false,
      exporting: false,
      showAddForm: false,
      editingField: null,
      fields: [],
      searchQuery: '',
      debugMode: false,
      selectedFile: null,
      importPreviewData: null,
      
      // Form data
      formData: {
        name: '',
        label: '',
        type: 'text',
        description: '',
        required: false,
        options: []
      },
      
      // Selected field type object for NcSelect
      selectedFieldType: null,
      
      // Edit data
      editData: {
        name: '',
        label: '',
        description: '',
        required: false,
        options: []
      }
    }
  },
  
  computed: {
    fieldsCount() {
      const count = this.fields.length
      return this.t('metavox', '{count} fields configured', { count })
    },
    
    filteredFieldsCount() {
      const count = this.filteredFields.length
      return this.searchQuery 
        ? this.t('metavox', '{count} fields found', { count })
        : this.t('metavox', '{count} fields configured', { count })
    },
    
    // Filter fields based on search query
    filteredFields() {
      if (!this.searchQuery.trim()) {
        return this.fields
      }
      
      const query = this.searchQuery.toLowerCase().trim()
      return this.fields.filter(field => {
        const displayName = this.getDisplayName(field).toLowerCase()
        const internalName = (field.field_name || field.name || '').toLowerCase()
        const description = (field.field_description || field.description || '').toLowerCase()
        const fieldType = this.getFieldTypeLabel(field.field_type || field.type).toLowerCase()
        
        return displayName.includes(query) || 
               internalName.includes(query) ||
               description.includes(query) || 
               fieldType.includes(query)
      })
    },
    
    // Field type options for NcSelect
    selectOptions() {
      return [
        { id: 'text', label: this.t('metavox', 'Text') },
        { id: 'textarea', label: this.t('metavox', 'Long Text') },
        { id: 'number', label: this.t('metavox', 'Number') },
        { id: 'date', label: this.t('metavox', 'Date') },
        { id: 'select', label: this.t('metavox', 'Dropdown') },
        { id: 'multiselect', label: this.t('metavox', 'Multi-select') },
        { id: 'checkbox', label: this.t('metavox', 'Checkbox') }
      ]
    },
    
    showOptionsField() {
      return this.formData.type === 'select' || this.formData.type === 'multiselect'
    },
    
    isFormValid() {
      try {
        const hasName = String(this.formData.name || '').trim().length > 0
        const hasLabel = String(this.formData.label || '').trim().length > 0
        const hasValidOptions = !this.showOptionsField || 
                               (this.formData.options && this.formData.options.some(o => String(o.value || '').trim().length > 0))
        
        return hasName && hasLabel && hasValidOptions
      } catch (error) {
        console.error('Error in isFormValid:', error)
        return false
      }
    },
    
    isEditFormValid() {
      try {
        const hasName = String(this.editData.name || '').trim().length > 0
        const hasLabel = String(this.editData.label || '').trim().length > 0
        const hasValidOptions = !(this.editingField?.field_type === 'select' || this.editingField?.field_type === 'multiselect') || 
                               (this.editData.options && this.editData.options.some(o => String(o.value || '').trim().length > 0))
        
        return hasName && hasLabel && hasValidOptions
      } catch (error) {
        console.error('Error in isEditFormValid:', error)
        return false
      }
    }
  },
  
  watch: {
    'formData.type'(newType) {
      // Initialize options array for select/multiselect fields
      if (this.showOptionsField && (!this.formData.options || this.formData.options.length === 0)) {
        this.formData.options = [{ value: '' }]
      }
    }
  },
  
  mounted() {
    this.loadFields()
    // Initialize the selected field type
    this.selectedFieldType = this.selectOptions.find(opt => opt.id === 'text')
  },
  
  methods: {
    // Handle field type selection
    onFieldTypeChange(selectedOption) {
      this.selectedFieldType = selectedOption
      this.formData.type = selectedOption ? selectedOption.id : 'text'
    },

    async loadFields() {
      this.loading = true
      try {
        const response = await axios.get(generateUrl('/apps/metavox/api/groupfolder-fields'))
        // Filter for groupfolder metadata fields (applies_to_groupfolder = 1)
        this.fields = (response.data || []).filter(field => 
          field.applies_to_groupfolder === 1 || field.applies_to_groupfolder === '1'
        )
      } catch (error) {
        console.error('Failed to load metadata fields:', error)
        showError(this.t('metavox', 'Failed to load metadata fields'))
        this.fields = []
      } finally {
        this.loading = false
      }
    },

    async addField() {
      const fieldName = String(this.formData.name || '').trim()
      const fieldLabel = String(this.formData.label || '').trim()
      
      if (!fieldName) {
        showError(this.t('metavox', 'Please enter a field name'))
        return
      }
      
      if (!fieldLabel) {
        showError(this.t('metavox', 'Please enter a field label'))
        return
      }
      
      if (this.showOptionsField && (!this.formData.options || !this.formData.options.some(o => String(o.value || '').trim()))) {
        showError(this.t('metavox', 'Please add at least one option for dropdown fields'))
        return
      }
      
      this.saving = true
      
      let finalFieldName = fieldName
      if (finalFieldName && !finalFieldName.startsWith('gf_')) {
        finalFieldName = 'gf_' + finalFieldName
      }
      
      const fieldData = {
        field_name: finalFieldName,
        field_label: fieldLabel,
        field_type: this.formData.type,
        field_description: String(this.formData.description || '').trim(),
        is_required: this.formData.required ? 1 : 0,
        field_options: this.formData.options
          .filter(o => String(o.value || '').trim().length > 0)
          .map(o => String(o.value).trim())
          .join('\n'),
        sort_order: 0,
        applies_to_groupfolder: 1
      }
      
      try {
        const response = await axios.post(generateUrl('/apps/metavox/api/groupfolder-fields'), fieldData)
        
        // Reload fields to ensure consistency
        await this.loadFields()
        
        this.resetForm()
        this.showAddForm = false
        showSuccess(this.t('metavox', 'Field "{name}" added successfully', { name: fieldLabel }))
      } catch (error) {
        console.error('Failed to add field:', error)
        showError(this.t('metavox', 'Failed to add field'))
      } finally {
        this.saving = false
      }
    },
    
    async deleteField(fieldId) {
      this.deleting = fieldId
      
      try {
        await axios.delete(generateUrl(`/apps/metavox/api/fields/${fieldId}`))
        this.fields = this.fields.filter(f => f.id !== fieldId)
        showSuccess(this.t('metavox', 'Field deleted successfully'))
      } catch (error) {
        console.error('Failed to delete field:', error)
        if (error.response && error.response.status === 409) {
          showError(this.t('metavox', 'Cannot delete field - it is currently in use'))
        } else {
          showError(this.t('metavox', 'Failed to delete field'))
        }
      } finally {
        this.deleting = null
      }
    },
    
    editField(field) {
      this.editingField = field
      this.editData = {
        name: field.field_name || field.name,
        label: field.field_label || this.getDisplayName(field),
        description: field.field_description || field.description || '',
        required: !!(field.is_required || field.required),
        options: this.parseFieldOptions(field.field_options || field.options)
      }
    },
    
    async saveEdit() {
      if (!this.editingField || !this.isEditFormValid) return
      
      this.saving = true
      
      // Prepare the updated field data
      const fieldData = {
        field_name: String(this.editData.name || '').trim(),
        field_label: String(this.editData.label || '').trim(),
        field_type: this.editingField.field_type || this.editingField.type,
        field_description: String(this.editData.description || '').trim(),
        is_required: this.editData.required ? 1 : 0,
        field_options: this.editData.options
          ? this.editData.options
              .filter(o => String(o.value || '').trim().length > 0)
              .map(o => String(o.value).trim())
          : []
      }
      
      // Add gf_ prefix if missing
      if (fieldData.field_name && !fieldData.field_name.startsWith('gf_')) {
        fieldData.field_name = 'gf_' + fieldData.field_name
      }
      
      try {
        await axios.put(generateUrl(`/apps/metavox/api/fields/${this.editingField.id}`), fieldData)
        
        // Reload fields to ensure consistency
        await this.loadFields()
        
        this.cancelEdit()
        showSuccess(this.t('metavox', 'Field updated successfully'))
      } catch (error) {
        console.error('Failed to update field:', error)
        showError(this.t('metavox', 'Failed to update field'))
      } finally {
        this.saving = false
      }
    },
    
    cancelEdit() {
      this.editingField = null
      this.editData = {
        name: '',
        label: '',
        description: '',
        required: false,
        options: []
      }
    },
    
    addOption() {
      this.formData.options.push({ value: '' })
    },
    
    removeOption(index) {
      this.formData.options.splice(index, 1)
    },
    
    addEditOption() {
      if (!this.editData.options) {
        this.editData.options = []
      }
      this.editData.options.push({ value: '' })
    },
    
    removeEditOption(index) {
      this.editData.options.splice(index, 1)
    },
    
    cancelAdd() {
      this.resetForm()
      this.showAddForm = false
    },
    
    resetForm() {
      this.formData = {
        name: '',
        label: '',
        type: 'text',
        description: '',
        required: false,
        options: []
      }
      this.selectedFieldType = this.selectOptions.find(opt => opt.id === 'text')
    },
    
    getFieldTypeLabel(type) {
      const option = this.selectOptions.find(opt => opt.id === type)
      return option ? option.label : type
    },
    
    getFieldIcon(type) {
      const icons = {
        text: 'TextIcon',
        textarea: 'TextIcon',
        number: 'NumericIcon',
        date: 'CalendarIcon',
        select: 'MenuDownIcon',
        multiselect: 'MenuDownIcon',
        checkbox: 'CheckboxIcon'
      }
      return icons[type] || 'TextIcon'
    },
    
    getDisplayName(field) {
      // Prioritize field_label, then fallback logic
      if (field.field_label && field.field_label.trim()) {
        return field.field_label
      }
      
      // If no field_label, try to get from field_name 
      if (field.field_name) {
        // Remove gf_ prefix for display
        if (field.field_name.startsWith('gf_')) {
          return field.field_name.substring(3)
        }
        return field.field_name
      }
      
      // Last fallback
      return field.name || 'Unnamed Field'
    },
    
    getUsageCount(field) {
      // Use usage_count if available, otherwise fall back to usage or 0
      return field.usage_count !== undefined ? field.usage_count : (field.usage || 0)
    },
    
    hasFieldOptions(field) {
      const fieldType = field.field_type || field.type
      return (fieldType === 'select' || fieldType === 'multiselect') && 
             (field.field_options || field.options) && 
             (field.field_options || field.options).length > 0
    },
    
    formatFieldOptions(options) {
      if (Array.isArray(options)) {
        return options.map(o => o.value || o).join(', ')
      }
      if (typeof options === 'string') {
        return options.split('\n').filter(o => o.trim()).join(', ')
      }
      return ''
    },
    
    parseFieldOptions(options) {
      if (Array.isArray(options)) {
        return options.map(o => ({ value: o.value || o }))
      }
      if (typeof options === 'string') {
        return options.split('\n').filter(o => o.trim()).map(o => ({ value: o.trim() }))
      }
      return []
    },
    
    // Import/Export methods
    triggerFileInput() {
      this.$refs.jsonFileInput?.click()
    },
    
    handleFileSelect(event) {
      const files = event.target.files
      if (!files || files.length === 0) {
        this.selectedFile = null
        return
      }
      
      this.selectedFile = files[0]
    },
    
    async importFields() {
      if (!this.selectedFile) {
        showError(this.t('metavox', 'Please select a JSON file to import'))
        return
      }
      
      try {
        const fileContent = await this.readFileAsText(this.selectedFile)
        const parsedData = JSON.parse(fileContent)
        
        // Validate the JSON structure
        if (!Array.isArray(parsedData)) {
          throw new Error(this.t('metavox', 'Invalid JSON format. Expected an array of field definitions.'))
        }
        
        // Show preview before importing
        this.importPreviewData = parsedData
      } catch (error) {
        console.error('Failed to parse JSON file:', error)
        showError(this.t('metavox', 'Failed to parse JSON file: {error}', { error: error.message }))
      }
    },
    
async confirmImport() {
  if (!this.importPreviewData || this.importPreviewData.length === 0) {
    this.cancelImportPreview()
    return
  }
  
  this.importing = true
  let importedCount = 0
  let errorCount = 0
  
  try {
    // Process each field individually (like in working version)
    for (const fieldData of this.importPreviewData) {
      try {
        // Prepare field data for import
        const importData = {
          field_name: fieldData.field_name || fieldData.name,
          field_label: fieldData.field_label || fieldData.label,
          field_type: fieldData.field_type || fieldData.type || 'text',
          field_description: fieldData.field_description || fieldData.description || '',
          is_required: fieldData.is_required || fieldData.required ? 1 : 0,
          field_options: Array.isArray(fieldData.field_options) 
            ? fieldData.field_options.join('\n')
            : (fieldData.field_options || ''),
          sort_order: fieldData.sort_order || 0,
          applies_to_groupfolder: 1
        }
        
        // Add gf_ prefix if missing
        if (importData.field_name && !importData.field_name.startsWith('gf_')) {
          importData.field_name = 'gf_' + importData.field_name
        }
        
        await axios.post(generateUrl('/apps/metavox/api/groupfolder-fields'), importData)
        importedCount++
      } catch (error) {
        console.error('Failed to import field:', error)
        errorCount++
      }
    }
    
    // Reload fields
    await this.loadFields()
    
    // Show results
    if (errorCount === 0) {
      showSuccess(this.t('metavox', 'Successfully imported {count} fields', { count: importedCount }))
    } else {
      showSuccess(this.t('metavox', 'Import completed: {imported} imported, {errors} errors', 
        { imported: importedCount, errors: errorCount }))
    }
    
    // Reset import state
    this.cancelImportPreview()
    this.selectedFile = null
    if (this.$refs.jsonFileInput) {
      this.$refs.jsonFileInput.value = ''
    }
    
  } catch (error) {
    console.error('Import failed:', error)
    showError(this.t('metavox', 'Failed to import fields'))
  } finally {
    this.importing = false
  }
},
    
    cancelImportPreview() {
      this.importPreviewData = null
    },
    
    async exportFields() {
      if (this.fields.length === 0) return
      
      this.exporting = true
      
      try {
        // Prepare data for export
        const exportData = this.fields.map(field => ({
          field_name: field.field_name,
          field_label: field.field_label,
          field_type: field.field_type,
          field_description: field.field_description,
          is_required: field.is_required,
          field_options: field.field_options,
          sort_order: field.sort_order,
          applies_to_groupfolder: field.applies_to_groupfolder
        }))
        
        // Create JSON string
        const jsonString = JSON.stringify(exportData, null, 2)
        
        // Create download link
        const blob = new Blob([jsonString], { type: 'application/json' })
        const url = URL.createObjectURL(blob)
        const link = document.createElement('a')
        link.href = url
        link.download = `teamfolder-metadata-fields-${new Date().toISOString().slice(0, 10)}.json`
        document.body.appendChild(link)
        link.click()
        document.body.removeChild(link)
        URL.revokeObjectURL(url)
        
        showSuccess(this.t('metavox', 'Exported {count} fields', { count: this.fields.length }))
      } catch (error) {
        console.error('Export failed:', error)
        showError(this.t('metavox', 'Failed to export fields'))
      } finally {
        this.exporting = false
      }
    },
    
    readFileAsText(file) {
      return new Promise((resolve, reject) => {
        const reader = new FileReader()
        reader.onload = e => resolve(e.target.result)
        reader.onerror = e => reject(new Error('Failed to read file'))
        reader.readAsText(file)
      })
    },
    
    // Translation helper
    t(app, text, vars) {
      if (typeof OC !== 'undefined' && OC.L10N) {
        return OC.L10N.translate(app, text, vars)
      }
      // Fallback for development
      if (vars) {
        return Object.keys(vars).reduce((result, key) => {
          return result.replace(`{${key}}`, vars[key])
        }, text)
      }
      return text
    }
  }
}
</script>

<style scoped lang="scss">
.groupfolder-metadata {
  padding: 20px;
  max-width: 1200px;
  margin: 0 auto;
}

.loading-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 60px 20px;
  
  .icon-loading {
    margin-bottom: 20px;
  }
}

.page-header {
  margin-bottom: 30px;
  
  h2 {
    margin-bottom: 8px;
    font-weight: bold;
  }
  
  .page-description {
    color: var(--color-text-lighter);
    margin: 0;
  }
}

.settings-section {
  background: var(--color-main-background);
  border-radius: var(--border-radius-large);
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
  margin-bottom: 30px;
  padding: 20px;
  
  .section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--color-border);
    
    h3 {
      margin: 0;
      font-weight: bold;
    }
    
    .field-count {
      background: var(--color-background-dark);
      color: var(--color-text-light);
      padding: 4px 10px;
      border-radius: 12px;
      font-size: 0.9em;
    }
  }
}

.import-export-container {
  .import-export-row {
    display: flex;
    gap: 30px;
    
    @media (max-width: 768px) {
      flex-direction: column;
      gap: 20px;
    }
  }
  
  .import-section,
  .export-section {
    flex: 1;
    
    h4 {
      margin-top: 0;
      margin-bottom: 8px;
    }
    
    p {
      margin-top: 0;
      margin-bottom: 16px;
      color: var(--color-text-lighter);
    }
  }
  
  .file-input-container {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 15px;
    
    .file-input {
      display: none;
    }
    
    .selected-file {
      font-size: 0.9em;
      color: var(--color-text-lighter);
    }
  }
  
  .import-button {
    margin-top: 10px;
  }
}

.add-field-form {
  background: var(--color-background-dark);
  border-radius: var(--border-radius);
  padding: 20px;
  margin-top: 15px;
}

.form-row {
  margin-bottom: 20px;
  
  .field-label {
    display: block;
    margin-bottom: 8px;
    font-weight: bold;
  }
  
  .field-help-text {
    font-size: 0.9em;
    margin-top: 5px;
    
    &.warning {
      color: var(--color-error);
    }
  }
}

.options-container {
  .option-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
    
    .text-field {
      flex: 1;
    }
  }
}

.form-actions {
  display: flex;
  gap: 10px;
  margin-top: 25px;
}

.search-container {
  margin-bottom: 20px;
}

.search-results-info {
  background: var(--color-background-dark);
  padding: 10px 15px;
  border-radius: var(--border-radius);
  margin-bottom: 20px;
  
  p {
    margin: 0;
    font-size: 0.9em;
  }
}

.empty-search,
.empty-content {
  text-align: center;
  padding: 40px 20px;
  color: var(--color-text-lighter);
  
  .empty-icon {
    margin-bottom: 15px;
    opacity: 0.5;
  }
  
  h4 {
    margin-bottom: 10px;
    color: var(--color-text-light);
  }
  
  p {
    margin-bottom: 20px;
  }
}

.fields-list {
  .field-item {
    background: var(--color-background-dark);
    border-radius: var(--border-radius);
    margin-bottom: 15px;
    padding: 15px;
    transition: background-color 0.2s;
    
    &:hover {
      background: var(--color-background-darker);
    }
  }
  
  .field-main {
    display: flex;
    align-items: flex-start;
    gap: 15px;
  }
  
  .field-icon {
    color: var(--color-primary-element);
    padding-top: 4px;
  }
  
  .field-content {
    flex: 1;
    
    .field-header {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 10px;
      margin-bottom: 8px;
      
      .field-name {
        margin: 0;
        font-weight: bold;
      }
      
      .field-badges {
        display: flex;
        gap: 8px;
        
        .field-type-badge,
        .required-badge {
          font-size: 0.75em;
          padding: 2px 8px;
          border-radius: 10px;
        }
        
        .field-type-badge {
          background: var(--color-background-darker);
          color: var(--color-text-light);
        }
        
        .required-badge {
          background: var(--color-error);
          color: white;
        }
      }
    }
    
    .field-internal-name,
    .field-description,
    .field-options {
      margin: 5px 0;
      font-size: 0.9em;
    }
    
    .options-preview {
      color: var(--color-text-lighter);
    }
    
    .field-meta {
      margin-top: 10px;
      
      .field-usage {
        font-size: 0.85em;
        color: var(--color-text-lighter);
      }
    }
  }
  
  .field-actions {
    flex-shrink: 0;
  }
}

.modal-content {
  padding: 20px;
  
  .modal-form {
    margin-bottom: 25px;
  }
  
  .warning-box {
    background: var(--color-warning);
    color: var(--color-warning-text);
    padding: 15px;
    border-radius: var(--border-radius);
    margin-top: 20px;
  }
}

.modal-actions {
  display: flex;
  gap: 10px;
  justify-content: flex-end;
}

.import-preview-header {
  margin-bottom: 20px;
  
  h3 {
    margin-bottom: 5px;
  }
  
  p {
    margin: 0;
    color: var(--color-text-lighter);
  }
}

.import-preview-list {
  max-height: 300px;
  overflow-y: auto;
  margin-bottom: 25px;
  
  .import-preview-item {
    display: flex;
    align-items: flex-start;
    gap: 15px;
    padding: 15px;
    border-bottom: 1px solid var(--color-border);
    
    &:last-child {
      border-bottom: none;
    }
  }
  
  .preview-field-icon {
    color: var(--color-primary-element);
    padding-top: 2px;
  }
  
  .preview-field-info {
    flex: 1;
    
    h4 {
      margin: 0 0 8px 0;
    }
    
    p {
      margin: 5px 0;
      font-size: 0.9em;
    }
    
    .preview-field-type {
      background: var(--color-background-darker);
      color: var(--color-text-light);
      padding: 2px 8px;
      border-radius: 10px;
      font-size: 0.8em;
      margin-right: 8px;
    }
    
    .preview-required-badge {
      background: var(--color-error);
      color: white;
      padding: 2px 8px;
      border-radius: 10px;
      font-size: 0.8em;
    }
    
    .preview-description {
      color: var(--color-text-lighter);
    }
  }
}

.debug-info {
  margin-top: 30px;
  padding: 15px;
  background: var(--color-background-darker);
  border-radius: var(--border-radius);
  font-family: monospace;
  font-size: 0.85em;
  
  h4 {
    margin-top: 0;
  }
}

.icon-loading-small {
  display: inline-block;
  width: 16px;
  height: 16px;
  background-image: var(--icon-loading-small);
  background-size: contain;
  animation: rotate 1s linear infinite;
}

@keyframes rotate {
  from {
    transform: rotate(0deg);
  }
  to {
    transform: rotate(360deg);
  }
}
</style>