<template>
	<div class="metavox-filter-panel">
		<div v-if="!filter || configs.length === 0" class="empty-msg">
			{{ t('metavox', 'No filterable fields') }}
		</div>
		<template v-else>
			<details v-for="config in configs"
				:key="config.field_name"
				:open="getActiveCount(config.field_name) > 0 || undefined">
				<summary>
					<span class="summary-text">{{ config.field_label }}</span>
					<span v-if="getActiveCount(config.field_name) > 0"
						class="summary-badge">
						{{ getActiveCount(config.field_name) }}
					</span>
					<ChevronRight :size="16" class="summary-chevron" />
				</summary>
				<div class="options">
					<button v-if="getActiveCount(config.field_name) > 0"
						class="clear-btn"
						@click="clearField(config.field_name)">
						{{ t('metavox', 'Clear selection') }}
					</button>
					<NcCheckboxRadioSwitch v-for="opt in optionsMap[config.field_name]"
						:key="opt.value"
						:model-value="isActive(config.field_name, opt.value)"
						type="checkbox"
						@update:model-value="toggleValue(config.field_name, opt.value)">
						{{ opt.label }}
					</NcCheckboxRadioSwitch>
				</div>
			</details>
			<div class="reset-wrapper">
				<NcButton :wide="true"
					:variant="hasActiveFilters ? 'secondary' : 'tertiary'"
					@click="resetAll">
					{{ t('metavox', 'Clear filters') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { translate } from '@nextcloud/l10n'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import { getPrefetchedFilterValues } from './MetaVoxColumns.js'

function formatOptionLabel(value, fieldType) {
	if (fieldType === 'checkbox' || fieldType === 'boolean') {
		if (value === '1' || value === 'true') return translate('metavox', 'Yes')
		if (value === '0' || value === 'false') return translate('metavox', 'No')
	}
	return value
}

export default {
	name: 'MetaVoxFilterPanel',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		ChevronRight,
	},
	props: {
		filter: {
			type: Object,
			default: null,
		},
	},
	data() {
		return {
			optionsMap: {},
			updateKey: 0,
		}
	},
	computed: {
		configs() {
			if (!this.filter) return []
			return this.filter.getFilterableConfigs()
		},
		hasActiveFilters() {
			// eslint-disable-next-line no-unused-expressions
			this.updateKey
			return this.filter ? this.filter.getActiveFilters().size > 0 : false
		},
	},
	watch: {
		filter: {
			immediate: true,
			handler() {
				if (this.filter) {
					this.loadAllOptions()
				}
			},
		},
	},
	methods: {
		loadAllOptions() {
			// Use prefetched filter values (loaded in parallel with fields/views)
			const allValues = getPrefetchedFilterValues() || {}

			for (const config of this.configs) {
				let values = allValues[config.field_name] || []
				if (config.field_type === 'checkbox' || config.field_type === 'boolean') {
					values = ['1', '0']
				}
				this.optionsMap[config.field_name] = values.map(val => ({
					value: val,
					label: formatOptionLabel(val, config.field_type),
				}))
			}
		},
		getActiveCount(fieldName) {
			// eslint-disable-next-line no-unused-expressions
			this.updateKey
			return this.filter ? this.filter.getFieldActiveCount(fieldName) : 0
		},
		isActive(fieldName, value) {
			// eslint-disable-next-line no-unused-expressions
			this.updateKey
			return this.filter ? this.filter.isFieldValueActive(fieldName, value) : false
		},
		toggleValue(fieldName, value) {
			this.filter.toggleFilterValue(fieldName, value)
			this.updateKey++
		},
		clearField(fieldName) {
			this.filter.clearFieldFilter(fieldName)
			this.updateKey++
		},
		resetAll() {
			this.filter.reset()
			this.updateKey++
		},
	},
}
</script>

<style scoped>
.metavox-filter-panel {
	min-width: 240px;
	max-width: 320px;
	max-height: 70vh;
	overflow-y: auto;
	padding: 4px 0;
}

details {
	border-bottom: 1px solid var(--color-border, #e8e8e8);
}
details:last-of-type {
	border-bottom: none;
}

summary {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 14px;
	cursor: pointer;
	user-select: none;
	font-size: 14px;
	color: var(--color-main-text, #222);
	list-style: none;
	min-height: 44px;
}
summary::-webkit-details-marker {
	display: none;
}
summary:hover {
	background: var(--color-background-hover, #f5f5f5);
}

.summary-text {
	font-weight: 500;
	flex: 1;
}

.summary-badge {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 20px;
	height: 20px;
	padding: 0 5px;
	border-radius: 10px;
	background: var(--color-primary-element, #0082c9);
	color: #fff;
	font-size: 11px;
	font-weight: 600;
	margin-right: 6px;
}

.summary-chevron {
	transition: transform 0.15s;
	color: var(--color-text-maxcontrast, #767676);
	flex-shrink: 0;
}
details[open] .summary-chevron {
	transform: rotate(90deg);
}

.options {
	padding: 2px 8px 6px;
}

/* Compact NcCheckboxRadioSwitch voor filter dropdown */
.options :deep(.checkbox-content) {
	min-height: 32px;
	padding-block: 4px;
}
/* Herstel icoon-uitlijning: verwijder de 'auto' margin-bottom die het icoon omhoog trekt */
.options :deep(.checkbox-content__icon) {
	margin-block: auto !important;
}

.clear-btn {
	display: block;
	width: 100%;
	padding: 4px 6px 6px;
	border: none;
	background: transparent;
	color: var(--color-primary-element, #0082c9);
	font-size: 12px;
	text-align: left;
	cursor: pointer;
}
.clear-btn:hover {
	text-decoration: underline;
}

.reset-wrapper {
	padding: 6px 8px 4px;
}

.empty-msg {
	color: var(--color-text-maxcontrast, #767676);
	font-size: 13px;
	text-align: center;
	padding: 16px;
}
</style>
