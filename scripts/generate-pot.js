#!/usr/bin/env node
/**
 * Generate translationfiles/templates/metavox.pot from the source.
 *
 * The string set comes from scripts/extract-en-json.js (computeSourceStrings),
 * which scans src/ (Vue/JS) and lib/ + templates/ (PHP). One extractor for
 * both halves keeps the POT, l10n/en.json and the sync manifest identical.
 * xgettext is not used here: it cannot read Vue templates, and its wrapped
 * multi-line msgids are easy to mangle when merged back in.
 *
 * Note: the Nextcloud sync bot regenerates this file with its own extractor
 * (translationtool create-pot-files) before it pushes to Transifex. This POT is
 * the reviewable record and the input for scripts/json-to-po.js;
 * tests/js/l10n-bot-visibility.test.js keeps the two extractions equal.
 *
 * Usage:
 *   npm run pot                    (regenerates en.json + manifest, then POT)
 *   node scripts/generate-pot.js
 *
 * TRANSLATORS comments above a string in the source become #. lines, the way
 * the bot's xgettext run writes them, so the hints can be checked here.
 *
 * Output: translationfiles/templates/metavox.pot (overwrites previous)
 */

const fs = require('fs');
const path = require('path');
const { computeSourceStrings } = require('./extract-en-json.js');

const ROOT = path.resolve(__dirname, '..');
const POT = path.join(ROOT, 'translationfiles', 'templates', 'metavox.pot');

const src = computeSourceStrings();
const VERSION = JSON.parse(fs.readFileSync(path.join(ROOT, 'package.json'), 'utf8')).version;

// Escape a string for a PO `msgid "..."` line.
function escapePo(s) {
	return s
		.replace(/\\/g, '\\\\')
		.replace(/"/g, '\\"')
		.replace(/\n/g, '\\n')
		.replace(/\t/g, '\\t');
}

// The TRANSLATORS hints as extracted comments (#.), as xgettext writes them.
function comments(msgid) {
	return (src.hints.get(msgid) || [])
		.flatMap(hint => hint.split('\n'))
		.map(line => `#. ${line}\n`)
		.join('');
}

function poEntry(msgid) {
	return `${comments(msgid)}msgid "${escapePo(msgid)}"\nmsgstr ""\n`;
}

// Plural entries get a msgid_plural block, so %n is a real gettext plural.
function poPluralEntry(singular, plural) {
	return `${comments(singular)}msgid "${escapePo(singular)}"\n` +
		`msgid_plural "${escapePo(plural)}"\n` +
		`msgstr[0] ""\nmsgstr[1] ""\n`;
}

console.log(`Generating POT: ${src.singulars.length} singular + ${src.plurals.length} plural strings`);

const now = new Date().toISOString().replace('T', ' ').replace(/:\d+\.\d+Z$/, '+0000');
const header = `# MetaVox translation template.
# Copyright (C) ${new Date().getFullYear()} VoxCloud
# This file is distributed under the AGPL-3.0-or-later license.
#
msgid ""
msgstr ""
"Project-Id-Version: MetaVox ${VERSION}\\n"
"Report-Msgid-Bugs-To: translations@nextcloud.com\\n"
"POT-Creation-Date: ${now}\\n"
"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n"
"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n"
"Language-Team: LANGUAGE <LL@li.org>\\n"
"Language: \\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"

`;

const body = [
	...src.singulars.map(poEntry),
	...src.plurals.map(([s, p]) => poPluralEntry(s, p)),
].join('\n');

// translationfiles/ may not exist on a fresh checkout after a bot sync.
fs.mkdirSync(path.dirname(POT), { recursive: true });
fs.writeFileSync(POT, header + body);
console.log(`Wrote ${path.relative(ROOT, POT)} (${src.singulars.length + src.plurals.length} msgids)`);
