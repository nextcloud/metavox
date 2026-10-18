/**
 * The MetaVox check as a custom element, the way Nextcloud's Flow app takes a
 * check since NC 31 (CheckPlugin.element). The Flow app itself still runs on
 * Vue 2; our check is a Vue 3 component and gets its own app inside the
 * element, so neither needs the other's Vue.
 *
 * The Flow app sets the attributes model-value, operator and disabled, and
 * listens for update:model-value with the new value in detail[0].
 */

import { createApp, h, reactive } from 'vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import MetadataCheck from './MetadataCheck.vue'
import { watchLoading } from '../utils/updatedPage.js'

export const TAG_NAME = 'oca-metavox-metadata-check'

class MetadataCheckElement extends HTMLElement {

	static get observedAttributes() {
		return ['model-value']
	}

	constructor() {
		super()
		this._state = reactive({ value: '' })
		this._app = null
	}

	get modelValue() {
		return this._state.value
	}

	set modelValue(value) {
		this._state.value = value ?? ''
	}

	attributeChangedCallback(name, oldValue, newValue) {
		if (name === 'model-value') {
			this.modelValue = newValue
		}
	}

	connectedCallback() {
		if (this._app) {
			return
		}
		const state = this._state
		const mountPoint = document.createElement('div')
		this.appendChild(mountPoint)
		this._app = createApp({
			render: () => h(MetadataCheck, {
				value: state.value,
				onInput: (json) => {
					// Ours first, so the value coming back from the Flow app is no change.
					state.value = json
					this.dispatchEvent(new CustomEvent('update:model-value', { detail: [json] }))
				},
			}),
		})
		this._app.config.globalProperties.t = translate
		this._app.config.globalProperties.n = translatePlural
		watchLoading(this._app).mount(mountPoint)
	}

	disconnectedCallback() {
		this._app?.unmount()
		this._app = null
		this.replaceChildren()
	}

}

if (!customElements.get(TAG_NAME)) {
	customElements.define(TAG_NAME, MetadataCheckElement)
}
