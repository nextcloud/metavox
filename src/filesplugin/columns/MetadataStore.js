/**
 * MetaVox MetadataStore — the only writer of metadataCache and permissionCache.
 *
 * Values come from three places: the nc:metavox property of listed nodes
 * (seedFromNode), local edits and pushes (applyMutation), and the API as a
 * fallback (fillFromApi). A recent edit always wins over older data for
 * PROTECT_MS, so navigating right after saving never shows the old value.
 */

import { decodeNode } from './DavMetadata.js'
import { metadataCache, permissionCache } from './MetaVoxState.js'

export const PROTECT_MS = 60000

// Node objects already applied. Never reset: the Files app may show old node
// objects again while it loads, and those must not overwrite newer values.
const seen = new WeakSet()
const mutatedAt = new Map() // fileId -> time of the last edit or push
const pending = new Map() // fileId -> {field: value}, for edits to files not cached yet
const truncated = new Map() // fileId -> Set of fields whose cached value is cut off
const noTeamFolder = new Set() // listed mounts of another storage: no values of ours

let storeGroupfolderId = null
let epoch = 0
let invalidatedAt = -Infinity
let clock = () => Date.now()

/** Tests only. */
export function _setClock(fn) {
	clock = fn
}

export const now = () => clock()
export const currentEpoch = () => epoch
export const isTruncated = (id, field) => truncated.get(id)?.has(field) === true
// Values present, a cut-off one included: filter and sort use what the grid shows.
export const hasValues = (id) => metadataCache.has(id)

/**
 * Whether the store holds these values for a file: '' and null alike, a
 * cut-off value as the start of the whole one.
 *
 * @param {number} id
 * @param {object} values field -> value
 * @return {boolean}
 */
export function matchesStored(id, values) {
	const entry = metadataCache.get(id)
	if (!entry) return false
	return Object.entries(values).every(([field, value]) => {
		const shown = String(value ?? '')
		const stored = String(entry[field] ?? '')
		return isTruncated(id, field) ? shown.startsWith(stored) : shown === stored
	})
}
export const hasNoTeamFolder = (id) => noTeamFolder.has(id)

const isProtected = (id) => (mutatedAt.get(id) ?? -Infinity) > clock() - PROTECT_MS

const listeners = new Set()

/**
 * Hear which files changed: edits, pushes and API answers. The sidebar
 * follows its file this way.
 *
 * @param {function(number[]): void} fn
 * @return {function(): void} stops listening
 */
export function onStoreChange(fn) {
	listeners.add(fn)
	return () => listeners.delete(fn)
}

function changed(ids) {
	for (const fn of listeners) {
		try {
			fn(ids)
		} catch (e) {
			console.error('MetaVox: store listener failed', e)
		}
	}
}

/**
 * Start over for another team folder. Safe to call more than once.
 *
 * @return {boolean} whether the team folder changed
 */
export function switchGroupfolder(groupfolderId) {
	if (groupfolderId === storeGroupfolderId) {
		return false
	}
	storeGroupfolderId = groupfolderId
	epoch++
	metadataCache.clear()
	permissionCache.clear()
	truncated.clear()
	noTeamFolder.clear()
	pending.clear()
	mutatedAt.clear()
	return true
}

function write(id, values, truncatedFields) {
	const patch = isProtected(id) ? pending.get(id) : undefined
	pending.delete(id)
	metadataCache.set(id, patch ? { ...values, ...patch } : values)
	const stillTruncated = patch ? truncatedFields.filter((field) => !(field in patch)) : truncatedFields
	if (stillTruncated.length) {
		truncated.set(id, new Set(stillTruncated))
	} else {
		truncated.delete(id)
	}
}

/**
 * @param {object} node a node from the Files app listing
 * @param {?number} listingGroupfolderId the team folder of the listed folder
 * @return {'seeded'|'protected'|'seen'|'absent'|'foreign'}
 */
export function seedFromNode(node, listingGroupfolderId) {
	const id = Number(node?.fileid)
	if (!id || listingGroupfolderId == null || listingGroupfolderId !== storeGroupfolderId) {
		// Not marked as seen: it is seeded once its team folder becomes active.
		return 'foreign'
	}
	if (seen.has(node)) {
		return 'seen'
	}
	seen.add(node)

	const decoded = decodeNode(node)
	if (!decoded || (decoded.gf !== null && decoded.gf !== listingGroupfolderId)) {
		return 'absent'
	}
	if (decoded.gf === null) {
		noTeamFolder.add(id)
	} else {
		noTeamFolder.delete(id)
	}
	// The server's verdict (admins included); never the node's own permissions.
	permissionCache.set(id, decoded.canWrite)
	if (metadataCache.has(id) && isProtected(id)) {
		return 'protected'
	}

	// Keep a complete cached value when the cut-off value is its start.
	const cached = metadataCache.get(id)
	const values = { ...decoded.values }
	const truncatedFields = []
	for (const field of decoded.truncated) {
		const full = cached?.[field]
		if (typeof full === 'string' && !isTruncated(id, field) && full.length > values[field].length && full.startsWith(values[field])) {
			values[field] = full
		} else {
			truncatedFields.push(field)
		}
	}
	write(id, values, truncatedFields)
	return 'seeded'
}

/**
 * A local edit (inline, fill handle, undo, sidebar) or a single-field push.
 * Merges into the cached entry; never creates a partial one.
 */
export function applyMutation(id, patch) {
	mutatedAt.set(id, clock())
	for (const field of Object.keys(patch)) {
		truncated.get(id)?.delete(field)
	}
	const entry = metadataCache.get(id)
	if (!entry) {
		pending.set(id, { ...pending.get(id), ...patch })
	} else {
		metadataCache.set(id, { ...entry, ...patch })
	}
	changed([id])
}

/** A push that changed these files without saying which fields. */
export function touch(ids) {
	const time = clock()
	for (const id of ids) {
		mutatedAt.set(id, time)
	}
	changed(ids)
}

/** After missed pushes: API answers to requests started before now are ignored. */
export function invalidateAll() {
	invalidatedAt = clock()
}

/**
 * Store an API answer.
 *
 * @param {object} data fileId -> {field: value, _permissions}
 * @param {number[]} okIds ids from requests that succeeded
 * @param {number} startedAt now() when the request started
 * @param {number} epochAtStart currentEpoch() when the request started
 */
export function fillFromApi(data, okIds, startedAt, epochAtStart) {
	if (epochAtStart !== epoch || startedAt < invalidatedAt) {
		return
	}
	for (const id of okIds) {
		const { _permissions, ...fields } = data[id] ?? {}
		permissionCache.set(id, ((_permissions ?? 0) & 2) !== 0)

		if ((mutatedAt.get(id) ?? -Infinity) > startedAt) {
			// Changed during the request: only complete the fields still cut
			// off, which have not changed since they were seeded.
			const entry = metadataCache.get(id)
			const cutOff = truncated.get(id)
			if (entry && cutOff) {
				const next = { ...entry }
				for (const field of cutOff) {
					if (field in fields) {
						next[field] = fields[field]
					} else {
						delete next[field]
					}
				}
				truncated.delete(id)
				metadataCache.set(id, next)
			}
			continue
		}
		write(id, fields, [])
	}
	changed(okIds)
}
