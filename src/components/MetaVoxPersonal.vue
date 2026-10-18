<template>
  <div id="metavox-user">
    <!-- NcSettingsSection, not a hand-built .settings-section: §6 says a
         settings page is built from the component, which owns the 900px
         measure, the inset and the heading. This page had three divs using
         the library's own class name (§6 forbids borrowing it) and an <h2>
         plus a <p> reproducing what the component renders itself. -->
    <!-- TRANSLATORS Heading of the MetaVox section in the personal settings -->
    <NcSettingsSection :name="t('metavox', 'Personal Team folder settings')"
      :description="t('metavox', 'Configure metadata fields and manage metadata for your Team folders.')">

      <!-- A standing explanation of the screen is NcNoteCard (§3). The
           hand-built .info-box was a grey card whose <h4> rendered at
           21px/600 — heavier than the section heading above it. -->
      <NcNoteCard type="info" class="mv-how-it-works">
        <!-- TRANSLATORS Lead-in above a short list of what this page offers; keep the colon -->
        <p class="mv-how-it-works__lead">{{ t('metavox', 'What you can do:') }}</p>
        <ul class="mv-how-it-works__list">
          <li>{{ t('metavox', 'Configure which metadata fields are available for your Team folders') }}</li>
          <li>{{ t('metavox', 'Edit Team folder metadata values') }}</li>
          <li>{{ t('metavox', 'File metadata can be edited by people within the Files app') }}</li>
        </ul>
      </NcNoteCard>

      <div v-if="loading" class="loading-container">
        <NcLoadingIcon :size="44" />
        <!-- TRANSLATORS Status text while the list of Team folders loads -->
        <p>{{ t('metavox', 'Loading your Team folders …') }}</p>
      </div>

      <template v-else>
        <div v-if="accessibleGroupfolders.length > 0" class="mv-block">
          <!-- TRANSLATORS Label of the search field above the list of Team folders -->
          <NcTextField :label="t('metavox', 'Search Team folders')"
            :model-value="searchQuery"
            :show-trailing-button="!!searchQuery"
            @update:model-value="searchQuery = $event"
            @trailing-button-click="searchQuery = ''">
            <template #icon>
              <MagnifyIcon :size="20" />
            </template>
          </NcTextField>
        </div>

        <NcEmptyContent v-if="accessibleGroupfolders.length === 0"
          :name="t('metavox', 'No accessible Team folders')"
          :description="t('metavox', 'You do not have access to any Team folders yet. Contact your administrator if you need access.')">
          <template #icon>
            <FolderIcon :size="20" />
          </template>
        </NcEmptyContent>

        <div v-else class="mv-block">
          <div class="section-header">
            <!-- Heading and count as one unit, so the count sits in the same
                 place here as on the admin page (§6). -->
            <div class="section-header__title">
              <!-- TRANSLATORS Heading of the list of Team folders this account can open -->
              <h3>{{ t('metavox', 'Your Team folders') }}</h3>
              <span class="field-count">{{ folderCount }}</span>
            </div>
          </div>

          <!-- The same NcListItem row the admin page uses. Hand-built, these
               rows were bordered cards 98px tall with a 24px icon, a 21px
               name and two labelled buttons each — §3 says a row of data is
               NcListItem, not a card. -->
          <ul class="groupfolders-list">
            <NcListItem
              v-for="groupfolder in filteredGroupfolders"
              :key="groupfolder.id"
              :name="groupfolder.mount_point"
              :force-display-actions="true"
              @click="openGroupfolder(groupfolder, $event)">
              <template #icon>
                <FolderIcon :size="20" />
              </template>

              <template #subname>
                {{ fieldsSummary(groupfolder.id) }}
              </template>

              <template #actions>
                <NcActionButton @click="toggleFieldsConfiguration(groupfolder.id)">
                  <template #icon>
                    <CogIcon :size="20" />
                  </template>
                  <!-- TRANSLATORS Menu item that opens the dialog to choose the metadata fields of a Team folder -->
                  {{ t('metavox', 'Configure fields') }}
                </NcActionButton>
                <NcActionButton
                  :disabled="getAssignedGroupfolderFieldsCount(groupfolder.id) === 0"
                  @click="editGroupfolderMetadata(groupfolder)">
                  <template #icon>
                    <EditIcon :size="20" />
                  </template>
                  <!-- TRANSLATORS Menu item that opens the dialog to edit the metadata values of a Team folder -->
                  {{ t('metavox', 'Edit metadata') }}
                </NcActionButton>
              </template>
            </NcListItem>
          </ul>
        </div>
      </template>
    </NcSettingsSection>

    <!-- Configuring a folder's fields is a form, so it is a dialog and not
         an inline panel that pushes the list down (§3). Same shape as the
         admin page, and now literally the same component inside. -->
    <!-- TRANSLATORS Title of the dialog to choose the metadata fields of a Team folder -->
    <NcDialog v-if="configFolderId !== null" :name="t('metavox', 'Configure fields')"
      :no-close="savingFields[configFolderId]"
      :close-on-click-outside="!savingFields[configFolderId]"
      :buttons="configDialogButtons"
      size="normal"
      @closing="closeFieldsConfig(configFolderId)">

      <p class="mv-config__folder">{{ configFolderName }}</p>

      <NcLoadingIcon v-if="loadingFields[configFolderId]" :size="32" class="mv-config__loading" />

      <FieldConfigurationPanel v-else
        :folder-id="configFolderId"
        :folder-fields="groupfolderMetadataFields"
        :file-fields="fileMetadataFields"
        :assigned-ids="tempAssignedFields[configFolderId] || []"
        :defaults="fileFieldDefaults[configFolderId] || {}"
        :default-files="fileFieldDefaultFiles[configFolderId] || {}"
        :empty-text="t('metavox', 'No fields available. Contact your administrator to create metadata fields.')"
        @update:assigned-ids="tempAssignedFields[configFolderId] = $event"
        @update:default="(field, value) => setDefaultValue(configFolderId, field, value)" />
    </NcDialog>


    <!-- Metadata Edit Modal -->
    <!-- NcDialog, not NcModal, and :name, not :title.
         NcModal declares no `title` prop — only `name` — so the heading was
         dropped in silence and this dialog had no accessible name at all.
         §3 also makes NcDialog the default for a form, and §6 asks for
         size="normal" rather than large for a column of labelled fields.
         Its twin ManageGroupfolders was converted during the design pass;
         this file was missed. -->
    <NcDialog
      v-if="showMetadataModal"
      :name="modalTitle"
      :buttons="metadataDialogButtons"
      size="normal"
      @closing="closeMetadataModal">
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
          
          <!-- Same component as ManageGroupfolders. The <form> wrapper is
               gone with it: the save button lives in NcDialog's :buttons
               now, so there is no submit to intercept. -->
          <MetadataValueForm
            v-else
            :fields="currentMetadataFields"
            :model-value="currentMetadataValues"
            :errors="metadataErrors"
            :base-path="currentFolderPath"
            @update:model-value="setMetadataValues" />
        </div>
      </div>
    </NcDialog>
  </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate, translatePlural } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { dateFieldIncludesTime, padDatetimeLocal } from '../utils/dateField.js'
