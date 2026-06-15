/**
 * Imperatively open the metadata filter picker and resolve with the user's
 * selection (or null if dismissed). Mounts the Vue component into a throwaway
 * container, mirroring the BulkMetadataAction mount pattern.
 */

import { createApp, h } from 'vue'
import FilterPicker from './FilterPicker.vue'

/**
 * @return {Promise<{field: string, value: string, groupfolderId: ?number}|null>}
 */
export function openFilterPicker() {
	return new Promise((resolve) => {
		const container = document.createElement('div')
		document.body.appendChild(container)

		let settled = false
		const cleanup = () => {
			try {
				app.unmount()
			} catch (e) { /* ignore unmount noise */ }
			container.remove()
		}

		const app = createApp({
			render() {
				return h(FilterPicker, {
					onSelect: (selection) => {
						if (settled) {
							return
						}
						settled = true
						// Let the modal close animation start before tearing down.
						setTimeout(() => {
							cleanup()
							resolve(selection)
						}, 0)
					},
				})
			},
		})

		app.mount(container)
	})
}
