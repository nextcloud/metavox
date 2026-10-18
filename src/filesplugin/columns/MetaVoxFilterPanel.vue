<template>
	<div class="metavox-filter-panel">
		<!-- NcEmptyContent rather than a bare line of grey text: the previous
		     message named what was missing without saying what to do about it,
		     and the fix is a checkbox in the view editor. -->
		<NcEmptyContent v-if="!filter || configs.length === 0"
			class="mv-filter-empty"
			:name="t('metavox', 'No filters in this view')"
			:description="t('metavox', 'Mark a field as filterable in the view editor to filter on it here.')">
			<template #icon>
				<FilterVariantIcon :size="20" />
			</template>
		</NcEmptyContent>
		<template v-else>
			<div v-for="config in configs" :key="config.field_name"
				class="filter-section">
				<button class="filter-summary"
					type="button"
					@click="toggleSection(config.field_name)">
					<span class="summary-text">{{ config.field_label }}</span>
					<!-- A mark, not a count. The number read as "this many files
					     match", which is what a number beside a filter name means
					     almost everywhere; Nextcloud's own filter button signals an
					     active filter with :pressed, never a tally. The section
					     still needs the signal, because a section the user closed
					     by hand stays closed even while it holds active values. -->
					<span v-if="getActiveCount(config.field_name) > 0"
						class="summary-dot"
						role="img"
						:aria-label="n('metavox', '%n filter active', '%n filters active', getActiveCount(config.field_name))" /> <!-- TRANSLATORS Accessible name of the dot next to a field in the filter panel; %n is the number of values selected for that field -->
					<ChevronRight :size="16" class="summary-chevron"
						:class="{ open: isSectionOpen(config.field_name) }" />
				</button>
				<div v-if="isSectionOpen(config.field_name)" class="options">
					<!-- A long list gets a search; it searches all values, not only the listed ones. -->
					<NcTextField v-if="(optionsMap[config.field_name] || []).length > SEARCH_FROM"
						class="options-search"
						:model-value="queries[config.field_name] || ''"
						:label="t('metavox', 'Search values')"
						:show-trailing-button="!!queries[config.field_name]"
						trailing-button-icon="close"
						:trailing-button-label="t('metavox', 'Clear search')"
						@trailing-button-click="setQuery(config.field_name, '')"
						@update:model-value="setQuery(config.field_name, $event)" />
					<NcCheckboxRadioSwitch v-for="opt in listedMap[config.field_name].listed"
						:key="opt.value"
						:model-value="isActive(config.field_name, opt.value)"
						type="checkbox"
						@update:model-value="toggleValue(config.field_name, opt.value)">
						{{ opt.label }}
					</NcCheckboxRadioSwitch>
					<p v-if="listNote(config.field_name)" class="options-note">
						{{ listNote(config.field_name) }}
					</p>
				</div>
			</div>
		</template>
	</div>
</template>

<script setup>
import { ref, shallowRef, computed, watch, onBeforeUnmount, defineAsyncComponent } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import FilterVariantIcon from 'vue-material-design-icons/FilterVariant.vue'
// Shared with the filter chips, so a value cannot read one way in the
// dropdown and another way in the chip it produces.
import { folderCutOffValues, folderFilterValues, optionLabel } from './FilterOptions.js'
import { onFilelinkNames } from './FilelinkNames.js'
import { LISTED_OPTIONS, SEARCH_FROM, listedOptions, searchKey } from './FilterSortCore.js'
import { PLAIN_TEXT } from '../../utils/fieldValue.js'
import { loadPart } from '../../utils/updatedPage.js'

// Only a long list has a search field: load it then, not with the Files page.
const NcTextField = defineAsyncComponent(() => loadPart(() => import('@nextcloud/vue/components/NcTextField')))

const props = defineProps({
	filter: {
		type: Object,
		default: null,
	},
})

// Replaced whole, never changed in place: thousands of options need no deep reactivity.
const optionsMap = shallowRef({})
const activeState = ref({})
const openSections = ref({})
const queries = ref({})

const configs = computed(() => {
	if (!props.filter) return []
	return props.filter.getFilterableConfigs()
})

let loaded = { values: {}, cutOff: {} }

async function loadAllOptions() {
	// The values of the folder you are in, from the listing: no server round trip.
	const values = await folderFilterValues(configs.value)
	loaded = { values, cutOff: folderCutOffValues(configs.value) }
	buildOptions()
}

