<template>
	<div class="metavox-filter-panel">
		<div v-if="!filter || configs.length === 0" class="empty-msg">
			{{ t('metavox', 'No filterable fields') }}
		</div>
		<template v-else>
			<div v-for="config in configs" :key="config.field_name"
				class="filter-section">
				<button class="filter-summary"
					type="button"
					@click="toggleSection(config.field_name)">
					<span class="summary-text">{{ config.field_label }}</span>
					<span v-if="getActiveCount(config.field_name) > 0"
						class="summary-badge">
						{{ getActiveCount(config.field_name) }}
					</span>
					<ChevronRight :size="16" class="summary-chevron"
						:class="{ open: isSectionOpen(config.field_name) }" />
				</button>
				<div v-if="isSectionOpen(config.field_name)" class="options">
					<NcCheckboxRadioSwitch v-for="opt in optionsMap[config.field_name]"
						:key="opt.value"
						:model-value="isActive(config.field_name, opt.value)"
						type="checkbox"
						@update:model-value="toggleValue(config.field_name, opt.value)">
						{{ opt.label }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>
		</template>
	</div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import { getPrefetchedFilterValues } from './MetaVoxColumns.js'

const props = defineProps({
	filter: {
		type: Object,
		default: null,
	},
})

const optionsMap = ref({})
const activeState = ref({})
const openSections = ref({})

const configs = computed(() => {
	if (!props.filter) return []
	return props.filter.getFilterableConfigs()
})

function formatOptionLabel(value, fieldType) {
	if (fieldType === 'checkbox' || fieldType === 'boolean') {
		if (value === '1' || value === 'true') return t('metavox', 'Yes')
		if (value === '0' || value === 'false') return t('metavox', 'No')
	}
	return value
}

function loadAllOptions() {
	const allValues = getPrefetchedFilterValues() || {}
	const map = {}
	for (const config of configs.value) {
		let values = allValues[config.field_name] || []
		if (config.field_type === 'checkbox' || config.field_type === 'boolean') {
			values = ['1', '0']
		}
		map[config.field_name] = values.map(val => ({
			value: val,
			label: formatOptionLabel(val, config.field_type),
		}))
	}
	optionsMap.value = map
}

function syncActiveState() {
	if (!props.filter) return
	const state = {}
	const filters = props.filter.getActiveFilters()
	for (const config of configs.value) {
		const values = filters.get(config.field_name)
		if (values && values.size > 0) {
			state[config.field_name] = {}
			for (const v of values) {
				state[config.field_name][v] = true
			}
			// Auto-open sections that have active filters
			if (openSections.value[config.field_name] === undefined) {
				openSections.value[config.field_name] = true
			}
		}
	}
	activeState.value = state
}

function getActiveCount(fieldName) {
	const s = activeState.value[fieldName]
	return s ? Object.keys(s).length : 0
}

function isActive(fieldName, value) {
	return !!activeState.value[fieldName]?.[value]
}

function isSectionOpen(fieldName) {
	return !!openSections.value[fieldName]
}

function toggleSection(fieldName) {
	openSections.value = {
		...openSections.value,
		[fieldName]: !openSections.value[fieldName],
	}
}

function toggleValue(fieldName, value) {
	props.filter.toggleFilterValue(fieldName, value)
	syncActiveState()
}

watch(() => props.filter, () => {
	if (props.filter) {
		loadAllOptions()
		syncActiveState()
	}
}, { immediate: true })
</script>

<style scoped>
.metavox-filter-panel {
	min-width: 240px;
	max-width: 320px;
	max-height: 70vh;
	overflow-y: auto;
	padding: 4px 0;
}

.filter-section {
	border-bottom: 1px solid var(--color-border, #e8e8e8);
}
.filter-section:last-child {
	border-bottom: none;
}

.filter-summary {
	display: flex;
	align-items: center;
	justify-content: space-between;
	width: 100%;
	padding: 8px 14px;
	border: none;
	background: transparent;
	cursor: pointer;
	user-select: none;
	font-size: 14px;
	color: var(--color-main-text, #222);
	min-height: 44px;
	text-align: left;
}
.filter-summary:hover {
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
.summary-chevron.open {
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

.empty-msg {
	color: var(--color-text-maxcontrast, #767676);
	font-size: 13px;
	text-align: center;
	padding: 16px;
}
</style>
