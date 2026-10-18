// The access matrix: every MetaVox route, called as every role, against a
// team folder with ACL. See README.md for how to run it.

import { readFileSync, writeFileSync } from 'node:fs'
import { ROLES, FILES, World } from './world.mjs'
import { ROUTES, DAV_CHECK, DONE_STEP, OK, NOT_REFUSED, statusFor } from './routes.mjs'

const env = (name) => process.env[name] ?? (() => { throw new Error(`Set ${name}`) })()

// Exception text, paths or SQL must never reach a client.
const LEAK_PATTERN = /SQLSTATE|Stack trace|\/var\/www|Exception:|#\d+ \/\S+\.php/

function routesFromApp() {
	const src = readFileSync(new URL('../../../appinfo/routes.php', import.meta.url), 'utf8')
	const ocsStart = src.indexOf("'ocs'")
	const routes = []
	for (const m of src.matchAll(/'name'\s*=>\s*'([^']+)',\s*'url'\s*=>\s*'([^']+)',\s*'verb'\s*=>\s*'([^']+)'/g)) {
		routes.push({ name: m[1], url: m[2], verb: m[3], ocs: m.index > ocsStart })
	}
	return routes
}

function matches(expected, status) {
	if (expected === OK) return status >= 200 && status < 300
	if (expected === NOT_REFUSED) return status !== 401 && status !== 403
	return status === expected
}

const isRefusal = (expected) => typeof expected === 'number' && expected >= 400

// Strings a role must never get back: values and names it cannot see.
function forbiddenFor(w, role, step) {
	const r = ROLES[role]
	const out = []
	if (!r.member && !r.admin) {
		out.push(w.values.open, w.values.alleenLezen, w.values.geheim, w.values.folder)
		out.push(w.names.open, w.names.alleenLezen, w.names.geheim, w.names.view)
		if (step >= 8) out.push(w.names.fileField, w.names.folderField)
	}
	if (r.hiddenFile) out.push(w.values[r.hiddenFile], w.names[r.hiddenFile])
	return out
}

async function main() {
	const w = new World({
		base: env('NC_BASE'),
		adminUser: process.env.NC_USER ?? 'admin',
		adminPass: env('NC_PASS'),
		occ: env('NC_OCC'),
	})
	const only = process.env.ONLY ? new RegExp(process.env.ONLY) : null
	const results = []

	// The table has to cover every route of the app, and nothing else.
	const appRoutes = routesFromApp()
	const missing = appRoutes.filter((r) => !ROUTES[r.name]).map((r) => r.name)
	const unknown = Object.keys(ROUTES).filter((n) => !appRoutes.some((r) => r.name === n))
	if (missing.length || unknown.length) {
		console.log(`Route table out of date. Missing: ${missing.join(', ') || '-'}. Unknown: ${unknown.join(', ') || '-'}`)
		process.exit(1)
	}

	try {
		await w.setup()
		for (const route of appRoutes) {
			if (only && !only.test(route.name)) continue
			const def = ROUTES[route.name]
			await runRoute(w, route, def, results)
		}
		if (!only || only.test('dav')) await runDav(w, results)
		if (!only || only.test('push')) await runPush(w, results)
		if (!only || only.test('filelink')) await runFileLinks(w, results)
		if (!only || only.test('usersearch')) await runUserSearch(w, results)
		if (!only || only.test('search')) await runSearch(w, results)
		if (!only || only.test('savedsearch')) await runSavedSearch(w, results)
		if (!only || only.test('searchfields')) await runSearchFields(w, results)
		if (!only || only.test('boot')) await runBootFlag(w, results)
	} finally {
		await w.cleanup()
	}

	report(results)
	if (process.env.MATRIX_OUT) writeFileSync(process.env.MATRIX_OUT, JSON.stringify({ doneStep: DONE_STEP, results }, null, 2))
	process.exit(results.some((r) => r.outcome === 'FAIL') ? 1 : 0)
}

