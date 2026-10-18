/**
 * Applying a picked filter to Nextcloud's unified search.
 *
 * NC only searches when its search box holds at least one character: a
 * filter added on an empty box sends no request and shows nothing. So the box
 * gets the chosen value first; the server ignores that term whenever the
 * MetaVox filter is present, so it never changes the results.
 */

// Nextcloud's own search box, newest markup first. This is not an API: check
// these on every new Nextcloud version (e2e: scratchpad searchfilter.mjs).
export const SEARCH_INPUTS = [
	// NC35: the search menu and, on small screens, the full-screen one.
	'.unified-search-menu input.unified-search-input__input',
	'input.unified-search-input__input',
	'.unified-search-modal__mobile-input input',
	// NC31–34: the search dialog.
	'#unified-search input[type="text"]',
	'.unified-search__input input',
	'.unified-search__input-wrapper input',
	// The search bar inside an app.
	'.local-unified-search__input input',
]

import { loadState } from '@nextcloud/initial-state'
import { PLAIN_TEXT, t } from '../utils/pageL10n.js'

// Must equal MetadataSearchProvider::getId().
export const FILTER_ID = 'metavox_metadata'

/**
 * Put a value in Nextcloud's search box, as if typed.
 *
 * @param {string} value
 * @param {Document} doc
 * @return {boolean} whether a search box was found
 */
export function setSearchQuery(value, doc = document) {
	for (const selector of SEARCH_INPUTS) {
		const input = [...doc.querySelectorAll(selector)].find((el) => el.isConnected)
		if (!input) {
			continue
		}
		// Through the prototype's setter, so Vue's v-model sees the change.
		const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value')?.set
		if (setter) {
			setter.call(input, value)
		} else {
			input.value = value
		}
		input.dispatchEvent(new Event('input', { bubbles: true }))
		return true
	}
	return false
}

/**
 * The add-filter event Nextcloud turns into a chip and the provider's parameters.
 *
 * @param {{field: string, value: string, label: string, groupfolderId: ?number}} selection
 * @return {object}
 */
export function filterEvent({ field, value, label, groupfolderId }) {
	return {
		id: FILTER_ID,
		filterUpdateText: label || chipText(field, value),
		filterParams: {
			metavox_field: `${field}:${value}`,
			...(groupfolderId != null ? { metavox_groupfolder: String(groupfolderId) } : {}),
		},
	}
}

/**
 * The chip's text when the picker gave no label.
 *
 * @param {string} field
 * @param {string} value
 * @return {string}
 */
function chipText(field, value) {
	// TRANSLATORS: Text of a search filter chip, e.g. "Status: Approved".
	return t('metavox', '{field}: {value}', { field, value }, undefined, PLAIN_TEXT)
}

/** The shortest search Nextcloud sends: 1 unless an admin raised it. */
function minSearchLength() {
	try {
		return Number(loadState('unified-search', 'min-search-length', 1)) || 1
	} catch (e) {
		return 1
	}
}

/**
 * Apply a picked filter: fill the search box, then add the filter.
 *
 * @param {{field: string, value: string, label: string, groupfolderId: ?number}} selection
 */
export function applyFilter(selection) {
	// The first value, or the chip's text when that is too short for Nextcloud.
	const min = minSearchLength()
	const first = String(selection.value).split(';#')[0]
	const seed = first.length >= min ? first : (selection.label || chipText(selection.field, selection.value))
	const filled = setSearchQuery(seed) && seed.length >= min
	window._nc_event_bus?.emit?.('nextcloud:unified-search:add-filter', filterEvent(selection))
	if (!filled) {
		// Without a word in the box Nextcloud shows nothing: say what to do.
		// Nextcloud's own toast: no extra code in this bundle (OC.Notification is gone in NC34+).
		const message = t('metavox', 'Type a word in the search box to see the results of the filter.')
		if (window.OCP?.Toast?.warning) {
			window.OCP.Toast.warning(message)
		} else {
			window.OC?.Notification?.showTemporary?.(message)
		}
	}
}
