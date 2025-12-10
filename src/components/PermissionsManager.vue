<template>
  <div class="permissions-manager">
    <!-- Page Header -->
    <div class="page-header">
      <h2>{{ t('metavox', 'User Permissions') }}</h2>
      <p class="page-description">
        {{ t('metavox', 'Manage user and group permissions for metadata access') }}
      </p>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="loading-container">
      <div class="icon-loading"></div>
      <p>{{ t('metavox', 'Loading permissions...') }}</p>
    </div>

    <div v-else>
      <!-- Add Permission Section -->
      <div class="settings-section">
        <div class="section-header">
          <h3>{{ t('metavox', 'Grant Permission') }}</h3>
          <NcButton 
            v-if="!showAddForm"
            @click="showAddForm = true" 
            type="primary">
            <template #icon>
              <PlusIcon :size="20" />
            </template>
            {{ t('metavox', 'Add Permission') }}
          </NcButton>
        </div>

        <!-- Add Permission Form -->
        <div v-if="showAddForm" class="add-permission-form">
          <!-- Group Selection with Multiselect (like Groupfolders) -->
          <div class="form-row">
            <label class="field-label">{{ t('metavox', 'Select Groups') }}</label>
            <NcSelect
              v-model="formData.selectedGroups"
              :options="availableGroups"
              :multiple="true"
              label="displayname"
              track-by="id"
              :placeholder="t('metavox', 'Search and select groups...')"
              :loading="loadingGroups"
              @search-change="searchGroups">
              <template #noResult>
                {{ t('metavox', 'No groups found') }}
              </template>
            </NcSelect>
            <p class="field-help-text">
              {{ t('metavox', 'Users in selected groups will be able to manage metadata fields') }}
            </p>
          </div>

          <!-- Permission Type (Fixed to manage_fields) -->
          <input type="hidden" v-model="formData.permissionType" value="manage_fields" />

          <!-- Form Actions -->
          <div class="form-actions">
            <NcButton 
              @click="grantPermission" 
              type="primary" 
              :disabled="!isFormValid || saving">
              <template #icon>
                <div v-if="saving" class="icon-loading-small"></div>
                <CheckIcon v-else :size="20" />
              </template>
              {{ saving ? t('metavox', 'Saving...') : t('metavox', 'Grant Permission') }}
            </NcButton>
            <NcButton @click="cancelAdd" type="secondary" :disabled="saving">
              {{ t('metavox', 'Cancel') }}
            </NcButton>
          </div>
        </div>
      </div>

      <!-- Existing Permissions List -->
      <div class="settings-section">
        <div class="section-header">
          <h3>{{ t('metavox', 'Active Permissions') }}</h3>
          <span class="permission-count">{{ permissions.length }}</span>
        </div>

  <!-- Search -->
<div class="search-container">
  <NcTextField
    :value="searchQuery"
    @update:value="searchQuery = $event"
    :placeholder="t('metavox', 'Search permissions...')"
    :show-trailing-button="!!searchQuery"
    trailing-button-icon="close"
    @trailing-button-click="searchQuery = ''">
    <template #prefix>
      <MagnifyIcon :size="20" />
    </template>
  </NcTextField>
</div>

        <!-- Empty State -->
        <div v-if="filteredPermissions.length === 0 && !searchQuery" class="empty-content">
          <ShieldIcon :size="64" class="empty-icon" />
          <h4>{{ t('metavox', 'No permissions configured') }}</h4>
          <p>{{ t('metavox', 'Grant permissions to users or groups to allow them to view or edit metadata.') }}</p>
        </div>

        <!-- No Search Results -->
        <div v-else-if="filteredPermissions.length === 0 && searchQuery" class="empty-search">
          <p>{{ t('metavox', 'No permissions found matching "{query}"', { query: searchQuery }) }}</p>
        </div>

        <!-- Permissions Table -->
        <div v-else class="permissions-table">
          <table>
            <thead>
              <tr>
                <th>{{ t('metavox', 'Group') }}</th>
                <th>{{ t('metavox', 'Permission') }}</th>
                <th>{{ t('metavox', 'Created') }}</th>
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
                      <span class="target-type">{{ t('metavox', 'Group') }}</span>
                    </div>
                  </div>
                </td>
                <td>
                  <span class="permission-badge">
                    {{ getPermissionLabel(permission.permission_type) }}
                  </span>
                </td>
                <td>
                  <span class="date-text">{{ formatDate(permission.created_at) }}</span>
                </td>
                <td class="actions-column">
                  <NcButton
                    @click="revokePermission(permission)"
                    type="error"
                    :disabled="deleting === permission.id">
                    <template #icon>
                      <div v-if="deleting === permission.id" class="icon-loading-small"></div>
                      <DeleteIcon v-else :size="20" />
                    </template>
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
import { generateUrl } from '@nextcloud/router'

