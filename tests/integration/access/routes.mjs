// What every route should do for every role. Levels are the ones from
// lib/Access/Access.php; `since` is the authorization step in which a route
// reaches its level (internal-docs/autorisatie-audit.md). Until then its
// results are reported as pending, not as failures.

import { ROLES, FILES, PADDING, DUMMY_GROUPS, DUMMY_USERS, canRead, canUpdate } from './world.mjs'

// The last authorization step that is done. Raise it with every step.
// (Step 10, file links, was done after 11-13.)
export const DONE_STEP = 15

export const OK = 'ok'
// Any answer except a refusal: for admin routes called with harmless input.
export const NOT_REFUSED = 'not-refused'

// From step 9 on, what you may not see answers 404 instead of 403.
const notMember = (step) => (step >= 9 ? 404 : 403)

export function statusFor(level, role, file, step) {
	const r = ROLES[role]
	switch (level) {
	case 'nc_admin':
		return r.admin ? OK : 403
	case 'logged_in':
		return OK
	case 'team_folder_member':
		return r.admin || r.member ? OK : notMember(step)
	case 'can_update_team_folder':
		// Views and the team folder's own metadata: write permission on the team folder (step 15).
		if (r.admin) return OK
		if (!r.member) return notMember(step)
		return r.rootWrite ? OK : 403
	case 'metavox_manager':
		if (r.admin) return OK
		if (!r.member) return notMember(step)
		// From step 4 on also Nextcloud's write permission on the team folder root.
		return r.grant && (step < 4 || r.rootWrite) ? OK : 403
	case 'can_read':
		return canRead(role, file) ? OK : 404
	case 'can_update':
		return canUpdate(role, file) ? OK : canRead(role, file) ? 403 : 404
	case 'gone':
		return 404
	default:
		return null // not decided yet
	}
}

// --- small helpers for the table ---

const fileBody = (w, file) => ({ metadata: { [w.fields.file.name]: w.values[file] } })
const folderBody = (w) => ({ metadata: { [w.fields.folder.name]: w.values.folder } })
const assignBody = (w) => ({ field_ids: Object.values(w.fields).map((f) => f.id) })
const allFileIds = (w) => FILES.map((f) => w.fileIds[f])
// With the padding: enough files of one folder that the server lists it.
const manyFileIds = (w) => [...FILES, ...PADDING].map((f) => w.fileIds[f])
// A highlight rule with a neutral operand: no world value, so the leak check stays quiet.
const RULE = { op: 'contains', value: 'e2e-regel', tone: 'warning', label: 'E2E markering' }
const BAD_RULE = { op: 'nope', tone: 'error' }
const ruleColumn = (w, format) => ({ field_id: w.fields.file.id, field_name: w.fields.file.name, visible: true, filterable: true, ...(format === undefined ? {} : { format }) })
// A refused role sends a rule the server refuses, and still gets its 403/404: access comes first.
const viewBody = (w, f, p) => ({ name: w.names.view, columns: [ruleColumn(w, [p?.bad ? BAD_RULE : RULE])], filters: {} })
const newViewBody = (w, f, p) => ({ name: 'E2E tijdelijk', columns: [ruleColumn(w, [p?.bad ? BAD_RULE : RULE])], filters: {} })
const newFieldBody = () => ({ field_name: `file_gf_e2e_acc_new_${Date.now()}`, field_label: 'E2E nieuw', field_type: 'text' })
// A value no file has, so the leak check stays quiet when the answer repeats it.
const searchBody = (w) => ({ name: 'E2E zoekopdracht', field: w.fields.file.name, value: 'e2e-zoekwaarde', groupfolderId: w.folderId })
const createdId = (res) => res.json?.ocs?.data?.id ?? res.json?.ocs?.data?.view?.id ?? res.json?.id ?? res.json?.view?.id

const includes = (res, value, what) => (res.text.includes(value) ? null : `${what} missing from the answer`)
const count = (actual, expected, what) => (actual === expected ? null : `${what}: ${actual}, expected ${expected}`)

