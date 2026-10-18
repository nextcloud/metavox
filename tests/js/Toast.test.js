// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

// Nextcloud's own toasts: what every page has, so the Files script needs no
// @nextcloud/dialogs.
const toast = await import('../../src/utils/toast.js')

let shown
beforeEach(() => {
	shown = []
	window.OCP = { Toast: {
		error: vi.fn(), warning: vi.fn(), success: vi.fn(),
		message: vi.fn((node, options) => {
			shown.push({ node, options })
			return { hideToast: vi.fn() }
		}),
	} }
	window.t = (app, text) => text
})

afterEach(() => {
	delete window.OCP
	delete window.t
})

describe('the toasts', () => {
	it('pass a message on to Nextcloud', () => {
		toast.showError('Could not save')
		toast.showWarning('Only inside this team folder')
		toast.showSuccess('Saved')

		expect(window.OCP.Toast.error).toHaveBeenCalledWith('Could not save', undefined)
		expect(window.OCP.Toast.warning).toHaveBeenCalledWith('Only inside this team folder', undefined)
		expect(window.OCP.Toast.success).toHaveBeenCalledWith('Saved', undefined)
	})

	it('say nothing, and do not fail, where Nextcloud has no toasts', () => {
		delete window.OCP
		expect(() => toast.showError('x')).not.toThrow()
		expect(() => toast.showUndo('x', () => {}).hideToast()).not.toThrow()
	})

	it('offer an Undo button for ten seconds that undoes and closes', () => {
		const onUndo = vi.fn()
		const undo = toast.showUndo('Metadata updated', onUndo)
		const [{ node, options }] = shown
		const button = node.querySelector('button')

		expect(node.textContent).toBe('Metadata updated Undo')
		expect(options.timeout).toBe(10000)
		button.click()
		expect(onUndo).toHaveBeenCalledOnce()
		expect(window.OCP.Toast.message.mock.results[0].value.hideToast).toHaveBeenCalled()
		expect(typeof undo.hideToast).toBe('function')
	})

	it('closes the toast Nextcloud 32 gives back as well', () => {
		const hideToast = vi.fn()
		window.OCP.Toast.message = vi.fn(() => Object.assign(Object.create({ hideToast }), { options: {}, toastElement: {} }))

		toast.showUndo('Metadata updated', () => {}).hideToast()

		expect(hideToast).toHaveBeenCalled()
	})
})