import { errorText } from '../utils/errorText.js'
import { PLAIN_TEXT } from '../utils/fieldValue.js'


// Nextcloud Vue components
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
// Icons
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import EditIcon from 'vue-material-design-icons/Pencil.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import FieldConfigurationPanel from './FieldConfigurationPanel.vue'
import MetadataValueForm from './MetadataValueForm.vue'
import defaultValuesMixin from './mixins/defaultValuesMixin.js'
import folderListMixin from './mixins/folderListMixin.js'

export default {
  name: 'MetaVoxUser',

  mixins: [defaultValuesMixin, folderListMixin],

  components: {
    CogIcon,
    EditIcon,
    FieldConfigurationPanel,
    FolderIcon,
    MagnifyIcon,
    MetadataValueForm,
    NcActionButton,
    NcDialog,
    NcEmptyContent,
    NcListItem,
    NcLoadingIcon,
    NcNoteCard,
    NcSettingsSection,
    NcTextField,
  },
  
  data() {
    return {
      loading: false,
      loadingMetadata: false,
      accessibleGroupfolders: [],
      allFields: [],
      searchQuery: '',

      // The folder whose Configure fields dialog is open, or null. Was a
      // map of expanded rows; a dialog shows one at a time.
      configFolderId: null,

      // Loading states
      loadingFields: {},
      savingFields: {},

      // Data storage
      metadataValues: {},
      metadataFields: {},
      assignedFields: {},
      tempAssignedFields: {},

      // Metadata modal state
      showMetadataModal: false,
      currentGroupfolderId: null,
      currentMetadataFields: [],
      currentMetadataValues: {},
      savingMetadata: false,
      modalTitle: '',
    }
  },
  
  computed: {
    // Where the open team folder is in your files: the file picker starts there.
    currentFolderPath() {
      const folder = this.accessibleGroupfolders.find(gf => gf.id === this.currentGroupfolderId)
      return folder?.mount_point ? '/' + folder.mount_point : ''
    },



    /** Shared shape with ManageGroupfolders; the count is inside the label. */
    fieldTypeOptions() {
      return [
        // TRANSLATORS Filter option that shows Team folder fields and file fields together; keep it short
        { value: 'all', label: this.t('metavox', 'All fields') },
        {
          value: 'groupfolder',
          // TRANSLATORS Filter option; %n is the number of fields that hold metadata for the Team folder itself
          label: this.n('metavox', '%n Team folder field', '%n Team folder fields',
            this.groupfolderMetadataFields.length),
        },
        {
          value: 'file',
          // TRANSLATORS Filter option; %n is the number of fields that hold metadata for files
          label: this.n('metavox', '%n file field', '%n file fields',
            this.fileMetadataFields.length),
        },
      ]
    },


    
    
  },
  
  async mounted() {
    await this.loadAllFields()
    await this.loadAccessibleGroupfolders()
  },
  
  methods: {
    /**
     * folderListMixin host contract: the folders this screen may show
     * (personal: the folders this user can reach).
     */
    folderSource() {
      return this.accessibleGroupfolders
    },

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
          this.metadataValues[gf.id] = {}
          this.metadataFields[gf.id] = []
          this.assignedFields[gf.id] = []
          this.tempAssignedFields[gf.id] = []
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
    



    
    // --- defaultValuesMixin host contract ---
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
        showSuccess(this.t('metavox', 'Field configuration saved'))
        if (!keepOpen) {
          this.configFolderId = null
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
      // No dialog on top of a dialog (§3): both open from the same row
      // menu, so each closes the other.
      this.configFolderId = null
      this.showMetadataModal = true
      this.currentGroupfolderId = groupfolder.id
      // TRANSLATORS Dialog title; {folder} is the name of a Team folder, e.g. "Edit metadata for Finance"
      this.modalTitle = this.t('metavox', 'Edit metadata for {folder}', { folder: groupfolder.mount_point }, undefined, PLAIN_TEXT)
      this.loadingMetadata = true
      
      this.currentMetadataValues = {}
      this.currentMetadataFields = []
      
      try {
        const { fields, values } = await this.loadGroupfolderMetadata(groupfolder.id)
        
        this.currentMetadataFields = [...fields]
        this.currentMetadataValues = {...values}
      } catch (error) {
        console.error('Error loading metadata:', error)
        // TRANSLATORS Error notice when the metadata values of a Team folder could not be loaded
        showError(this.t('metavox', 'Error loading metadata'))
        this.currentMetadataFields = []
        this.currentMetadataValues = {}
      } finally {
        this.loadingMetadata = false
      }
    },
    
    
    async saveGroupfolderMetadata() {
      if (!this.currentGroupfolderId) return false

      // Required is required: not saved until each has its value.
      if (!this.checkRequiredMetadata()) return false

      this.savingMetadata = true

      try {
        const response = await axios.post(
          generateUrl(`/apps/metavox/api/groupfolders/${this.currentGroupfolderId}/metadata`),
          { metadata: this.currentMetadataValues }
        )
        
        if (response.data.success) {
          this.metadataValues[this.currentGroupfolderId] = {...this.currentMetadataValues}
          
          // TRANSLATORS Confirmation after the metadata values of a Team folder were saved
          showSuccess(this.t('metavox', 'Metadata saved'))
          // Closing forgets which folder this was.
          const groupfolderId = this.currentGroupfolderId
          this.closeMetadataModal()
          await this.loadGroupfolderMetadata(groupfolderId)
        } else {
          throw new Error('Save failed')
        }
        
        return true
        
      } catch (error) {
        if (this.showMetadataRefusal(error)) return false
        // The server's reason is in `error` (ExceptionMiddleware), not `message`.
        // TRANSLATORS {error} is the reason the server gave, or "Please try again." when it gave none
        showError(this.t('metavox', 'Error saving metadata: {error}', {
          // TRANSLATORS Shown after "Error saving metadata:" when the server gave no reason
          error: errorText(error, this.t('metavox', 'Please try again.')),
        }))
        return false
      } finally {
        this.savingMetadata = false
      }
    },
    
    // The platform's own; the helper this replaced had no plural form, which
    // is why this screen counted with {count} interpolation.
    t: translate,
    n: translatePlural,
  }
}
</script>

