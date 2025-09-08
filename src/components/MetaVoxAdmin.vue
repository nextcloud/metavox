<template>
	<div id="metavox-admin" class="section">
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
				<ClockIcon v-if="tab.id === 'retention'" :size="16" />
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
					@notification="showNotification" />

				<RetentionManager
					v-if="activeTab === 'retention'"
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
import ClockIcon from 'vue-material-design-icons/Clock.vue'

// Import our custom components
import GroupfolderMetadataFields from './GroupfolderMetadataFields.vue'
import FileMetadataFields from './FileMetadataFields.vue'
import ManageGroupfolders from './ManageGroupfolders.vue'
import RetentionManager from './RetentionManager.vue'

export default {
	name: 'MetaVoxAdmin',
	
	components: {
		FolderIcon,
		FileIcon,
		CogIcon,
		ClockIcon,
		GroupfolderMetadataFields,
		FileMetadataFields,
		ManageGroupfolders,
		RetentionManager,
	},
	
	data() {
		return {
			activeTab: 'groupfolder-metadata',
			tabs: [
				{ id: 'groupfolder-metadata', name: this.t('metavox', 'Team folder Metadata') },
				{ id: 'file-metadata', name: this.t('metavox', 'File Metadata') },
				{ id: 'groupfolders', name: this.t('metavox', 'Manage Team folders') },
				{ id: 'retention', name: this.t('metavox', 'Retention Manager') },
			]
		}
	},
	
	methods: {
		setActiveTab(tab) {
			this.activeTab = tab
		},
		
		showNotification(notification) {
			// Simple console log for now, later we can add proper Nextcloud notifications
			console.log(`${notification.type}: ${notification.message}`)
		},
	},
}
</script>

<style lang="scss" scoped>
.section {
	padding: 20px;
}

.section-header {
	margin-bottom: 30px;
	
	h2 {
		font-size: 28px;
		font-weight: 300;
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
	
	&.active {
		border-bottom-color: var(--color-primary);
		color: var(--color-primary);
		background: var(--color-primary-element-light);
	}
	
	&:hover {
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
	}
	
	.tab-button {
		width: 100%;
		justify-content: flex-start;
	}
}
</style>