// The value of the requested file shows up when the call is allowed.
const showsFileValue = (res, w, role, file) => includes(res, w.values[file], 'file value')
const showsFolderValue = (res, w) => includes(res, w.values.folder, 'team folder value')
// Every file the role can read shows its value ("a reader sees the metadata").
const showsReadableValues = (res, w, role) => {
	for (const f of FILES) {
		if (canRead(role, f) && !res.text.includes(w.values[f])) return `value of ${f} missing`
	}
	return null
}
// Each file the user sees, with the edit bit (Nextcloud UPDATE = 2) only where they may edit.
const showsEditBits = (res, w, role) => {
	const files = res.json?.ocs?.data ?? res.json ?? {}
	for (const f of [...FILES, ...PADDING]) {
		const file = files[w.fileIds[f]]
		const rights = PADDING.includes(f) ? 'open' : f
		if (!canRead(role, rights)) {
			if (file !== undefined) return `${f} is shown`
			continue
		}
		if (file === undefined) return `${f} missing`
		if (((file._permissions & 2) !== 0) !== canUpdate(role, rights)) return `${f}: _permissions ${file._permissions}`
	}
	return null
}
// The sidebar's edit right is the one every save checks.
const reportsEditable = (res, w, role, file) => (res.json?.editable === canUpdate(role, file) ? null : `editable ${res.json?.editable}, expected ${canUpdate(role, file)}`)
// Who may assign fields and set defaults: the grant, from step 4 on with write on the root.
const canManage = (role) => !!ROLES[role].admin || (!!ROLES[role].member && !!ROLES[role].grant && !!ROLES[role].rootWrite)
// Who may edit views and the team folder's own metadata: write on the team folder (step 15).
const canUpdateTeamFolder = (role) => !!ROLES[role].admin || (!!ROLES[role].member && !!ROLES[role].rootWrite)
// The flag that shows the view editor in the Files app.
const flagIs = (res, expected) => {
	const flag = res.json?.ocs?.data?.can_manage ?? res.json?.can_manage
	return flag === expected ? null : `can_manage ${flag}, expected ${expected}`
}
const reportsCanEditViews = (res, w, role) => flagIs(res, canUpdateTeamFolder(role))
// init only knows the folders the user is a member of.
const reportsCanEditViewsForMember = (res, w, role) => flagIs(res, canUpdateTeamFolder(role) && !!ROLES[role].member)
const listed = (res, expected) => {
	const found = res.text.includes('"E2E-Rechten"')
	return found === expected ? null : `team folder ${found ? 'listed' : 'not listed'}`
}
const listsManageableFolder = (res, w, role) => listed(res, canManage(role) && !!ROLES[role].member)
// The catalogue shows the test folder's fields to its members and to admins.
const showsFolderFields = (res, w, role) => {
	const shown = res.text.includes(w.names.fileField)
	const expected = !!ROLES[role].member || !!ROLES[role].admin
	return shown === expected ? null : `folder fields ${shown ? 'shown' : 'missing'}`
}
// Exactly the files the role can read, and no ids of hidden ones.
const listsReadableIds = (res, w, role) => {
	const ids = res.json?.ocs?.data?.file_ids ?? []
	for (const f of FILES) {
		if (ids.includes(w.fileIds[f]) !== canRead(role, f)) return `${f} ${ids.includes(w.fileIds[f]) ? 'listed' : 'missing'}`
	}
	return null
}
// Admins see every team folder, on web and OCS.
const listsFolderOrAll = (res, w, role) => listed(res, !!ROLES[role].member || !!ROLES[role].admin)

// --- file links (step 10): ids only, names as the caller sees the file ---
const all = (...checks) => async (...args) => {
	for (const c of checks) {
		const p = await c(...args)
		if (p) return p
	}
	return null
}
// The open file links to the hidden file and to the read-only one.
const linkIds = (w) => `${w.fileIds.geheim}:;#${w.fileIds.alleenLezen}:`
const showsLinksAsIds = (res, w, role, file) => (file !== 'open' || res.text.includes(linkIds(w)) ? null : 'file link not stored as ids only')
// Names come from the caller's own files: members only, and never of a file hidden to them.
const seesName = (w, role, f) => (ROLES[role].member && canRead(role, f) ? w.names[f] : null)
const namesAre = (items, expected) => {
	const names = (items ?? []).map((i) => i.name)
	return JSON.stringify(names) === JSON.stringify(expected) ? null : `link names ${JSON.stringify(names)}, expected ${JSON.stringify(expected)}`
}
// A field list, or the sidebar's answer with its file and team folder fields.
const fieldList = (res) => {
	const data = res.json?.ocs?.data ?? res.json ?? []
	return Array.isArray(data) ? data : [...(data.fields ?? []), ...(data.team_folder_fields ?? [])]
}
const fieldIn = (res, name) => fieldList(res).find((f) => f.field_name === name && (f.value ?? '') !== '') ?? fieldList(res).find((f) => f.field_name === name)
const showsLinkNames = (res, w, role, file) => (file !== 'open' ? null
	: namesAre(fieldIn(res, w.fields.link.name)?.resolved, [seesName(w, role, 'geheim'), seesName(w, role, 'alleenLezen')]))
