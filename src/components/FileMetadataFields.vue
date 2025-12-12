<template>
  <div class="file-metadata">
    <!-- Loading state -->
    <div v-if="loading" class="loading-container">
      <div class="icon-loading"></div>
      <p>{{ t('metavox', 'Loading metadata fields...') }}</p>
    </div>

    <div v-else>
      <!-- Page Header -->
      <div class="page-header">
        <h2>{{ t('metavox', 'File Metadata Fields') }}</h2>
        <p class="page-description">
          {{ t('metavox', 'Configure custom metadata fields that will be available for files within team folders. These fields help organize and categorize your files.') }}
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
              :placeholder="t('metavox', 'e.g., author, document_type, status')"
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
              :placeholder="t('metavox', 'e.g., Author, Document Type, Status')"
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
            <NcTextField
              :value="formData.description"
              @update:value="formData.description = $event"
              :label="t('metavox', 'Description')"
              :placeholder="t('metavox', 'Optional description for this field')"
              :helper-text="t('metavox', 'Description')"
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
              <div v-for="(option, index) in formData.options" :key="`option-add-${index}`" class="option-row">
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
          <FileIcon :size="64" class="empty-icon" />
          <h4>{{ t('metavox', 'No metadata fields configured') }}</h4>
          <p>{{ t('metavox', 'Add your first metadata field to get started organizing your files.') }}</p>
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
              :placeholder="t('metavox', 'e.g., author, document_type, status')"
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
              :placeholder="t('metavox', 'e.g., Author, Document Type, Status')"
              :helper-text="t('metavox', 'Name shown to users')"
              required />
          </div>

          <!-- Description -->
          <div class="form-row">
            <NcTextField
              :value="editData.description"
              @update:value="editData.description = $event"
              :helper-text="t('metavox', 'Description')"
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
              <div v-for="(option, index) in editData.options" :key="`option-edit-${index}`" class="option-row">
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
          <div v-for="(field, index) in importPreviewData" :key="field.field_name || field.name || `import-${index}`" class="import-preview-item">
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
  }
}

const showError = (message) => {
  if (typeof OC !== 'undefined' && OC.Notification) {
    OC.Notification.showTemporary(message)
  }
}

