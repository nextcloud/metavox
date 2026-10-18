/**
 * MetaVox FilesListRef — finds the Files app's FilesList component.
 *
 * Kept free of imports so any module can use it without circular imports.
 */

let vue3Instance = null // cached while it stays mounted

/**
 * @return {object|null} the FilesList component (loading, dirContents,
 *   currentFolder, fetchContent), or null when it is not mounted
 */
export function _findFilesList() {
	const filesListEl = document.querySelector('.files-list')
	if (!filesListEl) return null

	// Vue 2 (NC 31/32): the element points at its component.
	const vm = filesListEl['__vue__']
	if (vm) {
		let current = vm
		for (let i = 0; i < 8 && current; i++) {
			if (current.$options && current.$options.name === 'FilesList') return current
			current = current.$parent
		}
		return null
	}

	// Vue 3 (NC 33+): only the app's mount element knows the rendered tree.
	if (vue3Instance && !vue3Instance.isUnmounted) {
		return vue3Instance.proxy
	}
	vue3Instance = null
	for (let el = filesListEl; el; el = el.parentElement) {
		if (el._vnode) {
			vue3Instance = findComponent(el._vnode, 'FilesList')
			return vue3Instance?.proxy ?? null
		}
	}
	return null
}

// Breadth-first through components and fragments; FilesList sits near the top.
function findComponent(root, name) {
	const queue = [root]
	for (let visited = 0; queue.length && visited < 5000; visited++) {
		const vnode = queue.shift()
		if (!vnode || typeof vnode !== 'object') continue
		const component = vnode.component
		if (component) {
			if ((component.type?.name ?? component.type?.__name) === name) return component
			queue.push(component.subTree)
		} else if (vnode.suspense) {
			queue.push(vnode.suspense.activeBranch)
		} else if (Array.isArray(vnode.children)) {
			queue.push(...vnode.children)
		}
	}
	return null
}
