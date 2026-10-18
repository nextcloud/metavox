// Measures the search on the scale test's world, the same request twice: the
// way before the value index (by setting the "index is being filled" flag,
// which makes the filter read the metadata itself) and with the index. Each
// cold (database just restarted) and warm (median of 5), with what the
// database read: rows, and pages from disk. See README.md.
// env: SCALE_PASS (the test users' password); NC_BASE (default 127.0.0.1:8035);
//      SCALE_OUT (the results as JSON, default /tmp/metavox-scale-results.json)
import { execFileSync } from 'node:child_process'
import { writeFileSync } from 'node:fs'

const BASE = (process.env.NC_BASE ?? 'http://127.0.0.1:8035').replace(/\/$/, '')
const PASS = process.env.SCALE_PASS ?? (() => { throw new Error('Set SCALE_PASS') })()
const WARM_RUNS = 5
const dir = new URL('.', import.meta.url).pathname

const sh = (cmd, args, opts = {}) => execFileSync(cmd, args, { cwd: dir, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], ...opts }).trim()
const sql = (query) => sh('docker', ['compose', 'exec', '-T', 'db', 'mariadb', '-uroot', '-pscale-root', '-N', '-B', 'nextcloud', '-e', query])
const occ = (...args) => sh('docker', ['compose', 'exec', '-T', '-u', 'www-data', 'app', 'php', 'occ', ...args])

// Rows: what MariaDB's handlers read, by key or in order.
const ROWS = ['Handler_read_first', 'Handler_read_key', 'Handler_read_last', 'Handler_read_next', 'Handler_read_prev', 'Handler_read_rnd', 'Handler_read_rnd_next']
const STATS = ['Innodb_buffer_pool_reads', 'Innodb_buffer_pool_read_requests', ...ROWS]
function stats() {
	const out = { rows: 0 }
	for (const line of sql(`SHOW GLOBAL STATUS WHERE Variable_name IN ('${STATS.join("','")}')`).split('\n')) {
		const [name, value] = line.split('\t')
		out[name] = Number(value)
		if (ROWS.includes(name)) out.rows += Number(value)
	}
	return out
}

async function coldDatabase() {
	sh('docker', ['compose', 'restart', 'db'])
	for (let i = 0; i < 60; i++) {
		try {
			sql('SELECT 1')
			return
		} catch {
			await new Promise((resolve) => setTimeout(resolve, 1000))
		}
	}
	throw new Error('database did not come back')
}

// App passwords: checking the real password costs about half a second a request.
const tokens = {}
async function login(user) {
	const res = await fetch(`${BASE}/ocs/v2.php/core/getapppassword?format=json`, {
		headers: { Authorization: 'Basic ' + Buffer.from(`${user}:${PASS}`).toString('base64'), 'OCS-APIRequest': 'true', Accept: 'application/json' },
	})
	if (res.status !== 200) throw new Error(`login ${user}: ${res.status}`)
	tokens[user] = 'Basic ' + Buffer.from(`${user}:${(await res.json()).ocs.data.apppassword}`).toString('base64')
}
async function logout(user) {
	await fetch(`${BASE}/ocs/v2.php/core/apppassword?format=json`, { method: 'DELETE', headers: { Authorization: tokens[user], 'OCS-APIRequest': 'true' } })
}

async function request(user, path, query) {
	const url = new URL(BASE + path)
	for (const [k, v] of Object.entries({ format: 'json', ...query })) url.searchParams.set(k, String(v))
	const start = performance.now()
	const res = await fetch(url, { headers: { Authorization: tokens[user], 'OCS-APIRequest': 'true', Accept: 'application/json' } })
	const body = await res.json().catch(() => null)
	const ms = performance.now() - start
	if (res.status !== 200) throw new Error(`${path}: ${res.status}`)
	const data = body?.ocs?.data ?? body
	return { ms, n: data?.entries?.length ?? data?.values?.length ?? 0, cursor: data?.cursor, more: data?.isPaginated }
}

const SEARCH = '/ocs/v2.php/search/providers/metavox_metadata/search'
const median = (xs) => [...xs].sort((a, b) => a - b)[Math.floor(xs.length / 2)]

// One measured request: follows the cursor first when a later page is asked.
async function once(c) {
	let query = { ...c.query }
	for (let page = 1; page < (c.page ?? 1); page++) {
		const r = await request(c.user, c.path ?? SEARCH, query)
		query = { ...query, cursor: r.cursor }
	}
	const before = stats()
	const r = await request(c.user, c.path ?? SEARCH, query)
	const after = stats()
	return { ...r, disk: after.Innodb_buffer_pool_reads - before.Innodb_buffer_pool_reads, pages: after.Innodb_buffer_pool_read_requests - before.Innodb_buffer_pool_read_requests, rows: after.rows - before.rows }
}

