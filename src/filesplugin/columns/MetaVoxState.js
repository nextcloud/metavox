/**
 * MetaVox — Centralised global state
 *
 * Every piece of mutable state that was previously a module-level variable
 * in MetaVoxColumns.js lives here so that helper modules (API, Undo, etc.)
 * can read / write it without circular imports.
 */

// ── Nextcloud version ────────────────────────────────────────────────
/** @type {number} Nextcloud major version (0 if unknown) */
const ncVersion = (() => {
	try {
		// OC.config.version is "32.0.5.0" format
		const v = window.OC?.config?.version
		if (v) return parseInt(v.split('.')[0], 10)
	} catch (e) { /* ignore */ }
	return 0
})()
export function getNcVersion() { return ncVersion }

// ── Column configs ───────────────────────────────────────────────────
let _activeColumnConfigs = []
export function getActiveColumnConfigs() { return _activeColumnConfigs }
export function setActiveColumnConfigs(v) { _activeColumnConfigs = v }

// ── Groupfolder ID ───────────────────────────────────────────────────
let _activeGroupfolderId = null
export function getActiveGroupfolderId() { return _activeGroupfolderId }
export function setActiveGroupfolderId(v) { _activeGroupfolderId = v }

// ── Metadata cache (LRU Map — evicts oldest entries when exceeding limit) ──
const MAX_CACHE_SIZE = 100000
const _metadataCache = new Map()
export const metadataCache = {
	get: (key) => { return _metadataCache.get(key) },
	set: (key, value) => {
		// Delete + re-add moves key to end (most recent)
		_metadataCache.delete(key)
		_metadataCache.set(key, value)
		// Evict oldest 25% when over limit
		if (_metadataCache.size > MAX_CACHE_SIZE) {
			const toRemove = Math.floor(MAX_CACHE_SIZE * 0.25)
			const iter = _metadataCache.keys()
			for (let i = 0; i < toRemove; i++) {
				_metadataCache.delete(iter.next().value)
			}
		}
	},
	has: (key) => _metadataCache.has(key),
	delete: (key) => _metadataCache.delete(key),
	clear: () => _metadataCache.clear(),
	keys: () => _metadataCache.keys(),
	values: () => _metadataCache.values(),
	entries: () => _metadataCache.entries(),
	forEach: (fn) => _metadataCache.forEach(fn),
	get size() { return _metadataCache.size },
	[Symbol.iterator]: () => _metadataCache[Symbol.iterator](),
}

// ── Columns active flag ──────────────────────────────────────────────
let _columnsActive = false
export function getColumnsActive() { return _columnsActive }
export function setColumnsActive(v) { _columnsActive = v }

// ── Row observer ─────────────────────────────────────────────────────
let _rowObserver = null
export function getRowObserver() { return _rowObserver }
export function setRowObserver(v) { _rowObserver = v }

// ── Load queue (mutable Set — export directly) ──────────────────────
/** @type {Set<number>} File IDs queued for metadata loading */
export const loadQueue = new Set()

// ── Load timer ───────────────────────────────────────────────────────
let _loadTimer = null
export function getLoadTimer() { return _loadTimer }
export function setLoadTimer(v) { _loadTimer = v }

// ── Flush deadline ───────────────────────────────────────────────────
let _flushDeadline = 0
export function getFlushDeadline() { return _flushDeadline }
export function setFlushDeadline(v) { _flushDeadline = v }

// ── Column widths (mutable Map — export directly) ───────────────────
/** @type {Map<string, number>} Persisted column widths */
export const columnWidths = new Map()

// ── Views ────────────────────────────────────────────────────────────
let _activeViews = []
export function getActiveViews() { return _activeViews }
export function setActiveViews(v) { _activeViews = v }

let _activeView = null
export function getActiveView() { return _activeView }
export function setActiveView(v) { _activeView = v }

let _viewTabsEl = null
export function getViewTabsEl() { return _viewTabsEl }
export function setViewTabsEl(v) { _viewTabsEl = v }

let _canManageViews = false
export function getCanManageViews() { return _canManageViews }
export function setCanManageViews(v) { _canManageViews = v }

// ── Available fields ─────────────────────────────────────────────────
let _availableFields = []
export function getAvailableFields() { return _availableFields }
export function setAvailableFields(v) { _availableFields = v }

// ── Prefetched filter values ─────────────────────────────────────────
let _prefetchedFilterValues = null
export function getPrefetchedFilterValues() { return _prefetchedFilterValues }
export function setPrefetchedFilterValues(v) { _prefetchedFilterValues = v }

// ── Pending undo ─────────────────────────────────────────────────────
let _pendingUndo = null
export function getPendingUndo() { return _pendingUndo }
export function setPendingUndo(v) { _pendingUndo = v }

// ── Permission cache (mutable Map — export directly) ────────────────
/** @type {Map<number, boolean>} Cache of file write permissions: fileId -> canEdit */
export const permissionCache = new Map()

// ── Active editor ────────────────────────────────────────────────────
let _activeEditor = null
export function getActiveEditor() { return _activeEditor }
export function setActiveEditor(v) { _activeEditor = v }

// ── Cached actions width ─────────────────────────────────────────────
let _cachedActionsWidth = null
export function getCachedActionsWidth() { return _cachedActionsWidth }
export function setCachedActionsWidth(v) { _cachedActionsWidth = v }

// ── Initial-state consumed flag ──────────────────────────────────────
let _initialStateConsumed = false
export function getInitialStateConsumed() { return _initialStateConsumed }
export function setInitialStateConsumed(v) { _initialStateConsumed = v }

// ── Window-level groupfolder data (convenience accessors) ────────────
export function getMetavoxGroupfolders() { return window._metavoxGroupfolders }
export function setMetavoxGroupfolders(v) { window._metavoxGroupfolders = v }

export function getMetavoxAllGfData() { return window._metavoxAllGfData }
export function setMetavoxAllGfData(v) { window._metavoxAllGfData = v }
