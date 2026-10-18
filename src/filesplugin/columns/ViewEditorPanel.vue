<template>
	<!-- NcDialog rather than a hand-built slide-over: it brings the frame,
	     the focus trap, Escape, the button row and its order. "large", not the
	     "normal" of a form: the section list takes 220px of it, which leaves
	     the form about the width "normal" gives, and below 876px NcDialog
	     folds the list away. Not NcAppSettingsDialog: it has no buttons, and
	     a click outside would drop what is not saved. -->
	<NcDialog :open="true"
		:name="dialogName"
		size="large"
		content-classes="mv-editor-content"
		:buttons="dialogButtons"
		@update:open="emit('close')">
		<!-- The sections, as NcAppSettingsDialog lists them: not on a phone,
		     and not when the dialog is too narrow to put them beside the form. -->
		<template v-if="!isMobile" #navigation="{ isCollapsed }">
			<ul v-if="!isCollapsed" class="mv-editor-nav">
				<li v-for="section in sections" :key="section.id">
					<a :href="`#${section.id}`"
						class="mv-editor-nav__link"
						:class="{ 'mv-editor-nav__link--active': section.id === currentSection }"
						:aria-current="section.id === currentSection ? 'location' : undefined"
						@click.prevent="goToSection(section.id)">
						{{ section.name }}
					</a>
				</li>
			</ul>
		</template>

		<!-- Inert while saving: an edit made then would be lost when the dialog closes. -->
		<div ref="body" class="mv-editor-body" :inert="saving || undefined">
			<section id="mv-view-general" class="mv-editor-section" aria-labelledby="mv-view-general-title">
				<h3 id="mv-view-general-title" class="mv-editor-section-title" tabindex="-1">
					{{ t('metavox', 'General') }} <!-- TRANSLATORS Noun: heading of the view editor section with the name of the view -->
				</h3>
				<!-- Name row -->
				<div class="mv-editor-row">
					<!-- The label is the component's own, so it is bound to the
					     input; a bare <label> beside it named nothing. -->
					<NcTextField id="mv-view-name"
						:model-value="editorState.name"
						:label="nameLabel"
						:placeholder="namePlaceholder"
						:disabled="readonly"
						:error="nameError !== ''"
						:helper-text="nameError"
						@update:model-value="onNameInput" />
				</div>

				<!-- Default toggle -->
				<div class="mv-editor-row">
					<NcCheckboxRadioSwitch :model-value="editorState.isDefault"
						type="checkbox"
						:disabled="readonly"
						@update:model-value="editorState.isDefault = $event">
						{{ t('metavox', 'Standard view') }} <!-- TRANSLATORS Checkbox in the view editor: this view opens by default when the folder is opened -->
					</NcCheckboxRadioSwitch>
				</div>
			</section>

			<section id="mv-view-columns" class="mv-editor-section" aria-labelledby="mv-view-columns-title">
				<h3 id="mv-view-columns-title" class="mv-editor-section-title" tabindex="-1">
					{{ t('metavox', 'Columns') }}
				</h3>

				<div class="mv-col-header-row">
					<span class="mv-col-drag" />
					<span class="mv-col-name mv-field-label">{{ t('metavox', 'Field') }}</span>
					<span class="mv-col-check mv-field-label">{{ t('metavox', 'Visible') }}</span>
					<span class="mv-col-check mv-field-label">{{ t('metavox', 'Filterable') }}</span>
				</div>

				<div class="mv-col-list">
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
								:aria-label="showLabel(col.field_label)"
								:disabled="readonly"
								@update:model-value="toggleVisible(col, $event)" />
						</span>
						<span class="mv-col-check" :class="{ 'mv-disabled': !col.visible }">
							<NcCheckboxRadioSwitch :model-value="col.filterable"
								type="checkbox"
								:aria-label="filterLabel(col.field_label)"
								:disabled="readonly || !col.visible"
								@update:model-value="toggleFilterable(col, $event)" />
						</span>
					</div>
				</div>
			</section>

			<section id="mv-view-filters" class="mv-editor-section" aria-labelledby="mv-view-filters-title">
				<h3 id="mv-view-filters-title" class="mv-editor-section-title" tabindex="-1">
					{{ t('metavox', 'Filters') }} <!-- TRANSLATORS Noun: heading of the filter section in the view editor -->
				</h3>

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
									{{ t('metavox', 'Yes') }} <!-- TRANSLATORS Filter option for a checkbox field: files where the box is ticked -->
								</NcCheckboxRadioSwitch>
								<NcCheckboxRadioSwitch
									:model-value="hasFilterValue(col, '0')"
									type="checkbox"
									:disabled="readonly"
									@update:model-value="toggleFilterValue(col, '0')">
									{{ t('metavox', 'No') }} <!-- TRANSLATORS Filter option for a checkbox field: files where the box is not ticked -->
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
									:key="opt.value"
									:model-value="hasFilterValue(col, opt.value)"
									type="checkbox"
									:disabled="readonly"
									@update:model-value="toggleFilterValue(col, opt.value)">
									{{ opt.label }}
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
									<!-- The name was the × glyph itself, which a screen
									     reader reads as "times" or skips. -->
									<button v-if="!readonly"
										type="button"
										class="mv-filter-tag-remove"
										:aria-label="removeValueLabel(val)"
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
			</section>

			<!-- Highlight rules, per visible column -->
			<ColumnFormatEditor ref="formatEditor" :columns="editorState.columns" :readonly="readonly" />

			<section id="mv-view-sorting" class="mv-editor-section" aria-labelledby="mv-view-sorting-title">
				<h3 id="mv-view-sorting-title" class="mv-editor-section-title" tabindex="-1">
					{{ t('metavox', 'Sorting') }} <!-- TRANSLATORS Noun: heading of the sort section in the view editor -->
				</h3>
				<div class="mv-sort-row">
					<NcSelect v-model="editorState.sortField"
						:options="sortOptions"
						:reduce="opt => opt.id"
						label="label"
						:placeholder="noSortPlaceholder"
						:clearable="!readonly"
						:disabled="readonly"
						input-id="mv-sort-field" />
					<div v-if="editorState.sortField" class="mv-sort-direction">
						<NcCheckboxRadioSwitch v-model="editorState.sortOrder"
							value="asc"
							type="radio"
							name="mv_sort_order"
							:disabled="readonly">
							{{ t('metavox', 'Ascending') }} <!-- TRANSLATORS Radio option for the sort direction (A to Z, oldest first) -->
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch v-model="editorState.sortOrder"
							value="desc"
							type="radio"
							name="mv_sort_order"
							:disabled="readonly">
							{{ t('metavox', 'Descending') }} <!-- TRANSLATORS Radio option for the sort direction (Z to A, newest first) -->
						</NcCheckboxRadioSwitch>
					</div>
				</div>
			</section>
		</div>

		<!-- Footer -->


		<!-- Delete confirmation dialog -->
	</NcDialog>
