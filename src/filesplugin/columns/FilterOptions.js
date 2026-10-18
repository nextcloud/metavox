/**
 * MetaVox FilterOptions — the filter options of the folder you are in, from the
 * values the listing already brought, cut-off ones as the grid shows them. Only
 * missing values are loaded.
 */

import { parseToken } from '../../components/fields/filelinkUtils.js'
import { formatFieldValue } from '../../utils/fieldValue.js'
import { optionValues } from '../../utils/fieldOptions.js'
import { filelinkLabel, requestFilelinkName } from './FilelinkNames.js'
import { cutOffValues, filterValues, missingIds } from './FilterSortCore.js'
import { getListingNodes } from './ListingSync.js'
import { queueMetadataLoad } from './MetadataLoader.js'
import { hasValues, isTruncated } from './MetadataStore.js'
import { metadataCache } from './MetaVoxState.js'

// A Files node reads its id through a slow getter: once per node, not once per field.
const listedIds = () => getListingNodes().map((node) => ({ fileid: node.fileid }))

/**
 * @param {object[]} configs field configs (field_name, field_type, field_options)
 * @return {Promise<Object<string, string[]>>} field name => options
 */
export async function folderFilterValues(configs) {
	const nodes = listedIds()
	const missing = missingIds(nodes, hasValues)
	if (missing.length > 0) {
		// Without them the options would miss values; the options still come, from what there is.
		await queueMetadataLoad(missing).catch(() => {})
	}
	return filterValues(configs, nodes, (id) => metadataCache.get(id), optionValues)
}

/**
 * What an option reads as, in the dropdown and in the chip it produces. File
 * links show the file's name; the rest reads as everywhere else.
 *
 * @param {string} value stored value
 * @param {string} fieldType
 * @param {object} [field] field config, for a date's includeTime
 * @return {string}
 */
export function optionLabel(value, fieldType, field = null) {
	if (fieldType === 'filelink') {
		const token = parseToken(value)
		if (token.fileId != null) requestFilelinkName(token.fileId)
		return filelinkLabel(token).label
	}
	return formatFieldValue(value, fieldType, field) || value
}

/**
 * @param {object[]} configs field configs
 * @return {Object<string, Set<string>>} field name => options that stand for a cut-off value
 */
export function folderCutOffValues(configs) {
	return cutOffValues(configs.map((c) => c.field_name), listedIds(), (id) => metadataCache.get(id), isTruncated)
}
