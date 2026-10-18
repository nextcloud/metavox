<template>
	<div class="fields-config-content">
		<div v-if="allFields.length === 0" class="no-fields-available">
			<p>{{ emptyText }}</p>
		</div>

		<div v-else class="fields-config-form">
			<div class="field-search-section">
				<!-- TRANSLATORS Label of the search box that filters the field list -->
				<NcTextField :label="t('metavox', 'Search fields')"
					:model-value="search"
					:show-trailing-button="!!search"
					@update:model-value="search = $event"
					@trailing-button-click="search = ''">
					<template #icon>
						<MagnifyIcon :size="16" />
					</template>
				</NcTextField>

				<!-- NcRadioGroup rather than bare buttons under a hand-written
				     role="radiogroup": that declared the pattern without
				     implementing it — three tab stops where a radio group is
				     one, and dead arrow keys. -->
				<!-- TRANSLATORS Label of the filter that chooses which fields are listed: all, Team folder fields or file fields -->
				<NcRadioGroup :label="t('metavox', 'Show')"
					v-model="typeFilter"
					class="field-type-filter">
					<NcRadioGroupButton v-for="option in typeOptions"
						:key="option.value"
						:value="option.value"
						:label="option.label">
						<template #icon>
							<component :is="option.icon" :size="20" />
						</template>
					</NcRadioGroupButton>
				</NcRadioGroup>
			</div>

			<div v-if="visibleFolderFields.length > 0" class="field-section">
				<!-- TRANSLATORS Heading above the fields that describe the Team folder itself -->
				<h5>{{ t('metavox', 'Team folder metadata fields') }}</h5>
				<p class="section-description">{{ t('metavox', 'These fields apply to the Team folder itself') }}</p>
				<div class="checkbox-group">
					<NcCheckboxRadioSwitch v-for="field in visibleFolderFields"
						:key="`gf-${field.id}`"
						:model-value="isAssigned(field.id)"
						type="checkbox"
						@update:model-value="setAssigned(field.id, $event)">
						{{ field.field_label }}
						<span class="field-type-label">({{ fieldTypeLabel(field.field_type) }})</span>
					</NcCheckboxRadioSwitch>
				</div>
			</div>

			<div v-if="visibleFileFields.length > 0" class="field-section">
				<!-- TRANSLATORS Heading above the fields that can be set on files in the Team folder -->
				<h5>{{ t('metavox', 'File metadata fields') }}</h5>
				<p class="section-description">{{ t('metavox', 'These fields can be set on individual files') }}</p>
				<div class="checkbox-group">
					<div v-for="field in visibleFileFields"
						:key="`file-${field.id}`"
						class="file-field-row">
						<NcCheckboxRadioSwitch :model-value="isAssigned(field.id)"
							type="checkbox"
							@update:model-value="setAssigned(field.id, $event)">
							{{ field.field_label }}
							<span class="field-type-label">({{ fieldTypeLabel(field.field_type) }})</span>
						</NcCheckboxRadioSwitch>

						<DefaultValueInput v-if="isAssigned(field.id)"
							:field="field"
							:input-id="`default-${folderId}-${field.id}`"
							:model-value="defaults[field.id] ?? ''"
							:resolved="defaultFiles[field.id] || []"
							@update:model-value="$emit('update:default', field, $event)" />
					</div>
				</div>

				<!-- Only the admin can rewrite values on existing files, so the
				     whole block is opt-in rather than disabled-but-visible. -->
				<div v-if="canApplyDefaults" class="apply-defaults-section">
					<p class="section-description">{{ t('metavox', 'Apply the configured default values to existing files in this Team folder that do not yet have a value for these fields.') }}</p>
					<div class="apply-defaults-actions">
						<NcButton variant="secondary"
							:disabled="applyingDefaults"
							@click="$emit('apply-defaults')">
							<template #icon>
								<NcLoadingIcon v-if="applyingDefaults" :size="20" />
								<PlayIcon v-else :size="20" />
							</template>
							<!-- TRANSLATORS Button that fills in the default values on existing files of the Team folder -->
							{{ t('metavox', 'Apply defaults now') }}
						</NcButton>
						<span class="apply-defaults-status">
							{{ applyDefaultsStatus }}
						</span>
					</div>
				</div>
			</div>

			<div v-if="visibleFolderFields.length === 0 && visibleFileFields.length === 0" class="no-search-results">
				<p v-if="search">
					<!-- TRANSLATORS {query} is the text typed in the search box -->
					{{ t('metavox', 'No fields found matching "{query}"', { query: search }, undefined, PLAIN_TEXT) }}
				</p>
				<p v-else-if="typeFilter !== 'all'">
					{{ t('metavox', 'No fields match the selected filter') }}
				</p>
				<p v-else>
					<!-- TRANSLATORS Shown in place of the field list when there are no fields to choose from -->
					{{ t('metavox', 'No fields available') }}
				</p>
			</div>
		</div>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcRadioGroup from '@nextcloud/vue/components/NcRadioGroup'
import NcRadioGroupButton from '@nextcloud/vue/components/NcRadioGroupButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'
import FormatListBulletedIcon from 'vue-material-design-icons/FormatListBulleted.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import PlayIcon from 'vue-material-design-icons/Play.vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import { PLAIN_TEXT } from '../utils/fieldValue.js'
import DefaultValueInput from './fields/DefaultValueInput.vue'
import { fieldTypeLabel } from '../utils/fieldTypes.js'