</template>

<script setup>
import { ref, computed, nextTick, onBeforeUnmount, onMounted } from 'vue'
import { useIsMobile } from '@nextcloud/vue/composables/useIsMobile'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { translate as t } from '@nextcloud/l10n'
import { showError } from '../../utils/toast.js'
import { showConfirmation } from '../../utils/confirm.js'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import DragVerticalIcon from 'vue-material-design-icons/DragVertical.vue'
import { cloneRules, viewColumnsPayload } from './ColumnUtils.js'
import ColumnFormatEditor from './ColumnFormatEditor.vue'
import { errorText, isFieldError } from '../../utils/errorText.js'
import { PLAIN_TEXT } from '../../utils/fieldValue.js'
import { optionItems } from '../../utils/fieldOptions.js'

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
	// Saves the view; rejects with the request's error, so the dialog stays.
	submit: {
		type: Function,
		required: true,
	},
})
const emit = defineEmits(['close', 'delete'])

// ========================================
// State
// ========================================

// The highlight rules as loaded, per field: a column is sent with its rules
// only when they differ. And the stored columns the editor could not show.
const baseline = new Map()
const carried = []
let ruleKey = 0

const editorState = ref(buildEditorState())
const saving = ref(false)
const formatEditor = ref(null)
// What was sent per column at the last save, to name the rule a refusal is about.
let lastSent = []
const nameError = ref('')

