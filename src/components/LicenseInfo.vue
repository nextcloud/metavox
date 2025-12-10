<template>
  <div class="license-info">
    <div v-if="loading" class="loading-container">
      <div class="icon-loading"></div>
    </div>

    <div v-else class="settings-section">
      <div class="section-header">
        <h3>{{ t('metavox', 'License Information') }}</h3>
      </div>

      <!-- License Settings Form -->
      <div class="license-settings-card">
        <h4>{{ t('metavox', 'License Configuration') }}</h4>
        <form @submit.prevent="saveLicenseConfig" class="license-form">
          <div class="form-group">
            <label for="license-key">{{ t('metavox', 'License Key') }}</label>
            <input
              id="license-key"
              v-model="licenseConfig.licenseKey"
              type="text"
              :placeholder="t('metavox', 'MVOX-XXXX-XXXX-XXXX-XXXX')"
              :disabled="saving"
              required>
          </div>

          <div class="form-group">
            <label for="license-server">{{ t('metavox', 'License Server URL') }}</label>
            <input
              id="license-server"
              v-model="licenseConfig.licenseServerUrl"
              type="url"
              :placeholder="t('metavox', 'https://license.example.com')"
              :disabled="saving"
              required>
          </div>

          <div class="form-actions">
            <NcButton
              type="primary"
              native-type="submit"
              :disabled="saving">
              <template #icon>
                <component :is="saving ? 'LoadingIcon' : 'ContentSaveIcon'" :size="20" />
              </template>
              {{ saving ? t('metavox', 'Saving...') : t('metavox', 'Save License Configuration') }}
            </NcButton>
          </div>
        </form>
      </div>

      <!-- Not Configured or Invalid - Show Default Limit -->
      <div v-if="!licenseInfo.configured || !licenseInfo.valid" class="license-default">
        <div class="info-box warning">
          <h4>⚠️ {{ t('metavox', 'Limited Mode - Default Limit Active') }}</h4>
          <p>{{ t('metavox', 'MetaVox is running with a default limit of 5 team folders with metadata fields.') }}</p>
          <p>{{ t('metavox', 'Enter a valid license key above to increase your team folder limit.') }}</p>
          <p v-if="licenseInfo.reason"><strong>{{ t('metavox', 'Reason:') }}</strong> {{ licenseInfo.reason }}</p>
        </div>

        <!-- Usage Statistics for default limit -->
        <div class="stats-grid">
          <div class="stat-card" :class="{ 'stat-exceeded': licenseInfo.limitsExceeded?.teamFolders }">
            <div class="stat-header">
              <h4>{{ t('metavox', 'Team Folders (Default Limit)') }}</h4>
              <span v-if="licenseInfo.limitsExceeded?.teamFolders" class="warning-badge">⚠️ {{ t('metavox', 'Limit Reached') }}</span>
            </div>
            <div class="stat-values">
              <span class="stat-current">{{ licenseInfo.currentTeamFolders || 0 }}</span>
              <span class="stat-separator">/</span>
              <span class="stat-max">{{ licenseInfo.maxTeamFolders || 5 }}</span>
            </div>
            <div class="progress-bar">
              <div
                class="progress-fill"
                :class="{ 'progress-exceeded': licenseInfo.limitsExceeded?.teamFolders }"
                :style="{ width: getProgressPercentage('teamFolders') + '%' }">
              </div>
            </div>
          </div>
        </div>

        <!-- Warning if limit exceeded -->
        <div v-if="licenseInfo.limitsExceeded?.teamFolders" class="info-box error">
          <h4>🚫 {{ t('metavox', 'Default Team Folder Limit Reached') }}</h4>
          <p>{{ t('metavox', 'You have reached the default limit of 5 team folders with metadata fields. Please enter a valid license key to increase this limit.') }}</p>
        </div>
      </div>

      <!-- License Valid -->
      <div v-else class="license-valid">
        <!-- License Details -->
        <div class="info-box success">
          <h4>✅ {{ t('metavox', 'License Active') }}</h4>
          <div class="license-details">
            <div class="license-row">
              <span class="label">{{ t('metavox', 'License Type:') }}</span>
              <span class="value">
                <span class="badge" :class="licenseInfo.isTrial ? 'badge-warning' : 'badge-success'">
                  {{ licenseInfo.licenseType }}{{ licenseInfo.isTrial ? ' (Trial)' : '' }}
                </span>
              </span>
            </div>
            <div v-if="licenseInfo.validUntil" class="license-row">
              <span class="label">{{ t('metavox', 'Valid Until:') }}</span>
              <span class="value">{{ formatDate(licenseInfo.validUntil) }}</span>
            </div>
            <div v-else class="license-row">
              <span class="label">{{ t('metavox', 'Validity:') }}</span>
              <span class="value badge badge-success">{{ t('metavox', 'Perpetual') }}</span>
            </div>
          </div>
        </div>

        <!-- Usage Statistics -->
        <div class="stats-grid">
          <div class="stat-card" :class="{ 'stat-exceeded': licenseInfo.limitsExceeded?.teamFolders }">
            <div class="stat-header">
              <h4>{{ t('metavox', 'Team Folders') }}</h4>
              <span v-if="licenseInfo.limitsExceeded?.teamFolders" class="warning-badge">⚠️ {{ t('metavox', 'Limit Exceeded') }}</span>
            </div>
            <div class="stat-values">
              <span class="stat-current">{{ licenseInfo.currentTeamFolders || 0 }}</span>
              <span class="stat-separator">/</span>
              <span class="stat-max">{{ licenseInfo.maxTeamFolders }}</span>
            </div>
            <div class="progress-bar">
              <div
                class="progress-fill"
                :class="{ 'progress-exceeded': licenseInfo.limitsExceeded?.teamFolders }"
                :style="{ width: getProgressPercentage('teamFolders') + '%' }">
              </div>
            </div>
          </div>

          <div v-if="licenseInfo.maxUsers" class="stat-card" :class="{ 'stat-exceeded': licenseInfo.limitsExceeded?.users }">
            <div class="stat-header">
              <h4>{{ t('metavox', 'Users') }}</h4>
              <span v-if="licenseInfo.limitsExceeded?.users" class="warning-badge">⚠️ {{ t('metavox', 'Limit Exceeded') }}</span>
            </div>
            <div class="stat-values">
              <span class="stat-current">{{ licenseInfo.currentUsers || 0 }}</span>
              <span class="stat-separator">/</span>
              <span class="stat-max">{{ licenseInfo.maxUsers }}</span>
            </div>
            <div class="progress-bar">
              <div
                class="progress-fill"
                :class="{ 'progress-exceeded': licenseInfo.limitsExceeded?.users }"
                :style="{ width: getProgressPercentage('users') + '%' }">
              </div>
            </div>
          </div>
        </div>

        <!-- Warning if limit exceeded -->
        <div v-if="licenseInfo.limitsExceeded?.teamFolders" class="info-box warning">
          <h4>⚠️ {{ t('metavox', 'Team Folder Limit Exceeded') }}</h4>
          <p>{{ t('metavox', 'You have exceeded your team folder limit. Please delete some team folders or contact your license provider to increase your limit.') }}</p>
        </div>
      </div>

      <!-- Refresh Button -->
      <div class="actions">
        <NcButton @click="loadLicenseInfo" type="secondary">
          <template #icon>
            <RefreshIcon :size="20" />
          </template>
          {{ t('metavox', 'Refresh License Info') }}
        </NcButton>
      </div>
    </div>
  </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/dist/Components/NcButton'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import ContentSaveIcon from 'vue-material-design-icons/ContentSave.vue'
