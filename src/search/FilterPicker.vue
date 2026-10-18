<template>
	<NcModal :show="show" size="small" @close="cancel">
		<div class="metavox-filter-picker">
			<h2 class="picker-title">
				<MetadataIcon :size="20" class="picker-title__icon" />
				<!-- TRANSLATORS Title of the dialog that adds a metadata filter to the unified search -->
				{{ t('metavox', 'Filter by metadata') }}
			</h2>

			<div class="picker-field">
				<!-- TRANSLATORS Noun: label of the dropdown that picks the Team folder to search in -->
				<label class="picker-field__label" for="mv-filter-folder">{{ t('metavox', 'Team folder') }}</label>
				<!-- TRANSLATORS Placeholder of the Team folder dropdown in the search filter dialog -->
				<NcSelect :placeholder="t('metavox', 'Select a Team folder')"
					input-id="mv-filter-folder"
					v-model="selectedFolder"
					:options="folderOptions"
					label="mount_point"
					:loading="loadingFolders"
					:searchable="false"
					label-outside
					@update:model-value="onFolderChange">
					<template #no-options>
						{{ folderNoOptions }}
					</template>
				</NcSelect>
			</div>

			<div v-if="selectedFolder" class="picker-field">
				<!-- TRANSLATORS Noun: label of the dropdown that picks the metadata field to filter on -->
				<label class="picker-field__label" for="mv-filter-field">{{ t('metavox', 'Field') }}</label>
				<!-- TRANSLATORS Placeholder of the field dropdown in the search filter dialog -->
				<NcSelect :placeholder="t('metavox', 'Select a field')"
					input-id="mv-filter-field"
					v-model="selectedField"
					:options="fields"
					label="display_label"
					:loading="loadingFields"
					:searchable="allFolders"
					label-outside
					@update:model-value="onFieldChange">
					<!-- `no-options` is a SLOT on vue-select, not a prop. Passed
					     as :no-options it was dropped in silence and the reader
					     saw vue-select's untranslated default. -->
					<template #no-options>
						{{ allFolders ? t('metavox', 'No filterable fields') : t('metavox', 'No filterable fields in this folder') }}
					</template>
				</NcSelect>
			</div>

			<div v-if="selectedField" class="picker-field">
				<!-- TRANSLATORS Noun: label of the input for the field value to filter on -->
				<label class="picker-field__label" for="mv-filter-value">{{ t('metavox', 'Value') }}</label>
				<!-- Value step is driven by the values that ACTUALLY occur for this
				     field in this folder, so a chosen filter can never land on an
				     empty result set. text/url/number stay taggable (pick a known
				     value or type a new one); select/multiselect offer only the
				     occurring values (not every defined option). date/checkbox/
				     user/file keep their specialised DynamicFieldInput picker. -->
				<!-- TRANSLATORS Placeholder of the value dropdown in the search filter dialog -->
				<NcSelect :placeholder="t('metavox', 'Select a value')"
					v-if="useValueSuggestions"
					input-id="mv-filter-value"
					v-model="value"
					:options="valueSuggestions"
					:multiple="isMultiselect"
					:taggable="suggestionsTaggable"
					:searchable="suggestionsTaggable"
					:create-option="(v) => v"
					:loading="loadingValues"
					label-outside>
					<template #no-options>
						{{ valueNoOptions }}
					</template>
				</NcSelect>
				<DynamicFieldInput
					v-else
					input-id="mv-filter-value"
					v-model="value"
					:type="selectedField.field_type"
					:field="selectedField" />
				<p v-if="isMultiselect" class="picker-field__hint">
					{{ t('metavox', 'Files with any of the selected options are shown.') }}
				</p>
				<p v-if="allFolders" class="picker-field__hint">
					{{ t('metavox', 'Searches every Team folder you are a member of.') }}
				</p>
				<p v-if="allFolders && suggestionsTaggable" class="picker-field__hint">
					{{ t('metavox', 'Type the whole value, exactly as it is written. To find part of a value, type it in the search box instead.') }}
				</p>
			</div>

			<p v-if="saveError" class="picker-field__error">
				{{ saveError }}
			</p>
			<!-- The other way to go: keep the filter as a folder in Files. Off
			     while saving, as a second click would save it twice. -->
			<div v-if="canSaveAsFolder" class="picker-save">
				<NcButton variant="secondary"
					:disabled="!canApply || saving"
					@click="saveAsFolder">
					{{ t('metavox', 'Save as folder') }}
				</NcButton>
			</div>
			<div class="picker-actions">
				<NcButton variant="tertiary" @click="cancel">
					<!-- TRANSLATORS Button that closes the search filter dialog without adding a filter; keep it short -->
					{{ t('metavox', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="!canApply" @click="apply">
					<!-- TRANSLATORS Button that adds the chosen filter to the search; keep it short -->
					{{ t('metavox', 'Apply filter') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcButton from '@nextcloud/vue/components/NcButton'
import MetadataIcon from 'vue-material-design-icons/Tag.vue'
import DynamicFieldInput from '../components/fields/DynamicFieldInput.vue'
import { loadState } from '@nextcloud/initial-state'
import { ncMajor } from '../ncVersion.js'

// "All my team folders": the filter then goes without a team folder, and the
// server searches all of them.
const ALL_FOLDERS = Object.freeze({ id: 'all', all: true })

/**
 * Labels as shown: across team folders two fields can share one, so those get
 * their name beside it.
 */
function withDisplayLabels(fields) {
	const count = {}
	for (const f of fields) {
		count[f.field_label] = (count[f.field_label] ?? 0) + 1
	}
	return fields.map((f) => ({ ...f, display_label: count[f.field_label] > 1 ? `${f.field_label} (${f.field_name})` : f.field_label }))
}
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
			saving: false,
			saveError: '',
			// The folders in Files exist from Nextcloud 33 on, when the admin
			// turned them on; that comes with the page.
			canSaveAsFolder: ncMajor() >= 33 && loadState('metavox', 'search-folders', false) === true,
		}
	},
	computed: {
		// "All my team folders" first, when there is more than one.
		folderOptions() {
			if (this.folders.length < 2) {
				return this.folders
			}
			return [{ ...ALL_FOLDERS, mount_point: this.t('metavox', 'All my Team folders') }, ...this.folders]
		},
		allFolders() {
			return this.selectedFolder?.all === true
		},
		// What the Team folder dropdown shows when it has no entries.
		folderNoOptions() {
			return this.loadingFolders
				// TRANSLATORS Shown in a dropdown while its entries load
				? this.t('metavox', 'Loading …')
				: this.t('metavox', 'You are not a member of a Team folder.')
		},
		// What the value dropdown shows when it has no entries.
		valueNoOptions() {
			return this.loadingValues
				// TRANSLATORS Shown in a dropdown while its entries load
				? this.t('metavox', 'Loading …')
				: this.t('metavox', 'No matching values in this folder')
		},
		isMultiselect() {
			const type = this.selectedField?.field_type
			return type === 'multiselect' || type === 'multi_select'
		},
		// Fields whose value step is driven by the values occurring in the
		// folder. Includes select/multiselect so we only offer options that are
		// actually used (never a defined-but-unused option → empty results).
		// date/checkbox/user/file keep their specialised DynamicFieldInput.
		useValueSuggestions() {
			// Across all team folders the values in use would mean reading every
			// folder: choice fields offer their defined options instead, the
			// others are typed.
			if (this.allFolders) {
				return false
			}
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
			// A multi-select's own input gives its options joined with ';#'.
			return this.normalizedValue.split(';#').join(', ')
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
				// The team folders you are a member of, as the Files app lists them.
				const res = await axios.get(generateUrl('/apps/metavox/api/groupfolders'), { params: { scope: 'member' } })
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
			if (!this.selectedFolder || !this.selectedField || this.allFolders) {
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
			const folder = this.selectedFolder
			if (!folder) {
				this.loadingFields = false
				return
			}
			this.loadingFields = true
			let fields = []
			try {
				// The folder's file fields, whole, in one request; for all team
				// folders the fields of the ones the user is in.
				const res = folder.all
					? await axios.get(generateUrl('/apps/metavox/api/user/groupfolder-fields'), { params: { scope: 'member' } })
					: await axios.get(generateUrl(`/apps/metavox/api/groupfolders/${folder.id}/file-fields`))
				fields = (res.data || [])
					.filter((f) => Number(f.applies_to_groupfolder ?? 0) === 0)
					// File-link values are opaque "<fileid>:<path>" tokens — not
					// meaningfully filterable by value, so don't offer them.
					.filter((f) => !UNFILTERABLE_TYPES.includes(f.field_type))
			} catch (e) {
				fields = []
			}
			// Another folder was picked meanwhile: its answer counts, not this one.
			if (this.selectedFolder !== folder) {
				return
			}
			this.fields = withDisplayLabels(fields)
			this.loadingFields = false
		},
		apply() {
			if (!this.canApply) {
				return
			}
			this.$emit('select', {
				field: this.selectedField.field_name,
				value: this.normalizedValue,
				// Readable label for the chip: "Field label: value(s)".
				// TRANSLATORS Text of a search filter chip, e.g. "Status: Approved"
				label: t('metavox', '{field}: {value}', { field: this.selectedField.display_label ?? this.selectedField.field_label, value: this.displayValue }, undefined, PLAIN_TEXT),
				groupfolderId: this.groupfolderId(),
			})
			this.show = false
		},
		// Saves the filter as a folder of the Files app and opens it there.
		async saveAsFolder() {
			if (!this.canApply || this.saving) {
				return
			}
			this.saving = true
			this.saveError = ''
			try {
				const { data } = await axios.post(generateUrl('/apps/metavox/api/user/metadata-searches'), {
					// By characters, as the server counts: never half an emoji.
					name: Array.from(`${this.selectedField.display_label ?? this.selectedField.field_label}: ${this.displayValue}`).slice(0, 100).join(''),
					field: this.selectedField.field_name,
					value: this.normalizedValue,
					groupfolderId: this.groupfolderId(),
				})
				window.location.assign(generateUrl('/apps/files/metavox-searches') + '?dir=' + encodeURIComponent(`/${data.id}`))
			} catch (e) {
				const fields = e.response?.data?.fields ?? {}
				this.saveError = fields.searches ?? fields.value ?? fields.name ?? this.t('metavox', 'The search could not be saved.')
				this.saving = false
			}
		},
		// None for all team folders: the server then searches every one.
		groupfolderId() {
			return this.allFolders ? null : this.selectedFolder.id
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
.picker-field__error {
	color: var(--color-error-text);
}
.picker-field__hint {
	margin-top: 6px;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}
.picker-save {
	margin-top: 28px;
}
.picker-actions {
	display: flex;
	flex-wrap: wrap;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 28px;
}
.picker-save + .picker-actions {
	margin-top: 12px;
}
</style>
