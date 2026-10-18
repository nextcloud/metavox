<template>
  <div class="permissions-manager">
    <!-- Loading State -->
    <div v-if="loading" class="loading-container">
      <NcLoadingIcon :size="44" />
      <!-- TRANSLATORS Status text while the list of permissions loads -->
      <p>{{ t('metavox', 'Loading permissions …') }}</p>
    </div>

    <div v-else>
      <!-- Add Permission Section -->
      <div class="mv-block">
        <!-- No heading over this row. "Grant permission" sat above a button
             reading "Add permission": the same instruction twice, and the
             heading named a section whose entire content was that one
             button. §6 keeps a heading for a group of things that needs
             naming; a lone action names itself. The section's own
             NcSettingsSection title ("User permissions") already says what
             the page is. -->
        <div class="section-header section-header--action-only">
          <NcButton
            v-if="!showAddForm"
            @click="showAddForm = true"
            variant="primary">
            <template #icon>
              <PlusIcon :size="20" />
            </template>
            <!-- TRANSLATORS Button that opens the form to give groups a permission; keep it short -->
            {{ t('metavox', 'Add permission') }}
          </NcButton>
        </div>

        <!-- Add Permission Form -->
        <div v-if="showAddForm" class="add-permission-form">
          <!-- Group Selection with Multiselect (like Groupfolders) -->
          <div class="form-row">
            <!-- TRANSLATORS Label above the group picker in the add permission form -->
            <label class="field-label">{{ t('metavox', 'Select groups') }}</label>
            <NcSelect
              v-model="formData.selectedGroups"
              :options="availableGroups"
              :multiple="true"
              label="displayname"
              track-by="id"
              :placeholder="t('metavox', 'Search and select groups …')"
              :loading="loadingGroups"
              @search-change="searchGroups">
              <template #noResult>
                <!-- TRANSLATORS Shown in the group picker when the search matches no group -->
                {{ t('metavox', 'No groups found') }}
              </template>
            </NcSelect>
            <p class="field-help-text">
              {{ t('metavox', 'People in the selected groups will be able to manage metadata fields and views') }}
            </p>
          </div>

          <!-- Permission Type (Fixed to manage_fields) -->
          <input type="hidden" v-model="formData.permissionType" value="manage_fields" />

          <!-- Form Actions -->
          <div class="form-actions">
            <NcButton 
              @click="grantPermission" 
              variant="primary" 
              :disabled="!isFormValid || saving">
              <template #icon>
                <NcLoadingIcon v-if="saving" :size="20" />
                <CheckIcon v-else :size="20" />
              </template>
              {{ grantButtonLabel }}
            </NcButton>
            <NcButton @click="cancelAdd" variant="secondary" :disabled="saving">
              <!-- TRANSLATORS Button that closes the add permission form without saving -->
              {{ t('metavox', 'Cancel') }}
            </NcButton>
          </div>
        </div>
      </div>

      <!-- Existing Permissions List -->
      <div class="mv-block">
        <div class="section-header">
          <!-- Supporting text, not a bubble. I reached for NcCounterBubble
               here to fix a contrast failure in the hand-built badge it
               replaced, and fixed the contrast but picked the wrong
               component: the guidelines list it as "count beside a
               navigation entry", and all three uses in core server are
               exactly that — a #counter slot on an NcAppNavigationItem
               (UserManagementNavigation, AppstoreNavigation, GroupListItem).
               A filled bubble says "unread, needs attention"; this is just
               how many rows follow.

               The other two lists in this page already say it as plain
               supporting text, so this one matching them is also what makes
               the three panes agree.

               Grouped with the heading so the count sits in the same place
               in all three panes. See MetadataFieldsEditor. -->
          <div class="section-header__title">
            <!-- TRANSLATORS Heading of the list of permissions that are in effect -->
            <h3>{{ t('metavox', 'Active permissions') }}</h3>
            <span class="field-count">{{ permissionCount }}</span>
          </div>
        </div>

  <!-- Search -->
