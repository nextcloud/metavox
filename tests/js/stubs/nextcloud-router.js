/**
 * Stub for @nextcloud/router: return a predictable URL so components that
 * build links can be asserted against.
 */
export const generateUrl = (url, params = {}) => {
	let out = url
	for (const [k, v] of Object.entries(params)) {
		out = out.replace(`{${k}}`, String(v))
	}
	return out
}
export const generateFilePath = (app, type, file) => `/apps/${app}/${type}/${file}`
export const generateOcsUrl = (url) => `/ocs/v2.php/${url}`
export const imagePath = (app, file) => `/apps/${app}/img/${file}`
export const linkTo = (app, file) => `/apps/${app}/${file}`
