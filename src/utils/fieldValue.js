/**
 * Rendering a stored metadata value as the user knows it.
 */

/**
 * t() options for text that ends up in textContent or an attribute:
 * escaped or sanitized as HTML, R&D would read "R&amp;D" and <x> would vanish.
 * Never for v-html or innerHTML.
 */
export const PLAIN_TEXT = { escape: false, sanitize: false }
