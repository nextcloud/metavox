<template>
	<section v-if="shown"
		id="mv-format-section"
		class="mv-fmt"
		aria-labelledby="mv-format-heading">
		<h3 id="mv-format-heading" class="mv-editor-section-title" tabindex="-1">
			<!-- TRANSLATORS Heading of the section in the view editor where cells get colored highlight rules -->
			{{ t('metavox', 'Highlight cells') }}
		</h3>
		<p class="mv-fmt-muted">
			{{ t('metavox', 'When a cell matches more than one rule, the top one counts.') }}
		</p>
		<p v-if="blocking" class="mv-fmt-reason">
			<!-- TRANSLATORS Shown when the view cannot be saved yet; {column} is the column name, e.g. "To save, finish or cancel the open rule for Due date." -->
			{{ t('metavox', 'To save, finish or cancel the open rule for {column}.', { column: formColumn?.field_label ?? '' }, undefined, PLAIN_TEXT) }}
		</p>
		<div class="hidden-visually" aria-live="polite" aria-atomic="true">
			{{ live }}
		</div>

		<p v-if="!readonly && shownColumns.length === 0" class="mv-fmt-muted">
			<!-- TRANSLATORS "Columns" is the heading of the column list section of the same panel; use the same translation -->
			{{ t('metavox', 'Make a column visible under Columns to highlight its cells.') }}
		</p>

		<details v-for="col in shownColumns"
			:key="col.field_id"
			class="mv-fmt-column"
			:open="opened.has(col.field_id) || undefined"
			@toggle="onToggle(col, $event)">
			<summary class="mv-fmt-summary" @click="onSummaryClick(col, $event)">
				<span class="mv-fmt-summary-text">{{ col.field_label }}</span>
				<span v-if="rowsOf(col).length" class="mv-fmt-count">
					<!-- TRANSLATORS Badge next to a column name; %n is the number of highlight rules of that column; keep it short -->
					{{ n('metavox', '%n rule', '%n rules', rowsOf(col).length) }}
				</span>
				<ChevronRightIcon :size="14" class="mv-fmt-chevron" />
			</summary>

			<RulePreview v-if="previewShown(col)" :field="col" :rules="previewRules(col)" />

			<ol class="mv-fmt-rules">
				<template v-for="(entry, index) in rowsOf(col)" :key="entry.key">
					<li v-if="newFormBefore(col, entry)" class="mv-fmt-rule">
						<RuleForm :ref="setFormRef"
							:field="col"
							:initial="form.initial"
							is-new
							:uid="formUid"
							@done="onDone(col, $event)"
							@cancel="onCancel(col)" />
					</li>
					<li class="mv-fmt-rule" :data-rule-key="entry.key">
						<RuleForm v-if="isEditing(col, entry)"
							:ref="setFormRef"
							:field="col"
							:initial="form.initial"
							:position="index + 1"
							:uid="formUid"
							@done="onDone(col, $event)"
							@cancel="onCancel(col)" />
						<template v-else>
							<div class="mv-fmt-line">
								<button v-if="!readonly && statusOf(col, entry) === 'edit'"
									:id="editId(col, entry)"
									type="button"
									class="mv-fmt-edit"
									:aria-describedby="noteOf(col, entry) ? noteId(col, entry) : undefined"
									@click="edit(col, entry)">
									<!-- TRANSLATORS Verb: screen-reader text of the button that opens a highlight rule for editing -->
									<span class="hidden-visually">{{ t('metavox', 'Edit') }}</span>
									<ToneSample :tone="entry.rule.tone" />
									<span class="mv-fmt-text">
										<strong v-if="titleOf(entry)" class="mv-state-name">{{ titleOf(entry) }}</strong>
										<span>{{ describeRule(entry.rule, col) }}</span>
									</span>
									<PencilIcon :size="16" class="mv-fmt-pencil" />
								</button>
								<div v-else class="mv-fmt-static">
									<ToneSample v-if="TONES.includes(entry.rule.tone)" :tone="entry.rule.tone" />
									<span class="mv-fmt-text">
										<strong v-if="titleOf(entry)" class="mv-state-name">{{ titleOf(entry) }}</strong>
										<span>{{ describeRule(entry.rule, col) }}</span>
									</span>
								</div>
								<NcActions v-if="!readonly"
									force-menu
									:aria-label="t('metavox', 'Move or delete rule {position} of {column}', { position: index + 1, column: col.field_label }, undefined, PLAIN_TEXT)"> <!-- TRANSLATORS Accessible name of the menu button of a highlight rule; {position} is the rule number, {column} the column name, e.g. "Move or delete rule 2 of Status" -->
									<NcActionButton v-if="index > 0" close-after-click @click="move(col, index, -1)">
										<template #icon>
											<ArrowUpIcon :size="20" />
										</template>
										{{ t('metavox', 'Move up') }} <!-- TRANSLATORS Verb: menu item that moves a highlight rule one place up -->
									</NcActionButton>
									<NcActionButton v-if="index < rowsOf(col).length - 1" close-after-click @click="move(col, index, 1)">
										<template #icon>
											<ArrowDownIcon :size="20" />
										</template>
										{{ t('metavox', 'Move down') }} <!-- TRANSLATORS Verb: menu item that moves a highlight rule one place down -->
									</NcActionButton>
									<NcActionButton close-after-click @click="remove(col, index)">
										<template #icon>
											<DeleteIcon :size="20" />
										</template>
										{{ t('metavox', 'Delete') }} <!-- TRANSLATORS Verb: menu item that deletes a highlight rule -->
									</NcActionButton>
								</NcActions>
							</div>
							<p v-if="noteOf(col, entry)" :id="noteId(col, entry)" class="mv-fmt-note">
								{{ noteOf(col, entry) }}
							</p>
						</template>
					</li>
				</template>
				<li v-if="newFormAtEnd(col)" class="mv-fmt-rule">
					<RuleForm :ref="setFormRef"
						:field="col"
						:initial="form.initial"
						is-new
						:uid="formUid"
						@done="onDone(col, $event)"
						@cancel="onCancel(col)" />
				</li>
			</ol>

			<div v-if="!readonly" class="mv-fmt-add" :data-add-for="col.field_id">
				<NcActions force-menu
					variant="tertiary"
					:disabled="col.rules.length >= MAX_RULES"
					:menu-name="t('metavox', 'Add rule for {column}', { column: col.field_label }, undefined, PLAIN_TEXT)"> <!-- TRANSLATORS Menu button that adds a highlight rule; {column} is the column name, e.g. "Add rule for Status" -->
					<template #icon>
						<PlusIcon :size="20" />
					</template>
					<NcActionButton v-for="id in presetsLeft(col)"
						:key="id"
						close-after-click
						@click="addPreset(col, id)">
						<template #icon>
							<ToneSample :tone="PRESETS[id].rule.tone" decorative />
						</template>
						{{ presetName(id) }}
					</NcActionButton>
					<NcActionSeparator v-if="presetsLeft(col).length" />
					<NcActionButton close-after-click @click="addCustom(col)">
						{{ t('metavox', 'Custom rule') }} <!-- TRANSLATORS Menu item that adds a highlight rule the user sets up, as opposed to a ready-made one -->
					</NcActionButton>
				</NcActions>
				<p v-if="col.rules.length >= MAX_RULES" class="mv-fmt-muted">
					{{ t('metavox', 'A column can have at most 10 rules') }}
				</p>
			</div>
		</details>

		<p v-if="!readonly && hiddenWithRules.length" class="mv-fmt-muted">
			<!-- TRANSLATORS {columns} is a comma-separated list of column names, e.g. "Hidden columns keep their rules: Status, Owner" -->
			{{ t('metavox', 'Hidden columns keep their rules: {columns}', { columns: hiddenWithRules.join(', ') }, undefined, PLAIN_TEXT) }}
		</p>
	</section>
