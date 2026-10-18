import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	getCanonicalLocale: () => 'nl-NL',
}))

const { formatValue } = await import('../../src/filesplugin/columns/ColumnUtils.js')

describe('a value in a grid cell', () => {
	it('shows a date the way the sidebar and the chips show it', () => {
		expect(formatValue('2026-01-14', 'date')).toBe('14-1-2026')
	})

	it('keeps the stored day west of UTC', () => {
		const zone = process.env.TZ
		process.env.TZ = 'America/New_York'
		try {
			expect(formatValue('2026-01-14', 'date')).toBe('14-1-2026')
		} finally {
			if (zone === undefined) delete process.env.TZ
			else process.env.TZ = zone
		}
	})

	it('keeps the tick for a checkbox', () => {
		expect(formatValue('1', 'checkbox')).toBe('✓')
		expect(formatValue('0', 'checkbox')).toBe('')
	})

	it('ticks a checkbox stored as the number 1, as the sidebar does', () => {
		expect(formatValue(1, 'checkbox')).toBe('\u2713')
	})

	it('lists a multiple choice of either type name', () => {
		expect(formatValue('Rood;#Groen', 'multi_select')).toBe('Rood, Groen')
	})

	it('shows the label of an option, and a value no option has as it is', () => {
		const field = { field_options: [{ value: 'nl', label: 'Nederland' }, 'Belgie'] }
		expect(formatValue('nl', 'select', field)).toBe('Nederland')
		expect(formatValue('nl;#Belgie;#de', 'multiselect', field)).toBe('Nederland, Belgie, de')
	})
})
