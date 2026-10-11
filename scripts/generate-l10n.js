#!/usr/bin/env node
/**
 * Generate l10n .js files from .json files.
 *
 * Converts the Nextcloud l10n JSON format to the JavaScript format that
 * Nextcloud loads at runtime (`OC.L10N.register("metavox", {...}, "<plural-form>")`).
 *
 * IMPORTANT — output format: this reproduces the EXACT pretty-printed layout the
 * Nextcloud Transifex bot writes (multi-line, `"key" : "value"` with spaces around
 * the colon), NOT a compact JSON.stringify. MetaVox's existing de/fr/nl/sv.js are
 * in that bot format; matching it byte-for-byte means regenerating is
 * value-preserving and produces no spurious diff. Once the bot takes over it will
 * overwrite these files in the same format.
 *
 * Plural-form selection:
 *   1. If the source JSON has a `pluralForm` field, use it verbatim (this is what
 *      the NC Transifex bot writes).
 *   2. Otherwise fall back to PLURAL_OVERRIDES[lang] for languages whose plural
 *      rule diverges from the default.
 *   3. Otherwise the safe binary default (English-like languages).
 *
 * Usage: node scripts/generate-l10n.js
 */

const fs = require('fs');
const path = require('path');

const l10nDir = path.join(__dirname, '..', 'l10n');
const appId = 'metavox';

const DEFAULT_PLURAL = 'nplurals=2; plural=(n != 1);';

// Plural rules per language (base code). Used only when the source JSON has no
// explicit `pluralForm` field. MetaVox's current languages (de/fr/nl/sv) are all
// binary-plural, but keep the table for languages the bot may add later.
const PLURAL_OVERRIDES = {
	'ja':    'nplurals=1; plural=0;',
	'ko':    'nplurals=1; plural=0;',
	'zh':    'nplurals=1; plural=0;',
	'zh_CN': 'nplurals=1; plural=0;',
	'zh_HK': 'nplurals=1; plural=0;',
	'zh_TW': 'nplurals=1; plural=0;',
	'th':    'nplurals=1; plural=0;',
	'vi':    'nplurals=1; plural=0;',
	'id':    'nplurals=1; plural=0;',
	'fr':    'nplurals=2; plural=(n > 1);',
	'pt':    'nplurals=2; plural=(n > 1);',
	'pt_BR': 'nplurals=2; plural=(n > 1);',
	'pl':    'nplurals=3; plural=(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
	'ru':    'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
	'uk':    'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
	'cs':    'nplurals=3; plural=(n==1) ? 0 : (n>=2 && n<=4) ? 1 : 2;',
	'sk':    'nplurals=3; plural=(n==1) ? 0 : (n>=2 && n<=4) ? 1 : 2;',
	'sl':    'nplurals=4; plural=(n%100==1 ? 0 : n%100==2 ? 1 : n%100==3 || n%100==4 ? 2 : 3);',
	'ar':    'nplurals=6; plural=n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 ? 4 : 5;',
};

function pluralFormFor(lang, dataPluralForm) {
	if (typeof dataPluralForm === 'string' && dataPluralForm.length > 0) {
		return dataPluralForm;
	}
	if (PLURAL_OVERRIDES[lang]) return PLURAL_OVERRIDES[lang];
	const base = lang.split('_')[0];
	if (PLURAL_OVERRIDES[base]) return PLURAL_OVERRIDES[base];
	return DEFAULT_PLURAL;
}

// JSON-escape a string value exactly the way JSON.stringify does (quotes + escapes).
function jsStr(s) {
	return JSON.stringify(s);
}

// Render one translation value: a plain string, or a [singular, plural] array.
function renderValue(val) {
	if (Array.isArray(val)) {
		return '[' + val.map(jsStr).join(',') + ']';
	}
	return jsStr(val);
}

// Reproduce the NC bot's exact pretty layout:
//   OC.L10N.register(
//       "metavox",
//       {
//       "key" : "value",
//       ...
//   },
//   "<plural-form>")
function renderJs(translations, pluralForm) {
	const keys = Object.keys(translations);
	const lines = keys.map((k, i) => {
		const comma = i < keys.length - 1 ? ',' : '';
		return `    ${jsStr(k)} : ${renderValue(translations[k])}${comma}`;
	});
	return `OC.L10N.register(\n    "${appId}",\n    {\n${lines.join('\n')}\n},\n"${pluralForm}");\n`;
}

// Language bundles only: the dot-files (.source-count.json, .source-strings.json)
// are the sync guard's manifests, not translations.
const jsonFiles = fs.readdirSync(l10nDir).filter(f => f.endsWith('.json') && !f.startsWith('.'));

console.log('Generating l10n JavaScript files...');

for (const jsonFile of jsonFiles) {
	const lang = path.basename(jsonFile, '.json');
	const jsonPath = path.join(l10nDir, jsonFile);
	const jsPath = path.join(l10nDir, `${lang}.js`);

	try {
		const data = JSON.parse(fs.readFileSync(jsonPath, 'utf8'));
		const translations = data.translations || {};
		const pluralForm = pluralFormFor(lang, data.pluralForm);

		fs.writeFileSync(jsPath, renderJs(translations, pluralForm));

		const keyCount = Object.keys(translations).length;
		console.log(`  ✓ ${lang}.js (${keyCount} translations)`);
	} catch (error) {
		console.error(`  ✗ ${lang}: ${error.message}`);
	}
}

console.log('Done!');
