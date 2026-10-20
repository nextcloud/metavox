/**
 * MetaVox AccountCells — an account, group or team as the grid draws it: an
 * avatar or an icon, and its name. Shared by the cell and its editor.
 */

import { generateUrl } from '@nextcloud/router'
import { svgIcon } from './CellFormat.js'

// Material Design Icons, as Nextcloud uses them: account-multiple, account-group.
const GROUP_ICON_PATH = 'M16 17V19H2V17S2 13 9 13 16 17 16 17M12.5 7.5A3.5 3.5 0 1 0 9 11A3.5 3.5 0 0 0 12.5 7.5M15.94 13A5.32 5.32 0 0 1 18 17V19H22V17S22 13.37 15.94 13M15 4A3.39 3.39 0 0 0 13.07 4.59A5 5 0 0 1 13.07 10.41A3.39 3.39 0 0 0 15 11A3.5 3.5 0 0 0 15 4Z'
const TEAM_ICON_PATH = 'M12,5.5A3.5,3.5 0 0,1 15.5,9A3.5,3.5 0 0,1 12,12.5A3.5,3.5 0 0,1 8.5,9A3.5,3.5 0 0,1 12,5.5M5,8C5.56,8 6.08,8.15 6.53,8.42C6.38,9.85 6.8,11.27 7.66,12.38C7.16,13.34 6.16,14 5,14A3,3 0 0,1 2,11A3,3 0 0,1 5,8M19,8A3,3 0 0,1 22,11A3,3 0 0,1 19,14C17.84,14 16.84,13.34 16.34,12.38C17.2,11.27 17.62,9.85 17.47,8.42C17.92,8.15 18.44,8 19,8M5.5,18.25C5.5,16.18 8.41,14.5 12,14.5C15.59,14.5 18.5,16.18 18.5,18.25V20H5.5V18.25M0,20V18.5C0,17.11 1.89,15.94 4.45,15.6C3.86,16.28 3.5,17.22 3.5,18.25V20H0M24,20H20.5V18.25C20.5,17.22 20.14,16.28 19.55,15.6C22.11,15.94 24,17.11 24,18.5V20Z'

/**
 * An account's avatar, or the icon of a group or team.
 *
 * @param {{id: string, kind: string}} person from accountsOf()
 * @param {number} size in pixels
 * @return {HTMLElement}
 */
export function accountIcon(person, size) {
	if (person.kind === 'user') {
		const avatar = document.createElement('img')
		avatar.className = 'metavox-cell-avatar'
		avatar.src = generateUrl('/avatar/{userId}/{size}', { userId: person.id, size })
		avatar.width = size
		avatar.height = size
		avatar.alt = ''
		avatar.onerror = () => { avatar.style.display = 'none' }
		return avatar
	}
	const icon = document.createElement('span')
	icon.className = 'metavox-cell-avatar metavox-cell-account-icon'
	icon.style.width = icon.style.height = size + 'px'
	icon.appendChild(svgIcon(person.kind === 'group' ? GROUP_ICON_PATH : TEAM_ICON_PATH))
	return icon
}

/**
 * Its name, muted when it is gone or hidden from you.
 *
 * @param {{kind: string, label: string, known: boolean}} person from accountsOf()
 * @return {HTMLElement}
 */
export function accountNameElement(person) {
	const name = document.createElement('span')
	name.className = 'metavox-cell-user-name'
	if (person.kind === 'team' && !person.known) name.classList.add('metavox-cell-user-missing')
	name.textContent = person.label
	return name
}
