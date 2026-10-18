/**
 * MetaVox's metadata panel for other apps, as <metavox-metadata-form>.
 *
 * The same panel the Files sidebar shows, so a host gets the pickers, the
 * edit dialog, the team folder fields and the "Linked from" list without a
 * copy of its own. A custom element rather than a Vue component: two Vue
 * instances in one render are not valid, and a tag name ties the host to
 * nothing (NC 33 settled this for sidebar tabs and profile sections).
 *
 *   const el = document.createElement('metavox-metadata-form')
 *   el.fileId = node.fileid
 *   container.appendChild(el)
 *
 * The server finds the team folder and decides whether the user may edit,
 * by the same rule every save checks; the host does not pass either.
 *
 * Events, bubbling: metavox:loaded { fileId, fields },
 * metavox:saved { fileId, metadata }, metavox:error { message }.
 */

// Chunks load from MetaVox's js folder, not from the host app's page.
// eslint-disable-next-line camelcase
__webpack_public_path__ = document.querySelector('script[src*="metavox/js/metavox-embed"]')?.src?.replace(/metavox-embed\.js.*$/, '') || '/apps/metavox/js/'

import { createApp, h, reactive } from 'vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import FilesSidebarTab from '../filesplugin/FilesSidebarTab.vue'
import { watchLoading } from '../utils/updatedPage.js'

const TAG_NAME = 'metavox-metadata-form'

class MetaVoxMetadataFormElement extends HTMLElement {

	static get observedAttributes() {
		return ['file-id']
	}

	constructor() {
		super()
		this._state = reactive({ fileId: null })
		this._app = null
	}

	get fileId() {
		return this._state.fileId
	}

	set fileId(value) {
		const id = Number(value)
		this._state.fileId = Number.isInteger(id) && id > 0 ? id : null
	}

	// For hosts that render the element in a template.
	attributeChangedCallback(name, oldValue, newValue) {
		if (name === 'file-id' && oldValue !== newValue) {
			this.fileId = newValue
		}
	}

	connectedCallback() {
		if (this._app) {
			return
		}
		const state = this._state
		const forward = (name) => (detail) => {
			this.dispatchEvent(new CustomEvent(name, { detail, bubbles: true }))
		}
		const mountPoint = document.createElement('div')
		this.appendChild(mountPoint)
		this._app = createApp({
			// A new file id reloads the panel through its own watcher.
			render: () => h(FilesSidebarTab, {
				fileInfo: state.fileId ? { id: state.fileId, type: 'file' } : null,
				embedded: true,
				onLoaded: forward('metavox:loaded'),
				onSaved: forward('metavox:saved'),
				onError: (message) => forward('metavox:error')({ message }),
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
	customElements.define(TAG_NAME, MetaVoxMetadataFormElement)
}
