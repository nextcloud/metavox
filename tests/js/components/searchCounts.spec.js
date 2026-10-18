import { describe, it, expect } from 'vitest'
import folderListMixin from '@/components/mixins/folderListMixin.js'
import PermissionsManager from '@/components/PermissionsManager.vue'
import { translatePlural as n } from '@nextcloud/l10n'

/**
 * The count beside a list heading counts the list.
 *
 * On dev, searching "tr" in Per folder narrowed eight team folders to two
 * and the counter still read "8 folders" — directly above two rows, and
 * directly below a grey banner that said "Found 2 team folders matching
 * tr". Three numbers, two of them agreeing with each other and not with
 * the third.
 *
 * Each of the three lists had its own idea of this. MetadataFieldsEditor
 * was already right, so these pin the two that were not, plus the personal
 * page, whose count was not even pluralised ("1 folders").
 *
 * The folder count is tested once, on folderListMixin: the admin and
 * personal pages read it from there, so testing each host separately would
 * assert the same function twice and still not catch one of them dropping
 * the mixin.
 */
describe('list counts follow the search', () => {
	/**
	 * Stand-in for the host: the computed only reads the filtered list and
	 * the query, and `n` is the real stub, so the assertions are on the
	 * rendered string rather than on which branch was taken.
	 */
	const ctx = (matches, searchQuery) => ({ n, searchQuery, filteredGroupfolders: new Array(matches) })

	describe('folderListMixin folderCount', () => {
		const { folderCount } = folderListMixin.computed

		it('counts the matches while searching, not the whole list', () => {
			expect(folderCount.call(ctx(2, 'tr'))).toBe('2 folders found')
		})

		it('counts everything when the search is empty', () => {
			expect(folderCount.call(ctx(8, ''))).toBe('8 folders')
		})

		/** "1 folders" was shipping on the personal page. */
		it('uses the singular at one, in both states', () => {
			expect(folderCount.call(ctx(1, ''))).toBe('1 folder')
			expect(folderCount.call(ctx(1, 'intra'))).toBe('1 folder found')
		})

		/** A search that matches nothing still has to say so. */
		it('says zero rather than falling back to the total', () => {
			expect(folderCount.call(ctx(0, 'zzz'))).toBe('0 folders found')
		})
	})

	describe('PermissionsManager permissionCount', () => {
		const pctx = (matches, searchQuery) => ({ n, searchQuery, filteredPermissions: new Array(matches) })
		const { permissionCount } = PermissionsManager.computed

		it('counts the matches while searching', () => {
			expect(permissionCount.call(pctx(1, 'dev'))).toBe('1 permission found')
		})

		it('counts everything when the search is empty', () => {
			expect(permissionCount.call(pctx(2, ''))).toBe('2 permissions')
		})
	})
})
