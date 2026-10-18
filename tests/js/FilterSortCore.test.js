import { describe, expect, it } from 'vitest'
import { applyFilterSort, compareValues, compileFilters, cutOffValues, filterValues, listedOptions, LISTED_OPTIONS, matchesFilters, missingIds, searchKey } from '../../src/filesplugin/columns/FilterSortCore.js'
import { optionValues } from '../../src/utils/fieldOptions.js'

const filtersOf = (spec) => compileFilters(new Map(Object.entries(spec).map(([field, values]) => [field, new Set(values)])))

describe('matchesFilters', () => {
	it('matches a value, AND between fields and OR within a field', () => {
		const filters = filtersOf({ status: ['Draft', 'Final'], dept: ['HR'] })

		expect(matchesFilters({ status: 'Draft', dept: 'HR' }, filters)).toBe(true)
		expect(matchesFilters({ status: 'Final', dept: 'HR' }, filters)).toBe(true)
		expect(matchesFilters({ status: 'Draft', dept: 'IT' }, filters)).toBe(false)
	})

	it('matches any item of a ;# list', () => {
		const filters = filtersOf({ tags: ['Offer'] })

		expect(matchesFilters({ tags: 'Contract;#Offer' }, filters)).toBe(true)
		expect(matchesFilters({ tags: 'Contract;# Report' }, filters)).toBe(false)
	})

	it('matches empty values only when "empty" (0) is chosen', () => {
		expect(matchesFilters({}, filtersOf({ status: ['0'] }))).toBe(true)
		expect(matchesFilters({ status: '' }, filtersOf({ status: ['0', 'Draft'] }))).toBe(true)
		expect(matchesFilters({ approved: '0' }, filtersOf({ approved: ['0'] }))).toBe(true)
		expect(matchesFilters({}, filtersOf({ status: ['Draft'] }))).toBe(false)
		expect(matchesFilters({ status: 'Draft' }, filtersOf({ status: ['0'] }))).toBe(false)
	})

	it('ignores fields without chosen values', () => {
		expect(compileFilters(new Map([['status', new Set()]]))).toEqual([])
	})
})

describe('compareValues', () => {
	const sorted = (values, type, direction) => [...values].sort((a, b) => compareValues(a, b, type, direction))

	it('compares numbers, integers and floats as numbers', () => {
		for (const type of ['number', 'integer', 'float']) {
			expect(sorted(['10', '9', '0', '-1.5'], type)).toEqual(['-1.5', '0', '9', '10'])
		}
	})

	it('compares dates and checkboxes', () => {
		expect(sorted(['2026-03-01', '2025-12-31', '2026-01-15'], 'date')).toEqual(['2025-12-31', '2026-01-15', '2026-03-01'])
		expect(sorted(['0', '1', '0'], 'checkbox')).toEqual(['1', '0', '0'])
	})

	it('compares text naturally', () => {
		expect(sorted(['beta', 'Alpha', 'gamma'], 'text')).toEqual(['Alpha', 'beta', 'gamma'])
	})

	it('puts empty values last in both directions', () => {
		expect(sorted(['2', '', undefined, '1'], 'number', 'asc')).toEqual(['1', '2', '', undefined])
		expect(sorted(['2', '', undefined, '1'], 'number', 'desc')).toEqual(['2', '1', '', undefined])
	})

	it('treats 0 as a value, not as empty', () => {
		expect(sorted(['', '0', '5'], 'number')).toEqual(['0', '5', ''])
	})

	it('falls back to text for values that are not numbers or dates', () => {
		expect(sorted(['n/a', '3'], 'number')).toEqual(['3', 'n/a'])
	})
})

describe('applyFilterSort', () => {
	const meta = { 1: { status: 'Final', budget: '300' }, 2: { status: 'Draft', budget: '100' }, 3: { status: 'Draft', budget: '200' }, 4: {} }
	const nodes = [1, 2, 3, 4].map((fileid) => ({ fileid }))
	const metaOf = (id) => meta[id]

	it('filters and sorts together', () => {
		const result = applyFilterSort(nodes, filtersOf({ status: ['Draft'] }), { fieldName: 'budget', fieldType: 'number', direction: 'desc' }, metaOf)

		expect(result.map((n) => n.fileid)).toEqual([3, 2])
	})

	it('keeps nodes without a file id and the original order without a sort', () => {
		const withParent = [{ fileid: 0 }, ...nodes]

		expect(applyFilterSort(withParent, filtersOf({ status: ['Final'] }), null, metaOf).map((n) => n.fileid)).toEqual([0, 1])
	})

	it('keeps the incoming order for ties', () => {
		const result = applyFilterSort(nodes, [], { fieldName: 'status', fieldType: 'text', direction: 'asc' }, metaOf)

		expect(result.map((n) => n.fileid)).toEqual([2, 3, 1, 4])
	})
})

