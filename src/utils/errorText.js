/**
 * The message to show for a failed request: the server's own text when it
 * explains what went wrong, our translated fallback for an internal error
 * (500, a generic English "Internal server error") or when there is no answer.
 *
 * @param {Error} error the axios error
 * @param {string} fallback translated text
 * @return {string}
 */
export function errorText(error, fallback) {
	const response = error?.response
	const text = response?.data?.error
	if (!response || response.status === 500 || typeof text !== 'string' || text === '') {
		return fallback
	}
	return text
}

/**
 * Whether the server refused the input and said where: a 400 with a fields map.
 *
 * @param {Error} error the axios error
 * @return {boolean}
 */
export function isFieldError(error) {
	const response = error?.response
	const fields = response?.data?.fields
	return response?.status === 400 && fields !== null && typeof fields === 'object'
		&& !Array.isArray(fields) && Object.keys(fields).length > 0
}
