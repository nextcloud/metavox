/**
 * MetaVox — Metadata Batch Loading
 *
 * Extracted from MetaVoxColumns.js. Handles debounced bulk metadata loading
 * for file rows, including the queue/flush mechanism and the full directory
 * metadata loader used during folder initialisation.
 */

import {
	metadataCache,
	loadQueue,
	getLoadTimer,
	setLoadTimer,
	getActiveGroupfolderId,
	getFlushDeadline,
	setFlushDeadline,
	permissionCache,
} from './MetaVoxState.js'
import { fetchDirectoryMetadata } from './MetaVoxAPI.js'
import { updateAllRowCells } from './ColumnDOM.js'
import { updateFilterCache } from './MetadataFilter.js'

// ── Callback for _findFilesList ────────────────────────────────

/** @type {function|null} */
let _findFilesList = null

/**
 * Register the _findFilesList function so loadAllMetadata can access
 * the NC33 FilesList Vue instance.
 *
 * @param {function} fn
 */
export function setFindFilesList(fn) { _findFilesList = fn }

// ── Callback for ensureSortBypass ──────────────────────────────

/** @type {function|null} */
let _ensureSortBypass = null

/**
 * Register the ensureSortBypass function.
 *
 * @param {function} fn
 */
export function setEnsureSortBypass(fn) { _ensureSortBypass = fn }

// ── Queue / flush mechanism ────────────────────────────────────

/**
 * Queue a file ID for metadata loading. If the file's metadata is already
 * cached, the call is a no-op.
 *
 * Uses debouncing: waits 50 ms after the last add, but never longer than
 * 200 ms total before flushing.
 *
 * @param {number} fileId
 */
export function queueMetadataLoad(fileId) {
	if (metadataCache.has(fileId)) return
	loadQueue.add(fileId)

	// Debounce: wait 50ms after last add, but never longer than 200ms total
	const timer = getLoadTimer()
	if (timer) clearTimeout(timer)
	const now = Date.now()
	if (!getFlushDeadline()) setFlushDeadline(now + 200)
	const remaining = Math.max(0, getFlushDeadline() - now)
	setLoadTimer(setTimeout(flushLoadQueue, Math.min(50, remaining)))
}

/**
 * Flush the pending load queue: fetch metadata for all queued file IDs
 * in one batch, update the cache, refresh the DOM, and re-apply
 * filters/sort.
 */
export async function flushLoadQueue() {
	if (loadQueue.size === 0 || !getActiveGroupfolderId()) return

	const ids = [...loadQueue]
	loadQueue.clear()
	setLoadTimer(null)
	setFlushDeadline(0)

	const data = await fetchDirectoryMetadata(getActiveGroupfolderId(), ids)

	for (const [fileId, fields] of Object.entries(data)) {
		const id = Number(fileId)
		if (fields._permissions !== undefined) {
			const NC_PERMISSION_UPDATE = 2
			permissionCache.set(id, (fields._permissions & NC_PERMISSION_UPDATE) !== 0)
			delete fields._permissions
		}
		metadataCache.set(id, fields)
	}

	for (const id of ids) {
		if (!metadataCache.has(id)) {
			metadataCache.set(id, {})
		}
	}

	updateAllRowCells()

	// Re-apply filters and sort after new metadata loaded
	if (_ensureSortBypass) _ensureSortBypass()
	updateFilterCache(metadataCache)

	// Clear loading indicator — visible row metadata is now loaded
	document.querySelector('.files-list')?.classList.remove('metavox-loading')

}

// ── Full directory metadata loader ─────────────────────────────

/**
 * Load metadata for ALL files in the current folder.
 *
 * Tries to get file IDs from the NC33 FilesList Vue instance's dirContents
 * first, falling back to DOM rows. Fetches in chunks of 200.
 *
 * @param {number} gfId Groupfolder ID
 */
export async function loadAllMetadata(gfId) {
	const filesList = _findFilesList ? _findFilesList() : null
	let fileIds = []
	let source = 'none'
	if (filesList?.dirContents) {
		fileIds = filesList.dirContents.map(n => n.fileid).filter(Boolean)
		source = 'dirContents'
	}
	if (fileIds.length === 0) {
		const rows = document.querySelectorAll('tr[data-cy-files-list-row]')
		fileIds = [...rows].map(r => Number(r.getAttribute('data-cy-files-list-row-fileid'))).filter(Boolean)
		source = 'DOM rows'
	}
	// Filter out already cached
	const totalFound = fileIds.length
	fileIds = fileIds.filter(id => !metadataCache.has(id))
	if (fileIds.length === 0) return

	const CHUNK = 200
	for (let i = 0; i < fileIds.length; i += CHUNK) {
		const chunk = fileIds.slice(i, i + CHUNK)
		try {
			const data = await fetchDirectoryMetadata(gfId, chunk)
			const NC_PERMISSION_UPDATE = 2
			for (const [fileId, fields2] of Object.entries(data)) {
				const id = Number(fileId)
				if (fields2._permissions !== undefined) {
					permissionCache.set(id, (fields2._permissions & NC_PERMISSION_UPDATE) !== 0)
					delete fields2._permissions
				}
				metadataCache.set(id, fields2)
			}
			for (const id of chunk) {
				if (!metadataCache.has(id)) metadataCache.set(id, {})
			}
			updateAllRowCells()
			updateFilterCache(metadataCache)
		} catch (e) { /* ignore */ }
	}
}
