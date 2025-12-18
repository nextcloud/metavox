/**
 * MetaVox Bulk Metadata Action
 * Registers a file action for bulk editing metadata
 */

import { registerFileAction, FileAction, Permission } from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'

// SVG icon for MetaVox (original logo, properly sized)
const metadataIconSvg = `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path d="M2.5 2.5 C4.96 2.92 6.2 3.16 7.88 5.06 C9.51 6.77 9.51 6.77 12.44 6.94 C16.15 6.41 17.04 5.21 19.5 2.5 C20.49 2.5 21.48 2.5 22.5 2.5 C23.43 6.86 23.31 10.14 22.5 14.5 C22.83 14.83 23.16 15.16 23.5 15.5 C23.54 17.5 23.54 19.5 23.5 21.5 C22.08 22.18 22.08 22.18 20.63 22.88 C17.5 24.3 17.5 24.3 15.5 26.5 C11.62 27.02 10.02 26.88 6.81 24.56 C6.05 23.88 5.29 23.2 4.5 22.5 C3.51 22.17 2.52 21.84 1.5 21.5 C1.38 15.75 1.38 15.75 2.5 13.5 C2.24 11.49 1.99 9.47 1.66 7.47 C1.5 5.5 1.5 5.5 2.5 2.5 Z" fill="currentColor"/></svg>`

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
