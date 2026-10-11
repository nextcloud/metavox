#!/usr/bin/env node
/**
 * Seed translationfiles/<lang>/metavox.po from the hand-maintained
 * l10n/<lang>.json bundles.
 *
 * MetaVox was translated by hand before it moved to the Transifex PO workflow.
 * Those translations are real work; handing Transifex an empty resource would
 * restart every language from zero. This script carries them across as the
 * initial PO content.
 *
 * Only strings that exist in the current POT are emitted — entries for strings
 * that no longer occur in the source are left out rather than shipped as
 * obsolete msgids. Plural entries are looked up under Nextcloud's
 * "_<singular>_::_<plural>_" key and keep the language's own plural rule.
 *
 * A value identical to its msgid is left out: the bundles hold English copies
 * that were never translated (French had over 300), and seeding those would
 * report them to Transifex as done and hide the gap from translators. The few
 * that are genuinely the same word ("Status", "URL") cost a translator one click.
 * Pass --keep-identical to seed them anyway.
 *
 * Usage (after `npm run pot`):
 *   node scripts/json-to-po.js            all languages in l10n/*.json except en
 *   node scripts/json-to-po.js de nl      only these
 */

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const L10N = path.join(ROOT, 'l10n');
const POT = path.join(ROOT, 'translationfiles', 'templates', 'metavox.pot');
const VERSION = JSON.parse(fs.readFileSync(path.join(ROOT, 'package.json'), 'utf8')).version;

const args = process.argv.slice(2)
const KEEP_IDENTICAL = args.includes('--keep-identical');
const named = args.filter(a => !a.startsWith('--'));

// Never type the language list: every l10n/<lang>.json is a language.
const LANGS = named.length > 0
	? named
	: fs.readdirSync(L10N)
		.filter(f => /^[a-z]{2,3}(_[A-Z]{2})?\.json$/.test(f) && f !== 'en.json')
		.map(f => f.replace(/\.json$/, ''))
		.sort();

function escapePo(s) {
	return s
		.replace(/\\/g, '\\\\')
		.replace(/"/g, '\\"')
		.replace(/\n/g, '\\n')
		.replace(/\t/g, '\\t');
}

const unescapePo = (s) => s.replace(/\\(.)/g, (_, ch) => (ch === 'n' ? '\n' : ch === 't' ? '\t' : ch));

/** Read the msgids (and plural pairs) the POT declares. */
function readPot() {
	const text = fs.readFileSync(POT, 'utf8');
	const singular = [];
	const plural = []; // [singular, plural]
	for (const block of text.split(/\n\n+/)) {
		const idMatch = block.match(/^msgid "((?:[^"\\]|\\.)*)"/m);
		if (!idMatch) continue;
		const id = unescapePo(idMatch[1]);
		if (id === '') continue; // header
		const plMatch = block.match(/^msgid_plural "((?:[^"\\]|\\.)*)"/m);
		if (plMatch) plural.push([id, unescapePo(plMatch[1])]);
		else singular.push(id);
	}
	return { singular, plural };
}

const { singular, plural } = readPot();
const total = singular.length + plural.length;

for (const lang of LANGS) {
	const jsonPath = path.join(L10N, `${lang}.json`);
	if (!fs.existsSync(jsonPath)) {
		console.warn(`  ${lang}: no l10n/${lang}.json — skipped`);
		continue;
	}
	const data = JSON.parse(fs.readFileSync(jsonPath, 'utf8'));
	const translations = data.translations || {};
	const pluralForm = data.pluralForm || 'nplurals=2; plural=(n != 1);';
	const nplurals = Number((pluralForm.match(/nplurals\s*=\s*(\d+)/) || [])[1] || 2);

	const header = `# MetaVox ${lang} translation.
# Carried over from the pre-Transifex l10n/${lang}.json bundle.
#
msgid ""
msgstr ""
"Project-Id-Version: MetaVox ${VERSION}\\n"
"Report-Msgid-Bugs-To: translations@nextcloud.com\\n"
"PO-Revision-Date: ${new Date().toISOString().slice(0, 10)} 00:00+0000\\n"
"Last-Translator: MetaVox <translations@nextcloud.com>\\n"
"Language-Team: ${lang}\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"Language: ${lang}\\n"
"Plural-Forms: ${pluralForm}\\n"
`;

	const entries = [];
	let translated = 0;

	for (const msgid of singular) {
		const value = translations[msgid];
		let str = typeof value === 'string' ? value : '';
		if (str === msgid && !KEEP_IDENTICAL) str = '';
		if (str) translated++;
		entries.push(`msgid "${escapePo(msgid)}"\nmsgstr "${escapePo(str)}"`);
	}

	for (const [msgid, msgidPlural] of plural) {
		const value = translations[`_${msgid}_::_${msgidPlural}_`];
		let forms = Array.isArray(value) ? value : [];
		if (!KEEP_IDENTICAL && forms.length && forms.every((f, i) => f === (i === 0 ? msgid : msgidPlural))) forms = [];
		if (forms[0]) translated++;
		const lines = [`msgid "${escapePo(msgid)}"`, `msgid_plural "${escapePo(msgidPlural)}"`];
		for (let i = 0; i < nplurals; i++) {
			// A form the JSON lacks repeats the last one it has, or stays empty.
			const form = forms.length ? (forms[i] ?? forms[forms.length - 1]) : '';
			lines.push(`msgstr[${i}] "${escapePo(form)}"`);
		}
		entries.push(lines.join('\n'));
	}

	const outDir = path.join(ROOT, 'translationfiles', lang);
	fs.mkdirSync(outDir, { recursive: true });
	fs.writeFileSync(path.join(outDir, 'metavox.po'), header + '\n' + entries.join('\n\n') + '\n', 'utf8');

	const pct = Math.round((translated / total) * 100);
	console.log(`  ${lang}: ${translated}/${total} translated (${pct}%) → translationfiles/${lang}/metavox.po`);
}
