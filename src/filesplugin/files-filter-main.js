/**
 * MetaVox Files Filter Plugin
 * Adds metadata filtering capabilities to Nextcloud Files app
 */

import Vue from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import FilesFilterPanel from './FilesFilterPanel.vue'

let filterInstance = null
let currentGroupfolderId = null
let filterContainerElement = null

/**
 * Get current path from URL or Files app
 */
function getCurrentPath() {
	// Try to get from OCA.Files if available
	if (window.OCA?.Files?.App?.fileList?.getCurrentDirectory) {
		return window.OCA.Files.App.fileList.getCurrentDirectory()
	}

	// Fallback: parse from URL
	const urlParams = new URLSearchParams(window.location.search)
	const dir = urlParams.get('dir')
	if (dir) {
		return dir
	}

	// Last fallback: check URL path
	const pathMatch = window.location.pathname.match(/\/apps\/files\/?(.*)/)
	if (pathMatch && pathMatch[1]) {
		return '/' + pathMatch[1]
	}

	return '/'
}

/**
 * Detect groupfolder ID from current path
 */
async function detectGroupfolder(path) {
	try {
		// Get all groupfolders
		const response = await axios.get(generateUrl('/apps/metavox/api/groupfolders'))
		const groupfolders = Object.values(response.data || {})

		// Check if current path is in a groupfolder
		for (const gf of groupfolders) {
			const mountPoint = '/' + gf.mount_point
			if (path.startsWith(mountPoint + '/') || path === mountPoint) {
				return gf.id
			}
		}

		return null
	} catch (error) {
		console.error('MetaVox Filter: Error detecting groupfolder:', error)
		return null
	}
}

/**
 * Show filter panel
 */
function showFilterPanel(container, groupfolderId, currentPath) {

	// Remove existing instance if any
	if (filterInstance) {
		filterInstance.$destroy()
		filterInstance = null
	}

	// Clear container
	container.innerHTML = ''

	// Create a mount point inside the container
	const mountPoint = document.createElement('div')
	container.appendChild(mountPoint)

	// Create Vue instance
	const FilterPanel = Vue.extend(FilesFilterPanel)
	filterInstance = new FilterPanel({
		propsData: {
			groupfolderId,
			currentPath,
		},
	})

	// Mount to the mount point
	filterInstance.$mount(mountPoint)

	// Listen for filter events
	filterInstance.$on('filter-applied', async (filters) => {
		await applyFilters(groupfolderId, filters, currentPath)
	})

	filterInstance.$on('filters-cleared', () => {
		reloadFileList()
	})

	filterInstance.$on('close-panel', () => {
		hideFilterPanel()
	})

	currentGroupfolderId = groupfolderId
}

/**
 * Hide filter panel
 */
function hideFilterPanel() {

	if (filterInstance) {
		filterInstance.$destroy()
		filterInstance = null
	}

	// Hide the filter panel container using the saved reference
	if (filterContainerElement) {
		filterContainerElement.style.display = 'none'
		filterContainerElement.innerHTML = ''
	}

	// Update global state
	if (typeof window.metavoxFilterPanelVisible !== 'undefined') {
		window.metavoxFilterPanelVisible = false
	}

	// Remove body class
	document.body.classList.remove('metavox-filter-active')

	// Show filter button again
	const filterButton = document.getElementById('metavox-filter-toggle')
	if (filterButton) {
		filterButton.style.display = 'inline-flex'
	}

	// Clear all filtered states
	reloadFileList()
}

/**
 * Close filter panel when NC sidebar opens
 */
function handleSidebarConflict() {
	const sidebar = document.querySelector('#app-sidebar')
	const filterPanelOpen = window.metavoxFilterPanelVisible
	const ncSidebarOpen = sidebar && !sidebar.classList.contains('disappear')

	// If NC sidebar opens while filter panel is open, close filter panel
	if (filterPanelOpen && ncSidebarOpen) {
		hideFilterPanel()
	}
}

/**
 * Apply filters and update file list
 */
