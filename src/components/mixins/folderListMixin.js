import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { isFieldError } from '../../utils/errorText.js'
import { missingRequired } from '../../utils/requiredFields.js'

/**
 * The team folder list, shared by the admin page (ManageGroupfolders) and the
 * personal page (MetaVoxPersonal).
 *
 * Both screens show the same list of the same folders and open the same two
 * dialogs on them. What differs is only which folders they may see: the admin
 * page pages through every folder on the server, the personal page shows the
 * ones the user can reach. So the host supplies the folders and this mixin
 * supplies everything done with them.
 *
 * Ten of these methods were byte-identical in the two files, and four more
 * differed only in the name of the array they read. That is the shape that
 * produced `split(';#'.filter(...))` — a correct line in one twin and not the
 * other — so they live here once.
 *
 * ## Host contract
 *
 * The host **must** provide:
 *
 * - `folderSource()` — the folders this screen may show, newest state. The
 *   admin page returns every loaded folder (so a search can reach past the
 *   current page); the personal page returns the accessible ones.
 * - `allFields` — every field definition, used to split folder from file
 *   fields.
 * - the state this mixin reads and writes: `searchQuery`, `configFolderId`,
 *   `assignedFields`, `tempAssignedFields`, `loadingFields`, `savingFields`,
 *   `showMetadataModal`, `currentGroupfolderId`, `currentMetadataFields`,
 *   `currentMetadataValues`, `savingMetadata`.
 * - `saveFieldsConfiguration(id, opts)` and `saveGroupfolderMetadata()` —
 *   the two saves, which differ per screen because the endpoints and the
 *   permission they need differ.
 * - `editGroupfolderMetadata(folder)` — opening the metadata dialog, which
 *   loads different data per screen.
 *
 * It also completes `defaultValuesMixin`'s contract (`getAssignedFieldIds`,
 * `getFileFields`), so a host that uses both only has to provide
 * `persistFieldConfig`.
 */
