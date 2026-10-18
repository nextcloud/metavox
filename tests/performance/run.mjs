// Performance: a fixed list of the grid's hot requests against a real Nextcloud
// with the profiler app, on a team folder with 100 and with 1,000 files. See
// README.md. Modes:
//   seed      build the team folder, fields and values (once)
//   requests  run the request list and write the labels to $PERF_OUT/labels.json

import { execFileSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'

const env = (name) => process.env[name] ?? (() => { throw new Error(`Set ${name}`) })()
const BASE = env('NC_BASE').replace(/\/$/, '')
const OUT = process.env.PERF_OUT ?? '/tmp/metavox-perf'
const WORLD = `${OUT}/world.json`
const FOLDER = 'Perf'
const USER = 'perf-user'
const GROUP = 'perf-members'
export const SIZES = { small: 100, large: 1000 }

const auth = (user, pass) => 'Basic ' + Buffer.from(`${user}:${pass}`).toString('base64')
const admin = auth('admin', env('NC_PASS'))

function occ(...args) {
	return execFileSync('sh', ['-c', `${env('NC_OCC')} ${args.join(' ')}`], { encoding: 'utf8' }).trim()
}

async function request(authHeader, verb, path, { body, query, headers = {}, raw } = {}) {
	const url = new URL(BASE + path)
	for (const [k, v] of Object.entries(query ?? {})) url.searchParams.set(k, String(v))
	const init = { method: verb, headers: { Authorization: authHeader, 'OCS-APIRequest': 'true', Accept: 'application/json', ...headers } }
	if (raw !== undefined) init.body = raw
	else if (body !== undefined) {
		init.body = JSON.stringify(body)
		init.headers['Content-Type'] = 'application/json'
	}
	const start = performance.now()
	const res = await fetch(url, init)
	const text = await res.text()
	return { status: res.status, text, ms: performance.now() - start, url: url.pathname + url.search }
}

async function must(promise, what) {
	const res = await promise
	if (res.status >= 300 && res.status !== 207) throw new Error(`${what}: HTTP ${res.status} ${res.text.slice(0, 200)}`)
	return res
}

const json = (res) => JSON.parse(res.text)
const ocsData = (res) => json(res).ocs.data

// Ten file fields of every type, and one on the team folder.
const FIELDS = [
	['file_gf_perf_text', 'text'], ['file_gf_perf_long', 'textarea'], ['file_gf_perf_number', 'number'], ['file_gf_perf_date', 'date'],
	['file_gf_perf_select', 'select', ['Concept', 'Review', 'Final']], ['file_gf_perf_multi', 'multiselect', ['A', 'B', 'C']],
	['file_gf_perf_check', 'checkbox'], ['file_gf_perf_url', 'url'], ['file_gf_perf_user', 'user'], ['file_gf_perf_link', 'filelink'],
]

function value(name, i, fileIds) {
	switch (name) {
	case 'file_gf_perf_text': return `Klant ${i % 37}`
	case 'file_gf_perf_long': return i % 10 === 0 ? 'x'.repeat(700) : `Notitie ${i}`
	case 'file_gf_perf_number': return String(i * 13 % 1000)
	case 'file_gf_perf_date': return `2026-${String(i % 12 + 1).padStart(2, '0')}-${String(i % 28 + 1).padStart(2, '0')}`
	case 'file_gf_perf_select': return ['Concept', 'Review', 'Final'][i % 3]
	case 'file_gf_perf_multi': return ['A', 'A;#B', 'B;#C'][i % 3]
	case 'file_gf_perf_check': return String(i % 2)
	case 'file_gf_perf_url': return `https://example.org/${i}`
	case 'file_gf_perf_user': return USER
	case 'file_gf_perf_link': return `${fileIds[(i + 1) % fileIds.length]}:`
	}
}

async function seed() {
	mkdirSync(OUT, { recursive: true })
	const ocsCloud = (verb, path, body) => must(request(admin, verb, `/ocs/v2.php/cloud${path}`, { body, query: { format: 'json' } }), `${verb} ${path}`)
	await ocsCloud('POST', '/groups', { groupid: GROUP })
	await ocsCloud('POST', '/users', { userid: USER, password: randomBytes(18).toString('base64') + 'aA1!', groups: [GROUP] })
	const token = occ('user:auth-tokens:add', '--no-interaction', USER).match(/^[A-Za-z0-9]{40,}$/m)[0]
	const user = auth(USER, token)
	// The search filter and its folders are off until an admin turns them on.
	occ('config:app:set', 'metavox', 'search_filter', '--value=true', '--type=boolean')
	occ('config:app:set', 'metavox', 'search_folders', '--value=true', '--type=boolean')

	const folderId = Number(occ('groupfolders:create', FOLDER))
	occ('groupfolders:group', String(folderId), GROUP, 'write', 'share', 'delete')

	const dav = `/remote.php/dav/files/${USER}/${FOLDER}`
	const fileIds = {}
	for (const [size, count] of Object.entries(SIZES)) {
		await must(request(user, 'MKCOL', `${dav}/${size}`), 'mkcol ' + size)
		for (let i = 1; i <= count; i++) {
			await must(request(user, 'PUT', `${dav}/${size}/file-${String(i).padStart(4, '0')}.txt`, { raw: `perf ${i}` }), 'upload')
		}
		const listing = await must(request(user, 'PROPFIND', `${dav}/${size}`, {
			headers: { Depth: '1', 'Content-Type': 'application/xml' },
			raw: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
		}), 'list ' + size)
		fileIds[size] = [...listing.text.matchAll(/file-\d+\.txt<\/d:href>[\s\S]*?<oc:fileid>(\d+)</g)].map((m) => Number(m[1]))
	}

	// One ACL rule, so the ACL paths are measured too.
	occ('groupfolders:permissions', String(folderId), '--enable')
	occ('groupfolders:permissions', String(folderId), 'large/file-0001.txt', '--user', USER, '--', '-read')

	const fieldIds = []
	for (const [name, type, options] of [...FIELDS, ['gf_perf_folder', 'text']]) {
		const res = await must(request(admin, 'POST', '/ocs/v2.php/apps/metavox/api/v1/groupfolder-fields', {
			query: { format: 'json' },
			body: { field_name: name, field_label: name, field_type: type, field_options: options ?? [], applies_to_groupfolder: name === 'gf_perf_folder' },
		}), 'field ' + name)
		fieldIds.push(Number(ocsData(res).id))
	}
	await must(request(admin, 'POST', `/ocs/v2.php/apps/metavox/api/v1/groupfolders/${folderId}/fields`, { query: { format: 'json' }, body: { field_ids: fieldIds } }), 'assign')

	for (const ids of Object.values(fileIds)) {
		for (let i = 0; i < ids.length; i += 100) {
			const updates = ids.slice(i, i + 100).map((id, k) => ({
				file_id: id, groupfolder_id: folderId,
				metadata: Object.fromEntries(FIELDS.map(([name]) => [name, value(name, i + k, ids)])),
			}))
			await must(request(user, 'POST', '/ocs/v2.php/apps/metavox/api/v1/files/metadata/batch-update', { query: { format: 'json' }, body: { updates } }), 'values')
		}
	}
	writeFileSync(WORLD, JSON.stringify({ folderId, fileIds, token }, null, 1))
	console.log(`seeded team folder ${folderId}: ${Object.entries(fileIds).map(([s, ids]) => `${s} ${ids.length}`).join(', ')} files, ${FIELDS.length} fields`)
}

// The grid's hot requests, per folder size; the label pairs them up.
async function requests() {
	const { folderId, fileIds, token } = JSON.parse(readFileSync(WORLD, 'utf8'))
	const user = auth(USER, token)
	const labels = []
	const run = async (label, verb, path, opts = {}) => {
		const res = await request(user, verb, path, opts)
		const before = process.env.PERF_BEFORE === '1'
		let status = res.status
		// The code before the change does not have the routes the change adds.
		if (status === 404 && before) {
			console.log(`${label}: not in the code before the change`)
		} else if (status >= 400) {
			throw new Error(`${label}: HTTP ${status} ${res.text.slice(0, 200)}`)
		} else if (opts.expect) {
			// What the change promises. Code before it that does not do it yet has
			// nothing to compare with: counted as new, like a route it lacks.
			const problem = opts.expect(res)
			if (problem && !before) throw new Error(`${label}: ${problem}`)
			if (problem) {
				console.log(`${label}: not in the code before the change (${problem})`)
				status = 404
			}
		}
		labels.push({ label, method: verb, url: res.url, status, ms: Math.round(res.ms) })
		return res
	}
	const web = (p) => '/index.php/apps/metavox' + p
	const ocs = (p) => '/ocs/v2.php/apps/metavox' + p
	const propfind = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns"><d:prop>'
		+ '<d:getlastmodified/><d:getetag/><d:getcontenttype/><d:resourcetype/><oc:fileid/><oc:permissions/><oc:size/><nc:metavox/></d:prop></d:propfind>'

	for (const size of Object.keys(SIZES)) {
		const ids = fileIds[size]
		const first = ids[1] // file-0001 is hidden by the ACL in the large folder
		await run(`grid listing (PROPFIND) ${size}`, 'PROPFIND', `/remote.php/dav/files/${USER}/${FOLDER}/${size}`, { headers: { Depth: '1', 'Content-Type': 'application/xml' }, raw: propfind })
		await run(`directory metadata ${size}`, 'GET', web(`/api/groupfolders/${folderId}/directory-metadata`), { query: { file_ids: ids.slice(0, 200).join(',') } })
		// The sidebar's one request, with the team folder's fields; the grid's and sidebar's save.
		await run(`file metadata ${size}`, 'GET', web(`/api/files/${first}/metadata`))
		await run(`save fields ${size}`, 'POST', web(`/api/files/${first}/metadata`), { body: { metadata: { file_gf_perf_text: `Bewerkt ${size}` } } })
		await run(`batch update ${size}`, 'POST', ocs('/api/v1/files/metadata/batch-update'), { query: { format: 'json' }, body: { updates: ids.slice(2, 52).map((id) => ({ file_id: id, groupfolder_id: folderId, metadata: { file_gf_perf_select: 'Review' } })) } })
		await run(`filter values ${size}`, 'GET', ocs(`/api/v1/groupfolders/${folderId}/all-filter-values`), { query: { format: 'json', field_names: 'file_gf_perf_text,file_gf_perf_select,file_gf_perf_link' } })
		await run(`sorted file ids ${size}`, 'GET', ocs(`/api/v1/groupfolders/${folderId}/sorted-file-ids`), { query: { format: 'json', filters: JSON.stringify({ file_gf_perf_select: ['Review'] }), sort_field: 'file_gf_perf_number', sort_order: 'asc' } })
	}
	await run('field values', 'GET', web(`/api/user/groupfolders/${folderId}/field-values`), { query: { field: 'file_gf_perf_text' } })
	await run('view init', 'GET', web('/api/init'), { query: { dir: `/${FOLDER}/large` } })
	await run('files page start data', 'GET', '/index.php/apps/files/files', { query: { dir: `/${FOLDER}/large` }, headers: { Accept: 'text/html' } })
	await run('search', 'GET', '/ocs/v2.php/search/providers/metavox_metadata/search', { query: { format: 'json', term: 'Klant 5' } })
	// The search filter: its picker, then a value, several values (any of them:
	// no file holds both A and C) and no team folder.
	const search = '/ocs/v2.php/search/providers/metavox_metadata/search'
	const found = (what) => (res) => (json(res).ocs.data.entries.length > 0 ? null : `no files for ${what}`)
	await run('filter picker team folders', 'GET', web('/api/groupfolders'), { query: { scope: 'member' } })
	await run('filter picker fields', 'GET', web(`/api/groupfolders/${folderId}/file-fields`))
	const first = await run('field search', 'GET', search, { query: { format: 'json', term: 'Review', metavox_field: 'file_gf_perf_select:Review', metavox_groupfolder: folderId, limit: 10 }, expect: found('Review') })
	// A result shows the file's own value, not the two asked for.
	const ownValues = (res) => found('A or C')(res) ?? (json(res).ocs.data.entries.some((e) => e.subline.includes('A;#C')) ? 'a result shows A;#C, which no file holds' : null)
	await run('field search any of', 'GET', search, { query: { format: 'json', term: 'A', metavox_field: 'file_gf_perf_multi:A;#C', metavox_groupfolder: folderId }, expect: ownValues })
	await run('field search all team folders', 'GET', search, { query: { format: 'json', term: 'Review', metavox_field: 'file_gf_perf_select:Review' }, expect: found('Review without a team folder') })
	// A page at a time, as Nextcloud's search menu asks: 10, then the next 10
	// after the last file shown (the cursor).
	const firstPage = first.status === 200 ? json(first).ocs.data : null
	const nextPage = (res) => {
		const data = json(res).ocs.data
		const seen = new Set((firstPage?.entries ?? []).map((e) => e.resourceUrl))
		return data.entries.length === 10 && data.isPaginated && !data.entries.some((e) => seen.has(e.resourceUrl)) ? null : `page of ${data.entries.length}, cursor ${data.cursor}`
	}
	await run('field search next page', 'GET', search, { query: { format: 'json', term: 'Review', metavox_field: 'file_gf_perf_select:Review', metavox_groupfolder: folderId, limit: 10, cursor: firstPage?.cursor ?? 0 }, expect: nextPage })
	// Number 0 is on file-0001 in both folders; the ACL hides the one in large.
	const onlySmall = (res) => {
		const titles = json(res).ocs.data.entries.map((e) => e.title)
		return titles.length === 1 && titles[0].endsWith(`/${FOLDER}/small`) ? null : `found ${JSON.stringify(titles)}`
	}
	await run('field search leaves out what the ACL hides', 'GET', search, { query: { format: 'json', term: '000', metavox_field: 'file_gf_perf_number:0' }, expect: onlySmall })
	// A saved search as a folder in Files: its files with the properties the
	// Files app asks for, minus the ones the server leaves out.
	const saved = await run('save search', 'POST', web('/api/user/metadata-searches'), { body: { name: 'Perf', field: 'file_gf_perf_select', value: 'Review', groupfolderId: folderId } })
	if (saved.status < 300) {
		const props = ['d:getcontentlength', 'd:getcontenttype', 'd:getetag', 'd:getlastmodified', 'd:creationdate', 'd:displayname', 'd:quota-available-bytes', 'd:resourcetype',
			'nc:has-preview', 'nc:is-encrypted', 'nc:mount-type', 'oc:comments-unread', 'oc:favorite', 'oc:fileid', 'oc:owner-display-name', 'oc:owner-id', 'oc:permissions', 'oc:size',
			'nc:upload_time', 'oc:share-types', 'nc:sharees', 'nc:system-tags', 'nc:reminder-due-date', 'nc:lock', 'nc:hidden', 'nc:is-mount-root', 'nc:metavox']
		const report = '<?xml version="1.0"?><nc:metavox-search xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">'
			+ `<d:prop>${props.map((p) => `<${p}/>`).join('')}</d:prop><nc:search-id>${json(saved).id}</nc:search-id></nc:metavox-search>`
		// Nextcloud reads those per file: the server leaves them out.
		const filesNoShares = (res) => {
			const n = (res.text.match(/<d:response>/g) ?? []).length
			return n < 100 ? `${n} files` : /share-types|system-tags|comments-unread|nc:lock/.test(res.text) ? 'a property it leaves out was served' : null
		}
		await run('search folder', 'REPORT', `/remote.php/dav/files/${USER}`, { headers: { 'Content-Type': 'application/xml' }, raw: report, expect: filesNoShares })
		await run('remove search', 'DELETE', web(`/api/user/metadata-searches/${json(saved).id}`))
	}
	await run('status heartbeat', 'PUT', '/ocs/v2.php/apps/user_status/api/v1/heartbeat', { query: { format: 'json' }, body: { status: 'online' } })

	mkdirSync(OUT, { recursive: true })
	writeFileSync(`${OUT}/labels.json`, JSON.stringify(labels, null, 1))
	console.log(`${labels.length} requests`)
}

const mode = process.argv[2]
if (mode === 'seed') await seed()
else if (mode === 'requests') await requests()
else throw new Error('usage: run.mjs seed|requests')
