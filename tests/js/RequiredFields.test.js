import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	getCanonicalLocale: () => 'nl-NL',
}))

const { canBeRequired, isRequired, missingReason, missingRequired } = await import('../../src/utils/requiredFields.js')

const status = { field_name: 'file_gf_status', field_type: 'select', is_required: true }
const approved = { field_name: 'file_gf_ok', field_type: 'checkbox', is_required: true }
const notes = { field_name: 'file_gf_notes', field_type: 'text', is_required: false }

describe('a required field, as the server sees it', () => {
	it('needs a value that is more than spaces', () => {
		for (const empty of ['', '  ', null, undefined]) expect(missingReason(status, empty)).toBe('This field is required')
		expect(missingReason(status, 'Open')).toBe('')
		expect(missingReason(status, 0)).toBe('')
	})

	it('never holds a checkbox to it, ticked or not', () => {
		for (const type of ['checkbox', 'boolean']) expect(canBeRequired(type)).toBe(false)
		for (const type of ['text', 'select', 'multiselect', 'date', 'user', undefined]) expect(canBeRequired(type)).toBe(true)
		expect(isRequired(approved)).toBe(false)
		expect(isRequired({ ...approved, field_type: 'boolean' })).toBe(false)
		for (const value of ['0', '', null, false, '1', true]) expect(missingReason(approved, value)).toBe('')
	})

	it('asks nothing of a field that is not required', () => {
		expect(missingReason(notes, '')).toBe('')
		expect(missingReason({ ...status, is_required: '0' }, '')).toBe('')
		expect(isRequired({ is_required: 1 })).toBe(true)
		expect(isRequired({ is_required: false })).toBe(false)
	})

	it('lists every required field that is missing', () => {
		expect(missingRequired([status, approved, notes], { file_gf_status: '', file_gf_ok: '1' }))
			.toEqual({ file_gf_status: 'This field is required' })
		expect(missingRequired([status, approved], { file_gf_status: 'Open', file_gf_ok: '1' })).toEqual({})
	})
})
