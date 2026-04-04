<template>
	<div id="metavox-admin" class="section">
		<!-- License Warning Banner -->
		<div v-if="licenseBanner && !bannerDismissed" :class="['license-banner', licenseBanner.type]">
			<span class="license-banner-text">
				{{ licenseBanner.message }}
				<a v-if="licenseBanner.link" :href="licenseBanner.link" :target="licenseBanner.external ? '_blank' : null"
					@click.prevent="licenseBanner.external ? null : setActiveTab('support')">
					{{ licenseBanner.linkText }}
				</a>
			</span>
			<button class="license-banner-close" @click="bannerDismissed = true">&times;</button>
		</div>

		<!-- Header -->
		<div class="section-header">
			<h2>{{ t('metavox', 'MetaVox - Custom Metadata Fields') }}</h2>
		</div>

		<!-- Tab Navigation -->
		<div class="tab-navigation">
			<button
				v-for="tab in tabs"
				:key="tab.id"
				:class="{ active: activeTab === tab.id }"
				@click="setActiveTab(tab.id)"
				class="tab-button">
				<FolderIcon v-if="tab.id === 'groupfolder-metadata'" :size="16" />
				<FileIcon v-if="tab.id === 'file-metadata'" :size="16" />
				<CogIcon v-if="tab.id === 'groupfolders'" :size="16" />
				<ShieldIcon v-if="tab.id === 'permissions'" :size="16" />
			<ChartBoxIcon v-if="tab.id === 'statistics'" :size="16" />
				<HeartIcon v-if="tab.id === 'support'" :size="16" />
				<BackupRestoreIcon v-if="tab.id === 'backup'" :size="16" />
				{{ tab.name }}
			</button>
		</div>

		<!-- Main Content Area -->
		<div class="metavox-content">
			<div class="container">
				<!-- Components -->
				<GroupfolderMetadataFields
					v-if="activeTab === 'groupfolder-metadata'"
					@notification="showNotification" />

				<FileMetadataFields
					v-if="activeTab === 'file-metadata'"
					@notification="showNotification" />

				<ManageGroupfolders
					v-if="activeTab === 'groupfolders'"
					@notification="showNotification"
					@switch-tab="handleTabSwitch" />

				<PermissionsManager
					v-if="activeTab === 'permissions'"
					@notification="showNotification" />

				<StatisticsSettings
					v-if="activeTab === 'statistics'" />

				<SupportSettings
					v-if="activeTab === 'support'" />

				<BackupRestore
					v-if="activeTab === 'backup'"
					@notification="showNotification" />
			</div>
		</div>
	</div>
</template>

<script>
// Icons from vue-material-design-icons
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import FileIcon from 'vue-material-design-icons/File.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import ShieldIcon from 'vue-material-design-icons/Shield.vue'
import ChartBoxIcon from 'vue-material-design-icons/ChartBox.vue'
import HeartIcon from 'vue-material-design-icons/Heart.vue'
import BackupRestoreIcon from 'vue-material-design-icons/BackupRestore.vue'

// Import our custom components
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import GroupfolderMetadataFields from './GroupfolderMetadataFields.vue'
import FileMetadataFields from './FileMetadataFields.vue'
import ManageGroupfolders from './ManageGroupfolders.vue'
import PermissionsManager from './PermissionsManager.vue'
import StatisticsSettings from './StatisticsSettings.vue'
import SupportSettings from './SupportSettings.vue'
import BackupRestore from './BackupRestore.vue'

