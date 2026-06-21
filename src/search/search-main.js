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
import { openFilterPicker } from './FilterPicker.js'

const t = window.t ? (text) => window.t('metavox', text) : (text) => text

// Must equal MetadataSearchProvider::getId() so NC can match the filter to the
// provider in handlePluginFilter() (it looks up filteredProviders by id, then
// only sets extraParams when the id is also a registered backend provider).
const FILTER_ID = 'metavox_metadata'
const APP_ID = 'metavox'

// NC renders the filter icon as <img src={icon}>, so it must be a URL, not a
// CSS class. Use the dedicated single-color filter mark (metadata-filter.svg):
// the MetaVox mark with its document lines tapering like a funnel, drawn as
// evenodd cutouts (no contrast-dependent strokes) so it stays legible at ~16px
// in every theme and reads as "filter" — distinct from the plain app mark.
const FILTER_ICON = imagePath('metavox', 'metadata-filter.svg')

// Guard so the filter is registered at most once, even if this bundle is
// evaluated more than once in a session (SPA navigation, double include).
let registered = false

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
			label: t('MetaVox · Filter by field…'),
			icon: FILTER_ICON,
			callback: () => {
				// Open the picker; it resolves with the selection (or null when
				// dismissed). The picker enforces a non-empty value, so we never
				// emit an empty filter (which NC rejects with a 400).
				openFilterPicker()
					.then((selection) => {
						if (selection) {
							applyFilter(selection)
						}
					})
					.catch(() => { /* dismissed — no-op */ })
			},
		})
		registered = true
		return true
	} catch (e) {
		// Unknown signature on this NC version — degrade silently.
		return false
	}
}

/**
 * Apply a chosen filter via the native add-filter event.
 *
 * handlePluginFilter() reads these keys and turns filterParams into the
 * provider's extraParams, which NC spreads into the search request — where
 * MetadataSearchProvider::getFilter() reads them.
 *
 * Important: NC's find() early-returns when the search box is shorter than
 * minSearchLength (default 1), so handlePluginFilter's debouncedFind() fires no
 * request on an empty box. We therefore seed the search input with the chosen
 * value BEFORE emitting add-filter, so the re-search actually runs. The backend
 * ignores this term whenever metavox_field is present ($filterExpr ?? term), so
 * the seed value never pollutes our own results.
 *
 * @param {{field: string, value: string, label: string, groupfolderId: ?number}} selection
 */
function applyFilter({ field, value, label, groupfolderId }) {
	const expr = `${field}:${value}`

	// Seed the search box so find() passes its length guard, then let
	// handlePluginFilter's debouncedFind issue the request with our extraParams.
	// Use the first token as the seed so it stays short and meaningful.
	setSearchQuery(String(value).split(';#')[0])

	emit('nextcloud:unified-search:add-filter', {
		id: FILTER_ID,
		// Readable chip text: "Field label: value(s)" supplied by the picker.
		filterUpdateText: label || `${field}: ${value}`,
		filterParams: {
			metavox_field: expr,
			...(groupfolderId != null ? { metavox_groupfolder: String(groupfolderId) } : {}),
		},
	})
}

/**
 * Seed NC's unified-search input so its searchQuery is non-empty. NC binds the
 * box via NcInputField; we set the underlying <input> value and dispatch a
 * native 'input' event so Vue's v-model picks it up.
 *
 * Why this exists: NC's find() early-returns when searchQuery is shorter than
 * minSearchLength, so a plugin filter applied on an empty box fires no request.
 * Seeding the box is the only lever an app has (the modal owns searchQuery and
 * exposes no setter).
 *
 * KNOWN FRAGILITY (verify on NC version bumps): this targets NC's INTERNAL,
 * undocumented search-input markup by selector. The class differs between the
 * global modal and the in-app bar, hence the candidate list below. If a future
 * NC (e.g. the NC34/35 search redesign, server#60241) renames these, seeding
 * silently fails and the chip stops returning results — re-check the selectors
 * then. Typing "field:value" inline in the search bar keeps working regardless,
 * since the provider parses it from the term.
 * @param {string} value
 * @return {boolean} true if an input was found and updated
 */
function setSearchQuery(value) {
	const selectors = [
		'.unified-search__input input',
		'.unified-search__input-wrapper input',
		'.local-unified-search__input input',
		'#unified-search input[type="text"]',
		'input[role="searchbox"]',
	]
	for (const sel of selectors) {
		const el = document.querySelector(sel)
		if (el) {
			const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value')?.set
			if (setter) {
				setter.call(el, value)
			} else {
				el.value = value
			}
			el.dispatchEvent(new Event('input', { bubbles: true }))
			return true
		}
	}
	return false
}

/**
 * Emit via the @nextcloud event bus if present.
 * @param {string} name
 * @param {object} detail
 */
function emit(name, detail) {
	if (window._nc_event_bus && typeof window._nc_event_bus.emit === 'function') {
		try {
			window._nc_event_bus.emit(name, detail)
		} catch (e) { /* best-effort */ }
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

if (document.readyState === 'loading') {
	window.addEventListener('DOMContentLoaded', init)
} else {
	init()
}
