/**
 * MetaVox Files Plugin - Nextcloud 31-33 Compatible Version
 * Registers metadata sidebar tab in Nextcloud Files app
 */

// Dynamically set public path for chunk loading based on where this script is served from
// eslint-disable-next-line camelcase
__webpack_public_path__ = document.querySelector('script[src*="metavox/js/filesplugin"]')?.src?.replace(/filesplugin\.js.*$/, '') || '/apps/metavox/js/'

import { createApp, h } from 'vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import { registerBulkMetadataAction } from './BulkMetadataAction.js'
import { startColumnWatcher } from './columns/MetaVoxColumns.js'
import FilesSidebarTab from './FilesSidebarTab.vue'

// MetaVox icon SVG
const metavoxIconSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="-4 -3 32 30"><polygon fill="currentColor" points="6.4,0.9 2,10.1 11,10.1"/><polygon fill="currentColor" points="17.5,0.9 13,10.1 22,10.1"/><rect x="2" y="10.1" width="20" height="13.1" rx="0.5" fill="none" stroke="currentColor" stroke-width="1.8"/><line x1="4.9" y1="15" x2="18.8" y2="15" stroke="currentColor" stroke-width="1"/><line x1="4.9" y1="17.4" x2="18.8" y2="17.4" stroke="currentColor" stroke-width="1"/><line x1="4.9" y1="19.8" x2="13" y2="19.8" stroke="currentColor" stroke-width="1"/></svg>'

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
			iconSvgInline: metavoxIconSvg,
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
						this._props = {
							node: null,
							folder: null,
							view: null,
							active: false,
						}
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
						if (this._app) {
							this._remount()
						}
					}

					set node(value) {
						this._props.node = value
						if (this._app) {
							this._remount()
						}
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
						if (this._app) {
							this._remount()
						}
					}

					get active() {
						return this._props.active
					}

					_mount() {
						if (this._app) return

						this._app = createApp({
							render: () => h(FilesSidebarTab, {
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
						this._app.mount(mountPoint)
					}

					_unmount() {
						if (this._app) {
							this._app.unmount()
							this._app = null
						}
						this.innerHTML = ''
					}

					_remount() {
						this._unmount()
						this._mount()
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
			iconSvg: metavoxIconSvg,
			order: 100,

			async mount(el, fileInfo, context) {
				if (this.vueApp) {
					this.vueApp.unmount()
				}

				const mountEl = document.createElement('div')
				el.innerHTML = ''
				el.appendChild(mountEl)

				this.currentFileInfo = fileInfo
				this.vueApp = createApp({
					render: () => h(FilesSidebarTab, {
						fileInfo: this.currentFileInfo,
					}),
				})
				this.vueApp.config.globalProperties.t = translate
				this.vueApp.config.globalProperties.n = translatePlural
				this.vueApp.mount(mountEl)
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
							render: () => h(FilesSidebarTab, {
								fileInfo: this.currentFileInfo,
							}),
						})
						this.vueApp.config.globalProperties.t = translate
						this.vueApp.config.globalProperties.n = translatePlural
						this.vueApp.mount(mountEl)
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

// Register the bulk metadata action
registerBulkMetadataAction()

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
