/**
 * Who a People field holds, by name. The field stores ids: an account id as
 * it is, group:<id> or team:<id>, several joined with ;#. The server sends the
 * names with the values (PROPFIND, the API, the picker), as the viewer may
 * read them; they are kept here, so every place shows the same name.
 */

import { translate as t } from '@nextcloud/l10n'

const PREFIXES = { group: 'group:', team: 'team:' }

const names = new Map() // item => name
const listeners = new Set()
let notifying = false
let mine = null

/** @return {'user'|'group'|'team'} */
export function accountKind(item) {
	for (const [kind, prefix] of Object.entries(PREFIXES)) {
		if (String(item).startsWith(prefix)) return kind
	}
	return 'user'
}

export function accountId(item) {
	const kind = accountKind(item)
	return kind === 'user' ? String(item) : String(item).slice(PREFIXES[kind].length)
}

/** The name the server sent; null for one it did not, or that is gone or hidden. */
export const accountName = (item) => names.get(String(item)) ?? null

/**
 * What an item reads as: its name; without one, an account or a group by its
 * id, a team as unavailable: its id means nothing to anyone.
 */
export function accountLabel(item) {
	const name = accountName(item)
	if (name) return name
	// TRANSLATORS Shown instead of a team's name when the team was deleted or the user cannot see it
	return accountKind(item) === 'team' ? t('metavox', 'Unavailable team') : accountId(item)
}

/** The items of a stored value, each once. */
export function accountItems(value) {
	if (value === null || value === undefined || value === '') return []
	return [...new Set(String(value).split(';#').map((item) => item.trim()).filter(Boolean))]
}

/** The items of a value, each with its kind and what it reads as. */
export function accountsOf(value) {
	return accountItems(value).map((id) => ({ id, kind: accountKind(id), label: accountLabel(id), known: names.has(id) }))
}

/**
 * Names from the server: item => name, or [{id, name}].
 *
 * @param {Object<string, string>|Array<{id: string, name: ?string}>} given
 */
export function rememberAccountNames(given) {
	const entries = Array.isArray(given) ? given.map((a) => [a?.id, a?.name]) : Object.entries(given ?? {})
	let changed = false
	for (const [item, name] of entries) {
		if (typeof item !== 'string' || typeof name !== 'string' || name === '' || names.get(item) === name) continue
		names.set(item, name)
		changed = true
	}
	// Once for all names that come in together, such as a whole listing.
	if (changed && !notifying) {
		notifying = true
		queueMicrotask(() => {
			notifying = false
			for (const fn of listeners) fn()
		})
	}
}

/** Called when names came in, so what shows them can be drawn again. Returns a stop function. */
export function onAccountNames(fn) {
	listeners.add(fn)
	return () => listeners.delete(fn)
}

/** The viewer's own items: their account, groups and teams (from the Files page). */
export function setViewerItems(items) {
	mine = Array.isArray(items) ? items.map(String) : null
}

/** What "assigned to you" looks for; without the server's list, the account alone. */
export function viewerItems(uid) {
	return mine ?? (uid ? [String(uid)] : [])
}
