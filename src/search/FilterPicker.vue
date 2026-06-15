<template>
	<NcModal :show="show" size="small" @close="cancel">
		<div class="metavox-filter-picker">
			<h2 class="picker-title">
				<MetadataIcon :size="20" class="picker-title__icon" />
				{{ t('Filter by metadata') }}
			</h2>

			<div class="picker-field">
				<label class="picker-field__label" for="mv-filter-folder">{{ t('Team folder') }}</label>
				<NcSelect
					input-id="mv-filter-folder"
					v-model="selectedFolder"
					:options="folders"
					label="mount_point"
					:placeholder="t('Select a team folder')"
					:loading="loadingFolders"
					@update:model-value="onFolderChange" />
			</div>

			<div v-if="selectedFolder" class="picker-field">
				<label class="picker-field__label" for="mv-filter-field">{{ t('Field') }}</label>
				<NcSelect
					input-id="mv-filter-field"
					v-model="selectedField"
					:options="fields"
					label="field_label"
					:placeholder="t('Select a field')"
					:loading="loadingFields"
					:no-options="t('No filterable fields in this folder')"
					@update:model-value="onFieldChange" />
			</div>

			<div v-if="selectedField" class="picker-field">
				<label class="picker-field__label" for="mv-filter-value">{{ t('Value') }}</label>
				<DynamicFieldInput
					id="mv-filter-value"
					v-model="value"
					:type="selectedField.field_type"
					:field="selectedField" />
				<p v-if="isMultiselect" class="picker-field__hint">
					{{ t('Files matching all selected options are shown.') }}
				</p>
			</div>

			<div class="picker-actions">
				<NcButton type="tertiary" @click="cancel">
					{{ t('Cancel') }}
				</NcButton>
				<NcButton type="primary" :disabled="!canApply" @click="apply">
					{{ t('Apply filter') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcModal, NcSelect, NcButton } from '@nextcloud/vue'
import MetadataIcon from 'vue-material-design-icons/Tag.vue'
import DynamicFieldInput from '../components/fields/DynamicFieldInput.vue'

export default {
	name: 'FilterPicker',
	components: { NcModal, NcSelect, NcButton, MetadataIcon, DynamicFieldInput },
	data() {
		return {
			show: true,
			folders: [],
			fields: [],
			selectedFolder: null,
			selectedField: null,
			value: '',
			loadingFolders: false,
			loadingFields: false,
		}
	},
	computed: {
		isMultiselect() {
			const t = this.selectedField?.field_type
			return t === 'multiselect' || t === 'multi_select'
		},
		// Raw value array (for multiselect) normalized to a token list.
		valueTokens() {
			if (Array.isArray(this.value)) {
				return this.value
					.map((v) => (v && typeof v === 'object' ? (v.value ?? v.label ?? '') : v))
					.map((v) => String(v))
					.filter((v) => v !== '')
			}
			const s = this.value === null || this.value === undefined ? '' : String(this.value)
			return s === '' ? [] : [s]
		},
		// Value the backend filters on: multiselect tokens joined with ';#'.
		normalizedValue() {
			return this.valueTokens.join(';#')
		},
		// Human-readable value for the chip label (option labels where known).
		displayValue() {
			if (Array.isArray(this.value)) {
				return this.value
					.map((v) => (v && typeof v === 'object' ? (v.label ?? v.value ?? '') : v))
					.filter((v) => String(v) !== '')
					.join(', ')
			}
			return this.normalizedValue
		},
		canApply() {
			return Boolean(this.selectedFolder) && Boolean(this.selectedField)
				&& this.normalizedValue !== ''
		},
	},
	async mounted() {
		await this.loadFolders()
	},
	methods: {
		t(text) {
			return window.t ? window.t('metavox', text) : text
		},
		async loadFolders() {
			this.loadingFolders = true
			try {
				const res = await axios.get(generateUrl('/apps/metavox/api/user/groupfolders'))
				this.folders = res.data || []
			} catch (e) {
				this.folders = []
			} finally {
				this.loadingFolders = false
			}
		},
		onFieldChange() {
			// Reset the value when the field changes so a stale value from a
			// previous (differently-typed) field can't leak through.
			this.value = ''
		},
		async onFolderChange() {
			this.selectedField = null
			this.value = ''
			this.fields = []
			if (!this.selectedFolder) {
				return
			}
			this.loadingFields = true
			try {
				const gfId = this.selectedFolder.id
				// The /fields endpoint returns only the assigned field IDs, so
				// fetch the full field definitions separately and intersect.
				const [assignedRes, allRes] = await Promise.all([
					axios.get(generateUrl(`/apps/metavox/api/user/groupfolders/${gfId}/fields`)),
					axios.get(generateUrl('/apps/metavox/api/user/groupfolder-fields')),
				])
				const assignedIds = new Set((assignedRes.data || []).map((id) => Number(id)))
				this.fields = (allRes.data || [])
					.filter((f) => assignedIds.has(Number(f.id)))
					// Only file fields can be filtered per file.
					.filter((f) => (f.applies_to_groupfolder ?? 0) === 0)
			} catch (e) {
				this.fields = []
			} finally {
				this.loadingFields = false
			}
		},
		apply() {
			if (!this.canApply) {
				return
			}
			this.$emit('select', {
				field: this.selectedField.field_name,
				value: this.normalizedValue,
				// Readable label for the chip: "Field label: value(s)".
				label: `${this.selectedField.field_label}: ${this.displayValue}`,
				groupfolderId: this.selectedFolder.id,
			})
			this.show = false
		},
		cancel() {
			this.show = false
			this.$emit('select', null)
		},
	},
}
</script>

<style scoped>
.metavox-filter-picker {
	padding: 24px;
	min-width: 320px;
}
.picker-title {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 20px;
	font-size: 1.25rem;
}
.picker-title__icon {
	opacity: 0.8;
}
.picker-field {
	margin-bottom: 18px;
}
.picker-field__label {
	display: block;
	margin-bottom: 6px;
	font-weight: 600;
}
.picker-field__hint {
	margin-top: 6px;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}
.picker-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 28px;
}
</style>
