import { afterEach, describe, expect, it } from 'vitest'
import { _setClock, addDays, datePart, isDay, todayKey } from '../../src/utils/dateField.js'

const zone = process.env.TZ
afterEach(() => {
	_setClock(null)
	if (zone === undefined) delete process.env.TZ
	else process.env.TZ = zone
})

describe('the day of a stored date', () => {
	it('reads the day of a date, with or without a time', () => {
		expect(datePart('2026-01-14')).toBe('2026-01-14')
		expect(datePart('2026-01-14T23:30:00')).toBe('2026-01-14')
		expect(datePart('2026-01-14T08:00')).toBe('2026-01-14')
		expect(datePart('2026-01-14 08:00:00')).toBe('2026-01-14')
	})

	it('is a day only when written as exactly a real day', () => {
		expect(isDay('2026-01-14')).toBe(true)
		expect(isDay('0099-03-01')).toBe(true)
		for (const value of ['2026-02-30', '2026-01-14T08:00', ' 2026-01-14', '2026-1-14', null, 20260114]) expect(isDay(value)).toBe(false)
	})

	it('keeps years below 100 as they are', () => {
		expect(datePart('0099-03-01')).toBe('0099-03-01')
		expect(datePart('0004-02-29')).toBe('0004-02-29')
		expect(datePart('0099-02-29')).toBeNull()
	})

	it('has no day for what is not a date', () => {
		for (const value of ['2026-02-30', '2026-13-01', '14-1-2026', 'abc', '', null, undefined, 20260114]) {
			expect(datePart(value)).toBeNull()
		}
	})
})

describe('today', () => {
	it('is the day of the browser, late in the evening too', () => {
		_setClock(() => new Date(2026, 9, 6, 23, 59))
		expect(todayKey()).toBe('2026-10-06')
	})

	it('is the local day west of UTC', () => {
		process.env.TZ = 'America/New_York'
		// 02:00 UTC on the 7th is still the evening of the 6th in New York.
		_setClock(() => new Date(Date.UTC(2026, 9, 7, 2, 0)))
		expect(todayKey()).toBe('2026-10-06')
	})
})

describe('counting days', () => {
	it('crosses months, years and leap days', () => {
		expect(addDays('2026-12-31', 1)).toBe('2027-01-01')
		expect(addDays('2026-03-01', -1)).toBe('2026-02-28')
		expect(addDays('2024-03-01', -1)).toBe('2024-02-29')
		expect(addDays('2026-10-06', 7)).toBe('2026-10-13')
		expect(addDays('0099-12-31', 1)).toBe('0100-01-01')
		expect(addDays('0100-01-01', -1)).toBe('0099-12-31')
	})

	it('loses no day over a daylight saving change', () => {
		process.env.TZ = 'Europe/Amsterdam'
		expect(addDays('2026-03-28', 1)).toBe('2026-03-29')
		expect(addDays('2026-03-29', 1)).toBe('2026-03-30')
		expect(addDays('2026-10-25', 1)).toBe('2026-10-26')
	})
})
