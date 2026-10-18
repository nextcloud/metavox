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
	// Actions-cell width pin (column alignment). NC rows are flexbox and
	// .files-list__row-actions has width:auto → it sizes to its inline buttons
	// (labelled via force-name, plus render-actions) and is therefore a
	// DIFFERENT width per row. Because our metavox columns are appended AFTER the
	// actions cell, each row's columns start at a different x and the skew
	// accumulates left-to-right. Pinning one FIXED, capped width on the header +
	// every data row makes them all identical geometry so our columns line up.
	// A bare min-width is NOT enough: an uncapped, locale-dependent actions cell
	// can grow past the floor and re-introduce the per-row skew — so we also cap
	// with max-width + overflow:hidden (excess inline buttons fall back into the
	// overflow "…" menu). Scoped to LIST mode only: in grid mode the actions cell
	// is position:absolute (out of flow, fixed width) and must stay untouched.
	// No media query — NC's narrow mode is a CONTAINER width (<512px), not a
	// viewport breakpoint, so a viewport query would gate on the wrong axis.
	const nc32Styles = `
		.files-list:not(.files-list--grid) .files-list__row-actions {
			width: calc(var(--default-clickable-area) * 6) !important;
			min-width: calc(var(--default-clickable-area) * 6) !important;
			max-width: calc(var(--default-clickable-area) * 6) !important;
			flex: 0 0 auto !important;
			overflow: hidden !important;
			justify-content: start !important;
		}
	`

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
			inset-inline-start: 44px !important;
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
			transition: opacity var(--animation-quick) ease-in-out;
		}
		.files-list__table tbody {
			transition: opacity var(--animation-quick) ease-in-out;
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
			border-radius: var(--border-radius-element);
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
		/* Values for the sort are still loading: pulse the sort icon, rows stay usable */
		.${HEADER_MARKER}.metavox-sort-pending .files-list__column-sort-button-icon {
			animation: metavox-sort-pending 1s ease-in-out infinite;
		}
		@keyframes metavox-sort-pending {
			50% { opacity: 0.3; }
		}
		.${HEADER_MARKER} .files-list__column-sort-button-text {
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
		}
		/* Resize handle */
		.${RESIZE_HANDLE} {
			position: absolute;
			inset-inline-end: 0;
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
		.${MARKER_CLASS}--empty:not([data-mv-tone]) {
			color: var(--color-text-maxcontrast) !important;
		}
		/* A required field without its value; a highlight rule of the view goes first. */
		.${MARKER_CLASS}--empty[data-mv-required]:not([data-mv-tone]) {
			color: var(--color-warning-text) !important;
			font-weight: bold;
		}
		/* Inline editor styles */
		.metavox-inline-editor {
			width: 100%;
			box-sizing: border-box;
			font: inherit;
			font-size: var(--default-font-size);
			color: var(--color-main-text);
			background: var(--color-main-background);
			border: 2px solid var(--color-primary-element);
			border-radius: var(--border-radius-element);
		}
		.metavox-inline-editor:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 2px;
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
			border-radius: var(--border-radius-container);
			box-shadow: 0 2px 6px var(--color-box-shadow);
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
			font-size: var(--default-font-size);
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
			background: var(--color-primary-element-light);
			font-weight: var(--font-weight-heading, 600);
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
			border-radius: var(--border-radius-small);
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
			border-radius: var(--border-radius-pill);
			cursor: pointer;
			font-size: var(--default-font-size);
			font-weight: var(--font-weight-heading, 600);
		}
		.metavox-ms-save {
			background: var(--color-primary-element);
			color: var(--color-primary-element-text);
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
			margin: 8px;
			padding: 8px 12px;
			border: 1px solid var(--color-border);
			border-radius: var(--border-radius-element);
			background: var(--color-main-background);
			font-size: var(--default-font-size);
			min-height: var(--default-clickable-area);
		}
		.metavox-user-search:focus-visible {
			border-color: var(--color-primary-element);
			outline: 2px solid var(--color-primary-element);
			outline-offset: 2px;
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
			border-radius: var(--border-radius-small);
			background: var(--color-main-background);
			font-size: var(--default-font-size);
			min-width: 120px;
		}
		.metavox-url-input:focus-visible {
			border-color: var(--color-primary-element);
			outline: 2px solid var(--color-primary-element);
			outline-offset: -2px;
		}
		.metavox-url-open {
			display: flex;
			align-items: center;
			justify-content: center;
			width: var(--clickable-area-small);
			height: var(--clickable-area-small);
			border-radius: var(--border-radius-small);
			background: var(--color-background-hover);
			color: var(--color-primary-element);
			text-decoration: none;
			font-size: var(--default-font-size);
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
			border-radius: var(--border-radius-container);
			box-shadow: 0 2px 6px var(--color-box-shadow);
		}
		.metavox-filelink-path {
			flex: 1;
			font-size: var(--default-font-size);
			color: var(--color-main-text);
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
			min-width: 60px;
		}
		.metavox-filelink-browse {
			padding: 4px 12px;
			border: none;
			border-radius: var(--border-radius-pill);
			background: var(--color-primary-element);
			color: var(--color-primary-element-text);
			font-size: var(--font-size-small);
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
			width: var(--clickable-area-small);
			height: var(--clickable-area-small);
			border-radius: var(--border-radius-small);
			background: var(--color-background-hover);
			color: var(--color-primary-element);
			text-decoration: none;
			font-size: var(--default-font-size);
			flex-shrink: 0;
		}
		.metavox-filelink-open:hover {
			background: var(--color-primary-element-light);
		}
		.metavox-filelink-clear {
			width: var(--clickable-area-small);
			height: var(--clickable-area-small);
			border: none;
			border-radius: 50%;
			background: var(--color-background-hover);
			color: var(--color-main-text);
			cursor: pointer;
			font-size: var(--default-font-size);
			display: flex;
			align-items: center;
			justify-content: center;
		}
		.metavox-filelink-clear:hover {
			background: var(--color-error);
			color: var(--color-primary-element-text);
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
		.metavox-cell-filelink-missing,
		.metavox-filelink-missing {
			color: var(--color-text-maxcontrast);
			font-style: italic;
		}
		.metavox-cell-link-btn {
			flex-shrink: 0;
			display: flex;
			align-items: center;
			justify-content: center;
			width: 22px;
			height: 22px;
			border-radius: var(--border-radius-small);
			background: var(--color-background-hover);
			color: var(--color-primary-element);
			text-decoration: none;
			font-size: var(--font-size-small);
			opacity: 0;
			transition: opacity var(--animation-quick) ease-in-out;
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
		/* Highlight rules: a soft wash, a bar on the leading edge and an icon in
		   the tone; the text keeps the theme's text colour. The selectors start
		   with .files-list td to outrank the file list's own td colour; list mode
		   only, a tile has no room for our cells.
		   Wash and bar use the saturated element colour (NC 32+): the dark
		   theme's -text colours are near-white and would wash to one grey.
		   NC 31 has no element colours and falls back to -text.
		   The editor's preview (.mv-grid-preview) gets the same. */
		.files-list td.${MARKER_CLASS}[data-mv-tone="error"], .mv-tone-sample[data-mv-tone="error"], .mv-grid-preview td[data-mv-tone="error"] { --mv-tone: var(--color-error-text); --mv-wash: var(--color-element-error, var(--color-error-text)); }
		.files-list td.${MARKER_CLASS}[data-mv-tone="warning"], .mv-tone-sample[data-mv-tone="warning"], .mv-grid-preview td[data-mv-tone="warning"] { --mv-tone: var(--color-warning-text); --mv-wash: var(--color-element-warning, var(--color-warning-text)); }
		.files-list td.${MARKER_CLASS}[data-mv-tone="success"], .mv-tone-sample[data-mv-tone="success"], .mv-grid-preview td[data-mv-tone="success"] { --mv-tone: var(--color-success-text); --mv-wash: var(--color-element-success, var(--color-success-text)); }
		.files-list td.${MARKER_CLASS}[data-mv-tone="info"], .mv-tone-sample[data-mv-tone="info"], .mv-grid-preview td[data-mv-tone="info"] { --mv-tone: var(--color-info-text); --mv-wash: var(--color-element-info, var(--color-info-text)); }
		.files-list:not(.files-list--grid) td.${MARKER_CLASS}[data-mv-tone],
		.files-list:not(.files-list--grid) td.${MARKER_CLASS}[data-mv-tone]:hover,
		.mv-grid-preview td[data-mv-tone] {
			background: color-mix(in srgb, var(--mv-wash) 16%, var(--color-main-background));
			box-shadow: inset 3px 0 0 var(--mv-wash);
			color: var(--color-main-text);
		}
		[dir="rtl"] .files-list:not(.files-list--grid) td.${MARKER_CLASS}[data-mv-tone],
		[dir="rtl"] .mv-grid-preview td[data-mv-tone] {
			box-shadow: inset -3px 0 0 var(--mv-wash);
		}
		.files-list td.${MARKER_CLASS}[data-mv-tone] .metavox-cell-filelink-missing {
			color: inherit;
		}
		/* The editor's sample: a cell in miniature. */
		.mv-tone-sample {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			flex: none;
			inline-size: 24px;
			block-size: 24px;
			border-radius: var(--border-radius-small, 4px);
			background: color-mix(in srgb, var(--mv-wash) 16%, var(--color-main-background));
			box-shadow: inset 3px 0 0 var(--mv-wash);
			color: var(--mv-tone);
		}
		[dir="rtl"] .mv-tone-sample {
			box-shadow: inset -3px 0 0 var(--mv-wash);
		}
		/* The name of a state beside its sample: weight from the token, not
		   the 700 a bare <strong> gets (core does not restyle it). */
		.mv-state-name {
			font-weight: var(--font-weight-element, 500);
		}
		/* A section heading in the view editor, as NcAppSettingsSection heads
		   one (Talk's settings): 20px, bold, space between, no rule. Here, so
		   the panel and the highlight editor share it. */
		.mv-editor-section-title {
			margin: calc(8 * var(--default-grid-baseline, 4px)) 0 calc(2 * var(--default-grid-baseline, 4px));
			font-size: 20px;
			font-weight: var(--font-weight-heading, bold);
			color: var(--color-main-text);
		}
		.mv-editor-section:first-child > .mv-editor-section-title {
			margin-block-start: 0;
		}
		/* A label in the view editor, the same for all of them: supporting
		   text, like the floated label of NcTextField and NcSelect (§6). */
		.mv-field-label {
			font-size: var(--font-size-small);
			font-weight: var(--font-weight-element, 500);
			color: var(--color-text-maxcontrast);
		}
		.mv-tone-icon {
			display: inline-flex;
			flex: none;
			inline-size: 16px;
			block-size: 16px;
			margin-inline-end: 4px;
			vertical-align: -3px;
			color: var(--mv-tone);
		}
		.mv-tone-icon svg,
		.metavox-col-rules-icon svg {
			flex: none;
			min-width: 16px;
		}
		.${HEADER_MARKER} .metavox-col-has-rules {
			display: inline-flex;
			align-items: center;
			min-width: 0;
		}
		.${HEADER_MARKER} .metavox-col-has-rules .files-list__column-sort-button-text {
			min-width: 0;
		}
		.metavox-col-rules-icon {
			display: inline-flex;
			flex: none;
			margin-inline-start: 4px;
			vertical-align: -3px;
			color: var(--color-text-maxcontrast);
		}
		/* Fill handle (Excel-style drag to copy) */
		.metavox-fill-handle {
			position: absolute;
			inset-inline-end: -1px;
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
		/* Focus indicators. Every one of these targets is reachable by keyboard;
		   until now none of them showed where the focus was (§7). */
		.metavox-select-option:focus-visible,
		.metavox-ms-option:focus-visible,
		.metavox-ms-save:focus-visible,
		.metavox-ms-cancel:focus-visible,
		.metavox-filelink-browse:focus-visible,
		.metavox-filelink-clear:focus-visible,
		.metavox-filelink-open:focus-visible,
		.metavox-url-open:focus-visible,
		.metavox-cell-link-btn:focus-visible,
		.metavox-cell-editable:focus-visible,
		.${HEADER_MARKER} .files-list__column-sort-button:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: -2px;
		}
		/* An editable cell is focusable, so it needs to show where the focus is;
		   without this the keyboard route into editing is invisible. */
		.metavox-cell-editable:focus-visible {
			border-radius: var(--border-radius-small);
		}
		/* A link button that only appears on row hover is unreachable by keyboard
		   while it is transparent — reveal it on focus too. */
		.metavox-cell-link-btn:focus-visible {
			opacity: 1;
		}
		@media (prefers-reduced-motion: reduce) {
			.files-list.metavox-loading .files-list__table tbody,
			.files-list__table tbody,
			.metavox-cell-link-btn {
				transition: none;
			}
		}
		${nc32Styles}
	`
	document.head.appendChild(style)
}

export function removeColumnStyles() {
	document.getElementById(STYLE_ID)?.remove()
}
