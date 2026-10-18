import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import FilterPicker from '@/search/FilterPicker.vue'

/**
 * The search filter's picker: the team folders you are a member of (not only
 * those you manage), a folder's file fields in one request, and the values.
 */
const FOLDERS = [{ id: 2, mount_point: 'MetaVoxTest' }, { id: 3, mount_point: 'MetaVoxTest/Genest' }]
const FIELDS = [
	{ id: 7, field_name: 'file_gf_status', field_label: 'Status', field_type: 'select', applies_to_groupfolder: 0 },
	{ id: 8, field_name: 'file_gf_tags', field_label: 'Tags', field_type: 'multiselect', applies_to_groupfolder: 0 },
	{ id: 9, field_name: 'file_gf_link', field_label: 'Link', field_type: 'filelink', applies_to_groupfolder: 0 },
]

// Every team folder of the user together: a team folder field and a file link too, which the picker leaves out.
const ALL_FIELDS = [
	...FIELDS,
	{ id: 10, field_name: 'gf_owner', field_label: 'Owner', field_type: 'text', applies_to_groupfolder: 1 },
	{ id: 11, field_name: 'file_gf_klant', field_label: 'Klant', field_type: 'text', applies_to_groupfolder: 0 },
]

function stubServer() {
	return vi.spyOn(axios, 'get').mockImplementation((url) => {
		if (url === '/apps/metavox/api/groupfolders') return Promise.resolve({ data: FOLDERS })
		if (url === '/apps/metavox/api/user/groupfolder-fields') return Promise.resolve({ data: ALL_FIELDS })
		if (url === '/apps/metavox/api/groupfolders/2/file-fields') return Promise.resolve({ data: FIELDS })
		if (url.endsWith('/field-values')) return Promise.resolve({ data: { values: ['Rapport', 'Notulen'] } })
		return Promise.reject(new Error('unexpected ' + url))
	})
}

afterEach(() => {
	vi.restoreAllMocks()
	document.body.innerHTML = ''
})

