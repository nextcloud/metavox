<template>
	<!-- Escape is Cancel, and stops here: the dialog must not close with it.
	     An open select closes its own list on the first Escape. -->
	<form class="mv-rule-form"
		:aria-label="formName"
		novalidate
		@submit.prevent="onDone"
		@keydown.esc.stop.prevent="emit('cancel')">
		<NcSelect :model-value="currentCondition"
			:options="conditions"
			label="label"
			:input-id="`${uid}-condition`"
			:input-label="text.condition"
			:clearable="false"
			class="mv-rule-control"
			@update:model-value="onCondition" />

		<NcTextField v-if="input === 'days'"
			:id="`${uid}-value`"
			:model-value="draft.value"
			type="number"
			min="1"
			max="3650"
			step="1"
			:label="text.days"
			:error="valueError !== ''"
			:helper-text="valueError || t('metavox', 'Enter a whole number from 1 to 3650')"
			@update:model-value="setValue"
			@blur="touched.value = true" />

		<NcTextField v-else-if="input === 'number'"
			:id="`${uid}-value`"
			:model-value="draft.value"
			inputmode="decimal"
			:label="text.value"
			:error="valueError !== ''"
			:helper-text="valueError || text.numberHelper"
			@update:model-value="setValue"
			@blur="touched.value = true" />

		<NcTextField v-else-if="input === 'date'"
			:id="`${uid}-value`"
			:model-value="draft.value"
			type="date"
			:label="text.date"
			:error="valueError !== ''"
			:helper-text="valueError"
			@update:model-value="setValue"
			@blur="touched.value = true" />

		<NcTextField v-else-if="input === 'text'"
			:id="`${uid}-value`"
			:model-value="draft.value"
			:label="text.value"
			:error="valueError !== ''"
			:helper-text="valueError"
			@update:model-value="setValue"
			@blur="touched.value = true" />

		<NcSelect v-else-if="input === 'option' || input === 'options'"
			:model-value="selectedOptions"
			:options="optionItems"
			label="label"
			:multiple="input === 'options'"
			:input-id="`${uid}-value`"
			:input-label="input === 'options' ? text.values : text.value"
			:error="valueError !== ''"
			:helper-text="valueError"
			class="mv-rule-control"
			@update:model-value="onOptions"
			@close="touched.value = true" />

		<p v-if="hint" class="mv-rule-hint">
			{{ hint }}
		</p>

		<fieldset class="mv-rule-tones">
			<!-- TRANSLATORS Noun: heading of the color choice in a highlight rule -->
			<legend class="mv-field-label">{{ t('metavox', 'Color') }}</legend>
			<NcCheckboxRadioSwitch v-for="tone in TONES"
				:key="tone"
				:model-value="draft.tone"
				:value="tone"
				type="radio"
				:name="`${uid}-tone`"
				@update:model-value="draft.tone = $event">
				<span class="mv-rule-tone-label">
					<ToneSample :tone="tone" decorative />
					{{ toneName(tone) }}
				</span>
			</NcCheckboxRadioSwitch>
		</fieldset>

		<NcTextField :id="`${uid}-label`"
			:model-value="draft.label"
			:label="text.label"
			:error="labelError !== ''"
			:helper-text="labelError || labelHelper"
			@update:model-value="draft.label = $event" />

		<div class="mv-rule-preview">
			<!-- TRANSLATORS Noun: label in front of a sample of how the highlight rule will look -->
			<span class="mv-field-label">{{ t('metavox', 'Preview') }}</span>
			<span class="mv-rule-preview-line">
				<template v-if="result.rule">
					<ToneSample :tone="result.rule.tone" />
					<strong v-if="ownName(result.rule, false)" class="mv-state-name">{{ ownName(result.rule, false) }}</strong>
					<span>{{ describeRule(result.rule, field) }}</span>
				</template>
			</span>
		</div>

		<div class="mv-rule-buttons">
			<NcButton variant="tertiary" @click="emit('cancel')">
				{{ t('metavox', 'Cancel') }} <!-- TRANSLATORS Verb: button that closes the highlight rule form without keeping changes -->
			</NcButton>
			<NcButton variant="secondary" type="submit">
				{{ t('metavox', 'Done') }} <!-- TRANSLATORS Button that closes the highlight rule form and keeps the rule; keep it short -->
			</NcButton>
		</div>
		<p v-if="!result.rule" class="mv-rule-hint">
			{{ t('metavox', 'Finish or cancel this rule to save the view.') }}
		</p>
	</form>
</template>

<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { translate as t } from '@nextcloud/l10n'
import { TONES, conditionLabel, conditionsFor, toneName } from '../../utils/valueOperators.js'
import { describeRule, ownName, ruleFromDraft, stateName, switchCondition } from '../../utils/valueRules.js'
import { PLAIN_TEXT } from '../../utils/fieldValue.js'
import { includesTime, optionItems as itemsOf } from '../../utils/fieldOptions.js'
import ToneSample from './ToneSample.vue'

const props = defineProps({
	// The column: field_type, field_options, field_label.
	field: { type: Object, required: true },
	// The form's starting point (ruleDraft, presetDraft or customDraft).
	initial: { type: Object, required: true },
	isNew: { type: Boolean, default: false },
	position: { type: Number, default: 0 },
	// Prefix for the ids of the inputs.
	uid: { type: String, required: true },
})
const emit = defineEmits(['done', 'cancel'])

const type = computed(() => props.field.field_type)
const draft = reactive({ ...props.initial, value: Array.isArray(props.initial.value) ? [...props.initial.value] : props.initial.value })
const touched = reactive({ value: false, label: false })
const tried = ref(false)

