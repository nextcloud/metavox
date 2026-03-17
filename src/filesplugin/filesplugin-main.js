/**
 * MetaVox Files Plugin - Nextcloud 31-33 Compatible Version
 * Registers metadata sidebar tab in Nextcloud Files app
 */

// Dynamically set public path for chunk loading based on where this script is served from
// eslint-disable-next-line camelcase
__webpack_public_path__ = document.querySelector('script[src*="metavox/js/filesplugin"]')?.src?.replace(/filesplugin\.js.*$/, '') || '/apps/metavox/js/'

import { translate, translatePlural } from '@nextcloud/l10n'
import { registerBulkMetadataAction } from './BulkMetadataAction.js'
import { startColumnWatcher } from './columns/MetaVoxColumns.js'

// MetaVox icon SVG
const metavoxIconSvg = '<svg version="1.1" xmlns="http://www.w3.org/2000/svg" width="24" height="24"><path d="M0 0 C2.45685425 0.42359556 3.6964912 0.65510363 5.375 2.5625 C7.01309099 4.27469613 7.01309099 4.27469613 9.9375 4.4375 C13.65445502 3.90650643 14.5402558 2.7142005 17 0 C17.99 0 18.98 0 20 0 C20.93405225 4.35891051 20.81144268 7.63849557 20 12 C20.33 12.33 20.66 12.66 21 13 C21.04080783 14.99958364 21.04254356 17.00045254 21 19 C19.576875 19.680625 19.576875 19.680625 18.125 20.375 C14.99686321 21.80238254 14.99686321 21.80238254 13 24 C9.12472131 24.51670383 7.52342441 24.37735248 4.3125 22.0625 C3.549375 21.381875 2.78625 20.70125 2 20 C1.01 19.67 0.02 19.34 -1 19 C-1.125 13.25 -1.125 13.25 0 11 C-0.25556108 8.98745646 -0.51448107 6.97446167 -0.84375 4.97265625 C-1 3 -1 3 0 0 Z " fill="currentColor" transform="translate(2,0)"/></svg>'

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

		scope.filesSidebarTabs ??= new Map()

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
				const { h, createApp } = await import('vue')
				const FilesSidebarTab = (await import('./FilesSidebarTab.vue')).default

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

	try {
		const { createApp, h } = await import('vue')
		const FilesSidebarTab = (await import('./FilesSidebarTab.vue')).default

		window.OCA.Files.Sidebar.registerTab(new window.OCA.Files.Sidebar.Tab({
			id: 'metavox-metadata',
			name: 'MetaVox',
			iconSvg: metavoxIconSvg,

			async mount(el, fileInfo, context) {
				if (this.vueApp) {
					this.vueApp.unmount()
				}

				this.vueApp = createApp({
					render: () => h(FilesSidebarTab, {
						fileInfo: this.currentFileInfo || fileInfo,
					}),
				})

				this.vueApp.config.globalProperties.t = translate
				this.vueApp.config.globalProperties.n = translatePlural

				this.currentFileInfo = fileInfo
				this.vueInstance = this.vueApp.mount(el)
			},

			update(fileInfo) {
				this.currentFileInfo = fileInfo
				if (this.vueApp && this.vueInstance?.$el?.parentElement) {
					const el = this.vueInstance.$el.parentElement
					this.vueApp.unmount()

					this.vueApp = createApp({
						render: () => h(FilesSidebarTab, {
							fileInfo: this.currentFileInfo,
						}),
					})
					this.vueApp.config.globalProperties.t = translate
					this.vueApp.config.globalProperties.n = translatePlural
					this.vueInstance = this.vueApp.mount(el)
				}
			},

			destroy() {
				if (this.vueApp) {
					this.vueApp.unmount()
					this.vueApp = null
					this.vueInstance = null
				}
			},

			enabled(fileInfo) {
				return fileInfo?.type === 'file' || fileInfo?.type === 'dir'
			},
		}))

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