describe('FilterPicker', () => {
	it('lists the team folders you are a member of', async () => {
		const get = stubServer()
		const wrapper = mount(FilterPicker)
		await flushPromises()

		expect(get).toHaveBeenCalledWith('/apps/metavox/api/groupfolders', { params: { scope: 'member' } })
		expect(wrapper.vm.folders).toEqual(FOLDERS)
	})

	it('offers a folder\'s file fields in one request, without file links', async () => {
		const get = stubServer()
		const wrapper = mount(FilterPicker)
		await flushPromises()

		wrapper.vm.selectedFolder = FOLDERS[0]
		await wrapper.vm.onFolderChange()

		expect(get.mock.calls.filter(([url]) => url.includes('field')).map(([url]) => url)).toEqual(['/apps/metavox/api/groupfolders/2/file-fields'])
		expect(wrapper.vm.fields.map((f) => f.field_name)).toEqual(['file_gf_status', 'file_gf_tags'])
	})

	it('finds files with any of several picked options', async () => {
		stubServer()
		const wrapper = mount(FilterPicker)
		await flushPromises()
		wrapper.vm.selectedFolder = FOLDERS[0]
		await wrapper.vm.onFolderChange()
		wrapper.vm.selectedField = FIELDS[1]
		wrapper.vm.onFieldChange()
		await flushPromises()

		wrapper.vm.value = ['Rapport', 'Notulen']
		await wrapper.vm.$nextTick()
		expect(wrapper.text()).toContain('Files with any of the selected options are shown.')

		wrapper.vm.apply()
		expect(wrapper.emitted('select')[0][0]).toEqual({
			field: 'file_gf_tags', value: 'Rapport;#Notulen', label: 'Tags: Rapport, Notulen', groupfolderId: 2,
		})
	})

	it('leaves the folder list empty, without an error, when you are in no team folder', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
		const wrapper = mount(FilterPicker)
		await flushPromises()

		expect(wrapper.vm.folders).toEqual([])
		expect(wrapper.vm.loadingFolders).toBe(false)
	})

	describe('all my team folders', () => {
		async function inAll(get = stubServer()) {
			// A spy set up by the test itself comes as {}.
			get = typeof get?.mock === 'object' ? get : null
			const wrapper = mount(FilterPicker)
			await flushPromises()
			wrapper.vm.selectedFolder = wrapper.vm.folderOptions[0]
			await wrapper.vm.onFolderChange()
			return { wrapper, get }
		}

		it('comes first, when there is more than one team folder', async () => {
			stubServer()
			const wrapper = mount(FilterPicker)
			await flushPromises()

			expect(wrapper.vm.folderOptions.map((f) => f.mount_point)).toEqual(['All my Team folders', 'MetaVoxTest', 'MetaVoxTest/Genest'])
		})

		it('is not offered without team folders', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
			const wrapper = mount(FilterPicker)
			await flushPromises()

			expect(wrapper.vm.folderOptions).toEqual([])
		})

		it('is not offered for a single team folder', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({ data: [FOLDERS[0]] })
			const wrapper = mount(FilterPicker)
			await flushPromises()

			expect(wrapper.vm.folderOptions).toEqual([FOLDERS[0]])
		})

		it('offers the file fields of every team folder of the user, in one request', async () => {
			const { wrapper, get } = await inAll()

			expect(get).toHaveBeenCalledWith('/apps/metavox/api/user/groupfolder-fields', { params: { scope: 'member' } })
			expect(get.mock.calls.filter(([url]) => url.includes('file-fields'))).toEqual([])
			expect(wrapper.vm.fields.map((f) => f.field_name)).toEqual(['file_gf_status', 'file_gf_tags', 'file_gf_klant'])
		})

		it('reads no values from the folders: options come from the field, others are typed', async () => {
			const { wrapper, get } = await inAll()
			wrapper.vm.selectedField = ALL_FIELDS[1]
			wrapper.vm.onFieldChange()
			await flushPromises()

			expect(wrapper.vm.useValueSuggestions).toBe(false)
			expect(get.mock.calls.filter(([url]) => url.endsWith('/field-values'))).toEqual([])
			expect(wrapper.text()).toContain('Searches every Team folder you are a member of.')
		})

		it('tells a typed value has to be complete and exact', async () => {
			const { wrapper } = await inAll()
			wrapper.vm.selectedField = ALL_FIELDS[3]
			await wrapper.vm.$nextTick()

			expect(wrapper.text()).toContain('Type the whole value, exactly as it is written.')
		})

		it('names fields that share a label', async () => {
			const twin = { id: 12, field_name: 'file_gf_status_sales', field_label: 'Status', field_type: 'select', applies_to_groupfolder: 0 }
			vi.spyOn(axios, 'get').mockImplementation((url) => Promise.resolve({ data: url === '/apps/metavox/api/groupfolders' ? FOLDERS : [...ALL_FIELDS, twin] }))
			const { wrapper } = await inAll({})

			expect(wrapper.vm.fields.map((f) => f.display_label)).toEqual(['Status (file_gf_status)', 'Tags', 'Klant', 'Status (file_gf_status_sales)'])
			wrapper.vm.selectedField = wrapper.vm.fields[3]
			wrapper.vm.value = 'Concept'
			wrapper.vm.apply()
			expect(wrapper.emitted('select')[0][0].label).toBe('Status (file_gf_status_sales): Concept')
		})

		it('keeps the fields of the folder picked last', async () => {
			let answerAll
			vi.spyOn(axios, 'get').mockImplementation((url) => {
				if (url === '/apps/metavox/api/groupfolders') return Promise.resolve({ data: FOLDERS })
				if (url === '/apps/metavox/api/user/groupfolder-fields') return new Promise((resolve) => { answerAll = resolve })
				return Promise.resolve({ data: [FIELDS[0]] })
			})
			const wrapper = mount(FilterPicker)
			await flushPromises()

			wrapper.vm.selectedFolder = wrapper.vm.folderOptions[0]
			const slow = wrapper.vm.onFolderChange()
			wrapper.vm.selectedFolder = FOLDERS[0]
			await wrapper.vm.onFolderChange()
			answerAll({ data: ALL_FIELDS })
			await slow

			expect(wrapper.vm.fields.map((f) => f.field_name)).toEqual(['file_gf_status'])
			expect(wrapper.vm.loadingFields).toBe(false)
		})

		it('filters without a team folder', async () => {
			const { wrapper } = await inAll()
			wrapper.vm.selectedField = FIELDS[1]
			// The multi-select's own input gives its options joined.
			wrapper.vm.value = 'Rapport;#Notulen'
			await wrapper.vm.$nextTick()

			wrapper.vm.apply()
			expect(wrapper.emitted('select')[0][0]).toEqual({
				field: 'file_gf_tags', value: 'Rapport;#Notulen', label: 'Tags: Rapport, Notulen', groupfolderId: null,
			})
		})

		it('saves a folder over all team folders', async () => {
			window.OC = { config: { version: '35.0.0.10' } }
			globalThis.__INITIAL_STATE__ = { 'metavox:search-folders': true }
			const { wrapper } = await inAll()
			wrapper.vm.selectedField = FIELDS[0]
			wrapper.vm.value = 'Concept'
			const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { id: 4 } })
			vi.spyOn(window.location, 'assign').mockImplementation(() => {})

			await wrapper.vm.saveAsFolder()

			expect(post.mock.calls[0][1]).toEqual({ name: 'Status: Concept', field: 'file_gf_status', value: 'Concept', groupfolderId: null })
			delete window.OC
			delete globalThis.__INITIAL_STATE__
		})
	})

	describe('save as folder', () => {
		async function picked() {
			stubServer()
			const wrapper = mount(FilterPicker)
			await flushPromises()
			wrapper.vm.selectedFolder = FOLDERS[0]
			await wrapper.vm.onFolderChange()
			wrapper.vm.selectedField = FIELDS[1]
			wrapper.vm.onFieldChange()
			await flushPromises()
			wrapper.vm.value = ['Rapport', 'Notulen']
			await wrapper.vm.$nextTick()
			return wrapper
		}

		beforeEach(() => {
			// The admin turned the folders on; the page says so.
			globalThis.__INITIAL_STATE__ = { 'metavox:search-folders': true }
		})

		afterEach(() => {
			delete window.OC
			delete globalThis.__INITIAL_STATE__
		})

		it('is not offered when the admin left the folders off', async () => {
			window.OC = { config: { version: '35.0.0.10' } }
			globalThis.__INITIAL_STATE__ = {}
			const wrapper = await picked()

			expect(wrapper.vm.canSaveAsFolder).toBe(false)
			expect(wrapper.text()).not.toContain('Save as folder')
		})

		it('is not offered before Nextcloud 33, which has no such folders', async () => {
			window.OC = { config: { version: '32.0.5.1' } }
			const wrapper = await picked()

			expect(wrapper.vm.canSaveAsFolder).toBe(false)
			expect(wrapper.text()).not.toContain('Save as folder')
		})

		it('saves the filter and opens its folder in Files', async () => {
			window.OC = { config: { version: '35.0.0.10' } }
			const wrapper = await picked()
			const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { id: 12 } })
			const open = vi.spyOn(window.location, 'assign').mockImplementation(() => {})

			expect(wrapper.text()).toContain('Save as folder')
			await wrapper.vm.saveAsFolder()

			expect(post).toHaveBeenCalledWith('/apps/metavox/api/user/metadata-searches', {
				name: 'Tags: Rapport, Notulen', field: 'file_gf_tags', value: 'Rapport;#Notulen', groupfolderId: 2,
			})
			expect(open).toHaveBeenCalledWith('/apps/files/metavox-searches?dir=%2F12')
		})

		it('saves once, however often it is clicked', async () => {
			window.OC = { config: { version: '35.0.0.10' } }
			const wrapper = await picked()
			let answer
			const post = vi.spyOn(axios, 'post').mockReturnValue(new Promise((resolve) => { answer = resolve }))
			vi.spyOn(window.location, 'assign').mockImplementation(() => {})

			const first = wrapper.vm.saveAsFolder()
			wrapper.vm.saveAsFolder()
			answer({ data: { id: 12 } })
			await first

			expect(post).toHaveBeenCalledOnce()
		})

		it('cuts a long name by characters, never half an emoji', async () => {
			window.OC = { config: { version: '35.0.0.10' } }
			const wrapper = await picked()
			// "Tags: " and 93 x's are 99 characters: the emoji is the 100th.
			wrapper.vm.value = ['x'.repeat(93) + '😀abc']
			await wrapper.vm.$nextTick()
			const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { id: 12 } })
			vi.spyOn(window.location, 'assign').mockImplementation(() => {})

			await wrapper.vm.saveAsFolder()

			const name = post.mock.calls[0][1].name
			expect(Array.from(name)).toHaveLength(100)
			expect(name.endsWith('😀')).toBe(true)
		})

		it('shows the server\'s reason for a value', async () => {
			window.OC = { config: { version: '35.0.0.10' } }
			const wrapper = await picked()
			vi.spyOn(axios, 'post').mockRejectedValue({ response: { data: { fields: { value: 'Choose a value.' } } } })

			await wrapper.vm.saveAsFolder()
			await wrapper.vm.$nextTick()

			expect(wrapper.text()).toContain('Choose a value.')
		})

		it('says why when it can not be saved', async () => {
			window.OC = { config: { version: '35.0.0.10' } }
			const wrapper = await picked()
			vi.spyOn(axios, 'post').mockRejectedValue({ response: { data: { fields: { searches: 'You can save at most 50 searches.' } } } })

			await wrapper.vm.saveAsFolder()
			await wrapper.vm.$nextTick()

			expect(wrapper.text()).toContain('You can save at most 50 searches.')
			expect(wrapper.vm.saving).toBe(false)
		})
	})
})
