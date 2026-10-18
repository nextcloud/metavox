/**
 * MetaVox Bulk Metadata Action
 * Registers a file action for bulk editing metadata (Vue 3 version)
 *
 * Registered through Nextcloud's official API. NC31/32 (@nextcloud/files 3) and
 * NC33+ (@nextcloud/files 4) each read their own list, so we use both.
 */

import { registerFileAction } from '@nextcloud/files'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { loadPart } from '../utils/updatedPage.js'
import { METAVOX_ICON_SVG } from '../utils/metavoxIcon.js'
import { PLAIN_TEXT } from '../utils/fieldValue.js'

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
 * The file action definition (plain object matching NC33's validation requirements)
 */
const bulkMetadataAction = {
	id: 'metavox-edit-metadata',
	displayName: (nodes) => {
		const nodeArray = extractNodes(nodes)
		if (nodeArray.length > 1) {
			// TRANSLATORS Action in the Files menu for several selected items; {count} is the number of selected files and folders, e.g. "Edit metadata (3 items)"
			return n('metavox', 'Edit metadata ({count} item)', 'Edit metadata ({count} items)', nodeArray.length, { count: nodeArray.length }, PLAIN_TEXT)
		}
		// TRANSLATORS Action in the Files menu that opens the dialog to edit the metadata of the selected files
		return t('metavox', 'Edit metadata')
	},
	// TRANSLATORS Tooltip of the Edit metadata action in the Files menu
	title: () => t('metavox', 'Edit metadata fields for selected files'),
	iconSvgInline: () => METAVOX_ICON_SVG,

	enabled(nodes) {
		const nodeArray = extractNodes(nodes)
		if (nodeArray.length < 2) {
			return false
		}
		// Not the saved searches: those folders hold no metadata.
		return nodeArray.every(node => node && (node.type === 'file' || node.type === 'folder')
			&& node.attributes?.['metavox-search'] === undefined)
	},

	async exec() {
		return null
	},

	async execBatch(nodes) {
		const nodeArray = extractNodes(nodes)
		// Through the Files plugin when it is there: Vue and the components
		// came with it. Loaded from here they would come a second time.
		const load = window._metavoxLoadBulk ?? (() => import('./BulkMetadataModalApp.js'))
		// When it does not load, loadPart says to reload; Files says nothing more.
		const modal = await loadPart(load).catch(() => null)
		if (modal) {
			await modal.openBulkMetadataModal(nodeArray)
		}
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
		// NC33+: @nextcloud/files 4 (also tells an already running Files app).
		registerFileAction(bulkMetadataAction)
	} catch (error) {
		console.error('MetaVox: Failed to register bulk metadata action', error)
	}
	// NC31/32: what registerFileAction of @nextcloud/files 3 does.
	window._nc_fileactions ??= []
	if (!window._nc_fileactions.some((action) => action.id === bulkMetadataAction.id)) {
		window._nc_fileactions.push(bulkMetadataAction)
	}
}