async function applyFilters(groupfolderId, filters, path) {
	try {

		const response = await axios.post(
			generateUrl('/apps/metavox/api/groupfolders/{groupfolderId}/filter', {
				groupfolderId,
			}),
			{
				filters,
				path,
			},
		)

		// Log debug information from backend
		console.group('🔍 MetaVox Filter: Response Received')

		if (response.data.debug) {
			console.group('🔧 DEBUG INFO FROM BACKEND')

			if (response.data.debug.filter_conditions_built) {
				console.group('📋 Filter Conditions Built')
				response.data.debug.filter_conditions_built.forEach((condition, index) => {
				})
				console.groupEnd()
			}

			if (response.data.debug.sql) {
				console.group('💾 SQL Query')
				console.groupEnd()
			}


			if (response.data.debug.message) {
			}
			console.groupEnd()
		}

		const filteredFiles = response.data.files || []
		console.groupEnd()

		// Update the file list view
		updateFileListView(filteredFiles)
	} catch (error) {
		console.error('MetaVox Filter: Error applying filters:', error)
		if (error.response?.data?.trace) {
			console.error('Backend trace:', error.response.data.trace)
		}
		OC.Notification.showTemporary(t('metavox', 'Failed to apply filters'), { type: 'error' })
	}
}

/**
 * Update the file list view with filtered results
 */
function updateFileListView(filteredFiles) {

	// Find and update the Vue file list component directly
	const appContentVue = document.querySelector('#app-content-vue')
	if (appContentVue && appContentVue.__vue__) {
		const vueInstance = appContentVue.__vue__

		// Find the file list component
		function findFileListComponent(component) {
			if (component.$options?.name === 'FilesList' || component.$options?.name === 'FilesListVirtual') {
				return component
			}
			if (component.$children) {
				for (const child of component.$children) {
					const found = findFileListComponent(child)
					if (found) return found
				}
			}
			return null
		}

		const fileListComponent = findFileListComponent(vueInstance)
		if (fileListComponent) {

			// Get current files
			const currentFiles = fileListComponent.$props?.nodes || fileListComponent.nodes || []

			// Store original files for restoration if not already stored
			if (!fileListComponent._metavoxOriginalNodes) {
				fileListComponent._metavoxOriginalNodes = currentFiles
			}

			// Transform filtered files to Nextcloud file node format
			const transformedFiles = filteredFiles.map(file => {
				// Find matching original node to preserve all properties
				const originalNode = currentFiles.find(n => n.fileid === file.id)

				if (originalNode) {
					// Return original node if found
					return originalNode
				} else {
					// Create new node with basic properties
					return {
						fileid: file.id,
						basename: file.name,
						filename: file.path,
						type: file.type === 'dir' ? 'folder' : 'file',
						mime: file.mimetype || 'application/octet-stream',
						size: file.size,
						mtime: file.mtime * 1000,
						permissions: file.permissions,
						etag: file.id.toString(),
					}
				}
			})


			// Update the nodes - try multiple approaches
			let updateSuccess = false

			try {
				// Approach 1: Try to use Vuex/Pinia store
				if (window.OCA?.Files?.$store || fileListComponent.$store) {
					const store = window.OCA.Files.$store || fileListComponent.$store

					// Try to commit a mutation or dispatch an action to update files
					if (store.state?.files) {
						store.state.files = transformedFiles
						updateSuccess = true
					}
				}

				// Approach 2: Update props by finding the actual data owner
				// The FilesListVirtual receives nodes as prop from FilesBrowser
				// FilesBrowser likely gets it from a view or router
				if (!updateSuccess) {
					const filesBrowser = fileListComponent.$parent
					if (filesBrowser) {

						// Try to find currentView or similar
						if (filesBrowser.currentView) {
						}

						// Check if FilesBrowser has a method to update files
						if (typeof filesBrowser.updateNodes === 'function') {
							filesBrowser.updateNodes(transformedFiles)
							updateSuccess = true
						}
					}
				}

				// Approach 3: Hide files that don't match the filter
				// Use CSS class to hide rows while preserving DOM structure for menus
				if (!updateSuccess) {

					// Get all file IDs from filtered results
					const filteredFileIds = new Set(filteredFiles.map(f => f.id))

					// Find all file rows in the DOM
					const fileRows = document.querySelectorAll('[data-cy-files-list-row]')

					let hiddenCount = 0
					fileRows.forEach(row => {
						// Extract file ID from the row (it's usually in data attributes or aria-label)
						const fileId = row.getAttribute('data-cy-files-list-row-fileid')

						if (fileId && !filteredFileIds.has(parseInt(fileId))) {
							// Add CSS class to hide the row
							row.classList.add('metavox-filtered-hidden')
							hiddenCount++
						} else if (fileId) {
							// Ensure matching files are visible
							row.classList.remove('metavox-filtered-hidden')
						}
					})

					updateSuccess = true
				}
			} catch (e) {
				console.error('❌ Failed to update component:', e)
			}

			// If update was successful, show success notification and return
			if (updateSuccess) {
				OC.Notification.showTemporary(
					t('metavox', 'Showing {count} filtered files', { count: filteredFiles.length }),
					{ type: 'success' },
				)
				return
			}

			console.warn('⚠️ Could not find a way to update files')
		} else {
		}
	} else {
	}

	// Fallback: If Vue update failed, show overlay
	showFilterResultsOverlay(filteredFiles)
	OC.Notification.showTemporary(
		t('metavox', 'Found {count} matching files', { count: filteredFiles.length }),
		{ type: 'warning' },
	)
}