// Stable Nextcloud Vue 7.12.0 components
import { 
  NcButton, 
  NcTextField, 
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
import FileIcon from 'vue-material-design-icons/File.vue'
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
  name: 'FileMetadataFields',
  
  components: {
    NcButton,
    NcTextField,
    NcSelect,
    NcCheckboxRadioSwitch,
    NcModal,
    NcActions,
    NcActionButton,
    PlusIcon,
    CloseIcon,
    EditIcon,
    DeleteIcon,
    FileIcon,
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
    
    // NIEUWE: Cache properties
    usageCountsCache: null,
    usageCountsExpiry: null,
    
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

 // Vervang loadFields met deze simpele versie die altijd werkt:

async loadFields() {
  this.loading = true
  try {
    const response = await axios.get(generateUrl('/apps/metavox/api/groupfolder-fields'))
    
    // Filter voor file metadata fields (applies_to_groupfolder = 0)
    const fields = (response.data || []).filter(field => 
      field.applies_to_groupfolder === 0 || 
      field.applies_to_groupfolder === '0' || 
      !field.applies_to_groupfolder
    )
    
    // Zet alle usage_count eerst op 0 (zo zijn ze altijd zichtbaar)
    fields.forEach(field => {
      field.usage_count = 0
    })
    
    // Toon de velden METEEN
    this.fields = fields
    
    // Laad usage counts in de achtergrond (non-blocking, met cache)
    this.loadFieldUsageCountsInBackground()
    
  } catch (error) {
    console.error('Failed to load metadata fields:', error)
    showError(this.t('metavox', 'Failed to load metadata fields'))
    this.fields = []
  } finally {
    this.loading = false
  }
},



// Nieuwe methode om usage counts in achtergrond te laden
async loadFieldUsageCountsInBackground() {
  try {
    // Check cache (5 minuten geldig)
    const now = Date.now()
    if (this.usageCountsCache && this.usageCountsExpiry && this.usageCountsExpiry > now) {
      this.applyUsageCountsFromCache(this.usageCountsCache)
      return
    }
    
    // Haal alle groupfolders op
    const groupfoldersResponse = await axios.get(generateUrl('/apps/metavox/api/groupfolders'))
    const groupfolders = groupfoldersResponse.data || []
    
    if (groupfolders.length === 0) {
      // Cache lege resultaat ook
      this.usageCountsCache = new Map()
      this.usageCountsExpiry = now + (15 * 60 * 1000) // 15 min
      return
    }
    
    // Maak een map om usage bij te houden
    const usageMap = new Map()
    this.fields.forEach(field => usageMap.set(field.id, 0))
    
    // Laad alle field assignments parallel
    const assignmentPromises = groupfolders.map(async (groupfolder) => {
      try {
        const response = await axios.get(
          generateUrl(`/apps/metavox/api/groupfolders/${groupfolder.id}/fields`)
        )
        return response.data || []
      } catch (error) {
        console.error(`Failed to load assignments for groupfolder ${groupfolder.id}:`, error)
        return [] // Fail gracefully
      }
    })
    
    const allAssignments = await Promise.all(assignmentPromises)
    
    // Tel usage
    allAssignments.forEach(fieldIds => {
      fieldIds.forEach(fieldId => {
        if (usageMap.has(fieldId)) {
          usageMap.set(fieldId, usageMap.get(fieldId) + 1)
        }
      })
    })
    
    // Cache het resultaat (5 minuten geldig)
    this.usageCountsCache = usageMap
    this.usageCountsExpiry = now + (5 * 60 * 1000) // 5 minuten

    // Pas de counts toe
    this.applyUsageCountsFromCache(usageMap)

  } catch (error) {
    console.error('Failed to load usage counts in background:', error)
    // Bij error, cache niet updaten maar wel oude cache gebruiken als beschikbaar
    if (this.usageCountsCache && this.usageCountsExpiry > Date.now()) {
      this.applyUsageCountsFromCache(this.usageCountsCache)
    }
  }
},

applyUsageCountsFromCache(usageMap) {
  // Update usage counts in de bestaande fields array
  this.fields.forEach(field => {
    field.usage_count = usageMap.get(field.id) || 0
  })
  
  // Force reactivity update
  this.fields = [...this.fields]
},

// 5. Nieuwe methode om cache te invalideren:
invalidateUsageCountsCache() {
  this.usageCountsCache = null
  this.usageCountsExpiry = null
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
  if (finalFieldName && !finalFieldName.startsWith('file_')) {
    finalFieldName = 'file_gf_' + finalFieldName
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
    applies_to_groupfolder: 0
  }
  
  try {
    const response = await axios.post(generateUrl('/apps/metavox/api/groupfolder-fields'), fieldData)
    
    // Invalideer cache omdat er een nieuw field is
    this.invalidateUsageCountsCache()
    
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
    await axios.delete(generateUrl(`/apps/metavox/api/groupfolder-fields/${fieldId}`))
    
    // Invalideer cache omdat een field is verwijderd
    this.invalidateUsageCountsCache()
    
    // Reload all fields to refresh usage counts
    await this.loadFields()
    
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
  
  // Add file_ prefix if missing
  if (fieldData.field_name && !fieldData.field_name.startsWith('file_')) {
    fieldData.field_name = 'file_gf_' + fieldData.field_name
  }
  
  try {
    await axios.put(generateUrl(`/apps/metavox/api/groupfolder-fields/${this.editingField.id}`), fieldData)
    
    // Invalideer cache omdat field is gewijzigd (labels kunnen zijn veranderd)
    this.invalidateUsageCountsCache()
    
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
        // Remove file_ prefix for display
        if (field.field_name.startsWith('file_')) {
          return field.field_name.substring(5)
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
             (field.field_options || field.options)
    },
    
    parseFieldOptions(options) {
      if (!options) return []
      if (Array.isArray(options)) {
        return options.map(opt => ({ value: opt.value || opt }))
      }
      if (typeof options === 'string') {
        return options.split('\n').filter(opt => opt.trim()).map(opt => ({ value: opt.trim() }))
      }
      return []
    },
    
    formatFieldOptions(options) {
      if (!options) return ''
      if (Array.isArray(options)) {
        return options.map(o => o.value || o).join(', ')
      }
      if (typeof options === 'string') {
        return options.replace(/\n/g, ', ')
      }
      return options.toString()
    },

    // JSON Import/Export Methods
    triggerFileInput() {
      this.$refs.jsonFileInput.click()
    },

    handleFileSelect(event) {
      const file = event.target.files[0]
      if (!file) return
      
      this.selectedFile = file
      
      // Read and validate the file
      const reader = new FileReader()
      reader.onload = (e) => {
        try {
          const jsonData = JSON.parse(e.target.result)
          
          // Validate JSON structure
          if (!Array.isArray(jsonData)) {
            throw new Error(this.t('metavox', 'JSON must be an array of field definitions'))
          }
          
          // Show preview
          this.importPreviewData = jsonData
        } catch (error) {
          console.error('JSON parse error:', error)
          showError(this.t('metavox', 'Invalid JSON file: {error}', { error: error.message }))
          this.selectedFile = null
        }
      }
      reader.readAsText(file)
    },

async confirmImport() {
  if (!this.importPreviewData || this.importPreviewData.length === 0) {
    showError(this.t('metavox', 'No fields to import'))
    return
  }
  
  this.importing = true
  let importedCount = 0
  let errorCount = 0
  
  try {
    // Process each field individually
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
          applies_to_groupfolder: 0
        }
        
        // Add file_gf_ prefix if missing
        if (importData.field_name && !importData.field_name.startsWith('file_gf_')) {
          importData.field_name = 'file_gf_' + importData.field_name
        }
        
        await axios.post(generateUrl('/apps/metavox/api/groupfolder-fields'), importData)
        importedCount++
      } catch (error) {
        console.error('Failed to import field:', error)
        errorCount++
      }
    }
    
    // Invalideer cache omdat nieuwe fields zijn geïmporteerd
    this.invalidateUsageCountsCache()
    
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
    showError(this.t('metavox', 'Failed to import fields: {error}', { 
      error: error.response?.data?.message || error.message 
    }))
  } finally {
    this.importing = false
  }
},

    cancelImportPreview() {
      this.importPreviewData = null
      this.selectedFile = null
      if (this.$refs.jsonFileInput) {
        this.$refs.jsonFileInput.value = ''
      }
    },

async exportFields() {
  if (this.fields.length === 0) return
  
  this.exporting = true
  
  try {
    // Prepare data for export
    const exportData = this.fields.map(field => {
      // Remove file_gf_ prefix from field_name for export
      let exportFieldName = field.field_name
      if (exportFieldName && exportFieldName.startsWith('file_gf_')) {
        exportFieldName = exportFieldName.substring(8)
      }
      
      return {
        field_name: exportFieldName,
        field_label: field.field_label,
        field_type: field.field_type,
        field_description: field.field_description,
        is_required: field.is_required,
        field_options: field.field_options,
        sort_order: field.sort_order,
        applies_to_groupfolder: field.applies_to_groupfolder
      }
    })
    
    // Create JSON string
    const jsonString = JSON.stringify(exportData, null, 2)
    
    // Create download link
    const blob = new Blob([jsonString], { type: 'application/json' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `file-metadata-fields-${new Date().toISOString().slice(0, 10)}.json`
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

<style scoped>
.file-metadata {
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
  text-align: center;
}

.icon-loading {
  display: inline-block;
  width: 44px;
  height: 44px;
  background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="%23888" d="M12,4V2A10,10 0 0,0 2,12H4A8,8 0 0,1 12,4Z"/></svg>');
  background-size: contain;
  animation: rotate 1s linear infinite;
}

.icon-loading-small {
  display: inline-block;
  width: 16px;
  height: 16px;
  background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="%23fff" d="M12,4V2A10,10 0 0,0 2,12H4A8,8 0 0,1 12,4Z"/></svg>');
  background-size: contain;
  animation: rotate 1s linear infinite;
}

@keyframes rotate {
  100% { transform: rotate(360deg); }
}

.page-header {
  margin-bottom: 30px;
}

.page-header h2 {
  margin: 0 0 8px 0;
  font-size: 24px;
  font-weight: bold;
  color: var(--color-main-text);
}

.page-description {
  margin: 0;
  color: var(--color-text-lighter);
  line-height: 1.5;
}

.settings-section {
  background: var(--color-main-background);
  border-radius: var(--border-radius-large);
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
  margin-bottom: 30px;
  padding: 24px;
}

.section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
  border-bottom: 1px solid var(--color-border);
  padding-bottom: 16px;
}

.section-header h3 {
  margin: 0;
  font-size: 18px;
  font-weight: bold;
  color: var(--color-main-text);
}

.field-count {
  background: var(--color-background-darker);
  color: var(--color-text-lighter);
  padding: 4px 12px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: bold;
}

.import-export-container {
  margin-top: 20px;
}

.import-export-row {
  display: flex;
  gap: 30px;
  flex-wrap: wrap;
}

.import-section,
.export-section {
  flex: 1;
  min-width: 300px;
}

.import-section h4,
.export-section h4 {
  margin: 0 0 8px 0;
  font-size: 16px;
  color: var(--color-main-text);
}

.import-description,
.export-description {
  margin: 0 0 16px 0;
  color: var(--color-text-lighter);
  line-height: 1.5;
}

.file-input-container {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-bottom: 16px;
}

.file-input {
  display: none;
}

.selected-file {
  font-size: 14px;
  color: var(--color-text-lighter);
  font-style: italic;
}

.import-button {
  margin-top: 8px;
}

.add-field-form {
  background: var(--color-background-dark);
  border-radius: var(--border-radius);
  padding: 20px;
  margin-top: 16px;
}

.form-row {
  margin-bottom: 20px;
}

.field-label {
  display: block;
  margin-bottom: 8px;
  font-weight: bold;
  color: var(--color-main-text);
}

.field-help-text {
  margin: 8px 0 0 0;
  font-size: 12px;
  color: var(--color-text-lighter);
}

.field-help-text.warning {
  color: var(--color-warning);
}

.options-container {
  background: var(--color-background-darker);
  border-radius: var(--border-radius);
  padding: 16px;
}

.option-row {
  display: flex;
  gap: 8px;
  align-items: center;
  margin-bottom: 12px;
}

.option-row:last-child {
  margin-bottom: 0;
}

.option-row .text-field {
  flex: 1;
}

.form-actions {
  display: flex;
  gap: 12px;
  margin-top: 24px;
  padding-top: 20px;
  border-top: 1px solid var(--color-border);
}

.search-container {
  margin-bottom: 20px;
}

.search-results-info {
  background: var(--color-background-dark);
  padding: 12px 16px;
  border-radius: var(--border-radius);
  margin-bottom: 20px;
}

.search-results-info p {
  margin: 0;
  color: var(--color-text-lighter);
}

.empty-search,
.empty-content {
  text-align: center;
  padding: 40px 20px;
  color: var(--color-text-lighter);
}

.empty-icon {
  margin-bottom: 16px;
  opacity: 0.5;
}

.empty-search h4,
.empty-content h4 {
  margin: 0 0 8px 0;
  color: var(--color-text-lighter);
}

.empty-search p,
.empty-content p {
  margin: 0 0 20px 0;
}

.fields-list {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.field-item {
  background: var(--color-background-dark);
  border-radius: var(--border-radius);
  border: 1px solid var(--color-border);
  overflow: hidden;
}

.field-main {
  display: flex;
  align-items: flex-start;
  padding: 20px;
  gap: 16px;
}

.field-icon {
  flex-shrink: 0;
  margin-top: 4px;
  color: var(--color-primary-element);
}

.field-content {
  flex: 1;
  min-width: 0;
}

.field-header {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 8px;
  flex-wrap: wrap;
}

.field-name {
  margin: 0;
  font-size: 16px;
  font-weight: bold;
  color: var(--color-main-text);
}

.field-badges {
  display: flex;
  gap: 8px;
}

.field-type-badge,
.required-badge {
  padding: 2px 8px;
  border-radius: 10px;
  font-size: 11px;
  font-weight: bold;
}

.field-type-badge {
  background: var(--color-background-darker);
  color: var(--color-text-lighter);
}

.required-badge {
  background: var(--color-warning);
  color: white;
}

.field-internal-name,
.field-description,
.field-options,
.field-meta {
  margin: 4px 0;
  font-size: 14px;
  color: var(--color-text-lighter);
}

.field-options {
  display: flex;
  align-items: flex-start;
  gap: 8px;
}

.options-preview {
  flex: 1;
  background: var(--color-background-darker);
  padding: 4px 8px;
  border-radius: 4px;
  font-family: monospace;
  font-size: 12px;
  overflow: hidden;
  text-overflow: ellipsis;
}

.field-usage {
  font-size: 12px;
  color: var(--color-text-lighter);
}

.field-actions {
  flex-shrink: 0;
}

.modal-content {
  padding: 20px;
}

.modal-form {
  margin-bottom: 24px;
}

.warning-box {
  background: var(--color-warning-light);
  border: 1px solid var(--color-warning);
  border-radius: var(--border-radius);
  padding: 16px;
  margin-top: 20px;
}

.warning-box p {
  margin: 0;
  color: var(--color-text-lighter);
}

.warning-box strong {
  color: var(--color-warning);
}

.modal-actions {
  display: flex;
  gap: 12px;
  justify-content: flex-end;
  border-top: 1px solid var(--color-border);
  padding-top: 20px;
}

.import-preview-header {
  margin-bottom: 20px;
  text-align: center;
}

.import-preview-header h3 {
  margin: 0 0 8px 0;
  font-size: 20px;
  color: var(--color-main-text);
}

.import-preview-header p {
  margin: 0;
  color: var(--color-text-lighter);
}

.import-preview-list {
  max-height: 400px;
  overflow-y: auto;
  margin-bottom: 24px;
}

.import-preview-item {
  display: flex;
  align-items: flex-start;
  gap: 16px;
  padding: 16px;
  border-bottom: 1px solid var(--color-border);
}

.import-preview-item:last-child {
  border-bottom: none;
}

.preview-field-icon {
  flex-shrink: 0;
  margin-top: 4px;
  color: var(--color-primary-element);
}

.preview-field-info {
  flex: 1;
  min-width: 0;
}

.preview-field-info h4 {
  margin: 0 0 8px 0;
  font-size: 16px;
  color: var(--color-main-text);
}

.preview-field-info p {
  margin: 4px 0;
  font-size: 14px;
  color: var(--color-text-lighter);
}

.preview-field-type,
.preview-required-badge {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 10px;
  font-size: 11px;
  font-weight: bold;
  margin-right: 8px;
}

.preview-field-type {
  background: var(--color-background-darker);
  color: var(--color-text-lighter);
}

.preview-required-badge {
  background: var(--color-warning);
  color: white;
}

.preview-description {
  margin-top: 8px !important;
}

.debug-info {
  background: var(--color-background-darker);
  border-radius: var(--border-radius);
  padding: 16px;
  margin-top: 20px;
  font-family: monospace;
  font-size: 12px;
  overflow: auto;
}

.debug-info h4 {
  margin: 0 0 12px 0;
  color: var(--color-text-lighter);
}

.debug-info p {
  margin: 4px 0;
}

.debug-info pre {
  margin: 12px 0 0 0;
  background: rgba(0, 0, 0, 0.1);
  padding: 12px;
  border-radius: 4px;
  overflow: auto;
}

/* Responsive adjustments */
@media (max-width: 768px) {
  .file-metadata {
    padding: 12px;
  }
  
  .settings-section {
    padding: 16px;
  }
  
  .import-export-row {
    flex-direction: column;
    gap: 20px;
  }
  
  .field-main {
    flex-direction: column;
    align-items: stretch;
  }
  
  .field-actions {
    align-self: flex-end;
    margin-top: 16px;
  }
  
  .modal-actions {
    flex-direction: column;
  }
  
  .modal-actions .button {
    width: 100%;
  }
}
</style>