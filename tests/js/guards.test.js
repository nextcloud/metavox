import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'
import { describe, expect, it } from 'vitest'

const SRC = join(import.meta.dirname, '../../src')

function sourceFiles(dir = SRC) {
	return readdirSync(dir).flatMap((name) => {
		const path = join(dir, name)
		if (statSync(path).isDirectory()) return sourceFiles(path)
		return /\.(js|vue)$/.test(name) ? [path] : []
	})
}

// Static checks on src/ that keep the module structure the plan relies on.
describe('source guards', () => {
	it('FilesListRef has no imports, so it can never be part of an import cycle', () => {
		const source = readFileSync(join(SRC, 'filesplugin/columns/FilesListRef.js'), 'utf8')
		expect(source).not.toMatch(/^\s*import\s/m)
	})

	it('only the store writes the metadata and permission caches', () => {
		const allowed = ['filesplugin/columns/MetadataStore.js', 'filesplugin/columns/MetaVoxState.js']
		const offenders = []
		for (const file of sourceFiles()) {
			const name = relative(SRC, file)
			if (allowed.includes(name)) continue
			const matches = readFileSync(file, 'utf8').match(/\b(metadataCache|permissionCache)\.(set|clear|delete)\(/g)
			if (matches) offenders.push(`${name}: ${matches.join(', ')}`)
		}
		expect(offenders).toEqual([])
	})

	it('saves reach the grid through the store, not through window events', () => {
		const offenders = sourceFiles()
			.filter((file) => /'metavox:metadata:saved'/.test(readFileSync(file, 'utf8')))
			.map((file) => relative(SRC, file))
		expect(offenders).toEqual([])
	})

	it('the filter and bulk action register through the official API, without Vue internals or version guesses', () => {
		// Our own bundle creates window._nc_files_scope, so it says nothing about the NC version.
		for (const name of ['filesplugin/columns/MetadataFilter.js', 'filesplugin/BulkMetadataAction.js']) {
			expect(readFileSync(join(SRC, name), 'utf8'), name).not.toMatch(/__vue__|_nc_files_scope/)
		}
	})

	it('the bulk action module stays small: the init script loads it before the Files app', () => {
		const source = readFileSync(join(SRC, 'filesplugin/BulkMetadataAction.js'), 'utf8')
		expect(source).not.toMatch(/from 'vue'|BulkMetadataModal\.vue/)
	})

	it('request errors go through errorText, so a 500 never shows English server text', () => {
		const offenders = sourceFiles()
			.filter((file) => /response\?\.data\?\.(error|message)\s*\|\|/.test(readFileSync(file, 'utf8')))
			.map((file) => relative(SRC, file))
		expect(offenders).toEqual([])
	})

	it('filter options come from the listing: the frontend never asks the server for filter values', () => {
		const offenders = sourceFiles()
			.filter((file) => /\/filter-values/.test(readFileSync(file, 'utf8')))
			.map((file) => relative(SRC, file))
		expect(offenders).toEqual([])
	})

	it('_findFilesList is defined and imported from FilesListRef only', () => {
		const offenders = []
		for (const file of sourceFiles()) {
			const source = readFileSync(file, 'utf8')
			const name = relative(SRC, file)
			if (/function\s+_findFilesList\b/.test(source) && !name.endsWith('FilesListRef.js')) {
				offenders.push(`${name} defines _findFilesList`)
			}
			for (const match of source.matchAll(/import\s*\{([^}]*)\}\s*from\s*'([^']+)'/g)) {
				if (/\b_findFilesList\b/.test(match[1]) && !match[2].endsWith('/FilesListRef.js')) {
					offenders.push(`${name} imports _findFilesList from ${match[2]}`)
				}
			}
		}
		expect(offenders).toEqual([])
	})
})
