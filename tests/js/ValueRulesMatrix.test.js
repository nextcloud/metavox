import { readFileSync } from 'node:fs'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, translatePlural: (app, s, p, n) => (n === 1 ? s : p), getCanonicalLocale: () => 'en-US' }))

const { compileRules, evaluate } = await import('../../src/utils/valueRules.js')
const { vectors } = JSON.parse(readFileSync('tests/fixtures/value-rules-matrix.json', 'utf8'))
const plain = vectors.filter((v) => !v.check)
const folders = vectors.filter((v) => v.check === 'sameAsFile')

describe('every condition per field type, written without the code', () => {
	it.each(plain.map((v) => [v.name, v]))('%s', (name, v) => {
		expect(compileRules([v.rule], v.type)).not.toBeNull()
		expect(evaluate(v.rule, v.value, v.type, v.ctx)).toBe(v.expected)
	})

	it.each(folders.map((v) => [v.name, v]))('%s: a folder counts like a file', (name, v) => {
		expect(evaluate(v.rule, v.value, v.type, v.ctx)).toBe(evaluate(v.rule, v.value, v.type, { ...v.ctx, isFolder: false }))
	})

	// A vector with a check nobody runs would pass unseen.
	it('runs every vector', () => {
		expect(vectors.filter((v) => v.check && v.check !== 'sameAsFile').map((v) => v.name)).toEqual([])
	})
})
