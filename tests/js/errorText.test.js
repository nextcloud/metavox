import { describe, expect, it } from 'vitest'
import { errorText, isFieldError } from '../../src/utils/errorText.js'

const failed = (status, data) => ({ message: `Request failed with status code ${status}`, response: { status, data } })

describe('errorText', () => {
	it('shows our translated text for an internal server error', () => {
		expect(errorText(failed(500, { error: 'Internal server error', requestId: 'abc' }), 'Opslaan mislukt')).toBe('Opslaan mislukt')
	})

	it('shows the server\'s explanation of a refusal', () => {
		expect(errorText(failed(403, { error: 'Manage fields permission required' }), 'Opslaan mislukt')).toBe('Manage fields permission required')
		expect(errorText(failed(503, { error: 'No AI text generation provider available' }), 'AI mislukt')).toBe('No AI text generation provider available')
	})

	it('shows what each refused field takes, not "Validation failed"', () => {
		const refused = (fields) => failed(400, { error: 'Validation failed', reason: 'invalid_input', fields })
		expect(errorText(refused({ value: 'Voer een getal in' }), 'Opslaan mislukt')).toBe('Voer een getal in')
		expect(errorText(refused({ a: 'Dit veld is verplicht', b: 'Dit veld is verplicht', c: 'Kies een bestaand account' }), 'Opslaan mislukt'))
			.toBe('Dit veld is verplicht Kies een bestaand account')
		// A reason that is no text is left out; with none left, the server's text.
		expect(errorText(refused({ columns: { 0: 'x' } }), 'Opslaan mislukt')).toBe('Validation failed')
	})

	it('shows our text when there is no usable answer', () => {
		expect(errorText({ message: 'Network Error' }, 'Opslaan mislukt')).toBe('Opslaan mislukt')
		expect(errorText(failed(404, '<html>Not found</html>'), 'Opslaan mislukt')).toBe('Opslaan mislukt')
		expect(errorText(failed(400, { error: '' }), 'Opslaan mislukt')).toBe('Opslaan mislukt')
		expect(errorText(undefined, 'Opslaan mislukt')).toBe('Opslaan mislukt')
	})
})

describe('isFieldError', () => {
	it('is a refusal that names the fields', () => {
		expect(isFieldError(failed(400, { error: 'Validation failed', reason: 'invalid_input', fields: { name: 'Too long' } }))).toBe(true)
	})

	it('is nothing else', () => {
		expect(isFieldError(failed(400, { error: 'View name is required', reason: 'invalid_input' }))).toBe(false)
		expect(isFieldError(failed(400, { fields: [] }))).toBe(false)
		expect(isFieldError(failed(400, { fields: {} }))).toBe(false)
		expect(isFieldError(failed(403, { fields: { name: 'x' } }))).toBe(false)
		expect(isFieldError(failed(500, { error: 'Internal server error' }))).toBe(false)
		expect(isFieldError({ message: 'Network Error' })).toBe(false)
	})
})