// Nextcloud Vue components
import { 
  NcButton, 
  NcTextField, 
  NcSelect, 
  NcCheckboxRadioSwitch 
} from '@nextcloud/vue'

// Icons
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import ShieldIcon from 'vue-material-design-icons/Shield.vue'
import AccountIcon from 'vue-material-design-icons/Account.vue'
import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'

// Notification helpers
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

export default {
  name: 'PermissionsManager',
  
  components: {
    NcButton,
    NcTextField,
    NcSelect,
    NcCheckboxRadioSwitch,
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
        
        console.log('Groups loaded:', response.data)
        
        if (Array.isArray(response.data)) {
          this.availableGroups = response.data.map(group => ({
            id: group.id,
            displayname: group.displayname || group.id
          }))
          
          console.log('Available groups:', this.availableGroups)
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
      console.log('Search query:', query)
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
        
        showSuccess(this.t('metavox', 'Permission granted to {count} group(s)', { 
          count: this.formData.selectedGroups.length 
        }))
        
        this.resetForm()
        this.showAddForm = false
        await this.loadPermissions()
      } catch (error) {
        console.error('Failed to grant permission:', error)
        showError(this.t('metavox', 'Failed to grant permission'))
      } finally {
        this.saving = false
      }
    },
    
    async revokePermission(permission) {
      if (!confirm(this.t('metavox', 'Are you sure you want to revoke this permission?'))) {
        return
      }
      
      this.deleting = permission.id
      
      try {
        const data = {
          group_id: permission.group_id,
          permission_type: permission.permission_type,
        }
        
        await axios.delete(
          generateUrl(`/apps/metavox/api/permissions/group/${permission.id}`),
          { data }
        )
        
        showSuccess(this.t('metavox', 'Permission revoked successfully'))
        await this.loadPermissions()
      } catch (error) {
        console.error('Failed to revoke permission:', error)
        showError(this.t('metavox', 'Failed to revoke permission'))
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
        'manage_fields': this.t('metavox', 'Manage Metadata Fields'),
      }
      return labels[permissionType] || permissionType
    },
    
    formatDate(dateString) {
      if (!dateString) return '-'
      const date = new Date(dateString)
      return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], { 
        hour: '2-digit', 
        minute: '2-digit' 
      })
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
.permissions-manager {
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
  margin: 0;
  color: var(--color-text-lighter);
  line-height: 1.5;
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

.permission-count {
  background: var(--color-background-darker);
  color: var(--color-text-lighter);
  padding: 4px 12px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: bold;
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
  margin-left: 24px;
}

.field-help-text {
  margin: 8px 0 0 0;
  font-size: 12px;
  color: var(--color-text-lighter);
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
  color: var(--color-text-lighter);
}

.empty-icon {
  margin-bottom: 16px;
  opacity: 0.5;
}

.empty-content h4 {
  margin: 0 0 8px 0;
  color: var(--color-text-lighter);
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

thead {
  background: var(--color-background-dark);
}

th {
  padding: 12px;
  text-align: left;
  font-weight: 600;
  color: var(--color-text-lighter);
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
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

.target-type {
  font-size: 12px;
  color: var(--color-text-lighter);
}

.permission-badge {
  display: inline-block;
  padding: 4px 12px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 600;
  background: var(--color-primary-light);
  color: var(--color-primary-text);
}

.scope-cell {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.scope-badge {
  display: inline-block;
  padding: 4px 10px;
  border-radius: 12px;
  font-size: 11px;
  font-weight: 500;
}

.scope-badge.folder {
  background: var(--color-warning-light);
  color: var(--color-warning-text);
}

.scope-badge.scope {
  background: var(--color-info-light);
  color: var(--color-info-text);
}

.scope-badge.all {
  background: var(--color-success-light);
  color: var(--color-success-text);
}

.date-text {
  font-size: 13px;
  color: var(--color-text-lighter);
}

.actions-column {
  text-align: right;
  width: 120px;
}

@media (max-width: 768px) {
  .permissions-manager {
    padding: 12px;
  }
  
  .settings-section {
    padding: 16px;
  }
  
  .permissions-table {
    overflow-x: scroll;
  }
  
  table {
    min-width: 800px;
  }
}
</style>