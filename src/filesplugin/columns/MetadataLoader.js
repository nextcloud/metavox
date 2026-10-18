/**
 * MetaVox — Metadata loading through the API
 *
 * The fallback for what the listing's nc:metavox property did not deliver.
 * Requests are bundled (debounced) and never duplicated while one is running;
 * answers go into the store through fillFromApi.
 */

import { fetchDirectoryMetadata } from './MetaVoxAPI.js'
import { getActiveGroupfolderId } from './MetaVoxState.js'
import { currentEpoch, fillFromApi, isTruncated, now } from './MetadataStore.js'

// Files per request: the server's limit (FilterController).
const CHUNK = 200

const inFlight = new Map() // fileId -> promise of the request that covers it
const queued = new Set()
let timer = null
let deadline = 0
let waiters = []

// Set by MetaVoxColumns: redraw the cells and re-apply filters and sorting.
let afterLoad = () => {}
export function setAfterLoad(fn) {
	afterLoad = fn
}

/**
 * Queue files for loading: 50 ms after the last call, at most 200 ms after
 * the first. Pass only files that need loading.
 *
 * @param {number|number[]} ids
 * @return {Promise<void>} resolves once the load that includes these files is done
 */
export function queueMetadataLoad(ids) {
	for (const id of [].concat(ids)) {
		if (id) {
			queued.add(Number(id))
		}
	}
	const done = new Promise((resolve) => waiters.push(resolve))
	const time = Date.now()
	if (!deadline) {
		deadline = time + 200
	}
	clearTimeout(timer)
	timer = setTimeout(flush, Math.min(50, Math.max(0, deadline - time)))
	return done
}

function flush() {
	const ids = [...queued]
	const done = waiters
	queued.clear()
	waiters = []
	timer = null
	deadline = 0
	loadViaApi(ids).finally(() => done.forEach((resolve) => resolve()))
}

/**
 * Load files now. Waits for requests already running for them, and never
 * resolves before every request it depends on is done.
 *
 * @param {number[]} ids
 * @param {{force?: boolean}} options force: request again even if one is running
 * @return {Promise<{started: boolean}>}
 */
export async function loadViaApi(ids, { force = false } = {}) {
	const groupfolderId = getActiveGroupfolderId()
	const wanted = [...new Set(ids.map(Number))].filter(Boolean)
	if (!groupfolderId || wanted.length === 0) {
		return { started: false }
	}

	const waits = new Set()
	const fresh = []
	for (const id of wanted) {
		if (!force && inFlight.has(id)) {
			waits.add(inFlight.get(id))
		} else {
			fresh.push(id)
		}
	}
	// One request per chunk, one after the other, each shown as soon as it is
	// in: big folders fill progressively instead of all at the end.
	const startedAt = now()
	const epoch = currentEpoch()
	let previous = Promise.resolve()
	for (let i = 0; i < fresh.length; i += CHUNK) {
		const chunk = fresh.slice(i, i + CHUNK)
		const request = previous.then(() => fetchDirectoryMetadata(groupfolderId, chunk)).then(({ data, okIds }) => {
			fillFromApi(data, okIds, startedAt, epoch)
			afterLoad()
		})
		previous = request.catch(() => {})
		chunk.forEach((id) => inFlight.set(id, request))
		previous.finally(() => {
			chunk.forEach((id) => {
				if (inFlight.get(id) === request) {
					inFlight.delete(id)
				}
			})
		})
		waits.add(request)
	}
	await Promise.allSettled([...waits])
	return { started: true }
}

/** For the editor, fill handle and save: load right away. */
export const ensureFull = (ids) => loadViaApi(ids)

/**
 * The gate for every write: make sure the field's value is complete.
 *
 * @return {Promise<boolean>} false when a value is still cut off; the caller must refuse
 */
export async function requireFull(ids, fieldName) {
	const cutOff = ids.filter((id) => isTruncated(id, fieldName))
	if (cutOff.length === 0) {
		return true
	}
	await ensureFull(cutOff)
	return cutOff.every((id) => !isTruncated(id, fieldName))
}
