/**
 * MetaVox Bulk Metadata Action
 * Registers a file action for bulk editing metadata
 */

import { registerFileAction, FileAction, Permission } from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'

// SVG icon for metadata
const metadataIconSvg = `<svg version="1.1" xmlns="http://www.w3.org/2000/svg" width="24" height="24"><path d="M0 0 C2.45685425 0.42359556 3.6964912 0.65510363 5.375 2.5625 C7.01309099 4.27469613 7.01309099 4.27469613 9.9375 4.4375 C13.65445502 3.90650643 14.5402558 2.7142005 17 0 C17.99 0 18.98 0 20 0 C20.93405225 4.35891051 20.81144268 7.63849557 20 12 C20.33 12.33 20.66 12.66 21 13 C21.04080783 14.99958364 21.04254356 17.00045254 21 19 C19.576875 19.680625 19.576875 19.680625 18.125 20.375 C14.99686321 21.80238254 14.99686321 21.80238254 13 24 C9.12472131 24.51670383 7.52342441 24.37735248 4.3125 22.0625 C3.549375 21.381875 2.78625 20.70125 2 20 C1.01 19.67 0.02 19.34 -1 19 C-1.125 13.25 -1.125 13.25 0 11 C-0.25556108 8.98745646 -0.51448107 6.97446167 -0.84375 4.97265625 C-1 3 -1 3 0 0 Z " fill="currentColor" transform="translate(2,0)"/></svg>`

// Store for the modal instance
let modalInstance = null
let modalContainer = null

/**
 * Open the bulk metadata modal
 * @param {Array} nodes - Array of file nodes
 */
async function openBulkMetadataModal(nodes) {
	// Convert nodes to file info format
	const files = nodes.map(node => ({
		fileid: node.fileid,
		basename: node.basename,
		path: node.path,
		filename: node.path,
		type: node.type,
	}))

	// Dynamically import Vue and the modal component
	const Vue = (await import('vue')).default
	const BulkMetadataModal = (await import('./BulkMetadataModal.vue')).default

	// Create container if not exists
	if (!modalContainer) {
		modalContainer = document.createElement('div')
		modalContainer.id = 'metavox-bulk-modal-container'
		document.body.appendChild(modalContainer)
	}

	// Create or update modal instance
	if (modalInstance) {
		modalInstance.$destroy()
	}

	const ModalConstructor = Vue.extend(BulkMetadataModal)
	modalInstance = new ModalConstructor({
		propsData: {
			show: true,
			files,
		},
	})

	modalInstance.$on('close', () => {
		modalInstance.show = false
	})

	modalInstance.$on('saved', () => {
		// Refresh the file list if possible
		if (window.OCA?.Files?.App?.fileList) {
			window.OCA.Files.App.fileList.reload()
		}
	})

	modalInstance.$mount(modalContainer)
}

/**
 * Register the bulk metadata file action
 */
export function registerBulkMetadataAction() {
	const action = new FileAction({
		id: 'metavox-edit-metadata',
		displayName: () => t('metavox', 'Edit Metadata'),
		iconSvgInline: () => metadataIconSvg,

		// Enable for files and folders with write permission
		enabled(nodes) {
			// Must have at least one node
			if (!nodes || nodes.length === 0) {
				return false
			}

			// All nodes must have write permission
			return nodes.every(node => {
				return (node.permissions & Permission.UPDATE) !== 0
			})
		},

		// Single file action
		async exec(node) {
			await openBulkMetadataModal([node])
			return null
		},

		// Bulk action for multiple files
		async execBatch(nodes) {
			await openBulkMetadataModal(nodes)
			return nodes.map(() => null)
		},

		// Show in selection toolbar
		order: 50,
	})

	registerFileAction(action)
	console.log('✅ MetaVox: Bulk metadata action registered')
}
