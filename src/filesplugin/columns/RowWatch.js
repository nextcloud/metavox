/**
 * MetaVox RowWatch — the file list's rows as they come and are reused. Rows are
 * the tbody's children; while scrolling, Nextcloud gives a row another file by
 * changing its fileid. Only that is watched, not what changes inside the rows:
 * MetaVox's own cell writes alone were thousands of records per scroll.
 */

const ROW = 'tr[data-cy-files-list-row]'
const FILE_ID = 'data-cy-files-list-row-fileid'

/**
 * @param {HTMLElement} tbody
 * @param {{added: function(HTMLElement): void, reused: function(HTMLElement): void}} on
 * @return {{disconnect: function(): void}}
 */
export function watchRows(tbody, on) {
	const rows = new MutationObserver((records) => {
		for (const record of records) {
			for (const node of record.addedNodes) {
				if (node.nodeType === 1 && node.matches(ROW)) on.added(node)
			}
		}
	})
	rows.observe(tbody, { childList: true })

	const fileIds = new MutationObserver((records) => {
		for (const record of records) {
			if (record.target.matches?.(ROW)) on.reused(record.target)
		}
	})
	fileIds.observe(tbody, { subtree: true, attributes: true, attributeFilter: [FILE_ID] })

	return {
		disconnect() {
			rows.disconnect()
			fileIds.disconnect()
		},
	}
}
