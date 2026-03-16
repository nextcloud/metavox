/**
 * MetaVox Filter Web Component
 *
 * Custom element <metavox-metadata-filter> used by NC33's filter bar UI.
 * Mounts a Vue 3 component (MetaVoxFilterPanel) inside to use native
 * Nextcloud Vue components like NcCheckboxRadioSwitch.
 */

import { createApp, h } from 'vue'
import MetaVoxFilterPanel from './MetaVoxFilterPanel.vue'

export class MetaVoxFilterElement extends HTMLElement {

	constructor() {
		super()
		this._filter = null
		this._vueApp = null
	}

	set filter(filterInstance) {
		this._filter = filterInstance
		this._mountVue()
	}

	get filter() {
		return this._filter
	}

	_mountVue() {
		// Destroy previous instance if any
		if (this._vueApp) {
			this._vueApp.unmount()
			this._vueApp = null
		}

		// Clear content
		this.innerHTML = ''

		// Create mount point
		const mountEl = document.createElement('div')
		this.appendChild(mountEl)

		const filter = this._filter
		this._vueApp = createApp({
			render: () => h(MetaVoxFilterPanel, { filter }),
		})
		this._vueApp.mount(mountEl)
	}

	connectedCallback() {
		// Re-mount Vue when element is re-attached to the DOM (e.g. filter panel reopened)
		if (this._filter && !this._vueApp) {
			this._mountVue()
		}
	}

	disconnectedCallback() {
		if (this._vueApp) {
			this._vueApp.unmount()
			this._vueApp = null
		}
	}
}

if (!customElements.get('metavox-metadata-filter')) {
	customElements.define('metavox-metadata-filter', MetaVoxFilterElement)
}
