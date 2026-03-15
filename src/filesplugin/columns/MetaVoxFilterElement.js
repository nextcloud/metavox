/**
 * MetaVox Filter Web Component
 *
 * Custom element <metavox-metadata-filter> used by NC33's filter bar UI.
 * Shows collapsible sections per metadata field, each with button-style options
 * matching the NC33 native filter look.
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

const STYLE = `
:host {
	display: block;
	padding: 4px 0;
	min-width: 240px;
	max-width: 320px;
	font-family: var(--font-face, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif);
	font-size: 14px;
	color: var(--color-main-text, #222);
}

/* Collapsible section */
details {
	border-bottom: 1px solid var(--color-border, #e8e8e8);
}
details:last-of-type {
	border-bottom: none;
}
summary {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 14px;
	cursor: pointer;
	user-select: none;
	font-size: 14px;
	color: var(--color-main-text, #222);
	list-style: none;
	min-height: 44px;
}
summary::-webkit-details-marker { display: none; }
summary:hover {
	background: var(--color-background-hover, #f5f5f5);
}
.summary-text {
	font-weight: 500;
	flex: 1;
}
.summary-badge {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 20px;
	height: 20px;
	padding: 0 5px;
	border-radius: 10px;
	background: var(--color-primary-element, #0082c9);
	color: #fff;
	font-size: 11px;
	font-weight: 600;
	margin-right: 6px;
}
.summary-chevron {
	width: 16px;
	height: 16px;
	transition: transform 0.15s;
	color: var(--color-text-maxcontrast, #767676);
	flex-shrink: 0;
}
details[open] .summary-chevron {
	transform: rotate(90deg);
}

/* Option buttons inside section */
.options {
	padding: 2px 0 6px 0;
}
.opt-btn {
	display: block;
	width: 100%;
	padding: 6px 14px;
	border: none;
	background: transparent;
	color: var(--color-main-text, #222);
	font-size: 14px;
	font-family: inherit;
	text-align: left;
	cursor: pointer;
	border-radius: 0;
	min-height: 36px;
}
.opt-btn:hover {
	background: var(--color-background-hover, #f5f5f5);
}
.opt-btn[aria-pressed="true"] {
	background: var(--color-primary-element-light, #e8f4fd);
	color: var(--color-primary-element, #0082c9);
	font-weight: 600;
}

/* Reset button */
.reset-btn {
	display: block;
	width: calc(100% - 16px);
	margin: 6px 8px 4px 8px;
	height: 36px;
	border: 2px solid var(--color-border-maxcontrast, #949494);
	border-radius: var(--border-radius-element, 6px);
	background: transparent;
	color: var(--color-text-maxcontrast, #767676);
	font-size: 14px;
	font-family: inherit;
	cursor: pointer;
	text-align: center;
	box-sizing: border-box;
}
.reset-btn:hover {
	border-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element, #0082c9);
}
.reset-btn.has-filters {
	border-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element, #0082c9);
	font-weight: 600;
}

.empty-msg {
	color: var(--color-text-maxcontrast, #767676);
	font-size: 13px;
	text-align: center;
	padding: 16px;
}
`

const CHEVRON_SVG = `<svg class="summary-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>`

function formatOptionLabel(value, fieldType) {
	if (fieldType === 'checkbox') {
		if (value === '1' || value === 'true') return 'Ja'
		if (value === '0' || value === 'false') return 'Nee'
	}
	return value
}

export class MetaVoxFilterElement extends HTMLElement {

	constructor() {
		super()
		this._filter = null
		this._shadow = this.attachShadow({ mode: 'open' })

		const styleEl = document.createElement('style')
		styleEl.textContent = STYLE
		this._shadow.appendChild(styleEl)

		this._container = document.createElement('div')
		this._shadow.appendChild(this._container)
	}

	set filter(filterInstance) {
		this._filter = filterInstance
		this._render()
	}

	get filter() {
		return this._filter
	}

	async _render() {
		if (!this._filter) {
			this._container.innerHTML = '<div class="empty-msg">No filter available</div>'
			return
		}

		const configs = this._filter.getFilterableConfigs()
		if (!configs || configs.length === 0) {
			this._container.innerHTML = '<div class="empty-msg">Geen filterbare velden</div>'
			return
		}

		const groupfolderId = this._filter.getGroupfolderId()
		const activeFilters = this._filter.getActiveFilters()

		this._container.innerHTML = ''

		for (const config of configs) {
			const isActive = activeFilters.has(config.field_name)
			const currentVal = activeFilters.get(config.field_name)

			const details = document.createElement('details')
			if (isActive) details.open = true

			// Summary (header row)
			const summary = document.createElement('summary')

			const textSpan = document.createElement('span')
			textSpan.className = 'summary-text'
			textSpan.textContent = config.field_label
			summary.appendChild(textSpan)

			const badge = document.createElement('span')
			badge.className = 'summary-badge'
			badge.textContent = '1'
			badge.style.display = isActive ? 'inline-flex' : 'none'
			summary.appendChild(badge)

			summary.insertAdjacentHTML('beforeend', CHEVRON_SVG)
			details.appendChild(summary)

			// Options container
			const optionsDiv = document.createElement('div')
			optionsDiv.className = 'options'

			// "Alle" option
			const allBtn = this._makeOptBtn('Alle', '', !isActive)
			allBtn.addEventListener('click', () => {
				this._filter.setFilterValue(config.field_name, null)
				this._updateSection(details, optionsDiv, badge, '', config)
			})
			optionsDiv.appendChild(allBtn)

			// Load values from API
			if (groupfolderId) {
				try {
					const url = generateOcsUrl(
						'/apps/metavox/api/v1/groupfolders/{groupfolderId}/filter-values',
						{ groupfolderId },
					)
					const resp = await axios.get(url, { params: { field_name: config.field_name } })
					const values = resp.data?.ocs?.data || resp.data || []

					for (const val of values) {
						const label = formatOptionLabel(val, config.field_type)
						const btn = this._makeOptBtn(label, val, currentVal === val)
						btn.addEventListener('click', () => {
							this._filter.setFilterValue(config.field_name, val)
							this._updateSection(details, optionsDiv, badge, val, config)
						})
						optionsDiv.appendChild(btn)
					}
				} catch (e) {
					console.error('MetaVox: Failed to load filter values for', config.field_name, e)
				}
			}

			details.appendChild(optionsDiv)
			this._container.appendChild(details)
		}

		// Reset button
		const resetBtn = document.createElement('button')
		resetBtn.className = 'reset-btn' + (activeFilters.size > 0 ? ' has-filters' : '')
		resetBtn.textContent = 'Reset filters'
		resetBtn.addEventListener('click', () => {
			this._filter.reset()
			this._shadow.querySelectorAll('details').forEach(d => {
				d.open = false
				d.querySelectorAll('.opt-btn').forEach(b => {
					b.setAttribute('aria-pressed', b.dataset.value === '' ? 'true' : 'false')
				})
				const badge = d.querySelector('.summary-badge')
				if (badge) badge.style.display = 'none'
			})
			resetBtn.classList.remove('has-filters')
		})
		this._container.appendChild(resetBtn)
	}

	_makeOptBtn(label, value, isActive) {
		const btn = document.createElement('button')
		btn.type = 'button'
		btn.className = 'opt-btn'
		btn.textContent = label
		btn.dataset.value = value
		btn.setAttribute('aria-pressed', isActive ? 'true' : 'false')
		return btn
	}

	_updateSection(details, optionsDiv, badge, activeValue, config) {
		optionsDiv.querySelectorAll('.opt-btn').forEach(btn => {
			btn.setAttribute('aria-pressed', btn.dataset.value === activeValue ? 'true' : 'false')
		})
		const hasValue = activeValue !== ''
		badge.style.display = hasValue ? 'inline-flex' : 'none'

		// Update reset button
		const resetBtn = this._container.querySelector('.reset-btn')
		if (resetBtn) {
			resetBtn.classList.toggle('has-filters', this._filter.getActiveFilters().size > 0)
		}
	}
}

if (!customElements.get('metavox-metadata-filter')) {
	customElements.define('metavox-metadata-filter', MetaVoxFilterElement)
}
