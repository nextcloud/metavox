import { readFileSync } from 'node:fs'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, translatePlural: (app, s, p, n) => (n === 1 ? s : p), getCanonicalLocale: () => 'en-US' }))

const { compileRules } = await import('../../src/utils/valueRules.js')
const { cases } = JSON.parse(readFileSync('tests/fixtures/value-rules-server.json', 'utf8'))
const TYPES = [...Object.keys(JSON.parse(readFileSync('tests/fixtures/value-operators.json', 'utf8')).types), 'filelink']
const keptFor = (rule) => TYPES.filter((type) => compileRules([rule], type) !== null)

// What the server stores, the browser can use; what it refuses, the browser
// would not have used either, unless the server is stricter on purpose.
describe('a rule the server checked', () => {
	it.each(cases.filter((c) => c.stored).map((c) => [c.name, c]))('%s: stored, still used', (name, c) => {
		const sent = JSON.parse(c.rule)
		const stored = keptFor(c.stored)
		expect(stored.length).toBeGreaterThan(0)
		for (const type of keptFor(sent)) expect(stored).toContain(type)
	})

	it.each(cases.filter((c) => !c.stored && !c.stricter).map((c) => [c.name, c]))('%s: refused, never used', (name, c) => {
		expect(keptFor(JSON.parse(c.rule))).toEqual([])
	})

	it.each(cases.filter((c) => c.stricter).map((c) => [c.name, c]))('%s: refused on purpose, though the browser would use it', (name, c) => {
		expect(keptFor(JSON.parse(c.rule)).length).toBeGreaterThan(0)
	})
})
