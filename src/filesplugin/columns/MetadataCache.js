/**
 * MetaVox Metadata Cache
 *
 * Singleton cache for file metadata values used by file list columns.
 * Batch-loads metadata for visible files in a single API call.
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

class MetadataCache {

	constructor() {
		/** @type {Map<number, Object<string, string>>} fileId -> {fieldName: value} */
		this.cache = new Map()
		/** @type {number|null} */
		this.currentGroupfolderId = null
		/** @type {Promise|null} */
		this.pendingRequest = null
		/** @type {Set<number>} file IDs queued for loading */
		this.pendingFileIds = new Set()
		/** @type {number|null} debounce timer */
		this.debounceTimer = null
		/** @type {Array<Function>} callbacks waiting for load */
		this.waitingCallbacks = []
	}

	/**
	 * Set the current groupfolder context. Clears cache if changed.
	 * @param {number|null} groupfolderId
	 */
	setGroupfolder(groupfolderId) {
		if (this.currentGroupfolderId !== groupfolderId) {
			this.invalidate()
			this.currentGroupfolderId = groupfolderId
		}
	}

	/**
	 * Get cached metadata value for a file and field.
	 * @param {number} fileId
	 * @param {string} fieldName
	 * @returns {string|null}
	 */
	get(fileId, fieldName) {
		const fileData = this.cache.get(fileId)
		if (!fileData) return null
		return fileData[fieldName] ?? null
	}

	/**
	 * Check if we have cached data for a file.
	 * @param {number} fileId
	 * @returns {boolean}
	 */
	has(fileId) {
		return this.cache.has(fileId)
	}

	/**
	 * Queue file IDs for batch loading.
	 * Returns a promise that resolves when the batch load is complete.
	 * @param {number[]} fileIds
	 * @returns {Promise<void>}
	 */
	async loadForFiles(fileIds) {
		if (!this.currentGroupfolderId) return

		// Filter to only uncached file IDs
		const uncached = fileIds.filter(id => !this.cache.has(id))
		if (uncached.length === 0) return

		// Add to pending set
		uncached.forEach(id => this.pendingFileIds.add(id))

		// Debounce the actual fetch
		return new Promise(resolve => {
			this.waitingCallbacks.push(resolve)

			if (this.debounceTimer) {
				clearTimeout(this.debounceTimer)
			}

			this.debounceTimer = setTimeout(() => {
				this._executeBatchLoad()
			}, 50) // 50ms debounce - fast enough for UI, prevents flooding
		})
	}

	/**
	 * Execute the batch load for all pending file IDs.
	 * @private
	 */
	async _executeBatchLoad() {
		if (this.pendingFileIds.size === 0 || !this.currentGroupfolderId) {
			this._resolveWaiting()
			return
		}

		const fileIds = Array.from(this.pendingFileIds)
		this.pendingFileIds.clear()

		try {
			// Split into chunks of 200
			const chunks = []
			for (let i = 0; i < fileIds.length; i += 200) {
				chunks.push(fileIds.slice(i, i + 200))
			}

			for (const chunk of chunks) {
				const url = generateOcsUrl(
					'/apps/metavox/api/v1/groupfolders/{groupfolderId}/directory-metadata',
					{ groupfolderId: this.currentGroupfolderId },
				)

				const response = await axios.get(url, {
					params: { file_ids: chunk.join(',') },
				})

				const data = response.data?.ocs?.data || response.data || {}

				// Store in cache
				for (const [fileIdStr, metadata] of Object.entries(data)) {
					const fileId = parseInt(fileIdStr, 10)
					this.cache.set(fileId, metadata || {})
				}

				// Mark files with no metadata as empty (so we don't re-fetch)
				for (const fileId of chunk) {
					if (!this.cache.has(fileId)) {
						this.cache.set(fileId, {})
					}
				}
			}
		} catch (error) {
			console.error('MetaVox: Failed to load directory metadata', error)
			// Mark all as empty to prevent infinite retries
			for (const fileId of fileIds) {
				if (!this.cache.has(fileId)) {
					this.cache.set(fileId, {})
				}
			}
		}

		this._resolveWaiting()
	}

	/**
	 * Resolve all waiting callbacks.
	 * @private
	 */
	_resolveWaiting() {
		const callbacks = this.waitingCallbacks.splice(0)
		callbacks.forEach(cb => cb())
	}

	/**
	 * Clear all cached data.
	 */
	invalidate() {
		this.cache.clear()
		this.pendingFileIds.clear()
		if (this.debounceTimer) {
			clearTimeout(this.debounceTimer)
			this.debounceTimer = null
		}
		this._resolveWaiting()
	}

}

// Singleton instance
let instance = null

/**
 * Get the singleton MetadataCache instance.
 * @returns {MetadataCache}
 */
export function getMetadataCache() {
	if (!instance) {
		instance = new MetadataCache()
	}
	return instance
}