// TRANSLATORS Noun: label of the field that holds the name of a view
const nameLabel = t('metavox', 'Name')
// TRANSLATORS Placeholder of the field that holds the name of a view
const namePlaceholder = t('metavox', 'View name')
// TRANSLATORS Placeholder of the box where you type a value to filter a text or number field on; the "+" stays
const addValuePlaceholder = t('metavox', '+ add value')
// TRANSLATORS Accessible name of the "Visible" checkbox in the column list; {column} is the column name, e.g. "Show Status"
const showLabel = (column) => t('metavox', 'Show {column}', { column }, undefined, PLAIN_TEXT)
// TRANSLATORS Accessible name of the "Filterable" checkbox in the column list; {column} is the column name, e.g. "Filter on Status"
const filterLabel = (column) => t('metavox', 'Filter on {column}', { column }, undefined, PLAIN_TEXT)
// TRANSLATORS Accessible name of the × button on a filter value; {value} is that value, e.g. "Remove filter value Draft"
const removeValueLabel = (value) => t('metavox', 'Remove filter value {value}', { value }, undefined, PLAIN_TEXT)
// TRANSLATORS Placeholder of the sort field dropdown when the view does not sort
const noSortPlaceholder = t('metavox', '— no sorting —')

/** What this dialog is, which is what NcDialog puts in its title. */
const dialogName = computed(() => {
	// TRANSLATORS Title of the view editor when it only shows a view; also a menu item in the view menu
	if (props.readonly) return t('metavox', 'View details')
	return isNew.value
		// TRANSLATORS Title of the view editor when it creates a new view; also a menu item in the view menu
		? t('metavox', 'New view')
		// TRANSLATORS Title of the view editor when it changes an existing view
		: t('metavox', 'Edit view')
})

/**
 * The footer, as configuration rather than markup: NcDialog places the
 * buttons and puts the primary one last. Delete stays first so it is not
 * beside Save, and is never the focused default.
 *
 * Delete is tertiary, not error. Nextcloud reserves the red error variant
 * for bulk or irreversible operations — emptying a trash bin, revoking an
 * OAuth client — and keeps per-item delete deliberately quiet in an edit
 * dialog's footer: EditCalendarModal and SharingDetailsTab both do this.
 */
const dialogButtons = computed(() => {
	if (props.readonly) {
		// TRANSLATORS Verb: dialog button that closes the read-only view editor
		return [{ label: t('metavox', 'Close'), callback: () => emit('close') }]
	}
	const buttons = []
	if (!isNew.value) {
		buttons.push({
			// TRANSLATORS Verb: dialog button that deletes the view
			label: t('metavox', 'Delete'),
			variant: 'tertiary',
			callback: () => { onDelete(); return false },
		})
	}
	// TRANSLATORS Verb: dialog button that closes the view editor without saving
	buttons.push({ label: t('metavox', 'Cancel'), callback: () => emit('close') })
	buttons.push({
		label: saving.value
			// TRANSLATORS Label of the save button while the view is being saved; keep it short
			? t('metavox', 'Saving\u00a0\u2026')
			// TRANSLATORS Verb: dialog button that saves the view
			: t('metavox', 'Save'),
		variant: 'primary',
		// An open rule that is not valid yet has to be finished or cancelled first.
		disabled: saving.value || !!formatEditor.value?.blocking,
		// Awaited, so the dialog shows it is busy; false keeps it open (a
		// successful save closes it from outside).
		callback: async () => { await onSave(); return false },
	})
	return buttons
})

