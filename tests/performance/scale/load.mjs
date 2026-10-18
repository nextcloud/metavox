// N people searching at the same time, each one search after the other
// without a pause, for a while: how long one search takes then (median and
// 95th percentile), and how many searches per second the server answers. The
// search filter's three cases in turn, on the two versions of versions.mjs.
// env: SCALE_PASS; OLD and NEW as <compose project>@<port> (see versions.mjs);
//      LEVELS (default 10,25,50,100); SECONDS (default 20)
import { execFileSync } from 'node:child_process'

const PASS = process.env.SCALE_PASS ?? (() => { throw new Error('Set SCALE_PASS') })()
const LEVELS = (process.env.LEVELS ?? '10,25,50,100').split(',').map(Number)
const SECONDS = Number(process.env.SECONDS ?? 20)
const dir = new URL('.', import.meta.url).pathname
const version = (spec, label) => {
	const [project, port] = spec.split('@')
	return { label, project, base: `http://127.0.0.1:${port}` }
}
const VERSIONS = [version(process.env.OLD ?? 'metavox-scale-223@8023', 'old'), version(process.env.NEW ?? 'metavox-scale@8035', 'now')]
const sql = (project, q) => execFileSync('docker', ['compose', '-p', project, 'exec', '-T', 'db', 'mariadb', '-uroot', '-pscale-root', '-N', '-B', 'nextcloud', '-e', q], { cwd: dir, encoding: 'utf8' }).trim()

async function login(base) {
	const res = await fetch(`${base}/ocs/v2.php/core/getapppassword?format=json`, {
		headers: { Authorization: 'Basic ' + Buffer.from(`scale-big:${PASS}`).toString('base64'), 'OCS-APIRequest': 'true', Accept: 'application/json' },
	})
	return 'Basic ' + Buffer.from(`scale-big:${(await res.json()).ocs.data.apppassword}`).toString('base64')
}

const pct = (xs, p) => [...xs].sort((a, b) => a - b)[Math.min(xs.length - 1, Math.floor(xs.length * p))]

for (const v of VERSIONS) {
	const auth = await login(v.base)
	const folder = sql(v.project, 'SELECT folder_id FROM scale_folders WHERE n = 1')
	// The three filters of the comparison, in turn.
	const queries = [['file_gf_status', 'Concept'], ['file_gf_klant', 'Klant 42'], ['file_gf_tags', 'Contract']].map(([f, val]) => {
		const url = new URL(v.base + '/ocs/v2.php/search/providers/metavox_metadata/search')
		for (const [k, x] of Object.entries({ format: 'json', term: val, metavox_field: `${f}:${val}`, metavox_groupfolder: folder, limit: 10 })) url.searchParams.set(k, x)
		return url
	})
	for (const n of LEVELS) {
		const times = []
		let errors = 0
		const until = Date.now() + SECONDS * 1000
		await Promise.all(Array.from({ length: n }, async (_, worker) => {
			let i = worker
			while (Date.now() < until) {
				const start = performance.now()
				try {
					const res = await fetch(queries[i++ % queries.length], { headers: { Authorization: auth, 'OCS-APIRequest': 'true', Accept: 'application/json' } })
					await res.text()
					if (res.status !== 200) errors++
					else times.push(performance.now() - start)
				} catch {
					errors++
				}
			}
		}))
		console.log(`${v.label.padEnd(6)} ${String(n).padStart(3)} people: median ${String(Math.round(pct(times, 0.5))).padStart(6)} ms, 95% within ${String(Math.round(pct(times, 0.95))).padStart(6)} ms, ${(times.length / SECONDS).toFixed(1).padStart(6)} searches/s, ${errors} errors`)
	}
	await fetch(`${v.base}/ocs/v2.php/core/apppassword?format=json`, { method: 'DELETE', headers: { Authorization: auth, 'OCS-APIRequest': 'true' } })
}