const showsFolderLinkNames = (res, w, role) => namesAre(fieldIn(res, w.fields.folderLink.name)?.resolved, [seesName(w, role, 'geheim')])
const exportsLinkNames = (res, w, role, file) => {
	const text = file === 'open' && Array.isArray(res.json) && res.json.length ? String(res.json[0].metadata?.[w.fields.link.name] ?? '') : null
	if (text === null) return null
	return text.includes(w.names.alleenLezen) && text.includes(w.names.geheim) === canRead(role, 'geheim') ? null : `exported link "${text}"`
}

// A link to a file the user may not open is left out, as one outside the team
// folder is; one that is there already stays when the value is saved again.
const linksWhatItMayOpen = (surface) => async (res, w, role, file) => {
	if (file !== 'open' || !canUpdate(role, 'open')) return null
	const path = `/files/${w.fileIds.open}/metadata`
	const save = (value) => (surface === 'ocs' ? w.ocs(role, 'POST', '/api/v1' + path, { body: { metadata: { [w.fields.link.name]: value } } })
		: w.web(role, 'POST', '/api' + path, { body: { metadata: { [w.fields.link.name]: value } } }))
	const stored = async () => fieldIn(await w.web('admin', 'GET', '/api' + path), w.fields.link.name)?.value ?? ''
	const { geheim, alleenLezen } = w.fileIds
	try {
		await save(`${geheim}:;#${alleenLezen}:`)
		const kept = await stored()
		await save(`${alleenLezen}:`)
		await save(`${alleenLezen}:;#${geheim}:`)
		const added = await stored()
		if (kept !== `${geheim}:;#${alleenLezen}:`) return `a link already there was not kept: "${kept}"`
		const expected = canRead(role, 'geheim') ? `${alleenLezen}:;#${geheim}:` : `${alleenLezen}:`
		return added === expected ? null : `adding a link to the hidden file stored "${added}", expected "${expected}"`
	} finally {
		await w.restore()
	}
}

// Assigning fields again keeps the defaults of the fields that stay assigned.
const DEFAULT = 'e2e-standaard'
const setFileDefault = (w, value) => w.web('admin', 'POST', `/api/groupfolders/${w.folderId}/defaults`, { body: { fieldId: w.fields.file.id, value } })
const keepsDefaults = {
	prep: async (w) => { await w.must(setFileDefault(w, DEFAULT), 'set default'); return {} },
	check: async (res, w) => ((await w.web('admin', 'GET', `/api/groupfolders/${w.folderId}/defaults`)).text.includes(DEFAULT) ? null : 'default value lost'),
	after: (w) => setFileDefault(w, null),
}

const deleteField = async (w, id) => id && w.ocs('admin', 'DELETE', `/api/v1/groupfolder-fields/${id}`)
const deleteView = async (w, id) => id && w.ocs('admin', 'DELETE', `/api/v1/groupfolders/${w.folderId}/views/${id}`)
const tempField = (w) => w.tempField()
const tempView = async (w) => ({ viewId: await w.tempView() })
const dummyUser = { user_id: DUMMY_USERS[0], permission_type: 'manage_fields' }
const otherUser = { user_id: DUMMY_USERS[1], permission_type: 'manage_fields' }
const dummyGroup = { group_id: DUMMY_GROUPS[0], permission_type: 'manage_fields' }
const otherGroup = { group_id: DUMMY_GROUPS[1], permission_type: 'manage_fields' }

