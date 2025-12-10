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
						<NcButton
							v-if="canEdit && hasChanges"
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
					<MetadataForm
						:fields="itemFields"
						:values="metadata"
						:readonly="!canEdit"
						:select-values="selectValues"
						:multi-select-values="multiSelectValues"
						:select-key="selectKey"
						@update="handleMetadataUpdate" />
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

			console.log('🔍 isGroupfolderRoot check:', {
				isDirectory,
				isMountRoot,
				groupfolderId: this.groupfolderId,
				attributes: this.fileInfo.attributes,
				result,
				fileInfo: this.fileInfo
			})

			return result
		},

		isInGroupfolder() {
			const result = !!this.groupfolderId && !this.isGroupfolderRoot
			console.log('🔍 isInGroupfolder check:', result, 'groupfolderId:', this.groupfolderId, 'isGroupfolderRoot:', this.isGroupfolderRoot)
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

				console.log(`🔍 Loaded ${this.groupfolderFields.length} groupfolder fields and ${this.itemFields.length} item fields`)

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
				console.error('Error loading metadata:', error)
				this.error = error.response?.data?.error || error.message || this.t('metavox', 'Failed to load metadata')
			} finally {
				this.loading = false
			}
		},

		async detectGroupfolder() {
			try {
				// Method 1: Check voor mount-root directories (groupfolders zelf)
				if (this.fileInfo.type === 'dir' &&
					this.fileInfo.mountType === 'group' &&
					this.fileInfo.attributes?.['is-mount-root'] === true) {

					console.log('🔍 Item is een mount-root directory (groupfolder zelf)')
					const groupfolders = await this.getAllGroupfolders()

					for (const gf of groupfolders) {
						if (gf.mount_point === this.fileInfo.name) {
							console.log('✅ Mount-root matches groupfolder:', gf)
							return gf.id
						}
					}
				}

				// Method 2: Check mountPoint - meest betrouwbare methode voor items IN groupfolders
				if (this.fileInfo.mountPoint && this.fileInfo.mountPoint !== '/' && this.fileInfo.mountPoint !== '') {
					console.log('🔍 MountPoint gevonden:', this.fileInfo.mountPoint)
					const mountResult = await this.detectFromMountPoint(this.fileInfo.mountPoint)
					if (mountResult) {
						console.log('✅ Groupfolder gevonden via mountPoint:', mountResult)
						return mountResult
					}
				}

				// Method 3: Check via het volledige path van het fileInfo
				if (this.fileInfo.path && this.fileInfo.path !== '/' && this.fileInfo.path !== '') {
					console.log('🔍 Checking path via fileInfo.path:', this.fileInfo.path)
					const pathResult = await this.detectFromPath(this.fileInfo.path)
					if (pathResult) {
						console.log('✅ Groupfolder gevonden via fileInfo.path:', pathResult)
						return pathResult
					}
				}

				console.log('❌ Geen groupfolder gedetecteerd')
				return null
			} catch (error) {
				console.error('Error detecting groupfolder:', error)
				return null
			}
		},

		async getAllGroupfolders() {
			const response = await axios.get(generateUrl('/apps/metavox/api/groupfolders'))
			const groupfoldersData = response.data || {}
			return Object.values(groupfoldersData)
		},

		async detectFromMountPoint(mountPoint) {
			if (!mountPoint) return null

			try {
				const groupfolders = await this.getAllGroupfolders()

				for (const gf of groupfolders) {
					if (mountPoint === gf.mount_point ||
						mountPoint.endsWith('/' + gf.mount_point) ||
						gf.mount_point === mountPoint.replace(/^\//, '')) {
						console.log('✅ MountPoint matches groupfolder:', gf)
						return gf.id
					}
				}
			} catch (error) {
				console.warn('⚠️ Error getting groupfolders for mountPoint detection:', error)
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
						console.log('✅ Path matches groupfolder:', gf)
						return gf.id
					}

					if (path.startsWith(gf.mount_point + '/') || path === gf.mount_point) {
						console.log('✅ Path matches groupfolder (no leading slash):', gf)
						return gf.id
					}
				}
			} catch (error) {
				console.warn('⚠️ Error getting groupfolders for detection:', error)
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

			console.log('🔒 Nextcloud permissions calculated:', {
				rawPermissions: permissions,
				canRead,
				canWrite,
				canCreate,
				canDelete,
				canShare,
				canEditMetadata,
			})

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
					)
					const gfData = gfResponse.data || []

					// Only take fields with applies_to_groupfolder = 1 (they have values)
					gfData.forEach(field => {
						if (field.field_name && (field.applies_to_groupfolder === 1 || field.applies_to_groupfolder === '1')) {
							fieldMap.set(field.field_name, field)
							console.log('📋 Groupfolder field:', field.field_name, '=', field.value)
						}
					})
				} catch (gfError) {
					console.error('❌ Fout bij laden groupfolder metadata:', gfError)
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
						)
						const fileData = fileResponse.data || []

						// Only take fields with applies_to_groupfolder = 0 (they have values for this file)
						fileData.forEach(field => {
							if (field.field_name && (field.applies_to_groupfolder === 0 || field.applies_to_groupfolder === '0')) {
								fieldMap.set(field.field_name, field)
								console.log('📋 File field:', field.field_name, '=', field.value)
							}
						})
					} catch (fileError) {
						console.error('❌ Fout bij laden file metadata:', fileError)
					}
				}

				const allFields = Array.from(fieldMap.values())
				console.log('📋 Total fields loaded:', allFields.length)
				console.log('📋 Fields:', allFields.map(f => `${f.field_name} (${f.applies_to_groupfolder})`).join(', '))
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
			this.metadata = {
				...this.metadata,
				[fieldName]: value,
			}
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

/* Separator between sections */
.metadata-separator {
	height: 1px;
	background: var(--color-border-dark);
	margin: 24px 0;
	box-shadow: 0 1px 0 var(--color-border);
}

/* Item Metadata Header - Same prominent styling as teamfolder */
.item-metadata-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
	padding-bottom: 12px;
	border-bottom: 2px solid var(--color-primary-element);
}

.item-metadata-header h3 {
	margin: 0;
	font-size: 16px;
	font-weight: 700;
	color: var(--color-primary-element);
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

.field-display {
	display: flex;
	flex-direction: column;
	margin-bottom: 12px;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.field-display:last-child {
	border-bottom: none;
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