export default {
	name: 'MetaVoxAdmin',

	components: {
		FolderIcon,
		FileIcon,
		CogIcon,
		ShieldIcon,
		ChartBoxIcon,
		HeartIcon,
		GroupfolderMetadataFields,
		FileMetadataFields,
		ManageGroupfolders,
		PermissionsManager,
		StatisticsSettings,
		SupportSettings,
		BackupRestore,
		BackupRestoreIcon,
	},

	data() {
		return {
			activeTab: 'groupfolder-metadata',
			bannerDismissed: false,
			licenseStats: null,
			tabs: [
				{ id: 'groupfolder-metadata', name: this.t('metavox', 'Team folder Metadata') },
				{ id: 'file-metadata', name: this.t('metavox', 'File Metadata') },
				{ id: 'groupfolders', name: this.t('metavox', 'Manage Team folders') },
				{ id: 'permissions', name: this.t('metavox', 'User Permissions') },
				{ id: 'statistics', name: this.t('metavox', 'Statistics') },
				{ id: 'support', name: this.t('metavox', 'Support') },
				{ id: 'backup', name: this.t('metavox', 'Backup & Restore') },
			]
		}
	},
	
	computed: {
		licenseBanner() {
			if (!this.licenseStats) return null
			const s = this.licenseStats
			const hasKey = !!s.licenseKeyMasked

			// Invalid/expired subscription key — friendly notice, not an error
			if (hasKey && !s.licenseValid) {
				return { type: 'info', message: this.t('metavox', 'Your MetaVox subscription key needs attention.'), linkText: this.t('metavox', 'Visit Support'), link: '#support' }
			}
			// No subscription + significant usage (>20 team folders) — friendly nudge
			if (!hasKey && (s.teamFoldersWithFields || 0) > 20) {
				return { type: 'info', message: this.t('metavox', 'Your organization is getting great value from MetaVox! Consider a subscription to support continued development.'), linkText: this.t('metavox', 'Learn more'), link: '#support' }
			}
			return null
		},
	},

	async mounted() {
		try {
			const { data } = await axios.get(generateUrl('/apps/metavox/api/license/stats'))
			if (data.success) this.licenseStats = data.stats
		} catch (e) { /* silently fail — banner just won't show */ }
	},

	methods: {
		setActiveTab(tab) {
			this.activeTab = tab
		},
		
		handleTabSwitch(tabId) {
			// Handle tab switch request from child components
			// Map the old naming convention to the new one if needed
			const tabMapping = {
				'groupfolder-metadata-fields': 'groupfolder-metadata',
				'file-metadata-fields': 'file-metadata',
				// Add more mappings if needed
			}

			const mappedTab = tabMapping[tabId] || tabId

			// Check if the tab exists
			if (this.tabs.find(tab => tab.id === mappedTab)) {
				this.setActiveTab(mappedTab)
			}
		},
		
		showNotification(notification) {
			// Use Nextcloud's notification system if available
			if (typeof OC !== 'undefined' && OC.Notification) {
				OC.Notification.showTemporary(notification.message)
			}
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
	},
}
</script>

<style lang="scss" scoped>
.license-banner {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 16px;
	margin-bottom: 16px;
	border-radius: var(--border-radius-large, 10px);
	font-size: 14px;

	&.info {
		background: var(--color-primary-element, #0082c9);
		color: #fff;
		a { color: #fff; text-decoration: underline; font-weight: 600; }
	}
}
.license-banner-close {
	background: none;
	border: none;
	color: inherit;
	font-size: 20px;
	cursor: pointer;
	padding: 0 4px;
	opacity: 0.7;
	&:hover { opacity: 1; }
}

.section {
	padding: 20px;
}

.section-header {
	margin-bottom: 30px;
    font-size: 24px;
    font-weight: bold;
    color: var(--color-main-text);
	
	h2 {
		font-size: 28px;
		font-weight: bold;
		margin: 0;
		color: var(--color-main-text);
	}
}

.tab-navigation {
	border-bottom: 1px solid var(--color-border);
	margin-bottom: 20px;
	display: flex;
	gap: 10px;
}

.tab-button {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 12px 20px;
	border: none;
	background: none;
	cursor: pointer;
	border-bottom: 2px solid transparent;
	color: var(--color-text-lighter);
	transition: all 0.2s ease;
	
	&.active {
		border-bottom-color: var(--color-primary);
		color: var(--color-primary);
		background: var(--color-primary-element-light);
	}
	
	&:hover:not(.active) {
		background: var(--color-background-hover);
	}
}

.metavox-content {
	.container {
		max-width: 1200px;
		margin: 0 auto;
	}
}

// Responsive design
@media (max-width: 768px) {
	.tab-navigation {
		flex-direction: column;
		gap: 0;
	}
	
	.tab-button {
		width: 100%;
		justify-content: flex-start;
		border-bottom: none;
		border-left: 2px solid transparent;
		
		&.active {
			border-bottom: none;
			border-left-color: var(--color-primary);
		}
	}
}
</style>