// Grants are revoked by their id (step 14).
const grants = async (w) => {
	const res = await w.web(null, 'GET', '/api/permissions')
	return [].concat(res.json?.permissions ?? res.json ?? [])
}
const holderOf = (grant) => (grant.user_id ? 'user' : 'group')
const grantId = async (w, grant) => (await grants(w)).find((g) => g.user_id === (grant.user_id ?? null) && g.group_id === (grant.group_id ?? null))?.id
const grant = (w, g) => w.web(null, 'POST', `/api/permissions/${holderOf(g)}`, { body: g })
const revoke = async (w, g) => {
	const id = await grantId(w, g)
	if (id) await w.web(null, 'DELETE', `/api/permissions/${holderOf(g)}/${id}`)
}
// Revoking removes exactly the grant with that id, and never one of another holder kind.
const revokeRoute = (mine, other) => ({
	level: 'nc_admin',
	prep: async (w) => {
		await grant(w, mine)
		await grant(w, other)
		return { permissionId: await grantId(w, mine) }
	},
	check: async (res, w) => {
		const left = await grants(w)
		const has = (g) => left.some((x) => x.user_id === (g.user_id ?? null) && x.group_id === (g.group_id ?? null))
		if (has(mine)) return 'the grant is still there'
		if (!has(other)) return 'another grant was removed'
		// The other grant belongs to the other holder kind: this route may not remove it.
		const wrongKind = await w.web(null, 'DELETE', `/api/permissions/${holderOf(mine)}/${await grantId(w, other)}`)
		return wrongKind.status === 404 ? null : `revoking another holder's grant through this route: ${wrongKind.status}, expected 404`
	},
	after: async (w) => {
		await revoke(w, mine)
		await revoke(w, other)
	},
})

// A batch where nothing may be changed: from step 9 on 404 when none of it is visible.
const batchWrite = (role, file, step) => (canUpdate(role, file) ? OK : canRead(role, file) || step < 9 ? 403 : 404)

// The admin page's rules for a field in use (assigned to a team folder) hold for the API too.
const fieldInUse = (w, surface, verb, body) => {
	const path = `/groupfolder-fields/${w.fields.file.id}`
	return surface === 'ocs' ? w.ocs('admin', verb, '/api/v1' + path, { body }) : w.web('admin', verb, '/api' + path, { body })
}
// Its values hang on the name and have the form of the type; making it required is allowed.
const keepsNameAndType = (surface) => async (res, w) => {
	const same = { field_name: w.fields.file.name, field_label: w.names.fileField, field_type: 'text' }
	const renamed = await fieldInUse(w, surface, 'PUT', { ...same, field_name: w.fields.file.name + '_x' })
	const retyped = await fieldInUse(w, surface, 'PUT', { ...same, field_type: 'number' })
	return renamed.status === 400 && retyped.status === 400 ? null : `field in use: rename ${renamed.status}, type ${retyped.status}, expected 400`
}
const cannotBeDeleted = (surface) => async (res, w) => {
	const deleted = await fieldInUse(w, surface, 'DELETE')
	return deleted.status === 400 ? null : `deleting a field in use: ${deleted.status}, expected 400`
}

// A name carries the prefix of its kind: file_gf_ for a file field, gf_ for a team folder field.
const fieldsAt = (w, surface, verb, path, body) => (surface === 'ocs' ? w.ocs('admin', verb, '/api/v1' + path, { body }) : w.web('admin', verb, '/api' + path, { body }))
const refusesBadNames = (surface) => async (res, w, role) => {
	if (role !== 'admin') return null
	const bad = (body) => ({ field_label: 'E2E fout', field_type: 'text', ...body })
	const attempts = [
		['create without a prefix', 'POST', '/groupfolder-fields', bad({ field_name: `e2e_acc_bad_${Date.now()}` })],
		['create a team folder field with file_gf_', 'POST', '/groupfolder-fields', bad({ field_name: `file_gf_e2e_acc_bad_${Date.now()}`, applies_to_groupfolder: 1 })],
		['rename to a name without a prefix', 'PUT', `/groupfolder-fields/${createdId(res)}`, bad({ field_name: `e2e_acc_bad_${Date.now()}` })],
	]
	for (const [what, verb, path, body] of attempts) {
		const answer = await fieldsAt(w, surface, verb, path, body)
		if (!refusedAt(answer, 'field_name')) {
			if (verb === 'POST' && answer.status < 300) await deleteField(w, createdId(answer))
			return `${surface}: ${what} gave ${answer.status}, expected a 400 on field_name`
		}
	}
	// An update that leaves applies_to_groupfolder out keeps the kind.
	const folder = w.fields.folder
	const kept = await fieldsAt(w, surface, 'PUT', `/groupfolder-fields/${folder.id}`, { field_name: folder.name, field_label: w.names.folderField, field_type: 'text' })
	const listed = [].concat(viewData(await fieldsAt(w, surface, 'GET', '/groupfolder-fields')) ?? []).find((f) => Number(f.id) === folder.id)
	if (kept.status !== 200 || Number(listed?.applies_to_groupfolder) !== 1) {
		return `${surface}: an update without applies_to_groupfolder gave ${kept.status} and kind ${listed?.applies_to_groupfolder}, expected 200 and 1`
	}
	return null
}

