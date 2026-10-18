// Compares every translation with l10n/en.json (generated: run
// scripts/extract-en-json.js first): missing, extra and empty
// texts, and texts still in English. Reports only for now; pass --strict to
// fail on missing or extra texts once fr and sv are complete.

import { readFileSync, readdirSync } from 'node:fs'

const dir = new URL('../l10n/', import.meta.url)
const read = (file) => JSON.parse(readFileSync(new URL(file, dir), 'utf8')).translations
const source = read('en.json')
const keys = Object.keys(source)
let broken = false

// The dot-files are the sync guard's manifests, not bundles.
for (const file of readdirSync(dir).filter((f) => f.endsWith('.json') && f !== 'en.json' && !f.startsWith('.')).sort()) {
	const t = read(file)
	const missing = keys.filter((k) => !(k in t))
	const extra = Object.keys(t).filter((k) => !(k in source))
	const empty = keys.filter((k) => k in t && String(t[k]).trim() === '')
	const english = keys.filter((k) => t[k] === source[k])
	console.log(`${file}: ${missing.length} missing, ${extra.length} extra, ${empty.length} empty, ${english.length} still English`)
	for (const k of missing) console.log(`  missing: ${k}`)
	for (const k of extra) console.log(`  extra: ${k}`)
	broken ||= missing.length > 0 || extra.length > 0
}

if (broken && process.argv.includes('--strict')) process.exit(1)