async function runRoute(w, route, def, results) {
	const since = def.since ?? 3
	const due = since <= DONE_STEP
	// A pending route is held to its level as it will be when its step is done.
	const step = Math.max(DONE_STEP, since)
	for (const role of Object.keys(ROLES)) {
		for (const file of def.files ? FILES : ['open']) {
			const expected = def.expect ? def.expect(role, file, step) : statusFor(def.level, role, file, step)
			if (def.refusalsOnly && !isRefusal(expected)) continue

			const params = def.prep ? await def.prep(w, role, file) : {}
			const url = route.url
				.replace(/\{(groupfolderId|gfId)\}/, w.folderId)
				.replace('{fileId}', w.fileIds[file])
				.replace('{viewId}', params.viewId ?? w.viewId)
				.replace('{id}', params.id ?? 0)
				.replace('{permissionId}', params.permissionId ?? 0)
			const opts = { query: def.query?.(w, file, params), body: def.body?.(w, file, params) }
			const res = route.ocs ? await w.ocs(role, route.verb, url, opts) : await w.web(role, route.verb, url, opts)

			const problems = []
			if (expected !== null && !matches(expected, res.status)) problems.push(`status ${res.status}, expected ${expected}`)
			if (def.check && res.status < 300 && matches(expected, res.status)) {
				const p = await def.check(res, w, role, file)
				if (p) problems.push(p)
			}
			problems.push(...checkLeaks(w, role, step, res))

			await def.after?.(w, role, file, res, params)
			if (def.restore && res.status < 300) await w.restore()

			results.push(outcome({ route: route.name, role, file: def.files ? file : null, since, due, expected, status: res.status, problems, excerpt: res.text.slice(0, 160) }))
		}
	}
}

async function runDav(w, results) {
	const body = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns"><d:prop><nc:metavox/></d:prop></d:propfind>'
	for (const role of Object.keys(ROLES)) {
		const res = await w.request(role, 'PROPFIND', `/remote.php/dav/files/${w.userId(role)}/E2E-Rechten`, { headers: { Depth: '1', 'Content-Type': 'application/xml' }, raw: body })
		const expected = DAV_CHECK.expect(role)
		const problems = []
		if (!matches(expected, res.status)) problems.push(`status ${res.status}, expected ${expected}`)
		else if (res.status === 207) {
			const p = DAV_CHECK.check(res, w, role)
			if (p) problems.push(p)
		}
		problems.push(...checkLeaks(w, role, DONE_STEP, res))
		results.push(outcome({ route: 'dav#propfind', role, file: null, since: 3, due: true, expected, status: res.status, problems, excerpt: res.text.slice(0, 160) }))
	}
}

// Push goes to every member, so it may carry ids but never values (step 13).
const PUSH_WAIT_MS = 5000

function listen(url, user, password) {
	return new Promise((resolve, reject) => {
		const socket = new WebSocket(url)
		const frames = []
		socket.onopen = () => {
			socket.send(user)
			socket.send(password)
		}
		socket.onmessage = (event) => {
			const text = String(event.data)
			if (text === 'authenticated') resolve({ socket, frames })
			else if (text.startsWith('err')) reject(new Error(`push login ${user}: ${text}`))
			else frames.push(text)
		}
		socket.onerror = () => reject(new Error('push websocket failed for ' + user))
		setTimeout(() => reject(new Error('push login timed out for ' + user)), 10000)
	})
}

