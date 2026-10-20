/**
 * MetaVox FilterSortCore — filtering and sorting nodes on their metadata. Pure.
 */

// For filtering, an unchecked checkbox ('0'/'false') also counts as empty.
const isBlank = (value) => !value || value === '0' || value === 'false'

/**
 * @param {Map<string, Set<string>>} activeFilters field name => chosen values ('0' = empty)
 * @return {{fieldName: string, wanted: Set<string>, matchEmpty: boolean}[]}
 */
export function compileFilters(activeFilters) {
	const filters = []
	for (const [fieldName, values] of activeFilters) {
		if (values.size === 0) continue
		filters.push({ fieldName, wanted: new Set([...values].filter((v) => v !== '0')), matchEmpty: values.has('0') })
	}
	return filters
}

/** AND between fields, OR within a field; ';#' lists match on any item. */
export function matchesFilters(meta, filters) {
	for (const { fieldName, wanted, matchEmpty } of filters) {
		const value = meta?.[fieldName]
		if (isBlank(value)) {
			if (matchEmpty) continue
			return false
		}
		const text = String(value)
		const items = text.includes(';#') ? text.split(';#').map((item) => item.trim()) : [text]
		if (!items.some((item) => wanted.has(item))) return false
	}
	return true
}

const NUMERIC = new Set(['number', 'integer', 'float'])

/**
 * What a value sorts on, worked out once: a date was parsed again at every
 * comparison. textOf: what a value reads as, when it stores something else
 * (people by name).
 */
function sortKey(value, fieldType, textOf = String) {
	if (value === undefined || value === null || value === '') return { empty: true }
	let num = NaN
	if (NUMERIC.has(fieldType)) num = parseFloat(value)
	else if (fieldType === 'date') num = new Date(value).getTime()
	return { empty: false, value, num, text: textOf(value) }
}

/** Missing or '' values go last in both directions. */
function compareKeys(a, b, fieldType, direction) {
	if (a.empty || b.empty) return a.empty === b.empty ? 0 : (a.empty ? 1 : -1)
	let result
	if ((NUMERIC.has(fieldType) || fieldType === 'date') && !Number.isNaN(a.num) && !Number.isNaN(b.num)) {
		result = a.num - b.num
	} else if (fieldType === 'checkbox' || fieldType === 'boolean') {
		result = (a.value === '1' ? 0 : 1) - (b.value === '1' ? 0 : 1)
	} else {
		result = a.text.localeCompare(b.text)
	}
	return direction === 'desc' ? -result : result
}

export function compareValues(a, b, fieldType, direction = 'asc') {
	return compareKeys(sortKey(a, fieldType), sortKey(b, fieldType), fieldType, direction)
}

/**
 * @param {object[]} nodes Files app nodes
 * @param {ReturnType<compileFilters>} filters
 * @param {?{fieldName: string, fieldType: string, direction: string, textOf?: function(string): string}} sort
 * @param {function(number): ?object} metaOf file id => metadata
 * @return {object[]} the matching nodes, sorted (ties keep their order)
 */
export function applyFilterSort(nodes, filters, sort, metaOf) {
	let result = filters.length ? nodes.filter((node) => !node.fileid || matchesFilters(metaOf(node.fileid), filters)) : nodes
	if (sort) {
		// Each file's key once, not twice per comparison.
		const keyed = result.map((node) => ({ node, key: sortKey(metaOf(node.fileid)?.[sort.fieldName], sort.fieldType, sort.textOf) }))
		keyed.sort((a, b) => compareKeys(a.key, b.key, sort.fieldType, sort.direction))
		result = keyed.map((entry) => entry.node)
	}
	return result
}

const OPTION_TYPES = new Set(['select', 'multiselect', 'multi_select', 'dropdown'])
// A file link with several files and People with several are lists too: one option per item.
const LIST_TYPES = new Set(['multiselect', 'multi_select', 'filelink', 'user'])

/**
 * Filter options per field: the defined options of a select field, yes/no for a
 * checkbox, otherwise the distinct values of these files, sorted like the column.
 *
 * @param {{field_name: string, field_type: string, field_options?: *}[]} configs
 * @param {object[]} nodes the files of the folder
 * @param {function(number): ?object} metaOf file id => metadata
 * @param {function(*): string[]} optionsOf field_options => the option values
 * @return {Object<string, string[]>}
 */
export function filterValues(configs, nodes, metaOf, optionsOf) {
	const result = {}
	for (const { field_name: field, field_type: type, field_options: options } of configs) {
		if (type === 'checkbox' || type === 'boolean') {
			result[field] = ['1', '0']
			continue
		}
		const defined = OPTION_TYPES.has(type)
			? optionsOf(options)
			: []
		if (defined.length > 0) {
			result[field] = defined
			continue
		}
		const values = new Set()
		for (const node of nodes) {
			const value = node.fileid ? metaOf(node.fileid)?.[field] : undefined
			if (value === undefined || value === null) continue
			// Like the filter itself: a ';#' list matches on each item.
			const items = LIST_TYPES.has(type) ? String(value).split(';#').map((item) => item.trim()) : [String(value)]
			items.filter((item) => item !== '').forEach((item) => values.add(item))
		}
		result[field] = [...values].sort((a, b) => compareValues(a, b, type, 'asc'))
	}
	return result
}

/** Ids of the nodes without values yet. A cut-off value is there: it is what the grid shows. */
export function missingIds(nodes, hasValues) {
	return nodes.map((node) => node.fileid).filter((id) => id && !hasValues(id))
}

/**
 * Per field, the option values that stand for a cut-off value, so the filter
 * can mark them like the grid does.
 *
 * @param {string[]} fields
 * @param {object[]} nodes
 * @param {function(number): ?object} metaOf
 * @param {function(number, string): boolean} isTruncated
 * @return {Object<string, Set<string>>}
 */
export function cutOffValues(fields, nodes, metaOf, isTruncated) {
	const result = {}
	for (const field of fields) {
		result[field] = new Set()
		for (const node of nodes) {
			const value = node.fileid && isTruncated(node.fileid, field) ? metaOf(node.fileid)?.[field] : undefined
			if (value !== undefined && value !== null) result[field].add(String(value))
		}
	}
	return result
}

/** Most options a filter section lists at once: thousands froze the page. The search finds the rest. */
export const LISTED_OPTIONS = 100

/** From this many options on, a filter section gets a search field. */
export const SEARCH_FROM = 10

/** How an option is found: case and accents do not matter. */
export function searchKey(text) {
	return String(text ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLocaleLowerCase()
}

/**
 * The options a filter section lists: those matching the search, at most
 * `limit`, and the checked ones among the rest, so no active filter is out of reach.
 *
 * @param {{value: string, key: string}[]} options in order; key from searchKey()
 * @param {string} query
 * @param {function(string): boolean} isChecked
 * @param {number} [limit]
 * @return {{listed: object[], matching: number}}
 */
export function listedOptions(options, query, isChecked, limit = LISTED_OPTIONS) {
	const wanted = searchKey(query.trim())
	const matching = wanted ? options.filter((option) => option.key.includes(wanted)) : options
	if (matching.length <= limit) {
		return { listed: matching, matching: matching.length }
	}
	const listed = matching.slice(0, limit)
	listed.push(...matching.slice(limit).filter((option) => isChecked(option.value)))
	return { listed, matching: matching.length }
}
