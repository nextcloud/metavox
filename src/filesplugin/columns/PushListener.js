/**
 * MetaVox's notify_push events. listen() keeps the handlers through reconnects
 * and shares the socket with the other apps on the page.
 */
import { listen } from '@nextcloud/notify_push'

export const PUSH_EVENTS = ['metavox_metadata_changed', 'metavox_cell_locked', 'metavox_cell_unlocked']

/**
 * @param {function(string, object): void} handle called with the event name and its body
 */
export function listenForPushes(handle) {
	for (const name of PUSH_EVENTS) {
		listen(name, (event, body) => {
			try {
				handle(event, body ?? {})
			} catch (e) {
				// A push must never break the page.
			}
		})
	}
}