<div class="search-container">
  <!-- TRANSLATORS Accessible label of the search field above the permissions table -->
  <NcTextField :label="t('metavox', 'Search permissions')"
    :model-value="searchQuery"
    @update:model-value="searchQuery = $event"
    :placeholder="searchPlaceholder"
    :show-trailing-button="!!searchQuery"
    trailing-button-icon="close"
    @trailing-button-click="searchQuery = ''">
    <template #icon>
      <MagnifyIcon :size="20" />
    </template>
  </NcTextField>
</div>

        <!-- Empty State -->
        <div v-if="filteredPermissions.length === 0 && !searchQuery" class="empty-content">
          <ShieldIcon :size="64" class="empty-icon" />
          <!-- TRANSLATORS Heading of the empty state when no group has a permission yet -->
          <h4>{{ t('metavox', 'No permissions configured') }}</h4>
          <p>{{ t('metavox', 'Grant permissions to accounts or groups to allow them to view or edit metadata.') }}</p>
        </div>

        <!-- No Search Results -->
        <div v-else-if="filteredPermissions.length === 0 && searchQuery" class="empty-search">
          <!-- TRANSLATORS {query} is the text typed in the search field, e.g. No permissions found matching "finance" -->
          <p>{{ t('metavox', 'No permissions found matching "{query}"', { query: searchQuery }, undefined, PLAIN_TEXT) }}</p>
        </div>

        <!-- Permissions Table -->
        <div v-else class="permissions-table">
          <table>
            <thead>
              <tr>
                <!-- TRANSLATORS Noun: column header for the group a permission is given to; keep it short -->
                <th>{{ t('metavox', 'Group') }}</th>
                <!-- TRANSLATORS Noun: column header for the kind of permission; keep it short -->
                <th>{{ t('metavox', 'Permission') }}</th>
                <!-- TRANSLATORS Column header for the date the permission was given; keep it short -->
                <th>{{ t('metavox', 'Created') }}</th>
                <!-- TRANSLATORS Noun: column header above the revoke buttons; keep it short -->
                <th class="actions-column">{{ t('metavox', 'Actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="permission in filteredPermissions" :key="permission.id">
                <td>
                  <div class="target-cell">
                    <AccountGroupIcon :size="20" />
                    <div class="target-info">
                      <strong>{{ permission.group_id }}</strong>
                      <!-- TRANSLATORS Noun: small type label under the group name in the permissions table -->
                      <span class="target-type">{{ t('metavox', 'Group') }}</span>
                    </div>
                  </div>
                </td>
                <td>
                  <!-- The guidelines name NcChip for a tag or label, and
                       this is one. The hand-built pill it replaces carried
                       hardcoded fallbacks (#e8f0fe, #0082c9, and a 12px
                       radius standing in for --border-radius-pill, which is
                       100px) — all three tokens resolve on this server, so
                       those values were dead weight that would have drifted
                       silently under a theme.

                       no-close because the chip is a label, not something
                       the reader can remove; revoking is the button at the
                       end of the row. -->
                  <NcChip :text="getPermissionLabel(permission.permission_type)"
                    no-close />
                </td>
                <td>
                  <span class="date-text">{{ formatDate(permission.created_at) }}</span>
                </td>
                <td class="actions-column">
                  <NcButton
                    @click="revokePermission(permission)"
                    variant="error"
                    :disabled="deleting === permission.id">
                    <template #icon>
                      <NcLoadingIcon v-if="deleting === permission.id" :size="20" />
                      <DeleteIcon v-else :size="20" />
                    </template>
                    <!-- TRANSLATORS Verb: button that removes this permission from the group; keep it short -->
                    {{ t('metavox', 'Revoke') }}
                  </NcButton>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate, translatePlural, getCanonicalLocale } from '@nextcloud/l10n'
import { PLAIN_TEXT } from '../utils/fieldValue.js'
import { errorText } from '../utils/errorText.js'
import { generateUrl } from '@nextcloud/router'

// Nextcloud Vue components
import { showConfirmation, showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcChip from '@nextcloud/vue/components/NcChip'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
// Icons
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import ShieldIcon from 'vue-material-design-icons/Shield.vue'
import AccountIcon from 'vue-material-design-icons/Account.vue'
import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'


export default {
  name: 'PermissionsManager',
  // Templates read the t() options from the component.
  setup() {
    return { PLAIN_TEXT }
  },
  
  components: {
    NcButton,
    NcTextField,
    NcSelect,
    NcCheckboxRadioSwitch,
    NcChip,
    NcLoadingIcon,
    PlusIcon,
    CheckIcon,
    DeleteIcon,
    ShieldIcon,
    AccountIcon,
    AccountGroupIcon,
    MagnifyIcon,
  },
  
  data() {
    return {
      loading: false,
      saving: false,
      deleting: null,
      showAddForm: false,
      permissions: [],
      searchQuery: '',
      
      // Groups
      loadingGroups: false,
      availableGroups: [],
      
      // Form data
      formData: {
        selectedGroups: [],
        permissionType: 'manage_fields',
      },
    }
  },
  
  computed: {
/**
 * Counts what the table below shows, so a search narrows it. Same shape as
 * the folder and field lists.
 */
permissionCount() {
  const count = this.filteredPermissions.length
  if (this.searchQuery) {
    // TRANSLATORS %n is the number of permissions that match the search
    return this.n('metavox', '%n permission found', '%n permissions found', count)
  }
  // TRANSLATORS %n is the number of permissions in the table, shown next to its heading
  return this.n('metavox', '%n permission', '%n permissions', count)
},

grantButtonLabel() {
  if (this.saving) {
    // TRANSLATORS Button text while the permission is being saved; keep it short
    return this.t('metavox', 'Saving …')
  }
  // TRANSLATORS Button that gives the selected groups the permission; keep it short
  return this.t('metavox', 'Grant permission')
},

searchPlaceholder() {
  // TRANSLATORS Placeholder of the search field above the permissions table
  return this.t('metavox', 'Search permissions …')
},

filteredPermissions() {
  // Zorg ervoor dat searchQuery altijd een string is
  if (!this.searchQuery || typeof this.searchQuery !== 'string' || !this.searchQuery.trim()) {
    return this.permissions
  }
  
  const query = this.searchQuery.toLowerCase().trim()
  return this.permissions.filter(perm => {
    const target = (perm.group_id || '').toLowerCase()
    const permission = this.getPermissionLabel(perm.permission_type).toLowerCase()
    return target.includes(query) || permission.includes(query)
  })
},
    
    isFormValid() {
      return this.formData.selectedGroups && this.formData.selectedGroups.length > 0
    },
  },
  
  mounted() {
    this.loadPermissions()
    this.loadGroups()
  },
  
  methods: {
    async loadPermissions() {
      this.loading = true
      try {
        const response = await axios.get(generateUrl('/apps/metavox/api/permissions'))
        this.permissions = response.data || []
      } catch (error) {
        console.error('Failed to load permissions:', error)
        showError(this.t('metavox', 'Failed to load permissions'))
      } finally {
        this.loading = false
      }
    },
    
    async loadGroups() {
      this.loadingGroups = true
      try {
        // Use our custom endpoint that uses IGroupManager
        const response = await axios.get(generateUrl('/apps/metavox/api/permissions/groups'))

        if (Array.isArray(response.data)) {
          this.availableGroups = response.data.map(group => ({
            id: group.id,
            displayname: group.displayname || group.id
          }))
        }
      } catch (error) {
        console.error('Failed to load groups:', error)
        showError(this.t('metavox', 'Failed to load groups'))
        this.availableGroups = []
      } finally {
        this.loadingGroups = false
      }
    },
    
    searchGroups(query) {
      // Search is handled by NcSelect internally filtering availableGroups
    },
    
    async loadGroupfolders() {
      this.loadingGroupfolders = true
      try {
        const response = await axios.get(generateUrl('/apps/metavox/api/groupfolders'))
        const groupfolders = response.data || []
        
        this.groupfolderOptions = groupfolders.map(gf => ({
          id: gf.id,
          label: gf.mount_point
        }))
      } catch (error) {
        console.error('Failed to load groupfolders:', error)
      } finally {
        this.loadingGroupfolders = false
      }
    },
    
    async grantPermission() {
      if (!this.isFormValid) return
      
      this.saving = true
      
      try {
        // Grant permission to each selected group
        for (const group of this.formData.selectedGroups) {
          const data = {
            group_id: group.id,
            permission_type: 'manage_fields',
          }
          
          await axios.post(generateUrl('/apps/metavox/api/permissions/group'), data)
        }
        
        // TRANSLATORS %n is the number of groups that were given the permission
        showSuccess(this.n('metavox', 'Permission granted to %n group',
          'Permission granted to %n groups',
          this.formData.selectedGroups.length))
        
        this.resetForm()
        this.showAddForm = false
        await this.loadPermissions()
      } catch (error) {
        showError(errorText(error, this.t('metavox', 'Failed to grant permission')))
      } finally {
        this.saving = false
      }
    },
    
    async revokePermission(permission) {
      const ok = await showConfirmation({
        // TRANSLATORS Title of the dialog that asks to confirm revoking a permission
        name: this.t('metavox', 'Revoke permission'),
        text: this.t('metavox', 'Are you sure you want to revoke this permission?'),
        // TRANSLATORS Verb: confirm button of the revoke permission dialog; keep it short
        labelConfirm: this.t('metavox', 'Revoke'),
        // TRANSLATORS Button that closes the revoke permission dialog without changes
        labelReject: this.t('metavox', 'Cancel'),
        severity: 'warning',
      })
      if (!ok) {
        return
      }
      
      this.deleting = permission.id
      
      try {
        await axios.delete(generateUrl(`/apps/metavox/api/permissions/group/${permission.id}`))
        
        // TRANSLATORS Confirmation after a permission was removed from a group
        showSuccess(this.t('metavox', 'Permission revoked'))
        await this.loadPermissions()
      } catch (error) {
        showError(errorText(error, this.t('metavox', 'Failed to revoke permission')))
      } finally {
        this.deleting = null
      }
    },
    
    cancelAdd() {
      this.resetForm()
      this.showAddForm = false
    },
    
    resetForm() {
      this.formData = {
        selectedGroups: [],
        permissionType: 'manage_fields',
      }
    },
    
    getPermissionLabel(permissionType) {
      const labels = {
        // TRANSLATORS Name of the only permission type, shown as a label in the permissions table
        'manage_fields': this.t('metavox', 'Manage metadata fields & views'),
      }
      return labels[permissionType] || permissionType
    },
    
    formatDate(dateString) {
      if (!dateString) return '-'
      const date = new Date(dateString)
      return date.toLocaleDateString(getCanonicalLocale()) + ' ' + date.toLocaleTimeString(getCanonicalLocale(), { 
        hour: '2-digit', 
        minute: '2-digit' 
      })
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
/* No measure and no inset here: NcSettingsSection owns both (§6, "The measure
   belongs on the form"), and a second one on the child pushed this section's
   content 20px further in than the sections that had none — measured as three
   different left edges, 28 / 40 / 48, down one page. */

.loading-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 48px 20px;
  text-align: center;
}


.mv-block {
  /* A block inside an NcSettingsSection, not a card on top of one: the
     section already supplies the surface, the measure and the rule that
     separates it from the next. The card look this carried — a radius and a
     hardcoded rgba box-shadow that no theme can reach — drew a second frame
     inside the first, and the shadow was invisible in dark mode anyway. */
  margin-bottom: 24px;
}

/* Matched to the other two lists in this page (MetadataFieldsEditor and
   ManageGroupfolders): same wrap, same 8px gap, same 16px below, and no
   rule. A heading over its own list is not a section boundary, and 20px
   and 16px are both off the 4px grid. */
.section-header {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
}

/* The row that holds only the Add button has nothing to space against, so
   it aligns to the end rather than leaving the button stranded left. */
.section-header--action-only {
  justify-content: flex-end;
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

/* Same supporting-text count as the other two lists. Third copy of this
   rule in the page — .mv-block is duplicated across five components too,
   which is a shared-stylesheet job rather than something to fix from this
   diff. */
.field-count {
  color: var(--color-text-maxcontrast);
  font-size: var(--font-size-small);
  font-weight: var(--font-weight-default, 400);
}

.add-permission-form {
  background: var(--color-background-dark);
  border-radius: var(--border-radius);
  padding: 20px;
  margin-top: 16px;
}

.form-row {
  margin-bottom: 20px;
}

.form-row.indented {
  margin-inline-start: 24px;
}

/* Was a hardcoded 12px — below --font-size-small and below anything
   Nextcloud ships. Core explains a control at body size (Sharing settings
   uses 15px throughout) and NcTextField's own helper text inherits the
   same, so this takes the inherited size too. The name is also shared with
   MetadataFieldsEditor; scoping keeps them apart, but they should not drift
   (§7, "Do not borrow the library's class names"). */
.field-help-text {
  margin: 8px 0 0 0;
  color: var(--color-text-maxcontrast);
  line-height: 1.4;
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

.empty-content,
.empty-search {
  text-align: center;
  padding: 40px 20px;
  color: var(--color-text-maxcontrast);
}

.empty-icon {
  margin-bottom: 16px;
  opacity: 0.5;
}

.empty-content h4 {
  margin: 0 0 8px 0;
  color: var(--color-text-maxcontrast);
}

.empty-content p {
  margin: 0;
}

.permissions-table {
  overflow-x: auto;
}

table {
  width: 100%;
  border-collapse: collapse;
}

/* No fill behind the header row. Core's own settings table of grantable
   things, AuthTokenList.vue, styles its thead with padding and nothing else
   — the column names are distinguished by being smaller and quieter than
   the rows, not by a band of grey. Ours painted --color-background-dark
   (#ededed) across the full width, which reads as a toolbar rather than a
   heading and is the only filled band on the page.

   text-align: start, not left: the rule has to flip in RTL, and core's
   reset already sets `caption, th, td { text-align: start }` — `left`
   was overriding it back to the wrong side. */
th {
  padding: 12px;
  text-align: start;
  font-weight: 600;
  color: var(--color-text-maxcontrast);
  font-size: var(--font-size-small);
}

td {
  padding: 16px 12px;
  border-bottom: 1px solid var(--color-border);
}

tr:hover {
  background: var(--color-background-hover);
}

.target-cell {
  display: flex;
  align-items: center;
  gap: 12px;
}

.target-info {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.target-info strong {
  font-weight: 600;
  color: var(--color-main-text);
}

/* A subline under the group name. Was a hardcoded 12px — below
   --font-size-small and below anything the platform ships. */
.target-type {
  font-size: var(--font-size-small);
  color: var(--color-text-maxcontrast);
}

.scope-cell {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

/* 11px was below anything the platform ships; the radius and padding are
   NcChip's job, but this badge is purely decorative text so it keeps its
   shape and takes the smallest size the tokens define. */
.scope-badge {
  display: inline-block;
  padding: 4px 10px;
  border-radius: var(--border-radius-pill, 12px);
  font-size: var(--font-size-small);
  font-weight: 500;
}

.scope-badge.folder {
  background: var(--color-warning);
  color: var(--color-warning-text);
}

.scope-badge.scope {
  background: var(--color-info);
  color: var(--color-info-text);
}

.scope-badge.all {
  background: var(--color-success);
  color: var(--color-success-text);
}

.date-text {
  font-size: var(--font-size-small);
  color: var(--color-text-maxcontrast);
}

.actions-column {
  text-align: end;
  width: 120px;
}

@media (max-width: 768px) {
  .permissions-table {
    overflow-x: scroll;
  }
  
  table {
    min-width: 800px;
  }
  /* An empty state does not need 48px of air on a phone. */
  .empty-content,
  .empty-search,
  .loading-container {
    padding-block: 24px;
  }

}
</style>