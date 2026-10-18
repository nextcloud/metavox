/**
 * Helpers for the "File Link" field type.
 *
 * A `filelink` value holds one OR MORE file references, joined with the app's
 * ';#' delimiter. The server stores each as "<fileid>:" (e.g. "12:;#34:") and
 * never sends a path: names come from Nextcloud, as this user sees the file.
 * A token without an id is text (a picked path, or one we could not resolve).
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
 * Whether a picked path lies in the team folder at base ('' = no folder known).
 *
 * @param {string} path
 * @param {string} base
 * @return {boolean}
 */
export function isWithin(path, base) {
  if (!base) return true
  const root = base.replace(/\/+$/, '')
  return path === root || path.startsWith(root + '/')
}

/**
 * What a link shows: the file's current name, or for a token without id the
 * last part of its path. An id without a known name gives '': the caller
 * shows it as loading or unavailable.
 *
 * @param {{fileId: ?number, path: string}} token
 * @param {Object<number,string>} [resolvedNames] map fileId => current name
 * @return {string}
 */
export function displayName(token, resolvedNames) {
  if (token.fileId != null) {
    return (resolvedNames && resolvedNames[token.fileId]) || ''
  }
  const parts = (token.path || '').split('/')
  return parts[parts.length - 1] || token.path || ''
}