// Field definitions are admin work, on both surfaces.
const createField = (surface) => ({ level: 'nc_admin', since: 6, body: newFieldBody, check: refusesBadNames(surface), after: (w, role, file, res) => res.status < 300 && deleteField(w, createdId(res)) })
const updateField = (surface) => ({ level: 'nc_admin', since: 6, prep: tempField, body: (w, f, p) => ({ field_name: p.name, field_label: 'E2E gewijzigd', field_type: 'text' }), check: keepsNameAndType(surface), after: (w, role, file, res, p) => deleteField(w, p.id) })
const deleteFieldRoute = (surface) => ({ level: 'nc_admin', since: 6, prep: tempField, check: cannotBeDeleted(surface), after: (w, role, file, res, p) => deleteField(w, p.id) })
// Several at once: a field in use stays and comes back as skipped.
const skipsFieldInUse = async (res, w) => {
	const answer = await w.web('admin', 'POST', '/api/groupfolder-fields/bulk-delete', { body: { ids: [w.fields.file.id] } })
	const skipped = answer.json?.skipped ?? []
	return answer.status === 200 && (answer.json?.deleted ?? []).length === 0 && skipped.some((s) => s.id === w.fields.file.id && s.reason === 'in_use')
		? null : `bulk delete of a field in use: ${answer.status} ${answer.text.slice(0, 120)}`
}
const bulkDeleteFields = { level: 'nc_admin', since: 6, prep: tempField, body: (w, f, p) => ({ ids: [p.id] }), check: skipsFieldInUse, after: (w, role, file, res, p) => deleteField(w, p.id) }

// --- views and their highlight rules ---
const sendsBadRuleWhenRefused = async (w, role) => ({ bad: !canUpdateTeamFolder(role) })
const viewData = (res) => res.json?.ocs?.data ?? res.json
const ruleIn = (view, w) => (view?.columns ?? []).find((c) => Number(c.field_id) === w.fields.file.id)?.format
const isRule = (format) => Array.isArray(format) && format.length === 1 && ['op', 'value', 'tone', 'label'].every((k) => format[0][k] === RULE[k])
const showsRule = (res, w) => (isRule(ruleIn(viewData(res), w)) ? null : 'highlight rule missing from the answer')
const putView = (surface, w, role, column, name = w.names.view) => {
	const body = { name, columns: [column], filters: {} }
	const path = `/groupfolders/${w.folderId}/views/${w.viewId}`
	return surface === 'ocs' ? w.ocs(role, 'PUT', '/api/v1' + path, { body }) : w.web(role, 'PUT', '/api' + path, { body })
}
const refusedAt = (res, key) => res.status === 400 && viewData(res)?.reason === 'invalid_input' && viewData(res)?.fields?.[key] !== undefined
// Once per surface, by a manager who is no admin: keep, refuse, clear.
const keepsRules = (surface) => async (res, w, role) => {
	if (role !== 'beheerder') return null
	const put = (format, name) => putView(surface, w, role, ruleColumn(w, format), name)
	const steps = [
		['a save without rules keeps them', async () => isRule(ruleIn(viewData(await put(undefined)), w))],
		['an unknown operator is a 400', async () => refusedAt(await put([BAD_RULE]), 'columns.0.format.0.op')],
		['format null is a 400', async () => refusedAt(await put(null), 'columns.0.format')],
		['[] clears', async () => ruleIn(viewData(await put([])), w) === undefined],
		['the rule is stored again', async () => isRule(ruleIn(viewData(await put([RULE])), w))],
		['{} clears', async () => ruleIn(viewData(await put({})), w) === undefined],
		['and again', async () => isRule(ruleIn(viewData(await put([RULE])), w))],
		['a field id beyond the database is a 400', async () => refusedAt(await putView(surface, w, role, { ...ruleColumn(w), field_id: 2147483648 }), 'columns.0.field_id')],
		// varchar(100) counts characters on MySQL and PostgreSQL too.
		['a name of 100 characters is stored', async () => {
			const long = await put(undefined, 'é'.repeat(100))
			return long.status === 200 && viewData(long)?.name === 'é'.repeat(100) && isRule(ruleIn(viewData(long), w))
		}],
		['a name of 101 characters is a 400', async () => (await put(undefined, 'é'.repeat(101))).status === 400],
	]
	try {
		for (const [what, ok] of steps) {
			if (!(await ok())) return `${surface}: ${what} failed`
		}
		return null
	} finally {
		await put([RULE])
	}
}
const views = (surface) => ({
	list: { level: 'team_folder_member', since: 15, check: reportsCanEditViews },
	create: { level: 'can_update_team_folder', since: 15, prep: sendsBadRuleWhenRefused, body: newViewBody, check: showsRule, after: (w, role, file, res) => res.status < 300 && deleteView(w, createdId(res)) },
	reorder: { level: 'can_update_team_folder', since: 15, body: (w) => ({ view_ids: [w.viewId] }) },
	update: { level: 'can_update_team_folder', since: 15, prep: sendsBadRuleWhenRefused, body: viewBody, check: all(showsRule, keepsRules(surface)) },
	delete: { level: 'can_update_team_folder', since: 15, prep: tempView, after: (w, role, file, res, p) => deleteView(w, p.viewId) },
})
const webViews = views('web')
const ocsViews = views('ocs')
const directoryMetadata = {
	level: 'team_folder_member', // the controller filters the files per user
	query: (w) => ({ file_ids: manyFileIds(w).join(',') }),
	check: all(showsReadableValues, showsEditBits),
}

