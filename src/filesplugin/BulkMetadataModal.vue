<template>
	<!-- NcDialog, not NcModal: this is a form, and §3 makes NcDialog the
	     default for one. As an NcModal it showed TWO titles at once — the
	     modal's own floating header, absolutely positioned over whatever
	     was behind it, plus this component's <h2> — and that header said
	     "MetaVox", naming the app rather than the dialog. -->
	<!-- TRANSLATORS Title of the dialog that edits the metadata of the selected files -->
	<NcDialog :name="t('metavox', 'Edit metadata')"
		v-if="show"
		:buttons="dialogButtons"
		size="normal"
		@closing="close">
		<div class="bulk-metadata-modal">
			<!-- Header -->
			<div class="modal-header">
				<div class="file-badges">
					<span v-for="(file, i) in files.slice(0, 5)" :key="i" class="file-badge">
						{{ file.basename }}
					</span>
					<span v-if="files.length > 5" class="file-badge file-badge--more">
						+{{ files.length - 5 }}
					</span>
				</div>
			</div>

			<!-- Strategy + Search bar -->
			<div class="toolbar">
				<div class="strategy-selector">
					<NcCheckboxRadioSwitch
						:model-value="mergeStrategy === 'overwrite'"
						type="radio"
						name="merge-strategy"
						@update:model-value="mergeStrategy = 'overwrite'">
						<!-- TRANSLATORS Radio button: saving replaces values the selected files already have -->
						{{ t('metavox', 'Overwrite existing values') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						:model-value="mergeStrategy === 'fill-empty'"
						type="radio"
						name="merge-strategy"
						@update:model-value="mergeStrategy = 'fill-empty'">
						<!-- TRANSLATORS Radio button: saving only fills fields that are still empty on the selected files -->
						{{ t('metavox', 'Only fill empty fields') }}
					</NcCheckboxRadioSwitch>
				</div>
				<div v-if="fields.length > 6" class="field-search">
					<MagnifyIcon :size="16" class="search-icon" />
					<input
						v-model="searchQuery"
						type="text"
						class="search-input"
						:placeholder="t('metavox', 'Search fields')"> <!-- TRANSLATORS Placeholder of the search box that filters the fields in the dialog -->
				</div>
			</div>

			<!-- Loading state -->
			<div v-if="loading" class="loading-state">
				<NcLoadingIcon :size="32" />
				<p>{{ t('metavox', 'Loading metadata fields …') }}</p>
			</div>

			<!-- Metadata form -->
			<div v-else-if="filteredFields.length > 0" class="metadata-section">
				<MetadataForm
					:fields="filteredFields"
					:values="metadata"
					:select-values="selectValues"
					:multi-select-values="multiSelectValues"
					:select-key="selectKey"
					@update="handleFieldUpdate" />
			</div>

			<!-- No fields message -->
			<div v-else-if="fields.length > 0 && searchQuery" class="no-fields">
				<!-- TRANSLATORS {query} is the text typed in the field search box, e.g. "No fields match status" -->
				<p>{{ t('metavox', 'No fields match {query}', { query: searchQuery }, undefined, PLAIN_TEXT) }}</p>
			</div>
			<div v-else class="no-fields">
				<p>{{ t('metavox', 'No metadata fields available for these files.') }}</p>
			</div>

			<!-- Clear all and Export CSV act on the content, not on the
			     dialog, so they stay with it. Cancel and Save are the
			     dialog's own and moved to :buttons, which places them in
			     order with the primary last (§6). -->
			<div class="content-actions">
				<NcButton
					variant="error"
					:disabled="clearing || fields.length === 0"
					@click="confirmClearAll">
					<template #icon>
						<NcLoadingIcon v-if="clearing" :size="20" />
						<DeleteIcon v-else :size="20" />
					</template>
					{{ clearLabel }}
				</NcButton>
				<NcButton
					variant="secondary"
					:disabled="exporting || fields.length === 0"
					@click="exportToCsv">
					<template #icon>
						<NcLoadingIcon v-if="exporting" :size="20" />
						<DownloadIcon v-else :size="20" />
					</template>
					{{ exportLabel }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { showSuccess, showError } from '../utils/toast.js'
import { showConfirmation } from '../utils/confirm.js'
import { translate, translatePlural } from '@nextcloud/l10n'
import { PLAIN_TEXT } from '../utils/fieldValue.js'
import { errorText } from '../utils/errorText.js'
import { isRequired } from '../utils/requiredFields.js'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { loadFileMetadata } from './columns/MetaVoxAPI.js'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import FileDocumentEditIcon from 'vue-material-design-icons/FileDocumentEdit.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import MetadataForm from './MetadataForm.vue'

export default {
	name: 'BulkMetadataModal',

	// Templates read the t() options from the component.
	setup() {
		return { PLAIN_TEXT }
	},

	components: {
		NcDialog,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		DeleteIcon,
		DownloadIcon,
		PencilIcon,
		FileDocumentEditIcon,
		MagnifyIcon,
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
			searchQuery: '',
		}
	},

	computed: {
		/** The labels of the required fields: "Clear all" leaves them as they are. */
		requiredLabels() {
			return this.fields.filter(isRequired).map((field) => field.field_label).join(', ')
		},

		/**
		 * NcDialog renders these in order with the primary last, which is
		 * the §6 button order, and returning false from a callback keeps
		 * the dialog open while the save runs.
		 */
		dialogButtons() {
			return [
				{
					// TRANSLATORS Button that closes the dialog without saving; keep it short
					label: this.t('metavox', 'Cancel'),
					callback: () => this.close(),
				},
				{
					label: this.saving
						// TRANSLATORS Label of the save button while the metadata is being saved; keep it short
						? this.t('metavox', 'Saving …')
						// TRANSLATORS Button that saves the metadata in the dialog; keep it short
						: this.t('metavox', 'Save'),
					variant: 'primary',
					disabled: this.saving || !this.hasChanges,
					callback: () => { this.save(); return false },
				},
			]
		},

		hasChanges() {
			return JSON.stringify(this.metadata) !== JSON.stringify(this.originalMetadata)
		},
		clearLabel() {
			return this.clearing
				// TRANSLATORS Label of the clear button while the metadata is being removed; keep it short
				? this.t('metavox', 'Clearing …')
				// TRANSLATORS Button that removes all metadata from the selected files; keep it short
				: this.t('metavox', 'Clear all')
		},
		exportLabel() {
			return this.exporting
				// TRANSLATORS Label of the export button while the export file is being made; keep it short
				? this.t('metavox', 'Exporting …')
				// TRANSLATORS Button that downloads the metadata of the selected files as a CSV (spreadsheet) file; keep CSV as is
				: this.t('metavox', 'Export CSV')
		},
		filteredFields() {
			if (!this.searchQuery) return this.fields
			const q = this.searchQuery.toLowerCase()
			return this.fields.filter(f =>
				(f.field_label || '').toLowerCase().includes(q) ||
				(f.field_name || '').toLowerCase().includes(q)
			)
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
		// The library's own functions. The previous local shim called window.t,
		// fell back to the raw English string when it was missing, and could
		// not do plurals at all.
		t: translate,
		n: translatePlural,

		async loadFields() {
			this.loading = true
			try {
				// The server finds the team folder of the first file, and its fields.
				const { fields } = await loadFileMetadata(this.files[0].fileid)
				// Only file/item fields (applies_to_groupfolder = 0 or null), like the sidebar
				this.fields = fields.filter(field => {
					const appliesTo = field.applies_to_groupfolder
					return appliesTo === 0 || appliesTo === '0' || appliesTo === null || appliesTo === undefined
				})

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
				// Not in a team folder: no fields to edit, as before.
				if (error?.response?.status === 404) {
					this.fields = []
					return
				}
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

				// TRANSLATORS Confirmation message; {count} is the number of files the metadata was saved for
				showSuccess(this.n('metavox', 'Metadata saved for {count} file', 'Metadata saved for {count} files', this.files.length, { count: this.files.length }))
				this.$emit('saved')
				this.close()
			} catch (error) {
				console.error('Failed to save metadata:', error)
				showError(errorText(error, this.t('metavox', 'Failed to save metadata')))
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
			// A native confirm() is unthemeable, traps the browser and ignores
			// the platform's own dialog. showConfirmation names the destructive
			// action on its button rather than saying "OK".
			const ok = await showConfirmation({
				// TRANSLATORS Title of the dialog that asks to confirm removing all metadata from the selected files
				name: this.t('metavox', 'Clear metadata'),
				text: this.requiredLabels
					// TRANSLATORS Confirmation question; {count} is the number of selected files, {fields} the names of the required fields that keep their value, e.g. "Status, Owner"
					? this.n('metavox', 'Clear all metadata from this file? Required fields keep their value: {fields}. This cannot be undone.',
						'Clear all metadata from {count} files? Required fields keep their value: {fields}. This cannot be undone.',
						this.files.length,
						{ count: this.files.length, fields: this.requiredLabels }, PLAIN_TEXT,
					)
					// TRANSLATORS Confirmation question; {count} is the number of selected files
					: this.n('metavox', 'Clear all metadata from this file? This cannot be undone.',
						'Clear all metadata from {count} files? This cannot be undone.',
						this.files.length,
						{ count: this.files.length }, PLAIN_TEXT,
					),
				// TRANSLATORS Button in the confirmation dialog that removes all metadata from the selected files
				labelConfirm: this.t('metavox', 'Clear metadata'),
				// TRANSLATORS Button in the confirmation dialog that keeps the metadata; keep it short
				labelReject: this.t('metavox', 'Cancel'),
			})
			if (!ok) return
			await this.clearAllMetadata()
		},

		async clearAllMetadata() {
			this.clearing = true
			try {
				const fileIds = this.files.map(f => f.fileid)

				await axios.post(generateUrl('/apps/metavox/api/files/clear-metadata'), {
					fileIds,
				})

				if (this.requiredLabels) {
					// TRANSLATORS Confirmation message; {count} is the number of files the metadata was removed from, {fields} the required fields that kept their value
					showSuccess(this.n('metavox', 'Metadata cleared for {count} file. Kept: {fields}', 'Metadata cleared for {count} files. Kept: {fields}', this.files.length, { count: this.files.length, fields: this.requiredLabels }))
				} else {
					// TRANSLATORS Confirmation message; {count} is the number of files the metadata was removed from
					showSuccess(this.n('metavox', 'Metadata cleared for {count} file', 'Metadata cleared for {count} files', this.files.length, { count: this.files.length }))
				}
				this.$emit('saved')
				this.close()
			} catch (error) {
				console.error('Failed to clear metadata:', error)
				showError(errorText(error, this.t('metavox', 'Failed to clear metadata')))
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
				const headers = [
					// TRANSLATORS Noun: column header in the exported CSV file, the full path of each file
					this.t('metavox', 'File path'),
					// TRANSLATORS Noun: column header in the exported CSV file
					this.t('metavox', 'File name'),
					...this.fields.map(f => f.field_label || f.field_name),
				]
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

				// TRANSLATORS Confirmation message; {count} is the number of files whose metadata was exported to the CSV file
				showSuccess(this.n('metavox', 'Metadata exported for {count} file', 'Metadata exported for {count} files', this.files.length, { count: this.files.length }))
			} catch (error) {
				console.error('Failed to export metadata:', error)
				showError(errorText(error, this.t('metavox', 'Failed to export metadata')))
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
	gap: 12px;
	max-height: 75vh;
}

/* Header */
.modal-header {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.file-badges {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

.file-badge {
	display: inline-block;
	font-size: var(--font-size-small);
	padding: 2px 8px;
	border-radius: 10px;
	background: var(--color-primary-element-light, rgba(0,130,201,.1));
	color: var(--color-primary-element);
	max-width: 180px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.file-badge--more {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

/* Toolbar: strategy + search */
.toolbar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 10px;
	padding-bottom: 8px;
	border-bottom: 1px solid var(--color-border);
}

.strategy-selector {
	display: flex;
	gap: 4px;
	flex-shrink: 0;
}

/* Search: on a line of its own when the choices leave it too little room. */
.field-search {
	flex: 1 1 200px;
	position: relative;
}

.search-icon {
	position: absolute;
	left: 8px;
	top: 50%;
	transform: translateY(-50%);
	pointer-events: none;
	color: var(--color-text-maxcontrast);
}

.search-input {
	width: 100%;
	height: 32px;
	padding: 0 12px 0 30px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 10px);
	background: var(--color-main-background);
	font-size: 13px;
	outline: none;
	box-sizing: border-box;
}

.search-input:focus {
	border-color: var(--color-primary-element);
}

/* Metadata form — compact override */
.metadata-section {
	flex: 1;
	min-height: 0;
	overflow-y: auto;
}

/* MetadataForm owns its own density and got it right: 24px between fields,
   4px binding a label to its control. This block reached in with :deep()
   and flattened both to 2px, so the two gaps were identical — the exact
   failure §6 describes, where a label floats equidistant and attaches to
   the wrong control. 2px is also half a baseline, which is not on the
   scale, and 12px is below --font-size-small. */
.metadata-section :deep(.field-container) {
	border-bottom: none;
}

/* Loading / empty */
.loading-state {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	padding: 24px;
	color: var(--color-text-maxcontrast);
}

.no-fields {
	padding: 16px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

/* Clear all / Export CSV. Cancel and Save are NcDialog's now. 6px was off
   the 4px scale; 8px is the NcFormGroup group distance (§6). */
.content-actions {
	display: flex;
	gap: 8px;
	padding-top: 12px;
}
</style>
