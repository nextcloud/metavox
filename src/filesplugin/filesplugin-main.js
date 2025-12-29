/**
 * MetaVox Files Plugin - Vue Version
 * Registers metadata sidebar tab in Nextcloud Files app
 */

import Vue from 'vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import FilesSidebarTab from './FilesSidebarTab.vue'
import { registerBulkMetadataAction } from './BulkMetadataAction.js'

// Register translations globally for Vue components
Vue.prototype.t = translate
Vue.prototype.n = translatePlural

/**
 * Register the sidebar tab
 */
function registerMetadataTab() {
	// Prevent duplicate registration
	if (window._metavoxTabRegistered) {
		return
	}
	window._metavoxTabRegistered = true

	window.OCA.Files.Sidebar.registerTab(new window.OCA.Files.Sidebar.Tab({
		id: 'metavox-metadata',
		name: 'MetaVox',
		iconSvg: '<svg version="1.1" xmlns="http://www.w3.org/2000/svg" width="24" height="24"><path d="M0 0 C2.45685425 0.42359556 3.6964912 0.65510363 5.375 2.5625 C7.01309099 4.27469613 7.01309099 4.27469613 9.9375 4.4375 C13.65445502 3.90650643 14.5402558 2.7142005 17 0 C17.99 0 18.98 0 20 0 C20.93405225 4.35891051 20.81144268 7.63849557 20 12 C20.33 12.33 20.66 12.66 21 13 C21.04080783 14.99958364 21.04254356 17.00045254 21 19 C19.576875 19.680625 19.576875 19.680625 18.125 20.375 C14.99686321 21.80238254 14.99686321 21.80238254 13 24 C9.12472131 24.51670383 7.52342441 24.37735248 4.3125 22.0625 C3.549375 21.381875 2.78625 20.70125 2 20 C1.01 19.67 0.02 19.34 -1 19 C-1.125 13.25 -1.125 13.25 0 11 C-0.25556108 8.98745646 -0.51448107 6.97446167 -0.84375 4.97265625 C-1 3 -1 3 0 0 Z " fill="currentColor" transform="translate(2,0)"/></svg>',

		async mount(el, fileInfo, context) {
			// Remove any existing instance
			if (this.vueInstance) {
				this.vueInstance.$destroy()
			}

			// Create Vue instance
			const View = Vue.extend(FilesSidebarTab)
			this.vueInstance = new View({
				propsData: {
					fileInfo,
				},
			}).$mount(el)
		},

		update(fileInfo) {
			// Update the file info prop
			if (this.vueInstance) {
				this.vueInstance.fileInfo = fileInfo
			}
		},

		destroy() {
			// Clean up Vue instance
			if (this.vueInstance) {
				this.vueInstance.$destroy()
				this.vueInstance = null
			}
		},

		// Optional: Show/hide conditions
		enabled(fileInfo) {
			// Show for both files and folders
			return fileInfo?.type === 'file' || fileInfo?.type === 'dir'
		},
	}))
}

/**
 * Wait for Files app to be ready
 */
function waitForFilesApp() {
	// Check if already available
	if (window.OCA?.Files?.Sidebar) {
		registerMetadataTab()
		return
	}

	// Wait for DOMContentLoaded
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => {
			waitForFilesApp()
		})
		return
	}

	// Poll for Files app with timeout
	let attempts = 0
	const maxAttempts = 50 // 5 seconds (50 * 100ms)

	const checkInterval = setInterval(() => {
		attempts++

		if (window.OCA?.Files?.Sidebar) {
			clearInterval(checkInterval)
			registerMetadataTab()
		} else if (attempts >= maxAttempts) {
			clearInterval(checkInterval)
			console.error('❌ MetaVox Files Plugin - Timeout waiting for Files Sidebar API')
		}
	}, 100)
}

// Start waiting for Files app
waitForFilesApp()

// Register the bulk metadata action
registerBulkMetadataAction()
