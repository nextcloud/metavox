/**
 * Stub for a deep import of one @nextcloud/vue component.
 *
 * MetaVox imports components one at a time — `@nextcloud/vue/components/NcButton`
 * — so vitest maps every such path onto this module. A single module cannot
 * know which component was asked for, so the default export is one stub that
 * behaves acceptably for all of them: it renders its default slot, re-exposes
 * modelValue on an inner <input>, and re-emits the events our components
 * listen for. That is enough to assert what our own code passes down and how
 * it reacts to what comes back, which is what these tests are for.
 *
 * Where a test needs to tell two components apart, it stubs them by name
 * through mount()'s `global.stubs` instead.
 */
import { defineComponent, h } from 'vue'

const NcComponentStub = defineComponent({
	name: 'NcComponentStub',
	inheritAttrs: false,
	props: {
		modelValue: { type: null, default: undefined },
		value: { type: null, default: undefined },
		checked: { type: null, default: undefined },
		// NcDialog takes its footer as data rather than markup; a test that
		// asserts button order needs to see the list it was given.
		buttons: { type: Array, default: undefined },
	},
	emits: [
		'update:model-value', 'update:checked', 'update:open',
		'input', 'change', 'click', 'close', 'closing',
	],
	setup(props, { emit, attrs, slots }) {
		return () => {
			const children = slots.default ? slots.default() : []
			// Anything carrying a value renders a real input, so a test can type
			// into it and observe the event our component binds to.
			if (props.modelValue !== undefined || props.value !== undefined) {
				return h('div', { ...attrs, 'data-stub': 'nc' }, [
					h('input', {
						value: props.modelValue ?? props.value,
						onInput: (e) => {
							emit('update:model-value', e.target.value)
							emit('input', e.target.value)
						},
						onChange: (e) => {
							emit('update:checked', e.target.checked)
							emit('change', e.target.value)
						},
					}),
					...children,
				])
			}
			return h('div', {
				...attrs,
				'data-stub': 'nc',
				'data-buttons': props.buttons ? props.buttons.map((b) => b.label).join('|') : undefined,
				onClick: () => emit('click'),
			}, children)
		}
	},
})

export default NcComponentStub
