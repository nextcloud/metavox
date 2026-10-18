/**
 * MetaVox Files Plugin - Nextcloud 31-33 Compatible Version
 * Registers metadata sidebar tab in Nextcloud Files app
 */

// Dynamically set public path for chunk loading based on where this script is served from
// eslint-disable-next-line camelcase
__webpack_public_path__ = document.querySelector('script[src*="metavox/js/filesplugin"]')?.src?.replace(/filesplugin\.js.*$/, '') || '/apps/metavox/js/'

import { createApp, h, shallowReactive } from 'vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import { registerMetaVoxDavProperty } from './columns/DavMetadata.js'
import { startListingSync } from './columns/ListingSync.js'
import { startColumnWatcher } from './columns/MetaVoxColumns.js'
import { holdLink } from './columns/UrlState.js'
import { loadPart, watchLoading } from '../utils/updatedPage.js'
import { METAVOX_ICON_SVG } from '../utils/metavoxIcon.js'

// The tab's form with its field editors (about 175 KB) loads the first time
// a MetaVox tab opens, not with every Files page.
let sidebarTab = null
let sidebarTabLoad = null
function loadSidebarTab() {
	sidebarTabLoad ??= loadPart(() => import(/* webpackChunkName: "metavox-sidebar" */ './FilesSidebarTab.vue'))
		.then((module) => {
			sidebarTab = module.default
			return sidebarTab
		})
		.catch((error) => {
			// loadPart said to reload; a next opening may try again.
			sidebarTabLoad = null
			throw error
		})
	return sidebarTabLoad
}

// The page's own view and filters, before the Files app touches the URL.
holdLink()
// Right away, before the Files app sends its first PROPFIND: ask for
// nc:metavox and follow every listing.
registerMetaVoxDavProperty()
startListingSync()

// Bulk edit opens through this script (BulkMetadataAction): its Vue and
// components are here already. The import stays here, so its chunk is built
// on what this script holds.
window._metavoxLoadBulk = () => import(/* webpackChunkName: "metavox-bulk-edit" */ './BulkMetadataModalApp.js')

// ========================================
// MetaVox Metadata Sidebar Tab
// ========================================

/**
 * Register the metadata sidebar tab for NC33+ by writing directly to
 * NC33's scoped globals (window._nc_files_scope.v4_0.filesSidebarTabs).
 *
 * We cannot use the bundled @nextcloud/files registerSidebarTab() because
 * it writes to window._nc_files_sidebar_tabs, while NC33's sidebar reads
 * from window._nc_files_scope.v4_0.filesSidebarTabs (a different location).
 */
async function registerNewSidebarTab() {
	try {
		const scope = window._nc_files_scope?.v4_0
		if (!scope) {
			return false
		}

		// Only use NC33 API if filesSidebarTabs Map already exists (created by NC33 itself).
		// On NC31/NC32, _nc_files_scope may exist as empty object — creating the Map
		// ourselves would bypass the legacy OCA.Files.Sidebar registration that NC32 actually reads.
		if (!scope.filesSidebarTabs || !(scope.filesSidebarTabs instanceof Map)) {
			return false
		}

		if (scope.filesSidebarTabs.has('metavox')) {
			return true
		}

		scope.filesSidebarTabs.set('metavox', {
			id: 'metavox',
			displayName: 'MetaVox',
			iconSvgInline: METAVOX_ICON_SVG,
			order: 100,
			tagName: 'metavox-sidebar-tab',

			enabled(context) {
				const node = context?.node
				if (!node) return false
				return node.type === 'file' || node.type === 'folder'
			},

			async onInit() {

				class MetaVoxSidebarElement extends HTMLElement {
					constructor() {
						super()
						this._app = null
						// Reactive, so another file or tab reaches the open form instead
						// of building it again (which loaded twice per open). Shallow:
						// Nextcloud's Node must not become a Vue proxy.
						this._props = shallowReactive({
							node: null,
							folder: null,
							view: null,
							active: false,
						})
					}

					connectedCallback() {
						this._mount()
					}

					disconnectedCallback() {
						this._unmount()
					}

					static get observedAttributes() {
						return ['node', 'folder', 'view', 'active']
					}

					attributeChangedCallback(name, oldValue, newValue) {
						if (name === 'active') {
							this._props.active = newValue === 'true' || newValue === ''
						}
					}

					set node(value) {
						this._props.node = value
					}

					get node() {
						return this._props.node
					}

					set folder(value) {
						this._props.folder = value
					}

					get folder() {
						return this._props.folder
					}

					set view(value) {
						this._props.view = value
					}

					get view() {
						return this._props.view
					}

					set active(value) {
						this._props.active = value
					}

					get active() {
						return this._props.active
					}

					_mount() {
						if (this._app) return
						// The first time, once the form has loaded: if the tab is still there.
						if (!sidebarTab) {
							loadSidebarTab().then(() => this.isConnected && this._mount()).catch(() => {})
							return
						}

						this._app = createApp({
							render: () => h(sidebarTab, {
								node: this._props.node,
								folder: this._props.folder,
								view: this._props.view,
								active: this._props.active,
							}),
						})

						this._app.config.globalProperties.t = translate
						this._app.config.globalProperties.n = translatePlural

						const mountPoint = document.createElement('div')
						this.appendChild(mountPoint)
						watchLoading(this._app).mount(mountPoint)
					}

					_unmount() {
						if (this._app) {
							this._app.unmount()
							this._app = null
						}
						this.innerHTML = ''
					}
				}

				if (!customElements.get('metavox-sidebar-tab')) {
					customElements.define('metavox-sidebar-tab', MetaVoxSidebarElement)
				}
			},
		})

		return true
	} catch (error) {
		console.error('MetaVox: Failed to register sidebar tab with new API', error)
		return false
	}
}

