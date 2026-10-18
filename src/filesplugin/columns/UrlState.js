/**
 * The view and filters in the URL (mvview, mvfilter), read and written in one
 * place. A link to follow comes only from the page load and from back/forward
 * to another folder. Every other change of the URL is the Files app writing
 * its own, older copy of the query (Alt+Up, the Viewer, the sidebar, a row
 * click): never a link, and afterwards ours are put back, so a reload opens
 * the same view and filters.
 */

const VIEW = 'mvview'
const FILTERS = 'mvfilter'
// Ours in the history entry too: back/forward hands it over as it was, even
// when the Files app writes the URL before we hear the step.
const STATE = 'metavox'

/** The folder the URL shows. */
export const dirParam = () => new URLSearchParams(window.location.search).get('dir') || ''

/**
 * The view and filters the URL holds now.
 *
 * @return {{view: ?string, filters: ?string}}
 */
export function readLink() {
	const params = new URLSearchParams(window.location.search)
	return { view: params.get(VIEW), filters: params.get(FILTERS) }
}

// What we last wrote, and for which folder.
let written = null
// The link of the page or of a back/forward step, with its folder, until a
// team folder takes it.
let link = null

function put({ view, filters, dir }) {
	const params = new URLSearchParams(window.location.search)
	if (view == null) {
		params.delete(VIEW)
	} else {
		params.set(VIEW, view)
	}
	if (filters) {
		params.set(FILTERS, filters)
	} else {
		params.delete(FILTERS)
	}
	const query = params.toString()
	// The Files router keeps its own state (a key) in history: leave it, add ours.
	const state = history.state !== null && typeof history.state === 'object' ? history.state : {}
	history.replaceState({ ...state, [STATE]: { view, filters: filters || null, dir } }, '', window.location.pathname + (query ? '?' + query : '') + window.location.hash)
}

/**
 * Write the view and/or filters of the folder shown. A folder not written
 * yet starts without either, so an old one the router carried is dropped.
 *
 * @param {{view?: ?(string|number), filters?: ?string}} change
 */
export function writeUrl(change) {
	const dir = dirParam()
	written = { ...(written?.dir === dir ? written : { view: null, filters: null }), ...change, dir }
	if (written.view != null) written.view = String(written.view)
	put(written)
}

/** The Files app wrote its own copy in this folder: put ours back. */
export function restoreUrl() {
	if (!written || written.dir !== dirParam()) return
	const now = readLink()
	if (now.view !== written.view || (now.filters || null) !== (written.filters || null)) {
		put(written)
	}
}

/** Keep the URL's link until a team folder takes it. */
export function holdLink() {
	link = { ...readLink(), dir: dirParam() }
}

/**
 * The link to follow, once, and only in the folder it was for.
 *
 * @return {?{view: ?string, filters: ?string}}
 */
export function takeLink() {
	const taken = link
	link = null
	return taken && taken.dir === dirParam() ? { view: taken.view, filters: taken.filters } : null
}

/**
 * Call onFolderChange when the folder in the URL changes. Back/forward holds
 * that page's link; any other change of folder drops it. A change in the
 * same folder puts our view and filters back.
 *
 * @param {Function} onFolderChange
 * @return {Function} check now
 */
export function watchFolder(onFolderChange) {
	let lastDir = dirParam()
	// saved: what we had written in the history entry back/forward landed on.
	// The URL itself may already be rewritten by then: the Files app hears the
	// step first, and our filter can still write the old folder's filters.
	const check = (fromHistory = false, saved = null) => {
		const dir = dirParam()
		if (dir === lastDir) {
			restoreUrl()
			return
		}
		lastDir = dir
		if (fromHistory !== true) {
			link = null
		} else {
			link = saved?.dir === dir ? { view: saved.view, filters: saved.filters, dir } : { ...readLink(), dir }
		}
		onFolderChange()
	}
	window.addEventListener('popstate', (e) => check(true, e.state?.[STATE] ?? null))
	for (const name of ['pushState', 'replaceState']) {
		const original = history[name].bind(history)
		history[name] = function(...args) {
			original(...args)
			setTimeout(check, 100)
		}
	}
	return check
}

/** For tests: forget what was written and held. */
export function _reset() {
	written = null
	link = null
}