describe('missingIds', () => {
	it('lists the files without values yet', () => {
		const present = new Set([1, 3])

		expect(missingIds([{ fileid: 1 }, { fileid: 2 }, { fileid: 3 }, {}], (id) => present.has(id))).toEqual([2])
	})
})

describe('cutOffValues', () => {
	it('marks the option values that stand for a cut-off value', () => {
		const meta = { 1: { note: 'Long text start' }, 2: { note: 'Short' }, 3: {} }

		const result = cutOffValues(['note'], [{ fileid: 1 }, { fileid: 2 }, { fileid: 3 }], (id) => meta[id], (id, field) => id === 1 && field === 'note')

		expect([...result.note]).toEqual(['Long text start'])
	})
})

describe('filterValues', () => {
	const options = optionValues
	const nodes = [1, 2, 3, 4].map((fileid) => ({ fileid }))
	const values = (field, type, byId, fieldOptions) => filterValues(
		[{ field_name: field, field_type: type, field_options: fieldOptions }], nodes, (id) => byId[id], options,
	)[field]

	it('gives a select field its defined options, also in the {value, label} shape', () => {
		expect(values('status', 'select', {}, ['Draft', 'Final'])).toEqual(['Draft', 'Final'])
		expect(values('status', 'select', {}, [{ value: 'd', label: 'Draft' }])).toEqual(['d'])
	})

	it('gives a file link one option per linked file', () => {
		expect(values('link', 'filelink', { 1: { link: '12:;#34:' }, 2: { link: '12:' }, 3: { link: '' } })).toEqual(['12:', '34:'])
	})

	it('gives a checkbox yes and no', () => {
		expect(values('ok', 'checkbox', {})).toEqual(['1', '0'])
	})

	it('lists the distinct values of these files, without empty ones', () => {
		const byId = { 1: { klant: 'Beta' }, 2: { klant: 'Alpha' }, 3: { klant: 'Beta' }, 4: { klant: '' }, 5: { klant: 'Not in the folder' } }

		expect(values('klant', 'text', byId)).toEqual(['Alpha', 'Beta'])
	})

	it('sorts like the column: numbers and dates by value', () => {
		expect(values('budget', 'number', { 1: { budget: '10' }, 2: { budget: '9' }, 3: { budget: '100' } })).toEqual(['9', '10', '100'])
		expect(values('d', 'date', { 1: { d: '2026-03-01' }, 2: { d: '2025-12-31' } })).toEqual(['2025-12-31', '2026-03-01'])
	})

	it('splits a multiselect without defined options into its items', () => {
		expect(values('tags', 'multiselect', { 1: { tags: 'Offer;# Contract' }, 2: { tags: 'Contract' } })).toEqual(['Contract', 'Offer'])
	})

	it('uses the values themselves for a select without defined options', () => {
		expect(values('status', 'select', { 1: { status: 'Draft' }, 2: { status: 'Final' } }, [])).toEqual(['Draft', 'Final'])
	})
})