import LoadingIcon from 'vue-material-design-icons/Loading.vue'

export default {
  name: 'LicenseInfo',

  components: {
    NcButton,
    RefreshIcon,
    ContentSaveIcon,
    LoadingIcon
  },

  data() {
    return {
      loading: false,
      saving: false,
      licenseInfo: {
        configured: false,
        valid: false,
        reason: null
      },
      licenseConfig: {
        licenseKey: '',
        licenseServerUrl: ''
      }
    }
  },

  async mounted() {
    await this.loadLicenseConfig()
    await this.loadLicenseInfo()
  },

  methods: {
    async loadLicenseConfig() {
      try {
        const response = await axios.get(generateUrl('/apps/metavox/api/license/config'))
        if (response.data) {
          this.licenseConfig = {
            licenseKey: response.data.licenseKey || '',
            licenseServerUrl: response.data.licenseServerUrl || ''
          }
        }
      } catch (error) {
        console.error('Failed to load license config:', error)
      }
    },

    async loadLicenseInfo() {
      this.loading = true
      try {
        const response = await axios.get(generateUrl('/apps/metavox/api/license/info'))
        this.licenseInfo = response.data || {}
      } catch (error) {
        console.error('Failed to load license info:', error)
        showError(this.t('metavox', 'Failed to load license information'))
      } finally {
        this.loading = false
      }
    },

    async saveLicenseConfig() {
      this.saving = true
      try {
        const response = await axios.post(
          generateUrl('/apps/metavox/api/license/config'),
          {
            licenseKey: this.licenseConfig.licenseKey,
            licenseServerUrl: this.licenseConfig.licenseServerUrl
          }
        )

        if (response.data.success) {
          showSuccess(this.t('metavox', 'License configuration saved successfully'))
          // Reload license info to validate the new config
          await this.loadLicenseInfo()
        } else {
          showError(response.data.error || this.t('metavox', 'Failed to save license configuration'))
        }
      } catch (error) {
        console.error('Failed to save license config:', error)
        showError(this.t('metavox', 'Failed to save license configuration'))
      } finally {
        this.saving = false
      }
    },

    formatDate(dateString) {
      if (!dateString) return '-'
      const date = new Date(dateString)
      return date.toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
      })
    },

    getProgressPercentage(type) {
      if (type === 'teamFolders') {
        const current = this.licenseInfo.currentTeamFolders || 0
        const max = this.licenseInfo.maxTeamFolders || 1
        return Math.min((current / max) * 100, 100)
      } else if (type === 'users') {
        const current = this.licenseInfo.currentUsers || 0
        const max = this.licenseInfo.maxUsers || 1
        return Math.min((current / max) * 100, 100)
      }
      return 0
    }
  }
}
</script>

