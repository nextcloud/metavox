<template>
  <div id="metavox-user" class="metavox-user-interface">
    <!-- Loading state -->
    <div v-if="loading" class="loading-container">
      <div class="icon-loading"></div>
      <!-- TRANSLATORS Status text while the list of Team folders loads -->
      <p>{{ t('metavox', 'Loading your Team folders …') }}</p>
    </div>

    <div v-else>
      <!-- Page Header -->
      <div class="page-header">
        <!-- TRANSLATORS Heading of the MetaVox section in the personal settings -->
        <h2>{{ t('metavox', 'My Team folders') }}</h2>
        <p class="page-description">
          {{ t('metavox', 'Configure metadata fields and manage metadata for your Team folders.') }}
        </p>
      </div>

      <!-- Info Box -->
      <div class="settings-section">
        <div class="info-box">
          <!-- TRANSLATORS Lead-in above a short list of what this page offers; keep the colon -->
          <h4>💡 {{ t('metavox', 'What you can do:') }}</h4>
          <ul>
            <li>{{ t('metavox', 'Configure which metadata fields are available for your Team folders') }}</li>
            <li>{{ t('metavox', 'Edit Team folder metadata values') }}</li>
            <li>{{ t('metavox', 'File metadata can be edited by people within the Files app') }}</li>
          </ul>
        </div>
      </div>

      <!-- Search Section -->
      <div class="settings-section" v-if="accessibleGroupfolders.length > 0">
        <div class="search-container">
          <!-- TRANSLATORS Label of the search field above the list of Team folders -->
          <NcTextField :label="t('metavox', 'Search Team folders')"
            :model-value="searchQuery"
            @update:model-value="searchQuery = $event"
            :show-trailing-button="!!searchQuery"
            @trailing-button-click="searchQuery = ''">
            <MagnifyIcon slot="icon" :size="20" />
          </NcTextField>
        </div>
        
        <div v-if="searchQuery" class="search-results-info">
          <!-- TRANSLATORS Shown above the search results; %n is the number of Team folders found, {query} the text typed in the search field -->
          <p>{{ n('metavox', 'Found %n Team folder matching "{query}"', 'Found %n Team folders matching "{query}"', filteredGroupfolders.length, { query: searchQuery }) }}</p>
        </div>
      </div>

      <!-- Empty State -->
      <div v-if="accessibleGroupfolders.length === 0" class="empty-content">
        <FolderIcon :size="64" class="empty-icon" />
        <h4>{{ t('metavox', 'No accessible Team folders') }}</h4>
        <p>{{ t('metavox', 'You do not have access to any Team folders yet. Contact your administrator if you need access.') }}</p>
      </div>

      <!-- Groupfolders List -->
      <div v-else class="settings-section">
        <div class="section-header">
          <!-- TRANSLATORS Heading of the list of Team folders this account can open -->
          <h3>{{ t('metavox', 'Your Team folders') }}</h3>
          <!-- TRANSLATORS %n is the number of Team folders in the list; shown beside the list heading -->
          <span class="field-count">{{ n('metavox', '%n folder', '%n folders', accessibleGroupfolders.length) }}</span>
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
                    <!-- TRANSLATORS Badge in a Team folder row; %n is the number of metadata fields assigned to the folder -->
                    {{ n('metavox', '%n field configured', '%n fields configured', getAssignedFieldsCount(groupfolder.id)) }}
                  </span>
                  <span v-else class="no-fields-badge">
                    <!-- TRANSLATORS Shown in a Team folder row when no metadata fields are assigned to it -->
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
                  <!-- TRANSLATORS Menu item that opens the dialog to choose the metadata fields of a Team folder -->
                  {{ t('metavox', 'Configure fields') }}
                </NcButton>
                <NcButton 
                  @click="editGroupfolderMetadata(groupfolder)"
                  type="primary"
                  :disabled="getAssignedGroupfolderFieldsCount(groupfolder.id) === 0">
                  <template #icon>
                    <EditIcon :size="20" />
                  </template>
                  <!-- TRANSLATORS Menu item that opens the dialog to edit the metadata values of a Team folder -->
                  {{ t('metavox', 'Edit metadata') }}
                </NcButton>
              </div>
            </div>

            <!-- Fields Configuration Panel -->
            <div v-if="expandedFields[groupfolder.id]" class="expanded-panel">
              <div v-if="loadingFields[groupfolder.id]" class="loading-section">
                <div class="icon-loading-small"></div>
                <!-- TRANSLATORS Status text while the field settings of the Team folder load -->
                <span>{{ t('metavox', 'Loading field configuration …') }}</span>
              </div>
              
              <div v-else class="fields-config-content">
                <h4>{{ t('metavox', 'Configure fields for this Team folder') }}</h4>
                
                <div v-if="allFields.length === 0" class="no-fields-available">
                  <p>{{ t('metavox', 'No fields available. Contact your administrator to create metadata fields.') }}</p>
                </div>
                
                <div v-else class="fields-config-form">
                  <!-- Search for Fields -->
                  <div class="field-search-section">
                    <!-- TRANSLATORS Label of the search box that filters the field list -->
                    <NcTextField :label="t('metavox', 'Search fields')"
                      :model-value="fieldSearchQuery[groupfolder.id]"
                      @update:model-value="fieldSearchQuery[groupfolder.id] = $event"
                      :show-trailing-button="!!fieldSearchQuery[groupfolder.id]"
                      @trailing-button-click="fieldSearchQuery[groupfolder.id] = ''">
                      <MagnifyIcon slot="icon" :size="16" />
                    </NcTextField>
                    
                    <!-- Field Type Filter -->
                    <div class="field-type-filter">
                      <!-- TRANSLATORS Label in front of the buttons that filter the fields by type; keep the colon -->
                      <label>{{ t('metavox', 'Field type:') }}</label>
                      <div class="filter-button-group">
                        <button 
                          type="button"
                          @click="fieldTypeFilter[groupfolder.id] = 'all'"
                          :class="['filter-btn', { active: (fieldTypeFilter[groupfolder.id] || 'all') === 'all' }]">
                          <!-- TRANSLATORS Filter button that shows fields of all types; keep it short -->
                          {{ t('metavox', 'All types') }}
                        </button>
                        <button 
                          type="button"
                          @click="fieldTypeFilter[groupfolder.id] = 'groupfolder'"
                          :class="['filter-btn', { active: fieldTypeFilter[groupfolder.id] === 'groupfolder' }]">
                          <!-- TRANSLATORS Filter button that shows only the fields set on the Team folder itself; keep it short -->
                          {{ t('metavox', 'Team folder fields') }} ({{ groupfolderMetadataFields.length }})
                        </button>
                        <button 
                          type="button"
                          @click="fieldTypeFilter[groupfolder.id] = 'file'"
                          :class="['filter-btn', { active: fieldTypeFilter[groupfolder.id] === 'file' }]">
                          <!-- TRANSLATORS Filter button that shows only the fields set on files; keep it short -->
                          {{ t('metavox', 'File fields') }} ({{ fileMetadataFields.length }})
                        </button>
                      </div>
                    </div>
                  </div>

                  <!-- Groupfolder Metadata Fields Section -->
                  <div v-if="getFilteredGroupfolderFields(groupfolder.id).length > 0" class="field-section">
                    <!-- TRANSLATORS Heading above the fields that describe the Team folder itself -->
                    <h5>{{ t('metavox', 'Team folder metadata fields') }}</h5>
                    <p class="section-description">{{ t('metavox', 'These fields apply to the Team folder itself') }}</p>
                    <div class="checkbox-group">
                      <NcCheckboxRadioSwitch
                        v-for="field in getFilteredGroupfolderFields(groupfolder.id)"
                        :key="`gf-${field.id}-${groupfolder.id}`"
                        :model-value="isFieldAssigned(groupfolder.id, field.id)"
                        @update:model-value="updateFieldAssignment(groupfolder.id, field.id, $event)"
                        type="checkbox">
                        {{ field.field_label }}
                        <span class="field-type-label">({{ fieldTypeLabel(field.field_type) }})</span>
                      </NcCheckboxRadioSwitch>
                    </div>
                  </div>

                  <!-- File Metadata Fields Section -->
                  <div v-if="getFilteredFileFields(groupfolder.id).length > 0" class="field-section">
                    <!-- TRANSLATORS Heading above the fields that can be set on files in the Team folder -->
                    <h5>{{ t('metavox', 'File metadata fields') }}</h5>
                    <p class="section-description">{{ t('metavox', 'These fields can be set on individual files') }}</p>
                    <div class="checkbox-group">
                      <div
                        v-for="field in getFilteredFileFields(groupfolder.id)"
                        :key="`file-${field.id}-${groupfolder.id}`"
                        class="file-field-row">
                        <NcCheckboxRadioSwitch
                          :model-value="isFieldAssigned(groupfolder.id, field.id)"
                          @update:model-value="updateFieldAssignment(groupfolder.id, field.id, $event)"
                          type="checkbox">
                          {{ field.field_label }}
                          <span class="field-type-label">({{ fieldTypeLabel(field.field_type) }})</span>
                        </NcCheckboxRadioSwitch>

                        <!-- Default value (only when the field is assigned) -->
                        <DefaultValueInput
                          v-if="isFieldAssigned(groupfolder.id, field.id)"
                          :field="field"
                          :input-id="`default-${groupfolder.id}-${field.id}`"
                          :model-value="getDefaultValue(groupfolder.id, field)"
                          @update:model-value="setDefaultValue(groupfolder.id, field, $event)" />
                      </div>
                    </div>
                  </div>

                  <!-- No fields found message -->
                  <div v-if="getFilteredGroupfolderFields(groupfolder.id).length === 0 && getFilteredFileFields(groupfolder.id).length === 0" class="no-search-results">
                    <p v-if="fieldSearchQuery[groupfolder.id]">
                      <!-- TRANSLATORS {query} is the text typed in the search box -->
                      {{ t('metavox', 'No fields found matching "{query}"', { query: fieldSearchQuery[groupfolder.id] }) }}
                    </p>
                    <p v-else-if="fieldTypeFilter[groupfolder.id] && fieldTypeFilter[groupfolder.id] !== 'all'">
                      {{ t('metavox', 'No fields match the selected filter') }}
                    </p>
                    <p v-else>
                      <!-- TRANSLATORS Shown in place of the field list when there are no fields to choose from -->
                      {{ t('metavox', 'No fields available') }}
                    </p>
                  </div>

                  <!-- Apply defaults now -->
                  <div class="apply-defaults-section">
                    <p class="section-description">{{ t('metavox', 'Apply the configured default values to existing files in this Team folder that do not yet have a value for these fields.') }}</p>
                    <div class="apply-defaults-actions">
                      <NcButton
                        type="secondary"
                        :disabled="defaultsStatus[groupfolder.id] === 'running'"
                        @click="triggerDefaults(groupfolder.id)">
                        <template #icon>
                          <div v-if="defaultsStatus[groupfolder.id] === 'running'" class="icon-loading-small"></div>
                          <PlayIcon v-else :size="20" />
                        </template>
                        <!-- TRANSLATORS Button that fills in the default values on existing files of the Team folder -->
                        {{ t('metavox', 'Apply defaults now') }}
                      </NcButton>
                      <span class="apply-defaults-status">
                        {{ defaultsStatusText(groupfolder.id) }}
                      </span>
                    </div>
                  </div>

                  <div class="form-actions">
                    <NcButton
                      type="primary"
                      native-type="button"
                      :disabled="savingFields[groupfolder.id]"
                      @click="saveFieldsConfiguration(groupfolder.id)">
                      <template #icon>
                        <div v-if="savingFields[groupfolder.id]" class="icon-loading-small"></div>
                        <ContentSaveIcon v-else :size="20" />
                      </template>
                      {{ saveFieldsLabel(groupfolder.id) }}
                    </NcButton>
                    <NcButton
                      @click="closeFieldsConfig(groupfolder.id)"
                      type="secondary">
                      <!-- TRANSLATORS Button that closes a dialog without saving -->
                      {{ t('metavox', 'Cancel') }}
                    </NcButton>
                  </div>
                </div>

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
        <p class="modal-description">{{ t('metavox', 'Edit the metadata values for this Team folder') }}</p>
        
        <div class="metadata-form-container">
          <div v-if="loadingMetadata" class="loading-container">
            <div class="icon-loading"></div>
            <!-- TRANSLATORS Status text while the metadata values of the Team folder load -->
            <p>{{ t('metavox', 'Loading metadata …') }}</p>
          </div>
          
          <div v-else-if="currentMetadataFields.length === 0" class="no-fields-message">
            <p>{{ t('metavox', 'No metadata fields are configured for this Team folder.') }}</p>
            <!-- TRANSLATORS "Configure fields" names the menu item of a Team folder row; translate it the same way -->
            <p>{{ t('metavox', 'Please configure some fields first using the "Configure fields" button.') }}</p>
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
                :model-value="getFieldValue(field)"
                @update:model-value="updateMetadataValue(field, $event)"
                :label="field.field_label"
                :placeholder="field.field_label"
                :required="field.is_required"
                class="field-input" />
              
              <!-- Textarea input -->
              <textarea
                v-else-if="field.field_type === 'textarea'"
                :id="'field-' + field.id"
                :model-value="getFieldValue(field)"
                @input="updateMetadataValue(field, $event.target.value)"
                :placeholder="field.field_label"
                :required="field.is_required"
                class="field-input textarea-input"
                rows="4"></textarea>
              
              <!-- Select input -->
              <!-- TRANSLATORS Placeholder of a dropdown field before a value is picked -->
              <NcSelect :placeholder="t('metavox', 'Choose an option …')"
                v-else-if="field.field_type === 'select'"
                :id="'field-' + field.id"
                :key="`select-${field.field_name}-${selectKey}`"
                v-model="selectValues[field.field_name]"
                :options="getFieldOptions(field)"
                class="field-input select-field"
                :clearable="!field.is_required"
                :reduce="option => option.value"
                label="label"
                @update:model-value="handleSelectChange(field.field_name, $event)" />
              
              <!-- MultiSelect input -->
              <!-- TRANSLATORS Placeholder of a multi-select field before any value is picked -->
              <NcSelect :placeholder="t('metavox', 'Choose options …')"
                v-else-if="field.field_type === 'multiselect'"
                :id="'field-' + field.id"
                :key="`multiselect-${field.field_name}-${selectKey}`"
                v-model="multiSelectValues[field.field_name]"
                :options="getFieldOptions(field)"
                :multiple="true"
                class="field-input select-field"
                :clearable="!field.is_required"
                :reduce="option => option.value"
                label="label"
                @update:model-value="handleMultiSelectChange(field.field_name, $event)" />
              
              <!-- Date input (optionally datetime-local) -->
              <input
                v-else-if="field.field_type === 'date'"
                :id="'field-' + field.id"
                :type="dateFieldIncludesTime(field) ? 'datetime-local' : 'date'"
                :step="dateFieldIncludesTime(field) ? 1 : undefined"
                :model-value="getFieldValue(field)"
                @input="updateMetadataValue(field, dateFieldIncludesTime(field) ? padDatetimeLocal($event.target.value) : $event.target.value)"
                :required="field.is_required"
                class="field-input date-input" />
              
              <!-- Number input -->
              <input
                v-else-if="field.field_type === 'number'"
                :id="'field-' + field.id"
                type="number"
                :model-value="getFieldValue(field)"
                @input="updateMetadataValue(field, $event.target.value)"
                :required="field.is_required"
                class="field-input number-input" />
              
              <!-- Checkbox input -->
              <NcCheckboxRadioSwitch
                v-else-if="field.field_type === 'checkbox'"
                :id="'field-' + field.id"
                :model-value="isCheckboxChecked(field)"
                @update:model-value="updateCheckboxValue(field, $event)"
                type="checkbox">
                {{ field.field_label }}
              </NcCheckboxRadioSwitch>
              
              <!-- Default text input for unknown types -->
              <NcTextField
                v-else
                :id="'field-' + field.id"
                :model-value="getFieldValue(field)"
                @update:model-value="updateMetadataValue(field, $event)"
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
                <!-- TRANSLATORS Button that closes the edit dialog without saving; keep it short -->
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
                {{ saveMetadataLabel() }}
              </NcButton>
            </div>
          </form>
        </div>
      </div>
    </NcModal>
  </div>
