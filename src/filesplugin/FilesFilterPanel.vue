<template>
	<div class="metavox-filter-panel">
		<!-- Filter Header -->
		<div class="filter-header">
			<div class="filter-header-left">
				<h3>{{ t('metavox', 'Filter by Metadata') }}</h3>
				<NcButton
					type="tertiary-no-background"
					:aria-label="t('metavox', 'Close filter panel')"
					@click="closePanel">
					<template #icon>
						<CloseIcon :size="20" />
					</template>
				</NcButton>
			</div>
			<div class="filter-actions">
				<NcButton
					v-if="hasActiveFilters"
					type="tertiary"
					@click="clearAllFilters">
					<template #icon>
						<CloseIcon :size="20" />
					</template>
					{{ t('metavox', 'Clear all') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="!hasFilterValues"
					@click="applyFilters">
					<template #icon>
						<FilterIcon :size="20" />
					</template>
					{{ t('metavox', 'Apply') }}
				</NcButton>
			</div>
		</div>

		<!-- Loading State -->
		<div v-if="loading" class="loading-state">
			<div class="icon-loading" />
			<p>{{ t('metavox', 'Loading filters...') }}</p>
		</div>

		<!-- No Groupfolder -->
		<div v-else-if="!groupfolderId" class="info-message">
			<p>{{ t('metavox', 'Filtering is only available for team folder files.') }}</p>
		</div>

		<!-- Filter Fields -->
		<div v-else-if="availableFields.length > 0" class="filter-fields">
			<div
				v-for="field in availableFields"
				:key="field.field_name"
				class="filter-field">
				<div class="field-header">
					<label class="field-label">{{ field.field_label }}</label>
					<NcButton
						v-if="activeFilters[field.field_name]"
						type="tertiary-no-background"
						@click="removeFilter(field.field_name)">
						<template #icon>
							<CloseIcon :size="16" />
						</template>
					</NcButton>
				</div>

				<!-- Text Field Filter -->
				<div v-if="field.field_type === 'text' || field.field_type === 'textarea'" class="filter-controls">
					<NcSelect
						v-model="filterOperators[field.field_name]"
						:options="textOperators"
						:placeholder="t('metavox', 'Select operator')"
						:reduce="option => option"
						label="label"
						class="filter-operator" />
					<NcTextField
						:value="filterValues[field.field_name] || ''"
						:placeholder="t('metavox', 'Enter value...')"
						class="filter-value"
						@update:value="updateFilterValue(field.field_name, $event)"
						@keyup.enter="applyFilters" />
				</div>

				<!-- Number Field Filter -->
				<div v-if="field.field_type === 'number'" class="filter-controls">
					<NcSelect
						v-model="filterOperators[field.field_name]"
						:options="numberOperators"
						:placeholder="t('metavox', 'Select operator')"
						:reduce="option => option"
						label="label"
						class="filter-operator" />
					<NcTextField
						:value="filterValues[field.field_name] || ''"
						type="number"
						:placeholder="t('metavox', 'Enter value...')"
						class="filter-value"
						@update:value="updateFilterValue(field.field_name, $event)"
						@keyup.enter="applyFilters" />
				</div>

				<!-- Date Field Filter -->
				<div v-if="field.field_type === 'date'" class="filter-controls">
					<NcSelect
						v-model="filterOperators[field.field_name]"
						:options="dateOperators"
						:placeholder="t('metavox', 'Select operator')"
						:reduce="option => option"
						label="label"
						class="filter-operator" />
					<NcDatetimePicker
						v-if="filterOperators[field.field_name]?.value !== 'between'"
						v-model="filterValues[field.field_name]"
						type="date"
						:placeholder="t('metavox', 'Select date...')"
						class="filter-value" />
					<div v-else class="date-range">
						<NcDatetimePicker
							v-model="filterRanges[field.field_name].start"
							type="date"
							:placeholder="t('metavox', 'Start date...')"
							class="filter-value" />
						<span class="range-separator">-</span>
						<NcDatetimePicker
							v-model="filterRanges[field.field_name].end"
							type="date"
							:placeholder="t('metavox', 'End date...')"
							class="filter-value" />
					</div>
				</div>

				<!-- Checkbox Field Filter -->
				<div v-if="field.field_type === 'checkbox'" class="filter-controls">
					<NcCheckboxRadioSwitch
						:checked.sync="filterValues[field.field_name]"
						type="switch">
						{{ filterValues[field.field_name] ? t('metavox', 'Checked') : t('metavox', 'Unchecked') }}
					</NcCheckboxRadioSwitch>
				</div>

				<!-- Select Field Filter -->
				<div v-if="field.field_type === 'select'" class="filter-controls">
					<NcSelect
						v-model="filterValues[field.field_name]"
						:options="getSelectOptions(field.field_options)"
						:placeholder="t('metavox', 'Select value...')"
						:reduce="option => option.value"
						label="label"
						class="filter-value" />
				</div>

				<!-- MultiSelect Field Filter -->
				<div v-if="field.field_type === 'multiselect'" class="filter-controls">
					<NcSelect
						v-model="filterValues[field.field_name]"
						:options="getSelectOptions(field.field_options)"
						:placeholder="t('metavox', 'Select values...')"
						:multiple="true"
						:reduce="option => option.value"
						label="label"
						class="filter-value" />
				</div>
			</div>
		</div>

		<!-- No Fields -->
		<div v-else class="info-message">
			<p>{{ t('metavox', 'No metadata fields available for filtering.') }}</p>
		</div>
	</div>
</template>

<script>
import { NcButton, NcSelect, NcTextField, NcCheckboxRadioSwitch, NcDatetimePicker } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import FilterIcon from 'vue-material-design-icons/Filter.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'

export default {
	name: 'FilesFilterPanel',

	components: {
		NcButton,
		NcSelect,
		NcTextField,
		NcCheckboxRadioSwitch,
		NcDatetimePicker,
		FilterIcon,
		CloseIcon,
	},

	props: {
		groupfolderId: {
			type: Number,
			default: null,
		},
		currentPath: {
			type: String,
			default: '/',
		},
	},

	data() {
		return {
			loading: false,
			availableFields: [],
			filterOperators: {},
			filterValues: {},
			filterRanges: {},
			activeFilters: {},

			// Operator options
			textOperators: [
				{ value: 'contains', label: this.t('metavox', 'Contains') },
				{ value: 'not_contains', label: this.t('metavox', 'Does not contain') },
				{ value: 'equals', label: this.t('metavox', 'Equals') },
				{ value: 'not_equals', label: this.t('metavox', 'Does not equal') },
				{ value: 'starts_with', label: this.t('metavox', 'Starts with') },
				{ value: 'ends_with', label: this.t('metavox', 'Ends with') },
				{ value: 'is_empty', label: this.t('metavox', 'Is empty') },
				{ value: 'is_not_empty', label: this.t('metavox', 'Is not empty') },
			],

			numberOperators: [
				{ value: 'equals', label: this.t('metavox', 'Equals') },
				{ value: 'not_equals', label: this.t('metavox', 'Does not equal') },
				{ value: 'greater_than', label: this.t('metavox', 'Greater than') },
				{ value: 'less_than', label: this.t('metavox', 'Less than') },
				{ value: 'greater_or_equal', label: this.t('metavox', 'Greater or equal') },
				{ value: 'less_or_equal', label: this.t('metavox', 'Less or equal') },
				{ value: 'is_empty', label: this.t('metavox', 'Is empty') },
				{ value: 'is_not_empty', label: this.t('metavox', 'Is not empty') },
			],

			dateOperators: [
				{ value: 'equals', label: this.t('metavox', 'On') },
				{ value: 'greater_than', label: this.t('metavox', 'After') },
				{ value: 'less_than', label: this.t('metavox', 'Before') },
				{ value: 'between', label: this.t('metavox', 'Between') },
				{ value: 'is_empty', label: this.t('metavox', 'Is empty') },
				{ value: 'is_not_empty', label: this.t('metavox', 'Is not empty') },
			],
		}
	},

	computed: {
		hasActiveFilters() {
			return Object.keys(this.activeFilters).length > 0
		},

		hasFilterValues() {
			// Check if any filter values are filled in
			return this.availableFields.some(field => {
				const value = this.filterValues[field.field_name]
				if (field.field_type === 'checkbox') {
					return true // Checkboxes always have a value
				}
				if (Array.isArray(value)) {
					return value.length > 0
				}
				return value !== undefined && value !== null && value !== ''
			})
		},
	},

	watch: {
		groupfolderId: {
			immediate: true,
			handler(newId) {
				if (newId) {
					this.loadFilterFields()
				}
			},
		},
	},

	methods: {
		t,

		updateFilterValue(fieldName, value) {
			this.$set(this.filterValues, fieldName, value)
		},

		getSelectOptions(options) {
			console.log('🔍 getSelectOptions called with:', options)
			if (!options) {
				console.log('⚠️ No options provided')
				return []
			}

			// Handle string format (newline-separated, like in MetadataForm)
			if (typeof options === 'string') {
				const stringOptions = options.split('\n').filter(opt => opt.trim() !== '')
				console.log('📝 Parsed string options:', stringOptions)
				return stringOptions.map(opt => ({ label: opt.trim(), value: opt.trim() }))
			}

			if (Array.isArray(options)) {
				// Convert string array to objects for NcSelect
				const converted = options.map(opt => {
					if (typeof opt === 'string') {
						return { label: opt, value: opt }
					}
					return opt
				})
				console.log('✅ Converted array options:', converted)
				return converted
			}
			console.log('⚠️ Options not array or string')
			return []
		},

		async loadFilterFields() {
			if (!this.groupfolderId) {
				return
			}

			this.loading = true

			try {
				const response = await axios.get(
					generateUrl('/apps/metavox/api/groupfolders/{groupfolderId}/filter-fields', {
						groupfolderId: this.groupfolderId,
					}),
				)

				this.availableFields = response.data.fields || []
				console.log('🔍 Loaded filter fields:', this.availableFields)

				// Initialize filter operators and values
				this.availableFields.forEach(field => {
					console.log(`🔍 Field ${field.field_name}:`, {
						type: field.field_type,
						options: field.field_options,
					})
					// Set default operator
					if (field.field_type === 'text' || field.field_type === 'textarea') {
						this.$set(this.filterOperators, field.field_name, this.textOperators[0])
					} else if (field.field_type === 'number') {
						this.$set(this.filterOperators, field.field_name, this.numberOperators[0])
					} else if (field.field_type === 'date') {
						this.$set(this.filterOperators, field.field_name, this.dateOperators[0])
						this.$set(this.filterRanges, field.field_name, { start: null, end: null })
					} else if (field.field_type === 'checkbox') {
						this.$set(this.filterValues, field.field_name, false)
					}
				})
			} catch (error) {
				console.error('Error loading filter fields:', error)
				showError(this.t('metavox', 'Failed to load filter fields'))
			} finally {
				this.loading = false
			}
		},

		applyFilters() {
			const filters = []

			// Build filters array from current values
			this.availableFields.forEach(field => {
				const fieldName = field.field_name
				const operator = this.filterOperators[fieldName]
				const value = this.filterValues[fieldName]

				// For checkboxes: only add to filter if explicitly set to true
				// (false/unchecked should not appear as active filter)
				if (field.field_type === 'checkbox') {
					if (value === true) {
						// Only add checked checkboxes to filters
						filters.push({
							field_name: fieldName,
							field_type: field.field_type,
							operator: 'equals',
							value: true,
						})
						this.$set(this.activeFilters, fieldName, {
							operator: 'equals',
							value: true,
						})
					}
					return // Skip further processing for checkboxes
				}

				// Skip if no value set (except for is_empty/is_not_empty operators)
				if (!value && operator?.value !== 'is_empty' && operator?.value !== 'is_not_empty') {
					return
				}

				// Handle different field types
				if (field.field_type === 'date' && operator?.value === 'between') {
					const range = this.filterRanges[fieldName]
					if (range.start && range.end) {
						filters.push({
							field_name: fieldName,
							field_type: field.field_type,
							operator: operator.value,
							value: [this.formatDate(range.start), this.formatDate(range.end)],
						})
						this.$set(this.activeFilters, fieldName, {
							operator: operator.value,
							value: [range.start, range.end],
						})
					}
				} else if (field.field_type === 'multiselect' && Array.isArray(value)) {
					if (value.length > 0) {
						filters.push({
							field_name: fieldName,
							field_type: field.field_type,
							operator: 'one_of',
							value,
						})
						this.$set(this.activeFilters, fieldName, {
							operator: 'one_of',
							value,
						})
					}
				} else if (field.field_type === 'date') {
					filters.push({
						field_name: fieldName,
						field_type: field.field_type,
						operator: operator.value,
						value: this.formatDate(value),
					})
					this.$set(this.activeFilters, fieldName, {
						operator: operator.value,
						value,
					})
				} else {
					filters.push({
						field_name: fieldName,
						field_type: field.field_type,
						operator: operator?.value || 'equals',
						value,
					})
					this.$set(this.activeFilters, fieldName, {
						operator: operator?.value || 'equals',
						value,
					})
				}
			})

			this.$emit('filter-applied', filters)
		},

		removeFilter(fieldName) {
			// Remove from active filters
			this.$delete(this.activeFilters, fieldName)

			// Reset the filter value
			const field = this.availableFields.find(f => f.field_name === fieldName)
			if (field) {
				if (field.field_type === 'checkbox') {
					this.$set(this.filterValues, fieldName, false)
				} else if (field.field_type === 'date') {
					this.$set(this.filterValues, fieldName, null)
					this.$set(this.filterRanges, fieldName, { start: null, end: null })
				} else if (field.field_type === 'multiselect') {
					this.$set(this.filterValues, fieldName, [])
				} else {
					this.$set(this.filterValues, fieldName, '')
				}
			}

			// Re-apply remaining filters
			this.applyFilters()
		},

		clearAllFilters() {
			this.activeFilters = {}
			this.filterValues = {}
			this.filterRanges = {}

			// Reset to defaults
			this.availableFields.forEach(field => {
				if (field.field_type === 'checkbox') {
					this.$set(this.filterValues, field.field_name, false)
				} else if (field.field_type === 'date') {
					this.$set(this.filterRanges, field.field_name, { start: null, end: null })
				}
			})

			this.$emit('filters-cleared')
		},

		closePanel() {
			this.$emit('close-panel')
		},

		getFieldLabel(fieldName) {
			const field = this.availableFields.find(f => f.field_name === fieldName)
			return field?.field_label || fieldName
		},

		formatFilterValue(fieldName, filter) {
			const field = this.availableFields.find(f => f.field_name === fieldName)

			if (field?.field_type === 'checkbox') {
				return filter.value ? this.t('metavox', 'Checked') : this.t('metavox', 'Unchecked')
			}

			if (field?.field_type === 'date' && filter.operator === 'between' && Array.isArray(filter.value)) {
				return `${this.formatDisplayDate(filter.value[0])} - ${this.formatDisplayDate(filter.value[1])}`
			}

			if (field?.field_type === 'date') {
				return this.formatDisplayDate(filter.value)
			}

			if (Array.isArray(filter.value)) {
				return filter.value.join(', ')
			}

			return filter.value
		},

		formatDate(date) {
			if (!date) return ''
			if (date instanceof Date) {
				return date.toISOString().split('T')[0]
			}
			return date
		},

		formatDisplayDate(date) {
			if (!date) return ''
			try {
				const d = new Date(date)
				return d.toLocaleDateString()
			} catch (e) {
				return date
			}
		},
	},
}
</script>

<style scoped>
.metavox-filter-panel {
	background: transparent;
	border-radius: 0;
	padding: 20px;
	margin-bottom: 0;
	width: 360px;
	max-width: 360px;
	box-sizing: border-box;
	height: 100%;
}

.filter-header {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-bottom: 16px;
	padding-bottom: 12px;
	border-bottom: 1px solid var(--color-border);
}

.filter-header-left {
	display: flex;
	align-items: center;
	justify-content: space-between;
	width: 100%;
}

.filter-header h3 {
	margin: 0;
	font-size: 18px;
	font-weight: 700;
	color: var(--color-main-text);
}

.filter-actions {
	display: flex;
	gap: 8px;
	width: 100%;
}

.loading-state,
.info-message {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 24px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.filter-fields {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.filter-field {
	padding: 12px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.field-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 8px;
}

.field-label {
	font-weight: 600;
	font-size: 14px;
	color: var(--color-main-text);
}

.filter-controls {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.filter-operator {
	width: 100%;
}

.filter-value {
	width: 100%;
}

.date-range {
	display: flex;
	align-items: center;
	gap: 8px;
}

.range-separator {
	color: var(--color-text-maxcontrast);
}

/* Fix NcSelect dropdown to display vertically */
:deep(.vs__dropdown-menu) {
	display: block !important;
}

:deep(.vs__dropdown-option) {
	display: block !important;
	width: 100% !important;
}

/* Ensure NcSelect components take full width */
:deep(.v-select) {
	width: 100% !important;
}

:deep(.vs__dropdown-toggle) {
	width: 100% !important;
}

/* Fix NcTextField to take full width */
:deep(.input-field__input) {
	width: 100% !important;
}
</style>
