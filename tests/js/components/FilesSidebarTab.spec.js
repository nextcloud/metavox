import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import axios from '@nextcloud/axios'

/**
 * The sidebar panel follows the store: your own save coming back as a push
 * changes nothing, a change from elsewhere refreshes it in place, without the
 * "Loading metadata" spinner, and an open edit dialog is left alone.
 */
// happy-dom's AbortController lacks the tag a browser's has, so Vue would
// wrap it in a proxy and the panel could not recognise its own load.
Object.defineProperty(AbortController.prototype, Symbol.toStringTag, { value: 'AbortController', configurable: true })

vi.mock('@/filesplugin/columns/MetaVoxAPI.js', () => ({
	loadFileMetadata: vi.fn(),
	saveFileFields: vi.fn(),
	whenSaved: vi.fn(() => Promise.resolve()),
}))
vi.mock('@/filesplugin/MetadataForm.vue', () => ({ default: { name: 'MetadataForm', render: () => null } }))

const api = await import('@/filesplugin/columns/MetaVoxAPI.js')
const store = await import('@/filesplugin/columns/MetadataStore.js')
const { default: FilesSidebarTab } = await import('@/filesplugin/FilesSidebarTab.vue')

const field = (name, type, value) => ({ id: name.length, field_name: name, field_label: name, field_type: type, value, applies_to_groupfolder: 0 })
const answer = (values, types = {}) => ({
	groupfolderId: 2,
	editable: true,
	teamFolderFields: [],
	fields: Object.entries(values).map(([name, value]) => field(name, types[name] ?? 'text', value)),
})
const file = (id) => ({ id, name: `doc-${id}.txt`, path: `/A/doc-${id}.txt`, type: 'file', permissions: 31, attributes: {} })

let wrapper
let spinner

async function mountPanel(values, types) {
	api.loadFileMetadata.mockResolvedValue(answer(values, types))
	wrapper = mount(FilesSidebarTab, { props: { fileInfo: file(10) }, attachTo: document.body })
	await flushPromises()
	// Every time the spinner comes back after the first load.
	spinner = []
	wrapper.vm.$watch('loading', (now) => { if (now) spinner.push(true) }, { flush: 'sync' })
	return wrapper
}

beforeEach(() => {
	vi.clearAllMocks()
	api.whenSaved.mockImplementation(() => Promise.resolve())
	store.switchGroupfolder(2)
	store.fillFromApi({ 10: { klant: 'A', _permissions: 31 } }, [10], store.now(), store.currentEpoch())
})

afterEach(() => {
	wrapper?.unmount()
	vi.restoreAllMocks()
	document.body.innerHTML = ''
})

