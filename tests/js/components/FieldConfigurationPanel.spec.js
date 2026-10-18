import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import FieldConfigurationPanel from '@/components/FieldConfigurationPanel.vue'

/**
 * The field-configuration panel, shared by the admin and personal pages.
 *
 * It was the same panel twice — 74% of the lines identical, the real
 * differences being two comments and one string. These pin the behaviour
 * that differs by caller, so a future change to one page cannot silently
 * become a change to both, and the parts that must stay identical.
 */
describe('FieldConfigurationPanel', () => {
	const FOLDER_FIELDS = [
		{ id: 1, field_label: 'Project', field_name: 'gf_project', field_type: 'text' },
		{ id: 2, field_label: 'Confidentiality', field_name: 'gf_conf', field_type: 'select' },
	]
	const FILE_FIELDS = [
		{ id: 10, field_label: 'Author', field_name: 'file_author', field_type: 'text' },
	]

	const mountPanel = (props = {}) => mount(FieldConfigurationPanel, {
		props: {
			folderId: 1,
			folderFields: FOLDER_FIELDS,
			fileFields: FILE_FIELDS,
			assignedIds: [],
			emptyText: 'Nothing here',
			...props,
		},
	})

	it('shows the empty text the caller supplies', () => {
		// Admin and user are told different things: create some, or ask an admin.
		const wrapper = mountPanel({ folderFields: [], fileFields: [], emptyText: 'Ask your administrator' })
		expect(wrapper.text()).toContain('Ask your administrator')
	})

	/**
	 * Rewriting values on existing files is an admin action, so the whole
	 * block is opt-in rather than rendered-and-disabled.
	 */
	it('hides Apply defaults unless the caller allows it', () => {
		expect(mountPanel({ assignedIds: [10] }).find('.apply-defaults-section').exists()).toBe(false)
		expect(mountPanel({ assignedIds: [10], canApplyDefaults: true }).find('.apply-defaults-section').exists()).toBe(true)
	})

	/**
	 * The caller owns the assignment, so the panel never mutates the prop.
	 * Driven through setAssigned rather than a rendered checkbox: the
	 * library components are stubbed in this suite, so a stub carries no
	 * NcCheckboxRadioSwitch name to find.
	 */
	it('emits the new id list instead of mutating the prop', () => {
		const assignedIds = [1]
		const wrapper = mountPanel({ assignedIds })

		wrapper.vm.setAssigned(2, true)

		expect(wrapper.emitted('update:assignedIds')[0][0]).toEqual([1, 2])
		expect(assignedIds).toEqual([1])
	})

	it('emits removal when a field is unticked', () => {
		const wrapper = mountPanel({ assignedIds: [1, 2] })

		wrapper.vm.setAssigned(1, false)

		expect(wrapper.emitted('update:assignedIds')[0][0]).toEqual([2])
	})

	/**
	 * Search matches the internal name and the type as well as the label —
	 * the same rule the fields list uses, which is why "tr" finds gf_trust.
	 */
	it.each([
		['label', 'project', [1]],
		['internal name', 'gf_conf', [2]],
		['type', 'select', [2]],
	])('matches on %s', async (_what, query, expectedIds) => {
		const wrapper = mountPanel()
		await wrapper.setData({ search: query })

		expect(wrapper.vm.visibleFolderFields.map(f => f.id)).toEqual(expectedIds)
	})

	it('narrows to one group when the type filter is set', async () => {
		const wrapper = mountPanel()

		await wrapper.setData({ typeFilter: 'file' })
		expect(wrapper.vm.visibleFolderFields).toEqual([])
		expect(wrapper.vm.visibleFileFields).toHaveLength(1)

		await wrapper.setData({ typeFilter: 'groupfolder' })
		expect(wrapper.vm.visibleFolderFields).toHaveLength(2)
		expect(wrapper.vm.visibleFileFields).toEqual([])
	})

	/** Both hosts render the same two groups, named the same way. */
	it('names both groups of fields', () => {
		const text = mountPanel().text()
		expect(text).toContain('Team folder metadata fields')
		expect(text).toContain('File metadata fields')
	})
})
