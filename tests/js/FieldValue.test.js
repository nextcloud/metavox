import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	getCanonicalLocale: () => 'nl-NL',
}))

const { formatFieldValue, isChecked, isUnchecked, splitMulti } = await import('../../src/utils/fieldValue.js')
const { rememberAccountNames } = await import('../../src/utils/accountNames.js')

describe('a stored value as the user knows it', () => {
	it('reads a checkbox the same in every form it arrives in', () => {
		for (const yes of ['1', 'true', true, 1]) expect(formatFieldValue(yes, 'checkbox')).toBe('Yes')
		for (const no of ['0', 'false', false, 0]) expect(formatFieldValue(no, 'checkbox')).toBe('No')
	})

	it('lists the choices of a multiple choice value', () => {
		expect(formatFieldValue('Rood;#Groen;#', 'multiselect')).toBe('Rood, Groen')
		expect(splitMulti(' a ;#;#b')).toEqual(['a', 'b'])
	})

	it('shows a date in the Nextcloud language, not the browser one', () => {
		expect(formatFieldValue('2026-01-14', 'date')).toBe('14-1-2026')
	})

	it('keeps the stored day west of UTC', () => {
		const zone = process.env.TZ
		process.env.TZ = 'America/New_York'
		try {
			expect(formatFieldValue('2026-01-14', 'date')).toBe('14-1-2026')
		} finally {
			if (zone === undefined) delete process.env.TZ
			else process.env.TZ = zone
		}
	})

	it('shows people by name, the id when the name is missing', () => {
		rememberAccountNames([{ id: 'admin', name: 'Administrator' }, { id: 'group:staff', name: 'Staff' }, { id: 'gone', name: null }])

		expect(formatFieldValue('admin', 'user')).toBe('Administrator')
		expect(formatFieldValue('admin;#group:staff', 'user')).toBe('Administrator, Staff')
		// Gone, or a name the server did not send: an account or group by its id,
		// a team as unavailable.
		expect(formatFieldValue('gone;#group:other', 'user')).toBe('gone, other')
		expect(formatFieldValue('team:Ab12', 'user')).toBe('Unavailable team')
	})

	it('has nothing to show for no value', () => {
		for (const empty of [null, undefined, '']) expect(formatFieldValue(empty, 'text')).toBe('')
	})

	it('knows a ticked and an unticked checkbox in every form', () => {
		for (const yes of ['1', 'true', true, 1]) expect(isChecked(yes)).toBe(true)
		for (const no of ['0', 'false', false, 0]) expect(isUnchecked(no)).toBe(true)
		// Never set is neither: rules decide what that means.
		for (const unset of ['', null, undefined]) {
			expect(isChecked(unset)).toBe(false)
			expect(isUnchecked(unset)).toBe(false)
		}
	})
})
