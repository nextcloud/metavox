import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { accountOptions, includesTime, optionItems, optionLabel, optionValues } from '../../src/utils/fieldOptions.js'

// The same cases as tests/unit/Validation/FieldOptionsTest.php: browser and server read options alike.
const fixture = JSON.parse(readFileSync('tests/fixtures/field-options.json', 'utf8'))

describe('options in every shape read as one list', () => {
	it.each(fixture.items.map((c) => [c.name, c]))('%s', (name, c) => {
		expect(optionItems(c.options)).toEqual(c.items)
		expect(optionValues(c.options)).toEqual(c.items.map((item) => item.value))
	})
})

describe('whether a date field holds a time', () => {
	it.each(fixture.includeTime.map((c) => [c.name, c]))('%s', (name, c) => {
		expect(includesTime(c.options)).toBe(c.includeTime)
	})
})

describe('what a People field takes', () => {
	it.each(fixture.accounts.map((c) => [c.name, c]))('%s', (name, c) => {
		expect(accountOptions(c.options)).toEqual(c.accounts)
	})
})

describe('the label of a stored value', () => {
	const options = [{ value: 'nl', label: 'Nederland' }, 'Belgie']

	it('is the label of its option', () => {
		expect(optionLabel(options, 'nl')).toBe('Nederland')
		expect(optionLabel(options, 'Belgie')).toBe('Belgie')
	})

	it('is the value itself when no option has it', () => {
		expect(optionLabel(options, 'de')).toBe('de')
		expect(optionLabel(null, 'x')).toBe('x')
		expect(optionLabel(options, null)).toBe('')
	})

	it('reads options stored as text too', () => {
		expect(optionLabel('[{"value":"nl","label":"Nederland"}]', 'nl')).toBe('Nederland')
	})
})