/**
 * Show filtered results in an overlay
 */
function showFilterResultsOverlay(filteredFiles) {
	// Remove existing overlay
	const existingOverlay = document.querySelector('.metavox-filter-results-overlay')
	if (existingOverlay) {
		existingOverlay.remove()
	}

	// Create overlay
	const overlay = document.createElement('div')
	overlay.className = 'metavox-filter-results-overlay'
	overlay.innerHTML = `
		<div class="metavox-filter-results-header">
			<h3>Filtered Results (${filteredFiles.length} files)</h3>
			<button class="close-overlay" title="Close">
				<svg width="20" height="20" viewBox="0 0 20 20">
					<path fill="currentColor" d="M10 8.586L2.929 1.515 1.515 2.929 8.586 10l-7.071 7.071 1.414 1.414L10 11.414l7.071 7.071 1.414-1.414L11.414 10l7.071-7.071-1.414-1.414L10 8.586z"/>
				</svg>
			</button>
		</div>
		<div class="metavox-filter-results-list">
			${filteredFiles.length === 0
				? '<p class="empty-message">No files match your filters</p>'
				: filteredFiles.map(file => `
					<div class="filter-result-item" data-file-id="${file.id}">
						<div class="file-icon">
							${getFileIcon(file.mimetype, file.type)}
						</div>
						<div class="file-info">
							<div class="file-name">${escapeHtml(file.name)}</div>
							<div class="file-path">${escapeHtml(file.path)}</div>
						</div>
						<div class="file-metadata">
							${Object.entries(file.metadata || {}).map(([key, value]) => `
								<span class="metadata-tag">${escapeHtml(key)}: ${escapeHtml(value)}</span>
							`).join('')}
						</div>
					</div>
				`).join('')
			}
		</div>
	`

	// Add close handler
	const closeBtn = overlay.querySelector('.close-overlay')
	closeBtn.addEventListener('click', () => {
		overlay.remove()
	})

	// Add to page
	document.body.appendChild(overlay)
}

/**
 * Get file icon based on mimetype
 */
function getFileIcon(mimetype, type) {
	if (type === 'dir') {
		return '<svg width="32" height="32" viewBox="0 0 32 32"><path fill="#0082c9" d="M2 6v20c0 1.1.9 2 2 2h24c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2h-12l-2-2H4c-1.1 0-2 .9-2 2z"/></svg>'
	}

	// Simple file icon
	return '<svg width="32" height="32" viewBox="0 0 32 32"><path fill="#555" d="M6 2c-1.1 0-2 .9-2 2v24c0 1.1.9 2 2 2h20c1.1 0 2-.9 2-2V8l-6-6H6z"/><path fill="#fff" opacity="0.3" d="M22 2l6 6h-6V2z"/></svg>'
}

/**
 * Escape HTML to prevent XSS
 */
function escapeHtml(text) {
	const div = document.createElement('div')
	div.textContent = text
	return div.innerHTML
}

/**
 * Reload original file list (clear filters)
 */
