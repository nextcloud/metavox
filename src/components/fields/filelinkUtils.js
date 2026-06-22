/**
 * Helpers for the "File Link" field type.
 *
 * A `filelink` value holds one OR MORE file references. Each reference is
 * "<fileid>:<path>"; multiple references are joined with the app's ';#'
 * delimiter (e.g. "12:/a.pdf;#34:/b.docx"). A single reference is just a
 * one-element list, so old single-value data keeps working unchanged. The
 * fileid is canonical and survives renames/moves; the path is a display cache.
 * See issue #73 and lib/Service/FileReferenceService.php (same contract).
 */

export const MULTI_DELIM = ';#'

/**
 * Parse one "<fileid>:<path>" token. Splits on the FIRST colon only; the
 * prefix counts as a fileid only when it is all digits, otherwise the whole
 * token is a legacy bare path (fileId null).
 *
 * @param {string} token
 * @return {{fileId: ?number, path: string}}
 */
export function parseToken(token) {
  const t = (token || '').trim()
  const colon = t.indexOf(':')
  if (colon !== -1) {
    const prefix = t.slice(0, colon)
    if (prefix !== '' && /^\d+$/.test(prefix)) {
      return { fileId: parseInt(prefix, 10), path: t.slice(colon + 1) }
    }
  }
  return { fileId: null, path: t }
}

/**
 * Parse a stored value (single token or ';#'-joined multi) into tokens.
 *
 * @param {string} value
 * @return {Array<{fileId: ?number, path: string}>}
 */
export function parseValue(value) {
  if (!value) return []
  const raw = value.indexOf(MULTI_DELIM) === -1 ? [value] : value.split(MULTI_DELIM)
  return raw
    .filter(p => (p || '').trim() !== '')
    .map(parseToken)
}

/**
 * Format one reference as "<fileid>:<path>" (or just the path when no id).
 *
 * @param {?number} fileId
 * @param {string} path
 * @return {string}
 */
export function formatToken(fileId, path) {
  return (fileId !== null && fileId !== undefined) ? `${fileId}:${path}` : path
}

/**
 * Join several tokens into a stored multi value.
 *
 * @param {Array<{fileId: ?number, path: string}>} tokens
 * @return {string}
 */
export function joinTokens(tokens) {
  return tokens.map(t => formatToken(t.fileId, t.path)).join(MULTI_DELIM)
}

/**
 * Best-effort display name for a token: the server-resolved current name when
 * available, else the basename of the cached path.
 *
 * @param {{fileId: ?number, path: string}} token
 * @param {Object<number,string>} [resolvedNames] map fileId => current name
 * @return {string}
 */
export function displayName(token, resolvedNames) {
  if (resolvedNames && token.fileId != null && resolvedNames[token.fileId]) {
    return resolvedNames[token.fileId]
  }
  const parts = (token.path || '').split('/')
  return parts[parts.length - 1] || token.path || ''
}
