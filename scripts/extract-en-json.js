#!/usr/bin/env node
/**
 * Extract translatable strings into l10n/en.json — the canonical English source
 * that scripts/generate-pot.js turns into translationfiles/templates/metavox.pot.
 *
 * Why this exists: en.json is the source-of-truth for frontend (Vue/JS) strings,
 * but the Nextcloud Transifex sync-bot deletes it from the repo after each sync
 * (it is a build artifact, gitignored). It therefore has to be regenerated from
 * the code before every POT round. This script does that deterministically by
 * scanning the source for translation calls, so en.json is never hand-edited or
 * restored from git.
 *
 * What it scans:
 *   - src/**\/*.{vue,js}   t('metavox', '…')   this.t('metavox', '…')
 *                          n('metavox', 's', 'p', …)   this.n('metavox', …)
 *   - lib/**\/*.php        $l->t('…')   ->t('…')   ->n('…', '…', …)
 *                          Translatable::t('…') (a message ExceptionMiddleware translates)
 *   - templates/**\/*.php  $l->t('…')
 *
 * This extractor is deliberately more permissive than the Nextcloud bot: it
 * also recognises translate()/translatePlural() and calls without the app id.
 * The bot does not (it runs xgettext --keyword=t:2 --keyword=n:2,3), so such a
 * call would put a string in our POT that never reaches Transifex.
 * tests/js/l10n-bot-visibility.test.js fails on exactly those call forms, so
 * the two extractions stay equal.
 *
 * The (?<![\w$.]) lookbehind before the JS helper name stops us matching the
 * tail of an identifier (impor`t(`, spli`t(`) or a property access
 * (window`.t(`), so a wrapper's own `window.t('metavox', text)` never makes
 * 'metavox' a msgid.
 *
 * Output structure (matches the NC bot's en.json):
 *   { "translations": { "<msgid>": "<msgid>",
 *                       "_<singular>_::_<plural>_": ["<singular>", "<plural>"] } }
 *
 * This module also exports computeSourceStrings() so check-l10n-sync.js,
 * lint-l10n-strings.js and the bot-visibility test share one set of regexes.
 *
 * Usage: node scripts/extract-en-json.js
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..');
const OUT = path.join(ROOT, 'l10n', 'en.json');
const COUNT = path.join(ROOT, 'l10n', '.source-count.json');
const MANIFEST = path.join(ROOT, 'l10n', '.source-strings.json');

// A single- or double-quoted JS/PHP string literal, allowing backslash escapes.
// Group 1 holds a single-quoted body, group 2 a double-quoted one.
const STR = `(?:'((?:[^'\\\\]|\\\\.)*)'|"((?:[^"\\\\]|\\\\.)*)")`;
const APP = `(?:(?:'metavox'|"metavox")\\s*,\\s*)`;
const JS_T = `(?<![\\w$.])(?:this\\.)?(?:t|translate)`;
const JS_N = `(?<![\\w$.])(?:this\\.)?(?:n|translatePlural)`;
// t('metavox', variable) has no literal msgid; without this guard the optional
// app id would backtrack and make 'metavox' itself the msgid.
const NOT_APP = `(?!(?:'metavox'|"metavox")\\s*[,)])`;

// Factory functions so every caller gets fresh RegExp instances (a shared /g
// regex carries lastIndex state between files).
function makeJsRegexes() {
	return {
		T_RE: new RegExp(`${JS_T}\\(\\s*${APP}?${NOT_APP}${STR}`, 'g'),
		N_RE: new RegExp(`${JS_N}\\(\\s*${APP}?${NOT_APP}${STR}\\s*,\\s*${STR}`, 'g'),
	};
}

function makePhpRegexes() {
	return {
		// ->t() is IL10N; ::t() is Translatable::t(), a message the middleware translates.
		T_RE: new RegExp(`(?:->|::)t\\(\\s*${STR}`, 'g'),
		N_RE: new RegExp(`->n\\(\\s*${STR}\\s*,\\s*${STR}`, 'g'),
	};
}

// Un-escape a captured literal into its real string value, the way xgettext
// reads a JavaScript string (\n, \t, \', \", \\ and \uXXXX).
function unescapeJs(raw) {
	return raw.replace(/\\(u([0-9a-fA-F]{4})|.)/g, (_, ch, hex) => {
		if (hex) return String.fromCharCode(parseInt(hex, 16));
		if (ch === 'n') return '\n';
		if (ch === 't') return '\t';
		return ch;
	});
}

// PHP single-quoted strings only know \' and \\; double-quoted ones also \n, \t.
function unescapePhp(raw, doubleQuoted) {
	if (!doubleQuoted) return raw.replace(/\\(['\\])/g, '$1');
	return raw.replace(/\\(["\\nt$])/g, (_, ch) => (ch === 'n' ? '\n' : ch === 't' ? '\t' : ch));
}

// Pick the literal out of a match: group a (single) or b (double).
function literal(m, a, php) {
	if (m[a] !== undefined) return php ? unescapePhp(m[a], false) : unescapeJs(m[a]);
	return php ? unescapePhp(m[a + 1], true) : unescapeJs(m[a + 1]);
}

// Recursively collect files under dir matching one of the extensions.
function walk(dir, exts, acc = []) {
	let entries;
	try {
		entries = fs.readdirSync(dir, { withFileTypes: true });
	} catch (e) {
		return acc;
	}
	for (const entry of entries) {
		const full = path.join(dir, entry.name);
		if (entry.isDirectory()) {
			if (entry.name === 'node_modules' || entry.name === '.git') continue;
			walk(full, exts, acc);
		} else if (exts.some(ext => entry.name.endsWith(ext))) {
			acc.push(full);
		}
	}
	return acc;
}

/**
 * The translation calls in one file, in the order found.
 *
 * @param {string} file absolute path; .php is read as PHP, anything else as JS/Vue
 * @returns {{ singulars: string[], plurals: [string,string][], hints: Map<string,string[]> }}
 *   hints: the TRANSLATORS comments per msgid, each a list of lines
 */
