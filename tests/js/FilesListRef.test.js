import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

let _findFilesList

beforeEach(async () => {
	vi.resetModules()
	;({ _findFilesList } = await import('../../src/filesplugin/columns/FilesListRef.js'))
})

afterEach(() => vi.unstubAllGlobals())

const withFilesListElement = (element) => vi.stubGlobal('document', { querySelector: (s) => (s === '.files-list' ? element : null) })

describe('_findFilesList on Vue 2 (NC 31/32)', () => {
	// A .files-list element whose Vue instance sits `depth` parents below FilesList.
	function mountFilesList(depth) {
		const filesList = { $options: { name: 'FilesList' }, $parent: null }
		let vm = filesList
		for (let i = 0; i < depth; i++) {
			vm = { $options: { name: 'Child' + i }, $parent: vm }
		}
		withFilesListElement({ __vue__: vm })
		return filesList
	}

	it('finds FilesList on the element itself', () => {
		const filesList = mountFilesList(0)
		expect(_findFilesList()).toBe(filesList)
	})

	it('walks up the parents to FilesList', () => {
		const filesList = mountFilesList(3)
		expect(_findFilesList()).toBe(filesList)
	})

	it('gives up after 8 levels', () => {
		mountFilesList(8)
		expect(_findFilesList()).toBeNull()
	})

	it('returns null without the list element', () => {
		vi.stubGlobal('document', { querySelector: () => null })
		expect(_findFilesList()).toBeNull()
	})
})

describe('_findFilesList on Vue 3 (NC 33+)', () => {
	const component = (name, subTree, extra = {}) => ({ component: { type: { name }, subTree, isUnmounted: false, proxy: { name }, ...extra } })

	// The app's mount element holds the tree; .files-list sits somewhere below it.
	function mountApp(tree) {
		const container = { _vnode: tree, parentElement: null }
		const wrapper = { parentElement: container }
		withFilesListElement({ parentElement: wrapper })
		return container
	}

	it('finds FilesList in the app tree, through fragments and router views', () => {
		const filesList = component('FilesList', { children: [] })
		mountApp(component('App', { children: [component('Navigation', null), { children: [component('RouterView', filesList)] }] }))

		expect(_findFilesList()).toBe(filesList.component.proxy)
	})

	it('finds a component known by its script-setup name', () => {
		const filesList = { component: { type: { __name: 'FilesList' }, isUnmounted: false, proxy: {} } }
		mountApp(component('App', filesList))

		expect(_findFilesList()).toBe(filesList.component.proxy)
	})

	it('keeps using the found component while it is mounted', () => {
		const filesList = component('FilesList', null)
		const container = mountApp(component('App', filesList))
		_findFilesList()
		container._vnode = null

		expect(_findFilesList()).toBe(filesList.component.proxy)
	})

	it('looks again once the component was unmounted', () => {
		const first = component('FilesList', null)
		const container = mountApp(component('App', first))
		_findFilesList()
		first.component.isUnmounted = true
		const second = component('FilesList', null)
		container._vnode = component('App', second)

		expect(_findFilesList()).toBe(second.component.proxy)
	})

	it('returns null when FilesList is not in the tree', () => {
		mountApp(component('App', { children: [component('Other', null)] }))
		expect(_findFilesList()).toBeNull()
	})

	it('returns null without a mounted app', () => {
		withFilesListElement({ parentElement: { parentElement: null } })
		expect(_findFilesList()).toBeNull()
	})
})