export default {
	data() {
		return {
			// Why a folder field cannot be saved: field name => message, under the field.
			metadataErrors: {},
		}
	},

	computed: {
		/** Folder definitions that describe the folder itself. */
		groupfolderMetadataFields() {
			return this.allFields.filter(field =>
				field.applies_to_groupfolder === 1 || field.applies_to_groupfolder === '1',
			)
		},

		/**
		 * Field definitions that describe files inside the folder. A missing
		 * or null column means 0, which is why this is not the negation of
		 * the test above.
		 */
		fileMetadataFields() {
			return this.allFields.filter(field =>
				field.applies_to_groupfolder === 0
				|| field.applies_to_groupfolder === '0'
				|| !field.applies_to_groupfolder,
			)
		},

		/** The list as shown: everything, or the matches while searching. */
		filteredGroupfolders() {
			const folders = this.folderSource()
			if (!this.searchQuery) {
				return folders
			}
			const query = this.searchQuery.toLowerCase().trim()
			return folders.filter(gf =>
				gf.mount_point && gf.mount_point.toLowerCase().includes(query),
			)
		},

		/**
		 * The count answers the list below it, so a search narrows it — it
		 * used to read the unfiltered total, so narrowing eight folders to
		 * two still said "8 folders" above two rows. "Found" is what makes a
		 * small number read as a result rather than as a shrinking
		 * collection.
		 */
		folderCount() {
			const count = this.filteredGroupfolders.length
			return this.searchQuery
				// TRANSLATORS %n is the number of Team folders that match the search; shown beside the list heading
				? n('metavox', '%n folder found', '%n folders found', count)
				// TRANSLATORS %n is the number of Team folders in the list; shown beside the list heading
				: n('metavox', '%n folder', '%n folders', count)
		},

		/** The folder the Configure fields dialog is acting on. */
		configFolderName() {
			const gf = this.folderSource().find(f => f.id === this.configFolderId)
			return gf ? gf.mount_point : ''
		},

		/** The footer as configuration; NcDialog puts the primary one last. */
		configDialogButtons() {
			const saving = !!this.savingFields[this.configFolderId]
			return [
				{
					// TRANSLATORS Button that closes a dialog without saving
					label: t('metavox', 'Cancel'),
					disabled: saving,
					callback: () => this.closeFieldsConfig(this.configFolderId),
				},
				{
					label: saving
						// TRANSLATORS Dialog button label while the changes are being saved
						? t('metavox', 'Saving …')
						// TRANSLATORS Button that saves which fields a Team folder uses
						: t('metavox', 'Save configuration'),
					variant: 'primary',
					disabled: saving,
					// false keeps the dialog open, so a save that fails does
					// not discard the form.
					callback: () => { this.saveFieldsConfiguration(this.configFolderId); return false },
				},
			]
		},

		metadataDialogButtons() {
			return [
				{
					// TRANSLATORS Button that closes a dialog without saving
					label: t('metavox', 'Cancel'),
					disabled: this.savingMetadata,
					callback: () => this.closeMetadataModal(),
				},
				{
					label: this.savingMetadata
						// TRANSLATORS Dialog button label while the changes are being saved
						? t('metavox', 'Saving …')
						// TRANSLATORS Button that saves the changes in a dialog
						: t('metavox', 'Save'),
					variant: 'primary',
					disabled: this.savingMetadata
						|| this.currentMetadataFields.length === 0
						|| this.loadingMetadata,
					callback: () => { this.saveGroupfolderMetadata(); return false },
				},
			]
		},
	},

	methods: {
		/** Every field assigned to the folder, of either kind. */
		getAssignedFieldsCount(groupfolderId) {
			return (this.assignedFields[groupfolderId] || []).length
		},

		/**
		 * Only the fields the metadata dialog can edit. This is the count the
		 * row acts on: it decides whether Edit metadata is enabled and what a
		 * click on the row opens, so both must read it from here.
		 */
		getAssignedGroupfolderFieldsCount(groupfolderId) {
			const assignedIds = this.assignedFields[groupfolderId] || []
			return this.groupfolderMetadataFields.filter(field =>
				assignedIds.includes(field.id),
			).length
		},

		/**
		 * Clicking the row opens whichever of the two actions the folder can
		 * do. Editing metadata is the everyday one but needs fields to edit —
		 * it is disabled at zero — so a folder without any opens the
		 * configuration that gets it there rather than swallowing the click.
		 * Both read the same count, so the row can never open a dialog the
		 * menu refuses.
		 */
		openGroupfolder(groupfolder, event) {
			// NcListItem emits `click` for every click inside the row and
			// filters nothing (its onClick emits, then only checks modifier
			// keys), so without this the ... menu also fires this handler and
			// a dialog appears behind the open menu.
			if (event?.target?.closest?.('.action-item, .action-item__menu, [role="menu"]')) {
				return
			}
			if (this.getAssignedGroupfolderFieldsCount(groupfolder.id) > 0) {
				this.editGroupfolderMetadata(groupfolder)
			} else {
				this.toggleFieldsConfiguration(groupfolder.id)
			}
		},

		/**
		 * One dialog, so opening another folder replaces it — and so does
		 * opening the other kind. §3 forbids a dialog on top of a dialog,
		 * and both of these open from the same row menu: with the config
		 * dialog up, a row click used to stack the metadata dialog over it,
		 * measured as two dialogs on screen at once.
		 */
		toggleFieldsConfiguration(groupfolderId) {
			this.$nextTick(() => this.closeRowMenu())
			this.showMetadataModal = false
			this.configFolderId = groupfolderId
			this.tempAssignedFields[groupfolderId] = [...(this.assignedFields[groupfolderId] || [])]
			this.loadingFields[groupfolderId] = false
		},

		/** Cancelling discards the pending ticks. */
		closeFieldsConfig(groupfolderId) {
			this.tempAssignedFields[groupfolderId] = [...(this.assignedFields[groupfolderId] || [])]
			this.configFolderId = null
			this.stopDefaultsPolling?.(groupfolderId)
		},

		/**
		 * NcActions closes its menu when the click reaches its own handler,
		 * but opening a dialog in the same tick interrupts that: the dialog
		 * takes focus, so the click-outside and Escape that would dismiss the
		 * popper never arrive and it sits over the page behind the dialog.
		 * Clicking the expanded trigger is the one thing that toggles it
		 * shut, and it has to happen after the click settles.
		 */
		closeRowMenu() {
			document.querySelector('[aria-haspopup="menu"][aria-expanded="true"]')?.click()
		},

		closeMetadataModal() {
			this.showMetadataModal = false
			this.currentGroupfolderId = null
			this.currentMetadataFields = []
			this.currentMetadataValues = {}
			this.metadataErrors = {}
			this.savingMetadata = false
		},

		/** The values from the form; a field that changes loses its message. */
		setMetadataValues(values) {
			const errors = { ...this.metadataErrors }
			for (const name of Object.keys(errors)) {
				if (values[name] !== this.currentMetadataValues[name]) delete errors[name]
			}
			this.metadataErrors = errors
			this.currentMetadataValues = values
		},

		/** Required is required: false, with a message under each, while one is empty. */
		checkRequiredMetadata() {
			this.metadataErrors = missingRequired(this.currentMetadataFields, this.currentMetadataValues)
			return Object.keys(this.metadataErrors).length === 0
		},

		/** A value the server refused goes under its field; false when the refusal is about something else. */
		showMetadataRefusal(error) {
			const refused = isFieldError(error) ? error.response.data.fields : {}
			const names = Object.keys(refused)
			if (names.length === 0 || !names.every((name) => this.currentMetadataFields.some((f) => f.field_name === name))) {
				return false
			}
			this.metadataErrors = { ...refused }
			return true
		},

		// --- defaultValuesMixin host contract ---

		getAssignedFieldIds(groupfolderId) {
			return this.tempAssignedFields[groupfolderId] || []
		},

		getFileFields() {
			return this.fileMetadataFields
		},
	},
}