/**
 * Register the metadata sidebar tab using the legacy OCA.Files.Sidebar API
 * Fallback for older Nextcloud versions (NC31-32)
 */
async function registerLegacySidebarTab() {
	if (!window.OCA?.Files?.Sidebar?.registerTab) {
		return false
	}

	// Prevent duplicate registration
	if (window._metavoxTabRegistered) {
		return true
	}

	try {
		window.OCA.Files.Sidebar.registerTab(new window.OCA.Files.Sidebar.Tab({
			id: 'metavox-metadata',
			name: 'MetaVox',
			iconSvg: METAVOX_ICON_SVG,
			order: 100,

			async mount(el, fileInfo, context) {
				if (this.vueApp) {
					this.vueApp.unmount()
					this.vueApp = null
				}
				this.currentFileInfo = fileInfo
				const tab = await loadSidebarTab().catch(() => null)
				// Another mount may have come first while the form loaded.
				if (!tab || this.vueApp) return

				const mountEl = document.createElement('div')
				el.innerHTML = ''
				el.appendChild(mountEl)

				this.vueApp = createApp({
					render: () => h(tab, {
						fileInfo: this.currentFileInfo,
					}),
				})
				this.vueApp.config.globalProperties.t = translate
				this.vueApp.config.globalProperties.n = translatePlural
				watchLoading(this.vueApp).mount(mountEl)
			},

			update(fileInfo) {
				this.currentFileInfo = fileInfo
				if (this.vueApp) {
					const el = this.vueApp._container?.parentElement
					if (el) {
						this.vueApp.unmount()
						const mountEl = document.createElement('div')
						el.innerHTML = ''
						el.appendChild(mountEl)
						this.vueApp = createApp({
							// Loaded: the app exists only after mount loaded it.
							render: () => h(sidebarTab, {
								fileInfo: this.currentFileInfo,
							}),
						})
						this.vueApp.config.globalProperties.t = translate
						this.vueApp.config.globalProperties.n = translatePlural
						watchLoading(this.vueApp).mount(mountEl)
					}
				}
			},

			destroy() {
				if (this.vueApp) {
					this.vueApp.unmount()
					this.vueApp = null
				}
			},

			enabled(fileInfo) {
				return fileInfo?.type === 'file' || fileInfo?.type === 'dir'
			},
		}))

		// NC32 sidebar doesn't sort tabs by order. Move MetaVox to end
		// by replacing the entire array to trigger Vue reactivity.
		try {
			const state = window.OCA.Files.Sidebar._state
			if (state?.tabs?.length > 1) {
				const mvTab = state.tabs.find(t => t.id === 'metavox-metadata')
				if (mvTab) {
					const others = state.tabs.filter(t => t.id !== 'metavox-metadata')
					others.push(mvTab)
					state.tabs.splice(0, state.tabs.length, ...others)
				}
			}
		} catch (e) { /* ignore */ }

		return true
	} catch (error) {
		console.error('MetaVox: Failed to register sidebar tab with legacy API', error)
		return false
	}
}

// ========================================
// Registration Logic
// ========================================

/**
 * Register all sidebar tabs - tries new API first, then falls back to legacy
 */
async function registerAllTabs() {
	if (window._metavoxTabRegistered) {
		return
	}

	// Try new @nextcloud/files registerSidebarTab API first (NC33+)
	const newApiSuccess = await registerNewSidebarTab()
	if (newApiSuccess) {
		window._metavoxTabRegistered = true
		return
	}

	// Fallback to legacy OCA.Files.Sidebar API (NC31-32)
	const legacySuccess = await registerLegacySidebarTab()
	if (legacySuccess) {
		window._metavoxTabRegistered = true
	}
}

/**
 * Wait for Files app to be ready
 */
function waitForFilesApp() {
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => {
			waitForFilesApp()
		})
		return
	}

	// Try immediately for NC33 (scoped globals are available early)
	registerAllTabs()

	// Also poll for legacy API (NC31-32) which loads asynchronously
	let attempts = 0
	const maxAttempts = 50 // 5 seconds max
	const pollInterval = setInterval(() => {
		attempts++
		if (window._metavoxTabRegistered || attempts >= maxAttempts) {
			clearInterval(pollInterval)
			return
		}
		registerAllTabs()
	}, 100)
}

// Start waiting for Files app
waitForFilesApp()

// ========================================
// MetaVox File List Columns
// ========================================

/**
 * Start the column watcher after the Files app is ready.
 * Columns are injected directly into the DOM via MutationObserver.
 */
function initColumns() {
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => initColumns())
		return
	}

	// Wait for Files app to initialize, then start watcher
	setTimeout(() => startColumnWatcher(), 500)
}

initColumns()
