// Two MetaVox versions side by side on the same world (setup.sh twice, see
// README.md): each action as that version's own frontend does it, cold
// (database just restarted) and warm (median of 5), with the time, the rows
// the database read and its reads from disk.
// env: SCALE_PASS; OLD and NEW as <compose project>@<port>
//      (default metavox-scale-223@8023 and metavox-scale@8035);
//      VERSIONS_OUT (the results as JSON, default /tmp/metavox-versions.json)
import { execFileSync } from 'node:child_process'
import { writeFileSync } from 'node:fs'

const PASS = process.env.SCALE_PASS ?? (() => { throw new Error('Set SCALE_PASS') })()
const WARM_RUNS = 5
const dir = new URL('.', import.meta.url).pathname
const USER = 'scale-big'

function instance(spec, label) {
	const [project, port] = spec.split('@')
	const sh = (...args) => execFileSync('docker', ['compose', '-p', project, ...args], { cwd: dir, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim()
	return { label, project, base: `http://127.0.0.1:${port}`, sql: (q) => sh('exec', '-T', 'db', 'mariadb', '-uroot', '-pscale-root', '-N', '-B', 'nextcloud', '-e', q), sh }
}
const OLD = instance(process.env.OLD ?? 'metavox-scale-223@8023', '2.2.3')
const NEW = instance(process.env.NEW ?? 'metavox-scale@8035', 'now')

const ROWS = ['Handler_read_first', 'Handler_read_key', 'Handler_read_last', 'Handler_read_next', 'Handler_read_prev', 'Handler_read_rnd', 'Handler_read_rnd_next']
function stats(nc) {
	const out = { rows: 0, disk: 0 }
	for (const line of nc.sql(`SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_buffer_pool_reads','${ROWS.join("','")}')`).split('\n')) {
		const [name, value] = line.split('\t')
		if (name === 'Innodb_buffer_pool_reads') out.disk = Number(value)
		else out.rows += Number(value)
	}
	return out
}
async function coldDatabase(nc) {
	nc.sh('restart', 'db')
	for (let i = 0; i < 60; i++) {
		try {
			nc.sql('SELECT 1')
			return
		} catch {
			await new Promise((resolve) => setTimeout(resolve, 1000))
		}
	}
	throw new Error(`${nc.label}: database did not come back`)
}

// An app password per instance: checking the real password costs ~0.5 s a request.
async function login(nc) {
	const res = await fetch(`${nc.base}/ocs/v2.php/core/getapppassword?format=json`, {
		headers: { Authorization: 'Basic ' + Buffer.from(`${USER}:${PASS}`).toString('base64'), 'OCS-APIRequest': 'true', Accept: 'application/json' },
	})
	if (res.status !== 200) throw new Error(`${nc.label} login: ${res.status}`)
	nc.auth = 'Basic ' + Buffer.from(`${USER}:${(await res.json()).ocs.data.apppassword}`).toString('base64')
}
async function logout(nc) {
	await fetch(`${nc.base}/ocs/v2.php/core/apppassword?format=json`, { method: 'DELETE', headers: { Authorization: nc.auth, 'OCS-APIRequest': 'true' } })
}

async function send(nc, { method = 'GET', path, query, body, raw, headers = {} }) {
	const url = new URL(nc.base + path)
	for (const [k, v] of Object.entries(query ?? {})) url.searchParams.set(k, String(v))
	const init = { method, headers: { Authorization: nc.auth, 'OCS-APIRequest': 'true', Accept: 'application/json', ...headers } }
	if (raw !== undefined) init.body = raw
	else if (body !== undefined) {
		init.body = JSON.stringify(body)
		init.headers['Content-Type'] = 'application/json'
	}
	const res = await fetch(url, init)
	const text = await res.text()
	if (res.status >= 300 && res.status !== 207) throw new Error(`${nc.label} ${method} ${path}: ${res.status} ${text.slice(0, 160)}`)
	return text
}

// One action: every request it makes, measured together.
async function once(nc, requests) {
	const before = stats(nc)
	const start = performance.now()
	for (const r of requests) await send(nc, r)
	const ms = performance.now() - start
	const after = stats(nc)
	return { ms, rows: after.rows - before.rows, disk: after.disk - before.disk }
}
const median = (xs) => [...xs].sort((a, b) => a - b)[Math.floor(xs.length / 2)]
async function measure(nc, make) {
	await coldDatabase(nc)
	const cold = await once(nc, make(0))
	const warm = []
	for (let i = 1; i <= WARM_RUNS; i++) warm.push(await once(nc, make(i)))
	return { cold, warm: { ms: median(warm.map((w) => w.ms)), rows: median(warm.map((w) => w.rows)), disk: median(warm.map((w) => w.disk)) }, requests: make(0).length }
}

// The world as each instance has it: the 500,000-file team folder, its first directory of 1,000 files.
function world(nc) {
	const [folderId, storage] = nc.sql('SELECT folder_id, storage FROM scale_folders WHERE n = 1').split('\t').map(Number)
	const ids = nc.sql(`SELECT fileid FROM oc_filecache WHERE storage = ${storage} AND path LIKE 'files/d0001/%' ORDER BY name`).split('\n').map(Number)
	return { folderId, ids, dav: `/remote.php/dav/files/${USER}/Groot%20500000/d0001` }
}

const PROPS = '<d:getlastmodified/><d:getetag/><d:getcontenttype/><d:resourcetype/><oc:fileid/><oc:permissions/><oc:size/><d:getcontentlength/><nc:has-preview/><oc:favorite/><oc:comments-unread/><oc:owner-display-name/><oc:share-types/>'
const propfind = (extra = '') => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns"><d:prop>' + PROPS + extra + '</d:prop></d:propfind>'
const SEARCH = '/ocs/v2.php/search/providers/metavox_metadata/search'
const filter = (w, field, value) => ({ path: SEARCH, query: { format: 'json', term: value, metavox_field: `${field}:${value}`, metavox_groupfolder: w.folderId, limit: 10 } })

// What each version's frontend sends for each action.
const ACTIONS = [
	{
		name: 'Open a folder of 1,000 files in the grid',
		old: (w) => () => [
			{ method: 'PROPFIND', path: w.dav, headers: { Depth: '1', 'Content-Type': 'application/xml' }, raw: propfind() },
			// 2.2.3: then its values in blocks of 200.
			...[0, 200, 400, 600, 800].map((i) => ({ path: `/ocs/v2.php/apps/metavox/api/v1/groupfolders/${w.folderId}/directory-metadata`, query: { format: 'json', file_ids: w.ids.slice(i, i + 200).join(',') } })),
		],
		now: (w) => () => [{ method: 'PROPFIND', path: w.dav, headers: { Depth: '1', 'Content-Type': 'application/xml' }, raw: propfind('<nc:metavox/>') }],
	},
	{
		name: 'Open the sidebar of one file',
		old: (w) => () => [{ path: `/index.php/apps/metavox/api/groupfolders/${w.folderId}/files/${w.ids[0]}/metadata` }],
		now: (w) => () => [{ path: `/index.php/apps/metavox/api/files/${w.ids[0]}/metadata` }],
	},
	{
		name: 'Save one value',
		old: (w) => (i) => [{ method: 'POST', path: `/index.php/apps/metavox/api/groupfolders/${w.folderId}/files/${w.ids[1]}/metadata`, body: { metadata: { file_gf_klant: `Bewerkt ${i}` } } }],
		now: (w) => (i) => [{ method: 'POST', path: `/index.php/apps/metavox/api/files/${w.ids[1]}/metadata`, body: { metadata: { file_gf_klant: `Bewerkt ${i}` } } }],
	},
	{
		name: 'Save 50 files at once',
		both: (w) => (i) => [{ method: 'POST', path: '/ocs/v2.php/apps/metavox/api/v1/files/metadata/batch-update', query: { format: 'json' },
			body: { updates: w.ids.slice(2, 52).map((id) => ({ file_id: id, groupfolder_id: w.folderId, metadata: { file_gf_status: i % 2 ? 'Review' : 'Final' } })) } }],
	},
	{ name: 'Search filter, Status = Concept (25% of files)', both: (w) => () => [filter(w, 'file_gf_status', 'Concept')] },
	{ name: 'Search filter, Klant (1 in 5,000)', both: (w) => () => [filter(w, 'file_gf_klant', 'Klant 42')] },
	{ name: 'Search filter, multi-select Tags = Contract', both: (w) => () => [filter(w, 'file_gf_tags', 'Contract')] },
	{ name: 'Search words "dossier 42"', both: () => () => [{ path: SEARCH, query: { format: 'json', term: 'dossier 42', limit: 10 } }] },
]

const results = []
const fmt = (r) => `${String(Math.round(r.cold.ms)).padStart(6)} ms ${String(r.cold.disk).padStart(6)} disk | ${String(Math.round(r.warm.ms)).padStart(5)} ms ${String(r.warm.rows).padStart(8)} rows`
await login(OLD)
await login(NEW)
try {
	const worlds = { old: world(OLD), now: world(NEW) }
	for (const a of ACTIONS) {
		const row = { name: a.name }
		for (const [key, nc] of [['old', OLD], ['now', NEW]]) {
			row[key] = await measure(nc, (a[key] ?? a.both)(worlds[key]))
		}
		results.push(row)
		console.log(`${a.name.padEnd(46)} 2.2.3 ${fmt(row.old)} (${row.old.requests} req)  ||  now ${fmt(row.now)} (${row.now.requests} req)`)
	}
} finally {
	await logout(OLD)
	await logout(NEW)
}
writeFileSync(process.env.VERSIONS_OUT ?? '/tmp/metavox-versions.json', JSON.stringify(results, null, 1))
