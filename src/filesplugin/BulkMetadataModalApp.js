/**
 * MetaVox bulk metadata modal — loaded when the bulk action runs, so the init
 * script that registers the action stays small.
 */

import { translate as t } from '@nextcloud/l10n'
import { createApp, h, ref } from 'vue'
import BulkMetadataModal from './BulkMetadataModal.vue'
import { watchLoading } from '../utils/updatedPage.js'

// Store for the modal app instance
let modalApp = null
let modalContainer = null

/**
 * Open the bulk metadata modal
 * @param {Array} nodeArray - The selected file nodes
 */
export async function openBulkMetadataModal(nodeArray) {
	if (nodeArray.length === 0) {
		return
	}

	// Convert nodes to file info format
	const files = nodeArray.map(node => {
		const fileid = node.fileid
		const basename = node.basename || node.displayname
		const source = node.source
		const nodeType = node.type || (node.mime === 'httpd/unix-directory' ? 'folder' : 'file')
		const attributes = node.attributes || {}

		// Extract path from source URL or attributes
		let nodePath = node.path || attributes.filename
		if (!nodePath && source) {
			const davMatch = source.match(/\/remote\.php\/dav\/files\/[^/]+(.*)/)
			if (davMatch) {
				nodePath = decodeURIComponent(davMatch[1] || '/')
			}
		}

		return {
			fileid,
			basename,
			path: nodePath,
			filename: nodePath,
			type: nodeType,
			attributes,
		}
	})

	// Create container if not exists
	if (!modalContainer) {
		modalContainer = document.createElement('div')
		modalContainer.id = 'metavox-bulk-modal-container'
		document.body.appendChild(modalContainer)
	}

	// Safe unmount function that handles tiptap cleanup errors
	const safeUnmount = () => {
		if (modalApp) {
			try {
				modalApp.unmount()
			} catch (e) {
				// Ignore unmount errors (tiptap cleanup issues in NcModal)
			}
			modalApp = null
		}
		if (modalContainer) {
			modalContainer.innerHTML = ''
		}
	}

	// Unmount existing app if present
	safeUnmount()

	// Create reactive show state
	const showModal = ref(true)

	// Create Vue 3 app
	modalApp = createApp({
		render() {
			return h(BulkMetadataModal, {
				show: showModal.value,
				files,
				onClose: () => {
					showModal.value = false
					// Unmount after animation
					setTimeout(safeUnmount, 300)
				},
				onSaved: () => {
					// Refresh the file list if possible
					if (window.OCA?.Files?.App?.fileList) {
						window.OCA.Files.App.fileList.reload()
					}
				},
			})
		},
	})

	// Add global translation function
	modalApp.config.globalProperties.t = t

	watchLoading(modalApp).mount(modalContainer)
}
