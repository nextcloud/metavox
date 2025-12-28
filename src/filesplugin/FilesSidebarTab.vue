<template>
	<div id="metavox-sidebar" class="metavox-sidebar">
		<!-- Loading State -->
		<div v-if="loading" class="loading-container">
			<div class="icon-loading"></div>
			<p>{{ t('metavox', 'Loading metadata...') }}</p>
		</div>

		<!-- Error State -->
		<div v-else-if="error" class="error-container">
			<p class="error-message">{{ error }}</p>
			<NcButton @click="reload">
				{{ t('metavox', 'Retry') }}
			</NcButton>
		</div>

		<!-- No Groupfolder -->
		<div v-else-if="!groupfolderId" class="info-container">
			<p>{{ t('metavox', 'This file is not in a team folder. Metadata is only available for team folder files.') }}</p>
		</div>

		<!-- No Permission -->
		<div v-else-if="!hasPermission" class="info-container">
			<p>{{ t('metavox', 'You do not have permission to view or edit metadata for this file.') }}</p>
		</div>

		<!-- Metadata Editor -->
		<div v-else class="metadata-content">
			<!-- SCENARIO 1: Groupfolder Root - Show only teamfolder metadata (read-only) -->
			<div v-if="isGroupfolderRoot">
				<div v-if="groupfolderFields.length > 0">
					<div class="admin-notice">
						<p>{{ t('metavox', 'This metadata applies to the entire Team folder and can only be edited by administrators in the admin settings.') }}</p>
					</div>
					<!-- Read-only display as text -->
					<div
						v-for="field in groupfolderFields"
						:key="field.id"
						class="field-display">
						<span class="field-label">{{ field.field_label }}</span>
						<span class="field-value">{{ formatFieldValue(field) }}</span>
					</div>
				</div>
				<div v-else class="info-container">
					<p>{{ t('metavox', 'No Team folder metadata fields are configured for this Team folder. Contact your administrator to set up Team folder metadata fields.') }}</p>
				</div>
			</div>

			<!-- SCENARIO 2: Items in Groupfolder - Show both teamfolder info and item metadata -->
			<div v-else-if="isInGroupfolder">
				<!-- Teamfolder Information Section (read-only with prominent styling) -->
				<div v-if="groupfolderFields.length > 0" class="teamfolder-info-wrapper">
					<div class="teamfolder-info-header">
						<h3>{{ t('metavox', 'Team folder Information') }}</h3>
					</div>
					<div class="teamfolder-info-content">
						<div
							v-for="field in groupfolderFields"
							:key="field.id"
							class="field-display">
							<span class="field-label">{{ field.field_label }}</span>
							<span class="field-value">{{ formatFieldValue(field) }}</span>
						</div>
					</div>
				</div>

				<!-- Separator Line -->
				<div v-if="groupfolderFields.length > 0 && itemFields.length > 0" class="metadata-separator" />

				<!-- Item Metadata Section (editable if permissions allow) -->
				<div v-if="itemFields.length > 0" class="metadata-section">
					<div class="item-metadata-header">
						<h3>{{ t('metavox', '{itemType} Metadata', { itemType }) }}</h3>
					</div>
					<MetadataForm
						:fields="itemFields"
						:values="metadata"
						:readonly="!canEdit"
						:select-values="selectValues"
						:multi-select-values="multiSelectValues"
						:select-key="selectKey"
						@update="handleMetadataUpdate" />
					<div v-if="canEdit && hasChanges" class="metadata-actions">
						<NcButton
							type="primary"
							:disabled="saving"
							@click="saveMetadata">
							<template #icon>
								<ContentSaveIcon v-if="!saving" :size="20" />
								<LoadingIcon v-else :size="20" />
							</template>
							{{ saving ? t('metavox', 'Saving...') : t('metavox', 'Save') }}
						</NcButton>
					</div>
				</div>
				<div v-else-if="itemFields.length === 0" class="info-container">
					<p>{{ t('metavox', 'No {itemType} metadata fields are configured for items in this Team folder. Contact your administrator to set up file metadata fields.', { itemType: itemType.toLowerCase() }) }}</p>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import ContentSaveIcon from 'vue-material-design-icons/ContentSave.vue'
