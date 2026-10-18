/**
 * MetaVox — API call functions extracted from MetaVoxColumns.js.
 *
 * Each function talks to the MetaVox backend and returns plain data.
 * Global state is accessed via the getters/setters in MetaVoxState.js.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	getActiveColumnConfigs,
	getActiveGroupfolderId,
	metadataCache,
	setMetavoxGroupfolders,
	getMetavoxGroupfolders,
} from './MetaVoxState.js'
import { getListingFolderPath, getListingGroupfolderId } from './ListingSync.js'
import { applyMutation } from './MetadataStore.js'
import { pushUndo } from './UndoSupport.js'
import { errorText } from '../../utils/errorText.js'
import { translate as t } from '@nextcloud/l10n'
import { showError } from '../../utils/toast.js'

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
		const url = generateUrl(
			'/apps/metavox/api/groupfolders/{groupfolderId}/file-fields',
			{ groupfolderId },
		)
		const resp = await axios.get(url)
		return resp.data || []
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
 * @return {Promise<{data: Object, okIds: number[]}>} okIds: the files of chunks that succeeded
 */
export async function fetchDirectoryMetadata(groupfolderId, fileIds) {
	if (fileIds.length === 0) return { data: {}, okIds: [] }

	const url = generateUrl(
		'/apps/metavox/api/groupfolders/{groupfolderId}/directory-metadata',
		{ groupfolderId },
	)

	// Chunk to avoid 414 URI Too Long (max ~200 IDs per GET request)
	const CHUNK_SIZE = 200
	const data = {}
	const okIds = []

	for (let i = 0; i < fileIds.length; i += CHUNK_SIZE) {
		const chunk = fileIds.slice(i, i + CHUNK_SIZE)
		// All fields, so an entry is complete whatever view is shown.
		const params = { file_ids: chunk.join(',') }
		try {
			const resp = await axios.get(url, { params })
			Object.assign(data, resp.data || {})
			okIds.push(...chunk)
		} catch (e) {
			// The ids of a failed chunk stay missing, so they are loaded again later.
			console.error('MetaVox: Failed to fetch directory metadata chunk', e)
		}
	}

	return { data, okIds }
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
		const url = generateUrl('/apps/metavox/api/groupfolders')
		const resp = await axios.get(url, { params: { scope: 'member' } })
		setMetavoxGroupfolders(resp.data || [])
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

	// The longest mount point wins, so a team folder nested in another is found.
	let best = null
	for (const gf of gfs) {
		const mountPoint = gf.mount_point
		if ((path === mountPoint || path.startsWith(mountPoint + '/')) && (!best || mountPoint.length > best.mount_point.length)) {
			best = gf
		}
	}
	if (best) {
		return best.id
	}
	// Not in our list: trust the team folder the server reported for this listing.
	return getListingFolderPath() === '/' + path ? getListingGroupfolderId() : null
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

const fileMetadataUrl = (fileId) => generateUrl('/apps/metavox/api/files/{fileId}/metadata', { fileId })

const savesUnderWay = new Map() // fileId -> settles when its last save is done

/**
 * Settles when the saves of this file that are under way are done, so a
 * reload does not fetch the value from before the save.
 *
 * @param {number} fileId
 * @return {Promise<void>}
 */
export function whenSaved(fileId) {
	return savesUnderWay.get(fileId) ?? Promise.resolve()
}

/**
 * Everything the sidebar shows for a file. The server finds the team folder
 * and says whether the user may change the file, by the rule every save checks.
 *
 * @param {number} fileId
 * @param {{signal?: AbortSignal}} [options]
 * @return {Promise<{groupfolderId: number, editable: boolean, fields: Array<Object>, teamFolderFields: Array<Object>}>}
 */
export async function loadFileMetadata(fileId, { signal } = {}) {
	const { data } = await axios.get(fileMetadataUrl(fileId), { signal })
	return {
		groupfolderId: Number(data.groupfolder_id),
		editable: data.editable === true,
		fields: data.fields || [],
		teamFolderFields: data.team_folder_fields || [],
	}
}

/**
 * Save changed fields of one file in one request. The grid shows them right
 * away; when saving fails they go back and the error is thrown.
 *
 * @param {number} fileId
 * @param {Object<string, string>} patch field name -> new value
 * @param {{unlockField?: string}} [options] release the cell lock of this field too
 */
export async function saveFileFields(fileId, patch, { unlockField } = {}) {
	const payload = { metadata: patch }
	if (unlockField) {
		payload.unlock = true
		payload.unlock_field = unlockField
	}
	// Known as under way before the grid shows it: whoever hears of the change waits for it.
	const request = axios.post(fileMetadataUrl(fileId), payload)
	const settled = request.then(() => {}, () => {})
	savesUnderWay.set(fileId, settled)
	settled.then(() => {
		if (savesUnderWay.get(fileId) === settled) {
			savesUnderWay.delete(fileId)
		}
	})

	// Only files the grid holds: others belong to another team folder.
	const cached = metadataCache.get(fileId)
	const before = cached ? Object.fromEntries(Object.keys(patch).map((field) => [field, cached[field]])) : null
	if (cached) {
		applyMutation(fileId, patch)
		if (_updateAllRowCells) _updateAllRowCells()
	}

	try {
		await request
	} catch (e) {
		if (before) {
			applyMutation(fileId, before)
			if (_updateAllRowCells) _updateAllRowCells()
		}
		throw e
	}
}

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
 * @param {boolean} [options.unlock] - Release cell lock after save (combined save+unlock)
 */
export async function saveSingleField(fileId, fieldName, newValue, options = {}) {
	// The editor loaded the full value before opening, so undo gets the full old value.
	const oldValue = metadataCache.get(fileId)?.[fieldName]

	// Skip if value didn't change
	if (oldValue === newValue) return

	try {
		await saveFileFields(fileId, { [fieldName]: newValue }, { unlockField: options.unlock ? fieldName : undefined })
		// Show undo toast (unless suppressed by fill-handle)
		if (!options.skipUndo) {
			pushUndo([{ fileId, fieldName, oldValue }])
		}
	} catch (e) {
		showRefusal(e, fieldName)
	}
}

/** Why a value from the grid was not saved, as the server said it; the cell has its old value back. */
function showRefusal(error, fieldName) {
	const field = getActiveColumnConfigs()?.find((c) => c.field_name === fieldName)?.field_label || fieldName
	const reason = errorText(error, t('metavox', 'Could not save metadata. Try again.'))
	// TRANSLATORS Error after a value typed in the grid was not saved; {field} is the column name, {reason} why, e.g. "Budget not saved: Enter a number"
	showError(t('metavox', '{field} not saved: {reason}', { field, reason }))
}

/**
 * Save the same field+value to multiple files in one API call.
 * Used by fill-handle to avoid N separate requests.
 *
 * @param {number[]} fileIds
 * @param {string}   fieldName
 * @param {*}        value
 */
export async function saveBulkFields(fileIds, fieldName, value) {
	const activeGroupfolderId = getActiveGroupfolderId()
	if (!activeGroupfolderId || fileIds.length === 0) return

	// Show the new value right away; put the old values back if saving fails.
	const oldValues = fileIds.map((fileId) => [fileId, metadataCache.get(fileId)?.[fieldName]])
	for (const fileId of fileIds) {
		applyMutation(fileId, { [fieldName]: value })
	}
	if (_updateAllRowCells) _updateAllRowCells()

	// Single bulk API call
	try {
		await axios.post(
			generateUrl('/apps/metavox/api/files/bulk-metadata'),  // Internal route (FieldController)
			{
				fileIds,
				metadata: { [fieldName]: value },
				mergeStrategy: 'overwrite',
			},
		)
	} catch (e) {
		for (const [fileId, oldValue] of oldValues) {
			applyMutation(fileId, { [fieldName]: oldValue })
		}
		if (_updateAllRowCells) _updateAllRowCells()
		showRefusal(e, fieldName)
	}
}
