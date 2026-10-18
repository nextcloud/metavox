// The test world: one team folder with ACL, ten users with different rights,
// three files, two text and two file-link fields, a view, and a second team
// folder with one file to link to from outside. Built through Nextcloud's own
// APIs and occ, removed again afterwards. Everything is named e2e-acc-* /
// E2E-Rechten*.

import { execFileSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'

const FOLDER = 'E2E-Rechten'
const OTHER_FOLDER = 'E2E-Rechten-buiten'
const PREFIX = 'e2e-acc-'
const GROUP_MEMBERS = PREFIX + 'leden'
const GROUP_READERS = PREFIX + 'lezers'
// Someone to grant the manage permission to and take it back from: a grant
// needs an account or group that exists. In no group and no team folder.
export const DUMMY_USERS = [PREFIX + 'dummy', PREFIX + 'dummy2']
export const DUMMY_GROUPS = [PREFIX + 'dummygroep', PREFIX + 'dummygroep2']

// Who is who. The flags are what the expectations in routes.mjs are built on.
// The admin's switches for the search filter and saving it as a folder.
const SEARCH_SETTINGS = ['search_filter', 'search_folders']

export const ROLES = {
	admin: { admin: true, member: true, grant: true, rootWrite: true },
	adminBuiten: { admin: true }, // admin, not a member: the admin page works for every folder
	beheerder: { member: true, grant: true, rootWrite: true },
	beheerderLezen: { member: true, grant: true, rootWrite: false }, // ACL: read only from the root
	bewerker: { member: true, rootWrite: true, readOnlyFile: 'alleenLezen' }, // ACL on one file
	lezer: { member: true, rootWrite: false }, // ACL: read only from the root
	lezerGroep: { member: true, rootWrite: false }, // team folder group permission: read
	verborgen: { member: true, rootWrite: true, hiddenFile: 'geheim' }, // ACL: one file hidden
	buitenstaander: {},
	globaal: { grant: true }, // manage grant, not a member
}

export const FILES = ['open', 'alleenLezen', 'geheim']
// More files beside them, so a request for all of them is big enough for the
// server to list the folder instead of looking up each file. Rights as 'open'.
export const PADDING = ['vulling1', 'vulling2', 'vulling3']

const userId = (role) => PREFIX + role.toLowerCase()
const tag = () => randomBytes(4).toString('hex')

// Admins may read and change every file in a team folder, member or not.
export function canRead(role, file) {
	const r = ROLES[role]
	return (!!r.admin || !!r.member) && r.hiddenFile !== file
}

export function canUpdate(role, file) {
	const r = ROLES[role]
	return canRead(role, file) && (!!r.admin || (!!r.rootWrite && r.readOnlyFile !== file))
}

export class World {
	constructor({ base, adminUser, adminPass, occ }) {
		this.base = base.replace(/\/$/, '')
		this.adminAuth = auth(adminUser, adminPass)
		this.occPrefix = occ
		this.tokens = {}
		this.appPasswords = {}
	}

	// --- plumbing ---

	occ(...args) {
		for (const a of args) {
			if (!/^[\w./+:=-]+$/.test(a)) throw new Error('unsafe occ argument: ' + a)
		}
		try {
			return execFileSync('sh', ['-c', this.occPrefix + ' ' + args.join(' ')], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim()
		} catch (e) {
			throw new Error(`occ ${args.join(' ')}: ${(e.stdout + e.stderr).trim().slice(0, 300)}`)
		}
	}

	async request(role, verb, path, { body, query, headers = {}, raw } = {}) {
		const url = new URL(this.base + path)
		for (const [k, v] of Object.entries(query ?? {})) {
			for (const item of [].concat(v)) url.searchParams.append(k, String(item))
		}
		const init = {
			method: verb,
			headers: {
				Authorization: role === null ? this.adminAuth : this.tokens[role],
				'OCS-APIRequest': 'true',
				Accept: 'application/json',
				...headers,
			},
		}
		if (raw !== undefined) init.body = raw
		else if (body !== undefined) {
			init.body = JSON.stringify(body)
			init.headers['Content-Type'] = 'application/json'
		}
		// A connection that dies (a kept-alive one the server closed after an Apache
		// reload, or a network hiccup) gets one more try instead of hanging the run.
		const send = () => fetch(url, { ...init, signal: AbortSignal.timeout(60_000) })
		const transient = (e) => e.name === 'TimeoutError'
			|| ['UND_ERR_SOCKET', 'UND_ERR_CONNECT_TIMEOUT', 'ETIMEDOUT', 'ECONNRESET'].includes(e.cause?.code)
		let res
		for (let attempt = 1; ; attempt++) {
			try {
				res = await send()
				break
			} catch (e) {
				if (!transient(e) || attempt === 3) throw e
				await new Promise((resolve) => setTimeout(resolve, 2000))
			}
		}
		const text = await res.text()
		let json = null
		try { json = JSON.parse(text) } catch {}
		return { status: res.status, text, json }
	}

	web(role, verb, url, opts) {
		return this.request(role, verb, '/index.php/apps/metavox' + url, opts)
	}

	ocs(role, verb, url, opts = {}) {
		return this.request(role, verb, '/ocs/v2.php/apps/metavox' + url, { ...opts, query: { ...opts.query, format: 'json' } })
	}

	async must(promise, what) {
		const res = await promise
		if (res.status < 200 || res.status > 299) {
			throw new Error(`${what}: HTTP ${res.status} ${res.text.slice(0, 200)}`)
		}
		return res
	}

	// --- building ---

	async setup() {
		await this.cleanup()
		// The search filter and its folders are off until an admin turns them
		// on: on for the run, put back afterwards.
		this.searchSettings = {}
		for (const key of SEARCH_SETTINGS) {
			let before = null
			try {
				before = this.occ('config:app:get', 'metavox', key)
			} catch {
				// Not set: removed again afterwards.
			}
			this.searchSettings[key] = before
			this.occ('config:app:set', 'metavox', key, '--value=true', '--type=boolean')
		}
		const t = tag()
		this.values = {
			open: `e2e-open-${t}`,
			alleenLezen: `e2e-alleen-lezen-${t}`,
			geheim: `e2e-geheim-${t}`,
			folder: `e2e-map-${t}`,
		}
		this.names = {
			open: `open-${t}.txt`,
			alleenLezen: `alleen-lezen-${t}.txt`,
			geheim: `geheim-${t}.txt`,
			view: `E2E view ${t}`,
			fileField: `E2E tekst ${t}`,
			folderField: `E2E map ${t}`,
			outside: `buiten-${t}.txt`,
			...Object.fromEntries(PADDING.map((p) => [p, `${p}-${t}.txt`])),
		}

		const cloud = (verb, path, body) => this.must(this.request(null, verb, '/ocs/v2.php/cloud' + path, { body, query: { format: 'json' } }), `${verb} ${path}`)
		for (const g of [GROUP_MEMBERS, GROUP_READERS, ...DUMMY_GROUPS]) await cloud('POST', '/groups', { groupid: g })
		for (const u of DUMMY_USERS) await cloud('POST', '/users', { userid: u, password: randomBytes(18).toString('base64') + 'aA1!' })
		for (const role of Object.keys(ROLES)) {
			const groups = { admin: ['admin', GROUP_MEMBERS], adminBuiten: ['admin'], lezerGroep: [GROUP_READERS], buitenstaander: [], globaal: [] }[role] ?? [GROUP_MEMBERS]
			const password = randomBytes(18).toString('base64') + 'aA1!'
			await this.must(this.request(null, 'POST', '/ocs/v2.php/cloud/users', { body: { userid: userId(role), password, groups }, query: { format: 'json' } }), 'create user ' + userId(role))
			// An app password from occ, so the user never logs in for the first time: no
			// skeleton files, and no jobs for them that outlive the user and bring its
			// data folder back after cleanup.
			const token = this.occ('user:auth-tokens:add', '--no-interaction', userId(role)).match(/^[A-Za-z0-9]{40,}$/m)?.[0]
			if (!token) throw new Error('no app password for ' + role)
			this.tokens[role] = auth(userId(role), token)
			this.appPasswords[role] = token
		}

		this.folderId = Number(this.occ('groupfolders:create', FOLDER))
		if (!this.folderId) throw new Error('team folder not created')
		this.occ('groupfolders:group', String(this.folderId), GROUP_MEMBERS, 'write', 'share', 'delete')
		this.occ('groupfolders:group', String(this.folderId), GROUP_READERS)

		const dav = `/remote.php/dav/files/${userId('admin')}/${FOLDER}`
		this.fileIds = {}
		for (const f of [...FILES, ...PADDING]) {
			await this.must(this.request('admin', 'PUT', `${dav}/${this.names[f]}`, { raw: 'e2e' }), 'upload ' + f)
		}
		const listing = await this.request('admin', 'PROPFIND', dav, {
			headers: { Depth: '1', 'Content-Type': 'application/xml' },
			raw: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
		})
		for (const f of [...FILES, ...PADDING]) {
			const m = listing.text.match(new RegExp(`${this.names[f].replace('.', '\\.')}</d:href>[\\s\\S]*?<oc:fileid>(\\d+)<`))
			if (!m) throw new Error('no file id for ' + f)
			this.fileIds[f] = Number(m[1])
		}

		// ACL: read only from the root for two roles, one read-only and one hidden file.
		const id = String(this.folderId)
		this.occ('groupfolders:permissions', id, '--enable')
		for (const role of ['lezer', 'beheerderLezen']) {
			this.occ('groupfolders:permissions', id, '/', '--user', userId(role), '--', '-write', '-create', '-delete')
		}
		this.occ('groupfolders:permissions', id, this.names.alleenLezen, '--user', userId('bewerker'), '--', '-write')
		this.occ('groupfolders:permissions', id, this.names.geheim, '--user', userId('verborgen'), '--', '-read')

		// A file in another team folder: a link to it is outside this one.
		this.otherFolderId = Number(this.occ('groupfolders:create', OTHER_FOLDER))
		if (!this.otherFolderId) throw new Error('second team folder not created')
		this.occ('groupfolders:group', String(this.otherFolderId), GROUP_MEMBERS, 'write')
		const otherDav = `/remote.php/dav/files/${userId('admin')}/${OTHER_FOLDER}/${this.names.outside}`
		await this.must(this.request('admin', 'PUT', otherDav, { raw: 'e2e' }), 'upload outside file')
		const other = await this.request('admin', 'PROPFIND', otherDav, {
			headers: { Depth: '0', 'Content-Type': 'application/xml' },
			raw: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
		})
		this.fileIds.outside = Number(other.text.match(/<oc:fileid>(\d+)</)?.[1])
		if (!this.fileIds.outside) throw new Error('no file id for the outside file')

		this.fields = {}
		for (const [key, label, type, folderLevel] of [
			['file', this.names.fileField, 'text', false],
			['folder', this.names.folderField, 'text', true],
			['link', `E2E link ${t}`, 'filelink', false],
			['folderLink', `E2E maplink ${t}`, 'filelink', true],
		]) {
			const name = `${folderLevel ? 'gf_' : 'file_gf_'}e2e_acc_${key.toLowerCase()}_${t}`
			const res = await this.must(this.ocs('admin', 'POST', '/api/v1/groupfolder-fields', {
				body: { field_name: name, field_label: label, field_type: type, applies_to_groupfolder: folderLevel },
			}), 'create field ' + key)
			this.fields[key] = { id: Number(res.json.ocs.data.id), name }
		}
		await this.must(this.ocs('admin', 'POST', `/api/v1/groupfolders/${id}/fields`, {
			body: { field_ids: Object.values(this.fields).map((f) => f.id) },
		}), 'assign fields')

		for (const role of ['beheerder', 'beheerderLezen', 'globaal']) {
			await this.must(this.web(null, 'POST', '/api/permissions/user', {
				body: { user_id: userId(role), permission_type: 'manage_fields' },
			}), 'grant ' + role)
		}

		const view = await this.must(this.ocs('admin', 'POST', `/api/v1/groupfolders/${id}/views`, {
			body: { name: this.names.view, columns: [], filters: {} },
		}), 'create view')
		this.viewId = Number(view.json.ocs.data.id ?? view.json.ocs.data.view?.id)
		if (!this.viewId) throw new Error('no view id: ' + view.text.slice(0, 200))

		await this.restore()
	}

	// The open file links to the hidden one: picked as a path, and once with an
	// id plus the kind of path text older versions stored. Only ids may be kept.
	links() {
		return {
			file: `/${FOLDER}/${this.names.geheim};#${this.fileIds.alleenLezen}:/${FOLDER}/${this.names.alleenLezen}`,
			folder: `/${FOLDER}/${this.names.geheim}`,
		}
	}

	// Puts the values back after a request that may have changed them.
	async restore() {
		for (const f of FILES) {
			const links = f === 'open' ? { [this.fields.link.name]: this.links().file } : {}
			await this.must(this.web('admin', 'POST', `/api/files/${this.fileIds[f]}/metadata`, {
				body: { metadata: { [this.fields.file.name]: this.values[f], ...links } },
			}), 'value ' + f)
		}
		await this.must(this.web('admin', 'POST', `/api/groupfolders/${this.folderId}/metadata`, {
			body: { metadata: { [this.fields.folder.name]: this.values.folder, [this.fields.folderLink.name]: this.links().folder } },
		}), 'folder value')
	}

	// Throwaway objects for the routes that delete something.
	async tempField() {
		const name = `file_gf_e2e_acc_tmp_${tag()}`
		const res = await this.must(this.ocs('admin', 'POST', '/api/v1/groupfolder-fields', {
			body: { field_name: name, field_label: 'E2E tijdelijk', field_type: 'text' },
		}), 'temp field')
		return { id: Number(res.json.ocs.data.id), name }
	}

	async tempView() {
		const res = await this.must(this.ocs('admin', 'POST', `/api/v1/groupfolders/${this.folderId}/views`, {
			body: { name: `E2E tijdelijk ${tag()}`, columns: [], filters: {} },
		}), 'temp view')
		return Number(res.json.ocs.data.id ?? res.json.ocs.data.view?.id)
	}

	userId(role) {
		return userId(role)
	}

	// --- removing ---

	async cleanup() {
		for (const [key, before] of Object.entries(this.searchSettings ?? {})) {
			if (before === null) this.occ('config:app:delete', 'metavox', key)
			else this.occ('config:app:set', 'metavox', key, `--value=${before}`, '--type=boolean')
		}
		this.searchSettings = {}

		const folders = JSON.parse(this.occ('groupfolders:list', '--output=json') || '[]')
		for (const folder of Object.values(folders)) {
			if (![FOLDER, OTHER_FOLDER].includes(folder.mountPoint ?? folder.mount_point)) continue
			const id = folder.id
			const views = await this.ocs(null, 'GET', `/api/v1/groupfolders/${id}/views`)
			for (const v of views.json?.ocs?.data?.views ?? views.json?.ocs?.data ?? []) {
				if (v?.id) await this.ocs(null, 'DELETE', `/api/v1/groupfolders/${id}/views/${v.id}`)
			}
			this.occ('groupfolders:delete', '--force', String(id))
		}

		// Deleting a field also deletes its values everywhere.
		const fields = await this.ocs(null, 'GET', '/api/v1/groupfolder-fields')
		for (const f of fields.json?.ocs?.data ?? []) {
			if (String(f.field_name ?? '').includes('e2e_acc_')) {
				await this.ocs(null, 'DELETE', `/api/v1/groupfolder-fields/${f.id}`)
			}
		}

		const grants = await this.web(null, 'GET', '/api/permissions')
		for (const g of [].concat(grants.json?.permissions ?? grants.json ?? [])) {
			const holder = String(g.user_id ?? '').startsWith(PREFIX) ? 'user' : String(g.group_id ?? '').startsWith(PREFIX) ? 'group' : null
			if (holder) await this.web(null, 'DELETE', `/api/permissions/${holder}/${g.id}`)
		}

		const users = await this.request(null, 'GET', '/ocs/v2.php/cloud/users', { query: { search: PREFIX, format: 'json' } })
		for (const u of users.json?.ocs?.data?.users ?? []) {
			if (u.startsWith(PREFIX)) await this.request(null, 'DELETE', `/ocs/v2.php/cloud/users/${u}`, { query: { format: 'json' } })
		}
		for (const g of [GROUP_MEMBERS, GROUP_READERS, ...DUMMY_GROUPS]) {
			await this.request(null, 'DELETE', `/ocs/v2.php/cloud/groups/${g}`, { query: { format: 'json' } })
		}

		// A deleted team folder leaves search-index rows until the daily cleanup job; run it now.
		const jobs = JSON.parse(this.occ('background-job:list', '--limit=1000', '--output=json') || '[]')
		const cleanupJob = jobs.find((j) => j.class.endsWith('\\MetaVox\\BackgroundJobs\\CleanupDeletedMetadata'))
		if (cleanupJob) this.occ('background-job:execute', '--force-execute', String(cleanupJob.id))
	}
}

function auth(user, pass) {
	return 'Basic ' + Buffer.from(`${user}:${pass}`).toString('base64')
}