async function runPush(w, results) {
	const caps = await w.request(null, 'GET', '/ocs/v2.php/cloud/capabilities', { query: { format: 'json' } })
	const url = caps.json?.ocs?.data?.capabilities?.notify_push?.endpoints?.websocket
	if (!url) {
		console.log('push: notify_push is not installed here, skipped')
		return
	}
	// Pushes go to whoever has the grid of the team folder open (step 13 and the push fixes).
	const PROPFIND = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns"><d:prop><nc:metavox/></d:prop></d:propfind>'
	const openGrid = (role) => w.must(w.request(role, 'PROPFIND', `/remote.php/dav/files/${w.userId(role)}/E2E-Rechten`, { headers: { Depth: '1', 'Content-Type': 'application/xml' }, raw: PROPFIND }), 'open grid ' + role)
	const leave = (role) => w.must(w.web(role, 'POST', '/api/presence/leave', { body: { gf_id: w.folderId } }), 'leave ' + role)
	const statusHeartbeat = (role) => w.must(w.request(role, 'PUT', '/ocs/v2.php/apps/user_status/api/v1/heartbeat', { body: { status: 'online' }, query: { format: 'json' } }), 'heartbeat ' + role)

	// A member who reads the file, one it is hidden for, a member without the grid open, and an outsider.
	const roles = ['lezer', 'verborgen', 'bewerker', 'buitenstaander']
	const listeners = {}
	for (const role of roles) listeners[role] = await listen(url, w.userId(role), w.appPasswords[role])
	await openGrid('lezer')
	await openGrid('verborgen')
	await leave('bewerker') // listed earlier in this run; now the grid is closed

	const save = async (value) => {
		for (const role of roles) listeners[role].frames.length = 0
		await w.must(w.web('admin', 'POST', `/api/files/${w.fileIds.geheim}/metadata`, {
			body: { metadata: { [w.fields.file.name]: value } },
		}), 'push change')
		await new Promise((resolve) => setTimeout(resolve, PUSH_WAIT_MS))
	}
	const record = (route, role, value, shouldBeTold) => {
		const { frames } = listeners[role]
		const events = frames.filter((f) => f.startsWith('metavox_metadata_changed'))
		const bodies = events.map((f) => JSON.parse(f.slice('metavox_metadata_changed'.length).trim() || '{}'))
		const told = bodies.some((b) => b.gfId === w.folderId && (b.fileIds ?? []).includes(w.fileIds.geheim))
		const problems = []
		if (told !== shouldBeTold) problems.push(shouldBeTold ? 'no push for the change' : 'push to someone without the grid open')
		if (frames.some((f) => f.includes(value) || f.includes(w.values.geheim))) problems.push('push carries the value')
		if (bodies.some((b) => 'value' in b || 'fieldName' in b)) problems.push('push carries field or value')
		results.push(outcome({ route, role, file: 'geheim', since: 13, due: DONE_STEP >= 13, expected: shouldBeTold ? 'told' : 'not told', status: told ? 'told' : 'not told', problems, excerpt: events.join(' | ').slice(0, 160) }))
	}

	const first = `${w.values.geheim}-push`
	await save(first)
	record('push#grid-open', 'lezer', first, true)
	record('push#grid-open', 'verborgen', first, true)
	record('push#grid-open', 'bewerker', first, false)
	record('push#grid-open', 'buitenstaander', first, false)

	// Nextcloud's own status heartbeat keeps you present; leaving ends it.
	await statusHeartbeat('lezer')
	await leave('verborgen')
	const second = `${w.values.geheim}-push2`
	await save(second)
	record('push#after-heartbeat-and-leave', 'lezer', second, true)
	record('push#after-heartbeat-and-leave', 'verborgen', second, false)

	for (const role of roles) listeners[role].socket.close()
	await w.restore()
}

