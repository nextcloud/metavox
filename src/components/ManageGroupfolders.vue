<template>
  <div class="manage-groupfolders">
    <!-- Loading state -->
    <div v-if="loading" class="loading-container">
      <div class="icon-loading"></div>
      <!-- TRANSLATORS Status text while the list of Team folders loads -->
      <p>{{ t('metavox', 'Loading Team folders …') }}</p>
    </div>

    <div v-else>
      <!-- A standing explanation of the screen, which is what NcNoteCard is
           for (§3). The hand-built box this replaced set its background and
           its border to the same colour, so the border drew nothing, and gave
           its <h4> 21px/600 — heavier than the section heading above it. -->
      <NcNoteCard type="info" class="mv-how-it-works">
        <!-- TRANSLATORS Lead-in above a short list that explains this page; keep the colon -->
        <p class="mv-how-it-works__lead">{{ t('metavox', 'How it works:') }}</p>
        <ul class="mv-how-it-works__list">
          <!-- TRANSLATORS "Configure fields" names the menu item of a Team folder row; translate it the same way -->
          <li>{{ t('metavox', 'Configure fields: Choose which fields are available for each Team folder') }}</li>
          <!-- TRANSLATORS "Edit metadata" names the menu item of a Team folder row; translate it the same way -->
          <li>{{ t('metavox', 'Edit metadata: Set Team folder-specific metadata values (admin only)') }}</li>
          <li>{{ t('metavox', 'File metadata can be edited by people within the Files app') }}</li>
        </ul>
      </NcNoteCard>

      <!-- Search Section -->
      <div class="mv-block">
        <div class="search-container">
          <!-- TRANSLATORS Label of the search field above the list of Team folders -->
          <NcTextField :label="t('metavox', 'Search Team folders')"
            :model-value="searchQuery"
            @update:model-value="searchQuery = $event"
            :show-trailing-button="!!searchQuery"
            @trailing-button-click="searchQuery = ''">
            <template #icon>
            	<MagnifyIcon :size="20" />
            </template>
          </NcTextField>
        </div>
        
        <!-- The "Found N team folders matching …" banner is gone: the count
             beside the heading now says the same thing, and the banner said
             it in a grey block of its own directly above a contradicting
             number. A search result is not a notice — no other list in this
             page announces its own filtering. -->

      </div>

      <!-- Empty State -->
      <div v-if="groupfolders.length === 0" class="empty-content">
        <FolderIcon :size="64" class="empty-icon" />
        <!-- TRANSLATORS Heading of the empty state when the server has no Team folders -->
        <h4>{{ t('metavox', 'No Team folders found') }}</h4>
        <!-- TRANSLATORS "Team Folders" is the name of the Nextcloud app that provides Team folders -->
        <p>{{ t('metavox', 'Make sure the Team Folders app is installed and you have created some Team folders.') }}</p>
      </div>

      <!-- Groupfolders List -->
      <div v-else class="mv-block">
        <div class="section-header">
          <!-- Grouped with the heading so the count sits in the same place
               here as in the other two panes. See MetadataFieldsEditor. -->
          <div class="section-header__title">
            <!-- TRANSLATORS Heading of the list of all Team folders on the server -->
            <h3>{{ t('metavox', 'Team folders') }}</h3>
            <span class="field-count">{{ folderCount }}</span>
          </div>
        </div>

        <!-- The same NcListItem row the metadata fields list uses, so the
             two lists in this settings page read alike. Hand-built, this row
             was 20px padding around a 24px icon — roughly double the pitch
             of the fields list for the same name-plus-one-line of content
             (§3: a row of data is NcListItem, not a card). -->
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

            <!-- Two labelled buttons per row is a toolbar on every line; a
                 menu keeps the row a row. §6 collapses beyond three, and at
                 eight rows the pair was 16 buttons competing with the
                 folder names. -->
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

        <!-- Load More Button -->
        <div v-if="hasMoreFolders" class="load-more-container">
          <NcButton
            @click="loadMoreFolders"
            variant="secondary"
            :disabled="loadingMore">
            <template #icon>
              <div v-if="loadingMore" class="icon-loading-small"></div>
            </template>
            {{ loadMoreLabel }}
          </NcButton>
        </div>

        <!-- Total count info -->
        <div v-if="!searchQuery && allGroupfolders.length > 20" class="total-count-info">
          <!-- TRANSLATORS {displayed} is the number of Team folders shown, {total} the number of all Team folders, e.g. "Showing 20 of 45 folders" -->
          <p>{{ n('metavox', 'Showing {displayed} of {total} folder', 'Showing {displayed} of {total} folders', allGroupfolders.length, {
            displayed: groupfolders.length,
            total: allGroupfolders.length
          }, PLAIN_TEXT) }}</p>
        </div>
      </div>
    </div>


    <!-- Configuring a folder's fields is a form, so it is a dialog and not an
         inline panel (§3). As a panel it pushed the rest of the list down,
         gave its own Save row no fixed place, and on a phone the fields sat
         below the fold with the row they belonged to scrolled off the top.
         Same shape as the view editor in the Files app: NcDialog, size
         "normal", the footer as a :buttons list. -->
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
        :empty-text="t('metavox', 'No fields available. Create some fields first in the field management tabs.')"
        can-apply-defaults
        :applying-defaults="defaultsStatus[configFolderId] === 'running'"
        @update:assigned-ids="tempAssignedFields[configFolderId] = $event"
        @update:default="(field, value) => setDefaultValue(configFolderId, field, value)"
        @apply-defaults="triggerDefaults(configFolderId)" />

    </NcDialog>

    <!-- Metadata Edit Modal -->
    <!-- NcModal has no `title` prop — the name it was given rendered nowhere, so
         this dialog opened with an empty header and no accessible name. It is
         also a form, which §3 says is NcDialog's job: the dialog places the
         buttons, puts the primary one last and returns focus to the trigger.
         size="normal" because a column of labelled fields does not need 900px. -->
    <NcDialog
      v-if="showMetadataModal"
      :name="t('metavox', 'Edit Team folder metadata')"
      :no-close="savingMetadata"
      :close-on-click-outside="!savingMetadata"
      :buttons="metadataDialogButtons"
      size="normal"
      @closing="closeMetadataModal">
      <div class="modal-content">
        <p class="modal-description">{{ folderNameForDialog }}</p>
        
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
          
          <!-- Extracted: this form was 117 lines duplicated with
               MetaVoxPersonal, differing on four. See MetadataValueForm. -->
          <MetadataValueForm
            v-else
            :fields="currentMetadataFields"
            :model-value="currentMetadataValues"
            :errors="metadataErrors"
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
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcRadioGroup from '@nextcloud/vue/components/NcRadioGroup'
import NcRadioGroupButton from '@nextcloud/vue/components/NcRadioGroupButton'
// Icons
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import EditIcon from 'vue-material-design-icons/Pencil.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import ContentSaveIcon from 'vue-material-design-icons/ContentSave.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import PlayIcon from 'vue-material-design-icons/Play.vue'
import FieldConfigurationPanel from './FieldConfigurationPanel.vue'
import MetadataValueForm from './MetadataValueForm.vue'
import defaultValuesMixin from './mixins/defaultValuesMixin.js'
import folderListMixin from './mixins/folderListMixin.js'

