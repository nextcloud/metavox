/**
 * MetaVox ListingSync — follows the Files app's folder listing.
 *
 * On every 'files:list:updated' (NC 31-35: emitted before the Files app filters
 * and draws) the listed nodes are seeded into the store from their nc:metavox
 * property, tagged with the listed folder's team folder.
 */

import { subscribe } from '@nextcloud/event-bus'
import { decodeNode, registerMetaVoxDavProperty } from './DavMetadata.js'
import { _findFilesList } from './FilesListRef.js'
import { seedFromNode } from './MetadataStore.js'

let listing = new Map() // fileId -> node of the current folder
let folder = null
let generation = 0
let onListing = () => {}

export const getListingNode = (id) => listing.get(Number(id))
export const getListingNodes = () => [...listing.values()]
export const getListingIds = () => [...listing.keys()]
export const listingGeneration = () => generation
export const getListingGroupfolderId = () => decodeNode(folder)?.gf ?? null
export const getListingFolderPath = () => folder?.path ?? null

// While the Files app fetches, it still shows the previous folder's nodes.
export const isListingLoading = () => _findFilesList()?.loading === true

/** @param {function({gf: ?number, loading: boolean})} fn called after each listing */
export function setListingHandler(fn) {
	onListing = fn
}

function handle(payload) {
	try {
		// In case the Files app created its property lists after we loaded.
		registerMetaVoxDavProperty()
		folder = payload?.folder ?? null
		listing = new Map()
		generation++
		const groupfolderId = getListingGroupfolderId()
		for (const node of payload?.contents ?? []) {
			const id = Number(node?.fileid)
			if (!id) {
				continue
			}
			listing.set(id, node)
			seedFromNode(node, groupfolderId)
		}
		onListing({ gf: groupfolderId, loading: isListingLoading() })
	} catch (e) {
		// Never break the Files app's own listing.
	}
}

export function startListingSync() {
	subscribe('files:list:updated', handle)
}

/** Take over a listing that was emitted before we subscribed. */
export function catchUp(filesList) {
	if (filesList?.dirContents) {
		handle({ folder: filesList.currentFolder, contents: filesList.dirContents })
	}
}

/** Seed the current listing again, e.g. once its team folder became active. */
export function reseedListing() {
	const groupfolderId = getListingGroupfolderId()
	for (const node of listing.values()) {
		seedFromNode(node, groupfolderId)
	}
}

/**
 * Let the Files app load the folder again (fresh PROPFIND, fresh nodes).
 *
 * @return {boolean} false when there is no team folder listing to reload
 */
export function refreshListing() {
	const filesList = _findFilesList()
	if (!filesList || typeof filesList.fetchContent !== 'function' || getListingGroupfolderId() === null) {
		return false
	}
	filesList.fetchContent()
	return true
}