async function measure(c) {
	await coldDatabase()
	const cold = await once(c)
	const warm = []
	for (let i = 0; i < WARM_RUNS; i++) warm.push(await once(c))
	return { cold, warm: { ms: median(warm.map((w) => w.ms)), rows: median(warm.map((w) => w.rows)), pages: median(warm.map((w) => w.pages)), n: warm[0].n } }
}

const folder = Object.fromEntries(sql('SELECT n, folder_id FROM scale_folders WHERE n IN (1, 2)').split('\n').map((l) => l.split('\t').map(Number)))
const unique = sql(`SELECT field_value FROM oc_metavox_file_gf_meta WHERE groupfolder_id = ${folder[1]} AND field_name = 'file_gf_projectcode' LIMIT 1`)
const filter = (field, value, folderId) => ({ term: value.split(';#')[0], metavox_field: `${field}:${value}`, limit: 10, ...(folderId ? { metavox_groupfolder: folderId } : {}) })
const big = folder[1]

const ALL_CASES = [
	{ name: 'Status = Concept (25%), 500k folder', user: 'scale-big', query: filter('file_gf_status', 'Concept', big) },
	{ name: 'Status = Gearchiveerd (5%), 500k folder', user: 'scale-big', query: filter('file_gf_status', 'Gearchiveerd', big) },
	{ name: 'Klant (1 in 5,000), 500k folder', user: 'scale-big', query: filter('file_gf_klant', 'Klant 42', big) },
	{ name: 'Projectcode (1 file), 500k folder', user: 'scale-big', query: filter('file_gf_projectcode', unique, big) },
	{ name: 'Tags = Contract (list, common), 500k folder', user: 'scale-big', query: filter('file_gf_tags', 'Contract', big) },
	{ name: 'Tags = Zeldzaam (list, 0.1%), 500k folder', user: 'scale-big', query: filter('file_gf_tags', 'Zeldzaam', big) },
	{ name: 'Tags = Geheim (list, none), 500k folder', user: 'scale-big', query: filter('file_gf_tags', 'Geheim', big) },
	{ name: 'Status = Concept, page 20, 500k folder', user: 'scale-big', query: filter('file_gf_status', 'Concept', big), page: 20 },
	{ name: 'Status = Concept, all 15 team folders', user: 'scale-big', query: filter('file_gf_status', 'Concept') },
	{ name: 'Tags = Zeldzaam, all 15 team folders', user: 'scale-big', query: filter('file_gf_tags', 'Zeldzaam') },
	{ name: 'Status = Concept, 100k folder, ACL hides half', user: 'scale-acl', query: filter('file_gf_status', 'Concept', folder[2]) },
	{ name: 'Status = Concept, 15 small team folders', user: 'scale-normal', query: filter('file_gf_status', 'Concept') },
	{ name: 'Words "dossier 42", all team folders', user: 'scale-big', query: { term: 'dossier 42', limit: 10 }, words: true },
	{ name: 'Picker values of Klant, 500k folder', user: 'scale-big', path: `/index.php/apps/metavox/api/user/groupfolders/${big}/field-values`, query: { field: 'file_gf_klant' }, words: true },
]

// ONLY: a pattern to measure some cases again.
const CASES = process.env.ONLY ? ALL_CASES.filter((c) => new RegExp(process.env.ONLY).test(c.name)) : ALL_CASES
const results = []
const users = [...new Set(CASES.map((c) => c.user))]
for (const user of users) await login(user)
try {
	for (const mode of ['before', 'index']) {
		if (mode === 'before') occ('config:app:set', 'metavox', 'search_words_filling', '--value=1', '--type=boolean')
		else occ('config:app:delete', 'metavox', 'search_words_filling')
		// Nextcloud keeps app config 3 s in each web server's memory.
		await new Promise((resolve) => setTimeout(resolve, 5000))
		for (const c of CASES) {
			// Words and the picker don't use the value index: measured once.
			if (c.words && mode === 'index') continue
			const r = await measure(c)
			results.push({ mode, name: c.name, ...r })
			console.log(`${mode.padEnd(6)} ${c.name.padEnd(48)} cold ${String(Math.round(r.cold.ms)).padStart(6)} ms ${String(r.cold.disk).padStart(6)} disk reads | warm ${String(Math.round(r.warm.ms)).padStart(5)} ms ${String(r.warm.rows).padStart(8)} rows | ${r.warm.n} results`)
		}
	}
} finally {
	occ('config:app:delete', 'metavox', 'search_words_filling')
	for (const user of users) await logout(user)
}
writeFileSync(process.env.SCALE_OUT ?? '/tmp/metavox-scale-results.json', JSON.stringify(results, null, 1))
