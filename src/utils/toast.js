/**
 * Messages through Nextcloud's own toasts (window.OCP.Toast), which every
 * page has: @nextcloud/dialogs (about 200 KB) then stays out of the script
 * every Files page loads. Its confirmation dialogs still come from it, in the
 * parts that use them, which load when they open.
 */

import { t } from './pageL10n.js'

const toast = () => window.OCP?.Toast

export const showError = (text, options) => toast()?.error(text, options)
export const showWarning = (text, options) => toast()?.warning(text, options)
export const showSuccess = (text, options) => toast()?.success(text, options)
export const showInfo = (text, options) => toast()?.info(text, options)

// Ten seconds, as @nextcloud/dialogs gives an undo.
const UNDO_TIMEOUT = 10000

/**
 * A message with an Undo button, as @nextcloud/dialogs' showUndo. Core styles
 * a plain button in a toast as its own.
 *
 * @param {string} text
 * @param {Function} onUndo
 * @return {{hideToast: Function}}
 */
export function showUndo(text, onUndo) {
	const message = document.createElement('span')
	message.append(text, ' ')
	const button = document.createElement('button')
	button.type = 'button'
	button.textContent = t('metavox', 'Undo')
	message.append(button)
	// NC 35 answers {hideToast}, older ones the Toastify toast: both have it.
	const shown = toast()?.message(message, { timeout: UNDO_TIMEOUT })
	const hideToast = () => shown?.hideToast?.()
	button.addEventListener('click', () => {
		hideToast()
		onUndo()
	})
	return { hideToast }
}
