<template>
	<div class="mv-editor-panel">
		<!-- Header -->
		<div class="mv-editor-header">
			<h3>{{ isNew ? 'Nieuwe weergave' : 'Weergave bewerken' }}</h3>
			<NcButton type="tertiary"
				:aria-label="'Sluiten'"
				@click="$emit('close')">
				<template #icon>
					<CloseIcon :size="20" />
				</template>
			</NcButton>
		</div>

		<!-- Body -->
		<div class="mv-editor-body">
			<!-- Name row -->
			<div class="mv-editor-row">
				<label>Naam</label>
				<NcTextField :model-value="editorState.name"
					placeholder="Naam van de weergave"
					@update:model-value="editorState.name = $event" />
			</div>

			<!-- Default toggle -->
			<div class="mv-editor-row">
				<NcCheckboxRadioSwitch :model-value="editorState.isDefault"
					type="checkbox"
					@update:model-value="editorState.isDefault = $event">
					Standaard weergave
				</NcCheckboxRadioSwitch>
			</div>

			<!-- Columns section -->
			<div class="mv-editor-section-title">Kolommen</div>

			<div class="mv-col-header-row">
				<span class="mv-col-drag" />
				<span class="mv-col-name mv-col-header-label">Veld</span>
				<span class="mv-col-check mv-col-header-label">Zichtbaar</span>
				<span class="mv-col-check mv-col-header-label">Filterbaar</span>
			</div>

			<div ref="colList" class="mv-col-list">
				<div v-for="(col, index) in editorState.columns"
					:key="col.field_id"
					class="mv-col-row"
					draggable="true"
					:data-col-idx="index"
					@dragstart="onDragStart($event, index)"
					@dragend="onDragEnd"
					@dragover.prevent
					@drop.prevent="onDrop($event, index)">
					<span class="mv-col-drag" title="Slepen om te herordenen">⠿</span>
					<span class="mv-col-name">{{ col.field_label }}</span>
					<span class="mv-col-check">
						<NcCheckboxRadioSwitch :model-value="col.visible"
							type="checkbox"
							@update:model-value="toggleVisible(col, $event)" />
					</span>
					<span class="mv-col-check" :class="{ 'mv-disabled': !col.visible }">
						<NcCheckboxRadioSwitch :model-value="col.filterable"
							type="checkbox"
							:disabled="!col.visible"
							@update:model-value="toggleFilterable(col, $event)" />
					</span>
				</div>
			</div>

			<!-- Filters section -->
			<div class="mv-editor-section-title">Filters (preset waarden)</div>

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
								@update:model-value="toggleFilterValue(col, '1')">
								Ja
							</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch
								:model-value="hasFilterValue(col, '0')"
								type="checkbox"
								@update:model-value="toggleFilterValue(col, '0')">
								Nee
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
								Geen opties beschikbaar
							</div>
							<NcCheckboxRadioSwitch v-for="opt in getSelectOptions(col)"
								:key="opt"
								:model-value="hasFilterValue(col, opt)"
								type="checkbox"
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
								<button type="button"
									class="mv-filter-tag-remove"
									@click="removeFilterValue(col, val)">
									&times;
								</button>
							</span>
							<input ref="autocompleteInputs"
								type="text"
								class="mv-filter-input"
								placeholder="+ waarde toevoegen"
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
			<div class="mv-editor-section-title">Sortering</div>
			<div class="mv-sort-row">
				<NcSelect v-model="editorState.sortField"
					:options="sortOptions"
					:reduce="opt => opt.id"
					label="label"
					:placeholder="'— geen sortering —'"
					:clearable="true"
					input-id="mv-sort-field" />
				<template v-if="editorState.sortField">
					<NcCheckboxRadioSwitch v-model="editorState.sortOrder"
						value="asc"
						type="radio"
						name="mv_sort_order">
						Oplopend
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="editorState.sortOrder"
						value="desc"
						type="radio"
						name="mv_sort_order">
						Aflopend
					</NcCheckboxRadioSwitch>
				</template>
			</div>
		</div>

		<!-- Footer -->
		<div class="mv-editor-footer">
			<NcButton v-if="!isNew"
				type="error"
				@click="onDelete">
				Verwijderen
			</NcButton>
			<div class="mv-footer-spacer" />
			<NcButton type="secondary" @click="$emit('close')">
				Annuleren
			</NcButton>
			<NcButton type="primary"
				:disabled="saving"
				@click="onSave">
				{{ saving ? 'Opslaan...' : 'Opslaan' }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcTextField, NcSelect } from '@nextcloud/vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'