/** Clear the name error as soon as the user starts fixing it. */
function onNameInput(value) {
	editorState.value.name = value
	if (nameError.value && value.trim()) {
		nameError.value = ''
	}
}
const dragFrom = ref(null)
const autocompleteField = ref(null)
const autocompleteItems = ref([])
const autocompleteCache = ref({})

// ========================================
// Section navigation
// ========================================

const isMobile = useIsMobile()
const body = ref(null)

/** The sections in the order of the form; Highlight cells only when it shows. */
const sections = computed(() => [
	{ id: 'mv-view-general', name: t('metavox', 'General') },
	{ id: 'mv-view-columns', name: t('metavox', 'Columns') },
	{ id: 'mv-view-filters', name: t('metavox', 'Filters') },
	...(formatEditor.value?.shown ? [{ id: 'mv-format-section', name: t('metavox', 'Highlight cells') }] : []),
	{ id: 'mv-view-sorting', name: t('metavox', 'Sorting') },
])
const currentSection = ref('mv-view-general')

// A jump scrolls smoothly; the sections it passes must not take the mark.
let jumpUntil = 0
let scroller = null
let frame = 0

/** Scroll to a section and put focus on its heading, as a link to it would. */
function goToSection(id) {
	const section = document.getElementById(id)
	if (!section) return
	currentSection.value = id
	jumpUntil = Date.now() + 1000
	const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches
	section.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' })
	section.querySelector('h3')?.focus({ preventScroll: true })
}

/** The section at the top of the scroller marks itself; at the bottom, the last one. */
function markCurrentSection() {
	frame = 0
	if (!scroller || Date.now() < jumpUntil) return
	const ids = sections.value.map((s) => s.id)
	if (scroller.scrollTop + scroller.clientHeight >= scroller.scrollHeight - 2) {
		currentSection.value = ids[ids.length - 1]
		return
	}
	const top = scroller.getBoundingClientRect().top
	let current = ids[0]
	for (const id of ids) {
		const section = document.getElementById(id)
		if (section && section.getBoundingClientRect().top - top <= 16) current = id
	}
	currentSection.value = current
}

function onScroll() {
	if (!frame) frame = requestAnimationFrame(markCurrentSection)
}

onMounted(() => nextTick(() => {
	// NcDialog's content element is what scrolls, not our body inside it.
	scroller = body.value?.closest('.mv-editor-content') ?? null
	scroller?.addEventListener('scroll', onScroll, { passive: true })
}))

onBeforeUnmount(() => {
	scroller?.removeEventListener('scroll', onScroll)
	if (frame) cancelAnimationFrame(frame)
})

// ========================================
// Computeds
// ========================================

const isNew = computed(() => !props.view)

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

/** A column's rules, each with a key that stays with it while it is edited. */
function ruleEntries(rules) {
	return cloneRules(rules).map((rule) => ({ key: ++ruleKey, rule }))
}

function editorColumn(cfg, vc) {
	return {
		field_id: cfg.id,
		field_name: cfg.field_name,
		field_label: cfg.field_label,
		field_type: cfg.field_type,
		field_options: cfg.field_options,
		visible: vc ? vc.visible !== false && vc.show_as_column !== false : false,
		filterable: vc ? vc.filterable !== false : false,
		rules: ruleEntries(vc?.format),
	}
}

