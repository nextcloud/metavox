/**
 * "Metadata searches" in the Files navigation: each saved search as a folder
 * of the files it finds. Built like Nextcloud's Tags view
 * (apps/systemtags/src/files_views/systemtagsView.ts), registered from the
 * init script so it exists before the Files app routes.
 *
 * Nextcloud 33 and newer: @nextcloud/files 4 keeps the navigation where they
 * read it. Nextcloud 31/32 don't show the entry, nor does any Nextcloud where
 * the admin left the folders off.
 */

import { emit } from '@nextcloud/event-bus'
import { DefaultType, FileType, getNavigation, registerFileAction, View } from '@nextcloud/files'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { ncMajor } from '../ncVersion.js'
import { loadPart } from '../utils/updatedPage.js'
import searchIcon from './searchView.svg'

export const SEARCH_VIEW_ID = 'metavox-searches'

// Nextcloud's DAV client loads only when the view opens.
const loadService = () => loadPart(() => import(/* webpackChunkName: "metavox-searches" */ './searchService.js'))

const trashIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M9,3V4H4V6H5V19A2,2 0 0,0 7,21H17A2,2 0 0,0 19,19V6H20V4H15V3H9M7,6H17V19H7V6M9,8V17H11V8H9M13,8V17H15V8H13Z"/></svg>'

const isSearch = (node) => node?.attributes?.['metavox-search'] !== undefined

/** Where a file is: results come from every team folder. */
const locationColumn = {
	id: 'location',
	title: t('metavox', 'Location'),
	// Nextcloud 33.0.0 to 33.0.3 pass {nodes} instead of the node.
	render(arg) {
		const node = arg?.nodes?.[0] ?? arg
		const span = document.createElement('span')
		if (!isSearch(node)) {
			span.textContent = node?.dirname ?? ''
		}
		return span
	},
	sort: (a, b) => a.dirname.localeCompare(b.dirname),
}

/** A folder among the results opens where it is, as in the Tags view. */
const openInFilesAction = {
	id: 'metavox:searches-open-in-files',
	displayName: () => t('metavox', 'Open in Files'),
	iconSvgInline: () => '',
	enabled: ({ nodes, view }) => view.id === SEARCH_VIEW_ID && nodes.length === 1
		&& !isSearch(nodes[0]) && nodes[0].type === FileType.Folder,
	async exec({ nodes }) {
		window.OCP.Files.Router.goToRoute(null, { view: 'files', fileid: String(nodes[0].fileid) }, { dir: nodes[0].path, openfile: 'true' })
		return null
	},
	// Before Nextcloud's open-folder action.
	order: -1000,
	default: DefaultType.HIDDEN,
}

const deleteSearchAction = {
	id: 'metavox:delete-saved-search',
	displayName: () => t('metavox', 'Delete saved search'),
	iconSvgInline: () => trashIcon,
	enabled: ({ nodes, view }) => view.id === SEARCH_VIEW_ID && nodes.length === 1 && isSearch(nodes[0]),
	async exec({ nodes }) {
		const node = nodes[0]
		const { deleteSearch } = await loadService()
		try {
			await deleteSearch(node.attributes['metavox-search'])
		} catch (error) {
			return false
		}
		emit('files:node:deleted', node)
		const navigation = getNavigation()
		const child = `${SEARCH_VIEW_ID}-${node.attributes['metavox-search']}`
		if (navigation.views.some((view) => view.id === child)) {
			navigation.remove(child)
		}
		return true
	},
	order: 100,
}

/** One entry per saved search under ours, as Favorites does with its folders. */
function syncChildViews(searches) {
	const navigation = getNavigation()
	const wanted = new Set(searches.map((search) => `${SEARCH_VIEW_ID}-${search.id}`))
	for (const view of [...navigation.views]) {
		if (view.parent === SEARCH_VIEW_ID && !wanted.has(view.id)) {
			navigation.remove(view.id)
		}
	}
	searches.forEach((search, index) => {
		const id = `${SEARCH_VIEW_ID}-${search.id}`
		if (navigation.views.some((view) => view.id === id)) {
			return
		}
		navigation.register(new View({
			id,
			name: search.name,
			icon: searchIcon,
			order: index,
			// A view with params only leads to ours, with this folder open.
			params: { view: SEARCH_VIEW_ID, dir: `/${search.id}` },
			parent: SEARCH_VIEW_ID,
			columns: [],
			getContents,
		}))
	})
}

async function getContents(path = '/', options = {}) {
	const service = await loadService()
	const { folder, contents, searches } = await service.getContents(path, options)
	syncChildViews(searches)
	return { folder, contents }
}

export function registerSearchView() {
	// Whether the admin turned them on comes with the page: nothing to ask.
	if (ncMajor() < 33 || loadState('metavox', 'search-folders', false) !== true) {
		return false
	}
	const navigation = getNavigation()
	if (navigation.views.some((view) => view.id === SEARCH_VIEW_ID)) {
		return true
	}
	navigation.register(new View({
		id: SEARCH_VIEW_ID,
		name: t('metavox', 'Metadata searches'),
		caption: t('metavox', 'Your saved metadata searches and the files they find.'),
		// Shown for an empty list of searches and for a search without files.
		emptyTitle: t('metavox', 'Nothing found'),
		emptyCaption: t('metavox', 'Your saved metadata searches show here, each with the files it finds. Save one with the metadata filter in the search bar.'),
		icon: searchIcon,
		// Right after Tags.
		order: 26,
		columns: [locationColumn],
		getContents,
		async loadChildViews() {
			const { fetchSearches } = await loadService()
			syncChildViews(await fetchSearches())
		},
	}))
	registerFileAction(openInFilesAction)
	registerFileAction(deleteSearchAction)
	return true
}
