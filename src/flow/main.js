/**
 * MetaVox Flow registration
 *
 * Registers the MetaVox metadata check with Nextcloud's Workflow Engine.
 * This enables metadata-based conditions in Flow rules for access control.
 */

import { translate as t } from '@nextcloud/l10n'
import { TAG_NAME } from './MetadataCheckElement.js'

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
		// TRANSLATORS Name of the check in the Flow (workflow) rule editor; MetaVox is the app name, do not translate it
		name: t('metavox', 'MetaVox metadata'),
		operators: [
			// TRANSLATORS Operator of the Flow check, read as "MetaVox metadata where <field> equals <value>"; keep it short
			{ operator: 'matches', name: t('metavox', 'where') },
		],
		element: TAG_NAME,
	})

}