</template>

<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionSeparator from '@nextcloud/vue/components/NcActionSeparator'
import NcActions from '@nextcloud/vue/components/NcActions'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import ArrowDownIcon from 'vue-material-design-icons/ArrowDown.vue'
import ArrowUpIcon from 'vue-material-design-icons/ArrowUp.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import { PRESETS, TONES, presetName, presetsFor } from '../../utils/valueOperators.js'
import {
	MAX_RULES, compileRules, customDraft, describeRule, presetDraft, presetInsertIndex,
	ownName, ruleDraft, ruleStatus,
} from '../../utils/valueRules.js'
import { PLAIN_TEXT } from '../../utils/fieldValue.js'
import { optionValues } from '../../utils/fieldOptions.js'
import RuleForm from './RuleForm.vue'
import RulePreview from './RulePreview.vue'
import ToneSample from './ToneSample.vue'

const props = defineProps({
	// The editor's columns, in Columns order; each has rules: [{key, rule}].
	columns: { type: Array, required: true },
	readonly: { type: Boolean, default: false },
})

let newKeys = 0
// The one open form: { fieldId, key (the rule edited, null for a new one), before, initial, presetId }.
const form = ref(null)
const formRef = ref(null)
const formUid = ref('')
const live = ref('')
const opened = reactive(new Set(props.columns.filter((c) => c.visible && c.rules.length).map((c) => c.field_id)))

