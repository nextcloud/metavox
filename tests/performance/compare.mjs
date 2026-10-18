// Judges a profiler export of run.mjs's requests, and compares it with the run
// on the code before the change when there is one:
//  - no request may need more database queries than before (Nextcloud's own
//    reload of its app config and its last-login and token-activity writes
//    aside: which request gets those is chance);
//  - MetaVox's own queries may not grow with the number of files (100 vs 1,000).
// Memory and times are shown, not judged, as in Nextcloud's own check: PHP
// reports memory in 2 MB steps and keeps some from the requests before, and a
// CI runner is too noisy for times. A request the code before did not have (a
// new route) is new: nothing to compare it with.
// usage: compare.mjs <labels.json> <after.json> [<before-labels.json> <before.json>]

import { readFileSync } from 'node:fs'

const [labelsPath, afterPath, beforeLabelsPath, beforePath] = process.argv.slice(2)
const labels = JSON.parse(readFileSync(labelsPath, 'utf8'))
const GROWTH_ALLOWED = 2 // a few chunks more for ten times the files

// Known to grow with the files, and on the list to fix. Each is held to how
// fast it grows today (large / small), so it can only get better.
const KNOWN_GROWTH = {}

const isMetaVox = (query) => (query.backtrace ?? []).some((frame) => String(frame.file ?? '').includes('/apps/metavox/lib/'))

// Nextcloud keeps its app config a few seconds in the local cache and then
// loads it again: which request pays for that is chance, not the change.
// Shown, not judged.
const isCacheRefresh = (sql) => /FROM `oc_appconfig` WHERE `lazy` = /.test(sql)
// It writes a user's last login at most once a minute, on whichever request
// comes then (User::updateLastLoginTimestamp): chance as well.
const isLastLogin = (query) => (query.backtrace ?? []).some((frame) => frame.function === 'updateLastLoginTimestamp')
	|| Object.values(query.params ?? {}).includes('lastLogin')
// And the last activity of the login token, at most once a minute too
// (PublicKeyTokenProvider::updateTokenActivity).
const isTokenActivity = (sql) => /^UPDATE `oc_authtoken` SET `last_activity` = /.test(sql)
const isChance = (query) => isCacheRefresh(query.sql) || isLastLogin(query) || isTokenActivity(query.sql)

/** The profiles of the requests, by label. Only the code before may miss a route. */
function read(labels, path, { before = false } = {}) {
	const profiles = JSON.parse(readFileSync(path, 'utf8')).filter((p) => p.method !== 'occ')
	const byLabel = {}
	let i = 0
	for (const l of labels) {
		const p = profiles[i]
		const matches = p && p.method === l.method && decodeURIComponent(p.url) === decodeURIComponent(l.url)
		if (before && l.status === 404) {
			// Nextcloud may or may not profile a request without a route.
			if (matches) i++
			continue
		}
		if (!matches) {
			throw new Error(`${path}: profile ${i} is ${p?.method} ${p?.url}, expected ${l.method} ${l.url}`)
		}
		i++
		const queries = Object.values(p.collectors?.db?.queries ?? {})
		byLabel[l.label] = {
			queries: queries.map((q) => q.sql),
			judged: queries.filter((q) => !isChance(q)).map((q) => q.sql),
			metavox: queries.filter(isMetaVox).map((q) => q.sql),
			memory: p.collectors?.memory?.memory ?? 0,
			ms: l.ms,
		}
	}
	if (i !== profiles.length) {
		throw new Error(`${path}: ${profiles.length} profiles for ${i} requests`)
	}
	return byLabel
}

/** Queries in `after` that `before` does not have (counting repeats). */
function added(before, after) {
	const left = new Map()
	for (const sql of before) left.set(sql, (left.get(sql) ?? 0) + 1)
	return after.filter((sql) => {
		const n = left.get(sql) ?? 0
		if (n > 0) {
			left.set(sql, n - 1)
			return false
		}
		return true
	})
}

const after = read(labels, afterPath)
const before = beforePath ? read(JSON.parse(readFileSync(beforeLabelsPath, 'utf8')), beforePath, { before: true }) : null
const problems = []
const mb = (bytes) => (bytes / 1024 / 1024).toFixed(0)

console.log('request'.padEnd(36) + 'queries'.padStart(14) + '  MetaVox'.padStart(14) + '  memory MB'.padStart(14) + '  ms')
for (const { label } of labels) {
	const a = after[label]
	const b = before?.[label]
	const cell = (x, y) => (b ? `${y} -> ${x}` : before ? `new ${x}` : String(x)).padStart(14)
	console.log(label.padEnd(36) + cell(a.queries.length, b?.queries.length) + cell(a.metavox.length, b?.metavox.length)
		+ cell(mb(a.memory), b && mb(b.memory)) + `  ${a.ms}`)

	if (b && a.judged.length > b.judged.length) {
		problems.push(`${label}: ${a.judged.length - b.judged.length} more queries\n` + added(b.judged, a.judged).map((sql) => `      + ${sql.slice(0, 220)}`).join('\n'))
	}
	if (label.endsWith(' large')) {
		const small = after[label.replace(/ large$/, ' small')]
		const known = KNOWN_GROWTH[label.replace(/ large$/, '')]
		if (small && known) {
			if (a.metavox.length > small.metavox.length * known.factor) {
				problems.push(`${label}: grows faster than the known ${known.factor}x (${small.metavox.length} -> ${a.metavox.length}; ${known.reason})`)
			}
		} else if (small && a.metavox.length > small.metavox.length + GROWTH_ALLOWED) {
			problems.push(`${label}: MetaVox's queries grow with the files (${small.metavox.length} for 100, ${a.metavox.length} for 1,000)\n`
				+ added(small.metavox, a.metavox).slice(0, 5).map((sql) => `      + ${sql.slice(0, 220)}`).join('\n'))
		}
	}
}

if (!before) console.log('\nNo run on the code before this change: only checked that queries do not grow with the files.')
if (problems.length) {
	console.log('\nPossible performance regression:\n' + problems.map((p) => '  - ' + p).join('\n'))
	process.exit(1)
}
console.log('\nOK: no extra queries, nothing grows with the number of files.')
