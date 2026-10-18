/**
 * MetaVox StickyOffsets — the bars above the grid stack while scrolling: view
 * tabs (top 0), then the filters, then the column heads. Each needs the heights
 * of the bars above it, kept up to date by one ResizeObserver.
 */

const VIEW_TABS_ID = 'metavox-view-tabs'

let observer = null

function update() {
	const filesList = document.querySelector('.files-list')
	if (!filesList) return
	const tabs = document.getElementById(VIEW_TABS_ID)
	const filters = document.querySelector('.files-list__filters')
	const tabsH = tabs ? Math.round(tabs.getBoundingClientRect().height) : 0
	const filtersH = filters ? Math.round(filters.getBoundingClientRect().height) : 0
	filesList.style.setProperty('--mv-filters-top', tabsH + 'px')
	filesList.style.setProperty('--mv-thead-top', (tabsH + filtersH) + 'px')
}

/** Set the offsets and follow the bars' heights; one observer, however often it is called. */
export function watchStickyOffsets() {
	update()
	observer?.disconnect()
	observer = null
	if (typeof ResizeObserver === 'undefined') return
	observer = new ResizeObserver(update)
	for (const bar of [document.querySelector('.files-list__filters'), document.getElementById(VIEW_TABS_ID)]) {
		// The border box, as update() measures: padding counts too.
		if (bar) observer.observe(bar, { box: 'border-box' })
	}
}

/** When the grid goes: stop following. */
export function stopStickyOffsets() {
	observer?.disconnect()
	observer = null
}