<style scoped>
/* Base layout */
.mv-block {
  /* A block inside an NcSettingsSection, not a card on top of one: the
     section already supplies the surface and the measure. */
  margin-bottom: 24px;
}

/* NcNoteCard supplies the surface, the accent rail and the icon. All that is
   left is the list inside it. */
/* NcNoteCard pins `color: var(--color-main-text) !important` against
   `background: var(--color-info)`, so the two only stay legible while a
   theme moves them together. A third-party theming app on test.voxcloud.nl
   forces `--color-info: #00598a` and `--color-main-text: #222222`, both
   !important: dark text on a dark blue card, measured 2.12:1 against a
   4.5:1 floor.

   --color-info-text is the token that belongs with --color-info — it is
   what NcNoteCard already uses for the accent rail and the icon — so
   pairing the body text with it keeps the card legible whatever a theme
   does to the pair. On an untouched Nextcloud this changes nothing
   visible: 13.48:1 becomes 12.63:1, both far past the floor.

   The double class beats the library's !important without adding our own:
   0,2,0 against its 0,1,0. */
.mv-how-it-works.mv-how-it-works {
  color: var(--color-info-text);
}

.mv-how-it-works__lead {
  margin: 0 0 8px;
  font-weight: var(--font-weight-element);
}

.mv-how-it-works__list {
  margin: 0;
  padding-inline-start: 16px;
}

