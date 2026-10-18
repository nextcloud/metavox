<template>
	<!-- NcDialog, as the guidelines require for a form: it brings the frame,
	     the focus trap, Escape and the button order. "normal" is the size core
	     uses for a form. -->
	<!-- TRANSLATORS Title of the dialog that saves the current filters as a view -->
	<NcDialog :name="t('metavox', 'Save as view')"
		:open="true"
		size="normal"
		:buttons="dialogButtons"
		@update:open="emit('close')">
		<div class="mv-save-body">
			<template v-if="canUpdate">
				<NcCheckboxRadioSwitch :model-value="mode"
					value="update"
					type="radio"
					name="mv_save_mode"
					@update:model-value="mode = $event">
					{{ t('metavox', 'Update “{name}”', { name: viewName }, undefined, PLAIN_TEXT) }} <!-- TRANSLATORS Radio option: save the filters into the current view; {name} is the view name, e.g. "Update “Open contracts”" -->
				</NcCheckboxRadioSwitch>
				<p class="mv-save-hint">
					{{ t('metavox', 'The filters replace those of this view. Everyone with access sees the change.') }}
				</p>

				<NcCheckboxRadioSwitch :model-value="mode"
					value="new"
					type="radio"
					name="mv_save_mode"
					@update:model-value="mode = $event">
					{{ t('metavox', 'Create a new view') }} <!-- TRANSLATORS Radio option: save the filters as a new view instead of updating the current one -->
				</NcCheckboxRadioSwitch>
				<p class="mv-save-hint">
					{{ t('metavox', 'The current view stays as it is.') }}
				</p>
			</template>

			<!-- Outside any view there is only one thing this dialog can do.
			     Manage rights are checked before the action is offered. -->
			<p v-else class="mv-save-hint mv-save-hint-lead">
				{{ t('metavox', 'The current filters are saved as a new view.') }}
			</p>

			<div v-if="mode === 'new'" class="mv-save-field">
				<NcTextField ref="nameField"
					:model-value="name"
					:label="nameLabel"
					:placeholder="namePlaceholder"
					:error="nameError !== ''"
					:helper-text="nameError"
					@update:model-value="onNameInput" />
			</div>

			<p class="mv-save-summary">{{ summary }}</p>
		</div>
	</NcDialog>
</template>

<script setup>
import { ref, computed, nextTick, onMounted } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { PLAIN_TEXT } from '../../utils/fieldValue.js'

const props = defineProps({
	/** The view the user is currently in, or null for the plain file list. */
	view: {
		type: Object,
		default: null,
	},
	/** Whether the user may change an existing view. */
	canManage: {
		type: Boolean,
		default: false,
	},
	/** [{ label, values: [] }], for the summary line. */
	filterSummary: {
		type: Array,
		default: () => [],
	},
})

const emit = defineEmits(['close', 'save'])

const canUpdate = computed(() => !!props.view && props.canManage)
const viewName = computed(() => props.view?.name || '')

const mode = ref(canUpdate.value ? 'update' : 'new')
const name = ref('')
const nameError = ref('')
const saving = ref(false)
const nameField = ref(null)
// TRANSLATORS Noun: label of the field that holds the name of a view
const nameLabel = t('metavox', 'Name')
// TRANSLATORS Placeholder of the field that holds the name of a view
const namePlaceholder = t('metavox', 'View name')

/** Name what is about to be stored: saving a shared view is not a blind act. */
const summary = computed(() => {
	const count = props.filterSummary.length
	if (count === 0) return t('metavox', 'No filters to save')
	const parts = props.filterSummary.map(f => {
		// TRANSLATORS %n is the number of chosen values of one filter, e.g. "2 values"; used in "Status (2 values)"
		const values = n('metavox', '%n value', '%n values', f.values.length)
		// TRANSLATORS: One filter in a list, e.g. "Status (2 values)".
		return t('metavox', '{field} ({values})', { field: f.label, values }, undefined, PLAIN_TEXT)
	})
	// TRANSLATORS %n is the number of filters, {list} a comma-separated list of them, e.g. "Saves 2 filters: Status (2 values), Owner (1 value)"
	return n('metavox', 'Saves %n filter: {list}', 'Saves %n filters: {list}', count, {
		list: parts.join(', '),
	}, PLAIN_TEXT)
})

const dialogButtons = computed(() => [
	{
		// TRANSLATORS Verb: dialog button that closes the dialog without saving
		label: t('metavox', 'Cancel'),
		callback: () => emit('close'),
	},
	{
		label: saving.value
			// TRANSLATORS Label of the save button while the view is being saved; keep it short
			? t('metavox', 'Saving …')
			// TRANSLATORS Verb: dialog button that saves the view
			: t('metavox', 'Save'),
		variant: 'primary',
		disabled: saving.value || (mode.value === 'new' && name.value.trim() === ''),
		callback: () => { onSave(); return false },
	},
])

function onNameInput(value) {
	name.value = value
	if (nameError.value && value.trim()) nameError.value = ''
}

function onSave() {
	if (mode.value === 'new' && !name.value.trim()) {
		nameError.value = t('metavox', 'Enter a name for the view')
		return
	}
	saving.value = true
	emit('save', {
		mode: mode.value,
		name: name.value.trim(),
	})
}

onMounted(async () => {
	if (mode.value === 'new') {
		await nextTick()
		nameField.value?.focus?.()
	}
})
</script>

<style scoped>
.mv-save-body {
	padding: 4px 0 8px;
}

/* Sits under its radio, so it binds tighter to it than the radios are
   spaced apart. */
.mv-save-hint {
	margin: 0 0 12px calc(var(--default-clickable-area) - 6px);
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small);
}
.mv-save-hint-lead {
	margin-inline-start: 0;
}

.mv-save-field {
	margin-block: 4px 12px;
}

.mv-save-summary {
	margin: 0;
	padding-block-start: 12px;
	border-block-start: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small);
}
</style>
