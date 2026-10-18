/**
 * Save the filters currently set by hand as a view.
 *
 * Filters set from the filter panel live on the filter instance; a view's
 * filters live on the view. There was no bridge between them, so a set of
 * filters someone had assembled could not be kept. This is that bridge.
 */

import axios from '@nextcloud/axios'
import { createApp, h } from 'vue'
import { translate, translate as t, translatePlural } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '../../utils/toast.js'
import { loadPart, watchLoading } from '../../utils/updatedPage.js'

// The dialog loads when it opens.
let saveDialog = null
async function loadSaveDialog() {
	saveDialog ??= (await loadPart(() => import(/* webpackChunkName: "metavox-save-filters" */ './SaveFiltersDialog.vue'))).default
	return saveDialog
}
import {
	getActiveGroupfolderId,
	getActiveView,
	getActiveViews,
	setActiveViews,
	getCanManageViews,
	getActiveColumnConfigs,
	getAvailableFields,
} from './MetaVoxState.js'
import { fetchViews } from './MetaVoxAPI.js'
import { columnsForNewView, columnsWithoutRules } from './ColumnUtils.js'
import { errorText } from '../../utils/errorText.js'
import { formatFieldValue } from '../../utils/fieldValue.js'

const DIALOG_ID = 'metavox-save-filters'

/** Close the dialog and unmount its app, so a second open starts clean. */
function closeDialog() {
	const host = document.getElementById(DIALOG_ID)
	if (host?._vueApp) host._vueApp.unmount()
	host?.remove()
}

/**
 * Describe the active filters for the dialog's summary line.
 *
 * Values are formatted the way the panel and the chips format them, so a
 * checkbox stored as "0" reads "No" here too.
 *
 * @param {object} filter the filter instance
 * @return {Array<{label: string, values: Array<string>}>} one entry per field
 */
function summariseFilters(filter) {
	const configs = getActiveColumnConfigs() || []
	const out = []
	for (const [fieldName, valueSet] of filter.getActiveFilters()) {
		if (!valueSet || valueSet.size === 0) continue
		const config = configs.find(c => c.field_name === fieldName)
		out.push({
			label: config?.field_label || fieldName.replace('file_gf_', ''),
			values: [...valueSet].map(v => formatFieldValue(v, config?.field_type, config)),
		})
	}
	return out
}

/**
 * Turn the active filters into the shape a view stores.
 *
 * A view keys its filters by field_id and joins the values with a comma,
 * which is what the view editor writes as well.
 *
 * @param {object} filter the filter instance
 * @param {Array<object>} columns the columns this view will store
 * @return {object} filters keyed by field id
 */
function filtersAsViewPayload(filter, columns) {
	const configs = getActiveColumnConfigs() || []
	const fields = getAvailableFields() || []
	const filters = {}
	for (const [fieldName, valueSet] of filter.getActiveFilters()) {
		if (!valueSet || valueSet.size === 0) continue
		// applyView resolves a stored filter by looking its field_id up in the
		// view's own columns, so the id written here has to be the one that
		// column carries — otherwise the filter is silently dropped on read.
		const col = columns.find(c => c.field_name === fieldName)
		const key = col?.field_id
			?? configs.find(c => c.field_name === fieldName)?.field_id
			?? fields.find(f => f.field_name === fieldName)?.id
		if (key === undefined) continue
		filters[key] = [...valueSet].join(',')
	}
	return filters
}

/**
 * The columns to store alongside the filters.
 *
 * When updating a view, its own columns are kept — the user changed filters,
 * not columns. For a new view the current column configuration is taken, so
 * the view opens looking like the list the user is looking at now, highlight
 * rules included.
 *
 * @param {object|null} view the view being updated, or null for a new one
 * @return {Array<object>} column configuration
 */
function columnsForSave(view) {
	if (view?.columns?.length) return columnsWithoutRules(view.columns)
	return columnsForNewView(getActiveColumnConfigs())
}

