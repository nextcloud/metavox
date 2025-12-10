<template>
  <div id="metavox-user" class="metavox-user-interface">
    <!-- Loading state -->
    <div v-if="loading" class="loading-container">
      <div class="icon-loading"></div>
      <p>{{ t('metavox', 'Loading your team folders...') }}</p>
    </div>

    <div v-else>
      <!-- Page Header -->
      <div class="page-header">
        <h2>{{ t('metavox', 'My Team Folders') }}</h2>
        <p class="page-description">
          {{ t('metavox', 'Configure metadata fields and manage metadata for your team folders.') }}
        </p>
      </div>

      <!-- Info Box -->
      <div class="settings-section">
        <div class="info-box">
          <h4>💡 {{ t('metavox', 'What you can do:') }}</h4>
          <ul>
            <li>{{ t('metavox', 'Configure which metadata fields are available for your team folders') }}</li>
            <li>{{ t('metavox', 'Edit team folder metadata values') }}</li>
            <li>{{ t('metavox', 'File metadata can be edited by users within the Files app') }}</li>
          </ul>
        </div>
      </div>

      <!-- Search Section -->
      <div class="settings-section" v-if="accessibleGroupfolders.length > 0">
        <div class="search-container">
          <NcTextField
            :value="searchQuery"
            @update:value="searchQuery = $event"
            :label="t('metavox', 'Search team folders')"
            :show-trailing-button="!!searchQuery"
            @trailing-button-click="searchQuery = ''">
            <MagnifyIcon slot="icon" :size="20" />
          </NcTextField>
        </div>
        
        <div v-if="searchQuery" class="search-results-info">
          <p>{{ t('metavox', 'Found {count} team folders matching "{query}"', { 
            count: filteredGroupfolders.length, 
            query: searchQuery 
          }) }}</p>
        </div>
      </div>

      <!-- Empty State -->
      <div v-if="accessibleGroupfolders.length === 0" class="empty-content">
        <FolderIcon :size="64" class="empty-icon" />
        <h4>{{ t('metavox', 'No accessible team folders') }}</h4>
        <p>{{ t('metavox', 'You don\'t have access to any team folders yet. Contact your administrator if you need access.') }}</p>
      </div>

      <!-- Groupfolders List -->
      <div v-else class="settings-section">
        <div class="section-header">
          <h3>{{ t('metavox', 'Your Team Folders') }}</h3>
          <span class="field-count">{{ t('metavox', '{count} folders', { count: accessibleGroupfolders.length }) }}</span>
        </div>

        <div class="groupfolders-list">
          <div 
            v-for="groupfolder in filteredGroupfolders" 
            :key="groupfolder.id"
            class="groupfolder-item">
            
            <!-- Groupfolder Header -->
            <div class="groupfolder-main">
              <div class="groupfolder-icon">
                <FolderIcon :size="24" />
              </div>
              
              <div class="groupfolder-content">
                <h4 class="groupfolder-name">{{ groupfolder.mount_point }}</h4>
                <p class="groupfolder-info">
                  <span v-if="getAssignedFieldsCount(groupfolder.id) > 0" class="field-count-badge">
                    {{ t('metavox', '{count} fields configured', { count: getAssignedFieldsCount(groupfolder.id) }) }}
                  </span>
                  <span v-else class="no-fields-badge">
                    {{ t('metavox', 'No fields configured') }}
                  </span>
                </p>
              </div>
              
              <div class="groupfolder-actions">
                <NcButton 
                  @click="toggleFieldsConfiguration(groupfolder.id)"
                  type="secondary">
                  <template #icon>
                    <CogIcon :size="20" />
                  </template>
                  {{ t('metavox', 'Configure Fields') }}
                </NcButton>
                <NcButton 
                  @click="editGroupfolderMetadata(groupfolder)"
                  type="primary"
                  :disabled="getAssignedGroupfolderFieldsCount(groupfolder.id) === 0">
                  <template #icon>
                    <EditIcon :size="20" />
                  </template>
                  {{ t('metavox', 'Edit Metadata') }}
                </NcButton>
              </div>
            </div>

            <!-- Fields Configuration Panel -->
            <div v-if="expandedFields[groupfolder.id]" class="expanded-panel">
              <div v-if="loadingFields[groupfolder.id]" class="loading-section">
                <div class="icon-loading-small"></div>
                <span>{{ t('metavox', 'Loading field configuration...') }}</span>
              </div>
              
              <div v-else class="fields-config-content">
                <h4>{{ t('metavox', 'Configure Fields for this Team Folder') }}</h4>
                
                <div v-if="allFields.length === 0" class="no-fields-available">
                  <p>{{ t('metavox', 'No fields available. Contact your administrator to create metadata fields.') }}</p>
                </div>
                
                <form v-else @submit.prevent="saveFieldsConfiguration(groupfolder.id)" class="fields-config-form">
                  <!-- Search for Fields -->
                  <div class="field-search-section">
                    <NcTextField
                      :value="fieldSearchQuery[groupfolder.id]"
                      @update:value="fieldSearchQuery[groupfolder.id] = $event"
                      :label="t('metavox', 'Search fields')"
                      :show-trailing-button="!!fieldSearchQuery[groupfolder.id]"
                      @trailing-button-click="fieldSearchQuery[groupfolder.id] = ''">
                      <MagnifyIcon slot="icon" :size="16" />
                    </NcTextField>
                    
                    <!-- Field Type Filter -->
                    <div class="field-type-filter">
                      <label>{{ t('metavox', 'Field Type:') }}</label>
                      <div class="filter-button-group">
                        <button 
                          type="button"
                          @click="fieldTypeFilter[groupfolder.id] = 'all'"
                          :class="['filter-btn', { active: (fieldTypeFilter[groupfolder.id] || 'all') === 'all' }]">
                          {{ t('metavox', 'All Types') }}
                        </button>
                        <button 
                          type="button"
                          @click="fieldTypeFilter[groupfolder.id] = 'groupfolder'"
                          :class="['filter-btn', { active: fieldTypeFilter[groupfolder.id] === 'groupfolder' }]">
                          {{ t('metavox', 'Team Folder Fields') }} ({{ groupfolderMetadataFields.length }})
                        </button>
                        <button 
                          type="button"
                          @click="fieldTypeFilter[groupfolder.id] = 'file'"
                          :class="['filter-btn', { active: fieldTypeFilter[groupfolder.id] === 'file' }]">
                          {{ t('metavox', 'File Fields') }} ({{ fileMetadataFields.length }})
                        </button>
                      </div>
                    </div>
                  </div>

                  <!-- Groupfolder Metadata Fields Section -->
                  <div v-if="getFilteredGroupfolderFields(groupfolder.id).length > 0" class="field-section">
                    <h5>{{ t('metavox', 'Team Folder Metadata Fields') }}</h5>
                    <p class="section-description">{{ t('metavox', 'These fields apply to the team folder itself') }}</p>
                    <div class="checkbox-group">
                      <NcCheckboxRadioSwitch
                        v-for="field in getFilteredGroupfolderFields(groupfolder.id)"
                        :key="`gf-${field.id}-${groupfolder.id}`"
                        :checked="isFieldAssigned(groupfolder.id, field.id)"
                        @update:checked="updateFieldAssignment(groupfolder.id, field.id, $event)"
                        type="checkbox">
                        {{ field.field_label }}
                        <span class="field-type-label">({{ field.field_type }})</span>
                      </NcCheckboxRadioSwitch>
                    </div>
                  </div>

                  <!-- File Metadata Fields Section -->
                  <div v-if="getFilteredFileFields(groupfolder.id).length > 0" class="field-section">
                    <h5>{{ t('metavox', 'File Metadata Fields') }}</h5>
                    <p class="section-description">{{ t('metavox', 'These fields can be set on individual files') }}</p>
                    <div class="checkbox-group">
                      <NcCheckboxRadioSwitch
                        v-for="field in getFilteredFileFields(groupfolder.id)"
                        :key="`file-${field.id}-${groupfolder.id}`"
                        :checked="isFieldAssigned(groupfolder.id, field.id)"
                        @update:checked="updateFieldAssignment(groupfolder.id, field.id, $event)"
                        type="checkbox">
                        {{ field.field_label }}
                        <span class="field-type-label">({{ field.field_type }})</span>
                      </NcCheckboxRadioSwitch>
                    </div>
                  </div>

                  <!-- No fields found message -->
                  <div v-if="getFilteredGroupfolderFields(groupfolder.id).length === 0 && getFilteredFileFields(groupfolder.id).length === 0" class="no-search-results">
                    <p v-if="fieldSearchQuery[groupfolder.id]">
                      {{ t('metavox', 'No fields found matching "{query}"', { query: fieldSearchQuery[groupfolder.id] }) }}
                    </p>
                    <p v-else-if="fieldTypeFilter[groupfolder.id] && fieldTypeFilter[groupfolder.id] !== 'all'">
                      {{ t('metavox', 'No fields match the selected filter') }}
                    </p>
                    <p v-else>
                      {{ t('metavox', 'No fields available') }}
                    </p>
                  </div>

                  <div class="form-actions">
                    <NcButton 
                      type="primary" 
                      native-type="submit"
                      :disabled="savingFields[groupfolder.id]">
                      <template #icon>
                        <div v-if="savingFields[groupfolder.id]" class="icon-loading-small"></div>
                        <ContentSaveIcon v-else :size="20" />
                      </template>
                      {{ savingFields[groupfolder.id] ? t('metavox', 'Saving...') : t('metavox', 'Save Configuration') }}
                    </NcButton>
                    <NcButton 
                      @click="closeFieldsConfig(groupfolder.id)"
                      type="secondary">
                      {{ t('metavox', 'Cancel') }}
                    </NcButton>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Metadata Edit Modal -->
    <NcModal 
      v-if="showMetadataModal" 
      @close="closeMetadataModal" 
      :size="'large'"
      :title="modalTitle">
      <div class="modal-content">
        <p class="modal-description">{{ t('metavox', 'Edit the metadata values for this team folder') }}</p>
        
        <div class="metadata-form-container">
          <div v-if="loadingMetadata" class="loading-container">
            <div class="icon-loading"></div>
            <p>{{ t('metavox', 'Loading metadata...') }}</p>
          </div>
          
          <div v-else-if="currentMetadataFields.length === 0" class="no-fields-message">
            <p>{{ t('metavox', 'No metadata fields are configured for this team folder.') }}</p>
            <p>{{ t('metavox', 'Please configure some fields first using the "Configure Fields" button.') }}</p>
          </div>
          
          <form v-else @submit.prevent="saveGroupfolderMetadata" class="metadata-form">
            <div 
              v-for="field in currentMetadataFields" 
              :key="field.id" 
              class="field-item">
              
              <label :for="'field-' + field.id" class="field-label">
                {{ field.field_label }}
                <span v-if="field.is_required" class="required">*</span>
              </label>
              
              <!-- Text input -->
              <NcTextField
                v-if="field.field_type === 'text'"
                :id="'field-' + field.id"
                :value="getFieldValue(field)"
                @update:value="updateMetadataValue(field, $event)"
                :label="field.field_label"
                :placeholder="field.field_label"
                :required="field.is_required"
                class="field-input" />
              
              <!-- Textarea input -->
              <textarea
                v-else-if="field.field_type === 'textarea'"
                :id="'field-' + field.id"
                :value="getFieldValue(field)"
                @input="updateMetadataValue(field, $event.target.value)"
                :placeholder="field.field_label"
                :required="field.is_required"
                class="field-input textarea-input"
                rows="4"></textarea>
              
              <!-- Select input -->
              <NcSelect
                v-else-if="field.field_type === 'select'"
                :id="'field-' + field.id"
                :key="`select-${field.field_name}-${selectKey}`"
                v-model="selectValues[field.field_name]"
                :options="getFieldOptions(field)"
                :placeholder="t('metavox', 'Choose an option...')"
                class="field-input select-field"
                :clearable="!field.is_required"
                :reduce="option => option.value"
                label="label"
                @input="handleSelectChange(field.field_name, $event)" />
              
              <!-- MultiSelect input -->
              <NcSelect
                v-else-if="field.field_type === 'multiselect'"
                :id="'field-' + field.id"
                :key="`multiselect-${field.field_name}-${selectKey}`"
                v-model="multiSelectValues[field.field_name]"
                :options="getFieldOptions(field)"
                :multiple="true"
                :placeholder="t('metavox', 'Choose options...')"
                class="field-input select-field"
                :clearable="!field.is_required"
                :reduce="option => option.value"
                label="label"
                @input="handleMultiSelectChange(field.field_name, $event)" />
              
              <!-- Date input -->
              <input
                v-else-if="field.field_type === 'date'"
                :id="'field-' + field.id"
                type="date"
                :value="getFieldValue(field)"
                @input="updateMetadataValue(field, $event.target.value)"
                :required="field.is_required"
                class="field-input date-input" />
              
              <!-- Number input -->
              <input
                v-else-if="field.field_type === 'number'"
                :id="'field-' + field.id"
                type="number"
                :value="getFieldValue(field)"
                @input="updateMetadataValue(field, $event.target.value)"
                :required="field.is_required"
                class="field-input number-input" />
              
              <!-- Checkbox input -->
              <NcCheckboxRadioSwitch
                v-else-if="field.field_type === 'checkbox'"
                :id="'field-' + field.id"
                :checked="isCheckboxChecked(field)"
                @update:checked="updateCheckboxValue(field, $event)"
                type="checkbox">
                {{ field.field_label }}
              </NcCheckboxRadioSwitch>
              
              <!-- Default text input for unknown types -->
              <NcTextField
                v-else
                :id="'field-' + field.id"
                :value="getFieldValue(field)"
                @update:value="updateMetadataValue(field, $event)"
                :placeholder="field.field_label"
                :required="field.is_required"
                class="field-input" />
                
              <div v-if="field.field_description" class="field-description">
                {{ field.field_description }}
              </div>
            </div>
            
            <!-- Modal Actions within form -->
            <div class="modal-actions">
              <NcButton @click="closeMetadataModal" type="tertiary">
                {{ t('metavox', 'Cancel') }}
              </NcButton>
              <NcButton 
                type="primary"
                native-type="submit"
                :disabled="savingMetadata || currentMetadataFields.length === 0 || loadingMetadata">
                <template #icon>
                  <div v-if="savingMetadata" class="icon-loading-small"></div>
                  <ContentSaveIcon v-else :size="20" />
                </template>
                {{ savingMetadata ? t('metavox', 'Saving...') : t('metavox', 'Save') }}
              </NcButton>
            </div>
          </form>
        </div>
      </div>
    </NcModal>
  </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

