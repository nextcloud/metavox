/**
 * Highlight rules painted on the grid: a cell that matches gets a soft wash
 * and a bar in the rule's tone, an icon with the state's name, and that name
 * in its accessible name. Everything lives on our own <td>: data attributes
 * and one icon, never inline styles (the lock uses those) and never classes
 * on Nextcloud's <tr> (Vue rewrites that attribute).
 */

import { todayKey } from '../../utils/dateField.js'
import { cellState, compileRules, stateName } from '../../utils/valueRules.js'
import { MARKER_CLASS } from './ColumnStyles.js'
import { ICON_PATHS, RULES_ICON_PATH } from './ToneIcons.js'
import { hasNoTeamFolder, isTruncated } from './MetadataStore.js'
import { getActiveColumnConfigs, getAvailableFields, metadataCache } from './MetaVoxState.js'

// Late-bound, like ColumnDOM: InlineEditor imports this module, and the
// current user comes from the browser page.
let getActiveEditorCell = () => null
let updateCellAccessibleName = () => {}
let currentUserId = () => null

export function setDependencies(deps) {
	if (deps.getActiveEditorCell) getActiveEditorCell = deps.getActiveEditorCell
	if (deps.updateCellAccessibleName) updateCellAccessibleName = deps.updateCellAccessibleName
	if (deps.currentUserId) currentUserId = deps.currentUserId
}

export { RULES_ICON_PATH }

export const ICON_CLASS = 'mv-tone-icon'

/** A 16px icon in currentColor, hidden from assistive technology. */
export function svgIcon(path) {
	const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg')
	svg.setAttribute('viewBox', '0 0 24 24')
	svg.setAttribute('width', '16')
	svg.setAttribute('height', '16')
	svg.setAttribute('aria-hidden', 'true')
	svg.setAttribute('focusable', 'false')
	const p = document.createElementNS('http://www.w3.org/2000/svg', 'path')
	p.setAttribute('fill', 'currentColor')
	p.setAttribute('d', path)
	svg.appendChild(p)
	return svg
}

const iconTemplates = new Map()

// The icon carries the state's name, so table and reading modes, which read a
// cell's content and not its aria-label, hear it too. It holds no text: the
// cell's textContent stays its value, which the editor and the name rely on.
function makeIcon(tone, note) {
	if (!iconTemplates.has(tone)) {
		const span = document.createElement('span')
		span.className = ICON_CLASS
		span.setAttribute('role', 'img')
		span.appendChild(svgIcon(ICON_PATHS[tone]))
		iconTemplates.set(tone, span)
	}
	const icon = iconTemplates.get(tone).cloneNode(true)
	icon.dataset.tone = tone
	icon.setAttribute('aria-label', note)
	// Pointing at the icon shows the same text, often what needs to be done.
	icon.title = note
	return icon
}

const compiledCache = new WeakMap()

/**
 * A column's rules, ready to match; null when it has none. The column objects
 * are new for every view applied, and the field types they carry are those
 * of the fields loaded with them.
 *
 * @param {object} config column configuration
 * @return {{rule: object, index: number}[]|null}
 */
export function compiledFor(config) {
	const fields = getAvailableFields()
	const hit = compiledCache.get(config)
	if (hit && hit.fields === fields) return hit.compiled
	const compiled = Array.isArray(config.format) && config.format.length
		? compileRules(config.format, config.field_type)
		: null
	compiledCache.set(config, { fields, compiled })
	return compiled
}

// The day the cells were last painted for: "before today" changes at midnight.
let paintedDay = null

/** The names of a column's rules, in order: for the column head. */
export function ruleNames(config) {
	return (compiledFor(config) ?? []).map(({ rule }) => stateName(rule, config))
}

/**
 * Give a cell the state of the first rule its value matches, or none. Called
 * on every way a cell gets its value (ColumnDOM.setCellValue).
 *
 * @param {HTMLElement} td
 * @param {*} value stored value
 * @param {object} config column configuration
 */
export function applyCellFormat(td, value, config) {
	const compiled = compiledFor(config)
	const fileId = Number(td.dataset.fileId)
	// A mount of another storage in the folder has no values of ours: its
	// empty cells say nothing.
	if (!compiled || hasNoTeamFolder(fileId)) {
		clearCellFormat(td)
		return
	}
	const ctx = {
		today: todayKey(),
		me: currentUserId(),
		truncated: isTruncated(fileId, config.field_name),
	}
	paintedDay = ctx.today
	const state = cellState(compiled, value, config, ctx)
	if (!state) {
		clearCellFormat(td)
		return
	}

	const { tone, note } = state
	if (td.dataset.mvTone !== tone) td.dataset.mvTone = tone
	if (td.dataset.mvNote !== note) td.dataset.mvNote = note
	const icon = td.querySelector(':scope > .' + ICON_CLASS)
	if (icon?.dataset.tone === tone) {
		if (icon.getAttribute('aria-label') !== note) {
			icon.setAttribute('aria-label', note)
			icon.title = note
		}
	} else {
		icon?.remove()
		td.prepend(makeIcon(tone, note))
	}
}

/** @param {HTMLElement} td */
export function clearCellFormat(td) {
	delete td.dataset.mvTone
	delete td.dataset.mvNote
	td.querySelector(':scope > .' + ICON_CLASS)?.remove()
}

/**
 * Paint the states again without touching the values: for a new day, when
 * "before today" means something else.
 */
export function refreshCellFormats() {
	const configs = new Map(getActiveColumnConfigs().map((c) => [c.field_name, c]))
	const editing = getActiveEditorCell()
	for (const row of document.querySelectorAll('tr[data-cy-files-list-row]')) {
		const meta = metadataCache.get(Number(row.getAttribute('data-cy-files-list-row-fileid')))
		if (!meta) continue
		for (const cell of row.querySelectorAll('.' + MARKER_CLASS)) {
			const config = configs.get(cell.dataset.metavoxField)
			if (!config || cell === editing) continue
			applyCellFormat(cell, meta[config.field_name], config)
			updateCellAccessibleName(cell, config)
		}
	}
}

/**
 * Paint again when the day changed since the last paint.
 *
 * @return {boolean} whether it painted again
 */
export function checkDayChange() {
	if (paintedDay === null || todayKey() === paintedDay) return false
	refreshCellFormats()
	return true
}

/**
 * Check the day just after midnight. That timer stands still while the laptop
 * sleeps, so the page coming back and the first click check it too.
 */
export function watchDayChange() {
	const schedule = () => {
		const now = new Date()
		const midnight = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1)
		setTimeout(() => {
			checkDayChange()
			schedule()
		}, midnight - now + 1000)
	}
	schedule()
	document.addEventListener('visibilitychange', () => {
		if (document.visibilityState === 'visible') checkDayChange()
	})
	window.addEventListener('focus', () => checkDayChange())
	document.addEventListener('pointerdown', () => checkDayChange(), { capture: true, passive: true })
}
