/**
 * Ask before something that cannot be undone, in Nextcloud's own dialog
 * (NcDialog), as @nextcloud/dialogs' showConfirmation shows it: the parts
 * that ask already have NcDialog, @nextcloud/dialogs would add about 280 KB.
 */

import { createApp, h } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { watchLoading } from './updatedPage.js'

/**
 * @param {{name: string, text: string, labelConfirm: string, labelReject: string}} options
 * @return {Promise<boolean>} whether it was confirmed
 */
export function showConfirmation({ name, text, labelConfirm, labelReject }) {
	return new Promise((resolve) => {
		const host = document.createElement('div')
		document.body.appendChild(host)
		const app = createApp({
			render: () => h(NcDialog, {
				name,
				message: text,
				open: true,
				buttons: [
					{ label: labelReject, callback: () => 'reject' },
					// The action named on its button, not "OK".
					{ label: labelConfirm, variant: 'primary', callback: () => 'confirm' },
				],
				// The answer of the button that closed it; none for Escape or the cross.
				onClosing: (result) => resolve(result === 'confirm'),
				'onUpdate:open': (open) => {
					if (!open) {
						setTimeout(() => {
							app.unmount()
							host.remove()
						})
					}
				},
			}),
		})
		watchLoading(app).mount(host)
	})
}
