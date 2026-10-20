/**
 * The field types a user can pick, with their translated names. One list for
 * the field editor and every place that shows a field's type, so no screen
 * shows the raw id (multiselect, filelink) instead.
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * @return {Array<{id: string, label: string}>}
 */
export function fieldTypeOptions() {
	return [
		// TRANSLATORS Noun: field type for a single line of text, shown in the field type picker
		{ id: 'text', label: t('metavox', 'Text') },
		// TRANSLATORS Noun: field type for several lines of text, shown in the field type picker
		{ id: 'textarea', label: t('metavox', 'Long text') },
		// TRANSLATORS Noun: field type for a number, shown in the field type picker
		{ id: 'number', label: t('metavox', 'Number') },
		// TRANSLATORS Noun: field type for a date, shown in the field type picker
		{ id: 'date', label: t('metavox', 'Date') },
		// TRANSLATORS Noun: field type where one value is picked from a list, shown in the field type picker
		{ id: 'select', label: t('metavox', 'Dropdown') },
		// TRANSLATORS Noun: field type where several values are picked from a list, shown in the field type picker
		{ id: 'multiselect', label: t('metavox', 'Multi-select') },
		// TRANSLATORS Noun: field type for a yes/no tick box, shown in the field type picker
		{ id: 'checkbox', label: t('metavox', 'Checkbox') },
		// TRANSLATORS Noun: field type for a web address (URL), shown in the field type picker
		{ id: 'url', label: t('metavox', 'URL') },
		// TRANSLATORS Noun: field type that holds Nextcloud accounts, groups or teams, shown in the field type picker
		{ id: 'user', label: t('metavox', 'People and groups') },
		// TRANSLATORS Noun: field type that links to one or more files, shown in the field type picker
		{ id: 'filelink', label: t('metavox', 'File link') },
	]
}

/**
 * The translated name of a field type; an unknown id is shown as it is.
 *
 * @param {string} type
 * @return {string}
 */
export function fieldTypeLabel(type) {
	return fieldTypeOptions().find((option) => option.id === type)?.label ?? type
}