// Every way a file link is written keeps only ids of files in this team folder (step 10).
async function runFileLinks(w, results) {
	const { open, alleenLezen, outside } = w.fileIds
	// A link outside the team folder, one to the file itself, and one inside it:
	// as an id with path text, and as a picked path.
	const value = `${outside}:/elders/${w.names.outside};#${open}:/pad/${w.names.open};#${alleenLezen}:/pad/${w.names.alleenLezen}`
	const picked = `${outside}:;#/E2E-Rechten/${w.names.alleenLezen}`
	const fileLink = async () => {
		const res = await w.ocs('admin', 'GET', `/api/v1/files/${open}/metadata`)
		return [].concat(res.json?.ocs?.data ?? []).find((f) => f.field_name === w.fields.link.name)?.value ?? null
	}
	const cases = {
		'filelink#ocsSave': [
			() => w.ocs('admin', 'POST', `/api/v1/files/${open}/metadata`, { body: { metadata: { [w.fields.link.name]: value } } }),
			fileLink,
		],
		'filelink#ocsBatchUpdate': [
			() => w.ocs('admin', 'POST', '/api/v1/files/metadata/batch-update', { body: { updates: [{ file_id: open, groupfolder_id: w.folderId, metadata: { [w.fields.link.name]: value } }] } }),
			fileLink,
		],
		'filelink#webBulkSave': [
			() => w.web('admin', 'POST', '/api/files/bulk-metadata', { body: { fileIds: [open], metadata: { [w.fields.link.name]: picked } } }),
			fileLink,
		],
		'filelink#default': [
			() => w.web('admin', 'POST', `/api/groupfolders/${w.folderId}/defaults`, { body: { fieldId: w.fields.link.id, value: picked } }),
			async () => (await w.web('admin', 'GET', `/api/groupfolders/${w.folderId}/defaults`)).json?.defaults?.[w.fields.link.id] ?? null,
		],
		'filelink#ocsFolderSave': [
			() => w.ocs('admin', 'POST', `/api/v1/groupfolders/${w.folderId}/metadata`, { body: { metadata: { [w.fields.folderLink.name]: picked } } }),
			async () => {
				const res = await w.ocs('admin', 'GET', `/api/v1/groupfolders/${w.folderId}/metadata`)
				return [].concat(res.json?.ocs?.data ?? []).find((f) => f.field_name === w.fields.folderLink.name)?.value ?? null
			},
		],
	}
	for (const [name, [write, read]] of Object.entries(cases)) {
		const res = await write()
		const stored = res.status < 300 ? await read() : null
		const problems = []
		if (res.status >= 300) problems.push(`write answered ${res.status}`)
		else if (stored !== `${alleenLezen}:`) problems.push(`stored ${JSON.stringify(stored)}, expected "${alleenLezen}:"`)
		results.push(outcome({ route: name, role: 'admin', file: 'open', since: 10, due: DONE_STEP >= 10, expected: `${alleenLezen}:`, status: stored, problems, excerpt: res.text.slice(0, 160) }))
	}
	await w.must(w.web('admin', 'POST', `/api/groupfolders/${w.folderId}/defaults`, { body: { fieldId: w.fields.link.id, value: null } }), 'clear link default')
	await w.restore()
}

// The user picker follows Nextcloud's share settings (step 14): with "only your
// own groups" you find nobody outside them. The setting goes through the web
// API like the admin page does: Nextcloud caches app settings per web process,
// so a change through occ is not seen. It is put back afterwards.
async function runUserSearch(w, results) {
	const KEY = 'shareapi_restrict_user_enumeration_to_group'
	const setting = (verb, body) => w.must(w.request(null, verb, `/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/core/${KEY}`, { body, query: { format: 'json' } }), `${verb} ${KEY}`)
	const find = async (role) => {
		const res = await w.web(role, 'GET', '/api/users', { query: { search: 'e2e-acc' } })
		return new Set((Array.isArray(res.json) ? res.json : []).map((u) => u.id))
	}
	let before = null
	try { before = w.occ('config:app:get', 'core', KEY) } catch { /* not set */ }

	// Only meaningful when the setting changes the answer.
	const open = await find('bewerker')
	const cases = [['without the setting a member finds someone outside the groups', open.has(w.userId('buitenstaander'))]]
	await setting('POST', { value: 'yes' })
	try {
		const member = await find('bewerker')
		const outsider = await find('buitenstaander')
		cases.push(['a member finds another member', member.has(w.userId('lezer'))])
		cases.push(['a member does not find users outside their groups', !member.has(w.userId('buitenstaander')) && !member.has(w.userId('globaal'))])
		cases.push(['a user without groups finds only themselves', [...outsider].every((id) => id === w.userId('buitenstaander'))])
		cases.push(['you can pick yourself', outsider.has(w.userId('buitenstaander'))])
	} finally {
		await (before === null ? setting('DELETE') : setting('POST', { value: before }))
	}
	for (const [name, ok] of cases) {
		results.push(outcome({ route: 'usersearch#own-groups', role: name, file: null, since: 14, due: DONE_STEP >= 14, expected: 'yes', status: ok ? 'yes' : 'no', problems: ok ? [] : ['not so'], excerpt: '' }))
	}
}

