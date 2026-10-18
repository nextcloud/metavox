/**
 * Stub for @nextcloud/axios. Tests that exercise network calls should override
 * these methods per-test (e.g. vi.spyOn(axios, 'get').mockResolvedValue(...)).
 * The defaults resolve empty so a component that fires a request on mount does
 * not explode.
 */
const resolve = () => Promise.resolve({ data: {} })

const axios = {
	get: resolve,
	post: resolve,
	put: resolve,
	patch: resolve,
	delete: resolve,
	request: resolve,
}

export default axios
