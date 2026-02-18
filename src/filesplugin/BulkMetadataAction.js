/**
 * MetaVox Bulk Metadata Action
 * Registers a file action for bulk editing metadata (Vue 3 version)
 */

import { registerFileAction, FileAction, Permission } from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'
import { createApp, h, ref } from 'vue'

// SVG icon for MetaVox (original logo, properly sized)
const metadataIconSvg = `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path d="M2.5 2.5 C4.96 2.92 6.2 3.16 7.88 5.06 C9.51 6.77 9.51 6.77 12.44 6.94 C16.15 6.41 17.04 5.21 19.5 2.5 C20.49 2.5 21.48 2.5 22.5 2.5 C23.43 6.86 23.31 10.14 22.5 14.5 C22.83 14.83 23.16 15.16 23.5 15.5 C23.54 17.5 23.54 19.5 23.5 21.5 C22.08 22.18 22.08 22.18 20.63 22.88 C17.5 24.3 17.5 24.3 15.5 26.5 C11.62 27.02 10.02 26.88 6.81 24.56 C6.05 23.88 5.29 23.2 4.5 22.5 C3.51 22.17 2.52 21.84 1.5 21.5 C1.38 15.75 1.38 15.75 2.5 13.5 C2.24 11.49 1.99 9.47 1.66 7.47 C1.5 5.5 1.5 5.5 2.5 2.5 Z" fill="currentColor"/></svg>`

// Store for the modal app instance
let modalApp = null
let modalContainer = null

/**
 * Extract nodes array from NC33 ActionContext or legacy array format
 * NC33 (@nextcloud/files v4.x) passes { nodes, view, folder, contents }
 * NC31-32 passes Node[] directly
 * @param {Array|Object} input - Raw input from FileAction callback
 * @returns {Array} Array of Node objects
 */
function extractNodes(input) {
	if (Array.isArray(input)) {
		return input
	}
	if (input && typeof input === 'object' && Array.isArray(input.nodes)) {
		return input.nodes
	}
	if (input && typeof input === 'object' && (input.source || input.fileid)) {
		return [input]
	}
	return []
}

/**
 * Open the bulk metadata modal
 * @param {Array|Object} nodes - Array of file nodes or single node
 */
async function openBulkMetadataModal(nodes) {
	const nodeArray = extractNodes(nodes)

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

	// Dynamically import the modal component
	const BulkMetadataModal = (await import('./BulkMetadataModal.vue')).default

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

	modalApp.mount(modalContainer)
}

/**
 * Register the bulk metadata file action
 */
export function registerBulkMetadataAction() {
	try {
		const action = new FileAction({
			id: 'metavox-edit-metadata',
			displayName: (nodes) => {
				const nodeArray = extractNodes(nodes)
				if (nodeArray.length > 1) {
					return t('metavox', 'Edit Metadata ({count} items)', { count: nodeArray.length })
				}
				return t('metavox', 'Edit Metadata')
			},
			title: () => t('metavox', 'Edit metadata fields for selected files'),
			iconSvgInline: () => metadataIconSvg,

			// Enable only for 2+ files/folders (bulk action)
			enabled(nodes) {
				const nodeArray = extractNodes(nodes)

				// Require at least 2 items for bulk editing
				if (nodeArray.length < 2) {
					return false
				}

				// Show for all files and folders
				return nodeArray.every(node => node && (node.type === 'file' || node.type === 'folder'))
			},

			// Single file action - not used, bulk only
			async exec() {
				return null
			},

			// Bulk action for multiple files
			async execBatch(nodes) {
				const nodeArray = extractNodes(nodes)
				await openBulkMetadataModal(nodeArray)
				return nodeArray.map(() => null)
			},

			// Show in context menu (higher = lower in menu)
			order: 50,
		})

		registerFileAction(action)
	} catch (error) {
		console.error('MetaVox: Failed to register bulk metadata action', error)
	}
}
