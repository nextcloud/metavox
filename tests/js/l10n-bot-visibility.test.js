// Runs with Node's own test runner: node --test tests/js/ (npm run test:l10n).
const { describe, it } = require('node:test')
const assert = require('node:assert/strict')
const { readFileSync, readdirSync } = require('node:fs')
const { join, relative, resolve } = require('node:path')

/**
 * Simulate the Nextcloud Transifex extractor and fail if it would miss a string.
 *
 * Our own extractor (scripts/extract-en-json.js) is deliberately permissive, so
 * the POT we commit is complete. But that POT is NOT what reaches Transifex: the
 * sync bot runs `translationtool.phar create-pot-files`, which overwrites it with
 * its own extraction, pushes that and deletes the file. Whatever its extractor
 * cannot see does not exist for translators.
 *
 * Verified against nextcloud/docker-ci (translations/handleAppsTranslations.sh
 * and translationtool/src/translationtool.php). Per file type:
 *
 *   .vue <template>  its own regex, matching  t('metavox', '…')  literally
 *   .vue <script>    spliced into one file for xgettext --keyword=t:2 --keyword=n:2,3
 *   .js              xgettext --keyword=t:2 --keyword=n:2,3
 *   .php             xgettext --keyword=t --keyword=n:1,2 (literals only)
 *
 * So in JS and Vue the helper must be called t or n and the app id must be the
 * first argument: translate(…), a wrapper without the app id and
 * t('metavox', variable) are all invisible. MetaVox shipped 47 translate() calls
 * and a search picker whose every string went through t('text').
 */

const ROOT = resolve(__dirname, '../..')
const APP_ID = 'metavox'
const { computeSourceStrings, stringsInFile } = require(join(ROOT, 'scripts/extract-en-json.js'))

// translationtool collapses whitespace in a template match with PHP's
// preg_replace('/\s+/', ' '). PHP's \s is ASCII-only, so the U+00A0 before an
// ellipsis survives; JavaScript's \s would eat it and report "Loading …" as
// missing.
const collapsePhpWhitespace = (s) => s.replace(/[ \t\n\r\f\v]+/g, ' ')
const unescapeJs = (s) => s
	.replace(/\\u([0-9a-fA-F]{4})/g, (_, h) => String.fromCharCode(parseInt(h, 16)))
	.replace(/\\(.)/g, (_, c) => (c === 'n' ? '\n' : c === 't' ? '\t' : c))

const IGNORED = readFileSync(join(ROOT, '.l10nignore'), 'utf8')
	.split('\n').map((l) => l.trim()).filter((l) => l && !l.startsWith('#'))
const isIgnored = (rel) => IGNORED.some((pat) => {
	const clean = pat.replace(/\/$/, '')
	return rel === clean || rel.startsWith(clean + '/')
})

const walk = (dir, exts, acc = []) => {
	for (const e of readdirSync(dir, { withFileTypes: true })) {
		const full = join(dir, e.name)
		if (isIgnored(relative(ROOT, full))) continue
		if (e.isDirectory()) walk(full, exts, acc)
		else if (exts.some((x) => e.name.endsWith(x))) acc.push(full)
	}
	return acc
}

const frontendFiles = walk(join(ROOT, 'src'), ['.vue', '.js'])
const rel = (f) => relative(ROOT, f)
const STR = "'((?:[^'\\\\]|\\\\.)*)'"

/** The msgids the bot extracts from one .vue or .js file. */
function botSees(file) {
	const source = readFileSync(file, 'utf8')
	const found = new Set()
	const isVue = file.endsWith('.vue')

	if (isVue) {
		// translationtool's regex is `'(.+)'` (greedy), so two calls on one line
		// become one match; xgettext then re-reads the fake file and still finds
		// both. Matching each call on its own asks the stricter question: does
		// every call carry the app id?
		const template = source.match(/<template>([\s\S]+)<\/template>/)?.[1] ?? ''
		for (const m of template.matchAll(new RegExp(`\\Wt\\s*\\(\\s*'?([\\w.]+)'?,\\s*${STR}`, 'g'))) {
			if (m[1] === APP_ID) found.add(collapsePhpWhitespace(unescapeJs(m[2])))
		}
		for (const m of template.matchAll(new RegExp(`\\Wn\\s*\\(\\s*'?([\\w.]+)'?,\\s*${STR}\\s*,\\s*${STR}`, 'g'))) {
			if (m[1] === APP_ID) found.add(collapsePhpWhitespace(unescapeJs(m[2])))
		}
	}

	// xgettext with --keyword=t:2 / --keyword=n:2,3: a call to t or n (also as a
	// method, this.t) whose second argument is a literal.
	const scripts = isVue ? [...source.matchAll(/<script[^>]*>([\s\S]+?)<\/script>/g)].map((m) => m[1]) : [source]
	for (const body of scripts) {
		for (const m of body.matchAll(new RegExp(`(?<![\\w$])[tn]\\(\\s*'${APP_ID}'\\s*,\\s*${STR}`, 'g'))) {
			found.add(unescapeJs(m[1]))
		}
	}
	return found
}

