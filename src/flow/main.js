/**
 * MetaVox Flow registration
 *
 * Registers the MetaVox metadata check with Nextcloud's Workflow Engine.
 * This enables metadata-based conditions in Flow rules for access control.
 */

import MetadataCheck from './MetadataCheck.vue'

// Wait for the WorkflowEngine to be ready
window.addEventListener('DOMContentLoaded', () => {
	// Check if WorkflowEngine is available
	if (typeof OCA !== 'undefined' && OCA.WorkflowEngine) {
		registerFlowComponents()
	} else {
		// WorkflowEngine might load later, wait for it
		const checkInterval = setInterval(() => {
			if (typeof OCA !== 'undefined' && OCA.WorkflowEngine) {
				clearInterval(checkInterval)
				registerFlowComponents()
			}
		}, 100)

		// Stop checking after 10 seconds
		setTimeout(() => clearInterval(checkInterval), 10000)
	}
})

function registerFlowComponents() {
	/**
	 * Register the MetaVox check with Nextcloud's Workflow Engine
	 *
	 * We use a single generic operator here. The actual operator selection
	 * is handled within the Vue component based on field type.
	 * The selected operator is stored in the config JSON.
	 */
	OCA.WorkflowEngine.registerCheck({
		class: 'OCA\\MetaVox\\Flow\\MetadataCheck',
		name: t('metavox', 'MetaVox metadata'),
		operators: [
			// Single generic operator - actual operator is selected in the component
			{ operator: 'matches', name: t('metavox', 'where') },
		],
		component: MetadataCheck,
	})

	console.log('MetaVox Flow check registered')
}