describe('the sidebar panel and the store', () => {
	it('ignores the echo of its own save', async () => {
		await mountPanel({ klant: 'A' })
		api.saveFileFields.mockImplementation(async (id, patch) => store.applyMutation(id, patch))

		wrapper.vm.openEditDialog()
		wrapper.vm.handleMetadataUpdate('klant', 'B')
		await wrapper.vm.saveFromDialog()
		// The push of that save, then the forced reload of the grid.
		store.touch([10])
		store.fillFromApi({ 10: { klant: 'B', _permissions: 31 } }, [10], store.now(), store.currentEpoch())
		await flushPromises()

		expect(api.loadFileMetadata).toHaveBeenCalledOnce()
		expect(spinner).toEqual([])
		expect(wrapper.vm.originalMetadata.klant).toBe('B')
	})

	it('refreshes in place when the grid changed the file', async () => {
		await mountPanel({ klant: 'A' })
		api.loadFileMetadata.mockResolvedValue(answer({ klant: 'C' }))

		store.applyMutation(10, { klant: 'C' })
		await flushPromises()

		expect(api.loadFileMetadata).toHaveBeenCalledTimes(2)
		expect(spinner).toEqual([])
		expect(wrapper.text()).toContain('C')
	})

	it('waits while the edit dialog is open, and refreshes after Cancel', async () => {
		await mountPanel({ klant: 'A' })
		wrapper.vm.openEditDialog()
		api.loadFileMetadata.mockResolvedValue(answer({ klant: 'C' }))

		store.applyMutation(10, { klant: 'C' })
		await flushPromises()
		expect(api.loadFileMetadata).toHaveBeenCalledOnce()

		wrapper.vm.cancelEdit()
		await flushPromises()
		expect(api.loadFileMetadata).toHaveBeenCalledTimes(2)
		expect(wrapper.vm.originalMetadata.klant).toBe('C')
		expect(spinner).toEqual([])
	})

	it('stops a refresh still running when the edit button is pressed, and refreshes after', async () => {
		await mountPanel({ klant: 'A' })
		let signal
		let late
		api.loadFileMetadata.mockImplementation((id, options) => {
			signal = options.signal
			return new Promise((resolve) => { late = resolve })
		})
		store.applyMutation(10, { klant: 'C' })
		await flushPromises()

		await wrapper.findComponent({ ref: 'editButton' }).trigger('click')
		expect(wrapper.vm.editDialogOpen).toBe(true)
		expect(signal.aborted).toBe(true)
		// Its answer, arriving anyway, does not reach what you are editing.
		late(answer({ klant: 'stale' }))
		await flushPromises()
		expect(wrapper.vm.originalMetadata.klant).toBe('A')

		api.loadFileMetadata.mockResolvedValue(answer({ klant: 'C' }))
		wrapper.vm.cancelEdit()
		await flushPromises()
		expect(wrapper.vm.originalMetadata.klant).toBe('C')
	})

	it('starts a refresh again when the file changes while it runs', async () => {
		await mountPanel({ klant: 'A' })
		const answers = []
		api.loadFileMetadata.mockImplementation(() => new Promise((resolve) => answers.push(resolve)))
		store.applyMutation(10, { klant: 'C' })
		await flushPromises()
		// Undo back to A before that refresh answers: it was served with C.
		store.applyMutation(10, { klant: 'A' })
		await flushPromises()

		answers[0](answer({ klant: 'C' }))
		answers.at(-1)(answer({ klant: 'A' }))
		await flushPromises()

		expect(answers.length).toBe(2)
		expect(wrapper.vm.originalMetadata.klant).toBe('A')
	})

	it('starts the first load again when the file changes during it', async () => {
		const answers = []
		api.loadFileMetadata.mockImplementation(() => new Promise((resolve) => answers.push(resolve)))
		wrapper = mount(FilesSidebarTab, { props: { fileInfo: file(10) }, attachTo: document.body })
		await flushPromises()

		store.applyMutation(10, { klant: 'C' })
		await flushPromises()
		answers[0](answer({ klant: 'A' }))
		answers.at(-1)(answer({ klant: 'C' }))
		await flushPromises()

		expect(answers.length).toBe(2)
		expect(wrapper.vm.originalMetadata.klant).toBe('C')
	})

	it('waits for a save of the file still under way before it looks', async () => {
		await mountPanel({ klant: 'A' })
		let saved
		api.whenSaved.mockImplementation(() => new Promise((resolve) => { saved = resolve }))
		api.loadFileMetadata.mockResolvedValue(answer({ klant: 'C' }))

		store.applyMutation(10, { klant: 'C' })
		await flushPromises()
		expect(api.loadFileMetadata).toHaveBeenCalledOnce()

		saved()
		await flushPromises()
		expect(api.loadFileMetadata).toHaveBeenCalledTimes(2)
		expect(wrapper.vm.originalMetadata.klant).toBe('C')
	})

	it('follows a change made during its own save once the dialog closes', async () => {
		await mountPanel({ klant: 'A', note: 'x' })
		let finish
		api.saveFileFields.mockImplementation((id, patch) => new Promise((resolve) => {
			finish = () => { store.applyMutation(id, patch); resolve() }
		}))
		api.loadFileMetadata.mockResolvedValue(answer({ klant: 'B', note: 'y' }))

		wrapper.vm.openEditDialog()
		wrapper.vm.handleMetadataUpdate('klant', 'B')
		const saving = wrapper.vm.saveFromDialog()
		await flushPromises()
		// Someone else, meanwhile.
		store.applyMutation(10, { note: 'y' })
		await flushPromises()
		expect(api.loadFileMetadata).toHaveBeenCalledOnce()

		finish()
		await saving
		await flushPromises()
		expect(api.loadFileMetadata).toHaveBeenCalledTimes(2)
		expect(wrapper.vm.originalMetadata.note).toBe('y')
		expect(spinner).toEqual([])
	})

	it('refreshes after the dialog stopped a refresh, also when the values look the same', async () => {
		// A saved file link reloads for the names the server resolves; the value stays '13:'.
		await mountPanel({ link: '12:;#13:' }, { link: 'filelink' })
		store.applyMutation(10, { link: '12:;#13:' })
		await flushPromises()
		api.saveFileFields.mockImplementation(async (id, patch) => store.applyMutation(id, patch))
		api.loadFileMetadata.mockImplementation((id, { signal }) => new Promise((resolve, reject) => {
			signal.addEventListener('abort', () => reject(new Error('aborted')))
		}))
		wrapper.vm.openEditDialog()
		wrapper.vm.handleMetadataUpdate('link', '13:')
		await wrapper.vm.saveFromDialog()
		await flushPromises()

		wrapper.vm.openEditDialog()
		api.loadFileMetadata.mockResolvedValue(answer({ link: '13:' }, { link: 'filelink' }))
		const before = api.loadFileMetadata.mock.calls.length
		wrapper.vm.cancelEdit()
		await flushPromises()

		expect(api.loadFileMetadata.mock.calls.length).toBe(before + 1)
		expect(spinner).toEqual([])
	})

	it('keeps what it shows when a refresh fails', async () => {
		await mountPanel({ klant: 'A' })
		api.loadFileMetadata.mockRejectedValue(new Error('offline'))
		vi.spyOn(console, 'error').mockImplementation(() => {})

		store.applyMutation(10, { klant: 'C' })
		await flushPromises()

		expect(wrapper.vm.error).toBeNull()
		expect(wrapper.vm.originalMetadata.klant).toBe('A')
		expect(spinner).toEqual([])
	})

	it('refreshes a saved file link in place', async () => {
		await mountPanel({ link: '12:' }, { link: 'filelink' })
		store.applyMutation(10, { link: '12:' })
		await flushPromises()
		api.saveFileFields.mockImplementation(async (id, patch) => store.applyMutation(id, patch))
		api.loadFileMetadata.mockResolvedValue(answer({ link: '12:;#13:' }, { link: 'filelink' }))
		const calls = api.loadFileMetadata.mock.calls.length

		wrapper.vm.openEditDialog()
		wrapper.vm.handleMetadataUpdate('link', '12:;#/A/other.txt')
		await wrapper.vm.saveFromDialog()
		// The server keeps the link by id; the push of the save brings that.
		store.touch([10])
		store.fillFromApi({ 10: { link: '12:;#13:', _permissions: 31 } }, [10], store.now(), store.currentEpoch())
		await flushPromises()

		expect(api.loadFileMetadata.mock.calls.length).toBeGreaterThan(calls)
		expect(spinner).toEqual([])
		expect(wrapper.vm.originalMetadata.link).toBe('12:;#13:')
	})

	it('gives the focus back to the edit button when the dialog closes', async () => {
		await mountPanel({ klant: 'A' })
		wrapper.vm.openEditDialog()
		document.activeElement?.blur()

		wrapper.vm.cancelEdit()
		await wrapper.vm.$nextTick()

		expect(document.activeElement).toBe(wrapper.vm.$refs.editButton.$el)
	})

	it('shows the spinner for another file', async () => {
		await mountPanel({ klant: 'A' })
		api.loadFileMetadata.mockResolvedValue(answer({ klant: 'Z' }))

		await wrapper.setProps({ fileInfo: file(11) })
		await flushPromises()

		expect(spinner).toEqual([true])
	})
})

