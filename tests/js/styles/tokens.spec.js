import { describe, it, expect } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'

/**
 * Style rules that are only visible by reading every file at once.
 *
 * Each of these shipped at least once in this app: a hint at 12px beside a
 * library hint at 15px, a badge at 11px, a count at 10px. Individually each
 * looked deliberate; together they were four different ideas of "small" in
 * one settings page, and two of them failed contrast because small and
 * maxcontrast is the pairing that does.
 */
const walk = (dir) => readdirSync(dir).flatMap((entry) => {
	const path = join(dir, entry)
	return statSync(path).isDirectory() ? walk(path) : [path]
})

/**
 * src/filesplugin is PR #2's subject and is not walked here — it has the
 * same debt in five places and fixing it from this branch would cross the
 * two diffs.
 */
/**
 * DynamicFieldInput is used only by src/search/FilterPicker and appears in
 * none of the admin, user or filesplugin bundles — it belongs to the search
 * surface, which is #2's subject. Excluded for the same reason as
 * src/filesplugin, not because it is clean.
 */
const OUT_OF_SCOPE = ['fields/DynamicFieldInput.vue']

const adminFiles = walk('src/components')
	.filter((f) => f.endsWith('.vue'))
	.filter((f) => !OUT_OF_SCOPE.some((x) => f.endsWith(x)))

/**
 * Body-text sizes that are not a token. The scale is --font-size-small
 * (13px) and --default-font-size (15px), and a hardcoded 13px is the same
 * drift as 14px — it happens to match today and stops matching the moment
 * a theme changes the token.
 *
 * Heading sizes (18/20/24px) are allowed: §6 names 20px for an h3 and the
 * tokens do not cover headings.
 *
 * `em` is never allowed — it compounds, and 0.9em resolved to 13.5px,
 * which is on no scale at all.
 */
const OFF_SCALE = /font-size:\s*(?:(?:[0-9]|1[0-7])px|[0-9.]+em)/g

describe('type scale', () => {
	it.each(adminFiles)('%s sets no font-size off the token scale', (file) => {
		const source = readFileSync(file, 'utf8')
		const hits = [...source.matchAll(OFF_SCALE)].map((m) => {
			const line = source.slice(0, m.index).split('\n').length
			return `${file}:${line} ${m[0]}`
		})
		expect(hits, 'use var(--font-size-small), var(--default-font-size), or inherit').toEqual([])
	})

	it.each(adminFiles)('%s forces no control height of its own', (file) => {
		// Five controls in the Configure-fields dialog measured 42, 42, 44, 34
		// and 44px: four heights in one form. The components own their height
		// and the platform's is 34px, so a hardcoded min-height on a wrapper
		// only breaks the row (§6, "Every control in the form measures the
		// same height"). Spinners are exempt — they are sized art, not
		// controls, and are tracked separately.
		const source = readFileSync(file, 'utf8')
		const hits = [...source.matchAll(/(?:min-)?height:\s*(4[0-9]|[5-9][0-9])px/g)]
			.filter((m) => {
				const before = source.slice(Math.max(0, m.index - 260), m.index)
				return !/icon-loading|spinner|avatar|\bicon\b/.test(before)
			})
			.map((m) => `${file} ${m[0]}`)
		expect(hits, 'let the component set its own height').toEqual([])
	})

	it('does not hand-build a counter badge', () => {
		// NcCounterBubble exists for a count beside a heading and carries its
		// own contrast; the hand-built one measured 3.85:1.
		const source = readFileSync('src/components/PermissionsManager.vue', 'utf8')
		expect(source).not.toMatch(/\.permission-count\s*\{/)
		expect(source).toMatch(/NcCounterBubble/)
	})
})
