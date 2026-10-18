import { describe, it, expect, vi } from 'vitest'
import folderListMixin from '@/components/mixins/folderListMixin.js'

/**
 * Clicking a folder row.
 *
 * This list and the metadata fields list are both NcListItem and look
 * identical, but only the fields one answered a click: the rows were
 * converted to NcListItem without carrying the click handler over, so two
 * lists that read the same behaved differently.
 *
 * openGroupfolder is tested directly rather than through a mount. The
 * hosts fetch groupfolders, fields, assignments and metadata on created(),
 * and what is worth pinning here is the routing decision, not that
 * sequence — a mount would mostly assert the mocks.
 *
 * It lives on folderListMixin, which both pages use, so this is tested
 * once rather than per host.
 */
describe('folderListMixin row click', () => {
	const { openGroupfolder } = folderListMixin.methods

	/** A component stand-in holding just what openGroupfolder reads. */
	const ctx = (assignedCount) => ({
		getAssignedGroupfolderFieldsCount: () => assignedCount,
		editGroupfolderMetadata: vi.fn(),
		toggleFieldsConfiguration: vi.fn(),
	})

	const FOLDER = { id: 7, mount_point: 'IntraVox' }

	/** A click whose target is not inside any action menu. */
	const rowClick = () => ({ target: { closest: () => null } })

	it('opens the metadata editor when the folder has fields', () => {
		const self = ctx(8)
		openGroupfolder.call(self, FOLDER, rowClick())

		expect(self.editGroupfolderMetadata).toHaveBeenCalledWith(FOLDER)
		expect(self.toggleFieldsConfiguration).not.toHaveBeenCalled()
	})

	/**
	 * The everyday action is editing metadata, but it needs fields to edit —
	 * the menu disables it at zero. So the row opens the configuration that
	 * gets the folder there instead, rather than doing nothing.
	 */
	it('opens field configuration when the folder has no fields', () => {
		const self = ctx(0)
		openGroupfolder.call(self, FOLDER, rowClick())

		expect(self.toggleFieldsConfiguration).toHaveBeenCalledWith(FOLDER.id)
		expect(self.editGroupfolderMetadata).not.toHaveBeenCalled()
	})

	/**
	 * The row must never open a dialog the menu refuses. Both read the same
	 * count through the same method, so this pins that they cannot drift:
	 * whenever Edit metadata is disabled, the row opens configuration.
	 */
	it('never opens the metadata editor when the menu disables it', () => {
		const self = ctx(0)
		openGroupfolder.call(self, FOLDER, rowClick())

		expect(self.editGroupfolderMetadata).not.toHaveBeenCalled()
	})

	/**
	 * NcListItem emits `click` for every click inside the row and filters
	 * nothing — its onClick emits first and only then checks modifier keys.
	 * Without the guard, opening the `...` menu also fires this handler and a
	 * dialog appears behind the open menu.
	 */
	it.each([
		['the actions trigger', 'action-item'],
		['an item inside the open menu', 'role="menu"'],
	])('ignores a click on %s', (_label, ancestor) => {
		const self = ctx(8)
		// The handler passes one comma-joined selector, the way Element.closest
		// is actually called; a match on any branch of it means the click came
		// from the menu.
		openGroupfolder.call(self, FOLDER, {
			target: { closest: (sel) => (sel.includes(ancestor) ? {} : null) },
		})

		expect(self.editGroupfolderMetadata).not.toHaveBeenCalled()
		expect(self.toggleFieldsConfiguration).not.toHaveBeenCalled()
	})

	/** A keyboard-driven click can arrive with no target at all. */
	it('survives an event without a target', () => {
		const self = ctx(8)
		expect(() => openGroupfolder.call(self, FOLDER, undefined)).not.toThrow()
		expect(self.editGroupfolderMetadata).toHaveBeenCalledWith(FOLDER)
	})
})

/** The metadata dialog of a team folder: required is required, refusals go under their field. */
describe('folderListMixin metadata dialog', () => {
	const { checkRequiredMetadata, showMetadataRefusal, setMetadataValues } = folderListMixin.methods
	const FIELDS = [
		{ field_name: 'gf_owner', field_type: 'text', is_required: true },
		{ field_name: 'gf_note', field_type: 'text', is_required: false },
	]
	const host = (values) => ({ currentMetadataFields: FIELDS, currentMetadataValues: values, metadataErrors: {} })

	it('holds back a save while a required field is empty', () => {
		const self = host({ gf_owner: ' ', gf_note: 'x' })
		expect(checkRequiredMetadata.call(self)).toBe(false)
		expect(self.metadataErrors).toEqual({ gf_owner: 'This field is required' })

		self.currentMetadataValues = { gf_owner: 'Sam' }
		expect(checkRequiredMetadata.call(self)).toBe(true)
		expect(self.metadataErrors).toEqual({})
	})

	it('takes the message away from a field that changes', () => {
		const self = { ...host({ gf_owner: '' }), metadataErrors: { gf_owner: 'This field is required' } }
		setMetadataValues.call(self, { gf_owner: 'Sam' })
		expect(self.metadataErrors).toEqual({})
		expect(self.currentMetadataValues).toEqual({ gf_owner: 'Sam' })
	})

	it('puts a refusal of the server under its field, but not one about something else', () => {
		const self = host({})
		const refusal = (fields) => ({ response: { status: 400, data: { fields } } })
		expect(showMetadataRefusal.call(self, refusal({ gf_owner: 'Choose an existing account' }))).toBe(true)
		expect(self.metadataErrors).toEqual({ gf_owner: 'Choose an existing account' })
		expect(showMetadataRefusal.call(self, refusal({ metadata: 'Send each field name with its value' }))).toBe(false)
		expect(showMetadataRefusal.call(self, { response: { status: 500, data: {} } })).toBe(false)
	})
})
