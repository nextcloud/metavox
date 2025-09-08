<template>
  <div class="groupfolder-metadata">
    <!-- Header Section -->
    <div class="section-header">
      <h3>{{ t('metavox', 'Team folder Metadata Fields') }}</h3>
      <p class="section-description">
        {{ t('metavox', 'Configure custom metadata fields that will be available for all team folders. These fields help organize and categorize your team folders.') }}
      </p>
    </div>

    <!-- Loading state -->
    <div v-if="loading" class="loading-state">
      <div class="icon-loading"></div>
      <p>{{ t('metavox', 'Loading metadata fields...') }}</p>
    </div>

    <div v-else>
      <!-- Add New Field Section -->
      <div class="settings-section">
        <div class="section-title">
          <h4>{{ t('metavox', 'Add New Metadata Field') }}</h4>
          <NcButton 
            @click="showAddForm = !showAddForm" 
            type="primary">
            <template #icon>
              <PlusIcon v-if="!showAddForm" />
              <MinusIcon v-else />
            </template>
            {{ showAddForm ? t('metavox', 'Cancel') : t('metavox', 'Add Field') }}
          </NcButton>
        </div>

        <!-- Add Field Form -->
        <div v-if="showAddForm" class="add-form">
          <div class="form-grid">
            <div class="form-group">
              <label>{{ t('metavox', 'Field Name') }}</label>
              <NcInputField
                v-model="newField.name"
                :placeholder="t('metavox', 'e.g., Department, Project Type, Priority')"
                @keydown.enter="addField" />
            </div>

            <div class="form-group">
              <label>{{ t('metavox', 'Field Type') }}</label>
              <NcSelect
                v-model="newField.type"
                :options="fieldTypeOptions"
                :clearable="false" />
            </div>

            <div class="form-group">
              <label>{{ t('metavox', 'Description') }}</label>
              <NcTextArea
                v-model="newField.description"
                :placeholder="t('metavox', 'Optional description for this field')"
                :rows="2" />
            </div>

            <div class="form-group checkbox-group">
              <NcCheckboxRadioSwitch
                v-model="newField.required"
                type="checkbox">
                {{ t('metavox', 'Required field') }}
              </NcCheckboxRadioSwitch>
            </div>
          </div>

          <!-- Options for select/multiselect fields -->
          <div v-if="newField.type === 'select' || newField.type === 'multiselect'" class="options-section">
            <h5>{{ t('metavox', 'Dropdown Options') }}</h5>
            <div class="options-list">
              <div v-for="(option, index) in newField.options" :key="index" class="option-item">
                <NcInputField
                  v-model="option.value"
                  :placeholder="t('metavox', 'Option value')" />
                <NcButton
                  type="error"
                  @click="removeOption(index)"
                  :aria-label="t('metavox', 'Remove option')">
                  <template #icon>
                    <CloseIcon />
                  </template>
                </NcButton>
              </div>
            </div>
            <NcButton @click="addOption" type="secondary">
              <template #icon>
                <PlusIcon />
              </template>
              {{ t('metavox', 'Add Option') }}
            </NcButton>
          </div>

          <!-- Form Actions -->
          <div class="form-actions">
            <NcButton 
              @click="addField" 
              type="primary" 
              :disabled="!canAddField || saving">
              <div v-if="saving" class="icon-loading-small"></div>
              {{ saving ? t('metavox', 'Saving...') : t('metavox', 'Add Field') }}
            </NcButton>
            <NcButton @click="cancelAdd" type="secondary" :disabled="saving">
              {{ t('metavox', 'Cancel') }}
            </NcButton>
          </div>
        </div>
      </div>

      <!-- Existing Fields List -->
      <div class="settings-section fields-section">
        <div class="section-title">
          <h4>{{ t('metavox', 'Existing Metadata Fields') }}</h4>
          <span class="field-count">{{ fieldsCount }}</span>
        </div>

        <!-- Empty State -->
        <div v-if="fields.length === 0" class="empty-state">
          <FolderIcon :size="48" class="empty-icon" />
          <h5>{{ t('metavox', 'No metadata fields configured') }}</h5>
          <p>{{ t('metavox', 'Add your first metadata field to get started organizing your team folders.') }}</p>
          <NcButton @click="showAddForm = true" type="primary">
            {{ t('metavox', 'Add First Field') }}
          </NcButton>
        </div>

        <!-- Fields List -->
        <div v-else class="fields-list">
          <div v-for="field in fields" :key="field.id" class="field-card">
            <div class="field-header">
              <div class="field-info">
                <div class="field-icon">
                  <component :is="getFieldIcon(field.type)" :size="20" />
                </div>
                <div class="field-details">
                  <h5 class="field-name">{{ field.name }}</h5>
                  <div class="field-badges">
                    <span class="field-type-badge">{{ getFieldTypeLabel(field.type) }}</span>
                    <span v-if="field.required" class="required-badge">{{ t('metavox', 'Required') }}</span>
                  </div>
                </div>
              </div>
              <div class="field-actions">
                <NcActions>
                  <NcActionButton @click="editField(field)">
                    <template #icon>
                      <EditIcon :size="20" />
                    </template>
                    {{ t('metavox', 'Edit field') }}
                  </NcActionButton>
                  
                  <NcActionButton 
                    @click="deleteField(field.id)" 
                    :disabled="field.usage > 0 || deleting === field.id">
                    <template #icon>
                      <DeleteIcon v-if="deleting !== field.id" :size="20" />
                      <div v-else class="icon-loading-small"></div>
                    </template>
                    {{ field.usage > 0 ? t('metavox', 'Cannot delete (in use)') : t('metavox', 'Delete field') }}
                  </NcActionButton>
                </NcActions>
              </div>
            </div>
            
            <p v-if="field.description" class="field-description">{{ field.description }}</p>
            
            <!-- Show options for select fields -->
            <div v-if="(field.type === 'select' || field.type === 'multiselect') && field.options && field.options.length > 0" class="field-options">
              <strong>{{ t('metavox', 'Options:') }}</strong>
              <span class="options-preview">{{ field.options.map(o => o.value).join(', ') }}</span>
            </div>

            <div class="field-meta">
              <span class="field-usage">{{ t('metavox', 'Used in {count} team folders', { count: field.usage || 0 }) }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Edit Field Modal -->
    <NcModal 
      v-if="editingField"
      @close="cancelEdit"
      :name="t('metavox', 'Edit Field: {name}', { name: editingField.name })"
      size="normal">
      
      <div class="modal-content">
        <div class="modal-body">
          <div class="form-group">
            <label>{{ t('metavox', 'Field Name') }}</label>
            <NcInputField v-model="editingField.name" />
          </div>
          <div class="form-group">
            <label>{{ t('metavox', 'Description') }}</label>
            <NcTextArea 
              v-model="editingField.description" 
              :rows="3" />
          </div>
          <div class="form-group checkbox-group">
            <NcCheckboxRadioSwitch
              v-model="editingField.required"
              type="checkbox"
              :disabled="editingField.usage > 0">
              {{ t('metavox', 'Required field') }}
            </NcCheckboxRadioSwitch>
          </div>
          <div v-if="editingField.usage > 0" class="usage-warning">
            <p><strong>{{ t('metavox', 'Warning:') }}</strong> {{ t('metavox', 'This field is used in {count} team folders. Changes may affect existing data.', { count: editingField.usage }) }}</p>
          </div>
        </div>
        
        <div class="modal-actions">
          <NcButton 
            @click="saveEdit" 
            type="primary" 
            :disabled="saving">
            <div v-if="saving" class="icon-loading-small"></div>
            {{ saving ? t('metavox', 'Saving...') : t('metavox', 'Save Changes') }}
          </NcButton>
          <NcButton @click="cancelEdit" type="secondary" :disabled="saving">
            {{ t('metavox', 'Cancel') }}
          </NcButton>
        </div>
      </div>
    </NcModal>
  </div>
</template>

<script>
// Required imports for API calls
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

// Simple notification helpers (avoid @nextcloud/dialogs for build compatibility)
const showSuccess = (message) => {
  console.log('SUCCESS:', message)
  // You can replace this with Nextcloud's OC.Notification.showTemporary() later
}

const showError = (message) => {
  console.error('ERROR:', message)
  // You can replace this with Nextcloud's OC.Notification.showTemporary() later
}

// Nextcloud Vue Components
import { 
  NcButton, 
  NcInputField, 
  NcTextArea, 
  NcSelect, 
  NcCheckboxRadioSwitch, 
  NcModal, 
  NcActions, 
  NcActionButton 
} from '@nextcloud/vue'

// Icons
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import MinusIcon from 'vue-material-design-icons/Minus.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import EditIcon from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import TextIcon from 'vue-material-design-icons/Text.vue'
import NumericIcon from 'vue-material-design-icons/Numeric.vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import MenuDownIcon from 'vue-material-design-icons/MenuDown.vue'
import CheckboxIcon from 'vue-material-design-icons/CheckboxMarked.vue'

export default {
  name: 'GroupfolderMetadataFields',
  
  components: {
    NcButton,
    NcInputField,
    NcTextArea,
    NcSelect,
    NcCheckboxRadioSwitch,
    NcModal,
    NcActions,
    NcActionButton,
    PlusIcon,
    MinusIcon,
    CloseIcon,
    EditIcon,
    DeleteIcon,
    FolderIcon,
    TextIcon,
    NumericIcon,
    CalendarIcon,
    MenuDownIcon,
    CheckboxIcon,
  },
  
  data() {
    return {
      loading: false,
      saving: false,
      deleting: null,
      showAddForm: false,
      editingField: null,
      fields: [], // Start with empty array, load from API
      newField: {
        name: '',
        type: 'text',
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
    
    canAddField() {
      return this.newField.name && this.newField.name.trim().length > 0 && 
             (this.newField.type !== 'select' && this.newField.type !== 'multiselect' || 
              this.newField.options.length > 0)
    },
    
    fieldTypeOptions() {
      return [
        { id: 'text', label: this.t('metavox', 'Text') },
        { id: 'textarea', label: this.t('metavox', 'Long Text') },
        { id: 'number', label: this.t('metavox', 'Number') },
        { id: 'date', label: this.t('metavox', 'Date') },
        { id: 'select', label: this.t('metavox', 'Dropdown') },
        { id: 'multiselect', label: this.t('metavox', 'Multi-select') },
        { id: 'checkbox', label: this.t('metavox', 'Checkbox') }
      ]
    }
  },
  
  // Load data when component mounts
  mounted() {
    this.loadFields()
  },
  
  methods: {
    async loadFields() {
      this.loading = true
      try {
        console.log('Loading metadata fields...') // Debug log
        const response = await axios.get(generateUrl('/apps/metavox/api/groupfolder-fields'))
        console.log('API Response:', response.data) // Debug log
        this.fields = response.data || []
      } catch (error) {
        console.error('Failed to load metadata fields:', error)
        console.error('Error response:', error.response) // Extra debug info
        showError(this.t('metavox', 'Failed to load metadata fields'))
        this.fields = []
      } finally {
        this.loading = false
      }
    },

    async addField() {
      if (!this.canAddField) return
      
      this.saving = true
      
      const fieldData = {
        name: this.newField.name.trim(),
        type: this.newField.type,
        description: this.newField.description ? this.newField.description.trim() : '',
        required: this.newField.required,
        options: this.newField.options.filter(o => o.value && o.value.trim().length > 0),
        default_value: null
      }
      
      try {
        console.log('Adding field:', fieldData) // Debug log
        const response = await axios.post(generateUrl('/apps/metavox/api/teamfolder-metadata-definitions'), fieldData)
        console.log('Add response:', response.data) // Debug log
        
        this.fields.push(response.data)
        this.resetNewField()
        this.showAddForm = false
        
        showSuccess(this.t('metavox', 'Field "{name}" added successfully', { name: response.data.name }))
      } catch (error) {
        console.error('Failed to add field:', error)
        console.error('Error response:', error.response) // Extra debug info
        showError(this.t('metavox', 'Failed to add field'))
      } finally {
        this.saving = false
      }
    },
    
    async deleteField(fieldId) {
      this.deleting = fieldId
      
      try {
        console.log('Deleting field:', fieldId) // Debug log
        await axios.delete(generateUrl(`/apps/metavox/api/teamfolder-metadata-definitions/${fieldId}`))
        
        this.fields = this.fields.filter(f => f.id !== fieldId)
        showSuccess(this.t('metavox', 'Field deleted successfully'))
      } catch (error) {
        console.error('Failed to delete field:', error)
        console.error('Error response:', error.response) // Extra debug info
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
      this.editingField = { ...field }
    },
    
    async saveEdit() {
      if (!this.editingField) return
      
      this.saving = true
      
      const fieldData = {
        name: this.editingField.name,
        description: this.editingField.description || '',
        required: this.editingField.required
      }
      
      try {
        console.log('Updating field:', this.editingField.id, fieldData) // Debug log
        const response = await axios.put(
          generateUrl(`/apps/metavox/api/groupfolder-fields/${this.editingField.id}`), 
          fieldData
        )
        console.log('Update response:', response.data) // Debug log
        
        const index = this.fields.findIndex(f => f.id === this.editingField.id)
        if (index !== -1) {
          this.fields[index] = response.data
        }
        
        this.cancelEdit()
        showSuccess(this.t('metavox', 'Field updated successfully'))
      } catch (error) {
        console.error('Failed to update field:', error)
        console.error('Error response:', error.response) // Extra debug info
        showError(this.t('metavox', 'Failed to update field'))
      } finally {
        this.saving = false
      }
    },
    
    cancelEdit() {
      this.editingField = null
    },
    
    addOption() {
      this.newField.options.push({ value: '' })
    },
    
    removeOption(index) {
      this.newField.options.splice(index, 1)
    },
    
    cancelAdd() {
      this.resetNewField()
      this.showAddForm = false
    },
    
    resetNewField() {
      this.newField = {
        name: '',
        type: 'text',
        description: '',
        required: false,
        options: []
      }
    },
    
    getFieldTypeLabel(type) {
      const option = this.fieldTypeOptions.find(opt => opt.id === type)
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
    }
  }
}
</script>

<style lang="scss" scoped>
.groupfolder-metadata {
  max-width: 900px;
  margin: 0 auto;
  padding: 20px;
}

.loading-state {
  text-align: center;
  padding: 60px 20px;
  color: var(--color-text-lighter);
  
  .icon-loading {
    margin-bottom: 20px;
  }
}

.section-header {
  margin-bottom: 40px;
  
  h3 {
    margin: 0 0 10px 0;
    color: var(--color-main-text);
    font-size: 24px;
    font-weight: 300;
  }
  
  .section-description {
    color: var(--color-text-lighter);
    margin: 0;
    line-height: 1.6;
  }
}

.settings-section {
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius-large);
  padding: 24px;
  margin-bottom: 24px;
  
  &.fields-section {
    margin-top: 30px;
  }
  
  .section-title {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    
    h4 {
      margin: 0;
      color: var(--color-main-text);
      font-size: 18px;
      font-weight: 500;
    }
    
    .field-count {
      color: var(--color-text-lighter);
      font-size: 14px;
    }
  }
}

.add-form {
  margin-top: 20px;
  
  .form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
    
    @media (max-width: 768px) {
      grid-template-columns: 1fr;
    }
  }
  
  .form-group {
    display: flex;
    flex-direction: column;
    
    label {
      margin-bottom: 6px;
      font-weight: 500;
      color: var(--color-main-text);
      font-size: 14px;
    }
    
    &.checkbox-group {
      justify-content: center;
    }
  }
}

.options-section {
  margin: 20px 0;
  padding: 20px;
  background: var(--color-background-hover);
  border-radius: var(--border-radius);
  border: 1px solid var(--color-border);
  
  h5 {
    margin: 0 0 16px 0;
    color: var(--color-main-text);
  }
  
  .option-item {
    display: flex;
    gap: 10px;
    margin-bottom: 10px;
  }
}

.form-actions {
  display: flex;
  gap: 12px;
  margin-top: 24px;
}

// Empty State
.empty-state {
  text-align: center;
  padding: 60px 20px;
  color: var(--color-text-lighter);
  
  .empty-icon {
    opacity: 0.3;
    margin-bottom: 20px;
  }
  
  h5 {
    margin: 0 0 10px 0;
    color: var(--color-main-text);
    font-size: 18px;
  }
  
  p {
    margin: 0 0 24px 0;
    line-height: 1.5;
  }
}

// Fields List
.fields-list {
  display: grid;
  gap: 16px;
}

.field-card {
  background: var(--color-background-hover);
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius);
  padding: 20px;
  transition: all 0.2s ease;
  
  &:hover {
    box-shadow: 0 2px 12px rgba(0,0,0,0.15);
    border-color: var(--color-primary-element);
    background: var(--color-main-background);
  }
  
  .field-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
  }
  
  .field-info {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    flex: 1;
    
    .field-icon {
      color: var(--color-primary);
      margin-top: 2px;
    }
    
    .field-details {
      flex: 1;
    }
    
    .field-name {
      margin: 0 0 6px 0;
      color: var(--color-main-text);
      font-size: 16px;
      font-weight: 500;
    }
    
    .field-badges {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }
    
    .field-type-badge {
      background: var(--color-primary-element-light);
      color: var(--color-primary);
      padding: 3px 8px;
      border-radius: 12px;
      font-size: 12px;
      font-weight: 500;
    }
    
    .required-badge {
      background: var(--color-warning);
      color: white;
      padding: 3px 8px;
      border-radius: 12px;
      font-size: 12px;
      font-weight: 500;
    }
  }
  
  .field-actions {
    display: flex;
    align-items: flex-start;
  }
  
  .field-description {
    color: var(--color-text-lighter);
    margin: 12px 0;
    font-style: italic;
    line-height: 1.4;
  }
  
  .field-options {
    margin: 12px 0;
    font-size: 14px;
    
    .options-preview {
      color: var(--color-text-lighter);
    }
  }
  
  .field-meta {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--color-border);
    
    .field-usage {
      color: var(--color-text-lighter);
      font-size: 13px;
    }
  }
}

// Modal content styling for NcModal
.modal-content {
  padding: 20px;
  
  .modal-body {
    .form-group {
      margin-bottom: 20px;
      
      label {
        margin-bottom: 6px;
        font-weight: 500;
        color: var(--color-main-text);
        font-size: 14px;
      }
      
      &.checkbox-group {
        justify-content: flex-start;
      }
    }
    
    .usage-warning {
      padding: 16px;
      background: var(--color-warning-background);
      border: 1px solid var(--color-warning);
      border-radius: var(--border-radius);
      margin-top: 20px;
      
      p {
        margin: 0;
        color: var(--color-warning-text);
      }
    }
  }
  
  .modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px solid var(--color-border);
  }
}

// Responsive
@media (max-width: 768px) {
  .groupfolder-metadata {
    padding: 10px;
  }
  
  .settings-section {
    padding: 16px;
  }
  
  .section-title {
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
  }
  
  .field-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
  }
  
  .field-actions {
    align-self: flex-end;
  }
}
</style>