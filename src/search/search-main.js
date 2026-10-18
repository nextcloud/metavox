/**
 * MetaVox Unified Search filter registration.
 *
 * Adds a "Metadata filter" to the Nextcloud search bar (Cmd+K). Clicking it
 * opens a picker (groupfolder → field → value); confirming the picker emits the
 * native add-filter event so NC attaches our value as a filter chip and
 * forwards it to MetadataSearchProvider (provider id "metavox_metadata").
 *
 * The whole bundle self-guards: if window.OCA.UnifiedSearch (or its
 * registerFilterAction) is missing — e.g. unified search disabled, or an older
 * build — it does nothing and never throws. Typing "field:value" inline in the
 * search bar keeps working regardless (the provider parses it from the term).
 */

import { imagePath } from '@nextcloud/router'
import { FILTER_ID, applyFilter } from './searchQuery.js'
import { loadPart } from '../utils/updatedPage.js'
import { t } from '../utils/pageL10n.js'

// FILTER_ID must equal MetadataSearchProvider::getId() so NC can match the
// filter to the provider in handlePluginFilter() (it looks up filteredProviders
// by id, then only sets extraParams when the id is also a registered backend provider).
const APP_ID = 'metavox'

// NC renders the filter icon as <img src={icon}>, so it must be a URL, not a
// CSS class. Core inverts these images when the background is bright
// (--background-invert-if-bright), so the source is the white mark: app.svg.
const FILTER_ICON = imagePath('metavox', 'app.svg')

// The link of the provider's "too many matches" line
// (MetadataSearchProvider::NARROW_LINK): a hash, so no page loads.
export const NARROW_LINK = '#metavox-filter-by-field'

// Guard so the filter is registered at most once, even if this bundle is
// evaluated more than once in a session (SPA navigation, double include).
let registered = false

/**
 * Open the picker; its choice becomes the filter chip. The picker (Vue and
 * its components) loads on the first click: this script runs on every page.
 */
function pickFilter() {
	// The picker resolves with the selection, or null when dismissed. It
	// enforces a non-empty value, so we never emit an empty filter (which NC
	// rejects with a 400).
	loadPart(() => import(/* webpackChunkName: "metavox-filter-picker" */ './FilterPicker.js'))
		.then(({ openFilterPicker }) => openFilterPicker())
		.then((selection) => {
			if (selection) {
				applyFilter(selection)
			}
		})
		.catch(() => { /* dismissed — no-op */ })
}

/** The hint was opened, by click or Enter: drop the hash again, show the picker. */
export function onNarrowLink() {
	if (window.location.hash !== NARROW_LINK) {
		return
	}
	window.history.replaceState(window.history.state, '', window.location.pathname + window.location.search)
	pickFilter()
}

/**
 * Register the filter action, guarding against missing API and double register.
 * @return {boolean} true if registered (now or already)
 */
function registerMetaVoxFilter() {
	if (registered) {
		return true
	}
	const us = window.OCA && window.OCA.UnifiedSearch
	if (!us || typeof us.registerFilterAction !== 'function') {
		return false
	}

	try {
		us.registerFilterAction({
			id: FILTER_ID,
			appId: APP_ID,
			// searchFrom becomes the search request's provider id (NC: type =
			// searchFrom ?? id). It MUST be the provider id, not the app id —
			// using the app id 'metavox' yields "Provider metavox is unknown" (500).
			searchFrom: FILTER_ID,
			// Pairs with the provider "MetaVox" while making the role explicit:
			// the trailing "…" signals this entry opens a chooser (NC action
			// convention), distinguishing it from the plain "MetaVox" location.
			// TRANSLATORS Entry in the filter menu of the unified search that opens a field filter; MetaVox is the app name, do not translate it
			label: t('metavox', 'MetaVox · Filter by field …'),
			icon: FILTER_ICON,
			callback: pickFilter,
		})
		registered = true
		return true
	} catch (e) {
		// Unknown signature on this NC version — degrade silently.
		return false
	}
}

// UnifiedSearch may register after our script runs; retry briefly like the
// Flow bundle does for WorkflowEngine.
function init() {
	if (registerMetaVoxFilter()) {
		return
	}
	let tries = 0
	const interval = setInterval(() => {
		if (registerMetaVoxFilter() || ++tries > 50) {
			clearInterval(interval)
		}
	}, 100)
}

window.addEventListener('hashchange', onNarrowLink)

if (document.readyState === 'loading') {
	window.addEventListener('DOMContentLoaded', init)
} else {
	init()
}
