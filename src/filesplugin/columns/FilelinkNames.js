/**
 * MetaVox FilelinkNames — current names of linked files, looked up with
 * Nextcloud's own WebDAV SEARCH. A file link holds only the file id; Nextcloud
 * checks access, so a file that is gone or hidden to the user is not found
 * and shows as unavailable.
 */

import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { displayName } from '../../components/fields/filelinkUtils.js'
import { loadPart } from '../../utils/updatedPage.js'

// Nextcloud's WebDAV client (about 120 KB) loads with the first file link
// shown, not with every Files page.
const loadDav = () => loadPart(() => import(/* webpackChunkName: "metavox-dav" */ '@nextcloud/files/dav'))

// Nextcloud refuses a search with more than 100 operators.
export const BATCH_SIZE = 90
const WAIT_MS = 50

const names = new Map() // fileId => current name
const missing = new Set() // not found: gone, or hidden to this user
const asked = new Set() // looked up once per page session, found or not
let queue = []
let timer = null
const listeners = new Set()

export const getFilelinkName = (fileId) => names.get(Number(fileId))
export const isFilelinkMissing = (fileId) => missing.has(Number(fileId))

/** What a link shows: its name, '…' while looking it up, or that the file is unavailable to you. */
export function filelinkLabel(token) {
	if (token.fileId == null) return { label: displayName(token), title: token.path, missing: false }
	const name = getFilelinkName(token.fileId)
	if (name) return { label: name, title: name, missing: false }
	if (isFilelinkMissing(token.fileId)) {
		// TRANSLATORS Shown in a cell instead of a linked file's name when the file was deleted or you have no access to it
		const text = t('metavox', 'Unavailable file')
		return { label: text, title: text, missing: true }
	}
	return { label: '\u2026', title: '', missing: false }
}

export function filelinkHref(token) {
	if (token.fileId != null) return generateUrl('/f/{fileId}', { fileId: token.fileId })
	const dir = token.path.substring(0, token.path.lastIndexOf('/'))
	return generateUrl('/apps/files/?dir={dir}&openfile={file}', { dir, file: token.path })
}

/** Called with the ids whose lookup finished, so what shows them can be drawn again. Returns a stop function. */
export function onFilelinkNames(fn) {
	listeners.add(fn)
	return () => listeners.delete(fn)
}

/** Look up a linked file's name, bundled with the other cells drawn right now. */
export function requestFilelinkName(fileId) {
	const id = Number(fileId)
	if (!id || asked.has(id)) return
	asked.add(id)
	queue.push(id)
	timer ??= setTimeout(flush, WAIT_MS)
}

export function buildSearch(rootPath, ids) {
	const where = ids.map((id) => `<d:eq><d:prop><oc:fileid/></d:prop><d:literal>${Number(id)}</d:literal></d:eq>`).join('')
	return '<?xml version="1.0" encoding="UTF-8"?>'
		+ '<d:searchrequest xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:basicsearch>'
		+ '<d:select><d:prop><oc:fileid/><d:displayname/></d:prop></d:select>'
		+ `<d:from><d:scope><d:href>${rootPath}/</d:href><d:depth>infinity</d:depth></d:scope></d:from>`
		+ `<d:where>${ids.length > 1 ? `<d:or>${where}</d:or>` : where}</d:where>`
		+ '<d:orderby/></d:basicsearch></d:searchrequest>'
}

async function flush() {
	const ids = queue
	queue = []
	timer = null
	for (let i = 0; i < ids.length; i += BATCH_SIZE) {
		const batch = new Set(ids.slice(i, i + BATCH_SIZE))
		try {
			const { getClient, getRootPath } = await loadDav()
			const { data } = await getClient().search('/', { details: true, data: buildSearch(getRootPath(), [...batch]) })
			for (const result of data.results) {
				const id = Number(result.props?.fileid)
				if (batch.has(id)) names.set(id, result.basename)
			}
		} catch (e) {
			// No retry in this page session: the links show as unavailable.
			console.error('MetaVox: Could not look up file-link names', e)
		}
		for (const id of batch) {
			if (!names.has(id)) missing.add(id)
		}
		for (const fn of listeners) fn([...batch])
	}
}
