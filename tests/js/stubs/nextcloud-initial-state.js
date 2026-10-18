/**
 * Stub for @nextcloud/initial-state. Returns the fallback unless a spec set the
 * key, or mocks this module itself.
 */
// A spec can set globalThis.__INITIAL_STATE__ = { 'app:key': value }.
export const loadState = (app, key, fallback = undefined) => {
	const value = globalThis.__INITIAL_STATE__?.[`${app}:${key}`]
	return value === undefined ? fallback : value
}