describe('listedOptions', () => {
	const option = (label) => ({ value: label, key: searchKey(label) })
	const many = Array.from({ length: 5000 }, (_, i) => option(`Klant ${String(i).padStart(4, '0')}`))
	const none = () => false

	it('lists every option of a short list', () => {
		const few = ['Draft', 'Final'].map(option)

		expect(listedOptions(few, '', none)).toEqual({ listed: few, matching: 2 })
	})

	it('lists the first hundred of a long list, and says how many there are', () => {
		const { listed, matching } = listedOptions(many, '', none)

		expect(LISTED_OPTIONS).toBe(100)
		expect(listed).toHaveLength(100)
		expect(listed[0].value).toBe('Klant 0000')
		expect(listed[99].value).toBe('Klant 0099')
		expect(matching).toBe(5000)
	})

	it('keeps checked options past the hundred, in their place', () => {
		const checked = new Set(['Klant 4321', 'Klant 0005'])

		const { listed } = listedOptions(many, '', (value) => checked.has(value))

		expect(listed).toHaveLength(101)
		expect(listed[5].value).toBe('Klant 0005')
		expect(listed[100].value).toBe('Klant 4321')
	})

	it('searches every option, not only the listed ones', () => {
		expect(listedOptions(many, '4321', none)).toEqual({ listed: [option('Klant 4321')], matching: 1 })
		expect(listedOptions(many, '  klant 49', none).matching).toBe(100)
		expect(listedOptions(many, 'nobody', none)).toEqual({ listed: [], matching: 0 })
	})

	it('caps the matches too, but never drops a checked one', () => {
		const { listed, matching } = listedOptions(many, 'klant 4', (value) => value === 'Klant 4999')

		expect(matching).toBe(1000)
		expect(listed).toHaveLength(101)
		expect(listed.at(-1).value).toBe('Klant 4999')
	})

	it('ignores case and accents', () => {
		const options = ['Überprüfung', 'Café', 'ÉCOLE'].map(option)

		expect(listedOptions(options, 'uberpr', none).listed.map((o) => o.value)).toEqual(['Überprüfung'])
		expect(listedOptions(options, 'CAFE', none).listed.map((o) => o.value)).toEqual(['Café'])
		expect(listedOptions(options, 'école', none).listed.map((o) => o.value)).toEqual(['ÉCOLE'])
	})
})

describe('sorting many files', () => {
	// The comparison as it was, value by value: the order must not change.
	function oldCompare(a, b, fieldType, direction) {
		const aEmpty = a === undefined || a === null || a === ''
		const bEmpty = b === undefined || b === null || b === ''
		if (aEmpty || bEmpty) return aEmpty === bEmpty ? 0 : (aEmpty ? 1 : -1)
		let result
		if (['number', 'integer', 'float'].includes(fieldType) && !Number.isNaN(parseFloat(a)) && !Number.isNaN(parseFloat(b))) result = parseFloat(a) - parseFloat(b)
		else if (fieldType === 'date' && !Number.isNaN(new Date(a).getTime()) && !Number.isNaN(new Date(b).getTime())) result = new Date(a).getTime() - new Date(b).getTime()
		else if (fieldType === 'checkbox' || fieldType === 'boolean') result = (a === '1' ? 0 : 1) - (b === '1' ? 0 : 1)
		else result = String(a).localeCompare(String(b))
		return direction === 'desc' ? -result : result
	}

	let seed = 7
	const pick = (list) => list[(seed = (seed * 1103515245 + 12345) % 2147483648) % list.length]
	const VALUES = {
		date: () => pick(['2026-10-01', '2025-01-31', '2026-02-29', 'not a date', '', undefined, '2026-10-01 14:30', '1999-12-31', '2026-1-5']),
		number: () => pick(['10', '9', '-3', '2.5', '1e3', 'abc', '', undefined, '007', '0']),
		text: () => pick(['Beta', 'alpha', 'Älpha', 'beta', '', undefined, '10', '9', 'Zeta']),
		checkbox: () => pick(['1', '0', '', undefined, 'true']),
	}

	for (const [type, value] of Object.entries(VALUES)) {
		for (const direction of ['asc', 'desc']) {
			it(`keeps the order of ${type} values, ${direction}`, () => {
				const nodes = Array.from({ length: 2000 }, (_, i) => ({ fileid: i + 1 }))
				const meta = new Map(nodes.map((n) => [n.fileid, { f: value() }]))
				const metaOf = (id) => meta.get(id)
				const sort = { fieldName: 'f', fieldType: type, direction }

				const expected = [...nodes].sort((a, b) => oldCompare(metaOf(a.fileid).f, metaOf(b.fileid).f, type, direction))

				expect(applyFilterSort(nodes, [], sort, metaOf).map((n) => n.fileid)).toEqual(expected.map((n) => n.fileid))
			})
		}
	}

	it('looks up each file once, not at every comparison', () => {
		const nodes = Array.from({ length: 1000 }, (_, i) => ({ fileid: i + 1 }))
		let lookups = 0
		const metaOf = (id) => { lookups++; return { f: `2026-01-${String((id % 28) + 1).padStart(2, '0')}` } }

		applyFilterSort(nodes, [], { fieldName: 'f', fieldType: 'date', direction: 'asc' }, metaOf)

		expect(lookups).toBe(1000)
	})
})
