/**
 * MetaVox Flow registration
 *
 * Registers the MetaVox metadata check with Nextcloud's Workflow Engine.
 * This enables metadata-based conditions in Flow rules for access control.
 */

import MetadataCheck from './MetadataCheck.js'

const t = window.t || ((app, text) => text)

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
	OCA.WorkflowEngine.registerCheck({
		class: 'OCA\\MetaVox\\Flow\\MetadataCheck',
		name: t('metavox', 'MetaVox metadata'),
		operators: [
			{ operator: 'matches', name: t('metavox', 'where') },
		],
		component: MetadataCheck,
	})

	console.log('MetaVox Flow check registered')
}