export const ROUTES = {
	// --- field catalogue and definitions ---
	'field#getGroupfolderFields': { level: 'nc_admin', since: 8 },
	'field#createGroupfolderField': createField('web'),
	'field#updateGroupfolderField': updateField('web'),
	'field#deleteGroupfolderField': deleteFieldRoute('web'),
	'field#bulkDeleteFields': bulkDeleteFields,
	'user_field#getAllGroupfolderFields': { level: 'logged_in', since: 8, check: showsFolderFields },
	'apiField#getGroupfolderFields': { level: 'logged_in', since: 8, check: showsFolderFields },
	'apiField#createGroupfolderField': createField('ocs'),
	'apiField#updateGroupfolderField': updateField('ocs'),
	'apiField#deleteGroupfolderField': deleteFieldRoute('ocs'),

	// --- team folders ---
	'field#getGroupfolders': { level: 'logged_in', check: listsFolderOrAll },
	'apiField#getGroupfolders': { level: 'logged_in', since: 10, check: listsFolderOrAll },
	'user_field#getAccessibleGroupfolders': { level: 'logged_in', since: 4, check: listsManageableFolder },
	'field#getGroupfolderMetadata': { level: 'team_folder_member', check: all(showsFolderValue, showsFolderLinkNames) },
	'user_field#getGroupfolderMetadata': { level: 'team_folder_member', check: all(showsFolderValue, showsFolderLinkNames) },
	'apiField#getGroupfolderMetadata': { level: 'team_folder_member', check: showsFolderValue },
	'field#saveGroupfolderMetadata': { level: 'can_update_team_folder', since: 15, body: folderBody },
	'apiField#saveGroupfolderMetadata': { level: 'can_update_team_folder', since: 15, body: folderBody },
	'field#getGroupfolderAssignedFields': { level: 'team_folder_member' },
	'apiField#getGroupfolderAssignedFields': { level: 'team_folder_member' },
	'user_field#getGroupfolderFields': { level: 'team_folder_member' },
	'field#getGroupfolderFileFields': { level: 'team_folder_member' },
	'apiField#getGroupfolderFileFields': { level: 'team_folder_member' },
	'field#setGroupfolderFields': { level: 'metavox_manager', body: assignBody, ...keepsDefaults },
	'user_field#setGroupfolderFields': { level: 'metavox_manager', body: assignBody, ...keepsDefaults },
	'apiField#setGroupfolderFields': { level: 'metavox_manager', since: 5, body: assignBody, ...keepsDefaults },

	// --- defaults ---
	'defaults#getDefaults': { level: 'metavox_manager', since: 5 },
	'defaults#setDefault': { level: 'metavox_manager', since: 5, body: (w) => ({ fieldId: w.fields.file.id, value: '' }) },
	'defaults#trigger': { level: 'metavox_manager', since: 5, restore: true },
	'defaults#status': { level: 'metavox_manager', since: 5 },

	// --- one file ---
	'field#getFileMetadata': { level: 'can_read', since: 15, files: true, check: all(showsFileValue, showsFolderValue, showsLinksAsIds, showsLinkNames, reportsEditable) },
	'apiField#getGroupfolderFileMetadata': { level: 'can_read', files: true, check: all(showsFileValue, showsLinksAsIds) },
	'apiField#getFileMetadata': { level: 'can_read', files: true, check: all(showsFileValue, showsLinksAsIds) },
	'field#getFileBacklinks': { level: 'can_read', files: true },
	'field#saveFileMetadata': { level: 'can_update', since: 15, files: true, body: fileBody, check: linksWhatItMayOpen('web') },
	'apiField#saveGroupfolderFileMetadata': { level: 'can_update', files: true, body: fileBody },
	'apiField#saveFileMetadata': { level: 'can_update', files: true, body: fileBody, check: linksWhatItMayOpen('ocs') },
	'lock#lockCell': {
		level: 'can_update', files: true, body: (w) => ({ field_name: w.fields.file.name }),
		after: (w, role, file, res) => res.status < 300 && w.web(role, 'POST', `/api/groupfolders/${w.folderId}/files/${w.fileIds[file]}/unlock`, { body: { field_name: w.fields.file.name } }),
	},
	'lock#unlockCell': { level: 'can_read', files: true, body: (w) => ({ field_name: w.fields.file.name }) },
	// Allowed calls would start an AI generation: only the refusals are tested.
	'aiAutofill#generate': { level: 'can_update', files: true, refusalsOnly: true, body: (w, file) => ({ fileId: w.fileIds[file], groupfolderId: w.folderId }) },

	// --- file lists: the controller checks every file ---
	'field#saveBulkFileMetadata': {
		files: true, expect: () => OK,
		body: (w, file) => ({ fileIds: [w.fileIds[file]], ...fileBody(w, file) }),
		check: (res, w, role, file) => count(res.json?.successCount, canUpdate(role, file) ? 1 : 0, 'saved'),
	},
	'field#clearFileMetadata': {
		files: true, expect: () => OK, restore: true,
		body: (w, file) => ({ fileIds: [w.fileIds[file]] }),
		check: (res, w, role, file) => count(res.json?.successCount, canUpdate(role, file) ? 1 : 0, 'cleared'),
	},
	'field#exportFileMetadata': {
		files: true, expect: () => OK,
		body: (w, file) => ({ fileIds: [w.fileIds[file]] }),
		// Needs the file in the user's own files, so an admin who is no member gets nothing.
		check: all(
			(res, w, role, file) => count(Array.isArray(res.json) ? res.json.length : -1, canRead(role, file) && ROLES[role].member ? 1 : 0, 'exported'),
			exportsLinkNames,
		),
	},
	'api_field#getBulkFileMetadata': {
		files: true, expect: (role, file, step) => (canRead(role, file) ? OK : step >= 9 ? 404 : 403),
		query: (w, file) => ({ file_ids: w.fileIds[file] }),
	},
	'apiField#batchUpdateFileMetadata': {
		files: true, expect: (role, file, step) => batchWrite(role, file, step),
		body: (w, file) => ({ updates: [{ file_id: w.fileIds[file], groupfolder_id: w.folderId, ...fileBody(w, file) }] }),
	},
	'apiField#batchDeleteFileMetadata': {
		files: true, restore: true, expect: (role, file, step) => batchWrite(role, file, step),
		body: (w, file) => ({ deletes: [{ file_id: w.fileIds[file], groupfolder_id: w.folderId, field_names: [w.fields.file.name] }] }),
	},
	'apiField#batchCopyFileMetadata': {
		files: true, restore: true,
		// Copies from the open file: reading it is enough, the target must be writable.
		expect: (role, file, step) => (!canRead(role, 'open') ? 404 : batchWrite(role, file, step)),
		body: (w, file) => ({ source_file_id: w.fileIds.open, target_file_ids: [w.fileIds[file]] }),
	},
	'filter#getDirectoryMetadata': directoryMetadata,
	'apiFilter#getDirectoryMetadata': directoryMetadata,

	// --- value overviews: filtered per file from step 7 ---
	'user_field#getFieldValues': { level: 'team_folder_member', since: 7, query: (w) => ({ field: w.fields.file.name }) },
	'apiFilter#getAllFilterValues': { level: 'team_folder_member', since: 7, query: (w) => ({ field_names: w.fields.file.name }) },
	'apiFilter#getScopedFilterValues': { level: 'team_folder_member', since: 7, body: (w) => ({ file_ids: allFileIds(w) }) },
	'apiFilter#getSortedFileIds': { level: 'team_folder_member', since: 7, query: (w) => ({ sort_field: w.fields.file.name }), check: listsReadableIds },

	// --- views ---
	'view#listViews': webViews.list,
	'view#createView': webViews.create,
	'view#reorderViews': webViews.reorder,
	'view#updateView': webViews.update,
	'view#deleteView': webViews.delete,
	'apiView#listViews': ocsViews.list,
	'apiView#createView': ocsViews.create,
	'apiView#reorderViews': ocsViews.reorder,
	'apiView#updateView': ocsViews.update,
	'apiView#deleteView': ocsViews.delete,
	'view#init': { level: 'logged_in', since: 15, query: () => ({ dir: '/E2E-Rechten' }), check: reportsCanEditViewsForMember },

	// Saved searches: everyone's own. What they find is checked in run.mjs (savedsearch).
	'metadata_search#index': { level: 'logged_in', since: 15 },
	'metadata_search#create': { level: 'logged_in', since: 15, body: searchBody, after: (w, role, file, res) => res.status < 300 && w.web(role, 'DELETE', `/api/user/metadata-searches/${res.json?.id}`) },
	'metadata_search#destroy': { level: 'logged_in', since: 15, prep: async (w, role) => ({ id: (await w.must(w.web(role, 'POST', '/api/user/metadata-searches', { body: searchBody(w) }), 'save search')).json.id }) },

	// --- the rest for everyone ---
	'presence#leave': { level: 'logged_in', body: (w) => ({ gf_id: w.folderId }) },
	'aiAutofill#status': { level: 'logged_in' },
	'field#getUsers': { level: 'logged_in', since: 14, query: () => ({ search: 'e2e-acc' }) },

	// --- Nextcloud admins ---
	'permission#getGroups': { level: 'nc_admin' },
	'permission#getAllPermissions': { level: 'nc_admin' },
	'permission#grantUserPermission': { level: 'nc_admin', body: () => dummyUser, after: (w) => revoke(w, dummyUser) },
	'permission#grantGroupPermission': { level: 'nc_admin', body: () => dummyGroup, after: (w) => revoke(w, dummyGroup) },
	'permission#revokeUserPermission': revokeRoute(dummyUser, otherGroup),
	'permission#revokeGroupPermission': revokeRoute(dummyGroup, otherUser),
	'settings#get': { level: 'nc_admin' },
	'apiField#getMetadataStatistics': { level: 'nc_admin' },
	'telemetry#getStatus': { level: 'nc_admin' },
	'telemetry#getStats': { level: 'nc_admin' },
	'backup#list': { level: 'nc_admin' },
	'backup#status': { level: 'nc_admin' },
	'backup#download': { level: 'nc_admin', expect: (role) => (ROLES[role].admin ? NOT_REFUSED : 403), query: () => ({ filename: 'e2e-bestaat-niet.json' }) },
	// Side effects (settings, license server, telemetry, backups): only the refusals.
	'settings#save': { level: 'nc_admin', refusalsOnly: true },
	'license#getStats': { level: 'nc_admin', refusalsOnly: true },
	'license#saveSettings': { level: 'nc_admin', refusalsOnly: true },
	'license#validate': { level: 'nc_admin', refusalsOnly: true },
	'license#updateUsage': { level: 'nc_admin', refusalsOnly: true },
	'telemetry#sendTelemetry': { level: 'nc_admin', refusalsOnly: true },
	'telemetry#saveSettings': { level: 'nc_admin', refusalsOnly: true },
	'backup#trigger': { level: 'nc_admin', refusalsOnly: true },
	'backup#restore': { level: 'nc_admin', refusalsOnly: true },
}

// Not a MetaVox route, but where the grid gets its values: the Files app's PROPFIND.
export const DAV_CHECK = {
	level: 'team_folder_member',
	expect: (role) => (ROLES[role].member ? 207 : 404),
	check: showsReadableValues,
}