// Simple notification helpers using OC.Notification
const showSuccess = (message) => {
  if (typeof OC !== 'undefined' && OC.Notification) {
    OC.Notification.showTemporary(message)
  } else {
    console.log('Success:', message)
  }
}

const showError = (message) => {
  if (typeof OC !== 'undefined' && OC.Notification) {
    OC.Notification.showTemporary(message)
  } else {
    console.error('Error:', message)
  }
}

// Nextcloud Vue components
import { 
  NcButton,
  NcTextField,
  NcSelect,
  NcCheckboxRadioSwitch,
  NcModal
} from '@nextcloud/vue'

// Icons
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import EditIcon from 'vue-material-design-icons/Pencil.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import ContentSaveIcon from 'vue-material-design-icons/ContentSave.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'

export default {
  name: 'MetaVoxUser',
  
  components: {
    NcButton,
    NcTextField,
    NcSelect,
    NcCheckboxRadioSwitch,
    NcModal,
    FolderIcon,
    EditIcon,
    CogIcon,
    ContentSaveIcon,
    MagnifyIcon
  },
  
  data() {
    return {
      loading: false,
      loadingMetadata: false,
      accessibleGroupfolders: [],
      allFields: [],
      searchQuery: '',
      
      // Expanded states
      expandedFields: {},
      
      // Loading states
      loadingFields: {},
      savingFields: {},
      
      // Data storage
      metadataValues: {},
      metadataFields: {},
      assignedFields: {},
      tempAssignedFields: {},
      
      // Field search and filter
      fieldSearchQuery: {},
      fieldTypeFilter: {},
      
      // Metadata modal state
      showMetadataModal: false,
      currentGroupfolderId: null,
      currentMetadataFields: [],
      currentMetadataValues: {},
      selectValues: {},
      multiSelectValues: {},
      savingMetadata: false,
      modalTitle: '',
      selectKey: 0
    }
  },
  
  computed: {
    filteredGroupfolders() {
      if (!this.searchQuery) {
        return this.accessibleGroupfolders
      }
      
      const query = this.searchQuery.toLowerCase().trim()
      return this.accessibleGroupfolders.filter(gf => 
        gf.mount_point && gf.mount_point.toLowerCase().includes(query)
      )
    },
    
    groupfolderMetadataFields() {
      return this.allFields.filter(field => 
        field.applies_to_groupfolder === 1 || field.applies_to_groupfolder === '1'
      )
    },
    
    fileMetadataFields() {
      return this.allFields.filter(field => 
        field.applies_to_groupfolder === 0 || field.applies_to_groupfolder === '0' || !field.applies_to_groupfolder
      )
    }
  },
  
  async mounted() {
    await this.loadAllFields()
    await this.loadAccessibleGroupfolders()
  },
  
  methods: {
async loadAllFields() {
  try {
    const response = await axios.get(generateUrl('/apps/metavox/api/user/groupfolder-fields'))
    this.allFields = response.data || []
  } catch (error) {
    console.error('Failed to load fields:', error)
    this.allFields = []
  }
},
    
async loadAccessibleGroupfolders() {
  this.loading = true
  try {
    const response = await axios.get(generateUrl('/apps/metavox/api/user/groupfolders'))
    this.accessibleGroupfolders = response.data || []
        
        // Initialize state objects
        this.accessibleGroupfolders.forEach(gf => {
          this.$set(this.expandedFields, gf.id, false)
          this.$set(this.metadataValues, gf.id, {})
          this.$set(this.metadataFields, gf.id, [])
          this.$set(this.assignedFields, gf.id, [])
          this.$set(this.tempAssignedFields, gf.id, [])
          this.$set(this.fieldSearchQuery, gf.id, '')
          this.$set(this.fieldTypeFilter, gf.id, 'all')
        })
        
        // Pre-load assigned fields for all groupfolders
        for (const gf of this.accessibleGroupfolders) {
          try {
            const response = await axios.get(
              generateUrl(`/apps/metavox/api/groupfolders/${gf.id}/fields`)
            )
            const fieldIds = response.data || []
            this.$set(this.assignedFields, gf.id, fieldIds)
            this.$set(this.tempAssignedFields, gf.id, [...fieldIds])
          } catch (error) {
            console.error(`Failed to load fields for groupfolder ${gf.id}:`, error)
            this.$set(this.assignedFields, gf.id, [])
            this.$set(this.tempAssignedFields, gf.id, [])
          }
        }
      } catch (error) {
        console.error('Failed to load groupfolders:', error)
        showError(this.t('metavox', 'Failed to load team folders'))
      } finally {
        this.loading = false
      }
    },
    
    async toggleFieldsConfiguration(groupfolderId) {
      // Close other panels
      Object.keys(this.expandedFields).forEach(id => {
        if (id !== groupfolderId) {
          this.$set(this.expandedFields, id, false)
        }
      })
      
      const isExpanded = !this.expandedFields[groupfolderId]
      this.$set(this.expandedFields, groupfolderId, isExpanded)
      
      if (isExpanded) {
        // Reset temp fields to current assigned fields when opening
        this.$set(this.tempAssignedFields, groupfolderId, [...this.assignedFields[groupfolderId]])
        this.$set(this.loadingFields, groupfolderId, false)
      }
    },
    
    closeFieldsConfig(groupfolderId) {
      // Reset temp fields to actual assigned fields when canceling
      this.$set(this.tempAssignedFields, groupfolderId, [...this.assignedFields[groupfolderId]])
      this.$set(this.expandedFields, groupfolderId, false)
    },
    
    async saveFieldsConfiguration(groupfolderId) {
      this.$set(this.savingFields, groupfolderId, true)
      
      try {
        await axios.post(
          generateUrl(`/apps/metavox/api/groupfolders/${groupfolderId}/fields`),
          { field_ids: this.tempAssignedFields[groupfolderId] }
        )
        
        // Update the actual assigned fields after successful save
        this.$set(this.assignedFields, groupfolderId, [...this.tempAssignedFields[groupfolderId]])
        
        showSuccess(this.t('metavox', 'Field configuration saved successfully'))
        this.$set(this.expandedFields, groupfolderId, false)
      } catch (error) {
        console.error('Failed to save field configuration:', error)
        showError(this.t('metavox', 'Failed to save field configuration'))
        // Reset temp fields to actual assigned fields on error
        this.$set(this.tempAssignedFields, groupfolderId, [...this.assignedFields[groupfolderId]])
      } finally {
        this.$set(this.savingFields, groupfolderId, false)
      }
    },
    
    isFieldAssigned(groupfolderId, fieldId) {
      return (this.tempAssignedFields[groupfolderId] || []).includes(fieldId)
    },
    
    updateFieldAssignment(groupfolderId, fieldId, checked) {
      console.log(`Updating field assignment: GroupFolder ${groupfolderId}, Field ${fieldId}, Checked: ${checked}`)
      
      const fields = [...(this.tempAssignedFields[groupfolderId] || [])]
      
      if (checked) {
        if (!fields.includes(fieldId)) {
          fields.push(fieldId)
        }
      } else {
        const index = fields.indexOf(fieldId)
        if (index > -1) {
          fields.splice(index, 1)
        }
      }
      
      this.$set(this.tempAssignedFields, groupfolderId, fields)
    },
    
    getAssignedFieldsCount(groupfolderId) {
      return (this.assignedFields[groupfolderId] || []).length
    },
    
    getAssignedGroupfolderFieldsCount(groupfolderId) {
      const assignedIds = this.assignedFields[groupfolderId] || []
      return this.groupfolderMetadataFields.filter(field => 
        assignedIds.includes(field.id)
      ).length
    },
    
    filterFieldsForConfiguration(fields, groupfolderId) {
      let filtered = [...fields]
      
      // Apply search filter
      const searchQuery = (this.fieldSearchQuery[groupfolderId] || '').toString().toLowerCase().trim()
      if (searchQuery) {
        filtered = filtered.filter(field => {
          const searchText = `${field.field_label || ''} ${field.field_name || ''} ${field.field_type || ''}`.toLowerCase()
          return searchText.includes(searchQuery)
        })
      }
      
      return filtered
    },
    
    getFilteredGroupfolderFields(groupfolderId) {
      const typeFilter = this.fieldTypeFilter[groupfolderId] || 'all'
      
      if (typeFilter === 'file') {
        return []
      }
      
      if (typeFilter === 'groupfolder' || typeFilter === 'all') {
        return this.filterFieldsForConfiguration(this.groupfolderMetadataFields, groupfolderId)
      }
      
      return []
    },
    
    getFilteredFileFields(groupfolderId) {
      const typeFilter = this.fieldTypeFilter[groupfolderId] || 'all'
      
      if (typeFilter === 'groupfolder') {
        return []
      }
      
      if (typeFilter === 'file' || typeFilter === 'all') {
        return this.filterFieldsForConfiguration(this.fileMetadataFields, groupfolderId)
      }
      
      return []
    },
    
    async loadGroupfolderMetadata(groupfolderId) {
      console.log('Loading metadata for groupfolder:', groupfolderId)
      
      try {
        const response = await axios.get(
          generateUrl(`/apps/metavox/api/groupfolders/${groupfolderId}/metadata`)
        )
        
        console.log('Metadata API response:', response.data)
        
        const metadataData = response.data || []
        const values = {}
        
        if (Array.isArray(metadataData)) {
          metadataData.forEach(item => {
            if (item.field_name && item.value !== null && item.value !== undefined) {
              values[item.field_name] = String(item.value)
            }
          })
        }
        
        console.log('Processed metadata values:', values)
        this.$set(this.metadataValues, groupfolderId, values)
        
        const assignedFieldIds = this.assignedFields[groupfolderId] || []
        const assignedGroupfolderFields = metadataData.filter(field => {
          const isAssigned = assignedFieldIds.includes(field.id)
          const isGroupfolderField = field.applies_to_groupfolder === 1 || field.applies_to_groupfolder === '1'
          return isAssigned && isGroupfolderField
        })
        
        console.log('Assigned groupfolder fields with metadata:', assignedGroupfolderFields)
        this.$set(this.metadataFields, groupfolderId, assignedGroupfolderFields)
        
        return { fields: assignedGroupfolderFields, values }
        
      } catch (error) {
        console.error('Error loading groupfolder metadata:', error)
        this.$set(this.metadataFields, groupfolderId, [])
        this.$set(this.metadataValues, groupfolderId, {})
        throw error
      }
    },
    
    async editGroupfolderMetadata(groupfolder) {
      console.log('Opening metadata editor for:', groupfolder.mount_point)
      
      this.showMetadataModal = true
      this.currentGroupfolderId = groupfolder.id
      this.modalTitle = this.t('metavox', 'Edit metadata for {groupfolder}', { groupfolder: groupfolder.mount_point })
      this.loadingMetadata = true
      
      // Pre-initialize reactive objects
      this.selectValues = {}
      this.multiSelectValues = {}
      this.currentMetadataValues = {}
      this.currentMetadataFields = []
      this.selectKey = 0
      
      try {
        const { fields, values } = await this.loadGroupfolderMetadata(groupfolder.id)
        
        this.currentMetadataFields = [...fields]
        this.currentMetadataValues = {...values}
        
        // Initialize select and multiselect values
        fields.forEach(field => {
          if (field.field_type === 'select') {
            this.$set(this.selectValues, field.field_name, values[field.field_name] || null)
          } else if (field.field_type === 'multiselect') {
            const value = values[field.field_name]
            if (value) {
              this.$set(this.multiSelectValues, field.field_name, value.split(';#').filter(v => v.trim()))
            } else {
              this.$set(this.multiSelectValues, field.field_name, [])
            }
          }
        })
        
        this.$nextTick(() => {
          this.selectKey++
          console.log('Modal initialized with:', {
            fields: this.currentMetadataFields,
            values: this.currentMetadataValues
          })
        })
      } catch (error) {
        console.error('Error loading metadata:', error)
        showError(this.t('metavox', 'Error loading metadata'))
        this.currentMetadataFields = []
        this.currentMetadataValues = {}
        this.selectValues = {}
        this.multiSelectValues = {}
      } finally {
        this.loadingMetadata = false
      }
    },
    
    closeMetadataModal() {
      this.showMetadataModal = false
      this.currentGroupfolderId = null
      this.currentMetadataFields = []
      this.currentMetadataValues = {}
      this.selectValues = {}
      this.multiSelectValues = {}
      this.savingMetadata = false
      this.selectKey = 0
    },
    
    getFieldValue(field) {
      const value = this.currentMetadataValues[field.field_name]
      return value !== undefined ? value : ''
    },
    
    handleSelectChange(fieldName, value) {
      console.log(`Select change: ${fieldName} = ${value}`)
      this.$set(this.selectValues, fieldName, value)
      this.$set(this.currentMetadataValues, fieldName, value || '')
      
      this.$nextTick(() => {
        console.log(`Select value after change: ${this.selectValues[fieldName]}`)
      })
    },
    
    handleMultiSelectChange(fieldName, values) {
      console.log(`MultiSelect change: ${fieldName} = ${values}`)
      this.$set(this.multiSelectValues, fieldName, values || [])
      const joinedValue = Array.isArray(values) ? values.join(';#') : ''
      this.$set(this.currentMetadataValues, fieldName, joinedValue)
      
      this.$nextTick(() => {
        console.log(`MultiSelect value after change: ${this.multiSelectValues[fieldName]}`)
      })
    },
    
    updateMetadataValue(field, value) {
      console.log(`Updating ${field.field_name} = "${value}"`)
      this.$set(this.currentMetadataValues, field.field_name, value)
    },
    
    updateCheckboxValue(field, checked) {
      this.updateMetadataValue(field, checked ? '1' : '0')
    },
    
    isCheckboxChecked(field) {
      const value = this.getFieldValue(field)
      return value === '1' || value === 'true' || value === true || value === 1
    },
    
    async saveGroupfolderMetadata() {
      if (!this.currentGroupfolderId) return false
      
      console.log('Saving metadata for groupfolder:', this.currentGroupfolderId)
      
      // Sync all select values
      this.currentMetadataFields.forEach(field => {
        if (field.field_type === 'select') {
          const value = this.selectValues[field.field_name]
          this.$set(this.currentMetadataValues, field.field_name, value || '')
        } else if (field.field_type === 'multiselect') {
          const values = this.multiSelectValues[field.field_name]
          const joinedValue = Array.isArray(values) ? values.join(';#') : ''
          this.$set(this.currentMetadataValues, field.field_name, joinedValue)
        }
      })
      
      console.log('Final values to save:', this.currentMetadataValues)
      
      this.savingMetadata = true
      
      try {
        const response = await axios.post(
          generateUrl(`/apps/metavox/api/groupfolders/${this.currentGroupfolderId}/metadata`),
          { metadata: this.currentMetadataValues }
        )
        
        console.log('Save response:', response.data)
        
        if (response.data.success) {
          this.$set(this.metadataValues, this.currentGroupfolderId, {...this.currentMetadataValues})
          
          showSuccess(this.t('metavox', 'Metadata saved successfully'))
          this.closeMetadataModal()
          
          await this.loadGroupfolderMetadata(this.currentGroupfolderId)
        } else {
          throw new Error('Save failed')
        }
        
        return true
        
      } catch (error) {
        console.error('Error saving metadata:', error)
        if (error.response) {
          console.error('Error response:', error.response.data)
          showError(this.t('metavox', 'Error saving metadata: ') + (error.response.data.message || error.response.statusText))
        } else {
          showError(this.t('metavox', 'Error saving metadata'))
        }
        return false
      } finally {
        this.savingMetadata = false
      }
    },
    
    getFieldOptions(field) {
      if (!field.field_options) {
        console.log(`No options for field ${field.field_name}`)
        return []
      }
      
      let options = []
      if (typeof field.field_options === 'string') {
        options = field.field_options.split('\n').filter(o => o.trim())
      } else if (Array.isArray(field.field_options)) {
        options = field.field_options.filter(o => o && o.trim())
      }
      
      const formattedOptions = options.map(option => ({
        label: option.trim(),
        value: option.trim()
      }))
      
      console.log(`Options for field ${field.field_name}:`, formattedOptions)
      return formattedOptions
    },

    // Translation helper
    t(app, text, vars) {
      if (typeof OC !== 'undefined' && OC.L10N) {
        return OC.L10N.translate(app, text, vars)
      }
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
/* Base layout */
.metavox-user-interface {
  padding: 20px;
  max-width: 1200px;
  margin: 0 auto;
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
  color: var(--color-text-lighter);
  margin: 0;
  line-height: 1.5;
}

.settings-section {
  background: var(--color-main-background);
  border-radius: var(--border-radius-large);
  padding: 24px;
  margin-bottom: 30px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
  padding-bottom: 15px;
  border-bottom: 1px solid var(--color-border);
}

.section-header h3 {
  margin: 0;
  font-weight: 600;
  color: var(--color-text);
}

.field-count {
  background: var(--color-background-dark);
  color: var(--color-text-light);
  padding: 4px 10px;
  border-radius: 12px;
  font-size: 0.9em;
}

.info-box {
  background: var(--color-background-dark);
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius);
  padding: 20px;
}

.info-box h4 {
  margin: 0 0 15px 0;
  font-weight: 600;
  color: var(--color-text);
}

.info-box ul {
  margin: 0;
  padding-left: 20px;
  color: var(--color-text-lighter);
}

.info-box li {
  margin-bottom: 8px;
}

.search-container {
  margin-bottom: 20px;
}

.search-results-info {
  background: var(--color-background-dark);
  padding: 10px 15px;
  border-radius: var(--border-radius);
  margin-bottom: 20px;
}

.search-results-info p {
  margin: 0;
  color: var(--color-text-lighter);
}

.empty-content {
  text-align: center;
  padding: 60px 20px;
  color: var(--color-text-lighter);
}

.empty-icon {
  margin-bottom: 20px;
  opacity: 0.5;
}

.empty-content h4 {
  margin: 0 0 10px 0;
  font-weight: 600;
}

.groupfolders-list {
  display: flex;
  flex-direction: column;
  gap: 15px;
}

.groupfolder-item {
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius);
  overflow: hidden;
}

.groupfolder-main {
  display: flex;
  align-items: center;
  gap: 15px;
  padding: 20px;
  transition: background-color 0.2s ease;
}

.groupfolder-item:hover .groupfolder-main {
  background: var(--color-background-hover);
}

.groupfolder-icon {
  flex-shrink: 0;
  color: var(--color-primary);
}

.groupfolder-content {
  flex: 1;
  min-width: 0;
}

.groupfolder-name {
  margin: 0 0 5px 0;
  font-weight: 600;
  color: var(--color-text);
}

.groupfolder-info {
  margin: 0;
  font-size: 0.9em;
  color: var(--color-text-lighter);
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}

.field-count-badge {
  background: var(--color-success);
  color: white;
  padding: 2px 8px;
  border-radius: 10px;
  font-size: 0.85em;
}

.no-fields-badge {
  color: var(--color-text-lighter);
}

.groupfolder-actions {
  display: flex;
  gap: 10px;
  flex-shrink: 0;
}

.expanded-panel {
  padding: 20px;
  background: var(--color-background-dark);
  border-top: 1px solid var(--color-border);
}

.loading-section {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 40px;
  color: var(--color-text-lighter);
}

.fields-config-content h4 {
  margin: 0 0 20px 0;
  font-weight: 600;
}

.no-fields-available {
  text-align: center;
  padding: 40px;
  color: var(--color-text-lighter);
  background: var(--color-background-hover);
  border-radius: var(--border-radius);
}

.field-search-section {
  margin-bottom: 20px;
  padding: 15px;
  background: var(--color-background-hover);
  border-radius: var(--border-radius);
}

.field-type-filter {
  margin-top: 15px;
}

.field-type-filter label {
  display: block;
  margin-bottom: 8px;
  font-weight: 500;
  color: var(--color-text);
  font-size: 13px;
}

.filter-button-group {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.filter-btn {
  padding: 6px 12px;
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius);
  font-size: 12px;
  cursor: pointer;
  transition: all 0.2s;
  color: var(--color-text-lighter);
}

.filter-btn:hover {
  background: var(--color-background-dark);
  border-color: var(--color-border-dark);
}

.filter-btn.active {
  background: var(--color-primary);
  color: var(--color-primary-text);
  border-color: var(--color-primary);
}

.no-search-results {
  text-align: center;
  padding: 20px;
  color: var(--color-text-lighter);
  background: var(--color-background-hover);
  border-radius: var(--border-radius);
  margin: 20px 0;
}

.field-section {
  margin-bottom: 30px;
}

.field-section h5 {
  margin: 0 0 8px 0;
  font-weight: 600;
  color: var(--color-text);
}

.section-description {
  margin: 0 0 15px 0;
  color: var(--color-text-lighter);
  font-size: 0.9em;
}

.checkbox-group {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.field-type-label {
  color: var(--color-text-lighter);
  font-size: 0.9em;
  margin-left: 5px;
}

.form-actions {
  display: flex;
  gap: 10px;
  margin-top: 20px;
  padding-top: 20px;
  border-top: 1px solid var(--color-border);
}

.loading-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 15px;
  padding: 60px 20px;
  color: var(--color-text-lighter);
}

.icon-loading {
  width: 40px;
  height: 40px;
  background-image: var(--icon-loading-dark);
  background-size: contain;
  animation: rotate 1.5s linear infinite;
}

.icon-loading-small {
  width: 16px;
  height: 16px;
  background-image: var(--icon-loading-dark);
  background-size: contain;
  animation: rotate 1.5s linear infinite;
  display: inline-block;
}

@keyframes rotate {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

/* Modal styling */
.modal-content {
  padding: 20px;
}

.modal-description {
  margin: 0 0 24px 0;
  color: var(--color-text-lighter);
  font-size: 0.9em;
}

.metadata-form-container {
  min-height: 200px;
}

.no-fields-message {
  text-align: center;
  padding: 30px;
  background: var(--color-warning-light);
  border-radius: var(--border-radius);
}

.no-fields-message p {
  margin: 0 0 10px 0;
  color: var(--color-text-lighter);
}

.no-fields-message p:last-child {
  margin-bottom: 0;
}

.metadata-form {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.field-item {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 16px;
  background: var(--color-background-hover);
  border-radius: var(--border-radius);
}

.field-label {
  font-weight: 500;
  color: var(--color-text);
  font-size: 14px;
  display: block;
  margin-bottom: 4px;
}

.required {
  color: var(--color-error);
  margin-left: 4px;
}

.field-input {
  width: 100% !important;
  min-height: 44px;
}

/* Native input styling for consistency */
.textarea-input,
.date-input,
.number-input {
  width: 100%;
  padding: 8px 12px;
  border: 2px solid var(--color-border);
  border-radius: var(--border-radius);
  background: var(--color-main-background);
  color: var(--color-text);
  font-size: 14px;
  font-family: var(--font-face);
  transition: border-color 0.2s ease;
  resize: vertical;
}

.textarea-input:focus,
.date-input:focus,
.number-input:focus {
  border-color: var(--color-primary);
  outline: none;
  box-shadow: 0 0 0 2px var(--color-primary-light);
}

.textarea-input:hover,
.date-input:hover,
.number-input:hover {
  border-color: var(--color-border-dark);
}

.field-description {
  font-size: 12px;
  color: var(--color-text-lighter);
  margin-top: 4px;
  line-height: 1.4;
}

/* Modal actions within form */
.modal-actions {
  display: flex;
  gap: 10px;
  justify-content: flex-end;
  margin-top: 32px;
  padding-top: 20px;
  border-top: 1px solid var(--color-border);
}

/* Fix for NcSelect visibility in modal */
.select-field {
  z-index: 10001 !important;
}

.select-field :deep(.vs__dropdown-toggle) {
  background: var(--color-main-background) !important;
  border: 2px solid var(--color-border) !important;
  min-height: 44px;
}

.select-field :deep(.vs__dropdown-toggle:hover) {
  border-color: var(--color-border-dark) !important;
}

.select-field :deep(.vs--open .vs__dropdown-toggle) {
  border-color: var(--color-primary) !important;
  box-shadow: 0 0 0 2px var(--color-primary-light) !important;
}

.select-field :deep(.vs__dropdown-menu) {
  z-index: 10002 !important;
  background: var(--color-main-background) !important;
  border: 1px solid var(--color-border) !important;
}

.select-field :deep(.vs__selected) {
  background: var(--color-primary-light) !important;
  color: var(--color-primary-text) !important;
  border-radius: var(--border-radius-pill) !important;
  padding: 2px 8px !important;
  margin: 2px !important;
}

.select-field :deep(.vs__clear) {
  fill: var(--color-text-lighter) !important;
}

.select-field :deep(.vs__open-indicator) {
  fill: var(--color-text-lighter) !important;
}

/* Ensure modal has proper z-index */
:deep(.modal-wrapper) {
  z-index: 9999 !important;
}

:deep(.modal-container) {
  max-width: 800px;
  width: 90%;
}

/* Ensure dropdowns appear above modal */
:deep(.modal-wrapper .vs__dropdown-menu) {
  z-index: 10002 !important;
}

/* Fix for select text visibility */
.select-field :deep(.vs__dropdown-toggle),
.select-field :deep(.vs__dropdown-toggle *) {
  color: var(--color-text) !important;
}

/* Responsive Design */
@media (max-width: 768px) {
  .metavox-user-interface {
    padding: 12px;
  }

  .groupfolder-main {
    flex-direction: column;
    align-items: flex-start;
  }

  .groupfolder-actions {
    width: 100%;
    flex-direction: column;
  }

  .groupfolder-actions button {
    width: 100%;
  }

  .settings-section {
    padding: 16px;
  }
  
  .filter-button-group {
    flex-direction: column;
  }
  
  .filter-btn {
    width: 100%;
  }
}
</style>