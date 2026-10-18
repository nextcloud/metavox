/**
 * Nextcloud's own translation (window.t), for the scripts every page loads:
 * no @nextcloud/l10n in them. Called with the app id and a literal, as the
 * library's t(), so the Transifex bot finds the text.
 */
export const t = (...args) => (window.t ? window.t(...args) : args[1])

/**
 * t()/n() options for a call that fills placeholders and whose result is shown
 * as text: {{ }}, an attribute, textContent, a dialog. The library escapes every
 * value as HTML by default (R&D would read "R&amp;D"), and DOMPurify then
 * re-serializes any result with a "<" in it, so both are switched off.
 *
 * Pass it as the fifth argument of t() (the fourth stays undefined) and the
 * sixth of n(). A template in an Options API component needs it returned from
 * setup().
 *
 * Never for HTML: v-html, innerHTML, and toasts (showError, showSuccess,
 * showWarning, showInfo, showMessage, showUndo, and the admin page's
 * `notification` event, which becomes one). A toast puts its text into the DOM
 * as HTML, so it keeps the default escaping and still reads correctly. Any
 * other HTML site says so with an `l10n-html:` comment on the line above the
 * call. tests/js/l10nEscaping.test.js holds these rules.
 */
export const PLAIN_TEXT = { escape: false, sanitize: false }