describe('Transifex bot visibility', () => {
	const visible = new Set()
	for (const f of frontendFiles) for (const s of botSees(f)) visible.add(s)

	it('the simulation finds something', () => {
		// A broken simulation must not pass everything vacuously.
		assert.ok(visible.size > 300, `the simulation found only ${visible.size} strings`)
	})

	it('every frontend string is visible to the bot', () => {
		const invisible = new Set()
		for (const f of frontendFiles) {
			const { singulars, plurals } = stringsInFile(f)
			for (const s of [...singulars, ...plurals.map(([one]) => one)]) {
				if (!visible.has(s)) invisible.add(`${rel(f)}: ${JSON.stringify(s.slice(0, 80))}`)
			}
		}
		assert.deepEqual([...invisible], [])
	})

	it('no translation helper is called by another name', () => {
		// translate('metavox', …) works at runtime and xgettext never sees it:
		// import { translate as t, translatePlural as n } instead.
		const offenders = []
		for (const f of frontendFiles) {
			const body = readFileSync(f, 'utf8')
			for (const m of body.matchAll(/(?<![\w$.])(translate|translatePlural|trans|\$t|\$n)\(\s*['"]/g)) {
				offenders.push(`${rel(f)}: ${m[1]}(…)`)
			}
		}
		assert.deepEqual(offenders, [])
	})

	it('no call-site omits the app id', () => {
		// t('Some text') through a local wrapper is invisible to both passes.
		const offenders = []
		for (const f of frontendFiles) {
			const body = readFileSync(f, 'utf8')
			for (const m of body.matchAll(/(?<![\w$.'])(?:this\.)?[tn]\(\s*'((?:[^'\\]|\\.)*)'\s*[,)]/g)) {
				if (m[1] !== APP_ID && /\s/.test(m[1])) offenders.push(`${rel(f)}: t('${m[1].slice(0, 50)}')`)
			}
		}
		assert.deepEqual(offenders, [])
	})

	it('every msgid is a literal', () => {
		// t('metavox', LABELS[key]) has nothing to extract: translate each label
		// where it is written, one literal t() per value.
		const offenders = []
		for (const f of frontendFiles) {
			const body = readFileSync(f, 'utf8')
			for (const m of body.matchAll(/(?<![\w$.])(?:this\.)?[tn]\(\s*'metavox'\s*,\s*([^'\s])/g)) {
				const line = body.slice(0, m.index).split('\n').length
				offenders.push(`${rel(f)}:${line}`)
			}
		}
		assert.deepEqual(offenders, [])
	})

	it('every server message constant is written out for the extractor', () => {
		// ExceptionMiddleware translates a message with t() on a variable, which
		// the bot cannot read. A message held in a constant is therefore also
		// written as Translatable::t('…') beside it (see lib/L10n/Translatable.php).
		const extracted = new Set(computeSourceStrings().singulars)
		const missing = []
		for (const f of walk(join(ROOT, 'lib'), ['.php'])) {
			const body = readFileSync(f, 'utf8')
			for (const m of body.matchAll(/const (MSG_\w+|\w+_MESSAGE) = '((?:[^'\\]|\\.)*)';/g)) {
				if (!extracted.has(m[2].replace(/\\(['\\])/g, '$1'))) missing.push(`${rel(f)}: ${m[1]}`)
			}
		}
		assert.deepEqual(missing, [])
	})

	it('mentions the tag only to start a hint', () => {
		// xgettext starts a hint at the word TRANSLATORS anywhere in a comment, so an
		// ordinary comment that names the tag, directly above a real hint, would be
		// glued onto it in the bot's POT. Outside a hint, write "translator note".
		const offenders = []
		for (const f of walk(ROOT, ['.vue', '.js', '.php']).filter((x) => /^(src|lib|templates)\//.test(rel(x)))) {
			readFileSync(f, 'utf8').split('\n').forEach((line, i) => {
				if (line.includes('TRANSLATORS') && !/(\/\/|\/\*|^\s*\*|<!--)\s*TRANSLATORS\b/.test(line)) {
					offenders.push(`${rel(f)}:${i + 1}`)
				}
			})
		}
		assert.deepEqual(offenders, [])
	})
})