.mv-how-it-works__list li {
  margin-block: 4px;
}

/* Heading and count as one unit, so space-between pushes the pair left and
   any actions right. baseline, not center: the count is the smaller text. */
.section-header__title {
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: 8px;
  min-width: 0;
}

/* The folder a dialog is acting on: the title says what the dialog is, the
   body says what it acts on (§3). */
.mv-config__folder {
  margin: 0 0 16px;
  color: var(--color-text-maxcontrast);
}

.mv-config__loading {
  margin: 24px auto;
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
  /* min-width: 0 lets a long heading wrap instead of forcing the flex row
     wider than its container. */
  min-width: 0;
  overflow-wrap: anywhere;
  margin: 0;
  /* §6 names 20px for an h3, and Nextcloud has no variable for it. Left to
     inherit, this rendered at 24px against the section title's 27px — barely
     a step, so the list heading competed with the page heading. */
  font-size: 20px;
  font-weight: var(--font-weight-heading, 600);
  color: var(--color-main-text);
}

.field-count {
  /* A count beside a heading is supporting text, not a badge: a filled pill
     says "something needs attention here", and this is just how many there
     are. §6 — supporting text is --font-size-small in --color-text-maxcontrast.
     Two of the three copies of this rule also used 0.9em and a hardcoded
     radius, neither of which is a token. */
  color: var(--color-text-maxcontrast);
  font-size: var(--font-size-small);
  font-weight: var(--font-weight-default, 400);
}

.groupfolders-list {
  display: flex;
  flex-direction: column;
  gap: 15px;
}

/* --color-primary is the header colour; an interactive or accent element
   takes --color-primary-element (§5). They happen to resolve the same on
   the default theme, which is why this went unnoticed — a theme with a
   dark header would show it. */
.loading-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 15px;
  padding: 48px 20px;
  color: var(--color-text-maxcontrast);
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
  color: var(--color-text-maxcontrast);
  font-size: var(--font-size-small);
}

.no-fields-message {
  text-align: center;
  padding: 30px;
  background: var(--color-warning);
  border-radius: var(--border-radius);
}

.no-fields-message p {
  margin: 0 0 10px 0;
  color: var(--color-text-maxcontrast);
}

.no-fields-message p:last-child {
  margin-bottom: 0;
}

/* 14px is not on the scale; NcTextField's own label is --font-size-small,
   so these match the controls below them (§6). */
/* The components own their height (34px). Forcing 44px made these taller
   than every other control in the same row (§6). */
/* Native input styling for consistency */
/* The three inputs these styled are NcTextArea and NcTextField now, so
   these rules would land on the component wrapper rather than the
   input, and fight its own border, height and focus ring. They also
   hardcoded 14px and used --color-primary, a header colour, as a
   border. The components carry all of it (§4). */

/* Modal actions within form */
/* Fix for NcSelect visibility in modal */
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
/* Responsive Design */
</style>