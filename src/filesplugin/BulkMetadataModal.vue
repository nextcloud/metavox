<template>
	<NcModal
		v-if="show"
		:name="t('metavox', 'Edit Metadata')"
		size="large"
		@close="close">
		<div class="bulk-metadata-modal">
			<h2 class="modal-title">
				{{ t('metavox', 'Edit Metadata') }}
			</h2>

			<p class="modal-description">
				{{ t('metavox', 'Edit metadata for {count} selected files', { count: files.length }) }}
			</p>

			<!-- Selected files list -->
			<div class="selected-files">
				<h3>{{ t('metavox', 'Selected Files') }}</h3>
				<ul class="file-list">
					<li v-for="file in files" :key="file.fileid" class="file-item">
						<FileIcon :size="20" />
						<span class="file-name">{{ file.basename }}</span>
					</li>
				</ul>
			</div>

			<!-- Merge strategy -->
			<div class="merge-strategy">
				<h3>{{ t('metavox', 'Update Mode') }}</h3>
				<NcCheckboxRadioSwitch
					:checked="mergeStrategy === 'overwrite'"
					type="radio"
					name="merge-strategy"
					@update:checked="mergeStrategy = 'overwrite'">
					{{ t('metavox', 'Overwrite existing values') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:checked="mergeStrategy === 'fill-empty'"
					type="radio"
					name="merge-strategy"
					@update:checked="mergeStrategy = 'fill-empty'">
					{{ t('metavox', 'Only fill empty fields') }}
				</NcCheckboxRadioSwitch>
			</div>

			<!-- Loading state -->
			<div v-if="loading" class="loading-state">
				<NcLoadingIcon :size="32" />
				<p>{{ t('metavox', 'Loading metadata fields...') }}</p>
			</div>

			<!-- Metadata form -->
			<div v-else-if="fields.length > 0" class="metadata-section">
				<h3>{{ t('metavox', 'Metadata') }}</h3>
				<MetadataForm
					:fields="fields"
					:values="metadata"
					:select-values="selectValues"
					:multi-select-values="multiSelectValues"
					:select-key="selectKey"
					@update="handleFieldUpdate" />
			</div>

			<!-- No fields message -->
			<div v-else class="no-fields">
				<p>{{ t('metavox', 'No metadata fields available for these files.') }}</p>
			</div>

			<!-- Actions -->
			<div class="modal-actions">
				<div class="actions-left">
					<NcButton
						type="error"
						:disabled="clearing || fields.length === 0"
						@click="confirmClearAll">
						<template #icon>
							<NcLoadingIcon v-if="clearing" :size="20" />
							<DeleteIcon v-else :size="20" />
						</template>
						{{ clearing ? t('metavox', 'Clearing...') : t('metavox', 'Clear All') }}
					</NcButton>
					<NcButton
						type="secondary"
						:disabled="exporting || fields.length === 0"
						@click="exportToCsv">
						<template #icon>
							<NcLoadingIcon v-if="exporting" :size="20" />
							<DownloadIcon v-else :size="20" />
						</template>
						{{ exporting ? t('metavox', 'Exporting...') : t('metavox', 'Export CSV') }}
					</NcButton>
				</div>
				<div class="actions-right">
					<NcButton type="tertiary" @click="close">
						{{ t('metavox', 'Cancel') }}
					</NcButton>
					<NcButton
						type="primary"
						:disabled="saving || !hasChanges"
						@click="save">
						<template #icon>
							<NcLoadingIcon v-if="saving" :size="20" />
						</template>
						{{ saving ? t('metavox', 'Saving...') : t('metavox', 'Save') }}
					</NcButton>
				</div>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcModal, NcButton, NcCheckboxRadioSwitch, NcLoadingIcon } from '@nextcloud/vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import FileIcon from 'vue-material-design-icons/File.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import MetadataForm from './MetadataForm.vue'

export default {
	name: 'BulkMetadataModal',

	components: {
		NcModal,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		FileIcon,
		DeleteIcon,
		DownloadIcon,
		MetadataForm,
	},

	props: {
		show: {
			type: Boolean,
			default: false,
		},
		files: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			loading: false,
			saving: false,
			clearing: false,
			exporting: false,
			fields: [],
			metadata: {},
			originalMetadata: {},
			mergeStrategy: 'overwrite',
			selectValues: {},
			multiSelectValues: {},
			selectKey: 0,
		}
	},

	computed: {
		hasChanges() {
			return JSON.stringify(this.metadata) !== JSON.stringify(this.originalMetadata)
		},
	},

	watch: {
		show: {
			immediate: true,
			handler(newVal) {
				if (newVal && this.files.length > 0) {
					this.loadFields()
				}
			},
		},
	},

	methods: {
		t(app, text, vars) {
			if (vars) {
				let result = window.t ? window.t(app, text) : text
				Object.keys(vars).forEach(key => {
					result = result.replace(`{${key}}`, vars[key])
				})
				return result
			}
			return window.t ? window.t(app, text) : text
		},

		async loadFields() {
			this.loading = true
			try {
				// Get the groupfolder ID from the first file's path
				const firstFile = this.files[0]
				const path = firstFile.path || firstFile.filename || ''

				// Try to detect groupfolder from path
				const groupfolderMatch = path.match(/^\/__groupfolders\/(\d+)/)
				let groupfolderId = null

				if (groupfolderMatch) {
					groupfolderId = groupfolderMatch[1]
				} else {
					// Check if files are in a groupfolder mount
					const response = await axios.get(generateUrl('/apps/metavox/api/groupfolders'))
					const groupfolders = response.data

					// Find matching groupfolder based on path
					for (const gf of groupfolders) {
						if (path.includes(`/${gf.mount_point}/`) || path.startsWith(`/${gf.mount_point}`)) {
							groupfolderId = gf.id
							break
						}
					}
				}

				if (groupfolderId) {
					// Load fields for this groupfolder - use same endpoint as sidebar
					// Use the first file's ID to get the metadata endpoint which includes field definitions
					const firstFileId = this.files[0].fileid
					const fieldsResponse = await axios.get(
						generateUrl('/apps/metavox/api/groupfolders/{groupfolderId}/files/{fileId}/metadata', {
							groupfolderId,
							fileId: firstFileId,
						})
					)

					// Filter to only file/item fields (applies_to_groupfolder = 0 or null)
					// Same logic as FilesSidebarTab.vue
					this.fields = (fieldsResponse.data || []).filter(field => {
						const appliesTo = field.applies_to_groupfolder
						return appliesTo === 0 || appliesTo === '0' || appliesTo === null || appliesTo === undefined
					})
				} else {
					this.fields = []
				}

				// Initialize metadata object
				this.metadata = {}
				this.originalMetadata = {}
				this.fields.forEach(field => {
					this.metadata[field.field_name] = ''
					this.originalMetadata[field.field_name] = ''
				})

				// Initialize select values
				this.initializeSelectValues()
			} catch (error) {
				console.error('Failed to load fields:', error)
				showError(this.t('metavox', 'Failed to load metadata fields'))
			} finally {
				this.loading = false
			}
		},

		initializeSelectValues() {
			this.selectValues = {}
			this.multiSelectValues = {}

			this.fields.forEach(field => {
				if (field.field_type === 'select') {
					this.selectValues[field.field_name] = null
				} else if (field.field_type === 'multiselect' || field.field_type === 'multi_select') {
					this.multiSelectValues[field.field_name] = []
				}
			})

			this.selectKey++
		},

		handleFieldUpdate(fieldName, value) {
			this.metadata = {
				...this.metadata,
				[fieldName]: value,
			}

			// Update select values for select fields
			const field = this.fields.find(f => f.field_name === fieldName)
			if (field) {
				if (field.field_type === 'select') {
					this.selectValues[fieldName] = value || null
				} else if (field.field_type === 'multiselect' || field.field_type === 'multi_select') {
					this.multiSelectValues[fieldName] = value ? value.split(';#') : []
				}
			}
		},

		async save() {
			this.saving = true
			try {
				const fileIds = this.files.map(f => f.fileid)

				// Filter out empty values if using fill-empty strategy
				const metadataToSave = {}
				Object.keys(this.metadata).forEach(key => {
					if (this.metadata[key] !== '' && this.metadata[key] !== null) {
						metadataToSave[key] = this.metadata[key]
					}
				})

				if (Object.keys(metadataToSave).length === 0) {
					showError(this.t('metavox', 'No metadata values to save'))
					return
				}

				await axios.post(generateUrl('/apps/metavox/api/files/bulk-metadata'), {
					fileIds,
					metadata: metadataToSave,
					mergeStrategy: this.mergeStrategy,
				})

				showSuccess(this.t('metavox', 'Metadata saved for {count} files', { count: this.files.length }))
				this.$emit('saved')
				this.close()
			} catch (error) {
				console.error('Failed to save metadata:', error)
				showError(error.response?.data?.error || this.t('metavox', 'Failed to save metadata'))
			} finally {
				this.saving = false
			}
		},

		close() {
			this.metadata = {}
			this.originalMetadata = {}
			this.fields = []
			this.$emit('close')
		},

		async confirmClearAll() {
			if (!confirm(this.t('metavox', 'Are you sure you want to clear all metadata from {count} files? This cannot be undone.', { count: this.files.length }))) {
				return
			}
			await this.clearAllMetadata()
		},

		async clearAllMetadata() {
			this.clearing = true
			try {
				const fileIds = this.files.map(f => f.fileid)

				await axios.post(generateUrl('/apps/metavox/api/files/clear-metadata'), {
					fileIds,
				})

				showSuccess(this.t('metavox', 'Metadata cleared for {count} files', { count: this.files.length }))
				this.$emit('saved')
				this.close()
			} catch (error) {
				console.error('Failed to clear metadata:', error)
				showError(error.response?.data?.error || this.t('metavox', 'Failed to clear metadata'))
			} finally {
				this.clearing = false
			}
		},

		async exportToCsv() {
			this.exporting = true
			try {
				const fileIds = this.files.map(f => f.fileid)

				const response = await axios.post(generateUrl('/apps/metavox/api/files/export-metadata'), {
					fileIds,
				})

				// Create CSV content
				const data = response.data
				if (!data || data.length === 0) {
					showError(this.t('metavox', 'No metadata to export'))
					return
				}

				// Build CSV
				const headers = ['File Path', 'File Name', ...this.fields.map(f => f.field_label || f.field_name)]
				const rows = data.map(item => {
					const row = [item.path, item.name]
					this.fields.forEach(field => {
						row.push(item.metadata[field.field_name] || '')
					})
					return row
				})

				// Escape CSV values
				const escapeCsv = (val) => {
					if (val === null || val === undefined) return ''
					const str = String(val)
					if (str.includes(',') || str.includes('"') || str.includes('\n')) {
						return '"' + str.replace(/"/g, '""') + '"'
					}
					return str
				}

				const csvContent = [
					headers.map(escapeCsv).join(','),
					...rows.map(row => row.map(escapeCsv).join(',')),
				].join('\n')

				// Download file
				const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' })
				const url = URL.createObjectURL(blob)
				const link = document.createElement('a')
				link.href = url
				link.download = `metadata-export-${new Date().toISOString().split('T')[0]}.csv`
				document.body.appendChild(link)
				link.click()
				document.body.removeChild(link)
				URL.revokeObjectURL(url)

				showSuccess(this.t('metavox', 'Metadata exported for {count} files', { count: this.files.length }))
			} catch (error) {
				console.error('Failed to export metadata:', error)
				showError(error.response?.data?.error || this.t('metavox', 'Failed to export metadata'))
			} finally {
				this.exporting = false
			}
		},
	},
}
</script>

<style scoped>
.bulk-metadata-modal {
	padding: 20px;
	display: flex;
	flex-direction: column;
	gap: 20px;
	max-height: 80vh;
	overflow-y: auto;
}

.modal-title {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
}

.modal-description {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.selected-files {
	background: var(--color-background-hover);
	padding: 12px;
	border-radius: var(--border-radius-large);
}

.selected-files-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 8px;
}

.selected-files-header h3 {
	margin: 0;
	font-size: 14px;
	font-weight: 600;
}

.metadata-summary {
	font-size: 12px;
	color: var(--color-primary-element);
	font-weight: 500;
}

.file-list {
	list-style: none;
	margin: 0;
	padding: 0;
	max-height: 120px;
	overflow-y: auto;
}

.file-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 0;
	font-size: 13px;
}

.file-name {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	flex: 1;
}

.metadata-indicator {
	font-size: 11px;
	padding: 2px 6px;
	border-radius: 10px;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	min-width: 20px;
	text-align: center;
}

.metadata-indicator.has-metadata {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
	font-weight: 600;
}

.merge-strategy {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.merge-strategy h3 {
	margin: 0;
	font-size: 14px;
	font-weight: 600;
}

.loading-state {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
	padding: 40px;
	color: var(--color-text-maxcontrast);
}

.metadata-section h3 {
	margin: 0 0 12px 0;
	font-size: 14px;
	font-weight: 600;
}

.no-fields {
	padding: 20px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.modal-actions {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}

.actions-left {
	display: flex;
	gap: 8px;
}

.actions-right {
	display: flex;
	gap: 8px;
}
</style>