describe('the "Linked from" list', () => {
	it('stays during a refresh until the new list is there', async () => {
		let reply
		vi.spyOn(axios, 'get').mockImplementation((url) => (url.includes('backlinks')
			? new Promise((resolve) => { reply = resolve })
			: Promise.resolve({ data: { available: false } })))
		await mountPanel({ klant: 'A' })
		reply({ data: [{ fileId: 7, name: 'bron.txt', fieldName: 'klant' }] })
		await flushPromises()
		expect(wrapper.vm.backlinks).toHaveLength(1)

		api.loadFileMetadata.mockResolvedValue(answer({ klant: 'C' }))
		store.applyMutation(10, { klant: 'C' })
		await flushPromises()
		expect(wrapper.vm.backlinks).toHaveLength(1)

		reply({ data: [] })
		await flushPromises()
		expect(wrapper.vm.backlinks).toHaveLength(0)
	})

	it('drops an answer for a file no longer shown', async () => {
		const replies = []
		vi.spyOn(axios, 'get').mockImplementation((url) => (url.includes('backlinks')
			? new Promise((resolve) => replies.push(resolve))
			: Promise.resolve({ data: { available: false } })))
		await mountPanel({ klant: 'A' })
		api.loadFileMetadata.mockResolvedValue(answer({ klant: 'Z' }))
		await wrapper.setProps({ fileInfo: file(11) })
		await flushPromises()

		replies[0]({ data: [{ fileId: 7, name: 'oud.txt', fieldName: 'klant' }] })
		await flushPromises()

		expect(wrapper.vm.backlinks).toEqual([])
	})
})