const visibleColumns = computed(() => props.columns.filter((c) => c.visible))
// A member sees the rules that colour something, as a legend.
const legendColumns = computed(() => visibleColumns.value.filter((c) => compileRules(c.rules.map((r) => r.rule), c.field_type)))
const shownColumns = computed(() => (props.readonly ? legendColumns.value : visibleColumns.value))
// Read-only, the section only shows when there is a rule to read.
const shown = computed(() => !props.readonly || legendColumns.value.length > 0)
const hiddenWithRules = computed(() => props.columns.filter((c) => !c.visible && c.rules.length).map((c) => c.field_label))
const formColumn = computed(() => props.columns.find((c) => c.field_id === form.value?.fieldId) ?? null)

/** Save waits while the open rule is not valid. */
const blocking = computed(() => !!form.value && !!formRef.value && !formRef.value.result?.rule)

function rowsOf(col) {
	return props.readonly ? col.rules.filter((e) => statusOf(col, e) !== 'misfit') : col.rules
}
const statusOf = (col, entry) => ruleStatus(entry.rule, col.field_type)
// The menu name of a preset for who edits, the name for the viewer in the legend.
const titleOf = (entry) => ownName(entry.rule, props.readonly)
const editId = (col, entry) => `mv-rule-${col.field_id}-${entry.key}-edit`
const noteId = (col, entry) => `mv-rule-${col.field_id}-${entry.key}-note`

function noteOf(col, entry) {
	if (props.readonly) return ''
	// TRANSLATORS Note below a highlight rule that no longer fits the field, for example after the field type changed
	if (statusOf(col, entry) === 'misfit') return t('metavox', 'This rule does not work with this field and highlights nothing.')
	const options = optionValues(col.field_options).map((value) => value.toLowerCase())
	const used = [].concat(entry.rule.value ?? []).filter((v) => typeof v === 'string')
	if (['select', 'dropdown', 'multiselect', 'multi_select'].includes(col.field_type) && options.length
		&& used.some((v) => !options.includes(v.trim().toLowerCase()))) {
		return t('metavox', 'This rule uses an option the field no longer has.')
	}
	return ''
}

// Presets the column does not have yet, in menu order.
function presetsLeft(col) {
	const have = new Set(col.rules.map((e) => e.rule.preset).filter(Boolean))
	return presetsFor(col.field_type).filter((id) => !have.has(id))
}