/**
 * Open the dialog for the filters currently set on this instance.
 *
 * @param {object} filter the filter instance the chip belongs to
 */
export async function openSaveFiltersDialog(filter) {
	// When it does not load, loadPart says to reload and nothing changes.
	const SaveFiltersDialog = await loadSaveDialog().catch(() => null)
	if (!SaveFiltersDialog) return

	closeDialog()

	const view = getActiveView()
	const canManage = getCanManageViews()

	const host = document.createElement('div')
	host.id = DIALOG_ID
	document.body.appendChild(host)

	const app = createApp({
		render: () => h(SaveFiltersDialog, {
			view,
			canManage,
			filterSummary: summariseFilters(filter),
			onClose: closeDialog,
			onSave: (payload) => onSave(filter, view, payload),
		}),
	})
	app.config.globalProperties.t = translate
	app.config.globalProperties.n = translatePlural
	watchLoading(app).mount(host)
	host._vueApp = app
}

/**
 * Write the filters to an existing view or to a new one.
 *
 * @param {object} filter the filter instance
 * @param {object|null} view the view that was active when the dialog opened
 * @param {{mode: string, name: string}} payload what the dialog collected
 */
async function onSave(filter, view, payload) {
	const gfId = getActiveGroupfolderId()
	const updating = payload.mode === 'update' && view
	const columns = columnsForSave(updating ? view : null)
	const filters = filtersAsViewPayload(filter, columns)

	try {
		if (updating) {
			await axios.put(
				generateUrl('/apps/metavox/api/groupfolders/{gfId}/views/{viewId}', {
					gfId, viewId: view.id,
				}),
				{
					name: view.name,
					is_default: view.is_default,
					columns,
					filters,
					sort_field: view.sort_field ?? null,
					sort_order: view.sort_order ?? null,
				},
			)
		} else {
			await axios.post(
				generateUrl('/apps/metavox/api/groupfolders/{gfId}/views', { gfId }),
				{
					name: payload.name,
					is_default: false,
					columns,
					filters,
					sort_field: null,
					sort_order: null,
				},
			)
		}

		closeDialog()

		// Refresh the views so the picker lists the new one, and so an
		// updated view carries its new filters the next time it is opened.
		const result = await fetchViews(gfId)
		setActiveViews(result.views)
		if (window._metavoxAllGfData?.[gfId]) {
			window._metavoxAllGfData[gfId].views = result.views
		}
		// No reset here: applyView below re-applies the filters from the view
		// that now stores them, which is what the user should keep seeing.
		// The save action disappears on its own, because the menu compares
		// the filters in effect against the ones the view holds and they are
		// now the same.

		// Rebuild the bar from the module that owns it, imported lazily to
		// keep this file out of a cycle with ViewManager.
		const { injectViewTabs, applyView } = await import('./ViewManager.js')
		const fresh = result.views.find(v => updating
			? String(v.id) === String(view.id)
			: v.name === payload.name)
		if (fresh) {
			// applyView rebuilds the bar itself, so calling injectViewTabs as
			// well left a second, stale picker behind with its menu open.
			applyView(fresh)
		} else {
			injectViewTabs(result.views)
		}

		showSuccess(updating
			// TRANSLATORS Confirmation message after the current filters were saved into an existing view
			? t('metavox', 'View updated')
			// TRANSLATORS Confirmation message after the current filters were saved as a new view
			: t('metavox', 'View created'))
	} catch (e) {
		console.error('MetaVox: Failed to save filters as a view', e)
		// TRANSLATORS Error message; {reason} is the server's error text, e.g. "Could not save the view: Internal server error"
		showError(t('metavox', 'Could not save the view: {reason}', {
			// TRANSLATORS Fallback error reason when the server gives none; shown as {reason} in an error message, e.g. "Could not save the view: {reason}"
			reason: errorText(e, t('metavox', 'Internal server error')),
		}))
	}
}