describe('required fields in the edit dialog', () => {
	const required = async (values) => {
		await mountPanel(values)
		wrapper.vm.itemFields = wrapper.vm.itemFields.map((f) => ({ ...f, is_required: f.field_name === 'klant' }))
		wrapper.vm.openEditDialog()
	}

	it('saves nothing while a required field is empty, and says so under it', async () => {
		await required({ klant: 'A', notitie: '' })
		wrapper.vm.handleMetadataUpdate('klant', '')
		await wrapper.vm.saveFromDialog()

		expect(api.saveFileFields).not.toHaveBeenCalled()
		expect(wrapper.vm.fieldErrors).toEqual({ klant: 'This field is required' })
		expect(wrapper.vm.editDialogOpen).toBe(true)
		expect(wrapper.vm.error).toBe(null)
	})

	it('takes the message away once the field gets a value', async () => {
		await required({ klant: 'A' })
		wrapper.vm.handleMetadataUpdate('klant', '')
		await wrapper.vm.saveFromDialog()
		wrapper.vm.handleMetadataUpdate('klant', 'B')

		expect(wrapper.vm.fieldErrors).toEqual({})
	})

	it('shows what the server refused under the field, and keeps the panel', async () => {
		await mountPanel({ klant: 'A' })
		api.saveFileFields.mockRejectedValue({ response: { status: 400, data: { reason: 'invalid_input', fields: { klant: 'Enter a number' } } } })
		wrapper.vm.openEditDialog()
		wrapper.vm.handleMetadataUpdate('klant', 'B')
		await wrapper.vm.saveFromDialog()

		expect(wrapper.vm.fieldErrors).toEqual({ klant: 'Enter a number' })
		expect(wrapper.vm.editDialogOpen).toBe(true)
		expect(wrapper.vm.error).toBe(null)
	})
})

// NC33+ gives the tab a node and says whether it is the one shown.
const node = (id) => ({ fileid: id, basename: `doc-${id}.txt`, path: `/A/doc-${id}.txt`, type: 'file', permissions: 31, attributes: {} })

describe('the sidebar tab on NC33+', () => {
	beforeEach(() => api.loadFileMetadata.mockResolvedValue(answer({ klant: 'A' })))

	it('loads once when it opens, the node first and then shown', async () => {
		wrapper = mount(FilesSidebarTab, { props: { node: node(10), active: false }, attachTo: document.body })
		await flushPromises()
		expect(api.loadFileMetadata).not.toHaveBeenCalled()

		await wrapper.setProps({ active: true })
		await flushPromises()

		expect(api.loadFileMetadata).toHaveBeenCalledOnce()
	})

	it('loads once when it opens already shown', async () => {
		wrapper = mount(FilesSidebarTab, { props: { node: node(10), active: true }, attachTo: document.body })
		await flushPromises()

		expect(api.loadFileMetadata).toHaveBeenCalledOnce()
	})

	it('asks nothing again on another tab and back', async () => {
		wrapper = mount(FilesSidebarTab, { props: { node: node(10), active: true }, attachTo: document.body })
		await flushPromises()

		await wrapper.setProps({ active: false })
		await wrapper.setProps({ active: true })
		await wrapper.setProps({ node: node(10) })
		await flushPromises()

		expect(api.loadFileMetadata).toHaveBeenCalledOnce()
	})

	it('loads another file, but only once it is shown', async () => {
		wrapper = mount(FilesSidebarTab, { props: { node: node(10), active: true }, attachTo: document.body })
		await flushPromises()
		await wrapper.setProps({ node: node(11) })
		await flushPromises()
		expect(api.loadFileMetadata).toHaveBeenCalledTimes(2)

		await wrapper.setProps({ active: false })
		await wrapper.setProps({ node: node(12) })
		await flushPromises()
		expect(api.loadFileMetadata).toHaveBeenCalledTimes(2)

		await wrapper.setProps({ active: true })
		await flushPromises()
		expect(api.loadFileMetadata).toHaveBeenCalledTimes(3)
		expect(api.loadFileMetadata.mock.calls.map(([id]) => id)).toEqual([10, 11, 12])
	})
})

