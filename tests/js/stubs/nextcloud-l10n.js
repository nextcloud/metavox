/**
 * Stub for @nextcloud/l10n: identity translation so component output is
 * deterministic and readable in assertions.
 */
export const t = (app, text, vars) => {
	if (!vars) {
		return text
	}
	return text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? vars[k] : m))
}
/**
 * Picks the form the way the English rule does, then substitutes %n — the
 * real library does both, and a stub that only did the first let a test
 * assert on "%n file fields" and call it a pass. Verified against
 * @nextcloud/l10n: translatePlural('%n file field', '%n file fields', 0)
 * returns "0 file fields", not the raw pattern.
 */
export const n = (app, singular, plural, count, vars) =>
	t(app, count === 1 ? singular : plural, vars).replace(/%n/g, count)
export const translate = t
export const translatePlural = n
export const getLanguage = () => 'en'
export const getLocale = () => 'en'
export const getCanonicalLocale = () => 'en-US'