function buildOptions() {
	const map = {}
	for (const config of configs.value) {
		const options = (loaded.values[config.field_name] || []).map(val => ({
			value: val,
			// A cut-off value ends in "…", like in the grid.
			label: optionLabel(val, config.field_type, config) + (loaded.cutOff[config.field_name]?.has(val) ? '\u2026' : ''),
		}))
		for (const option of options) option.key = searchKey(option.label)
		// Linked files by name, not by id.
		if (config.field_type === 'filelink') options.sort((a, b) => a.label.localeCompare(b.label))
		map[config.field_name] = options
	}
	optionsMap.value = map
}

// Names of linked files come in after the options: label them again.
onBeforeUnmount(onFilelinkNames(() => buildOptions()))

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

// Per section what it lists: thousands of switches froze the page.
const listedMap = computed(() => {
	const map = {}
	for (const config of configs.value) {
		const field = config.field_name
		map[field] = listedOptions(optionsMap.value[field] || [], queries.value[field] || '', (value) => isActive(field, value))
	}
	return map
})

function setQuery(fieldName, query) {
	queries.value = { ...queries.value, [fieldName]: String(query ?? '') }
}

function listNote(fieldName) {
	const { matching } = listedMap.value[fieldName] || { matching: 0 }
	const searching = (queries.value[fieldName] || '').trim() !== ''
	if (searching && matching === 0) {
		return t('metavox', 'No matching values in this folder')
	}
	if (matching <= LISTED_OPTIONS) {
		return ''
	}
	if (searching) {
		// TRANSLATORS Below a filter's values while searching them; {shown} is how many are listed (100), %n how many match
		return n('metavox', 'Showing {shown} of %n match. Type more to narrow it down.', 'Showing {shown} of %n matches. Type more to narrow them down.', matching, { shown: LISTED_OPTIONS }, PLAIN_TEXT)
	}
	// TRANSLATORS Below a filter's values; {shown} is how many are listed (100), %n how many this folder has
	return n('metavox', 'Showing {shown} of %n value. Search to find the rest.', 'Showing {shown} of %n values. Search to find the rest.', matching, { shown: LISTED_OPTIONS }, PLAIN_TEXT)
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
	padding: 4px;
}

/* No rule between sections: Nextcloud reserves separators for section
   boundaries, and every field here is a peer of the next. Spacing does the
   grouping instead. */
.filter-summary {
	display: flex;
	align-items: center;
	justify-content: space-between;
	width: 100%;
	/* Height from the token, and the row's own padding rather than core's
	   7px 14px. */
	min-height: var(--default-clickable-area);
	margin: 0;
	padding: 0 10px;
	border: 0;
	border-radius: var(--border-radius-element);
	/* Core styles bare buttons and sets font-weight: bold, a primary-light
	   background, a :focus tint that sticks after a mouse click, and its own
	   padding. Restating one of those leaves the rest, so all of them are
	   restated here. */
	background-color: transparent;
	box-shadow: none;
	color: var(--color-main-text);
	font-size: var(--default-font-size);
	font-weight: var(--font-weight-element);
	cursor: pointer;
	user-select: none;
	text-align: start;
}
.filter-summary:hover {
	background-color: var(--color-background-hover);
}
/* Core tints :focus, not :focus-visible, so the tint survives a mouse click
   and the row reads as selected when it is not. */
.filter-summary:focus:not(:focus-visible) {
	background-color: transparent;
}
.filter-summary:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: -2px;
}

.summary-text {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.summary-dot {
	inline-size: 8px;
	block-size: 8px;
	border-radius: 50%;
	background-color: var(--color-primary-element);
	flex-shrink: 0;
	margin-inline-end: 6px;
}

.summary-chevron {
	transition: transform var(--animation-quick);
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}
.summary-chevron.open {
	transform: rotate(90deg);
}

.options {
	padding: 2px 8px 6px;
}

.options-search {
	margin-block: 4px 6px;
}

.options-note {
	margin: 4px 4px 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small);
}

/* A compact NcCheckboxRadioSwitch for the dropdown. */
.options :deep(.checkbox-content) {
	min-height: 32px;
	padding-block: 4px;
}
/* Restore icon alignment: drop the auto margin-bottom that pulls it up. */
.options :deep(.checkbox-content__icon) {
	margin-block: auto !important;
}

/* NcEmptyContent sizes itself for a full page; inside a dropdown it needs
   reining in. */
.mv-filter-empty {
	margin: 0;
	padding: 12px 8px;
}
.mv-filter-empty :deep(.empty-content__name) {
	font-size: var(--default-font-size);
	margin-top: 4px;
}
.mv-filter-empty :deep(.empty-content__description) {
	font-size: var(--font-size-small);
	max-width: 26ch;
}

@media (prefers-reduced-motion: reduce) {
	.summary-chevron {
		transition: none;
	}
}
</style>