function reloadFileList() {

	// Remove all hidden files (CSS approach)
	const hiddenRows = document.querySelectorAll('.metavox-filtered-hidden')

	hiddenRows.forEach(row => {
		row.classList.remove('metavox-filtered-hidden')
	})

	// Also remove any overlay if present
	const overlay = document.querySelector('.metavox-filter-results-overlay')
	if (overlay) {
		overlay.remove()
	}

	OC.Notification.showTemporary(t('metavox', 'Filters cleared'), { type: 'success' })
}

/**
 * Register filter button in Files app header
 */
function registerFilterButton() {

	// Check what's available in OCA.Files

	// Debug: log all available IDs
	const allIds = Array.from(document.querySelectorAll('[id]')).map(el => el.id)

	// Look for various possible control locations
	// Prioritize app-content-vue (main files content area) for floating button
	const possibleSelectors = [
		'#app-content-vue',  // Main files content area - best for floating button
		'.app-content',
		'#controls',
		'#app-navigation',
		'.app-files-header',
		'[data-cy-files-content-breadcrumbs]',
		'.files-controls',
	]

	// Wait for Files app to be ready (more flexible approach)
	let attempts = 0
	const maxAttempts = 100 // 10 seconds
	const checkFilesApp = setInterval(() => {
		attempts++

		// Try to find ANY suitable container
		let targetContainer = null
		for (const selector of possibleSelectors) {
			const element = document.querySelector(selector)
			if (element) {
				targetContainer = element
				break
			}
		}

		if (attempts % 10 === 0) {
		}

		if (targetContainer) {
			clearInterval(checkFilesApp)

			// Create filter button container
			const filterContainer = document.createElement('div')
			filterContainer.id = 'metavox-filter-container'
			filterContainer.style.display = 'none'
			filterContainer.className = 'metavox-filter-wrapper'

			// Save reference globally
			filterContainerElement = filterContainer

			// Create filter toggle button
			const filterButton = document.createElement('button')
			filterButton.id = 'metavox-filter-toggle'
			filterButton.className = 'button'
			filterButton.style.display = 'none' // Hidden by default, shown when in groupfolder
			filterButton.innerHTML = `
				<svg width="16" height="16" viewBox="0 0 24 24">
					<path fill="currentColor" d="M14,12V19.88C14.04,20.18 13.94,20.5 13.71,20.71C13.32,21.1 12.69,21.1 12.3,20.71L10.29,18.7C10.06,18.47 9.96,18.16 10,17.87V12H9.97L4.21,4.62C3.87,4.19 3.95,3.56 4.38,3.22C4.57,3.08 4.78,3 5,3V3H19V3C19.22,3 19.43,3.08 19.62,3.22C20.05,3.56 20.13,4.19 19.79,4.62L14.03,12H14Z" />
				</svg>
				<span>${t('metavox', 'Filter')}</span>
			`
			filterButton.title = t('metavox', 'Filter files by metadata')


			// Add button click handler
			window.metavoxFilterPanelVisible = false

			filterButton.addEventListener('click', () => {
				window.metavoxFilterPanelVisible = !window.metavoxFilterPanelVisible
				filterContainer.style.display = window.metavoxFilterPanelVisible ? 'block' : 'none'

				// Show/hide filter button when panel opens/closes
				filterButton.style.display = window.metavoxFilterPanelVisible ? 'none' : 'inline-flex'

				// Add/remove body class for CSS styling
				if (window.metavoxFilterPanelVisible) {
					document.body.classList.add('metavox-filter-active')
				} else {
					document.body.classList.remove('metavox-filter-active')
				}

				if (window.metavoxFilterPanelVisible && currentGroupfolderId) {
					const currentPath = getCurrentPath()
					showFilterPanel(filterContainer, currentGroupfolderId, currentPath)
				} else if (!window.metavoxFilterPanelVisible) {
					hideFilterPanel()
				}
			})

			// Watch for Nextcloud sidebar changes to auto-close filter
			const sidebarObserver = new MutationObserver(() => {
				handleSidebarConflict()
			})

			// Observe the app-sidebar element for visibility changes
			const observeSidebar = () => {
				const sidebar = document.querySelector('#app-sidebar')
				if (sidebar) {
					sidebarObserver.observe(sidebar, {
						attributes: true,
						attributeFilter: ['class', 'style'],
					})
				}
			}

			// Start observing after a short delay to ensure DOM is ready
			setTimeout(observeSidebar, 1000)

			// Add button and panel separately to Files app
			// Button stays in fixed position
			const buttonWrapper = document.createElement('div')
			buttonWrapper.className = 'metavox-filter-button-wrapper'
			buttonWrapper.appendChild(filterButton)

			// Panel container (already has its own positioning)
			targetContainer.appendChild(buttonWrapper)
			targetContainer.appendChild(filterContainer)

			// Listen for URL changes (navigation in Files app)
			const handlePathChange = async () => {
				const newPath = getCurrentPath()
				const groupfolderId = await detectGroupfolder(newPath)

				// Show/hide filter button based on groupfolder context
				if (groupfolderId) {
					filterButton.style.display = 'inline-flex'
					currentGroupfolderId = groupfolderId

					// Update filter panel if visible
					if (filterPanelVisible && filterInstance) {
						filterInstance.groupfolderId = groupfolderId
						filterInstance.currentPath = newPath
					}
				} else {
					filterButton.style.display = 'none'
					filterContainer.style.display = 'none'
					filterPanelVisible = false
					hideFilterPanel()
				}
			}

			// Listen for popstate (browser back/forward)
			window.addEventListener('popstate', handlePathChange)

			// Listen for OCA.Files events if available
			if (window.OCA?.Files?.App?.fileList?.$el) {
				window.OCA.Files.App.fileList.$el.on('changeDirectory', handlePathChange)
			}

			// Use MutationObserver to watch for URL changes
			let lastPath = getCurrentPath()
			const pathObserver = setInterval(() => {
				const currentPath = getCurrentPath()
				if (currentPath !== lastPath) {
					lastPath = currentPath
					handlePathChange()
				}
			}, 500)

			// Check initial path
			const initialPath = getCurrentPath()
			detectGroupfolder(initialPath).then(groupfolderId => {
				if (groupfolderId) {
					filterButton.style.display = 'inline-flex'
					currentGroupfolderId = groupfolderId
				} else {
				}
			})

		} else if (attempts >= maxAttempts) {
			clearInterval(checkFilesApp)
			console.error('❌ MetaVox Filter: Timeout waiting for Files app after', attempts, 'attempts')
		}
	}, 100)
}