function buildColumns(view) {
	const fields = []
	const copies = new Map()
	for (const vc of view?.columns || []) {
		const cfg = props.availableFields.find(c =>
			String(c.id) === String(vc.field_id) || c.field_name === vc.field_name,
		)
		// A field that did not load is not shown, but goes back as it came.
		if (!cfg) {
			carried.push(vc)
			continue
		}
		if (!copies.has(cfg.id)) {
			copies.set(cfg.id, [])
			fields.push(cfg)
		}
		copies.get(cfg.id).push(vc)
	}
	const columns = fields.map((cfg) => {
		// A field listed twice: edit the copy the grid shows (the first visible
		// one, else the first), and always send its rules, so the server can't
		// hand it those of the other copy.
		const list = copies.get(cfg.id)
		const vc = list.find(c => c.visible !== false && c.show_as_column !== false) ?? list[0]
		const col = editorColumn(cfg, vc)
		baseline.set(cfg.id, list.length > 1 ? null : JSON.stringify(col.rules.map(r => r.rule)))
		return col
	})
	for (const cfg of props.availableFields) {
		if (copies.has(cfg.id)) continue
		baseline.set(cfg.id, '[]')
		columns.push(editorColumn(cfg, null))
	}
	return columns
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

// The filter holds the stored values; a person picks by label.
function getSelectOptions(col) {
	return optionItems(col.field_options
		?? props.availableFields.find(c => String(c.id) === String(col.field_id))?.field_options)
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

function focusName() {
	nextTick(() => document.getElementById('mv-view-name')?.focus())
}

async function onSave() {
	// A valid open rule is part of what is saved; an invalid one shows its errors.
	if (formatEditor.value && !formatEditor.value.applyOpenForm()) return
	const name = editorState.value.name.trim()
	// On the field, not in an alert(): the manual asks for an error state
	// plus a short helper message saying how to fix it.
	if (!name) {
		nameError.value = t('metavox', 'Enter a name for the view')
		focusName()
		return
	}
	if ([...name].length > 100) {
		nameError.value = t('metavox', 'A view name can have at most 100 characters')
		focusName()
		return
	}

	const { columns, sent } = viewColumnsPayload(editorState.value.columns, baseline, carried)
	lastSent = sent

	const filters = {}
	for (const [fieldId, tagSet] of Object.entries(editorState.value.filters)) {
		if (tagSet.size > 0) {
			filters[fieldId] = [...tagSet].join(',')
		}
	}

	saving.value = true
	try {
		await props.submit({
			name,
			is_default: editorState.value.isDefault,
			position: editorState.value.isDefault ? 0 : undefined,
			columns,
			filters,
			sort_field: editorState.value.sortField || null,
			sort_order: editorState.value.sortOrder || null,
		})
	} catch (e) {
		onSaveError(e)
	} finally {
		saving.value = false
	}
}

/** A refused save keeps the dialog and every edit; the reason goes where it belongs. */
function onSaveError(e) {
	const fields = isFieldError(e) ? e.response.data.fields : null
	if (typeof fields?.name === 'string') {
		nameError.value = fields.name
		focusName()
		return
	}
	// A refused rule: say which column and which rule.
	for (const [key, problem] of Object.entries(fields ?? {})) {
		const match = key.match(/^columns\.(\d+)\.format(?:\.(\d+))?/)
		const column = match && editorState.value.columns.find((c) => c.field_id === lastSent[Number(match[1])]?.fieldId)
		if (!column || typeof problem !== 'string') continue
		showError(match[2] === undefined
			// TRANSLATORS Error message when the server refuses a column; {column} is the column name, {problem} the server's reason, e.g. "Status: This value is not valid"
			? t('metavox', '{column}: {problem}', { column: column.field_label, problem })
			// TRANSLATORS Error message when the server refuses a highlight rule; {column} is the column name, {position} the rule number, {problem} the server's reason, e.g. "Status, rule 2: This value is not valid"
			: t('metavox', '{column}, rule {position}: {problem}', { column: column.field_label, position: Number(match[2]) + 1, problem }))
		return
	}
	// The outcome of an async action belongs in a toast, and the reason is
	// a placeholder so it can be reordered in translation.
	// TRANSLATORS Error message; {reason} is the server's error text, e.g. "Could not save the view: Internal server error"
	showError(t('metavox', 'Could not save the view: {reason}', {
		// TRANSLATORS Fallback error reason when the server gives none; shown as {reason} in an error message, e.g. "Could not save the view: {reason}"
		reason: errorText(e, t('metavox', 'Internal server error')),
	}))
}

/**
 * Confirm before deleting. This used to be a hand-built overlay inside the
 * panel — a modal within a modal, with no focus trap, and Escape tore down
 * the whole editor instead of dismissing the confirmation.
 */
async function onDelete() {
	const ok = await showConfirmation({
		// TRANSLATORS Title of the confirmation dialog for deleting a view
		name: t('metavox', 'Delete view'),
		// TRANSLATORS Confirmation question; {name} is the view name, e.g. "Delete the view Open contracts? This cannot be undone."
		text: t('metavox', 'Delete the view {name}? This cannot be undone.', { name: props.view?.name || '' }, undefined, PLAIN_TEXT),
		// TRANSLATORS Verb: button in the confirmation dialog that deletes the view
		labelConfirm: t('metavox', 'Delete view'),
		// TRANSLATORS Verb: button in the confirmation dialog that keeps the view
		labelReject: t('metavox', 'Cancel'),
	})
	if (ok) {
		emit('delete', props.view)
	}
}
</script>

<style scoped>



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
	font-weight: var(--font-weight-heading, 600);
	margin-bottom: 4px;
}

/* The section list beside the form. Active is NcAppNavigationItem's mark on
   Nextcloud 34+ (@nextcloud/vue 9.8, #8448): 16% tint, 22% on hover, and a
   3px rail that grows in; the weight stays that of the others. */
.mv-editor-nav {
	display: flex;
	flex-direction: column;
	gap: 2px;
	margin: 0;
	padding: var(--default-grid-baseline);
	list-style: none;
}

.mv-editor-nav__link {
	position: relative;
	display: flex;
	align-items: center;
	min-height: var(--default-clickable-area);
	padding-inline: calc(2 * var(--default-grid-baseline));
	border-radius: var(--border-radius-element);
	color: var(--color-main-text);
	font-weight: var(--font-weight-element, 500);
	text-decoration: none;
	overflow-wrap: anywhere;
}

.mv-editor-nav__link:hover {
	background-color: color-mix(in srgb, var(--color-primary-element) 8%, transparent);
}

.mv-editor-nav__link:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: -2px;
}