// Nextcloud's search, with MetaVox's filter and with plain words: a role finds
// a file by its value exactly when it can open the file in the team folder.
// An admin outside the team folder finds nothing: a result has to open.
async function runSearch(w, results) {
	const path = '/ocs/v2.php/search/providers/metavox_metadata/search'
	for (const role of Object.keys(ROLES)) {
		const r = ROLES[role]
		for (const file of FILES) {
			const expected = !!r.member && r.hiddenFile !== file
			const ways = [
				['search#filter', { term: w.values[file], metavox_field: `${w.fields.file.name}:${w.values[file]}` }],
				['search#words', { term: w.values[file] }],
			]
			for (const [route, query] of ways) {
				const res = await w.request(role, 'GET', path, { query: { format: 'json', limit: 10, ...query } })
				const found = (res.json?.ocs?.data?.entries ?? []).some((e) => String(e.title).startsWith(w.names[file]))
				const problems = []
				if (res.status !== 200) problems.push(`status ${res.status}`)
				else if (found !== expected) problems.push(expected ? 'not found' : 'found')
				problems.push(...checkLeaks(w, role, DONE_STEP, res))
				results.push(outcome({ route, role, file, since: DONE_STEP, due: true, expected, status: found, problems, excerpt: res.text.slice(0, 160) }))
			}
		}
	}

	// A file in the trash is out of the team folder, while its metadata stays
	// for a restore: it is found before the delete, not after.
	const name = `prullenbak-${Date.now()}.txt`
	const value = `${w.values.open}-prullenbak`
	const dav = `/remote.php/dav/files/${w.userId('admin')}/E2E-Rechten/${name}`
	await w.must(w.request('admin', 'PUT', dav, { raw: 'e2e' }), 'upload trash file')
	const stat = await w.request('admin', 'PROPFIND', dav, {
		headers: { Depth: '0', 'Content-Type': 'application/xml' },
		raw: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
	})
	const fileId = Number(stat.text.match(/<oc:fileid>(\d+)</)?.[1])
	await w.must(w.web('admin', 'POST', `/api/files/${fileId}/metadata`, { body: { metadata: { [w.fields.file.name]: value } } }), 'value of trash file')
	const finds = async (query) => {
		const res = await w.request('admin', 'GET', path, { query: { format: 'json', limit: 10, ...query } })
		return (res.json?.ocs?.data?.entries ?? []).some((e) => String(e.title).startsWith(name))
	}
	const ways = { 'search#filter': { term: value, metavox_field: `${w.fields.file.name}:${value}` }, 'search#words': { term: value } }
	const before = {}
	for (const [route, query] of Object.entries(ways)) before[route] = await finds(query)
	await w.must(w.request('admin', 'DELETE', dav), 'delete trash file')
	for (const [route, query] of Object.entries(ways)) {
		const after = await finds(query)
		const problems = !before[route] ? ['not found before the delete'] : after ? ['found in the trash'] : []
		results.push(outcome({ route, role: 'admin', file: 'in the trash', since: DONE_STEP, due: true, expected: false, status: after, problems, excerpt: '' }))
	}
}

