/**
 * The contents of the saved-search folders: the searches themselves, and per
 * search the files it finds. Built like Nextcloud's Tags view
 * (apps/systemtags/src/services/systemtags.ts), with a MetaVox REPORT.
 */

import axios from '@nextcloud/axios'
import { getCurrentUser } from '@nextcloud/auth'
import { Folder, Permission } from '@nextcloud/files'
import { getClient, getDavNameSpaces, getDavProperties, getDefaultPropfind, getRemoteURL, getRootPath, resultToNode } from '@nextcloud/files/dav'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showInfo } from '../utils/toast.js'

const ROOT = '/metavox-searches'
const API = '/apps/metavox/api/user/metadata-searches'

// The folders' ids lie above every real file id: the URL carries them as
// fileid, and Nextcloud looks that up as a file on a full page load.
const VIRTUAL_ID_BASE = 2 ** 52

// Nextcloud's own client: it sends the request token, and headers.method
// becomes the HTTP method.
const client = getClient()

// Searches whose "newest files only" note was shown in this page.
const noted = new Set()

/** The user's saved searches: [{id, name, field, value, groupfolderId}]. */
export async function fetchSearches(signal) {
	try {
		const { data } = await axios.get(generateUrl(API), { signal })
		return Array.isArray(data?.searches) ? data.searches : []
	} catch (error) {
		// Files drops an aborted fetch silently only when it is an AbortError;
		// axios calls it CanceledError.
		if (signal?.aborted) {
			throw Object.assign(new Error('Aborted'), { name: 'AbortError' })
		}
		throw error
	}
}

export async function saveSearch(search) {
	const { data } = await axios.post(generateUrl(API), search)
	return data
}

export async function deleteSearch(id) {
	await axios.delete(generateUrl(`${API}/${Number(id)}`))
}

/** A saved search as a folder, like tagToNode in systemtags.ts. */
export function searchToNode(search) {
	return new Folder({
		id: VIRTUAL_ID_BASE + Number(search.id),
		source: `${getRemoteURL()}${ROOT}/${search.id}`,
		owner: String(getCurrentUser()?.uid ?? 'anonymous'),
		root: ROOT,
		displayname: search.name,
		// Read only: Nextcloud's open action needs it, nothing else may act on it.
		permissions: Permission.READ,
		attributes: {
			'metavox-search': search.id,
			'hide-download': true,
		},
	})
}

function reportBody(id) {
	return `<?xml version="1.0"?>
<nc:metavox-search ${getDavNameSpaces()}>
	<d:prop>${getDavProperties()}</d:prop>
	<nc:search-id>${Number(id)}</nc:search-id>
</nc:metavox-search>`
}

/**
 * The saved searches (path '/'), or the files one finds ('/<id>').
 *
 * @param {string} path
 * @param {{signal?: AbortSignal}} options Nextcloud calls it without on a drop
 */
export async function getContents(path = '/', options = {}) {
	const signal = options?.signal
	const searches = await fetchSearches(signal)
	if (path === '/') {
		return {
			folder: new Folder({
				id: 0,
				source: `${getRemoteURL()}${ROOT}`,
				owner: String(getCurrentUser()?.uid ?? 'anonymous'),
				root: ROOT,
				permissions: Permission.NONE,
			}),
			contents: searches.map(searchToNode),
			searches,
		}
	}

	const parts = path.split('/').filter(Boolean)
	const search = parts.length === 1 ? searches.find((s) => String(s.id) === parts[0]) : undefined
	if (!search && parts.length > 1) {
		// A real folder among the results, as Files asks for it when something
		// is dropped on it: its own listing.
		const response = await client.getDirectoryContents(`${getRootPath()}${path}`, {
			details: true,
			includeSelf: true,
			data: getDefaultPropfind(),
			signal,
		})
		const [self, ...rest] = response.data
		return { folder: resultToNode(self), contents: rest.map((stat) => resultToNode(stat)), searches }
	}
	if (!search) {
		throw new Error(t('metavox', 'This saved search does not exist.'))
	}
	const response = await client.getDirectoryContents(getRootPath(), {
		details: true,
		data: reportBody(search.id),
		headers: { method: 'REPORT' },
		signal,
	})
	if (response.headers?.['x-metavox-more'] && !noted.has(search.id)) {
		noted.add(search.id)
		showInfo(t('metavox', 'Showing the newest {count} files. Make the search narrower to see the rest.', { count: response.data.length }))
	}
	return {
		folder: searchToNode(search),
		contents: response.data.map((stat) => resultToNode(stat)),
		searches,
	}
}
