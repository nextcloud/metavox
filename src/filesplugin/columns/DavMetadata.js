/**
 * MetaVox DavMetadata — the nc:metavox WebDAV property the server adds to
 * Files app listings (lib/Dav/MetadataPropertyPlugin.php).
 *
 * Value per node: {"v":1,"g":<team folder>,"e":1,"f":{field:value},"t":[fields]}
 *   e  the user may edit the metadata
 *   t  fields whose value in f was cut off (the full value comes from the API)
 */

export const DAV_PROP = 'nc:metavox'

// The webdav client drops the namespace prefix.
export const NODE_ATTR = 'metavox'

/**
 * Add the property to the Files app's own PROPFIND lists (v3: NC 31/32,
 * v4: NC 33+). Never creates a list: Files fills a missing one with its own
 * defaults, which we would otherwise replace.
 *
 * @return {boolean} whether a list was found
 */
export function registerMetaVoxDavProperty() {
	let found = false
	for (const list of [window._nc_dav_properties, window._nc_files_scope?.v4_0?.davProperties]) {
		if (Array.isArray(list)) {
			if (!list.includes(DAV_PROP)) {
				list.push(DAV_PROP)
			}
			found = true
		}
	}
	return found
}

/**
 * @param {object} node a Files app node
 * @return {{gf: ?number, canWrite: boolean, values: Object<string, string>, truncated: string[]}|null}
 *   null when the server did not serve the property: unknown, never "empty"
 */
export function decodeNode(node) {
	const raw = node?.attributes?.[NODE_ATTR]
	// A JSON object always starts with '{', so the XML parser never turned it into a number.
	if (typeof raw !== 'string' || raw.charCodeAt(0) !== 0x7B) {
		return null
	}
	let doc
	try {
		doc = JSON.parse(raw)
	} catch (e) {
		return null
	}
	if (!doc || doc.v !== 1) {
		return null
	}

	const values = {}
	if (doc.f && typeof doc.f === 'object') {
		for (const [field, value] of Object.entries(doc.f)) {
			if (typeof value === 'string' && value !== '') {
				values[field] = value
			}
		}
	}
	return {
		gf: Number.isInteger(doc.g) ? doc.g : null,
		canWrite: doc.e === 1,
		values,
		truncated: Array.isArray(doc.t) ? doc.t.map(String).filter((field) => field in values) : [],
	}
}