.mv-editor-nav__link--active {
	background-color: color-mix(in srgb, var(--color-primary-element) 16%, transparent);
}

.mv-editor-nav__link--active:hover {
	background-color: color-mix(in srgb, var(--color-primary-element) 22%, transparent);
}

.mv-editor-nav__link--active::before {
	content: '';
	position: absolute;
	inset-block: calc(2 * var(--default-grid-baseline));
	inset-inline-start: 0;
	inline-size: 3px;
	border-radius: var(--border-radius-pill);
	background-color: var(--color-primary-element);
	animation: mv-editor-nav-rail var(--animation-quick, 200ms) ease-out;
}

@keyframes mv-editor-nav-rail {
	from {
		transform: scaleY(0);
		opacity: 0;
	}
	to {
		transform: scaleY(1);
		opacity: 1;
	}
}

@media (prefers-reduced-motion: reduce) {
	.mv-editor-nav__link--active::before {
		animation: none;
	}
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
	font-weight: var(--font-weight-element, 500);
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
	/* 20px, not 18: the text inside went from 11px to 13px, and 18 left it
	   with a pixel of room either side. Both are on the 4px grid. */
	min-width: 20px;
	height: 20px;
	padding: 0 4px;
	border-radius: var(--border-radius-pill);
	/* The tokens resolve on every supported Nextcloud, so the hex fallbacks
	   were dead weight that would drift silently under a theme — and
	   color: #fff was not even paired with the background it sits on.
	   11px is below --font-size-small, the platform's smallest (§6). */
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-size: var(--font-size-small);
	font-weight: var(--font-weight-heading, 600);
	margin-inline-end: 6px;
}

.mv-filter-chevron {
	transition: transform 0.15s;
	color: var(--color-text-maxcontrast);
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
	font-size: var(--font-size-small);
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
	font-size: var(--font-size-small);
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
	font-size: var(--font-size-small);
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
	margin-inline-end: 4px;
}

/* Footer */


/* Delete confirmation dialog */




</style>