// A saved search as a folder in Files: the files a role sees in the team
// folder, never one the ACL hides, nothing for admins who are not members.
// Only on the role's own files and with its own searches.
async function runSavedSearch(w, results) {
	const report = (role, id, uid = w.userId(role)) => w.request(role, 'REPORT', `/remote.php/dav/files/${uid}`, {
		headers: { 'Content-Type': 'application/xml' },
		raw: `<?xml version="1.0"?><nc:metavox-search xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns"><d:prop><oc:fileid/><d:displayname/><oc:share-types/><nc:system-tags/><oc:comments-unread/><nc:lock/></d:prop><nc:search-id>${id}</nc:search-id></nc:metavox-search>`,
	})
	const save = async (role, value) => (await w.must(w.web(role, 'POST', '/api/user/metadata-searches', { body: { name: 'E2E', field: w.fields.file.name, value } }), `save search as ${role}`)).json.id
	const saved = []
	try {
		for (const role of Object.keys(ROLES)) {
			const r = ROLES[role]
			for (const file of FILES) {
				const id = await save(role, w.values[file])
				saved.push([role, id])
				const res = await report(role, id)
				const hrefs = [...res.text.matchAll(/<d:href>([^<]*)<\/d:href>/g)].map((m) => decodeURIComponent(m[1]))
				const found = hrefs.some((href) => href.endsWith(`/${w.names[file]}`))
				const expected = !!r.member && r.hiddenFile !== file
				const problems = []
				if (res.status !== 207) problems.push(`status ${res.status}`)
				else if (found !== expected) problems.push(expected ? 'not found' : 'found')
				else if (hrefs.some((href) => !href.startsWith(`/remote.php/dav/files/${w.userId(role)}/`))) problems.push('a path outside their own files')
				else if (/share-types|system-tags|comments-unread|nc:lock/.test(res.text)) problems.push('a property it leaves out was served')
				problems.push(...checkLeaks(w, role, DONE_STEP, res))
				results.push(outcome({ route: 'savedsearch#report', role, file, since: DONE_STEP, due: true, expected, status: found, problems, excerpt: res.text.slice(0, 160) }))
			}
		}

		// Someone else's search, or someone else's files: not there.
		const [owner, ownersId] = saved.find(([role]) => role === 'beheerder')
		const doors = [
			['savedsearch#their id', 'lezer', () => report('lezer', ownersId + 1000)],
			['savedsearch#their files', 'lezer', () => report('lezer', ownersId, w.userId(owner))],
		]
		for (const [route, role, call] of doors) {
			const res = await call()
			const problems = res.status === 404 ? [] : [`status ${res.status}, expected 404`]
			problems.push(...checkLeaks(w, role, DONE_STEP, res))
			results.push(outcome({ route, role, file: null, since: DONE_STEP, due: true, expected: 404, status: res.status, problems, excerpt: res.text.slice(0, 160) }))
		}
	} finally {
		for (const [role, id] of saved) await w.web(role, 'DELETE', `/api/user/metadata-searches/${id}`)
	}
}

// The search picker's "all my team folders": the fields of the team folders a
// role is in, and for nobody else, admins and managers included.
async function runSearchFields(w, results) {
	for (const role of Object.keys(ROLES)) {
		const res = await w.web(role, 'GET', '/api/user/groupfolder-fields', { query: { scope: 'member' } })
		const shown = res.text.includes(w.names.fileField)
		const expected = !!ROLES[role].member
		const problems = []
		if (res.status !== 200) problems.push(`status ${res.status}`)
		else if (shown !== expected) problems.push(`file field ${shown ? 'shown' : 'missing'}`)
		problems.push(...checkLeaks(w, role, DONE_STEP, res))
		results.push(outcome({ route: 'searchfields#member', role, file: null, since: DONE_STEP, due: true, expected, status: shown, problems, excerpt: res.text.slice(0, 160) }))
	}
}