// Labels of the form's inputs.
const text = {
	// TRANSLATORS Noun: label of the dropdown that picks the condition of a highlight rule
	condition: t('metavox', 'Condition'),
	// TRANSLATORS Label of the number field of a highlight rule such as "Within the next … days"
	days: t('metavox', 'Number of days'),
	// TRANSLATORS Noun: label of the date field of a highlight rule such as "Before a date"
	date: t('metavox', 'Date'),
	// TRANSLATORS Noun: label of the field that holds the value a highlight rule compares the cell with
	value: t('metavox', 'Value'),
	// TRANSLATORS Noun: label of the dropdown that holds the options a highlight rule compares the cell with
	values: t('metavox', 'Values'),
	// TRANSLATORS Label of the optional short text shown as a tooltip on the highlight mark in the cell
	label: t('metavox', 'Text by the icon (optional)'),
	// TRANSLATORS Helper text below the number field of a highlight rule; write the example number 10000 without a thousands separator
	numberHelper: t('metavox', 'Without thousands separators, for example 10000'),
}

const formName = computed(() => (props.isNew
	// TRANSLATORS Accessible name of the form for a new highlight rule; {column} is the column name, e.g. "New rule for Status"
	? t('metavox', 'New rule for {column}', { column: props.field.field_label }, undefined, PLAIN_TEXT)
	// TRANSLATORS Accessible name of the form for an existing highlight rule; {position} is its number, {column} the column name, e.g. "Rule 2 of Status"
	: t('metavox', 'Rule {position} of {column}', { position: props.position, column: props.field.field_label }, undefined, PLAIN_TEXT)))

const items = computed(() => itemsOf(props.field.field_options))
// A condition on options needs options; the one the rule already has stays.
const conditions = computed(() => conditionsFor(type.value)
	.filter((c) => (c.input !== 'option' && c.input !== 'options') || items.value.length > 0 || c.id === props.initial.condition)
	.map((c) => ({ id: c.id, label: conditionLabel(c.id), input: c.input })))
const currentCondition = computed(() => conditions.value.find((c) => c.id === draft.condition) ?? null)
const input = computed(() => currentCondition.value?.input)

// A stored value the field no longer offers is still shown, and kept.
const optionItems = computed(() => {
	const list = [...items.value]
	for (const value of [].concat(draft.value ?? [])) {
		if (value !== '' && !list.some((o) => o.value === value)) list.push({ value, label: value })
	}
	return list
})
const selectedOptions = computed(() => {
	if (input.value === 'options') return optionItems.value.filter((o) => (draft.value ?? []).includes(o.value))
	return optionItems.value.find((o) => o.value === draft.value) ?? null
})

const result = computed(() => ruleFromDraft(draft, type.value))
const shows = (part) => (tried.value || touched[part]) && result.value.part === part
const valueError = computed(() => (shows('value') ? result.value.message : ''))
const labelError = computed(() => (result.value.part === 'label' ? result.value.message : ''))

const withTime = computed(() => includesTime(props.field.field_options))
const hint = computed(() => {
	if (input.value === 'me') return t('metavox', 'Each person sees the files assigned to them.')
	if (input.value === 'today' && withTime.value) return t('metavox', 'Compares by day: a time earlier today counts as today.')
	return ''
})
const labelHelper = computed(() => {
	// TRANSLATORS Helper text below the optional text of a highlight rule; the text appears as a tooltip on the highlight mark in the cell
	if (!result.value.rule) return t('metavox', 'Shown when you point at the icon in the cell, for example what needs to happen: “Approve first”.')
	const name = stateName({ ...result.value.rule, label: undefined }, props.field)
	// TRANSLATORS Helper text below the optional text of a highlight rule; {name} is the tooltip shown when the field stays empty, e.g. "Overdue"
	return t('metavox', 'Shown when you point at the icon in the cell, for example what needs to happen: “Approve first”. Empty: “{name}”.', { name }, undefined, PLAIN_TEXT)
})

function onCondition(option) {
	if (!option) return
	Object.assign(draft, switchCondition(draft, option.id, type.value))
	touched.value = false
}
function setValue(value) {
	draft.value = value
}
function onOptions(value) {
	draft.value = input.value === 'options' ? (value ?? []).map((o) => o.value) : (value?.value ?? '')
}

function focusId(id) {
	nextTick(() => requestAnimationFrame(() => document.getElementById(id)?.focus()))
}

function onDone() {
	tried.value = true
	if (result.value.rule) {
		emit('done', result.value.rule)
		return
	}
	const part = { op: 'condition', value: 'value', label: 'label' }[result.value.part] ?? 'condition'
	focusId(`${props.uid}-${part}`)
}

onMounted(() => focusId(`${props.uid}-condition`))

defineExpose({ result, onDone })
</script>

<style scoped>
.mv-rule-form {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 8px 0 12px;
}

.mv-rule-control {
	inline-size: 100%;
	min-inline-size: 0;
}

.mv-rule-hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.mv-rule-tones {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(8.5rem, 1fr));
	border: 0;
	margin: 0;
	padding: 0;
}

.mv-rule-tones legend {
	padding: 0;
	margin-block-end: 2px;
}

.mv-rule-tone-label {
	display: inline-flex;
	align-items: center;
	gap: 6px;
}

.mv-rule-preview {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.mv-rule-preview-line {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px;
	min-block-size: 24px;
	overflow-wrap: anywhere;
}

.mv-rule-buttons {
	display: flex;
	flex-wrap: wrap;
	justify-content: flex-end;
	gap: 8px;
}
</style>
