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
			z-index: 55 !important;
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
		/* User picker */
		.metavox-user-picker {
			display: flex;
			flex-direction: column;
			max-height: 280px;
		}
		.metavox-user-search {
			margin: 6px 8px;
			padding: 6px 10px;
			border: 1px solid var(--color-border);
			border-radius: var(--border-radius-large, 10px);
			background: var(--color-main-background);
			font-size: var(--default-font-size, 15px);
			outline: none;
		}
		.metavox-user-search:focus {
			border-color: var(--color-primary-element);
		}
		.metavox-user-list {
			overflow-y: auto;
			flex: 1;
		}
		.metavox-user-option {
			gap: 8px;
		}
		.metavox-user-avatar {
			border-radius: 50%;
			flex-shrink: 0;
		}
		.metavox-user-empty {
			color: var(--color-text-maxcontrast);
			cursor: default;
			font-style: italic;
		}
		/* URL inline editor */
		.metavox-inline-url {
			display: flex;
			align-items: center;
			gap: 4px;
			background: var(--color-main-background);
			border: none;
			padding: 0;
		}
		.metavox-url-input {
			flex: 1;
			height: 32px;
			padding: 0 8px;
			border: 1px solid var(--color-border);
			border-radius: var(--border-radius, 3px);
			background: var(--color-main-background);
			font-size: var(--default-font-size, 15px);
			outline: none;
			min-width: 120px;
		}
		.metavox-url-input:focus {
			border-color: var(--color-primary-element);
		}
		.metavox-url-open {
			display: flex;
			align-items: center;
			justify-content: center;
			width: 28px;
			height: 28px;
			border-radius: var(--border-radius, 3px);
			background: var(--color-background-hover);
			color: var(--color-primary-element);
			text-decoration: none;
			font-size: 16px;
			flex-shrink: 0;
		}
		.metavox-url-open:hover {
			background: var(--color-primary-element-light);
		}
		/* File link inline editor */
		.metavox-inline-filelink {
			display: flex;
			align-items: center;
			gap: 6px;
			padding: 4px 8px;
			background: var(--color-main-background);
			border: none;
			border-radius: var(--border-radius-large, 10px);
			box-shadow: 0 2px 6px var(--color-box-shadow, rgba(0,0,0,.15));
		}
		.metavox-filelink-path {
			flex: 1;
			font-size: var(--default-font-size, 15px);
			color: var(--color-main-text);
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
			min-width: 60px;
		}
		.metavox-filelink-browse {
			padding: 4px 12px;
			border: none;
			border-radius: var(--border-radius-pill, 20px);
			background: var(--color-primary-element);
			color: var(--color-primary-element-text, #fff);
			font-size: 13px;
			cursor: pointer;
			white-space: nowrap;
		}
		.metavox-filelink-browse:hover {
			background: var(--color-primary-element-hover);
		}
		.metavox-filelink-open {
			display: flex;
			align-items: center;
			justify-content: center;
			width: 28px;
			height: 28px;
			border-radius: var(--border-radius, 3px);
			background: var(--color-background-hover);
			color: var(--color-primary-element);
			text-decoration: none;
			font-size: 16px;
			flex-shrink: 0;
		}
		.metavox-filelink-open:hover {
			background: var(--color-primary-element-light);
		}
		.metavox-filelink-clear {
			width: 28px;
			height: 28px;
			border: none;
			border-radius: 50%;
			background: var(--color-background-hover);
			color: var(--color-main-text);
			cursor: pointer;
			font-size: 14px;
			display: flex;
			align-items: center;
			justify-content: center;
		}
		.metavox-filelink-clear:hover {
			background: var(--color-error);
			color: #fff;
		}
		/* Rich cell rendering — user, url, filelink */
		.metavox-cell-user,
		.metavox-cell-url,
		.metavox-cell-filelink {
			display: flex;
			align-items: center;
			gap: 6px;
			overflow: hidden;
			width: 100%;
		}
		.metavox-cell-avatar {
			border-radius: 50%;
			flex-shrink: 0;
		}
		.metavox-cell-url-text,
		.metavox-cell-filelink-text {
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
			flex: 1;
			min-width: 0;
		}
		.metavox-cell-link-btn {
			flex-shrink: 0;
			display: flex;
			align-items: center;
			justify-content: center;
			width: 22px;
			height: 22px;
			border-radius: var(--border-radius, 3px);
			background: var(--color-background-hover);
			color: var(--color-primary-element);
			text-decoration: none;
			font-size: 14px;
			opacity: 0;
			transition: opacity 0.15s;
		}
		.${MARKER_CLASS}:hover .metavox-cell-link-btn {
			opacity: 1;
		}
		.metavox-cell-link-btn:hover {
			background: var(--color-primary-element-light);
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