const isEditing = (col, entry) => form.value?.fieldId === col.field_id && form.value.key === entry.key
const newFormBefore = (col, entry) => form.value?.fieldId === col.field_id && form.value.key === null && form.value.before === entry.key
const newFormAtEnd = (col) => form.value?.fieldId === col.field_id && form.value.key === null
	&& (form.value.before === null || !col.rules.some((e) => e.key === form.value.before))

function setFormRef(el) {
	formRef.value = el
}

function announce(text) {
	live.value = ''
	nextTick(() => { live.value = text })
}

function focusLater(find) {
	nextTick(() => requestAnimationFrame(() => find()?.focus()))
}
const addTrigger = (col) => () => document.querySelector(`[data-add-for="${col.field_id}"] button`)
const menuTrigger = (key) => () => document.querySelector(`li[data-rule-key="${key}"] .mv-fmt-line > :last-child button`)

/**
 * Leave the open form: a valid one is applied, an invalid one stays and
 * shows what is wrong.
 *
 * @return {boolean} whether the form is gone
 */
function leaveForm() {
	if (!form.value) return true
	if (formRef.value?.result?.rule) {
		const col = formColumn.value
		if (col) onDone(col, formRef.value.result.rule, false)
		else closeForm()
		return true
	}
	formRef.value?.onDone()
	return false
}

function openForm(col, state) {
	if (!leaveForm()) return
	formUid.value = `mv-rule-${col.field_id}-${state.key ?? 'new'}-${++newKeys}`
	form.value = { fieldId: col.field_id, before: null, presetId: null, ...state }
	opened.add(col.field_id)
}

function closeForm() {
	form.value = null
	formRef.value = null
}

function edit(col, entry) {
	openForm(col, { key: entry.key, initial: ruleDraft(entry.rule, col.field_type) })
}

function addPreset(col, id) {
	const index = presetInsertIndex(col.rules.map((e) => e.rule), id)
	openForm(col, { key: null, before: col.rules[index]?.key ?? null, presetId: id, initial: presetDraft(id, col.field_type) })
}

function addCustom(col) {
	openForm(col, { key: null, initial: customDraft(col.field_type) })
}

// Where a new rule goes: a preset in its order, else above the rule it was
// added before, else at the end. Asked when it is placed: a rule above it
// may have gone while the form was open.
function newRuleIndex(col, state, rule) {
	if (rule.preset) return presetInsertIndex(col.rules.map((e) => e.rule), rule.preset)
	const anchor = col.rules.findIndex((e) => e.key === state.before)
	return anchor === -1 ? col.rules.length : anchor
}

const previewShown = (col) => col.rules.length > 0 || form.value?.fieldId === col.field_id

// A column's rules for its preview, with the open rule as it stands now in
// its place. Until it is valid, it colours nothing.
function previewRules(col) {
	const rules = col.rules.map((e) => e.rule)
	const state = form.value
	if (state?.fieldId !== col.field_id) return rules
	const draft = formRef.value?.result?.rule ?? null
	if (state.key !== null) {
		const index = col.rules.findIndex((e) => e.key === state.key)
		if (index !== -1) rules.splice(index, 1, ...(draft ? [draft] : []))
	} else if (draft) {
		rules.splice(newRuleIndex(col, state, draft), 0, draft)
	}
	return rules
}

function onDone(col, rule, focus = true) {
	const state = form.value
	let key = state.key
	if (key === null) {
		key = `new-${++newKeys}`
		col.rules.splice(newRuleIndex(col, state, rule), 0, { key, rule })
	} else {
		const entry = col.rules.find((e) => e.key === key)
		// Unchanged keeps the stored object, so the column is not sent for nothing.
		if (entry && JSON.stringify(entry.rule) !== JSON.stringify(rule)) entry.rule = rule
	}
	closeForm()
	if (focus) focusLater(() => document.getElementById(`mv-rule-${col.field_id}-${key}-edit`))
}

function onCancel(col) {
	const key = form.value?.key
	closeForm()
	focusLater(key === null ? addTrigger(col) : () => document.getElementById(`mv-rule-${col.field_id}-${key}-edit`))
}

