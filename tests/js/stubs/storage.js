// Node 25 brings a localStorage global of its own, without methods unless Node
// runs with --localstorage-file, and it hides happy-dom's. A plain one instead.
if (typeof globalThis.localStorage?.getItem !== 'function') {
	const items = new Map()
	const storage = {
		getItem: (key) => (items.has(String(key)) ? items.get(String(key)) : null),
		setItem: (key, value) => { items.set(String(key), String(value)) },
		removeItem: (key) => { items.delete(String(key)) },
		clear: () => items.clear(),
		key: (index) => [...items.keys()][index] ?? null,
		get length() { return items.size },
	}
	for (const target of [globalThis, globalThis.window].filter(Boolean)) {
		Object.defineProperty(target, 'localStorage', { value: storage, configurable: true })
	}
}
