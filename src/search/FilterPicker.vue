<template>
	<NcModal :show="show" size="small" @close="cancel">
		<div class="metavox-filter-picker">
			<h2 class="picker-title">
				<MetadataIcon :size="20" class="picker-title__icon" />
				{{ t('metavox', 'Filter by metadata') }} <!-- TRANSLATORS Title of the dialog that adds a metadata filter to the unified search -->
			</h2>

			<div class="picker-field">
				<label class="picker-field__label" for="mv-filter-folder">{{ t('metavox', 'Team folder') }}</label> <!-- TRANSLATORS Noun: label of the dropdown that picks the Team folder to search in -->
				<NcSelect
					input-id="mv-filter-folder"
					v-model="selectedFolder"
					:options="folders"
					label="mount_point"
					:placeholder="folderPlaceholder"
					:loading="loadingFolders"
					:searchable="false"
					@update:model-value="onFolderChange" />
			</div>

			<div v-if="selectedFolder" class="picker-field">
				<label class="picker-field__label" for="mv-filter-field">{{ t('metavox', 'Field') }}</label> <!-- TRANSLATORS Noun: label of the dropdown that picks the metadata field to filter on -->
				<NcSelect
					input-id="mv-filter-field"
					v-model="selectedField"
					:options="fields"
					label="field_label"
					:placeholder="fieldPlaceholder"
					:loading="loadingFields"
					:searchable="false"
					:no-options="t('metavox', 'No filterable fields in this folder')"
					@update:model-value="onFieldChange" />
			</div>

			<div v-if="selectedField" class="picker-field">
				<label class="picker-field__label" for="mv-filter-value">{{ t('metavox', 'Value') }}</label> <!-- TRANSLATORS Noun: label of the input for the field value to filter on -->
				<!-- Value step is driven by the values that ACTUALLY occur for this
				     field in this folder, so a chosen filter can never land on an
				     empty result set. text/url/number stay taggable (pick a known
				     value or type a new one); select/multiselect offer only the
				     occurring values (not every defined option). date/checkbox/
				     user/file keep their specialised DynamicFieldInput picker. -->
				<NcSelect
					v-if="useValueSuggestions"
					input-id="mv-filter-value"
					v-model="value"
					:options="valueSuggestions"
					:multiple="isMultiselect"
					:taggable="suggestionsTaggable"
					:searchable="suggestionsTaggable"
					:create-option="(v) => v"
					:placeholder="valuePlaceholder"
					:loading="loadingValues"
					:no-options="noValuesText" />
				<DynamicFieldInput
					v-else
					id="mv-filter-value"
					v-model="value"
					:type="selectedField.field_type"
					:field="selectedField" />
				<p v-if="isMultiselect" class="picker-field__hint">
					{{ t('metavox', 'Files matching all selected options are shown.') }}
				</p>
			</div>

			<div class="picker-actions">
				<NcButton type="tertiary" @click="cancel">
					{{ t('metavox', 'Cancel') }} <!-- TRANSLATORS Button that closes the search filter dialog without adding a filter; keep it short -->
				</NcButton>
				<NcButton type="primary" :disabled="!canApply" @click="apply">
					{{ t('metavox', 'Apply filter') }} <!-- TRANSLATORS Button that adds the chosen filter to the search; keep it short -->
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcModal, NcSelect, NcButton } from '@nextcloud/vue'
import MetadataIcon from 'vue-material-design-icons/Tag.vue'
import DynamicFieldInput from '../components/fields/DynamicFieldInput.vue'
import { PLAIN_TEXT } from '../utils/fieldValue.js'

// Field types whose stored value can't be filtered meaningfully by value.
// File-link values are opaque "<fileid>:<path>" tokens (path goes stale on
// rename), so exact matching is pointless — hide them from the picker.
const UNFILTERABLE_TYPES = ['filelink', 'file']

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
			valueSuggestions: [],
			loadingValues: false,
		}
	},
	computed: {
		isMultiselect() {
			const type = this.selectedField?.field_type
			return type === 'multiselect' || type === 'multi_select'
		},
		folderPlaceholder() {
			// TRANSLATORS Placeholder of the Team folder dropdown in the search filter dialog
			return t('metavox', 'Select a Team folder')
		},
		fieldPlaceholder() {
			// TRANSLATORS Placeholder of the field dropdown in the search filter dialog
			return t('metavox', 'Select a field')
		},
		valuePlaceholder() {
			// TRANSLATORS Placeholder of the value dropdown in the search filter dialog
			return t('metavox', 'Select a value')
		},
		noValuesText() {
			return this.loadingValues
				// TRANSLATORS Shown in a dropdown while its entries load
				? t('metavox', 'Loading …')
				: t('metavox', 'No matching values in this folder')
		},
		// Fields whose value step is driven by the values occurring in the
		// folder. Includes select/multiselect so we only offer options that are
		// actually used (never a defined-but-unused option → empty results).
		// date/checkbox/user/file keep their specialised DynamicFieldInput.
		useValueSuggestions() {
			const type = this.selectedField?.field_type
			return type === 'text' || type === 'textarea' || type === 'number' || type === 'url'
				|| type === 'select' || type === 'multiselect' || type === 'multi_select'
		},
		// Free-text types may still introduce a new value by typing; select-style
		// fields must pick from the values that exist (typing a new one is
		// meaningless and would filter to nothing).
		suggestionsTaggable() {
			const type = this.selectedField?.field_type
			return type === 'text' || type === 'textarea' || type === 'number' || type === 'url'
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
		t,
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
			this.valueSuggestions = []
			if (this.useValueSuggestions) {
				this.loadFieldValues()
			}
		},
		async loadFieldValues() {
			if (!this.selectedFolder || !this.selectedField) {
				return
			}
			this.loadingValues = true
			try {
				const gfId = this.selectedFolder.id
				const res = await axios.get(
					generateUrl(`/apps/metavox/api/user/groupfolders/${gfId}/field-values`),
					{ params: { field: this.selectedField.field_name } },
				)
				this.valueSuggestions = (res.data && res.data.values) || []
			} catch (e) {
				this.valueSuggestions = []
			} finally {
				this.loadingValues = false
			}
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
					// File-link values are opaque "<fileid>:<path>" tokens — not
					// meaningfully filterable by value, so don't offer them.
					.filter((f) => !UNFILTERABLE_TYPES.includes(f.field_type))
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
				// TRANSLATORS: Text of a search filter chip, e.g. "Status: Approved".
				label: t('metavox', '{field}: {value}', { field: this.selectedField.field_label, value: this.displayValue }, undefined, PLAIN_TEXT),
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
