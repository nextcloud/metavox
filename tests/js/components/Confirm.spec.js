import { afterEach, describe, expect, it } from 'vitest'
import { showConfirmation } from '@/utils/confirm.js'

// Nextcloud's own dialog (NcDialog) asks, as @nextcloud/dialogs' showConfirmation
// did. The stub stands in for NcDialog; its props are what the dialog gets.
const OPTIONS = { name: 'Delete view', text: 'Delete the view Werk?', labelConfirm: 'Delete view', labelReject: 'Cancel' }
const dialog = () => [...document.body.children].at(-1).__vue_app__._instance.subTree.props
const tick = () => new Promise((resolve) => setTimeout(resolve))

afterEach(() => {
	document.body.innerHTML = ''
})

describe('asking before something that cannot be undone', () => {
	it('names the action on its button, with Cancel beside it', () => {
		showConfirmation(OPTIONS)
		const props = dialog()

		expect(props.name).toBe('Delete view')
		expect(props.message).toBe('Delete the view Werk?')
		expect(props.buttons.map((b) => [b.label, b.variant])).toEqual([['Cancel', undefined], ['Delete view', 'primary']])
	})

	it('answers yes only for the button that names the action', async () => {
		const answer = showConfirmation(OPTIONS)
		const props = dialog()
		props.onClosing(props.buttons[1].callback())

		expect(await answer).toBe(true)
	})

	it('answers no for Cancel, and for Escape or the cross', async () => {
		const cancel = showConfirmation(OPTIONS)
		let props = dialog()
		props.onClosing(props.buttons[0].callback())
		expect(await cancel).toBe(false)

		const escape = showConfirmation(OPTIONS)
		props = dialog()
		props.onClosing(undefined)
		expect(await escape).toBe(false)
	})

	it('leaves nothing behind once closed', async () => {
		showConfirmation(OPTIONS)
		const before = document.body.children.length
		dialog()['onUpdate:open'](false)
		await tick()

		expect(document.body.children.length).toBe(before - 1)
	})
})
