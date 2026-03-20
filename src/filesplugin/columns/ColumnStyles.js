/**
 * MetaVox Column Styles — CSS injection and cleanup for column UI.
 *
 * Extracted from MetaVoxColumns.js
 */

// TODO: import ncVersion from MetaVoxState.js once it exists
// For now, inline the ncVersion detection
/** @type {number} Nextcloud major version (0 if unknown) */
const ncVersion = (() => {
	try {
		const v = window.OC?.config?.version
		if (v) return parseInt(v.split('.')[0], 10)
	} catch (e) { /* ignore */ }
	return 0
})()

// ── Constants ──────────────────────────────────────────────────

export const MARKER_CLASS = 'metavox-col'
export const HEADER_MARKER = 'metavox-col-header'
export const RESIZE_HANDLE = 'metavox-resize-handle'
export const STYLE_ID = 'metavox-column-styles'

// ── Style injection ────────────────────────────────────────────

export function injectColumnStyles() {
	if (document.getElementById(STYLE_ID)) return

	const style = document.createElement('style')
	style.id = STYLE_ID
	const nc32Styles = ''

	style.textContent = `
		/* Horizontal scroll: only #app-content-vue scrolls, nav stays fixed */
		#content-vue {
			overflow-x: visible !important;
		}
		#app-content-vue {
			overflow-x: auto !important;
		}
		/* Keep the breadcrumb/toolbar pinned during horizontal scroll.
		   left: 44px reserves space for the nav-collapse toggle (which sits at x=8..42
		   relative to #app-content-vue) without adding unwanted space at scrollLeft=0. */
		.files-list__header {
			position: sticky !important;
			left: 44px !important;
			z-index: 10 !important;
		}
		.files-list {
			overflow-x: visible !important;
			overflow-y: auto !important;
		}
		.files-list__table {
			table-layout: auto !important;
		}
		.files-list__row-name {
			min-width: 200px !important;
		}
		/* Loading state — fade the file list while server sort/filter is in progress */
		.files-list.metavox-loading .files-list__table tbody {
			opacity: 0.4;
			pointer-events: none;
			transition: opacity 0.15s ease;
		}
		.files-list__table tbody {
			transition: opacity 0.15s ease;
		}
		/* Data cells */
		.${MARKER_CLASS} {
			flex: 0 0 auto !important;
			padding: 0 8px !important;
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
			box-sizing: border-box;
		}
		/* Header cells */
		.${HEADER_MARKER} {
			flex: 0 0 auto !important;
			padding: 0 !important;
			box-sizing: border-box;
			position: relative;
			min-width: 60px;
		}
		.${HEADER_MARKER} .files-list__column-sort-button {
			width: 100%;
			height: 34px;
			display: flex !important;
			align-items: center;
			justify-content: flex-start;
			padding: 1px 8px 0 8px;
			background: transparent;
			border: none;
			color: var(--color-text-maxcontrast);
			font: inherit;
			cursor: pointer;
			border-radius: var(--border-radius-element, 32px);
		}
		.${HEADER_MARKER} .files-list__column-sort-button:hover {
			background: var(--color-background-hover);
			color: var(--color-main-text);
		}
		.${HEADER_MARKER} .files-list__column-sort-button:hover .files-list__column-sort-button-icon {
			opacity: 0.5 !important;
		}
		.${HEADER_MARKER} .files-list__column-sort-button .button-vue__wrapper {
			display: flex;
			align-items: center;
			flex-direction: row-reverse;
			gap: 0;
		}
		.${HEADER_MARKER} .files-list__column-sort-button .button-vue__icon {
			display: flex;
			align-items: center;
			min-width: 24px;
		}
		.${HEADER_MARKER} .files-list__column-sort-button-icon {
			display: flex;
			align-items: center;
		}
		.${HEADER_MARKER} .files-list__column-sort-button-icon svg {
			width: 24px;
			height: 24px;
		}
		.${HEADER_MARKER} .files-list__column-sort-button-text {
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
		}
		/* Resize handle */
		.${RESIZE_HANDLE} {
			position: absolute;
			right: 0;
			top: 0;
			bottom: 0;
			width: 5px;
			cursor: col-resize;
			z-index: 1;
		}
		.${RESIZE_HANDLE}:hover,
		.${RESIZE_HANDLE}.active {
			background: var(--color-primary-element);
			opacity: 0.5;
		}
		.${MARKER_CLASS} {
			color: var(--color-text-maxcontrast);
			min-width: 60px;
		}
		.${MARKER_CLASS}--empty {
			color: var(--color-text-maxcontrast, #767676) !important;
		}
		/* Inline editor styles */
		.metavox-inline-editor {
			width: 100%;
			box-sizing: border-box;
			font: inherit;
			font-size: 13px;
			color: var(--color-main-text);
			background: var(--color-main-background);
			border: 2px solid var(--color-primary-element);
			border-radius: var(--border-radius);
			outline: none;
		}
		.metavox-inline-input,
		.metavox-inline-date {
			padding: 4px 8px;
			height: 32px;
		}
		/* Shared dropdown base (NcSelect-style) */
		.metavox-inline-select,
		.metavox-inline-multiselect {
			padding: 4px 0;
			overflow-y: auto;
			border: none;
			border-radius: var(--border-radius-large, 10px);
			box-shadow: 0 2px 6px var(--color-box-shadow, rgba(0,0,0,.15));
			background: var(--color-main-background);
			cursor: default;
		}
		/* Shared option base */
		.metavox-select-option,
		.metavox-ms-option {
			display: flex;
			align-items: center;
			padding: 0 8px;
			min-height: 40px;
			cursor: pointer;
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
			font-size: var(--default-font-size, 15px);
			line-height: 20px;
			color: var(--color-main-text);
			border-radius: 0;
		}
		.metavox-select-option:hover,
		.metavox-ms-option:hover {
			background: var(--color-background-hover);
		}
		/* Select: selected state */
		.metavox-select-option--selected {
			background: var(--color-primary-element-light, rgba(0,130,201,.1));
			font-weight: 600;
		}
		/* Multiselect: checkbox + gap */
		.metavox-inline-multiselect {
			display: flex;
			flex-direction: column;
		}
		.metavox-ms-option {
			gap: 8px;
		}
		.metavox-ms-option input[type="checkbox"] {
			width: 18px;
			height: 18px;
			min-width: 18px;
			margin: 0;
			accent-color: var(--color-primary-element);
			cursor: pointer;
			border-radius: var(--border-radius, 3px);
		}
		/* Multiselect: action buttons */
		.metavox-ms-actions {
			display: flex;
			gap: 4px;
			padding: 4px 8px;
			border-top: 1px solid var(--color-border);
			margin-top: 2px;
		}
		.metavox-ms-save,
		.metavox-ms-cancel {
			flex: 1;
			min-height: 34px;
			border: none;
			border-radius: var(--border-radius-pill, 20px);
			cursor: pointer;
			font-size: var(--default-font-size, 15px);
			font-weight: 600;
		}
		.metavox-ms-save {
			background: var(--color-primary-element);
			color: var(--color-primary-element-text, #fff);
		}
		.metavox-ms-save:hover {
			background: var(--color-primary-element-hover);
		}
		.metavox-ms-cancel {
			background: var(--color-background-hover);
			color: var(--color-main-text);
		}
		.metavox-ms-cancel:hover {
			background: var(--color-background-dark);
		}
		.${MARKER_CLASS}:hover {
			background: var(--color-background-hover);
		}
		/* Fill handle (Excel-style drag to copy) */
		.metavox-fill-handle {
			position: absolute;
			right: -1px;
			bottom: -1px;
			width: 8px;
			height: 8px;
			background: var(--color-primary-element);
			cursor: crosshair;
			z-index: 10;
			border: 1px solid var(--color-main-background);
		}
		.metavox-fill-highlight {
			outline: 2px solid var(--color-primary-element);
			outline-offset: -2px;
			background: color-mix(in srgb, var(--color-primary-element) 8%, transparent) !important;
		}
		${nc32Styles}
	`
	document.head.appendChild(style)
}

export function removeColumnStyles() {
	document.getElementById(STYLE_ID)?.remove()
}