function move(col, index, step) {
	const [entry] = col.rules.splice(index, 1)
	col.rules.splice(index + step, 0, entry)
	// TRANSLATORS Screen-reader announcement after a highlight rule was moved; {position} is its new number, e.g. "Rule moved to position 2"
	announce(t('metavox', 'Rule moved to position {position}', { position: index + step + 1 }, undefined, PLAIN_TEXT))
	focusLater(menuTrigger(entry.key))
}

function remove(col, index) {
	const [entry] = col.rules.splice(index, 1)
	if (form.value?.key === entry.key) closeForm()
	// TRANSLATORS Screen-reader announcement after a highlight rule was deleted
	announce(t('metavox', 'Rule deleted'))
	const next = col.rules[index] ?? col.rules[index - 1]
	focusLater(next ? menuTrigger(next.key) : addTrigger(col))
}

// Folding a column with an open form leaves the form first.
function onSummaryClick(col, event) {
	if (form.value?.fieldId === col.field_id && opened.has(col.field_id) && !leaveForm()) event.preventDefault()
}
function onToggle(col, event) {
	if (event.target.open) opened.add(col.field_id)
	else opened.delete(col.field_id)
}

// Hiding the column of an open form drops that form.
watch(() => formColumn.value?.visible, (visible) => {
	if (form.value && visible === false) {
		const name = formColumn.value.field_label
		closeForm()
		// TRANSLATORS Screen-reader announcement; {column} is the column name, e.g. "The open rule for Status was closed without changes."
		announce(t('metavox', 'The open rule for {column} was closed without changes.', { column: name }, undefined, PLAIN_TEXT))
	}
})

defineExpose({ blocking, shown, applyOpenForm: leaveForm })
</script>

<style scoped>
.mv-fmt-muted,
.mv-fmt-note {
	margin: 4px 0;
	color: var(--color-text-maxcontrast);
}

.mv-fmt-reason {
	margin: 4px 0;
	color: var(--color-text-error, var(--color-error-text));
}

.mv-fmt-column {
	border-bottom: 1px solid var(--color-border);
}

.mv-fmt-column:last-of-type {
	border-bottom: none;
}

.mv-fmt-summary {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 10px 4px;
	min-height: 40px;
	cursor: pointer;
	list-style: none;
	font-weight: var(--font-weight-element, 500);
}

.mv-fmt-summary::-webkit-details-marker {
	display: none;
}

.mv-fmt-summary:hover {
	background: var(--color-background-hover);
	border-radius: var(--border-radius, 4px);
}

.mv-fmt-summary-text {
	flex: 1;
}

.mv-fmt-count {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.mv-fmt-chevron {
	transition: transform 0.15s;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

details.mv-fmt-column[open] .mv-fmt-chevron {
	transform: rotate(90deg);
}

.mv-fmt-rules {
	list-style: none;
	margin: 0;
	padding: 0;
}

.mv-fmt-rule {
	padding: 2px 0;
}

.mv-fmt-line {
	display: flex;
	align-items: center;
	gap: 4px;
}

.mv-fmt-edit,
.mv-fmt-static {
	display: flex;
	align-items: center;
	gap: 8px;
	flex: 1;
	min-width: 0;
	min-height: 44px;
	padding: 4px 8px;
	text-align: start;
	color: var(--color-main-text);
}

.mv-fmt-edit {
	border: 0;
	margin: 0;
	background: transparent;
	border-radius: var(--border-radius-element, 8px);
	cursor: pointer;
	font: inherit;
}

.mv-fmt-edit:hover,
.mv-fmt-edit:focus-visible {
	background: var(--color-background-hover);
}

.mv-fmt-text {
	display: flex;
	flex-wrap: wrap;
	gap: 0 6px;
	flex: 1;
	min-width: 0;
	overflow-wrap: anywhere;
}

.mv-fmt-pencil {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.mv-fmt-note {
	margin-inline-start: 40px;
}

.mv-fmt-add {
	padding: 2px 0 8px;
}
</style>
