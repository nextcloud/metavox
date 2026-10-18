import { describe, expect, it } from 'vitest'
import { displayName, isWithin, joinTokens, parseValue } from '../../src/components/fields/filelinkUtils.js'

describe('a file link as the server sends it', () => {
	it('holds ids only', () => {
		expect(parseValue('12:;#34:')).toEqual([{ fileId: 12, path: '' }, { fileId: 34, path: '' }])
	})

	it('goes back unchanged when saved again', () => {
		expect(joinTokens(parseValue('12:;#34:'))).toBe('12:;#34:')
	})

	it('keeps a picked path next to the ids until it is saved', () => {
		expect(joinTokens([...parseValue('12:'), { fileId: null, path: '/Team/a.pdf' }])).toBe('12:;#/Team/a.pdf')
	})
})

describe('displayName', () => {
	it('uses the name as you see the file', () => {
		expect(displayName({ fileId: 12, path: '' }, { 12: 'Offer.pdf' })).toBe('Offer.pdf')
	})

	it('has no name for an id it does not know: the caller shows loading or unavailable', () => {
		expect(displayName({ fileId: 12, path: '' }, {})).toBe('')
		expect(displayName({ fileId: 12, path: '/old/name.pdf' }, {})).toBe('')
	})

	it('shows the last part of a link without id', () => {
		expect(displayName({ fileId: null, path: '/Team/Docs/a.pdf' })).toBe('a.pdf')
	})
})

describe('isWithin', () => {
	it('accepts the team folder and what is in it', () => {
		expect(isWithin('/Team', '/Team')).toBe(true)
		expect(isWithin('/Team/Docs/a.pdf', '/Team/')).toBe(true)
	})

	it('refuses a path outside it, also one that only starts the same', () => {
		expect(isWithin('/Other/a.pdf', '/Team')).toBe(false)
		expect(isWithin('/Team-archive/a.pdf', '/Team')).toBe(false)
	})

	it('allows everything when the team folder is not known', () => {
		expect(isWithin('/anything', '')).toBe(true)
	})
})
