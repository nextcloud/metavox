/**
 * MetaVox Bulk Metadata Action
 * Registers a file action for bulk editing metadata (Vue 3 version)
 *
 * On NC33, file actions are stored in window._nc_files_scope.v4_0.fileActions.
 * Our bundled @nextcloud/files writes to a different location, so we register
 * directly into the scoped globals (same approach as the sidebar tab fix).
 */

import { translate as t } from '@nextcloud/l10n'
import { createApp, h, ref } from 'vue'
import BulkMetadataModal from './BulkMetadataModal.vue'

// SVG icon for MetaVox (fox ears + document)
const metadataIconSvg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="-4 -3 32 30"><polygon fill="currentColor" points="6.4,0.9 2,10.1 11,10.1"/><polygon fill="currentColor" points="17.5,0.9 13,10.1 22,10.1"/><rect x="2" y="10.1" width="20" height="13.1" rx="0.5" fill="none" stroke="currentColor" stroke-width="1.8"/><line x1="4.9" y1="15" x2="18.8" y2="15" stroke="currentColor" stroke-width="1"/><line x1="4.9" y1="17.4" x2="18.8" y2="17.4" stroke="currentColor" stroke-width="1"/><line x1="4.9" y1="19.8" x2="13" y2="19.8" stroke="currentColor" stroke-width="1"/></svg>`

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
 * The file action definition (plain object matching NC33's validation requirements)
 */
const bulkMetadataAction = {
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

	enabled(nodes) {
		const nodeArray = extractNodes(nodes)
		if (nodeArray.length < 2) {
			return false
		}
		return nodeArray.every(node => node && (node.type === 'file' || node.type === 'folder'))
	},

	async exec() {
		return null
	},

	async execBatch(nodes) {
		const nodeArray = extractNodes(nodes)
		await openBulkMetadataModal(nodeArray)
		return nodeArray.map(() => null)
	},

	order: 50,
}

/**
 * Register the bulk metadata file action.
 * Writes directly to NC33's scoped globals, falls back to bundled API for older versions.
 */
export function registerBulkMetadataAction() {
	try {
		const scope = window._nc_files_scope?.v4_0
		if (scope) {
			// NC33: register directly into scoped globals
			scope.fileActions ??= new Map()
			if (!scope.fileActions.has(bulkMetadataAction.id)) {
				scope.fileActions.set(bulkMetadataAction.id, bulkMetadataAction)
			}
		} else {
			// NC31-32 fallback: use bundled @nextcloud/files
			import('@nextcloud/files').then(({ registerFileAction, FileAction }) => {
				const action = new FileAction(bulkMetadataAction)
				registerFileAction(action)
			})
		}
	} catch (error) {
		console.error('MetaVox: Failed to register bulk metadata action', error)
	}
}
