import { describe, it, expect } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { PLAIN_TEXT } from '../../src/utils/fieldValue.js'

/**
 * Placeholder values and HTML escaping.
 *
 * translate() escapes every placeholder value as HTML by default, and then
 * sanitizes the result. That is right where the result becomes HTML, and wrong
 * everywhere else: in {{ }}, an attribute or textContent a team folder called
 * "R&D" read "R&amp;D". So:
 *
 * - a call that fills a placeholder and is shown as text passes PLAIN_TEXT
 *   ({ escape: false, sanitize: false });
 * - a call whose result reaches a toast (showError, showSuccess, showWarning,
 *   showInfo, showMessage, showUndo, or the `notification` event the admin page
 *   turns into one) keeps the default: a toast puts its text into the DOM as
 *   HTML, and decodes the escaped value back for display;
 * - any other HTML site (v-html, innerHTML) keeps the default and says so with
 *   an `l10n-html:` comment on the line above. MetaVox has none today.
 */

const VALUE = `Tom & Jerry's "<b>best</b>"`

describe('placeholder values', () => {
	it('render literally at a text site', () => {
		expect(t('metavox', 'Edit metadata for {folder}', { folder: VALUE }, undefined, PLAIN_TEXT))
			.toBe(`Edit metadata for ${VALUE}`)
		expect(n('metavox', 'Metadata saved for {count} file in {folder}', 'Metadata saved for {count} files in {folder}', 2, { count: 2, folder: VALUE }, PLAIN_TEXT))
			.toBe(`Metadata saved for 2 files in ${VALUE}`)
	})

	it('stay escaped at an HTML site such as a toast, where the default applies', () => {
		const html = t('metavox', 'Edit metadata for {folder}', { folder: VALUE })
		expect(html).toBe('Edit metadata for Tom &amp; Jerry&#39;s &quot;&lt;b&gt;best&lt;/b&gt;&quot;')
		expect(html).not.toContain('<')
	})

	it('are not sanitized either at a text site', () => {
		// In the browser DOMPurify re-serializes any string with a "<" in it:
		// "&" becomes "&amp;" and tags disappear. In text that is corruption, not
		// safety, so the text option switches it off together with escaping.
		expect(PLAIN_TEXT).toEqual({ escape: false, sanitize: false })
	})
})

const walk = (dir) => readdirSync(dir).flatMap((e) => {
	const p = join(dir, e)
	return statSync(p).isDirectory() ? walk(p) : [p]
})
const sources = walk('src').filter((f) => f.endsWith('.vue') || f.endsWith('.js'))

/** Every t('metavox', …)/n('metavox', …) call with its arguments split at depth 0. */
function calls(source) {
	const found = []
	const re = /(?<![\w$.])(?:this\.)?([tn])\(\s*'metavox'/g
	let m
	while ((m = re.exec(source)) !== null) {
		let i = m.index + m[0].indexOf('(') + 1
		let depth = 0
		let quote = null
		let comment = false
		let current = ''
		const args = []
		for (; i < source.length; i++) {
			const c = source[i]
			if (comment) { if (c === '\n') comment = false; continue }
			if (quote) { current += c; if (c === '\\') { current += source[++i]; continue } if (c === quote) quote = null; continue }
			if (c === '/' && source[i + 1] === '/') { comment = true; continue }
			if (c === "'" || c === '"' || c === '`') { quote = c; current += c; continue }
			if ('([{'.includes(c)) depth++
			if (')]}'.includes(c)) { if (depth === 0) break; depth-- }
			if (c === ',' && depth === 0) { args.push(current.trim()); current = ''; continue }
			current += c
		}
		if (current.trim()) args.push(current.trim())
		found.push({ kind: m[1], args, index: m.index })
	}
	return found
}

/** Where the arguments of each toast call lie: [start, end) offsets. */
function toastSpans(source) {
	const spans = []
	const re = /(?<![\w$.])show(?:Error|Success|Warning|Info|Message|Undo)\(|\$emit\(\s*'notification'\s*,/g
	let m
	while ((m = re.exec(source)) !== null) {
		const start = source.indexOf('(', m.index) + 1
		let depth = 0
		let quote = null
		let comment = false
		let i = start
		for (; i < source.length; i++) {
			const c = source[i]
			if (comment) { if (c === '\n') comment = false; continue }
			if (quote) { if (c === '\\') { i++; continue } if (c === quote) quote = null; continue }
			if (c === '/' && source[i + 1] === '/') { comment = true; continue }
			if (c === "'" || c === '"' || c === '`') { quote = c; continue }
			if ('([{'.includes(c)) depth++
			if (')]}'.includes(c)) { if (depth === 0) break; depth-- }
		}
		spans.push([start, i])
	}
	return spans
}

/** Every call that fills a placeholder, with whether it opts out and whether it feeds a toast. */
function placeholderCalls() {
	const found = []
	for (const f of sources) {
		const source = readFileSync(f, 'utf8')
		const toasts = toastSpans(source)
		for (const { kind, args, index } of calls(source)) {
			const vars = args[kind === 't' ? 2 : 4]
			if (!vars || vars === 'undefined') continue
			found.push({
				where: `${f}:${source.slice(0, index).split('\n').length}`,
				plain: /PLAIN_TEXT/.test(args.slice(kind === 't' ? 3 : 5).join(',')),
				toast: toasts.some(([a, b]) => index >= a && index < b),
				marked: /l10n-html:/.test(source.slice(0, index).split('\n').slice(-2, -1)[0] ?? ''),
			})
		}
	}
	return found
}

describe('every call that fills a placeholder', () => {
	it('passes PLAIN_TEXT at a text site, or is marked as HTML', () => {
		const offenders = placeholderCalls().filter((c) => !c.toast && !c.plain && !c.marked).map((c) => c.where)
		expect(offenders).toEqual([])
	})

	it('keeps the default escaping when it feeds a toast', () => {
		// A toast is HTML. Its text has to reach it inline, inside the show*()
		// call, so this rule can see it.
		const offenders = placeholderCalls().filter((c) => c.toast && c.plain).map((c) => c.where)
		expect(offenders).toEqual([])
	})

	it('finds the toasts at all', () => {
		// Guard against the span detection passing everything vacuously.
		expect(placeholderCalls().filter((c) => c.toast).length).toBeGreaterThan(10)
	})

	it('can read PLAIN_TEXT in a template', () => {
		// In an Options API component an imported constant is not in the
		// template's scope: _ctx.PLAIN_TEXT is undefined there, and the call
		// silently escapes again. The component has to return it from setup().
		const offenders = []
		for (const f of sources.filter((x) => x.endsWith('.vue'))) {
			const source = readFileSync(f, 'utf8')
			const template = source.slice(0, source.indexOf('<script'))
			if (!template.includes('PLAIN_TEXT')) continue
			const scriptSetup = /<script setup[^>]*>[\s\S]*import \{[^}]*PLAIN_TEXT/.test(source)
			const returned = /setup\s*\(\)\s*\{[\s\S]*?return \{[^}]*PLAIN_TEXT/.test(source)
			if (!scriptSetup && !returned) offenders.push(f)
		}
		expect(offenders).toEqual([])
	})
})
