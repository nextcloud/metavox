/**
 * Helpers for the optional `includeTime` flag on `date` field-types.
 *
 * Floating ISO strings — no timezone conversion:
 *   includeTime=false → "YYYY-MM-DD"
 *   includeTime=true  → "YYYY-MM-DDTHH:mm:ss"
 *
 * SharePoint SPFieldDateTime mapping:
 *   DateOnly  ⇄ includeTime=false
 *   DateTime  ⇄ includeTime=true
 */

export function dateFieldIncludesTime(field) {
	const opts = field?.field_options ?? field?.options
	return !!(opts && typeof opts === 'object' && !Array.isArray(opts) && opts.includeTime)
}

function pad(n) {
	return String(n).padStart(2, '0')
}

/**
 * Format a JS Date as a floating local "YYYY-MM-DDTHH:mm:ss" (no Z, no offset).
 */
export function formatLocalDatetime(d) {
	return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
		+ `T${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`
}

/**
 * Pad `<input type="datetime-local">` output (16 chars: YYYY-MM-DDTHH:mm)
 * to canonical 19-char storage (YYYY-MM-DDTHH:mm:ss).
 */
export function padDatetimeLocal(value) {
	if (typeof value === 'string' && value.length === 16) return value + ':00'
	return value
}
