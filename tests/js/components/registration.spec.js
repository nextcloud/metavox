import { describe, it, expect } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'

/**
 * A component used in a template but never imported is not a build error.
 * Vue leaves the unknown tag in the DOM as a literal element — `<ncnotecard>`
 * — so the markup is there, the build is green, and only the rendered page
 * shows that nothing happened. It cost a deploy to notice once; this catches
 * it in a second.
 *
 * The same shape of failure for a *prop* that a component does not declare is
 * covered by the checks in the component specs, since those need the real
 * typings.
 */
const walk = (dir) => readdirSync(dir).flatMap((entry) => {
	const path = join(dir, entry)
	return statSync(path).isDirectory() ? walk(path) : [path]
})

const vueFiles = walk('src').filter((f) => f.endsWith('.vue'))

describe('every Nc* component used in a template is registered', () => {
	it.each(vueFiles)('%s', (file) => {
		const source = readFileSync(file, 'utf8')
		const template = source.slice(0, source.indexOf('<script'))
		const script = source.slice(source.indexOf('<script'))

		// <NcFoo …> in the template, ignoring closing tags.
		const used = new Set([...template.matchAll(/<(Nc[A-Z][A-Za-z]*)[\s/>]/g)].map((m) => m[1]))
		if (used.size === 0) return

		// Barrel imports may span several lines, so collapse whitespace before
		// looking for the name inside one.
		const flat = script.replace(/\s+/g, ' ')

		const missing = [...used].filter((name) => {
			// Or loaded on demand: const NcFoo = defineAsyncComponent(…).
			const imported = new RegExp(`import\\s+${name}\\s+from`).test(script)
				|| new RegExp(`import \\{[^}]*\\b${name}\\b[^}]*\\} from '@nextcloud/vue'`).test(flat)
				|| new RegExp(`const\\s+${name}\\s*=\\s*defineAsyncComponent\\(`).test(script)
			// Registered in a components: block — which may be one per line or
			// all on one line — or via <script setup>, where the import alone
			// is enough. An earlier version of this anchored to the start of a
			// line and reported six correctly-registered files.
			const registered = new RegExp(`\\b${name}\\s*[,}]`).test(script)
				|| /<script setup/.test(script)
			return !(imported && registered)
		})

		expect(missing, `${file}: used in the template but not imported/registered`).toEqual([])
	})
})