export default {
	name: 'ViewEditorPanel',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcTextField,
		NcSelect,
		CloseIcon,
		ChevronRightIcon,
	},
	props: {
		view: {
			type: Object,
			default: null,
		},
		availableFields: {
			type: Array,
			required: true,
		},
		fetchFilterValuesFn: {
			type: Function,
			required: true,
		},
	},
	emits: ['close', 'save', 'delete'],
	data() {
		const editorState = this.buildEditorState()
		return {
			editorState,
			saving: false,
			dragFrom: null,
			autocompleteField: null,
			autocompleteItems: [],
			autocompleteCache: {},
			updateKey: 0,
		}
	},
	computed: {
		isNew() {
			return !this.view
		},
		filterableColumns() {
			return this.editorState.columns.filter(c => c.visible && c.filterable)
		},
		sortOptions() {
			return this.editorState.columns
				.filter(c => c.visible)
				.map(c => ({ id: c.field_name, label: c.field_label }))
		},
	},
	methods: {
		buildEditorState() {
			const view = this.view
			const columns = this.buildColumns(view)
			const filters = this.buildFilters(view)
			return {
				name: view?.name || '',
				isDefault: view?.is_default || false,
				columns,
				filters,
				sortField: view?.sort_field || '',
				sortOrder: view?.sort_order || 'asc',
			}
		},

		buildColumns(view) {
			const viewCols = view?.columns || []
			if (viewCols.length > 0) {
				const result = []
				const usedIds = new Set()
				viewCols.forEach(vc => {
					const cfg = this.availableFields.find(c =>
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
				this.availableFields.forEach(cfg => {
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
			return this.availableFields.map(cfg => ({
				field_id: cfg.id,
				field_name: cfg.field_name,
				field_label: cfg.field_label,
				field_type: cfg.field_type,
				field_options: cfg.field_options,
				visible: false,
				filterable: false,
			}))
		},

		buildFilters(view) {
			const filters = {}
			const raw = view?.filters || {}
			for (const [fieldId, valStr] of Object.entries(raw)) {
				if (!valStr) continue
				filters[fieldId] = new Set(String(valStr).split(',').map(v => v.trim()).filter(Boolean))
			}
			return filters
		},

		// Column toggles
		toggleVisible(col, val) {
			col.visible = val
			if (!val) {
				col.filterable = false
			}
			this.updateKey++
		},

		toggleFilterable(col, val) {
			col.filterable = val
			this.updateKey++
		},

		// Drag & drop
		onDragStart(e, index) {
			this.dragFrom = index
			e.dataTransfer.effectAllowed = 'move'
			e.target.style.opacity = '0.4'
		},

		onDragEnd(e) {
			e.target.style.opacity = ''
			this.dragFrom = null
		},

		onDrop(e, toIndex) {
			if (this.dragFrom === null || this.dragFrom === toIndex) return
			const [moved] = this.editorState.columns.splice(this.dragFrom, 1)
			this.editorState.columns.splice(toIndex, 0, moved)
			this.dragFrom = null
		},

		// Filter helpers
		getFilterCount(col) {
			// eslint-disable-next-line no-unused-expressions
			this.updateKey
			return this.editorState.filters[col.field_id]?.size || 0
		},

		hasFilterValue(col, val) {
			// eslint-disable-next-line no-unused-expressions
			this.updateKey
			return this.editorState.filters[col.field_id]?.has(val) || false
		},

		getFilterValues(col) {
			// eslint-disable-next-line no-unused-expressions
			this.updateKey
			return [...(this.editorState.filters[col.field_id] || [])]
		},

		toggleFilterValue(col, val) {
			if (!this.editorState.filters[col.field_id]) {
				this.editorState.filters[col.field_id] = new Set()
			}
			const set = this.editorState.filters[col.field_id]
			if (set.has(val)) {
				set.delete(val)
			} else {
				set.add(val)
			}
			this.updateKey++
		},

		removeFilterValue(col, val) {
			this.editorState.filters[col.field_id]?.delete(val)
			this.updateKey++
		},

		isSelectType(col) {
			return ['select', 'multiselect', 'multi_select'].includes(col.field_type)
		},

		getSelectOptions(col) {
			const rawOptions = col.field_options
				?? this.availableFields.find(c => String(c.id) === String(col.field_id))?.field_options
			if (!rawOptions) return []
			if (Array.isArray(rawOptions)) {
				return rawOptions.map(o => (typeof o === 'object' ? (o.label || o.value || String(o)) : String(o)))
			}
			return String(rawOptions).split(/[\n,]/).map(s => s.trim()).filter(Boolean)
		},

		// Autocomplete
		onAutocompleteFocus(col, e) {
			this.autocompleteField = col.field_id
			if (!this.autocompleteCache[col.field_name]) {
				this.autocompleteCache[col.field_name] = this.fetchFilterValuesFn(col.field_name)
			}
			this.renderAutocomplete(col, e.target.value)
		},

		onAutocompleteInput(col, e) {
			this.renderAutocomplete(col, e.target.value)
		},

		renderAutocomplete(col, query) {
			const allValues = this.autocompleteCache[col.field_name] || []
			const tagSet = this.editorState.filters[col.field_id] || new Set()
			const filtered = allValues.filter(v =>
				(!query || v.toLowerCase().includes(query.toLowerCase())),
			)
			this.autocompleteItems = filtered.map(v => ({
				value: v,
				selected: tagSet.has(v),
			}))
		},

		selectAutocompleteItem(col, item) {
			if (item.selected) return
			if (!this.editorState.filters[col.field_id]) {
				this.editorState.filters[col.field_id] = new Set()
			}
			this.editorState.filters[col.field_id].add(item.value)
			this.autocompleteField = null
			this.autocompleteItems = []
			this.updateKey++
		},

		onAutocompleteKeydown(col, e) {
			if ((e.key === 'Enter' || e.key === ',') && e.target.value.trim()) {
				e.preventDefault()
				if (!this.editorState.filters[col.field_id]) {
					this.editorState.filters[col.field_id] = new Set()
				}
				this.editorState.filters[col.field_id].add(e.target.value.trim())
				e.target.value = ''
				this.autocompleteField = null
				this.autocompleteItems = []
				this.updateKey++
			} else if (e.key === 'Escape') {
				this.autocompleteField = null
				this.autocompleteItems = []
			} else if (e.key === 'Backspace' && !e.target.value) {
				const set = this.editorState.filters[col.field_id]
				if (set && set.size > 0) {
					const last = [...set].pop()
					set.delete(last)
					this.updateKey++
				}
			}
		},

		onAutocompleteBlur(col, e) {
			setTimeout(() => {
				if (e.target.value.trim()) {
					if (!this.editorState.filters[col.field_id]) {
						this.editorState.filters[col.field_id] = new Set()
					}
					this.editorState.filters[col.field_id].add(e.target.value.trim())
					e.target.value = ''
					this.updateKey++
				}
				this.autocompleteField = null
				this.autocompleteItems = []
			}, 150)
		},

		// Save / Delete
		async onSave() {
			const name = this.editorState.name.trim()
			if (!name) {
				alert('Vul een naam in voor de weergave')
				return
			}

			this.saving = true

			const columns = this.editorState.columns.map(col => ({
				field_id: col.field_id,
				field_name: col.field_name,
				field_label: col.field_label,
				visible: col.visible,
				filterable: col.filterable,
			}))

			const filters = {}
			for (const [fieldId, tagSet] of Object.entries(this.editorState.filters)) {
				if (tagSet.size > 0) {
					filters[fieldId] = [...tagSet].join(',')
				}
			}

			this.$emit('save', {
				name,
				is_default: this.editorState.isDefault,
				columns,
				filters,
				sort_field: this.editorState.sortField || null,
				sort_order: this.editorState.sortOrder || null,
			})
		},

		onDelete() {
			if (!confirm(`Weergave "${this.view.name}" verwijderen?`)) return
			this.$emit('delete', this.view)
		},
	},
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
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 0.08em;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
	margin: 16px 0 8px;
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
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
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
	font-size: 16px;
	flex-shrink: 0;
	width: 20px;
	text-align: center;
}

.mv-col-name {
	flex: 1;
	font-size: 13px;
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
	font-size: 13px;
	font-weight: 600;
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
	font-size: 12px;
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
	font-size: 13px;
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
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
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
</style>
