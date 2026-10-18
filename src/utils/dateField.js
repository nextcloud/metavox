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

import { includesTime } from './fieldOptions.js'

export function dateFieldIncludesTime(field) {
	return includesTime(field?.field_options ?? field?.options)
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

/**
 * Parse a stored floating ISO string ("YYYY-MM-DD" or "YYYY-MM-DDTHH:mm[:ss]")
 * into a Date in the *local* zone, for components that model a Date.
 *
 * The parts are passed to the Date constructor individually on purpose:
 * `new Date('2026-05-30')` is parsed by the spec as UTC midnight, which is the
 * previous day in every negative-offset zone. Anything unparseable yields null,
 * which the pickers render as an empty field.
 *
 * Caveat, inherent to JS Date rather than to this function: a datetime that
 * falls in the hour a DST spring-forward skips (02:30 on a European transition
 * day) does not exist locally and is normalised to 03:30. Every Date
 * construction does this, so date-only values — the common case — are unaffected.
 *
 * @param {string|Date|null} value stored value
 * @return {Date|null} local Date, or null when there is nothing to show
 */
export function parseLocalDateValue(value) {
	if (value instanceof Date) return isNaN(value.getTime()) ? null : value
	if (typeof value !== 'string' || value === '') return null

	const match = value.match(/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?/)
	if (!match) return null

	const [, year, month, day, hours, minutes, seconds] = match
	const date = new Date(
		Number(year),
		Number(month) - 1,
		Number(day),
		Number(hours || 0),
		Number(minutes || 0),
		Number(seconds || 0),
	)
	return isNaN(date.getTime()) ? null : date
}

// setUTCFullYear, not Date.UTC: that reads years 0-99 as 1900-1999.
function utcDate(year, monthIndex, day) {
	const date = new Date(0)
	date.setUTCFullYear(year, monthIndex, day)
	return date
}

const DATE_PART = /^(\d{4})-(\d{2})-(\d{2})(?:[T ]\d{2}:\d{2}(?::\d{2})?)?/

/**
 * The day of a stored date, with or without a time, as "YYYY-MM-DD".
 *
 * @param {*} value stored value
 * @return {string|null} null for anything that is not a real date (2026-02-30)
 */
export function datePart(value) {
	if (typeof value !== 'string') return null
	const match = value.match(DATE_PART)
	if (!match) return null
	const [, year, month, day] = match.map(Number)
	if (utcDate(year, month - 1, day).getUTCDate() !== day || month < 1 || month > 12) return null
	return match[0].slice(0, 10)
}

/** A real day, written as exactly "YYYY-MM-DD". */
export const isDay = (value) => typeof value === 'string' && datePart(value) === value

let now = () => new Date()

/** For tests: the clock todayKey reads. */
export function _setClock(fn) {
	now = fn ?? (() => new Date())
}

/**
 * Today in the browser, as "YYYY-MM-DD". Not cached: read once per redraw, it
 * follows midnight and a change of time zone without anything to reset.
 *
 * @return {string}
 */
export function todayKey() {
	return formatLocalDatetime(now()).slice(0, 10)
}

/**
 * A day plus n days. Counted in whole UTC days, so a 23 or 25 hour day around
 * a daylight saving change moves nothing.
 *
 * @param {string} key "YYYY-MM-DD"
 * @param {number} days
 * @return {string}
 */
export function addDays(key, days) {
	const [year, month, day] = key.split('-').map(Number)
	const date = utcDate(year, month - 1, day + days)
	return `${String(date.getUTCFullYear()).padStart(4, '0')}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}`
}
