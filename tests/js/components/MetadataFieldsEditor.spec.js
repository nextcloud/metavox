import { describe, it, expect, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import MetadataFieldsEditor from '@/components/MetadataFieldsEditor.vue'
import axios from '@nextcloud/axios'
import { readFileSync } from 'node:fs'

/**
 * MetadataFieldsEditor manages both sets of field definitions. They live in one
 * table and are told apart by applies_to_groupfolder — 0 for a field that
 * describes a file, 1 for one that describes a team folder — so the component
 * takes a `scope` prop rather than existing twice.
 *
 * These tests pin the scope-dependent behaviour, because it used to be two
 * components that had drifted apart: the file one stored a name as
 * `file_gf_<name>` but stripped only `file_` for display, so a field created as
 * "author" was shown back as "gf_author".
 */
describe('MetadataFieldsEditor', () => {
	afterEach(() => vi.restoreAllMocks())

	const FIELDS = [
		{ id: 1, field_name: 'gf_department', field_label: 'Department', field_type: 'text', applies_to_groupfolder: 1 },
		{ id: 2, field_name: 'gf_priority', field_label: 'Priority', field_type: 'select', applies_to_groupfolder: '1' },
		{ id: 3, field_name: 'file_gf_author', field_label: 'Author', field_type: 'text', applies_to_groupfolder: 0 },
		{ id: 4, field_name: 'file_gf_status', field_label: 'Status', field_type: 'text', applies_to_groupfolder: '0' },
		// A file field can arrive with the column absent or null, which means 0.
		{ id: 5, field_name: 'file_gf_legacy', field_label: 'Legacy', field_type: 'text' },
		{ id: 6, field_name: 'file_gf_nulled', field_label: 'Nulled', field_type: 'text', applies_to_groupfolder: null },
	]

	const mountScope = async (scope, fields = FIELDS) => {
		vi.spyOn(axios, 'get').mockImplementation((url) => {
			if (url.includes('groupfolder-fields')) return Promise.resolve({ data: fields })
			return Promise.resolve({ data: [] })
		})
		const wrapper = mount(MetadataFieldsEditor, { props: { scope } })
		await flushPromises()
		return wrapper
	}

	describe('scope', () => {
		it('rejects a scope it does not know', () => {
			const { validator } = MetadataFieldsEditor.props.scope
			expect(validator('file')).toBe(true)
			expect(validator('teamfolder')).toBe(true)
			expect(validator('groupfolder')).toBe(false)
			expect(validator('')).toBe(false)
		})

		it('derives the prefix and the column value from the scope', async () => {
			const file = await mountScope('file')
			expect(file.vm.namePrefix).toBe('file_gf_')
			expect(file.vm.appliesToGroupfolder).toBe(0)

			const team = await mountScope('teamfolder')
			expect(team.vm.namePrefix).toBe('gf_')
			expect(team.vm.appliesToGroupfolder).toBe(1)
		})

		it('uses the prefixes the backend strips', async () => {
			// ApiFieldController strips /^(file_gf_|gf_)/ before validating, so
			// these two strings are the contract between the two ends.
			const backendStrips = /^(file_gf_|gf_)/
			for (const scope of ['file', 'teamfolder']) {
				const w = await mountScope(scope)
				expect(backendStrips.test(w.vm.namePrefix + 'x')).toBe(true)
			}
		})
	})

	describe('loading the right set', () => {
		it('shows only team folder fields when scoped to teamfolder', async () => {
			const w = await mountScope('teamfolder')
			expect(w.vm.fields.map((f) => f.field_name)).toEqual(['gf_department', 'gf_priority'])
		})

		it('shows only file fields when scoped to file', async () => {
			const w = await mountScope('file')
			expect(w.vm.fields.map((f) => f.field_name))
				.toEqual(['file_gf_author', 'file_gf_status', 'file_gf_legacy', 'file_gf_nulled'])
		})

		it('treats the column as 0 when it is absent, null or empty', async () => {
			const w = await mountScope('file', [
				{ id: 1, field_name: 'file_gf_a', field_label: 'A', field_type: 'text' },
				{ id: 2, field_name: 'file_gf_b', field_label: 'B', field_type: 'text', applies_to_groupfolder: null },
				{ id: 3, field_name: 'file_gf_c', field_label: 'C', field_type: 'text', applies_to_groupfolder: '' },
			])
			expect(w.vm.fields).toHaveLength(3)
		})

		it('reads the column whether it arrives as an int or a string', async () => {
			const w = await mountScope('teamfolder', [
				{ id: 1, field_name: 'gf_int', field_label: 'Int', field_type: 'text', applies_to_groupfolder: 1 },
				{ id: 2, field_name: 'gf_str', field_label: 'Str', field_type: 'text', applies_to_groupfolder: '1' },
			])
			expect(w.vm.fields).toHaveLength(2)
		})
	})

	describe('the display name', () => {
		it('shows a name without its scope prefix', async () => {
			const file = await mountScope('file')
			expect(file.vm.getDisplayName({ field_name: 'file_gf_author' })).toBe('author')

			const team = await mountScope('teamfolder')
			expect(team.vm.getDisplayName({ field_name: 'gf_department' })).toBe('department')
		})

		it('round-trips: what is typed is what is shown back', async () => {
			// The regression this pins: the file editor added file_gf_ and removed
			// file_, so "author" came back as "gf_author".
			for (const [scope, typed] of [['file', 'author'], ['teamfolder', 'department']]) {
				const w = await mountScope(scope)
				const stored = typed.startsWith(w.vm.namePrefix) ? typed : w.vm.namePrefix + typed
				expect(w.vm.getDisplayName({ field_name: stored })).toBe(typed)
			}
		})

		it('leaves a name alone when it does not carry this scope prefix', async () => {
			const w = await mountScope('file')
			expect(w.vm.getDisplayName({ field_name: 'gf_department' })).toBe('gf_department')
		})
	})

	describe('the icon for a field type', () => {
		it('maps every type the picker offers', async () => {
			// An unmapped type falls back to the text icon, so two different
			// types render identically in the list. filelink used to be mapped
			// in only one of the two editors, and url and user in neither.
			const w = await mountScope('file')
			const textTypes = ['text', 'textarea']
			for (const { id } of w.vm.selectOptions) {
				const icon = w.vm.getFieldIcon(id)
				if (!textTypes.includes(id)) {
					expect(icon, `${id} has no icon of its own`).not.toBe('TextIcon')
				}
			}
		})

		it('maps the File link type, which one editor used to miss', async () => {
			const w = await mountScope('teamfolder')
			expect(w.vm.getFieldIcon('filelink')).toBe('FileIcon')
		})

		it('falls back to a text icon for a type it does not know', async () => {
			const w = await mountScope('file')
			expect(w.vm.getFieldIcon('something-else')).toBe('TextIcon')
		})
	})

	describe('bulk selection', () => {
		it('offers no checkboxes until the usage counts are in', async () => {
			// Before the counts land the app cannot say which fields are
			// deletable. A checkbox that might be refused on submit is the
			// guessing this feature exists to remove.
			const w = await mountScope('teamfolder')
			w.vm.usageCountsCache = null
			expect(w.vm.canSelect).toBe(false)
			w.vm.usageCountsCache = new Map()
			expect(w.vm.canSelect).toBe(true)
		})

		it('select-all takes only the deletable rows on screen', async () => {
			// At 48 fields with 36 in use, selecting "all" would otherwise be
			// three-quarters no-op and the counter would overstate it.
			const w = await mountScope('teamfolder')
			w.vm.usageCountsCache = new Map()
			w.vm.fields = [
				{ ...FIELDS[0], id: 1, usage_count: 0 },
				{ ...FIELDS[1], id: 2, usage_count: 3 },
				{ ...FIELDS[0], id: 3, usage_count: 0 },
			]
			await w.vm.$nextTick()
			expect(w.vm.filteredSelectableIds).toEqual([1, 3])
			w.vm.toggleSelectAll()
			expect(w.vm.selected).toEqual([1, 3])
			expect(w.vm.allSelectableSelected).toBe(true)
			w.vm.toggleSelectAll()
			expect(w.vm.selected).toEqual([])
		})

		it('selecting does not open the edit dialog', async () => {
			// NcCheckboxRadioSwitch renders a <label> around an opacity-0
			// input, so the toggling click starts on the label and bubbles to
			// NcListItem's row handler. @click.stop on the component root
			// never saw it, and ticking a box opened Edit field instead.
			const w = await mountScope('teamfolder')
			w.vm.usageCountsCache = new Map()
			const fakeEvent = { target: { closest: (sel) => sel === '.field-select' ? {} : null } }
			w.vm.editField(FIELDS[0], fakeEvent)
			expect(w.vm.editingField).toBe(null)
		})

		it('still opens the dialog when the row itself is clicked', async () => {
			const w = await mountScope('teamfolder')
			w.vm.usageCountsCache = new Map()
			const fakeEvent = { target: { closest: () => null } }
			w.vm.editField(FIELDS[0], fakeEvent)
			expect(w.vm.editingField).toStrictEqual(FIELDS[0])
		})

		it('behaves the same on both scopes, because it is one component', async () => {
			// Team folders and Files are the same MetadataFieldsEditor with a
			// different scope prop (MetaVoxAdmin.vue:40 and :46). Anything
			// added for one is present on the other by construction; this
			// pins that, so a later split cannot quietly reintroduce two
			// copies of the selection code.
			for (const scope of ['teamfolder', 'file']) {
				const w = await mountScope(scope)
				w.vm.usageCountsCache = new Map()
				expect(w.vm.canSelect, scope).toBe(true)
				expect(typeof w.vm.toggleSelectAll, scope).toBe('function')
				expect(typeof w.vm.confirmBulkDelete, scope).toBe('function')
				expect(w.vm.selected, scope).toEqual([])
			}
		})

		it('says why a row cannot be picked, not just that it cannot', async () => {
			const w = await mountScope('teamfolder')
			w.vm.usageCountsCache = new Map()
			const free = { ...FIELDS[0], usage_count: 0 }
			const used = { ...FIELDS[1], usage_count: 2 }
			expect(w.vm.selectionLabel(free)).toMatch(/Select/)
			expect(w.vm.selectionLabel(used)).toMatch(/cannot be deleted/)
		})

		it('does not let a selection survive a scope change', async () => {
			// Ids are per scope; carrying them across would delete whatever
			// happened to share an id in the other list.
			const w = await mountScope('teamfolder')
			w.vm.usageCountsCache = new Map()
			w.vm.selected = [1, 2]
			await w.setProps({ scope: 'file' })
			await flushPromises()
			expect(w.vm.selected).toEqual([])
		})
	})

	describe('the subline', () => {
		it('states assignment either way once the counts are in', async () => {
			// "Deletable" used to be signalled by the absence of "in N team
			// folders", readable only by comparing a row against its
			// neighbours across 48 of them.
			// getUsageCount reads field.usage_count; the cache only gates
			// whether the answer is known yet.
			const w = await mountScope('teamfolder')
			w.vm.usageCountsCache = new Map()
			expect(w.vm.fieldSubline({ ...FIELDS[0], usage_count: 2 }))
				.toMatch(/in .*Team folder/)
			expect(w.vm.fieldSubline({ ...FIELDS[1], usage_count: 0 }))
				.toMatch(/not assigned/)
		})

		it('says neither while the counts are still loading', async () => {
			// The count arrives from a background call. Before it lands the
			// answer is unknown, and claiming "not assigned" would be a guess
			// that reads as fact — the same trap in the other direction.
			const w = await mountScope('teamfolder')
			w.vm.usageCountsCache = null
			const line = w.vm.fieldSubline(FIELDS[0])
			expect(line).not.toMatch(/not assigned/)
			expect(line).not.toMatch(/in .*Team folder/)
		})
	})

	describe('the edit dialog', () => {
		it('closes the row menu when it opens', async () => {
			// NcListItem owns its NcActions and forwards only `forceMenu`, not
			// `open`, so the menu cannot be closed through a prop. Opening a
			// dialog from an action click leaves the popper shown: the dialog
			// takes focus, so the click-outside and Escape that would dismiss
			// it never arrive. Clicking the expanded trigger is the one thing
			// that works, and it has to happen after the click settles —
			// before that, aria-expanded is still false and there is nothing
			// to find.
			const w = await mountScope('teamfolder')

			const trigger = document.createElement('button')
			trigger.setAttribute('aria-haspopup', 'menu')
			trigger.setAttribute('aria-expanded', 'true')
			let clicked = 0
			trigger.addEventListener('click', () => { clicked++ })
			document.body.appendChild(trigger)

			w.vm.editField(FIELDS[0])
			expect(w.vm.editingField).toStrictEqual(FIELDS[0])
			expect(clicked, 'must not fire before the click settles').toBe(0)

			await w.vm.$nextTick()
			expect(clicked, 'the expanded trigger is toggled shut').toBe(1)

			trigger.remove()
		})

		it('leaves a collapsed menu alone', async () => {
			const w = await mountScope('teamfolder')
			const trigger = document.createElement('button')
			trigger.setAttribute('aria-haspopup', 'menu')
			trigger.setAttribute('aria-expanded', 'false')
			let clicked = 0
			trigger.addEventListener('click', () => { clicked++ })
			document.body.appendChild(trigger)

			w.vm.editField(FIELDS[0])
			await w.vm.$nextTick()
			expect(clicked).toBe(0)
			trigger.remove()
		})

		it('shows a fixed internal name as text, not as a field', async () => {
			// As a readonly NcTextField it kept a white background, a dark
			// border and a text cursor, so it looked editable and said nothing
			// until you typed. `disabled` is not the alternative: core paints
			// that at 2.91:1, under the 4.5 floor.
			const w = await mountScope('teamfolder')
			expect(w.vm.$options.__file || '').toBeDefined()
			const src = readFileSync('src/components/MetadataFieldsEditor.vue', 'utf8')
			expect(src).toMatch(/form-row--static/)
			// The input variant must not carry :readonly any more.
			expect(src).not.toMatch(/:readonly="getUsageCount/)
		})

		it('never makes a checkbox required, whatever flag it still carries', async () => {
			// A checkbox always has an answer, ticked or not: no switch, no
			// badge, and saving it clears a flag 2.x left on it.
			const box = { id: 7, field_name: 'file_gf_pii', field_label: 'Personal data', field_type: 'checkbox', is_required: 1, applies_to_groupfolder: 0 }
			const w = await mountScope('file', [...FIELDS, box])
			expect(w.vm.canBeRequired('checkbox')).toBe(false)
			expect(w.vm.canBeRequired('select')).toBe(true)
			expect(w.vm.showsRequired(box)).toBe(false)
			expect(w.vm.showsRequired({ ...FIELDS[2], is_required: 1 })).toBe(true)

			const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: { success: true } })
			w.vm.editField(box)
			await w.vm.$nextTick()
			expect(w.vm.editData.required).toBe(true)
			await w.vm.saveEdit()
			expect(put).toHaveBeenCalledTimes(1)
			expect(put.mock.calls[0][1].is_required).toBe(0)
		})

		it('does not wrap the option rows in a sunken well', async () => {
			// --color-background-darker is the heaviest grey in the palette and
			// is for a sunken area, not a run of ordinary fields. Repeated
			// fields group by whitespace (§6).
			const src = readFileSync('src/components/MetadataFieldsEditor.vue', 'utf8')
			const block = src.slice(src.indexOf('.options-container {'))
			const rules = block.slice(0, block.indexOf('}'))
			expect(rules).not.toMatch(/--color-background-darker/)
			expect(rules).toMatch(/gap:/)
		})
	})

	describe('the export', () => {
		it('names the file after the scope', async () => {
			const file = await mountScope('file')
			expect(file.vm.exportFilename).toMatch(/^file-metadata-fields-\d{4}-\d{2}-\d{2}\.json$/)

			const team = await mountScope('teamfolder')
			expect(team.vm.exportFilename).toMatch(/^teamfolder-metadata-fields-\d{4}-\d{2}-\d{2}\.json$/)
		})
	})

	describe('the dialog footer', () => {
		it('puts the primary action last, which is where the platform puts it', async () => {
			const w = await mountScope('file')
			const labels = w.vm.editDialogButtons.map((b) => b.label)
			expect(labels).toHaveLength(2)
			expect(w.vm.editDialogButtons[1].variant).toBe('primary')
			expect(w.vm.editDialogButtons[0].variant).toBeUndefined()
			expect(labels[0]).toBe('Cancel')
		})

		it('keeps the dialog open when the primary action runs', async () => {
			// A callback returning false is how NcDialog is told not to close, so
			// a save that fails validation does not throw the form away.
			const w = await mountScope('file')
			const primary = w.vm.editDialogButtons[1]
			vi.spyOn(w.vm, 'saveEdit').mockImplementation(() => {})
			expect(primary.callback()).toBe(false)
		})

		it('disables the primary action while saving', async () => {
			const w = await mountScope('file')
			await w.setData({ saving: true })
			expect(w.vm.editDialogButtons[1].disabled).toBe(true)
			expect(w.vm.editDialogButtons[1].label).toContain('…')
		})

		it('orders the import footer the same way', async () => {
			const w = await mountScope('file')
			const buttons = w.vm.importDialogButtons
			expect(buttons[0].label).toBe('Cancel')
			expect(buttons[1].variant).toBe('primary')
		})
	})

	describe('editing the options of a field', () => {
		const LABELLED = [{ id: 9, field_name: 'file_gf_land', field_label: 'Land', field_type: 'select', applies_to_groupfolder: 0,
			field_options: [{ value: 'nl', label: 'Nederland' }, { value: 'Belgie', label: 'Belgie' }] }]

		it('keeps a label set through the API, and sends a plain option as its value', async () => {
			const w = await mountScope('file', LABELLED)
			const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: { success: true } })
			w.vm.editField(LABELLED[0])
			w.vm.editData.options.push({ value: 'Duitsland' })
			await w.vm.saveEdit()

			expect(put.mock.calls[0][1].field_options).toEqual([{ value: 'nl', label: 'Nederland' }, 'Belgie', 'Duitsland'])
		})

		it('keeps an old field name without its prefix as it is', async () => {
			const old = [{ ...LABELLED[0], field_name: 'land' }]
			const w = await mountScope('file', old)
			const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: { success: true } })
			w.vm.editField(old[0])
			await w.vm.saveEdit()

			expect(put.mock.calls[0][1].field_name).toBe('land')
		})

		it('shows what the server refused under the input it is about', async () => {
			const w = await mountScope('file', LABELLED)
			vi.spyOn(axios, 'put').mockRejectedValue({ response: { status: 400, data: { reason: 'invalid_input', fields: { field_options: 'An option cannot contain ;#' } } } })
			w.vm.editField(LABELLED[0])
			await w.vm.saveEdit()

			expect(w.vm.serverErrors).toEqual({ field_options: 'An option cannot contain ;#' })
		})
	})

	describe('the placeholders and the empty state', () => {
		it('offers examples that suit the scope', async () => {
			const file = await mountScope('file')
			expect(file.vm.namePlaceholder).toContain('author')
			expect(file.vm.emptyHint).toContain('files')

			const team = await mountScope('teamfolder')
			expect(team.vm.namePlaceholder).toContain('department')
			expect(team.vm.emptyHint).toContain('Team folders')
		})
	})
})
