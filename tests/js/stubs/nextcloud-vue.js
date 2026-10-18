/**
 * Lightweight stubs for @nextcloud/vue.
 *
 * The real library imports CSS and expects a running Nextcloud, which a unit
 * test neither has nor needs. Every Nc* export is a minimal Vue component:
 * inputs re-expose modelValue + the update event (so a wrapper's bind-down /
 * emit-up contract is observable); everything else renders its default slot.
 *
 * Named exports cover the commonly-used components; a Proxy fallback provides a
 * generic container stub for ANY other Nc* name a component imports, so tests
 * never fail on an unstubbed component. NcTextField deliberately does NOT
 * reproduce the real null->toString() crash (#134) — tests assert what our code
 * passes it (never null), which is the contract that matters.
 */
import { defineComponent, h } from 'vue'

function inputStub(name) {
	return defineComponent({
		name,
		inheritAttrs: false,
		props: {
			modelValue: { type: null, default: undefined },
			value: { type: null, default: undefined },
			checked: { type: null, default: undefined },
		},
		emits: ['update:model-value', 'update:checked', 'input', 'change'],
		setup(props, { emit, attrs, slots }) {
			return () => h('input', {
				...attrs,
				'data-stub': name,
				value: props.modelValue ?? props.value,
				onInput: (e) => {
					emit('update:model-value', e.target.value)
					emit('input', e.target.value)
				},
				onChange: (e) => {
					emit('update:checked', e.target.checked)
					emit('change', e.target.value)
				},
			}, slots.default ? slots.default() : [])
		},
	})
}

function containerStub(name) {
	return defineComponent({
		name,
		inheritAttrs: false,
		emits: ['click', 'close', 'update:open'],
		setup(_props, { slots, attrs, emit }) {
			return () => h('div', {
				...attrs,
				'data-stub': name,
				onClick: () => emit('click'),
			}, slots.default ? slots.default() : [])
		},
	})
}

// Named exports for the inputs (so `import { NcTextField }` works and is an input stub).
export const NcTextField = inputStub('NcTextField')
export const NcInputField = inputStub('NcInputField')
export const NcTextArea = inputStub('NcTextArea')
export const NcSelect = inputStub('NcSelect')
export const NcCheckboxRadioSwitch = inputStub('NcCheckboxRadioSwitch')
export const NcDateTimePicker = inputStub('NcDateTimePicker')
export const NcDateTimePickerNative = inputStub('NcDateTimePickerNative')

// Common containers.
export const NcButton = containerStub('NcButton')
export const NcModal = containerStub('NcModal')
export const NcDialog = containerStub('NcDialog')
export const NcActions = containerStub('NcActions')
export const NcActionButton = containerStub('NcActionButton')
export const NcActionSeparator = containerStub('NcActionSeparator')
export const NcLoadingIcon = containerStub('NcLoadingIcon')
export const NcEmptyContent = containerStub('NcEmptyContent')
export const NcNoteCard = containerStub('NcNoteCard')
export const NcAvatar = containerStub('NcAvatar')
export const NcAppContent = containerStub('NcAppContent')
export const NcAppSidebar = containerStub('NcAppSidebar')
export const NcContent = containerStub('NcContent')
export const NcAppSettingsDialog = containerStub('NcAppSettingsDialog')
export const NcAppSettingsSection = containerStub('NcAppSettingsSection')
export const NcPopover = containerStub('NcPopover')
export const NcCounterBubble = containerStub('NcCounterBubble')
export const NcChip = containerStub('NcChip')
