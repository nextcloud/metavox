/**
 * MetaVox — API call functions extracted from MetaVoxColumns.js.
 *
 * Each function talks to the MetaVox backend and returns plain data.
 * Global state is accessed via the getters/setters in MetaVoxState.js.
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import {
	getActiveColumnConfigs,
	getActiveGroupfolderId,
	metadataCache,
	setMetavoxGroupfolders,
	getMetavoxGroupfolders,
} from './MetaVoxState.js'
import { pushUndo } from './UndoSupport.js'

// ========================================
// Field / metadata fetching
// ========================================

/**
 * Fetch the file-level field definitions for a groupfolder.
 *
 * @param {number} groupfolderId
 * @return {Promise<Array<Object>>}
 */
export async function fetchAvailableFields(groupfolderId) {
	try {
		const url = generateOcsUrl(
			'/apps/metavox/api/v1/groupfolders/{groupfolderId}/file-fields',
			{ groupfolderId },
		)
		const resp = await axios.get(url)
		return resp.data?.ocs?.data || resp.data || []
	} catch (e) {
		console.error('MetaVox: Failed to fetch available fields', e)
		return []
	}
}

/**
 * Fetch metadata for a set of file IDs (chunked to avoid 414).
 *
 * @param {number}   groupfolderId
 * @param {number[]} fileIds
 * @return {Promise<Object>}
 */
export async function fetchDirectoryMetadata(groupfolderId, fileIds) {
	if (fileIds.length === 0) return {}

	const activeColumnConfigs = getActiveColumnConfigs()
	const visibleFields = activeColumnConfigs.map(c => c.field_name).filter(Boolean)
	const url = generateOcsUrl(
		'/apps/metavox/api/v1/groupfolders/{groupfolderId}/directory-metadata',
		{ groupfolderId },
	)

	// Chunk to avoid 414 URI Too Long (max ~200 IDs per GET request)
	const CHUNK_SIZE = 200
	const allData = {}

	for (let i = 0; i < fileIds.length; i += CHUNK_SIZE) {
		const chunk = fileIds.slice(i, i + CHUNK_SIZE)
		const params = { file_ids: chunk.join(',') }
		if (visibleFields.length > 0) {
			params.field_names = visibleFields.join(',')
		}
		try {
			const resp = await axios.get(url, { params })
			const data = resp.data?.ocs?.data || resp.data || {}
			Object.assign(allData, data)
		} catch (e) {
			console.error('MetaVox: Failed to fetch directory metadata chunk', e)
		}
	}

	return allData
}

// ========================================
// Views
// ========================================

/**
 * Fetch saved views for a groupfolder.
 *
 * @param {number} groupfolderId
 * @return {Promise<{views: Array<Object>, canManage: boolean}>}
 */
export async function fetchViews(groupfolderId) {
	try {
		const url = generateUrl(
			'/apps/metavox/api/groupfolders/{gfId}/views',
			{ gfId: groupfolderId },
		)
		const resp = await axios.get(url)
		// Response: { views: [...], can_manage: bool }
		const data = resp.data || {}
		return {
			views: data.views || [],
			canManage: data.can_manage === true,
		}
	} catch (e) {
		console.error('MetaVox: Failed to fetch views', e)
		return { views: [], canManage: false }
	}
}

// ========================================
// Filter values
// ========================================

/**
 * Fetch all unique filter values for the current groupfolder.
 *
 * @param {number}      groupfolderId
 * @param {number[]|null} fileIds  Optional subset of file IDs
 * @return {Promise<Object>}
 */
export async function fetchAllFilterValues(groupfolderId, fileIds = null) {
	try {
		const url = generateUrl(
			'/apps/metavox/api/groupfolders/{gfId}/filter-values',
			{ gfId: groupfolderId },
		)
		const body = {}
		if (fileIds && fileIds.length > 0) {
			body.file_ids = fileIds
		}
		const resp = await axios.post(url, body)
		return resp.data || {}
	} catch (e) {
		console.error('MetaVox: Failed to fetch filter values', e)
		return {}
	}
}

// ========================================
// Groupfolder loading & detection
// ========================================

/**
 * Ensure the list of groupfolders is loaded into window._metavoxGroupfolders.
 */
export async function loadGroupfolders() {
	if (getMetavoxGroupfolders()?.length >= 0) return

	// Skip if not logged in
	if (!document.querySelector('head[data-user]')?.dataset?.user) {
		setMetavoxGroupfolders([])
		return
	}

	try {
		const url = generateOcsUrl('/apps/metavox/api/v1/groupfolders')
		const resp = await axios.get(url)
		setMetavoxGroupfolders(resp.data?.ocs?.data || resp.data || [])
	} catch (e) {
		setMetavoxGroupfolders([])
	}
}

/**
 * Detect the current groupfolder from the URL's ?dir= parameter.
 *
 * @return {number|null}
 */
export function detectCurrentGroupfolder() {
	const params = new URLSearchParams(window.location.search)
	let dir = params.get('dir')

	if (!dir || dir === '/') return null

	const path = dir.startsWith('/') ? dir.substring(1) : dir
	const gfs = getMetavoxGroupfolders() || []

	for (const gf of gfs) {
		if (path === gf.mount_point || path.startsWith(gf.mount_point + '/')) {
			return gf.id
		}
	}
	return null
}

// ========================================
// Single-field save
// ========================================

/** @type {function|null} Callback to refresh all visible row cells. */
let _updateAllRowCells = null

/**
 * Register the DOM-refresh callback (set once by MetaVoxColumns.js).
 *
 * @param {function} fn
 */
export function setUpdateAllRowCells(fn) { _updateAllRowCells = fn }

/**
 * Save a single metadata field value for a file.
 *
 * Updates the local cache immediately, persists via API, and shows an
 * undo toast (unless `options.skipUndo` is true).
 *
 * @param {number} fileId
 * @param {string} fieldName
 * @param {*}      newValue
 * @param {Object} [options]
 * @param {boolean} [options.skipUndo]
 */
export async function saveSingleField(fileId, fieldName, newValue, options = {}) {
	const activeGroupfolderId = getActiveGroupfolderId()
	// Update cache immediately
	const meta = metadataCache.get(fileId) || {}
	const oldValue = meta[fieldName]

	// Skip if value didn't change
	if (oldValue === newValue) return

	meta[fieldName] = newValue
	metadataCache.set(fileId, meta)

	// Update the cell display
	if (_updateAllRowCells) _updateAllRowCells()

	// Save via API
	try {
		await axios.post(
			generateUrl('/apps/metavox/api/groupfolders/{groupfolderId}/files/{fileId}/metadata', {
				groupfolderId: activeGroupfolderId,
				fileId,
			}),
			{ metadata: { [fieldName]: newValue } },
		)

		// Dispatch event so sidebar stays in sync
		window.dispatchEvent(new CustomEvent('metavox:metadata:saved', {
			detail: { fileId, metadata: { ...meta } },
		}))

		// Show undo toast (unless suppressed by fill-handle)
		if (!options.skipUndo) {
			pushUndo([{ fileId, fieldName, oldValue }])
		}
	} catch (e) {
		console.error('MetaVox: Failed to save inline edit', e)
		// Revert on error
		meta[fieldName] = oldValue
		metadataCache.set(fileId, meta)
		if (_updateAllRowCells) _updateAllRowCells()
	}
}