/**
 * Choosing which metadata fields a team folder offers.
 *
 * Extracted from ManageGroupfolders (admin) and MetaVoxPersonal, which
 * carried the same panel twice: 74% of the lines identical, and the real
 * differences were two comments and one string. That is the shape that
 * produced the `split(';#'.filter(...))` bug — the correct line existing in
 * one twin and not the other — so it is one component with props now.
 *
 * Scoped to a single folder rather than taking the caller's id-keyed maps.
 * Both hosts held `fieldSearchQuery[folderId]`, `fieldTypeFilter[folderId]`
 * and so on because the panel used to be rendered once per row inline; a
 * dialog shows one folder at a time, so the search text and the type filter
 * are this component's own state and die with it.
 *
 * The assignment itself stays with the caller — it is what gets saved, and
 * the caller owns the save.
 */
export default {
	name: 'FieldConfigurationPanel',

	// Templates read the t() options from the component.
	setup() {
		return { PLAIN_TEXT }
	},

	components: {
		AccountGroupIcon,
		DefaultValueInput,
		FileDocumentOutlineIcon,
		FormatListBulletedIcon,
		MagnifyIcon,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcRadioGroup,
		NcRadioGroupButton,
		NcTextField,
		PlayIcon,
	},

	props: {
		/** The folder being configured; used only to key input ids. */
		folderId: {
			type: [Number, String],
			required: true,
		},
		/** Field definitions that describe the folder itself. */
		folderFields: {
			type: Array,
			required: true,
		},
		/** Field definitions that describe files inside the folder. */
		fileFields: {
			type: Array,
			required: true,
		},
		/** Ids currently ticked. The caller owns this and saves it. */
		assignedIds: {
			type: Array,
			required: true,
		},
		/** fieldId -> default value, for the assigned file fields. */
		defaults: {
			type: Object,
			default: () => ({}),
		},
		/** fieldId -> the linked files of a file link default, as the user sees them. */
		defaultFiles: {
			type: Object,
			default: () => ({}),
		},
		/**
		 * Shown when there are no fields at all. It differs by audience: an
		 * admin is told to create some, a user to ask an admin.
		 */
		emptyText: {
			type: String,
			required: true,
		},
		/** Applying defaults to existing files is an admin action. */
		canApplyDefaults: {
			type: Boolean,
			default: false,
		},
		applyingDefaults: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:assignedIds', 'update:default', 'apply-defaults'],

	data() {
		return {
			search: '',
			typeFilter: 'all',
		}
	},

	computed: {
		allFields() {
			return [...this.folderFields, ...this.fileFields]
		},

		/**
		 * §7: every option in a choice row carries a 20px icon, none
		 * repeated. The two type icons are deliberately the same glyphs the
		 * pane switcher uses for the same content — a team folder field and
		 * a file field are the same two things in both rows.
		 */
		typeOptions() {
			return [
				// TRANSLATORS Filter option that lists every field; keep it short
				{ value: 'all', label: this.t('metavox', 'All fields'), icon: 'FormatListBulletedIcon' },
				{
					value: 'groupfolder',
					// TRANSLATORS Filter option; %n is the number of fields that describe the Team folder itself; keep it short
					label: this.n('metavox', '%n Team folder field', '%n Team folder fields', this.folderFields.length),
					icon: 'AccountGroupIcon',
				},
				{
					value: 'file',
					// TRANSLATORS Filter option; %n is the number of fields that can be set on files in the Team folder; keep it short
					label: this.n('metavox', '%n file field', '%n file fields', this.fileFields.length),
					icon: 'FileDocumentOutlineIcon',
				},
			]
		},

		applyDefaultsStatus() {
			if (this.applyingDefaults) {
				// TRANSLATORS Status beside the "Apply defaults now" button while default values are being written to files
				return this.t('metavox', 'Applying defaults …')
			}
			// TRANSLATORS Status beside the "Apply defaults now" button when nothing is running
			return this.t('metavox', 'Idle')
		},

		visibleFolderFields() {
			return this.typeFilter === 'file' ? [] : this.matching(this.folderFields)
		},

		visibleFileFields() {
			return this.typeFilter === 'groupfolder' ? [] : this.matching(this.fileFields)
		},
	},

	methods: {
		fieldTypeLabel,
		t: translate,
		n: translatePlural,

		/** Label, internal name and type all match, as in the fields list. */
		matching(fields) {
			const query = this.search.toLowerCase().trim()
			if (!query) {
				return fields
			}
			return fields.filter(field => {
				const haystack = `${field.field_label || ''} ${field.field_name || ''} ${field.field_type || ''}`
				return haystack.toLowerCase().includes(query)
			})
		},

		isAssigned(fieldId) {
			return this.assignedIds.includes(fieldId)
		},

		/** Never mutates the prop: emits the new list for the caller to hold. */
		setAssigned(fieldId, checked) {
			const next = this.assignedIds.filter(id => id !== fieldId)
			if (checked) {
				next.push(fieldId)
			}
			this.$emit('update:assignedIds', next)
		},
	},
}
</script>

<style scoped>
.field-search-section {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 24px;
}

.field-section {
	margin-bottom: 24px;
}

.field-section h5 {
	margin: 0 0 4px;
	font-size: var(--default-font-size);
	font-weight: 600;
	color: var(--color-main-text);
}

.section-description {
	margin: 0 0 8px;
	font-size: var(--font-size-small);
	color: var(--color-text-maxcontrast);
}

.checkbox-group {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.file-field-row {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.field-type-label {
	color: var(--color-text-maxcontrast);
}

.apply-defaults-section {
	margin-top: 16px;
}

.apply-defaults-actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.apply-defaults-status {
	font-size: var(--font-size-small);
	color: var(--color-text-maxcontrast);
}

.no-fields-available,
.no-search-results {
	color: var(--color-text-maxcontrast);
}

.no-fields-available p,
.no-search-results p {
	margin: 0;
}
</style>
