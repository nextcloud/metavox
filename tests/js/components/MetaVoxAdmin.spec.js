import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import MetaVoxAdmin from '@/components/MetaVoxAdmin.vue'
import axios from '@nextcloud/axios'
import * as dialogs from '@nextcloud/dialogs'

/**
 * The admin page shows one pane at a time, chosen from a pill row.
 *
 * Stacked, the seven concerns measured 15603px — 17 screens, with the last
 * section starting at 14938px — and Nextcloud ships no settings page close to
 * that. The one app in core with the same problem, user_ldap, answers it the
 * same way: a radio pill row over panes swapped with v-if, so only one pane is
 * in the DOM (LDAPSettingsApp.vue:42-61).
 *
 * These tests pin the part a later change could quietly undo: that exactly one
 * pane renders, that every pill reaches its pane, and that the choice survives
 * a reload.
 */
describe('MetaVoxAdmin', () => {
	beforeEach(() => {
		window.location.hash = ''
		try { window.localStorage.clear() } catch (e) { /* not available in every env */ }
	})
	afterEach(() => vi.restoreAllMocks())

	const paneStubs = {
		MetadataFieldsEditor: { name: 'MetadataFieldsEditor', props: ['scope'], template: '<div class="editor-stub" :data-scope="scope" />' },
		ManageGroupfolders: { name: 'ManageGroupfolders', template: '<div class="pane-stub" data-pane="teamfolders" />' },
		PermissionsManager: { name: 'PermissionsManager', template: '<div class="pane-stub" data-pane="permissions" />' },
		SupportSettings: { name: 'SupportSettings', template: '<div class="pane-stub" data-pane="support" />' },
		BackupRestore: { name: 'BackupRestore', template: '<div class="pane-stub" data-pane="backup" />' },
		AiSettings: { name: 'AiSettings', template: '<div class="pane-stub" data-pane="ai" />' },
		LinkSettings: { name: 'LinkSettings', template: '<div class="pane-stub" data-pane="links" />' },
		SearchSettings: { name: 'SearchSettings', template: '<div class="pane-stub" data-pane="search" />' },
	}

	const mountAdmin = async (stats = null) => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: stats ? { success: true, stats } : { success: false } })
		const wrapper = mount(MetaVoxAdmin, { global: { stubs: paneStubs } })
		await flushPromises()
		return wrapper
	}

	describe('the pane switcher', () => {
		it('offers one pill per concern', async () => {
			const w = await mountAdmin()
			expect(w.vm.panes.map((p) => p.id)).toEqual([
				'teamfolder-metadata',
				'file-metadata',
				'teamfolders',
				'search',
				'general',
				'permissions',
				'backup',
				'support',
			])
		})

		it('keeps the row short enough to fit', async () => {
			// A horizontal pill group has no flex-wrap and the grouped variant
			// cancels the ellipsis rule, so a row that does not fit overflows
			// rather than wrapping. Below 660px the row wraps instead — but
			// the labels still have to stay short.
			const w = await mountAdmin()
			expect(w.vm.panes.length).toBeLessThanOrEqual(8)
			for (const p of w.vm.panes) {
				expect(p.name.length, `"${p.name}" is long for a pill`).toBeLessThanOrEqual(16)
			}
		})

		it('names the group, so the row is not an unlabelled set of radios', async () => {
			// The deep-import stub renders every Nc* as one generic component, so
			// this asserts the label reaches the DOM rather than looking the
			// component up by name.
			const w = await mountAdmin()
			expect(w.html()).toContain('metavox-admin__panes')
			expect(w.find('.metavox-admin__panes').attributes('label')).toBeTruthy()
		})
	})

	describe('one pane at a time', () => {
		it('shows only the selected pane, not all eight', async () => {
			const w = await mountAdmin()
			// One editor for the default pane, and no other pane mounted.
			expect(w.findAll('.editor-stub')).toHaveLength(1)
			expect(w.findAll('.pane-stub')).toHaveLength(0)
		})

		it('reaches every pane', async () => {
			const w = await mountAdmin()
			for (const p of w.vm.panes) {
				await w.setData({ pane: p.id })
				const rendered = w.findAll('.editor-stub').length + w.findAll('.pane-stub').length
				expect(rendered, `pane "${p.id}" renders nothing`).toBeGreaterThan(0)
			}
		})

		it('holds the link settings and AI under General', async () => {
			const w = await mountAdmin()
			await w.setData({ pane: 'general' })
			expect(w.findAll('.pane-stub').map((stub) => stub.attributes('data-pane'))).toEqual(['links', 'ai'])
		})

		it('passes the right scope to each metadata editor', async () => {
			const w = await mountAdmin()
			await w.setData({ pane: 'teamfolder-metadata' })
			expect(w.find('.editor-stub').attributes('data-scope')).toBe('teamfolder')
			await w.setData({ pane: 'file-metadata' })
			expect(w.find('.editor-stub').attributes('data-scope')).toBe('file')
		})

		it('has no tab row of bare buttons', async () => {
			const w = await mountAdmin()
			expect(w.find('.tab-navigation').exists()).toBe(false)
			expect(w.find('.tab-button').exists()).toBe(false)
		})
	})

	describe('remembering the pane', () => {
		it('stores the choice so a reload does not go back to the first pane', async () => {
			const w = await mountAdmin()
			await w.setData({ pane: 'backup' })
			expect(window.localStorage.getItem('metavox-admin-pane')).toBe('backup')
		})

		it('restores a stored pane on mount', async () => {
			window.localStorage.setItem('metavox-admin-pane', 'backup')
			const w = await mountAdmin()
			expect(w.vm.pane).toBe('backup')
		})

		it('lets the URL fragment win, so a link to a pane works', async () => {
			window.localStorage.setItem('metavox-admin-pane', 'backup')
			window.location.hash = '#permissions'
			const w = await mountAdmin()
			expect(w.vm.pane).toBe('permissions')
		})

		it('opens General for the AI pane it replaced', async () => {
			window.localStorage.setItem('metavox-admin-pane', 'ai')
			expect((await mountAdmin()).vm.pane).toBe('general')
			window.location.hash = '#ai'
			expect((await mountAdmin()).vm.pane).toBe('general')
		})

		it('falls back when a removed pane is still stored', async () => {
			// "statistics" was a pane until its counts moved to Support. An
			// administrator who last opened it has the id in localStorage, and
			// it must not leave the page showing nothing.
			window.localStorage.setItem('metavox-admin-pane', 'statistics')
			const w = await mountAdmin()
			expect(w.vm.pane).toBe('teamfolder-metadata')
			expect(w.findAll('.editor-stub')).toHaveLength(1)
		})

		it('ignores a pane id it does not know rather than showing nothing', async () => {
			window.location.hash = '#../../etc/passwd'
			const w = await mountAdmin()
			expect(w.vm.pane).toBe('teamfolder-metadata')
			expect(w.findAll('.editor-stub')).toHaveLength(1)
		})

		it('survives localStorage being unavailable', async () => {
			const get = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('blocked') })
			const set = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('blocked') })
			const w = await mountAdmin()
			await w.setData({ pane: 'support' })
			expect(w.vm.pane).toBe('support')
			get.mockRestore(); set.mockRestore()
		})
	})

	describe('the subscription banner', () => {
		it('stays away when the licence call fails', async () => {
			const w = await mountAdmin(null)
			expect(w.vm.licenseBanner).toBe(null)
		})

		it('asks for attention when a key is present but not valid', async () => {
			const w = await mountAdmin({ licenseKeyMasked: 'MVOX-****', licenseValid: false })
			expect(w.vm.licenseBanner).not.toBe(null)
			expect(w.vm.licenseBanner.link).toBe('support')
		})

		it('says nothing when the key is valid', async () => {
			const w = await mountAdmin({ licenseKeyMasked: 'MVOX-****', licenseValid: true, userCount: 5 })
			expect(w.vm.licenseBanner).toBe(null)
		})

		it('points at a pane that exists', async () => {
			const w = await mountAdmin({ licenseKeyMasked: 'MVOX-****', licenseValid: false })
			expect(w.vm.panes.map((p) => p.id)).toContain(w.vm.licenseBanner.link)
		})

		it('hides its own link while the support pane is open', async () => {
			const w = await mountAdmin({ licenseKeyMasked: 'MVOX-****', licenseValid: false })
			expect(w.find('.metavox-admin__banner a').exists()).toBe(true)
			await w.setData({ pane: 'support' })
			expect(w.find('.metavox-admin__banner a').exists()).toBe(false)
		})
	})

	describe('notifications', () => {
		it('sends an error to showError and anything else to showSuccess', async () => {
			const err = vi.spyOn(dialogs, 'showError').mockImplementation(() => {})
			const ok = vi.spyOn(dialogs, 'showSuccess').mockImplementation(() => {})
			const w = await mountAdmin()

			w.vm.showNotification({ type: 'error', message: 'nope' })
			expect(err).toHaveBeenCalledWith('nope')

			w.vm.showNotification({ message: 'saved' })
			expect(ok).toHaveBeenCalledWith('saved')
		})
	})
})