// The Files page brings can_manage in its start data. It follows the rule the
// server checks when a view is saved: member with write on the team folder.
// Members only: others have no team folder to start in (view#init covers them),
// and test users that never logged in have no files of their own to open.
async function runBootFlag(w, results) {
	for (const role of Object.keys(ROLES).filter((role) => ROLES[role].member)) {
		const r = ROLES[role]
		const res = await w.request(role, 'GET', '/index.php/apps/files/files', { query: { dir: '/E2E-Rechten' }, headers: { Accept: 'text/html' } })
		const raw = res.text.match(/id="initial-state-metavox-init" value="([^"]+)"/)?.[1]
		const init = raw ? JSON.parse(Buffer.from(raw, 'base64').toString()) : null
		const flag = init?.all_gf_data?.[w.folderId]?.can_manage ?? false
		const expected = !!r.member && (!!r.admin || !!r.rootWrite)
		const problems = []
		if (res.status !== 200) problems.push(`page answered ${res.status}`)
		else if (!!r.member && !init?.all_gf_data?.[w.folderId]) problems.push('no start data for the team folder')
		else if (flag !== expected) problems.push(`can_manage ${flag}, expected ${expected}`)
		results.push(outcome({ route: 'boot#can_manage', role, file: null, since: 15, due: DONE_STEP >= 15, expected, status: flag, problems, excerpt: '' }))
	}
}

function checkLeaks(w, role, step, res) {
	const problems = []
	for (const s of forbiddenFor(w, role, step)) {
		if (res.text.includes(s)) problems.push(`leaks "${s}"`)
	}
	if (res.status >= 400 && LEAK_PATTERN.test(res.text)) problems.push('error text leaks internals')
	// From step 9 on every refusal by MetaVox carries a fixed reason code (Nextcloud's own don't have "error").
	const body = res.json?.ocs?.data ?? res.json
	if (step >= 9 && res.status >= 400 && res.status < 500 && typeof body?.error === 'string' && !body.reason) {
		problems.push('refusal without reason')
	}
	return problems
}

function outcome(r) {
	if (r.due) return { ...r, outcome: r.problems.length ? 'FAIL' : 'ok' }
	if (r.expected === null) return { ...r, outcome: 'open' }
	return { ...r, outcome: r.problems.length ? 'pending' : 'ready' }
}

function report(results) {
	const byOutcome = (o) => results.filter((r) => r.outcome === o)
	const fails = byOutcome('FAIL')
	for (const r of fails) {
		console.log(`FAIL ${r.route} as ${r.role}${r.file ? ` on ${r.file}` : ''}: ${r.problems.join('; ')}\n     ${r.excerpt.replace(/\s+/g, ' ')}`)
	}

	// Pending routes per step: what that step still has to change.
	const pendingSteps = [...new Set(results.filter((r) => !r.due).map((r) => r.since))].sort((a, b) => a - b)
	for (const step of pendingSteps) {
		const rows = results.filter((r) => !r.due && r.since === step)
		const pending = rows.filter((r) => r.outcome === 'pending')
		const routes = [...new Set(pending.map((r) => r.route))]
		console.log(`step ${step}: ${rows.length} calls, ${pending.length} still to change${routes.length ? ` (${routes.join(', ')})` : ''}`)
		if (process.env.VERBOSE) {
			for (const r of pending) console.log(`     ${r.route} as ${r.role}${r.file ? ` on ${r.file}` : ''}: ${r.problems.join('; ')}`)
		}
	}

	console.log(`${results.length} calls at step ${DONE_STEP}: ${byOutcome('ok').length} ok, ${fails.length} failed, ` +
		`${byOutcome('pending').length} pending, ${byOutcome('ready').length} already right for a later step, ${byOutcome('open').length} not decided`)
}

main().catch((e) => {
	console.error(e)
	process.exit(1)
})
