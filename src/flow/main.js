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
	 * The 'class' must match the fully qualified class name of the PHP Check class.
	 * This enables metadata-based conditions in Flow rules, such as:
	 * - Block access if metadata field 'classification' = 'confidential'
	 * - Allow download only if 'status' = 'approved'
	 */
	OCA.WorkflowEngine.registerCheck({
		class: 'OCA\\MetaVox\\Flow\\MetadataCheck',
		name: t('metavox', 'MetaVox metadata'),
		operators: [
			{ operator: 'is', name: t('metavox', 'is') },
			{ operator: '!is', name: t('metavox', 'is not') },
			{ operator: 'contains', name: t('metavox', 'contains') },
			{ operator: '!contains', name: t('metavox', 'does not contain') },
			{ operator: 'matches', name: t('metavox', 'matches regex') },
			{ operator: '!matches', name: t('metavox', 'does not match regex') },
		],
		component: MetadataCheck,
	})

	console.log('MetaVox Flow check registered')
}