import LoadingIcon from 'vue-material-design-icons/Loading.vue'

import MetadataForm from './MetadataForm.vue'

export default {
	name: 'FilesSidebarTab',

	components: {
		NcButton,
		ContentSaveIcon,
		LoadingIcon,
		MetadataForm,
	},

	props: {
		fileInfo: {
			type: Object,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			saving: false,
			error: null,
			groupfolderId: null,
			groupfolderFields: [],
			itemFields: [],
			metadata: {},
			originalMetadata: {},
			permissions: null,
			selectValues: {},
			multiSelectValues: {},
			selectKey: 0,
			groupfoldersCache: null,
			groupfoldersCacheExpiry: null,
			loadCancelToken: null,
		}
	},

	computed: {
		hasPermission() {
			return this.permissions?.canView === true
		},

		canEdit() {
			return this.permissions?.canEdit === true
		},

		hasChanges() {
			return JSON.stringify(this.metadata) !== JSON.stringify(this.originalMetadata)
		},

		isGroupfolderRoot() {
			const isDirectory = this.fileInfo.type === 'dir'
			const isMountRoot = this.fileInfo.attributes?.['is-mount-root'] === true
			const result = isDirectory && this.groupfolderId && isMountRoot

			return result
		},

		isInGroupfolder() {
			const result = !!this.groupfolderId && !this.isGroupfolderRoot
			return result
		},

		itemType() {
			return this.fileInfo.type === 'dir' ? 'Folder' : 'File'
		},
	},

	watch: {
		fileInfo: {
			immediate: true,
			handler(newFileInfo) {
				if (newFileInfo?.id) {
					this.loadMetadata()
				}
			},
		},
	},

	methods: {
		t,

		async loadMetadata() {
			// Cancel previous request if still running
			if (this.loadCancelToken) {
				this.loadCancelToken.cancel('New metadata load request started')
			}

			// Create new cancel token
			this.loadCancelToken = axios.CancelToken.source()

			this.loading = true
			this.error = null

			try {
				// Detect groupfolder
				this.groupfolderId = await this.detectGroupfolder()

				if (!this.groupfolderId) {
					this.loading = false
					return
				}

				// Check permissions (synchronous - uses fileInfo.permissions)
				this.permissions = this.checkPermissions()

				// Load fields with their values (single API call)
				const fieldsWithValues = await this.loadFields()

				// Separate groupfolder fields (applies_to_groupfolder = 1) and item fields (applies_to_groupfolder = 0)
				this.groupfolderFields = fieldsWithValues.filter((field) => {
					const appliesTo = field.applies_to_groupfolder
					return appliesTo === 1 || appliesTo === '1'
				})

				this.itemFields = fieldsWithValues.filter((field) => {
					const appliesTo = field.applies_to_groupfolder
					return appliesTo === 0 || appliesTo === '0' || appliesTo === null || appliesTo === undefined
				})

				// Extract metadata values from ALL fields
				const metadataMap = {}
				fieldsWithValues.forEach((field) => {
					if (field.field_name && field.value !== undefined) {
						metadataMap[field.field_name] = field.value
					}
				})

				// Initialize select and multiselect values for ALL fields
				this.selectValues = {}
				this.multiSelectValues = {}
				fieldsWithValues.forEach((field) => {
					if (field.field_type === 'select') {
						this.$set(this.selectValues, field.field_name, metadataMap[field.field_name] || null)
					} else if (field.field_type === 'multiselect') {
						const value = metadataMap[field.field_name]
						if (value) {
							this.$set(this.multiSelectValues, field.field_name, value.split(';#').filter((v) => v.trim()))
						} else {
							this.$set(this.multiSelectValues, field.field_name, [])
						}
					}
				})

				this.metadata = metadataMap
				this.originalMetadata = { ...metadataMap }
				this.selectKey++ // Force re-render of select components
			} catch (error) {
				// Ignore cancelled requests
				if (axios.isCancel(error)) {
					return
				}
				console.error('Error loading metadata:', error)
				this.error = error.response?.data?.error || error.message || this.t('metavox', 'Failed to load metadata')
			} finally {
				this.loading = false
				this.loadCancelToken = null
			}
		},

		async detectGroupfolder() {
			try {
				// Method 1: Check voor mount-root directories (groupfolders zelf)
				if (this.fileInfo.type === 'dir' &&
					this.fileInfo.mountType === 'group' &&
					this.fileInfo.attributes?.['is-mount-root'] === true) {

					const groupfolders = await this.getAllGroupfolders()

					for (const gf of groupfolders) {
						if (gf.mount_point === this.fileInfo.name) {
							return gf.id
						}
					}
				}

				// Method 2: Check mountPoint - meest betrouwbare methode voor items IN groupfolders
				if (this.fileInfo.mountPoint && this.fileInfo.mountPoint !== '/' && this.fileInfo.mountPoint !== '') {
					const mountResult = await this.detectFromMountPoint(this.fileInfo.mountPoint)
					if (mountResult) {
						return mountResult
					}
				}

				// Method 3: Check via het volledige path van het fileInfo
				if (this.fileInfo.path && this.fileInfo.path !== '/' && this.fileInfo.path !== '') {
					const pathResult = await this.detectFromPath(this.fileInfo.path)
					if (pathResult) {
						return pathResult
					}
				}

				return null
			} catch (error) {
				console.error('Error detecting groupfolder:', error)
				return null
			}
		},

		async getAllGroupfolders() {
			// Check if cache exists and is not expired (5 minutes = 300000ms)
			const now = Date.now()
			const cacheExpiry = 5 * 60 * 1000 // 5 minutes

			if (this.groupfoldersCache && this.groupfoldersCacheExpiry && now < this.groupfoldersCacheExpiry) {
				return this.groupfoldersCache
			}

			// Fetch from API
			const response = await axios.get(
				generateUrl('/apps/metavox/api/groupfolders'),
				{ cancelToken: this.loadCancelToken?.token }
			)
			const groupfoldersData = response.data || {}
			const groupfolders = Object.values(groupfoldersData)

			// Store in cache with expiry
			this.groupfoldersCache = groupfolders
			this.groupfoldersCacheExpiry = now + cacheExpiry

			return groupfolders
		},

		async detectFromMountPoint(mountPoint) {
			if (!mountPoint) return null

			try {
				const groupfolders = await this.getAllGroupfolders()

				for (const gf of groupfolders) {
					if (mountPoint === gf.mount_point ||
						mountPoint.endsWith('/' + gf.mount_point) ||
						gf.mount_point === mountPoint.replace(/^\//, '')) {
						return gf.id
					}
				}
			} catch (error) {
				// Silently handle errors in mountPoint detection
			}

			return null
		},

		async detectFromPath(path) {
			if (!path || path === '/' || path === '') {
				return null
			}

			try {
				const groupfolders = await this.getAllGroupfolders()

				for (const gf of groupfolders) {
					const mountPoint = '/' + gf.mount_point

					if (path.startsWith(mountPoint + '/') || path === mountPoint) {
						return gf.id
					}

					if (path.startsWith(gf.mount_point + '/') || path === gf.mount_point) {
						return gf.id
					}
				}
			} catch (error) {
				// Silently handle errors in path detection
			}

			return null
		},

		checkPermissions() {
			// Use Nextcloud's built-in file permission system
			// Same approach as original files-plugin1.js
			const permissions = this.fileInfo.permissions || 0

			// Nextcloud permission constants
			const NC_PERMISSION_READ = 1
			const NC_PERMISSION_UPDATE = 2
			const NC_PERMISSION_CREATE = 4
			const NC_PERMISSION_DELETE = 8
			const NC_PERMISSION_SHARE = 16

			const canRead = (permissions & NC_PERMISSION_READ) !== 0
			const canWrite = (permissions & NC_PERMISSION_UPDATE) !== 0
			const canCreate = (permissions & NC_PERMISSION_CREATE) !== 0
			const canDelete = (permissions & NC_PERMISSION_DELETE) !== 0
			const canShare = (permissions & NC_PERMISSION_SHARE) !== 0

			// For metadata: user must have write permissions to edit
			// For groupfolders: respect write permissions strictly
			const canEditMetadata = canWrite || canCreate

			return {
				canView: canRead,
				canEdit: canEditMetadata,
			}
		},

		async loadFields() {
			if (!this.groupfolderId) {
				return []
			}

			try {
				const fieldMap = new Map()

				// 1. Load groupfolder metadata WITH VALUES (applies_to_groupfolder = 1)
				// This endpoint returns: groupfolder fields + file fields, but only groupfolder fields have values
				try {
					const gfResponse = await axios.get(
						generateUrl('/apps/metavox/api/groupfolders/{groupfolderId}/metadata', {
							groupfolderId: this.groupfolderId,
						}),
						{ cancelToken: this.loadCancelToken?.token }
					)
					const gfData = gfResponse.data || []

					// Only take fields with applies_to_groupfolder = 1 (they have values)
					gfData.forEach(field => {
						if (field.field_name && (field.applies_to_groupfolder === 1 || field.applies_to_groupfolder === '1')) {
							fieldMap.set(field.field_name, field)
						}
					})
				} catch (gfError) {
					console.error('Failed to load groupfolder metadata:', gfError)
				}

				// 2. Load file/folder metadata WITH VALUES (applies_to_groupfolder = 0)
				// This endpoint returns: ALL fields (groupfolder + file), but only file fields have values
				if (this.fileInfo?.id) {
					try {
						const fileResponse = await axios.get(
							generateUrl('/apps/metavox/api/groupfolders/{groupfolderId}/files/{fileId}/metadata', {
								groupfolderId: this.groupfolderId,
								fileId: this.fileInfo.id,
							}),
							{ cancelToken: this.loadCancelToken?.token }
						)
						const fileData = fileResponse.data || []

						// Only take fields with applies_to_groupfolder = 0 (they have values for this file)
						fileData.forEach(field => {
							if (field.field_name && (field.applies_to_groupfolder === 0 || field.applies_to_groupfolder === '0')) {
								fieldMap.set(field.field_name, field)
							}
						})
					} catch (fileError) {
						console.error('Failed to load file metadata:', fileError)
					}
				}

				const allFields = Array.from(fieldMap.values())
				return allFields
			} catch (error) {
				console.error('Error loading fields:', error)
				return []
			}
		},

		async saveMetadata() {
			if (!this.canEdit || !this.hasChanges) {
				return
			}

			this.saving = true
			this.error = null

			try {
				// Sync select and multiselect values before saving (only for item fields, not groupfolder fields)
				this.itemFields.forEach((field) => {
					if (field.field_type === 'select') {
						const value = this.selectValues[field.field_name]
						this.$set(this.metadata, field.field_name, value || '')
					} else if (field.field_type === 'multiselect') {
						const values = this.multiSelectValues[field.field_name]
						const joinedValue = Array.isArray(values) ? values.join(';#') : ''
						this.$set(this.metadata, field.field_name, joinedValue)
					}
				})

				await axios.post(
					generateUrl('/apps/metavox/api/groupfolders/{groupfolderId}/files/{fileId}/metadata', {
						groupfolderId: this.groupfolderId,
						fileId: this.fileInfo.id,
					}),
					{
						metadata: this.metadata,
					},
				)

				this.originalMetadata = { ...this.metadata }
				showSuccess(this.t('metavox', 'Metadata saved successfully!'))
			} catch (error) {
				console.error('Error saving metadata:', error)
				this.error = error.response?.data?.error || this.t('metavox', 'Failed to save metadata')
				showError(this.error)
			} finally {
				this.saving = false
			}
		},

		handleMetadataUpdate(fieldName, value) {
			console.log('FilesSidebarTab handleMetadataUpdate called:', fieldName, value)
			console.log('FilesSidebarTab metadata before:', JSON.stringify(this.metadata))
			this.metadata = {
				...this.metadata,
				[fieldName]: value,
			}
			console.log('FilesSidebarTab metadata after:', JSON.stringify(this.metadata))
			console.log('FilesSidebarTab originalMetadata:', JSON.stringify(this.originalMetadata))
			console.log('FilesSidebarTab hasChanges:', this.hasChanges)
		},

		reload() {
			this.loadMetadata()
		},

		formatFieldValue(field) {
			// For groupfolder fields, the value is in field.value
			// For item fields, the value is in this.metadata[field.field_name]
			const value = field.value !== undefined ? field.value : this.metadata[field.field_name]

			if (!value || value === '') {
				return '-'
			}

			// Handle multiselect (;# separated)
			if (field.field_type === 'multiselect' && typeof value === 'string' && value.includes(';#')) {
				return value.split(';#').filter(v => v.trim()).join(', ')
			}

			// Handle checkbox
			if (field.field_type === 'checkbox') {
				return value === '1' || value === true ? this.t('metavox', 'Yes') : this.t('metavox', 'No')
			}

			// Handle date
			if (field.field_type === 'date' && value) {
				try {
					const date = new Date(value)
					return date.toLocaleDateString()
				} catch (e) {
					return value
				}
			}

			return value
		},
	},
}
</script>

<style scoped>
.metavox-sidebar {
	padding: 16px;
}

.loading-container,
.error-container,
.info-container {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 32px 16px;
	text-align: center;
}

.icon-loading {
	width: 32px;
	height: 32px;
	margin-bottom: 16px;
}

.error-message {
	color: var(--color-error);
	margin-bottom: 16px;
}

.metadata-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
	padding-bottom: 8px;
	border-bottom: 1px solid var(--color-border);
}