function stringsInFile(file) {
	const php = file.endsWith('.php');
	const content = fs.readFileSync(file, 'utf8');
	const { T_RE, N_RE } = php ? makePhpRegexes() : makeJsRegexes();
	const singulars = [];
	const plurals = [];
	const hints = new Map(); // msgid -> [hint lines]
	const lines = content.split('\n');
	const templateEnd = file.endsWith('.vue') ? content.indexOf('<script') : -1;
	const hinted = new Set(); // lines whose hint went to their first string
	const note = (msgid, callIndex, literalIndex) => {
		// A Vue template string is placed by its call, a JS/PHP one by its
		// literal: xgettext only attaches a comment that directly precedes the
		// line the msgid literal is on.
		const index = templateEnd > 0 && callIndex < templateEnd ? callIndex : literalIndex;
		const lineNo = content.slice(0, index).split('\n').length - 1;
		if (hinted.has(lineNo)) return;
		const column = index - (content.lastIndexOf('\n', index - 1) + 1);
		const hint = templateEnd > 0 && index < templateEnd
			? vueHint(lines, lineNo, column)
			: commentHint(lines, lineNo);
		if (!hint) return;
		hinted.add(lineNo);
		const list = hints.get(msgid) || [];
		if (!list.includes(hint.join('\n'))) list.push(hint.join('\n'));
		hints.set(msgid, list);
	};
	const found = [];
	// Where the msgid literal starts: after the call, its parenthesis and the app id.
	const literalAt = (m) => m.index + m[0].match(/^[^(]*\(\s*(?:(?:'metavox'|"metavox")\s*,\s*)?/)[0].length;
	let m;
	while ((m = N_RE.exec(content)) !== null) {
		const sing = literal(m, 1, php);
		if (sing) {
			plurals.push([sing, literal(m, 3, php)]);
			found.push([m.index, literalAt(m), sing]);
		}
	}
	while ((m = T_RE.exec(content)) !== null) {
		const s = literal(m, 1, php);
		if (s) {
			singulars.push(s);
			found.push([m.index, literalAt(m), s]);
		}
	}
	// In source order, so a line's hint goes to its first string, as in xgettext.
	for (const [callIndex, literalIndex, msgid] of found.sort((a, b) => a[1] - b[1])) note(msgid, callIndex, literalIndex);
	return { singulars, plurals, hints };
}

/**
 * The TRANSLATORS hint of a string in a Vue <template>, the way
 * translationtool reads it: from the rest of the string's own line (after
 * the call), or else the line directly above. One line only.
 */
function vueHint(lines, lineNo, column) {
	// A CRLF file leaves "\r" after "-->"; the bot's trim() drops it, so do the same.
	const line = [lines[lineNo].slice(column), lines[lineNo - 1] || '']
		.map(l => l.replace(/\r$/, ''))
		.find(l => l.includes('TRANSLATORS'));
	if (!line) return null;
	const text = line.slice(line.indexOf('TRANSLATORS')).replace(/\s*-->.*$/, '').trim();
	return [text.replace(/\s+/g, ' ')];
}

/**
 * The TRANSLATORS hint of a string in JS or PHP, the way
 * xgettext --add-comments=TRANSLATORS reads it: the comment block directly
 * above the line, from the line that says TRANSLATORS down.
 */
function commentHint(lines, lineNo) {
	const block = [];
	for (let i = lineNo - 1; i >= 0 && /^\s*(\/\/|\/\*|\*)/.test(lines[i]); i--) block.unshift(lines[i].replace(/\r$/, ''));
	const start = block.findIndex(l => l.includes('TRANSLATORS'));
	if (start === -1) return null;
	return block.slice(start)
		.map(l => l.replace(/^\s*(\/\/+|\/\*+|\*\/|\*)\s?/, '').replace(/\s*\*\/\s*$/, '').trim())
		.map((l, i) => (i === 0 ? l.slice(l.indexOf('TRANSLATORS')) : l))
		.filter(Boolean);
}

/**
 * Scan src/ (JS/Vue) + lib/ and templates/ (PHP) for translation calls and
 * return the deterministic source string set.
 *
 * @returns {{ singulars: string[], plurals: [string,string][], files: string[] }}
 *   sorted (localeCompare); a string used in n() is a plural pair and never
 *   also appears in singulars.
 */
function computeSourceStrings() {
	const singulars = new Set();
	const plurals = new Map(); // singular -> plural

	const jsFiles = walk(path.join(ROOT, 'src'), ['.vue', '.js']);
	const phpFiles = [
		...walk(path.join(ROOT, 'lib'), ['.php']),
		...walk(path.join(ROOT, 'templates'), ['.php']),
	];

	const hints = new Map(); // msgid -> [hint, …], in file order
	for (const file of [...jsFiles, ...phpFiles]) {
		const found = stringsInFile(file);
		for (const [sing, plur] of found.plurals) plurals.set(sing, plur);
		for (const s of found.singulars) singulars.add(s);
		for (const [msgid, list] of found.hints) {
			const all = hints.get(msgid) || [];
			for (const h of list) if (!all.includes(h)) all.push(h);
			hints.set(msgid, all);
		}
	}

	// A string used in n() is a plural entry, not a bare singular.
	for (const sing of plurals.keys()) {
		singulars.delete(sing);
	}

	const sortedSingulars = [...singulars].sort((a, b) => a.localeCompare(b));
	const sortedPlurals = [...plurals.keys()]
		.sort((a, b) => a.localeCompare(b))
		.map(s => [s, plurals.get(s)]);

	return { singulars: sortedSingulars, plurals: sortedPlurals, hints, files: [...jsFiles, ...phpFiles] };
}

/** The l10n/<lang>.json key Nextcloud uses for a plural pair. */
function pluralKey(singular, plural) {
	return `_${singular}_::_${plural}_`;
}

/**
 * The canonical, order-stable list of every source msgid (singulars + the
 * singular form of each plural), and its sha256. This is what the manifest and
 * the guard compare on — durable across POT deletes by the bot.
 */
function sourceStringManifest(src) {
	const msgids = [...src.singulars, ...src.plurals.map(([s]) => s)]
		.sort((a, b) => a.localeCompare(b));
	const sha256 = crypto.createHash('sha256')
		.update(JSON.stringify(msgids))
		.digest('hex');
	return { msgids, sha256, count: msgids.length };
}

module.exports = { computeSourceStrings, sourceStringManifest, pluralKey, stringsInFile };

// ---- CLI ----
if (require.main === module) {
	const src = computeSourceStrings();

	const translations = {};
	for (const s of src.singulars) {
		translations[s] = s;
	}
	for (const [s, p] of src.plurals) {
		translations[pluralKey(s, p)] = [s, p];
	}

	const out = { translations, pluralForm: 'nplurals=2; plural=(n != 1);' };
	fs.mkdirSync(path.dirname(OUT), { recursive: true });
	fs.writeFileSync(OUT, JSON.stringify(out, null, 4) + '\n');

	// Committed count file — the denominator for a coverage percentage, since
	// l10n/en.json is a gitignored build artifact.
	const sourceStrings = src.singulars.length + src.plurals.length;
	fs.writeFileSync(COUNT, JSON.stringify({ sourceStrings }, null, 4) + '\n');

	// Committed source-string manifest — the durable record of exactly which
	// msgids the Transifex bot has been given. check-l10n-sync.js fails the
	// build if the code's set no longer matches it.
	const manifest = sourceStringManifest(src);
	fs.writeFileSync(MANIFEST, JSON.stringify({
		count: manifest.count,
		sha256: manifest.sha256,
		strings: manifest.msgids,
	}, null, 4) + '\n');

	console.log(`Extracted ${src.singulars.length} singular + ${src.plurals.length} plural strings`);
	console.log(`Scanned ${src.files.length} files`);
	console.log(`Wrote ${path.relative(ROOT, OUT)}`);
	console.log(`Wrote ${path.relative(ROOT, COUNT)} (sourceStrings=${sourceStrings})`);
	console.log(`Wrote ${path.relative(ROOT, MANIFEST)} (count=${manifest.count}, sha256=${manifest.sha256.slice(0, 12)}…)`);
}