<style scoped lang="scss">
.license-info {
  max-width: 900px;
  margin: 0 auto;
}

.loading-container {
  text-align: center;
  padding: 40px;
}

.settings-section {
  margin-bottom: 30px;
}

.section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;

  h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
  }
}

.info-box {
  padding: 20px;
  border-radius: 8px;
  margin-bottom: 20px;

  h4 {
    margin: 0 0 10px 0;
    font-size: 16px;
  }

  p {
    margin: 10px 0;
    line-height: 1.5;
  }

  &.info {
    background-color: #e3f2fd;
    border-left: 4px solid #2196f3;
  }

  &.success {
    background-color: #e8f5e9;
    border-left: 4px solid #4caf50;
  }

  &.warning {
    background-color: #fff3e0;
    border-left: 4px solid #ff9800;
  }

  &.error {
    background-color: #ffebee;
    border-left: 4px solid #f44336;
  }
}

.code-block {
  background-color: #f5f5f5;
  padding: 12px;
  border-radius: 4px;
  font-family: monospace;
  font-size: 13px;
  overflow-x: auto;
  margin: 10px 0;
}

.license-details {
  margin-top: 15px;

  .license-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid rgba(0, 0, 0, 0.1);

    &:last-child {
      border-bottom: none;
    }

    .label {
      font-weight: 500;
      color: #666;
    }

    .value {
      font-weight: 600;
    }
  }
}

.badge {
  display: inline-block;
  padding: 4px 12px;
  border-radius: 12px;
  font-size: 13px;
  font-weight: 600;
  text-transform: uppercase;

  &.badge-success {
    background-color: #4caf50;
    color: white;
  }

  &.badge-warning {
    background-color: #ff9800;
    color: white;
  }
}

.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 20px;
  margin: 20px 0;
}

.stat-card {
  background: white;
  border: 1px solid #e0e0e0;
  border-radius: 8px;
  padding: 20px;
  transition: all 0.2s;

  &.stat-exceeded {
    border-color: #ff9800;
    background-color: #fff8f0;
  }

  .stat-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;

    h4 {
      margin: 0;
      font-size: 14px;
      font-weight: 600;
      color: #666;
      text-transform: uppercase;
    }

    .warning-badge {
      font-size: 12px;
      color: #ff9800;
      font-weight: 600;
    }
  }

  .stat-values {
    display: flex;
    align-items: baseline;
    margin-bottom: 12px;

    .stat-current {
      font-size: 32px;
      font-weight: 700;
      color: #333;
    }

    .stat-separator {
      margin: 0 8px;
      font-size: 24px;
      color: #999;
    }

    .stat-max {
      font-size: 24px;
      font-weight: 600;
      color: #666;
    }
  }

  .progress-bar {
    height: 8px;
    background-color: #e0e0e0;
    border-radius: 4px;
    overflow: hidden;

    .progress-fill {
      height: 100%;
      background-color: #4caf50;
      transition: width 0.3s ease;

      &.progress-exceeded {
        background-color: #ff9800;
      }
    }
  }
}

.actions {
  margin-top: 20px;
  text-align: right;
}

.license-settings-card {
  background: white;
  border: 1px solid #e0e0e0;
  border-radius: 8px;
  padding: 24px;
  margin-bottom: 24px;

  h4 {
    margin: 0 0 20px 0;
    font-size: 16px;
    font-weight: 600;
  }
}

.license-form {
  .form-group {
    margin-bottom: 20px;

    label {
      display: block;
      margin-bottom: 8px;
      font-weight: 500;
      color: #333;
    }

    input {
      width: 100%;
      padding: 10px 12px;
      border: 1px solid #ddd;
      border-radius: 4px;
      font-size: 14px;
      font-family: monospace;

      &:focus {
        outline: none;
        border-color: #0082c9;
        box-shadow: 0 0 0 2px rgba(0, 130, 201, 0.1);
      }

      &:disabled {
        background-color: #f5f5f5;
        cursor: not-allowed;
      }
    }
  }

  .form-actions {
    margin-top: 24px;
  }
}
</style>