describe('a People field', () => {
	const owner = (value, name) => ({ ...field('owner', 'user', value), accounts: [{ id: value, kind: 'user', name }] })
	// Nextcloud components are stubs here: the props are their attributes.
	const bubbles = () => wrapper.findAll('.field-display__value [user]').map((b) => [b.attributes('user'), b.attributes('display-name')])

	async function mountWith(fields, teamFolderFields = []) {
		api.loadFileMetadata.mockResolvedValue({ groupfolderId: 2, editable: true, teamFolderFields, fields })
		wrapper = mount(FilesSidebarTab, { props: { fileInfo: file(10) }, attachTo: document.body })
		await flushPromises()
	}

	it('reads as the person, not the account id (github#109)', async () => {
		await mountWith([owner('admin', 'Administrator')], [{ ...owner('rik', 'Rik Dekker'), id: 99, field_name: 'lead', applies_to_groupfolder: 1 }])

		expect(bubbles()).toEqual([['rik', 'Rik Dekker'], ['admin', 'Administrator']])
	})

	it('reads as the person on the team folder itself too', async () => {
		const root = { ...file(10), type: 'dir', attributes: { 'is-mount-root': true } }
		api.loadFileMetadata.mockResolvedValue({ groupfolderId: 2, editable: true, fields: [], teamFolderFields: [{ ...owner('demo001', 'Emma van der Berg'), applies_to_groupfolder: 1 }] })
		wrapper = mount(FilesSidebarTab, { props: { fileInfo: root }, attachTo: document.body })
		await flushPromises()

		expect(bubbles()).toEqual([['demo001', 'Emma van der Berg']])
	})

	it('shows the id of an account that no longer exists', async () => {
		await mountWith([owner('gone', null)])

		expect(bubbles()).toEqual([['gone', 'gone']])
	})

	it('shows everyone it holds: people, a group, a team', async () => {
		await mountWith([{
			...field('owner', 'user', 'anna;#group:staff;#team:Ab12;#team:Hidden'),
			accounts: [
				{ id: 'anna', kind: 'user', name: 'Anna de Vries' },
				{ id: 'group:staff', kind: 'group', name: 'Staff' },
				{ id: 'team:Ab12', kind: 'team', name: 'Project X' },
				// A team you cannot see has no name.
				{ id: 'team:Hidden', kind: 'team', name: null },
			],
		}])

		expect(bubbles()).toEqual([['anna', 'Anna de Vries']])
		expect(wrapper.findAll('.account-bubble__name').map((b) => b.text())).toEqual(['Staff', 'Project X', 'Unavailable team'])
	})

	it('shows the name of the new account once saved, never the old one with it', async () => {
		await mountWith([owner('admin', 'Administrator')])
		api.saveFileFields.mockResolvedValue()
		let answer
		api.loadFileMetadata.mockReturnValue(new Promise((resolve) => { answer = resolve }))

		wrapper.vm.openEditDialog()
		wrapper.vm.handleMetadataUpdate('owner', 'newcomer')
		await wrapper.vm.saveFromDialog()
		await flushPromises()
		expect(bubbles()).toEqual([['newcomer', 'newcomer']])

		answer({ groupfolderId: 2, editable: true, teamFolderFields: [], fields: [owner('newcomer', 'Nieuwe Collega')] })
		await flushPromises()
		expect(bubbles()).toEqual([['newcomer', 'Nieuwe Collega']])
	})
})
