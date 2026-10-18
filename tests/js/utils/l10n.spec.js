import { describe, it, expect } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'

/**
 * The translation bundles, checked as data.
 *
 * Three things here are only visible by reading the files: a plural key has a
 * shape (`_singular_::_plural_` with an array of forms), the `.js` and `.json`
 * must stay identical because the app ships both, and a locale's number of
 * forms has to match its own plural rule — French has three where the rest
 * have two, and getting that wrong serves the wrong form rather than failing.
 */
// Every committed bundle. en.json/en.js are not among them: they are generated
// from the source (scripts/extract-en-json.js) and untracked, and the POT is the
// source of truth.
const LOCALES = readdirSync('l10n')
	.filter((f) => /^[a-z]{2,3}(_[A-Z]{2})?\.json$/.test(f) && f !== 'en.json')
	.map((f) => f.replace(/\.json$/, ''))

const json = (loc) => JSON.parse(readFileSync(`l10n/${loc}.json`, 'utf8'))

/** The msgids of the committed POT, plurals as "_singular_::_plural_". */
const potKeys = () => {
	const unescape = (x) => x.replace(/\\(.)/g, (_, c) => (c === 'n' ? '\n' : c === 't' ? '\t' : c))
	const keys = new Set()
	const pot = readFileSync('translationfiles/templates/metavox.pot', 'utf8')
	for (const block of pot.split(/\n\n+/)) {
		const id = /^msgid "((?:[^"\\]|\\.)*)"/m.exec(block)
		if (!id || id[1] === '') continue
		const plural = /^msgid_plural "((?:[^"\\]|\\.)*)"/m.exec(block)
		keys.add(plural ? `_${unescape(id[1])}_::_${unescape(plural[1])}_` : unescape(id[1]))
	}
	return keys
}

const jsBundle = (loc) => {
	const src = readFileSync(`l10n/${loc}.js`, 'utf8')
	const m = /OC\.L10N\.register\(\s*"metavox",\s*(\{[\s\S]*\})\s*,\s*"(?:[^"\\]|\\.)*"\s*\)/.exec(src)
	if (!m) throw new Error(`${loc}.js does not match the register() shape`)
	return JSON.parse(m[1])
}

const walk = (dir) => readdirSync(dir).flatMap((e) => {
	const p = join(dir, e)
	return statSync(p).isDirectory() ? walk(p) : [p]
})

const sources = walk('src').filter((f) => f.endsWith('.vue') || f.endsWith('.js'))

/** Every n() call in the source, as [singular, plural]. */
const pluralCalls = () => {
	const found = new Set()
	for (const f of sources) {
		const s = readFileSync(f, 'utf8')
		const re = /\bn\(\s*'metavox',\s*\n?\s*'((?:[^'\\]|\\.)*)',\s*\n?\s*'((?:[^'\\]|\\.)*)'/g
		for (const m of s.matchAll(re)) found.add(`${m[1]}\u0000${m[2]}`)
	}
	return [...found].map((k) => k.split('\u0000'))
}

describe('translation bundles', () => {
	it.each(LOCALES)('%s: .js and .json carry the same map', (loc) => {
		expect(jsBundle(loc)).toEqual(json(loc).translations)
	})

	it.each(LOCALES)('%s: every plural value is an array of the right length', (loc) => {
		const d = json(loc)
		const header = d.pluralForm ?? 'nplurals=2;'
		const nplurals = Number(/nplurals\s*=\s*(\d+)/.exec(header)[1])
		const wrong = Object.entries(d.translations)
			.filter(([k]) => k.includes('_::_'))
			.filter(([, v]) => !Array.isArray(v) || v.length !== nplurals)
			.map(([k, v]) => `${k} has ${Array.isArray(v) ? v.length : typeof v}, expected ${nplurals}`)
		expect(wrong).toEqual([])
	})

	it('French declares three forms, because its rule has three', () => {
		// The runtime puts 0 and 1 in the same slot for French and 0 in the
		// plural slot for the others; a two-form French bundle silently serves
		// the wrong one.
		expect(json('fr').pluralForm).toMatch(/nplurals=3/)
		expect(json('nl').pluralForm).toMatch(/nplurals=2/)
	})

	it('every n() call is in the POT', () => {
		// The POT is what translators get. A bundle may lack a string: that is
		// a translation still to be done, and the runtime shows the English.
		const pot = potKeys()
		const missing = pluralCalls()
			.map(([s, p]) => `_${s}_::_${p}_`)
			.filter((k) => !pot.has(k))
		expect(missing).toEqual([])
	})

	it('no n() call has the same singular and plural', () => {
		// Identical forms mean the call is doing nothing a t() would not.
		const same = pluralCalls().filter(([s, p]) => s === p)
		expect(same).toEqual([])
	})

	it('every plural source string carries the count', () => {
		// n() substitutes %n, and translate() substitutes {named} placeholders,
		// so a plural may use either — BulkMetadataModal passes {count}
		// explicitly, which a translator can reorder where %n cannot. What is
		// not allowed is a plural that names no count at all: the reader is
		// being told a number and it would silently vanish.
		// Only the plural form is checked. A singular may legitimately say
		// "this file" rather than "%n file" — that is natural English and the
		// runtime never substitutes a number there anyway.
		const hasCount = (x) => x.includes('%n') || /\{\w+\}/.test(x)
		const bad = pluralCalls().filter(([, p]) => !hasCount(p))
		expect(bad).toEqual([])
	})

	it.each(LOCALES.filter((l) => l !== 'en'))('%s: no translation is left empty', (loc) => {
		// An empty value is not "untranslated" to the runtime — t() falls back to
		// the English source, so the string renders in English beside translated
		// text and nothing reports it. Nine of these shipped on the Support pane.
		const t = json(loc).translations
		const empty = Object.entries(t)
			.filter(([, v]) => (typeof v === 'string' && v.trim() === '')
				|| (Array.isArray(v) && v.some((x) => x.trim() === '')))
			.map(([k]) => k)
		expect(empty).toEqual([])
	})

	it('every t() string is in the POT', () => {
		// The source is JS, so the literal is not the string: \' is just an
		// apostrophe and \u00a0 is a non-breaking space. ViewEditorPanel
		// writes "Saving\u00a0\u2026" where the bundle holds the decoded
		// characters, and comparing raw text reported it as missing.
		const unescape = (x) => x
			.replace(/\\u([0-9a-fA-F]{4})/g, (_, h) => String.fromCharCode(parseInt(h, 16)))
			.replace(/\\(['"\\])/g, '$1')
		const pot = potKeys()
		const missing = new Set()
		for (const f of sources) {
			const src = readFileSync(f, 'utf8')
			for (const m of src.matchAll(/\bt\(\s*'metavox',\s*\n?\s*'((?:[^'\\]|\\.)*)'/g)) {
				const key = unescape(m[1])
				if (!pot.has(key)) missing.add(key)
			}
		}
		expect([...missing]).toEqual([])
	})

	it('no user-facing string concatenates a number with a noun', () => {
		// `{{ count }} {{ t('app', 'entries') }}` cannot be reordered by a
		// translator and has no plural (§13).
		const offenders = []
		for (const f of sources.filter((x) => x.endsWith('.vue'))) {
			const s = readFileSync(f, 'utf8')
			const template = s.slice(0, s.indexOf('<script'))
			for (const m of template.matchAll(/\{\{[^}]*\}\}\s*\{\{\s*t\('metavox'/g)) {
				offenders.push(`${f}: ${template.slice(m.index, m.index + 60).replace(/\s+/g, ' ')}`)
			}
		}
		expect(offenders).toEqual([])
	})
})
