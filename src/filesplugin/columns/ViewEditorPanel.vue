<template>
	<div class="mv-editor-panel">
		<!-- Header -->
		<div class="mv-editor-header">
			<h3>{{ readonly ? t('metavox', 'View details') : (isNew ? t('metavox', 'New view') : t('metavox', 'Edit view')) }}</h3>
			<NcButton type="tertiary"
				:aria-label="t('metavox', 'Close')"
				@click="emit('close')">
				<template #icon>
					<CloseIcon :size="20" />
				</template>
			</NcButton>
		</div>

		<!-- Body -->
		<div class="mv-editor-body">
			<!-- Name row -->
			<div class="mv-editor-row">
				<label>{{ t('metavox', 'Name') }}</label>
				<NcTextField :model-value="editorState.name"
					:placeholder="t('metavox', 'View name')"
					:disabled="readonly"
					@update:model-value="editorState.name = $event" />
			</div>

			<!-- Default toggle -->
			<div class="mv-editor-row">
				<NcCheckboxRadioSwitch :model-value="editorState.isDefault"
					type="checkbox"
					:disabled="readonly"
					@update:model-value="editorState.isDefault = $event">
					{{ t('metavox', 'Default view') }}
				</NcCheckboxRadioSwitch>
			</div>

			<!-- Columns section -->
			<div class="mv-editor-section-title">{{ t('metavox', 'Columns') }}</div>

			<div class="mv-col-header-row">
				<span class="mv-col-drag" />
				<span class="mv-col-name mv-col-header-label">{{ t('metavox', 'Field') }}</span>
				<span class="mv-col-check mv-col-header-label">{{ t('metavox', 'Visible') }}</span>
				<span class="mv-col-check mv-col-header-label">{{ t('metavox', 'Filterable') }}</span>
			</div>

			<div ref="colList" class="mv-col-list">
				<div v-for="(col, index) in editorState.columns"
					:key="col.field_id"
					class="mv-col-row"
					:draggable="!readonly"
					:data-col-idx="index"
					@dragstart="!readonly && onDragStart($event, index)"
					@dragend="onDragEnd"
					@dragover.prevent
					@drop.prevent="!readonly && onDrop($event, index)">
					<DragVerticalIcon :size="18" class="mv-col-drag" :class="{ 'mv-disabled': readonly }" :title="t('metavox', 'Drag to reorder')" />
					<span class="mv-col-name">{{ col.field_label }}</span>
					<span class="mv-col-check">
						<NcCheckboxRadioSwitch :model-value="col.visible"
							type="checkbox"
							:disabled="readonly"
							@update:model-value="toggleVisible(col, $event)" />
					</span>
					<span class="mv-col-check" :class="{ 'mv-disabled': !col.visible }">
						<NcCheckboxRadioSwitch :model-value="col.filterable"
							type="checkbox"
							:disabled="readonly || !col.visible"
							@update:model-value="toggleFilterable(col, $event)" />
					</span>
				</div>
			</div>

			<!-- Filters section -->
			<div class="mv-editor-section-title">{{ t('metavox', 'Filters (preset values)') }}</div>

			<template v-for="col in filterableColumns" :key="col.field_id">
				<!-- Checkbox type filter -->
				<details v-if="col.field_type === 'checkbox'"
					class="mv-filter-row"
					:open="getFilterCount(col) > 0 || undefined">
					<summary class="mv-filter-summary">
						<span class="mv-filter-summary-text">{{ col.field_label }}</span>
						<span v-if="getFilterCount(col) > 0" class="mv-filter-badge">
							{{ getFilterCount(col) }}
						</span>
						<ChevronRightIcon :size="14" class="mv-filter-chevron" />
					</summary>
					<div class="mv-filter-body">
						<div class="mv-select-filter-list">
							<NcCheckboxRadioSwitch
								:model-value="hasFilterValue(col, '1')"
								type="checkbox"
								:disabled="readonly"
								@update:model-value="toggleFilterValue(col, '1')">
								{{ t('metavox', 'Yes') }}
							</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch
								:model-value="hasFilterValue(col, '0')"
								type="checkbox"
								:disabled="readonly"
								@update:model-value="toggleFilterValue(col, '0')">
								{{ t('metavox', 'No') }}
							</NcCheckboxRadioSwitch>
						</div>
					</div>
				</details>

				<!-- Select/multiselect filter -->
				<details v-else-if="isSelectType(col)"
					class="mv-filter-row"
					:open="getFilterCount(col) > 0 || undefined">
					<summary class="mv-filter-summary">
						<span class="mv-filter-summary-text">{{ col.field_label }}</span>
						<span v-if="getFilterCount(col) > 0" class="mv-filter-badge">
							{{ getFilterCount(col) }}
						</span>
						<ChevronRightIcon :size="14" class="mv-filter-chevron" />
					</summary>
					<div class="mv-filter-body">
						<div class="mv-select-filter-list">
							<div v-if="getSelectOptions(col).length === 0" class="mv-autocomplete-empty">
								{{ t('metavox', 'No options available') }}
							</div>
							<NcCheckboxRadioSwitch v-for="opt in getSelectOptions(col)"
								:key="opt"
								:model-value="hasFilterValue(col, opt)"
								type="checkbox"
								:disabled="readonly"
								@update:model-value="toggleFilterValue(col, opt)">
								{{ opt }}
							</NcCheckboxRadioSwitch>
						</div>
					</div>
				</details>

				<!-- Text/number autocomplete filter -->
				<details v-else-if="col.field_type !== 'date' && col.field_type !== 'file' && col.field_type !== 'filelink'"
					class="mv-filter-row"
					:open="getFilterCount(col) > 0 || undefined">
					<summary class="mv-filter-summary">
						<span class="mv-filter-summary-text">{{ col.field_label }}</span>
						<span v-if="getFilterCount(col) > 0" class="mv-filter-badge">
							{{ getFilterCount(col) }}
						</span>
						<ChevronRightIcon :size="14" class="mv-filter-chevron" />
					</summary>
					<div class="mv-filter-body">
						<div class="mv-filter-tags">
							<span v-for="val in getFilterValues(col)"
								:key="val"
								class="mv-filter-tag">
								{{ val }}
								<button v-if="!readonly"
									type="button"
									class="mv-filter-tag-remove"
									@click="removeFilterValue(col, val)">
									&times;
								</button>
							</span>
							<input v-if="!readonly"
								ref="autocompleteInputs"
								type="text"
								class="mv-filter-input"
								:placeholder="t('metavox', '+ add value')"
								:data-field-id="col.field_id"
								@focus="onAutocompleteFocus(col, $event)"
								@input="onAutocompleteInput(col, $event)"
								@keydown="onAutocompleteKeydown(col, $event)"
								@blur="onAutocompleteBlur(col, $event)">
						</div>
						<div v-if="autocompleteField === col.field_id && autocompleteItems.length > 0"
							class="mv-autocomplete-dropdown">
							<div v-for="item in autocompleteItems"
								:key="item.value"
								class="mv-autocomplete-item"
								:class="{ 'mv-item-selected': item.selected }"
								@mousedown.prevent="selectAutocompleteItem(col, item)">
								{{ item.value }}
							</div>
						</div>
					</div>
				</details>
			</template>

			<!-- Sort section -->
			<div class="mv-editor-section-title">{{ t('metavox', 'Sorting') }}</div>
			<div class="mv-sort-row">
				<NcSelect v-model="editorState.sortField"
					:options="sortOptions"
					:reduce="opt => opt.id"
					label="label"
					:placeholder="t('metavox', '— no sorting —')"
					:clearable="!readonly"
					:disabled="readonly"
					input-id="mv-sort-field" />
				<div v-if="editorState.sortField" class="mv-sort-direction">
					<NcCheckboxRadioSwitch v-model="editorState.sortOrder"
						value="asc"
						type="radio"
						name="mv_sort_order"
						:disabled="readonly">
						{{ t('metavox', 'Ascending') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="editorState.sortOrder"
						value="desc"
						type="radio"
						name="mv_sort_order"
						:disabled="readonly">
						{{ t('metavox', 'Descending') }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>
		</div>

		<!-- Footer -->
		<div class="mv-editor-footer">
			<template v-if="!readonly">
				<NcButton v-if="!isNew"
					type="error"
					@click="onDelete">
					{{ t('metavox', 'Delete') }}
				</NcButton>
				<div class="mv-footer-spacer" />
				<NcButton type="secondary" @click="emit('close')">
					{{ t('metavox', 'Cancel') }}
				</NcButton>
				<NcButton type="primary"
					:disabled="saving"
					@click="onSave">
					{{ saving ? t('metavox', 'Saving…') : t('metavox', 'Save') }}
				</NcButton>
			</template>
			<template v-else>
				<div class="mv-footer-spacer" />
				<NcButton type="secondary" @click="emit('close')">
					{{ t('metavox', 'Close') }}
				</NcButton>
			</template>
		</div>

		<!-- Delete confirmation dialog -->
		<div v-if="showDeleteDialog" class="mv-delete-overlay" @click.self="showDeleteDialog = false">
			<div class="mv-delete-dialog">
				<h3>{{ t('metavox', 'Delete view') }}</h3>
				<p>{{ deleteMessage }}</p>
				<div class="mv-delete-actions">
					<NcButton type="secondary" @click="showDeleteDialog = false">
						{{ t('metavox', 'Cancel') }}
					</NcButton>
					<NcButton type="error" @click="confirmDelete">
						{{ t('metavox', 'Delete') }}
					</NcButton>
				</div>
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { NcButton, NcCheckboxRadioSwitch, NcTextField, NcSelect } from '@nextcloud/vue'
import { translate as t, translate } from '@nextcloud/l10n'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import DragVerticalIcon from 'vue-material-design-icons/DragVertical.vue'
import { getNcVersion } from './MetaVoxColumns.js'

const props = defineProps({
	view: {
		type: Object,
		default: null,
	},
	readonly: {
		type: Boolean,
		default: false,
	},
	availableFields: {
		type: Array,
		required: true,
	},
	fetchFilterValuesFn: {
		type: Function,
		required: true,
	},
	totalViews: {
		type: Number,
		default: 0,
	},
})
const emit = defineEmits(['close', 'save', 'delete'])

// ========================================
// State
// ========================================

const editorState = ref(buildEditorState())
const saving = ref(false)
const dragFrom = ref(null)
const autocompleteField = ref(null)
const autocompleteItems = ref([])
const autocompleteCache = ref({})
const colList = ref(null)

// ========================================
// Computeds
// ========================================

const isNew = computed(() => !props.view)

const supportsFilters = computed(() => {
	const v = getNcVersion()
	return v === 0 || v >= 33
})

const filterableColumns = computed(() =>
	editorState.value.columns.filter(c => c.visible && c.filterable),
)

const sortOptions = computed(() =>
	editorState.value.columns
		.filter(c => c.visible)
		.map(c => ({ id: c.field_name, label: c.field_label })),
)

// ========================================
// Reactivity helper
// ========================================

function touchFilters() {
	editorState.value.filters = { ...editorState.value.filters }
}

function ensureFilterSet(fieldId) {
	if (!editorState.value.filters[fieldId]) {
		editorState.value.filters = {
			...editorState.value.filters,
			[fieldId]: new Set(),
		}
	}
}

// ========================================
// Builder functions
// ========================================

function buildEditorState() {
	const view = props.view
	const columns = buildColumns(view)
	const filters = buildFilters(view)
	return {
		name: view?.name || '',
		isDefault: view?.is_default || false,
		position: view?.position ?? (props.totalViews || 0),
		columns,
		filters,
		sortField: view?.sort_field || '',
		sortOrder: view?.sort_order || 'asc',
	}
}

function buildColumns(view) {
	const viewCols = view?.columns || []
	if (viewCols.length > 0) {
		const result = []
		const usedIds = new Set()
		viewCols.forEach(vc => {
			const cfg = props.availableFields.find(c =>
				String(c.id) === String(vc.field_id) || c.field_name === vc.field_name,
			)
			if (!cfg) return
			usedIds.add(cfg.id)
			result.push({
				field_id: cfg.id,
				field_name: cfg.field_name,
				field_label: cfg.field_label,
				field_type: cfg.field_type,
				field_options: cfg.field_options,
				visible: vc.visible !== false && vc.show_as_column !== false,
				filterable: vc.filterable !== false,
			})
		})
		props.availableFields.forEach(cfg => {
			if (usedIds.has(cfg.id)) return
			result.push({
				field_id: cfg.id,
				field_name: cfg.field_name,
				field_label: cfg.field_label,
				field_type: cfg.field_type,
				field_options: cfg.field_options,
				visible: false,
				filterable: false,
			})
		})
		return result
	}
	return props.availableFields.map(cfg => ({
		field_id: cfg.id,
		field_name: cfg.field_name,
		field_label: cfg.field_label,
		field_type: cfg.field_type,
		field_options: cfg.field_options,
		visible: false,
		filterable: false,
	}))
}

function buildFilters(view) {
	const filters = {}
	const raw = view?.filters || {}
	for (const [fieldId, valStr] of Object.entries(raw)) {
		if (!valStr) continue
		filters[fieldId] = new Set(String(valStr).split(',').map(v => v.trim()).filter(Boolean))
	}
	return filters
}

// ========================================
// Column toggles
// ========================================

function toggleVisible(col, val) {
	col.visible = val
	if (!val) {
		col.filterable = false
	}
}

function toggleFilterable(col, val) {
	col.filterable = val
}

// ========================================
// Drag & drop
// ========================================

function onDragStart(e, index) {
	dragFrom.value = index
	e.dataTransfer.effectAllowed = 'move'
	e.target.style.opacity = '0.4'
}

function onDragEnd(e) {
	e.target.style.opacity = ''
	dragFrom.value = null
}

function onDrop(e, toIndex) {
	if (dragFrom.value === null || dragFrom.value === toIndex) return
	const [moved] = editorState.value.columns.splice(dragFrom.value, 1)
	editorState.value.columns.splice(toIndex, 0, moved)
	dragFrom.value = null
}

// ========================================
// Filter helpers
// ========================================

function getFilterCount(col) {
	return editorState.value.filters[col.field_id]?.size || 0
}

function hasFilterValue(col, val) {
	return editorState.value.filters[col.field_id]?.has(val) || false
}

function getFilterValues(col) {
	return [...(editorState.value.filters[col.field_id] || [])]
}

function toggleFilterValue(col, val) {
	ensureFilterSet(col.field_id)
	const set = editorState.value.filters[col.field_id]
	if (set.has(val)) {
		set.delete(val)
	} else {
		set.add(val)
	}
	touchFilters()
}

function removeFilterValue(col, val) {
	editorState.value.filters[col.field_id]?.delete(val)
	touchFilters()
}

function isSelectType(col) {
	return ['select', 'multiselect', 'multi_select'].includes(col.field_type)
}

function getSelectOptions(col) {
	const rawOptions = col.field_options
		?? props.availableFields.find(c => String(c.id) === String(col.field_id))?.field_options
	if (!rawOptions) return []
	if (Array.isArray(rawOptions)) {
		return rawOptions.map(o => (typeof o === 'object' ? (o.label || o.value || String(o)) : String(o)))
	}
	return String(rawOptions).split(/[\n,]/).map(s => s.trim()).filter(Boolean)
}

// ========================================
// Autocomplete
// ========================================

async function onAutocompleteFocus(col, e) {
	autocompleteField.value = col.field_id
	if (!autocompleteCache.value[col.field_name]) {
		const result = await props.fetchFilterValuesFn(col.field_name)
		autocompleteCache.value[col.field_name] = result
	}
	renderAutocomplete(col, e.target.value)
}

function onAutocompleteInput(col, e) {
	renderAutocomplete(col, e.target.value)
}

function renderAutocomplete(col, query) {
	const allValues = autocompleteCache.value[col.field_name] || []
	const tagSet = editorState.value.filters[col.field_id] || new Set()
	const filtered = allValues.filter(v =>
		(!query || v.toLowerCase().includes(query.toLowerCase())),
	)
	autocompleteItems.value = filtered.map(v => ({
		value: v,
		selected: tagSet.has(v),
	}))
}

function selectAutocompleteItem(col, item) {
	if (item.selected) return
	ensureFilterSet(col.field_id)
	editorState.value.filters[col.field_id].add(item.value)
	autocompleteField.value = null
	autocompleteItems.value = []
	touchFilters()
}

function onAutocompleteKeydown(col, e) {
	if ((e.key === 'Enter' || e.key === ',') && e.target.value.trim()) {
		e.preventDefault()
		ensureFilterSet(col.field_id)
		editorState.value.filters[col.field_id].add(e.target.value.trim())
		e.target.value = ''
		autocompleteField.value = null
		autocompleteItems.value = []
		touchFilters()
	} else if (e.key === 'Escape') {
		autocompleteField.value = null
		autocompleteItems.value = []
	} else if (e.key === 'Backspace' && !e.target.value) {
		const set = editorState.value.filters[col.field_id]
		if (set && set.size > 0) {
			const last = [...set].pop()
			set.delete(last)
			touchFilters()
		}
	}
}

function onAutocompleteBlur(col, e) {
	setTimeout(() => {
		if (e.target.value.trim()) {
			ensureFilterSet(col.field_id)
			editorState.value.filters[col.field_id].add(e.target.value.trim())
			e.target.value = ''
			touchFilters()
		}
		autocompleteField.value = null
		autocompleteItems.value = []
	}, 150)
}

// ========================================
// Save / Delete
// ========================================

async function onSave() {
	const name = editorState.value.name.trim()
	if (!name) {
		alert(translate('metavox', 'Enter a name for the view'))
		return
	}

	saving.value = true

	const columns = editorState.value.columns.map(col => ({
		field_id: col.field_id,
		field_name: col.field_name,
		field_label: col.field_label,
		visible: col.visible,
		filterable: col.filterable,
	}))

	const filters = {}
	for (const [fieldId, tagSet] of Object.entries(editorState.value.filters)) {
		if (tagSet.size > 0) {
			filters[fieldId] = [...tagSet].join(',')
		}
	}

	emit('save', {
		name,
		is_default: editorState.value.isDefault,
		position: editorState.value.isDefault ? 0 : undefined,
		columns,
		filters,
		sort_field: editorState.value.sortField || null,
		sort_order: editorState.value.sortOrder || null,
	})
}

const showDeleteDialog = ref(false)

const deleteMessage = computed(() =>
	t('metavox', 'Are you sure you want to delete the view "{name}"?', { name: props.view?.name || '' }),
)


function onDelete() {
	showDeleteDialog.value = true
}

function confirmDelete() {
	showDeleteDialog.value = false
	emit('delete', props.view)
}
</script>

<style scoped>
.mv-editor-panel {
	display: flex;
	flex-direction: column;
	height: 100%;
}

.mv-editor-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 16px 20px 12px;
	border-bottom: 1px solid var(--color-border);
	flex-shrink: 0;
}

.mv-editor-header h3 {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.mv-editor-body {
	flex: 1;
	overflow-y: auto;
	padding: 16px 20px;
}

.mv-editor-row {
	margin-bottom: 16px;
}

.mv-editor-row label {
	display: block;
	font-size: 13px;
	font-weight: 600;
	margin-bottom: 4px;
}

.mv-editor-section-title {
	font-size: var(--default-font-size);
	font-weight: 700;
	color: var(--color-main-text);
	margin: 20px 0 8px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}

.mv-editor-section-title:first-of-type {
	border-top: none;
	padding-top: 0;
}

.mv-col-header-row {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 0 4px 4px;
	border-bottom: 1px solid var(--color-border);
	margin-bottom: 4px;
}

.mv-col-header-row .mv-col-drag {
	visibility: hidden;
}

.mv-col-header-label {
	font-size: 12px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.mv-col-list {
	margin-bottom: 8px;
}

.mv-col-row {
	display: flex;
	align-items: center;
	padding: 6px 4px;
	border-radius: var(--border-radius);
	gap: 8px;
}

.mv-col-row:hover {
	background: var(--color-background-hover);
}

.mv-col-drag {
	cursor: grab;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
	width: 20px;
	display: flex;
	align-items: center;
	justify-content: center;
}

.mv-col-name {
	flex: 1;
	font-size: 14px;
}

.mv-col-check {
	width: 70px;
	display: flex;
	justify-content: center;
}

.mv-disabled {
	opacity: 0.4;
	pointer-events: none;
}

/* Verticale centrering van checkbox labels (NcCheckboxRadioSwitch) */
.mv-col-check :deep(.checkbox-content),
.mv-filter-body :deep(.checkbox-content),
.mv-editor-row :deep(.checkbox-content) {
	min-height: 32px;
	padding-block: 4px;
}

.mv-col-check :deep(.checkbox-content__icon),
.mv-filter-body :deep(.checkbox-content__icon),
.mv-editor-row :deep(.checkbox-content__icon) {
	margin-block: auto !important;
}

/* Filter rows */
.mv-filter-row {
	border-bottom: 1px solid var(--color-border);
}

.mv-filter-row:last-of-type {
	border-bottom: none;
}

.mv-filter-summary {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 10px 4px;
	cursor: pointer;
	user-select: none;
	list-style: none;
	min-height: 40px;
	font-size: 14px;
	font-weight: 500;
	color: var(--color-main-text);
}

.mv-filter-summary::-webkit-details-marker {
	display: none;
}

.mv-filter-summary:hover {
	background: var(--color-background-hover);
	border-radius: var(--border-radius, 4px);
}

.mv-filter-summary-text {
	flex: 1;
}

.mv-filter-badge {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 18px;
	height: 18px;
	padding: 0 4px;
	border-radius: 9px;
	background: var(--color-primary-element, #0082c9);
	color: #fff;
	font-size: 11px;
	font-weight: 600;
	margin-right: 6px;
}

.mv-filter-chevron {
	transition: transform 0.15s;
	color: var(--color-text-maxcontrast, #767676);
	flex-shrink: 0;
}

details.mv-filter-row[open] .mv-filter-chevron {
	transform: rotate(90deg);
}

.mv-filter-body {
	padding: 4px 0 8px 0;
	position: relative;
}

.mv-select-filter-list {
	max-height: 160px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	padding: 4px 8px;
	background: var(--color-main-background);
}

.mv-filter-tags {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element, 32px);
	min-height: 34px;
	align-items: center;
	cursor: text;
}

.mv-filter-tag {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 8px;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
	border-radius: 12px;
	font-size: 13px;
}

.mv-filter-tag-remove {
	background: none;
	border: none;
	cursor: pointer;
	padding: 0;
	font-size: 14px;
	line-height: 1;
	color: inherit;
	opacity: 0.7;
}

.mv-filter-tag-remove:hover {
	opacity: 1;
}

.mv-filter-input {
	border: none;
	outline: none;
	background: transparent;
	font: inherit;
	font-size: 12px;
	min-width: 80px;
	flex: 1;
	color: var(--color-main-text);
}

.mv-autocomplete-dropdown {
	position: absolute;
	left: 0;
	right: 0;
	top: calc(100%);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
	z-index: 3000;
	max-height: 180px;
	overflow-y: auto;
}

.mv-autocomplete-item {
	padding: 6px 12px;
	font-size: 14px;
	cursor: pointer;
}

.mv-autocomplete-item:hover {
	background: var(--color-background-hover);
}

.mv-autocomplete-item.mv-item-selected {
	opacity: 0.4;
	cursor: default;
}

.mv-autocomplete-item.mv-item-selected:hover {
	background: transparent;
}

.mv-autocomplete-empty {
	padding: 8px 12px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

/* Sort */
.mv-sort-row {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.mv-sort-row :deep(.v-select) {
	width: 100%;
}

.mv-sort-direction {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.mv-sort-direction :deep(.checkbox-radio-switch) {
	display: inline-flex;
	align-items: center;
}

.mv-sort-direction :deep(.checkbox-radio-switch__label) {
	display: inline-flex;
	align-items: center;
	min-height: unset;
	padding: 4px 8px 4px 0;
}

.mv-sort-direction :deep(.checkbox-radio-switch__icon) {
	display: inline-flex;
	align-items: center;
	margin-right: 4px;
}

/* Footer */
.mv-editor-footer {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 12px 20px;
	border-top: 1px solid var(--color-border);
	flex-shrink: 0;
}

.mv-footer-spacer {
	flex: 1;
}

/* Delete confirmation dialog */
.mv-delete-overlay {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.4);
	z-index: 10000;
	display: flex;
	align-items: center;
	justify-content: center;
}

.mv-delete-dialog {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large, 12px);
	padding: 24px;
	min-width: 320px;
	max-width: 400px;
	box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
}

.mv-delete-dialog h3 {
	margin: 0 0 12px;
	font-size: 18px;
	font-weight: 600;
}

.mv-delete-dialog p {
	margin: 0 0 20px;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	line-height: 1.5;
}

.mv-delete-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