export default {
  name: 'ManageGroupfolders',

  // Templates read the t() options from the component.
  setup() {
    return { PLAIN_TEXT }
  },

  mixins: [defaultValuesMixin, folderListMixin],

  components: {
    NcActionButton,
    NcListItem,
    NcButton,
    NcTextArea,
    NcTextField,
    NcSelect,
    NcCheckboxRadioSwitch,
    NcDialog,
    NcLoadingIcon,
    NcNoteCard,
    NcRadioGroup,
    NcRadioGroupButton,
    FolderIcon,
    EditIcon,
    CogIcon,
    ContentSaveIcon,
    MagnifyIcon,
    PlayIcon,
    FieldConfigurationPanel,
    MetadataValueForm,
  },
  
  data() {
    return {
      loading: false,
      loadingMetadata: false,
      groupfolders: [],
      allGroupfolders: [], // All folders loaded from API
      displayedCount: 20, // Number of folders to display initially
      loadingMore: false, // Loading more folders
      allFields: [],
      searchQuery: '',

      // Expanded states
      configFolderId: null,

      // Loading states
      loadingFields: {},
      savingFields: {},

      // Data storage
      metadataValues: {},
      metadataFields: {},
      assignedFields: {},
      tempAssignedFields: {},

      // Field search and filter

      // Metadata modal state
      showMetadataModal: false,
      currentGroupfolderId: null,
      currentMetadataFields: [],
      currentMetadataValues: {},
      savingMetadata: false,
      currentMountPoint: '',


      // Default-value state (fileFieldDefaults / defaultsStatus /
      // defaultsPollTimers) comes from defaultValuesMixin.
    }
  },
  
  computed: {
    /**
     * The three filters, with their counts. The count belongs in the label
     * rather than beside it: an NcRadioGroupButton takes one string, and a
     * number rendered outside it would not be part of the control's
     * accessible name.
     */


    /**
     * The folder this dialog is editing. The dialog's own title says what the
     * dialog is; the thing it acts on belongs in the body (§3).
     */
    folderNameForDialog() {
      // TRANSLATORS {folder} is the name of a Team folder, e.g. "Editing metadata for Finance"
      return this.t('metavox', 'Editing metadata for {folder}', { folder: this.currentMountPoint }, undefined, PLAIN_TEXT)
    },




    loadMoreLabel() {
      if (this.loadingMore) {
        // TRANSLATORS Button text while more Team folders load; keep it short
        return this.t('metavox', 'Loading …')
      }
      // TRANSLATORS Button that shows more Team folders; %n is how many are not shown yet
      return this.n('metavox', 'Show %n more folder', 'Show %n more folders', this.allGroupfolders.length - this.displayedCount)
    },

    hasMoreFolders() {
      return this.displayedCount < this.allGroupfolders.length && !this.searchQuery
    },



    foldersWithFields() {
      // Count team folders that have fields assigned
      return this.groupfolders.filter(gf => {
        const assigned = this.assignedFields[gf.id] || []
        return assigned.length > 0
      }).length
    }
  },
  
  async mounted() {
    // Laad fields en groupfolders met field counts
    await this.loadAllFields()
    await this.loadGroupfoldersWithFieldCounts()
  },

  // Poll-timer cleanup is handled by defaultValuesMixin.beforeUnmount().

  methods: {
    /**
     * folderListMixin host contract: the folders this screen may show
     * (admin: paged, so a search reads the full list rather than the page).
     */
    folderSource() {
      // Searching reaches past the current page, so it reads the full list.
      return this.searchQuery ? this.allGroupfolders : this.groupfolders
    },

    dateFieldIncludesTime,
    padDatetimeLocal,
    async loadGroupfoldersWithFieldCounts() {
      this.loading = true
      try {
        const response = await axios.get(generateUrl('/apps/metavox/api/groupfolders'))
        this.allGroupfolders = response.data || []

        // Load initial batch
        this.groupfolders = this.allGroupfolders.slice(0, this.displayedCount)

        // Initialize state objects for initial batch
        await this.initializeFolders(this.groupfolders)
      } catch (error) {
        console.error('Failed to load groupfolders:', error)
        showError(this.t('metavox', 'Failed to load Team folders'))
      } finally {
        this.loading = false
      }
    },

    async initializeFolders(folders) {
      // Initialize state objects
      folders.forEach(gf => {
        if (this.metadataValues[gf.id] === undefined) {
          this.metadataValues[gf.id] = {}
          this.metadataFields[gf.id] = []
          this.assignedFields[gf.id] = []
          this.tempAssignedFields[gf.id] = []
          this.fileFieldDefaults[gf.id] = {}
          this.defaultsStatus[gf.id] = 'idle'
        }
      })

      // Pre-load assigned fields (only field IDs, no metadata)
      for (const gf of folders) {
        if (this.assignedFields[gf.id] && this.assignedFields[gf.id].length > 0) {
          continue // Already loaded
        }

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

        // Load configured default values for file fields (shared mixin).
        await this.loadFolderDefaults(gf.id)
      }
    },

    async loadMoreFolders() {
      if (this.loadingMore || !this.hasMoreFolders) {
        return
      }

      this.loadingMore = true
      try {
        const nextCount = Math.min(this.displayedCount + 20, this.allGroupfolders.length)
        const newFolders = this.allGroupfolders.slice(this.displayedCount, nextCount)

        // Add to displayed folders
        this.groupfolders.push(...newFolders)
        this.displayedCount = nextCount

        // Initialize new folders
        await this.initializeFolders(newFolders)
      } catch (error) {
        console.error('Failed to load more folders:', error)
      } finally {
        this.loadingMore = false
      }
    },

    handleScroll() {
      const scrollableElement = this.$el.querySelector('.groupfolders-list')
      if (!scrollableElement) return

      const scrollTop = scrollableElement.scrollTop
      const scrollHeight = scrollableElement.scrollHeight
      const clientHeight = scrollableElement.clientHeight

      // Load more when scrolled to 80% of the list
      if (scrollTop + clientHeight >= scrollHeight * 0.8) {
        this.loadMoreFolders()
      }
    },
    
    async loadAllFields() {
      try {
        const response = await axios.get(generateUrl('/apps/metavox/api/groupfolder-fields'))
        this.allFields = response.data || []
      } catch (error) {
        console.error('Failed to load fields:', error)
        this.allFields = []
      }
    },
    
    async loadGroupfolderMetadata(groupfolderId) {
      try {
        const response = await axios.get(
          generateUrl(`/apps/metavox/api/groupfolders/${groupfolderId}/metadata`)
        )

        const metadataData = response.data || []
        const values = {}

        // Process the metadata response - map field data with values
        if (Array.isArray(metadataData)) {
          metadataData.forEach(item => {
            if (item.field_name && item.value !== null && item.value !== undefined) {
              values[item.field_name] = String(item.value)
            }
          })
        }

        this.metadataValues[groupfolderId] = values

        // Store the full field definitions including the values
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
      this.$nextTick(() => this.closeRowMenu())
      this.showMetadataModal = true
      this.currentGroupfolderId = groupfolder.id
      this.currentMountPoint = groupfolder.mount_point
      this.loadingMetadata = true
      
      this.currentMetadataValues = {}
      this.currentMetadataFields = []
      
      try {
        // Load metadata and fields
        const { fields, values } = await this.loadGroupfolderMetadata(groupfolder.id)
        
        // Set current fields and values for the modal
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
    
    async saveFieldsConfiguration(groupfolderId, { keepOpen = false } = {}) {
      this.savingFields[groupfolderId] = true

      try {
        // Save the temporary assigned fields
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
        // Keep the panel open when saving as part of "Apply defaults now".
        if (!keepOpen) {
          this.configFolderId = null
        }
      } catch (error) {
        showError(errorText(error, this.t('metavox', 'Failed to save field configuration')))
        // Reset temp fields to actual assigned fields on error
        this.tempAssignedFields[groupfolderId] = [...this.assignedFields[groupfolderId]]
        throw error // let triggerDefaults know the save failed
      } finally {
        this.savingFields[groupfolderId] = false
      }
    },
    

    
    
    getGroupfolderFields(groupfolderId) {
      return this.metadataFields[groupfolderId] || []
    },
    
    
    
    // --- defaultValuesMixin host contract ---
    persistFieldConfig(groupfolderId) {
      // Save the field assignment + defaults but keep the panel open (the mixin
      // calls this before applying defaults).
      return this.saveFieldsConfiguration(groupfolderId, { keepOpen: true })
    },

    // The platform's own. The helper this replaced read OC.L10N directly and
    // had no plural form at all, which is why no count on this screen went
    // through n(). Its {key} replacer also only replaced the first occurrence
    // and did not escape, so a value containing $& corrupted the string.
    t: translate,
    n: translatePlural,
  }
}
</script>

<style scoped>
/* Base layout */
/* No measure and no inset here: NcSettingsSection owns both (§6, "The measure
   belongs on the form"), and a second one on the child pushed this section's
   content 20px further in than the sections that had none — measured as three
   different left edges, 28 / 40 / 48, down one page. */

.mv-block {
  /* A block inside an NcSettingsSection, not a card on top of one: the
     section already supplies the surface, the measure and the rule that
     separates it from the next. The card look this carried — a radius and a
     hardcoded rgba box-shadow that no theme can reach — drew a second frame
     inside the first, and the shadow was invisible in dark mode anyway. */
  margin-bottom: 24px;
}

/* Matches MetadataFieldsEditor's header exactly, so the two lists in this
   page open the same way. The border-bottom is gone: §6 reserves a rule for
   a section boundary, and a heading over its own list is not one — the
   fields list never had it. 20/15px were also off the 4px grid. */
/* The folder a dialog is acting on: the title says what the dialog is, the
   body says what it acts on (§3). Both were unstyled — a bare <p> at body
   size reading as content rather than context. */
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
  /* A heading and its count do not fit on one line on a phone, and this row
     cannot scroll, so it wraps. */
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 16px;
  padding-bottom: 16px;
}

/* Heading and count as one unit, so space-between pushes the pair left and
   any actions right. baseline, not center: the count is smaller than the h3.
   Third copy of this rule in the page — see the note on .field-count. */
.section-header__title {
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: 8px;
  min-width: 0;
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

.search-container {
  margin-bottom: 20px;
}

.empty-content {
  text-align: center;
  padding: 48px 20px;
  color: var(--color-text-maxcontrast);
}

.empty-icon {
  margin-bottom: 20px;
  opacity: 0.5;
}

.empty-content h4 {
  margin: 0 0 10px 0;
  font-weight: 600;
}

/* NcListItem owns the row: its height, hover, radius and action menu. All
   that is left is the list's own reset, since it is a <ul> now. */
.groupfolders-list {
  list-style: none;
  padding: 0;
  margin: 0;
}

.loading-section {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 40px;
  color: var(--color-text-maxcontrast);
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

/* Modal styling */
.modal-content {
  padding: 20px;
}

/* `0.9em` computed to 13.5px — a third size in one dialog, and not on the
   scale at all. */
.modal-description {
  margin: 0 0 24px 0;
  color: var(--color-text-maxcontrast);
}

.metadata-form-container {
  min-height: 200px;
}

/* 24px is NcAppSettingsSection's distance between groups; with the boxes
   gone this is what separates one labelled field from the next (§6). */
.metadata-form {
  display: flex;
  flex-direction: column;
  gap: 24px;
}

/* Each field was a grey box with its own padding: the same sunken-well
   treatment removed from the dropdown options, wrapped around every row of
   an ordinary form. Fields in a form are grouped by whitespace, not by a
   container (§6), and the box also inset every control 16px from the
   dialog's own edge. The 8px gap is the NcFormGroup distance. */
.field-item {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

/* 14px was not a token — the scale is 13 (--font-size-small) or 15
   (--default-font-size), and NcTextField's own label is the small one, so
   these sit in the same rhythm as the controls below them (§6). */
.field-label {
  font-weight: 500;
  color: var(--color-main-text);
  font-size: var(--font-size-small);
  display: block;
  margin-bottom: 4px;
}

.required {
  color: var(--color-error);
  margin-inline-start: 4px;
}

/* No min-height: NcSelect and the field components own theirs, and the
   platform's is 34px (§6, "Every control in the form measures the same
   height"). */
.field-input {
  width: 100%;
  max-width: 480px;
}

/* The .dv--* width caps and the .default-value-input / .default-value-label
   rules that stood here were scoped to this component while the elements are
   rendered by DefaultValueInput.vue. Vue rewrites a scoped selector with the
   DECLARING component's data-v id, so none of them ever matched (§6). The
   child carries its own copy, which is why nobody noticed — and the two had
   diverged: this copy added a 28px hanging indent and a 3px rail that do not
   render. If the indent is wanted, it belongs in the child. */


/* Native input styling for consistency */
/* The three inputs these styled are NcTextArea and NcTextField now, so
   these rules would land on the component wrapper rather than the
   input, and fight its own border, height and focus ring. They also
   hardcoded 14px and used --color-primary, a header colour, as a
   border. The components carry all of it (§4). */

/* Body size, like NcTextField's own helper text, which sets no size and
   inherits 15px. Small *and* maxcontrast is the pairing §5 warns about. */
.field-description {
  color: var(--color-text-maxcontrast);
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

.no-fields-available {
  text-align: center;
  padding: 40px;
  color: var(--color-text-maxcontrast);
  background: var(--color-background-hover);
  border-radius: var(--border-radius);
}

.field-search-section {
  margin-bottom: 20px;
  padding: 15px;
  background: var(--color-background-hover);
  border-radius: var(--border-radius);
}

/* The pill row that filters the list. NcRadioGroup owns every rule the old
   hand-built version restated — height, radius, the active tint, the focus
   ring, and the :focus-vs-:focus-visible correction core needs — so ~60 lines
   of overrides went with it (§16). */
.field-type-filter {
  margin-top: 16px;
}

.no-search-results {
  text-align: center;
  padding: 20px;
  color: var(--color-text-maxcontrast);
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
  color: var(--color-main-text);
}

/* 0.9em is not on the scale, and 15px is not on the 4px grid. */
.section-description {
  margin: 0 0 16px 0;
  color: var(--color-text-maxcontrast);
}

.checkbox-group {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.file-field-row {
  display: flex;
  flex-direction: column;
  gap: 8px;
}


.apply-defaults-section {
  margin-top: 20px;
  padding-top: 15px;
  border-top: 1px solid var(--color-border);
}

.apply-defaults-actions {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.apply-defaults-status {
  color: var(--color-text-maxcontrast);
}

.field-type-label {
  color: var(--color-text-maxcontrast);
  /* logical, and on the 4px grid */
  margin-inline-start: 4px;
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
  padding: 48px 20px;
  color: var(--color-text-maxcontrast);
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

/* The block that stood here overrode @nextcloud/vue's internals with 22
   !important declarations: vs__dropdown-toggle borders, the dropdown's
   background, the selected chips, a 44px min-height and a z-index war
   (.modal-wrapper at 9999, below the 10001/10002 it was fighting). §5 and §6
   both say not to patch library internals — it is how an app breaks on the
   next release — and it was unnecessary: NcSelect defaults appendToBody, so
   the menu already escapes the dialog. Three of the colours it used
   (--color-primary-light, --color-primary-text, --color-primary) are the
   deprecated pair or do not exist at all, so those declarations set nothing.

   The one rule worth keeping is the row height, and it belongs with the other
   controls rather than on the select alone. */
.metadata-form :deep(.input-field__main-wrapper),
.metadata-form :deep(.vs__dropdown-toggle),
.metadata-form :deep(textarea),
.metadata-form :deep(input) {
  min-height: var(--default-clickable-area);
}

/* Load More Button */
.load-more-container {
  display: flex;
  justify-content: center;
  padding: 30px 0;
  margin-top: 20px;
}

.total-count-info {
  text-align: center;
  color: var(--color-text-maxcontrast);
  padding: 8px 0;
}

.total-count-info p {
  margin: 0;
}

/* Phone. The rules that rescued the hand-built folder row are gone with it —
   NcListItem handles narrow widths itself, and its actions are a menu rather
   than a row of labelled buttons that needed breaking onto separate lines. */
@media (max-width: 512px) {
	.field-section {
		padding: 16px;
	}
}
</style>