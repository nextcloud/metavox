/** The major version of Nextcloud, from the page config ("35.0.0.10"). */
export function ncMajor() {
	const version = window.OC?.config?.version ?? window._oc_config?.version ?? ''
	return Number.parseInt(String(version).split('.')[0], 10) || 0
}