/**
 * Initialize the filter plugin
 */
function init() {

	// Wait for DOM to be ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', registerFilterButton)
	} else {
		registerFilterButton()
	}
}

// Start the plugin
init()

// Add CSS
const style = document.createElement('style')
style.textContent = `
	.metavox-filter-button-wrapper {
		position: fixed;
		top: 100px;
		right: 20px;
		z-index: 2000;
		display: flex;
		flex-direction: column;
		align-items: flex-end;
		gap: 8px;
	}

	#metavox-filter-toggle {
		display: inline-flex;
		align-items: center;
		gap: 8px;
		padding: 12px 20px;
		background: var(--color-primary-element);
		color: var(--color-primary-element-text);
		border: none;
		position: relative;
		z-index: 2000;
		border-radius: var(--border-radius-large);
		cursor: pointer;
		font-weight: 600;
		font-size: 14px;
		transition: all 0.2s;
		box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
	}

	#metavox-filter-toggle:hover {
		background: var(--color-primary-element-hover);
		box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
		transform: translateY(-1px);
	}

	#metavox-filter-toggle svg {
		width: 18px;
		height: 18px;
	}

	.metavox-filter-wrapper {
		width: 360px;
		min-width: 360px;
		max-width: 90vw;
		height: 100vh;
		overflow-y: auto;
		overflow-x: hidden;
		z-index: 1998;
		background: rgba(255, 255, 255, 0.98);
		backdrop-filter: blur(10px);
		border-left: 1px solid var(--color-border);
		box-shadow: -2px 0 16px rgba(0, 0, 0, 0.15);
		position: fixed;
		right: 0;
		top: 50px;
		transition: right 0.3s ease;
		pointer-events: auto;
	}

	/* When filter is open, add padding to file list to prevent overlap with filter panel */
	body.metavox-filter-active .files-list__table {
		padding-right: 380px !important;
	}

	body.metavox-filter-active main.app-content {
		overflow-x: hidden !important;
	}

	/* Ensure filter controls are vertical */
	.metavox-filter-panel .filter-controls {
		display: flex;
		flex-direction: column !important;
		gap: 8px;
		width: 100%;
	}

	.metavox-filter-panel .filter-operator,
	.metavox-filter-panel .filter-value {
		width: 100% !important;
	}

	@media (max-width: 768px) {
		.metavox-filter-button-wrapper {
			position: fixed;
			bottom: 20px;
			right: 20px;
			top: auto;
		}

		.metavox-filter-wrapper {
			position: fixed;
			bottom: 80px;
			right: 20px;
			width: calc(100vw - 40px);
			max-height: 70vh;
		}
	}

	/* Filter Results Overlay */
	.metavox-filter-results-overlay {
		position: fixed;
		top: 50%;
		left: 50%;
		transform: translate(-50%, -50%);
		width: 90%;
		max-width: 800px;
		max-height: 80vh;
		background: var(--color-main-background);
		border-radius: var(--border-radius-large);
		box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
		z-index: 10000;
		display: flex;
		flex-direction: column;
		overflow: hidden;
	}

	.metavox-filter-results-header {
		display: flex;
		justify-content: space-between;
		align-items: center;
		padding: 20px;
		border-bottom: 1px solid var(--color-border);
		background: var(--color-background-dark);
	}

	.metavox-filter-results-header h3 {
		margin: 0;
		font-size: 18px;
		font-weight: 600;
		color: var(--color-main-text);
	}

	.close-overlay {
		background: transparent;
		border: none;
		padding: 8px;
		cursor: pointer;
		border-radius: var(--border-radius);
		display: flex;
		align-items: center;
		justify-content: center;
		transition: background 0.2s;
	}

	.close-overlay:hover {
		background: var(--color-background-hover);
	}

	.metavox-filter-results-list {
		overflow-y: auto;
		padding: 20px;
		flex: 1;
	}

	.empty-message {
		text-align: center;
		color: var(--color-text-lighter);
		padding: 40px 20px;
		font-size: 16px;
	}

	.filter-result-item {
		display: flex;
		align-items: flex-start;
		gap: 16px;
		padding: 16px;
		margin-bottom: 12px;
		background: var(--color-background-hover);
		border-radius: var(--border-radius);
		transition: background 0.2s;
		cursor: pointer;
	}

	.filter-result-item:hover {
		background: var(--color-primary-element-light);
	}

	.file-icon {
		flex-shrink: 0;
	}

	.file-info {
		flex: 1;
		min-width: 0;
	}

	.file-name {
		font-weight: 600;
		color: var(--color-main-text);
		margin-bottom: 4px;
		word-break: break-word;
	}

	.file-path {
		font-size: 12px;
		color: var(--color-text-lighter);
		word-break: break-all;
	}

	.file-metadata {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
		margin-top: 8px;
	}

	.metadata-tag {
		display: inline-block;
		padding: 4px 8px;
		background: var(--color-primary-element-light);
		color: var(--color-primary-element-text);
		border-radius: var(--border-radius);
		font-size: 11px;
		font-weight: 500;
	}

	/* Hide filtered files - use display: none as it's the most reliable */
	[data-cy-files-list-row].metavox-filtered-hidden {
		display: none !important;
	}

	/* Ensure Nextcloud details sidebar stays on top */
	#app-sidebar {
		z-index: 2500 !important;
	}

	/* Ensure file action menus (three dots) stay on top of filter panel */
	div[id^="popper_"],
	.v-popper__popper,
	.v-popper__wrapper,
	.action-item__popper,
	.files-list__row-actions,
	.files-list__row-actions-batch,
	[class*="action-menu"],
	[data-popper-placement] {
		z-index: 2100 !important;
	}

	/* Also ensure the actions button/icon itself is on top */
	.action-item,
	.action-item--open,
	.action-item__menutoggle,
	.files-list__row-actions-button,
	button[aria-label="Actions"],
	button[aria-label*="actions"],
	.button-vue[aria-label="Actions"] {
		position: relative !important;
		z-index: 2100 !important;
	}
`
document.head.appendChild(style)
