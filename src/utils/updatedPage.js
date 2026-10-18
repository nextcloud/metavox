/**
 * After MetaVox is updated, a page that stayed open still runs the old code,
 * and the parts it loads later are gone or changed on the server. Loading
 * them fails; this says what helps, once, instead of a button that does
 * nothing. Nextcloud's own toast and translation (window.OCP, window.t): no
 * @nextcloud/dialogs or l10n in the small script every page loads.
 */

import { t } from './pageL10n.js'

let said = false

/**
 * Whether an error is a part that failed to load: webpack's ChunkLoadError,
 * or an async component's loader (Vue's error code 13, in production
 * builds only as the link to its reference).
 *
 * @param {*} error
 * @param {string} [info] Vue's error info
 * @return {boolean}
 */
export function isLoadFailure(error, info = '') {
	if (error?.name === 'ChunkLoadError') return true
	const where = String(info)
	return where === 'async component loader' || /runtime-13$/.test(where)
}

/** Say once that the page needs a reload. */
export function sayReload() {
	if (said) return
	said = true
	window.OCP?.Toast?.error?.(t('metavox', 'MetaVox was updated. Reload the page to continue.'), { timeout: -1 })
}

/**
 * A part loaded on demand; if it does not load, say so. The import fails as
 * a whole after an update: a missing file, or one that holds other code.
 *
 * @template T
 * @param {() => Promise<T>} load the import()
 * @return {Promise<T>}
 */
export async function loadPart(load) {
	try {
		return await load()
	} catch (error) {
		sayReload()
		throw error
	}
}

/**
 * A Vue app whose async components say so when they fail to load. Other
 * errors go on as before: to an earlier handler, else to the console as
 * Vue logs them.
 *
 * @param {import('vue').App} app
 * @return {import('vue').App}
 */
export function watchLoading(app) {
	const before = app.config.errorHandler
	app.config.errorHandler = (error, instance, info) => {
		if (isLoadFailure(error, info)) sayReload()
		if (before) before(error, instance, info)
		else console.error(error)
	}
	return app
}

/** For tests: say it again. */
export function _reset() {
	said = false
}