.metadata-header h3 {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.metadata-content {
	min-height: 200px;
}

.metadata-section {
	margin-bottom: 24px;
}

.metadata-section h3 {
	margin: 0 0 12px 0;
	font-size: 14px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

/* Team folder Information - Prominent styling */
.teamfolder-info-wrapper {
	background: var(--color-primary-element-light);
	border: 1px solid var(--color-primary-element);
	border-radius: var(--border-radius-large);
	padding: 16px;
	margin-bottom: 20px;
}

.teamfolder-info-header {
	margin-bottom: 12px;
}

.teamfolder-info-header h3 {
	margin: 0;
	font-size: 16px;
	font-weight: 700;
	color: var(--color-primary-element);
}

/* Separator between sections - subtle per Nextcloud guidelines */
.metadata-separator {
	height: 1px;
	background: var(--color-border);
	margin: 24px 0;
}

/* Item Metadata Header - subtle per Nextcloud guidelines */
.item-metadata-header {
	margin-bottom: 16px;
	padding-bottom: 12px;
	border-bottom: 1px solid var(--color-border);
}

.item-metadata-header h3 {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
	color: var(--color-main-text);
}

.admin-notice {
	padding: 12px;
	margin-bottom: 16px;
	background-color: var(--color-primary-element-light);
	border-left: 4px solid var(--color-primary);
	border-radius: var(--border-radius);
}

.admin-notice p {
	margin: 0;
	color: var(--color-text);
	font-size: 14px;
}

/* Field display - whitespace separation per Nextcloud guidelines */
.field-display {
	display: flex;
	flex-direction: column;
	margin-bottom: 16px;
}

.field-display:last-child {
	margin-bottom: 0;
}

.field-label {
	font-size: 12px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	margin-bottom: 4px;
}

.field-value {
	font-size: 14px;
	color: var(--color-main-text);
	word-break: break-word;
}

/* Save button container - positioned at bottom of form, right-aligned per Nextcloud Forms */
.metadata-actions {
	display: flex;
	justify-content: flex-end;
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.success-message {
	margin-top: 16px;
	padding: 12px;
	background-color: var(--color-success);
	color: var(--color-primary-element-text);
	border-radius: var(--border-radius);
	text-align: center;
	animation: fadeIn 0.3s ease-in-out;
}

@keyframes fadeIn {
	from {
		opacity: 0;
		transform: translateY(-10px);
	}
	to {
		opacity: 1;
		transform: translateY(0);
	}
}
</style>
