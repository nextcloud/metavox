import { describe, it, expect } from 'vitest'
import defaultValuesMixin from '@/components/mixins/defaultValuesMixin.js'

/**
 * The subline under a folder name.
 *
 * It read "%n fields configured" off the raw assignment count, which counts
 * every field assigned to the folder whatever it describes. On dev, IntraVox
 * showed "10 fields configured" while the Edit metadata dialog offered four
 * inputs. The six missing ones were file fields — they describe the files
 * inside the folder and are edited in the Files app, not in that dialog.
 * Nothing was corrupt; the number was answering a different question than
 * the dialog beside it.
 *
 * Verified against the database at the time: field ids 1, 38, 42, 47, 53 and
 * 55 have applies_to_groupfolder = 0, and 56, 57, 77 and 80 have 1. Ten
 * assignments, zero dangling.
 */
describe('fieldsSummary', () => {
	const { fieldsSummary } = defaultValuesMixin.methods

	/**
	 * A stand-in for the host component. Both real hosts (ManageGroupfolders
	 * and MetaVoxPersonal) already define this pair of counters; the mixin
	 * documents them as its contract.
	 */
	const ctx = (total, folderFields) => ({
		getAssignedFieldsCount: () => total,
		getAssignedGroupfolderFieldsCount: () => folderFields,
	})

	it('names both groups so the count matches the dialog', () => {
		// IntraVox as it actually stood: 10 assigned, 4 of them team folder.
		const summary = fieldsSummary.call(ctx(10, 4), 1)

		expect(summary).toContain('4 Team folder fields')
		expect(summary).toContain('6 file fields')
	})

	/**
	 * The regression itself: the old subline said 10, the Edit metadata
	 * dialog rendered 4. Whatever else the line says, the number a reader
	 * takes from it must be the one that dialog will show.
	 */
	it('never reports the raw total as the editable count', () => {
		const summary = fieldsSummary.call(ctx(10, 4), 1)

		expect(summary).not.toMatch(/\b10 fields configured\b/)
	})

	it('says so once when the folder has nothing assigned', () => {
		expect(fieldsSummary.call(ctx(0, 0), 2)).toBe('No fields configured')
	})

	/**
	 * A folder can hold only file fields — that is the state the old line
	 * hid worst, because it promised fields the dialog could not offer. Zero
	 * is still printed, so the row explains why Edit metadata is disabled.
	 */
	it('shows a zero team folder count rather than dropping the group', () => {
		const summary = fieldsSummary.call(ctx(6, 0), 3)

		expect(summary).toContain('0 Team folder fields')
		expect(summary).toContain('6 file fields')
	})

	it('uses singular forms at one', () => {
		const summary = fieldsSummary.call(ctx(2, 1), 4)

		expect(summary).toContain('1 Team folder field')
		expect(summary).toContain('1 file field')
		expect(summary).not.toContain('fields')
	})

	/**
	 * The team folder count comes first: it is the one that predicts this
	 * row, deciding whether Edit metadata is enabled and what a click opens.
	 */
	it('puts the team folder count first', () => {
		const summary = fieldsSummary.call(ctx(10, 4), 1)

		expect(summary.indexOf('Team folder')).toBeLessThan(summary.indexOf('file field'))
	})
})