</template>

<script>
import { translate, translatePlural } from '@nextcloud/l10n'
import { fieldTypeLabel } from '../utils/fieldTypes.js'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { dateFieldIncludesTime, padDatetimeLocal } from '../utils/dateField.js'
import { errorText } from '../utils/errorText.js'
import { PLAIN_TEXT } from '../utils/fieldValue.js'

// Simple notification helpers using OC.Notification
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
import PlayIcon from 'vue-material-design-icons/Play.vue'
import DefaultValueInput from './fields/DefaultValueInput.vue'
import defaultValuesMixin from './mixins/defaultValuesMixin.js'

export default {
  name: 'MetaVoxUser',

  mixins: [defaultValuesMixin],

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
    MagnifyIcon,
    PlayIcon,
    DefaultValueInput,
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
      selectKey: 0,

      // Cache for field options
      fieldOptionsCache: new Map()
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
    dateFieldIncludesTime,
    padDatetimeLocal,
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
          this.expandedFields[gf.id] = false
          this.metadataValues[gf.id] = {}
          this.metadataFields[gf.id] = []
          this.assignedFields[gf.id] = []
          this.tempAssignedFields[gf.id] = []
          this.fieldSearchQuery[gf.id] = ''
          this.fieldTypeFilter[gf.id] = 'all'
        })
        
        // Pre-load assigned fields + configured defaults for all groupfolders
        for (const gf of this.accessibleGroupfolders) {
          try {
            const response = await axios.get(
              generateUrl(`/apps/metavox/api/groupfolders/${gf.id}/fields`)
            )
            const fieldIds = response.data || []
            this.assignedFields[gf.id] = fieldIds
            this.tempAssignedFields[gf.id] = [...fieldIds]
          } catch (error) {
            console.error(`Failed to load fields for groupfolder ${gf.id}:`, error)
            this.assignedFields[gf.id] = []
            this.tempAssignedFields[gf.id] = []
          }
          // Default values for file fields (shared mixin).
          await this.loadFolderDefaults(gf.id)
        }
      } catch (error) {
        console.error('Failed to load groupfolders:', error)
        showError(this.t('metavox', 'Failed to load Team folders'))
      } finally {
        this.loading = false
      }
    },
    
    async toggleFieldsConfiguration(groupfolderId) {
      // Close other panels
      Object.keys(this.expandedFields).forEach(id => {
        if (id !== groupfolderId) {
          this.expandedFields[id] = false
        }
      })
      
      const isExpanded = !this.expandedFields[groupfolderId]
      this.expandedFields[groupfolderId] = isExpanded
      
      if (isExpanded) {
        // Reset temp fields to current assigned fields when opening
        this.tempAssignedFields[groupfolderId] = [...this.assignedFields[groupfolderId]]
        this.loadingFields[groupfolderId] = false
      }
    },
    
    closeFieldsConfig(groupfolderId) {
      // Reset temp fields to actual assigned fields when canceling
      this.tempAssignedFields[groupfolderId] = [...this.assignedFields[groupfolderId]]
      this.expandedFields[groupfolderId] = false
    },
    
    // --- defaultValuesMixin host contract ---
    getAssignedFieldIds(groupfolderId) {
      return this.tempAssignedFields[groupfolderId] || []
    },
    getFileFields() {
      return this.fileMetadataFields
    },
    persistFieldConfig(groupfolderId) {
      // Save assignment + defaults, keep the panel open (called by the mixin
      // before applying defaults).
      return this.saveFieldsConfiguration(groupfolderId, { keepOpen: true })
    },

    async saveFieldsConfiguration(groupfolderId, { keepOpen = false } = {}) {
      this.savingFields[groupfolderId] = true

      try {
        await axios.post(
          generateUrl(`/apps/metavox/api/groupfolders/${groupfolderId}/fields`),
          { field_ids: this.tempAssignedFields[groupfolderId] }
        )

        // Update the actual assigned fields after successful save
        this.assignedFields[groupfolderId] = [...this.tempAssignedFields[groupfolderId]]

        // Persist default values for assigned file fields (shared mixin).
        await this.saveFolderDefaults(groupfolderId)

        // TRANSLATORS Confirmation after the field choice of a Team folder was saved
        showSuccess(this.t('metavox', 'Field configuration saved successfully'))
        if (!keepOpen) {
          this.expandedFields[groupfolderId] = false
        }
      } catch (error) {
        console.error('Failed to save field configuration:', error)
        showError(this.t('metavox', 'Failed to save field configuration'))
        // Reset temp fields to actual assigned fields on error
        this.tempAssignedFields[groupfolderId] = [...this.assignedFields[groupfolderId]]
        throw error
      } finally {
        this.savingFields[groupfolderId] = false
      }
    },
    
    isFieldAssigned(groupfolderId, fieldId) {
      return (this.tempAssignedFields[groupfolderId] || []).includes(fieldId)
    },
    
    updateFieldAssignment(groupfolderId, fieldId, checked) {
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
      
      this.tempAssignedFields[groupfolderId] = fields
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
      try {
        const response = await axios.get(
          generateUrl(`/apps/metavox/api/groupfolders/${groupfolderId}/metadata`)
        )

        const metadataData = response.data || []
        const values = {}
        
        if (Array.isArray(metadataData)) {
          metadataData.forEach(item => {
            if (item.field_name && item.value !== null && item.value !== undefined) {
              values[item.field_name] = String(item.value)
            }
          })
        }
        
        this.metadataValues[groupfolderId] = values

        const assignedFieldIds = this.assignedFields[groupfolderId] || []
        const assignedGroupfolderFields = metadataData.filter(field => {
          const isAssigned = assignedFieldIds.includes(field.id)
          const isGroupfolderField = field.applies_to_groupfolder === 1 || field.applies_to_groupfolder === '1'
          return isAssigned && isGroupfolderField
        })

        this.metadataFields[groupfolderId] = assignedGroupfolderFields
        
        return { fields: assignedGroupfolderFields, values }
        
      } catch (error) {
        console.error('Error loading groupfolder metadata:', error)
        this.metadataFields[groupfolderId] = []
        this.metadataValues[groupfolderId] = {}
        throw error
      }
    },
    
    async editGroupfolderMetadata(groupfolder) {
      this.showMetadataModal = true
      this.currentGroupfolderId = groupfolder.id
      // TRANSLATORS Dialog title; {folder} is the name of a Team folder, e.g. "Edit metadata for Finance"
      this.modalTitle = this.t('metavox', 'Edit metadata for {folder}', { folder: groupfolder.mount_point }, undefined, PLAIN_TEXT)
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
            this.selectValues[field.field_name] = values[field.field_name] || null
          } else if (field.field_type === 'multiselect') {
            const value = values[field.field_name]
            if (value) {
              this.multiSelectValues[field.field_name] = value.split(';#'.filter(v => v.trim()))
            } else {
              this.multiSelectValues[field.field_name] = []
            }
          }
        })
        
        this.$nextTick(() => {
          this.selectKey++
        })
      } catch (error) {
        console.error('Error loading metadata:', error)
        // TRANSLATORS Error notice when the metadata values of a Team folder could not be loaded
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
      this.selectValues[fieldName] = value
      this.currentMetadataValues[fieldName] = value || ''
    },
    
    handleMultiSelectChange(fieldName, values) {
      this.multiSelectValues[fieldName] = values || []
      const joinedValue = Array.isArray(values) ? values.join(';#') : ''
      this.currentMetadataValues[fieldName] = joinedValue
    },
    
    updateMetadataValue(field, value) {
      this.currentMetadataValues[field.field_name] = value
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

      // Sync all select values
      this.currentMetadataFields.forEach(field => {
        if (field.field_type === 'select') {
          const value = this.selectValues[field.field_name]
          this.currentMetadataValues[field.field_name] = value || ''
        } else if (field.field_type === 'multiselect') {
          const values = this.multiSelectValues[field.field_name]
          const joinedValue = Array.isArray(values) ? values.join(';#') : ''
          this.currentMetadataValues[field.field_name] = joinedValue
        }
      })

      this.savingMetadata = true

      try {
        const response = await axios.post(
          generateUrl(`/apps/metavox/api/groupfolders/${this.currentGroupfolderId}/metadata`),
          { metadata: this.currentMetadataValues }
        )
        
        if (response.data.success) {
          this.metadataValues[this.currentGroupfolderId] = {...this.currentMetadataValues}
          
          // TRANSLATORS Confirmation after the metadata values of a Team folder were saved
          showSuccess(this.t('metavox', 'Metadata saved successfully'))
          this.closeMetadataModal()
          
          await this.loadGroupfolderMetadata(this.currentGroupfolderId)
        } else {
          throw new Error('Save failed')
        }
        
        return true
        
      } catch (error) {
        console.error('Error saving metadata:', error)
        // The server's reason is in `error` (ExceptionMiddleware), not `message`.
        // TRANSLATORS {error} is the reason the server gave, or "Please try again." when it gave none
        showError(this.t('metavox', 'Error saving metadata: {error}', {
          // TRANSLATORS Shown after "Error saving metadata:" when the server gave no reason
          error: errorText(error, this.t('metavox', 'Please try again.')),
        }, undefined, PLAIN_TEXT))
        return false
      } finally {
        this.savingMetadata = false
      }
    },
    
    getFieldOptions(field) {
      if (!field.field_options) {
        return []
      }

      // Check cache using field.id + field.field_options as key
      const cacheKey = `${field.id}_${field.field_options}`
      if (this.fieldOptionsCache.has(cacheKey)) {
        return this.fieldOptionsCache.get(cacheKey)
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

      // Cache the result
      this.fieldOptionsCache.set(cacheKey, formattedOptions)

      return formattedOptions
    },

    defaultsStatusText(groupfolderId) {
      if (this.defaultsStatus[groupfolderId] === 'running') {
        // TRANSLATORS Status beside the "Apply defaults now" button while default values are being written to files
        return this.t('metavox', 'Applying defaults …')
      }
      // TRANSLATORS Status beside the "Apply defaults now" button when nothing is running
      return this.t('metavox', 'Idle')
    },

    saveFieldsLabel(groupfolderId) {
      if (this.savingFields[groupfolderId]) {
        // TRANSLATORS Dialog button label while the changes are being saved
        return this.t('metavox', 'Saving …')
      }
      // TRANSLATORS Button that saves which fields a Team folder uses
      return this.t('metavox', 'Save configuration')
    },

    saveMetadataLabel() {
      if (this.savingMetadata) {
        // TRANSLATORS Label of the save button while the metadata is being saved; keep it short
        return this.t('metavox', 'Saving …')
      }
      // TRANSLATORS Button that saves the metadata in the edit dialog; keep it short
      return this.t('metavox', 'Save')
    },

    // Translation helper
    fieldTypeLabel,
    t: translate,
    n: translatePlural,
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