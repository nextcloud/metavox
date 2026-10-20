<template>
	<div :id="embedded ? undefined : 'metavox-sidebar'" class="metavox-sidebar">
		<!-- Loading State -->
		<NcEmptyContent
			v-if="loading"
			:name="loadingName">
			<template #icon>
				<NcLoadingIcon :size="32" />
			</template>
		</NcEmptyContent>

		<!-- Error State -->
		<div v-else-if="error" class="metavox-sidebar__state">
			<NcNoteCard type="error" :text="error" />
			<div class="metavox-sidebar__state-actions">
				<NcButton variant="secondary" @click="reload">
					<!-- TRANSLATORS Button that tries to load the metadata again after an error -->
					{{ t('metavox', 'Retry') }}
				</NcButton>
			</div>
		</div>

		<!-- No Groupfolder -->
		<!-- TRANSLATORS Heading shown in the sidebar when a file is not in a Team folder -->
		<NcEmptyContent :name="t('metavox', 'No metadata available')"
			v-else-if="!groupfolderId"
			:description="t('metavox', 'Metadata is only available for files in a Team folder.')">
			<template #icon>
				<FolderOffIcon :size="20" />
			</template>
		</NcEmptyContent>

		<!-- No Permission -->
		<!-- TRANSLATORS Heading shown in the sidebar when the user may not see the metadata of the file -->
		<NcEmptyContent :name="t('metavox', 'No access to metadata')"
			v-else-if="!hasPermission"
			:description="t('metavox', 'You do not have permission to view or edit metadata for this file.')">
			<template #icon>
				<LockIcon :size="20" />
			</template>
		</NcEmptyContent>

		<!-- Metadata Editor -->
		<div v-else class="metadata-content">
			<!-- SCENARIO 1: Groupfolder Root - Show only teamfolder metadata (read-only) -->
			<div v-if="isGroupfolderRoot">
				<div v-if="groupfolderFields.length > 0">
					<NcNoteCard
						type="info"
						:text="t('metavox', 'This metadata applies to the whole Team folder. Only administrators can change it.')" />
					<!-- Read-only display as text -->
					<dl class="field-display-list">
						<div
							v-for="field in groupfolderFields"
							:key="field.id"
							class="field-display">
							<dt class="field-display__label">{{ field.field_label }}</dt>
							<dd class="field-display__value">
								<!-- A File link points at a real file, so it opens like one —
								     the same icon and link the "Linked from" list uses for
								     the other direction. -->
								<ul v-if="fileLinks(field).length" class="file-link-values">
									<li v-for="link in fileLinks(field)" :key="link.key" class="file-link-value">
										<a v-if="link.fileId"
											class="file-link-value__link"
											:href="fileUrl(link.fileId)"
											target="_blank"
											rel="noopener noreferrer">
											<FileIcon :size="16" aria-hidden="true" />
											<span class="file-link-value__name">{{ link.name }}</span>
										</a>
										<span v-else class="file-link-value__missing">
											<FileIcon :size="16" aria-hidden="true" />
											<span class="file-link-value__name">{{ link.name }}</span>
										</span>
									</li>
								</ul>
								<span v-else-if="people(field)" class="account-values">
									<AccountBubble v-for="person in people(field)" :key="person.id" :account="person" />
								</span>
								<template v-else>{{ formatFieldValue(field) }}</template>
							</dd>
						</div>
					</dl>
				</div>
				<!-- TRANSLATORS Heading shown in the sidebar when the Team folder itself has no metadata fields -->
				<NcEmptyContent :name="t('metavox', 'No Team folder metadata')"
					v-else
					:description="t('metavox', 'Ask an administrator to add metadata fields for this Team folder.')">
					<template #icon>
						<TagOffIcon :size="20" />
					</template>
				</NcEmptyContent>
			</div>

			<!-- SCENARIO 2: Items in Groupfolder - Show both teamfolder info and item metadata -->
			<div v-else-if="isInGroupfolder">
				<!-- Teamfolder Information Section (read-only, collapsible) -->
				<section v-if="groupfolderFields.length > 0" class="teamfolder-info">
					<h3 class="teamfolder-info__heading">
						<button
							:id="teamfolderToggleId"
							type="button"
							class="teamfolder-info__toggle"
							:aria-expanded="teamfolderInfoExpanded ? 'true' : 'false'"
							:aria-controls="teamfolderPanelId"
							@click="teamfolderInfoExpanded = !teamfolderInfoExpanded">
							<ChevronDownIcon
								:size="20"
								class="teamfolder-info__chevron"
								:class="{ 'teamfolder-info__chevron--collapsed': !teamfolderInfoExpanded }" />
												<!-- TRANSLATORS Noun: heading of the collapsible section with the metadata of the whole Team folder -->
							<span>{{ t('metavox', 'Team folder metadata') }}</span>
						</button>
					</h3>
					<div
						v-show="teamfolderInfoExpanded"
						:id="teamfolderPanelId"
						role="region"
						:aria-labelledby="teamfolderToggleId"
						class="teamfolder-info__content">
						<p class="teamfolder-info__hint">
							{{ t('metavox', 'Set by the administrator, the same for every file here.') }}
						</p>
						<dl class="field-display-list">
							<div
								v-for="field in groupfolderFields"
								:key="field.id"
								class="field-display">
								<dt class="field-display__label">{{ field.field_label }}</dt>
								<dd class="field-display__value">
									<span v-if="people(field)" class="account-values">
										<AccountBubble v-for="person in people(field)" :key="person.id" :account="person" />
									</span>
									<template v-else>{{ formatFieldValue(field) }}</template>
								</dd>
							</div>
						</dl>
					</div>
				</section>

				<!-- Item metadata. The sidebar reads; editing happens in a dialog,
				     where there is room for every field's description. At 346px
				     a form of twelve inputs scrolls for 1.4 screens; the same
				     values as a read-only list fit without scrolling. -->
				<section v-if="itemFields.length > 0" class="metadata-section">
					<div class="metadata-section__header">
						<h3 class="metadata-section__heading">
												{{ itemHeading }}
						</h3>
						<NcButton v-if="canEdit"
							ref="editButton"
							variant="tertiary"
							:aria-label="editLabel"
							@click="openEditDialog">
							<template #icon>
								<PencilIcon :size="20" />
							</template>
						</NcButton>
					</div>

					<dl class="field-display-list">
						<div
							v-for="field in itemFields"
							:key="field.id"
							class="field-display">
							<dt class="field-display__label">{{ field.field_label }}</dt>
							<dd class="field-display__value">
								<!-- A File link points at a real file, so it opens like one —
								     the same icon and link the "Linked from" list uses for
								     the other direction. -->
								<ul v-if="fileLinks(field).length" class="file-link-values">
									<li v-for="link in fileLinks(field)" :key="link.key" class="file-link-value">
										<a v-if="link.fileId"
											class="file-link-value__link"
											:href="fileUrl(link.fileId)"
											target="_blank"
											rel="noopener noreferrer">
											<FileIcon :size="16" aria-hidden="true" />
											<span class="file-link-value__name">{{ link.name }}</span>
										</a>
										<span v-else class="file-link-value__missing">
											<FileIcon :size="16" aria-hidden="true" />
											<span class="file-link-value__name">{{ link.name }}</span>
										</span>
									</li>
								</ul>
								<!-- People read as the people, as in the edit dialog. -->
								<span v-else-if="people(field)" class="account-values">
									<AccountBubble v-for="person in people(field)" :key="person.id" :account="person" />
								</span>
								<template v-else>{{ formatFieldValue(field) }}</template>
							</dd>
						</div>
					</dl>
				</section>

				<!-- A Team folder can have folder-level fields but none for the items
				     inside it. Without this the pane renders blank and reads as broken. -->
				<NcEmptyContent v-else
					:name="emptyItemName"
					:description="emptyItemDescription">
					<template #icon>
						<TagOffIcon :size="20" />
					</template>
				</NcEmptyContent>

				<!-- Linked from (backlinks) -->
				<section v-if="backlinks.length > 0" class="metadata-section backlinks-section">
					<!-- TRANSLATORS Noun: heading of the list of files that link to this file -->
					<h3 class="metadata-section__heading">{{ t('metavox', 'Linked from') }}</h3>
					<!-- The field a link was made through is only worth naming when
					     it tells two rows apart. A Team folder usually has one File
					     link field, so repeating its name on every row says nothing
					     and costs a scan. With several, the name becomes a group
					     heading rather than a suffix — the way Jira groups by link
					     type and Notion names each direction of a relation. -->
					<template v-for="group in backlinkGroups" :key="group.fieldName">
						<h4 v-if="backlinkGroups.length > 1" class="backlinks-group__heading">
							{{ group.fieldLabel }}
						</h4>
						<ul class="backlinks-list">
							<li v-for="link in group.links" :key="`${link.referringFileId}-${link.fieldName}`" class="backlink-item">
								<a class="backlink-link"
									:href="fileUrl(link.referringFileId)"
									target="_blank"
									rel="noopener noreferrer"
									:title="link.referringFilePath">
									<FileIcon :size="16" aria-hidden="true" />
									<span class="backlink-name">{{ link.referringFileName }}</span>
								</a>
							</li>
						</ul>
					</template>
				</section>
			</div>
		</div>

		<!-- Editing happens here: NcDialog is the documented choice for a form,
		     and it arranges its own buttons and focus.

		     "normal" is 600px. Core's own form dialogs use it — files_sharing
		     NewFileRequestDialog — and apps/files NewNodeDialog passes no size
		     at all. The only size="large" in core is a dashboard, a template
		     picker and colour pickers: content that needs the width. A column
		     of labelled fields does not. At 900px a label and its value sit so
		     far apart that the eye loses the line between them, which is why
		     the guidelines cap a form at 900px rather than treat it as a
		     target. -->
		<NcDialog v-if="editDialogOpen"
			:name="editLabel"
			size="normal"
			content-classes="metavox-edit-dialog__content"
			:buttons="dialogButtons"
			@update:open="onDialogToggle">
			<NcNoteCard v-if="aiError" type="error" :text="aiError" />

			<div v-if="aiAvailable || aiGenerating" class="edit-dialog__ai">
				<NcButton variant="tertiary"
					:disabled="aiGenerating"
					@click="generateAiMetadata">
					<template #icon>
						<NcLoadingIcon v-if="aiGenerating" :size="20" />
						<CreationIcon v-else :size="20" />
					</template>
					{{ aiButtonLabel }}
				</NcButton>
			</div>

			<MetadataForm
				ref="form"
				:fields="itemFields"
				:values="metadata"
				:errors="fieldErrors"
				:readonly="!canEdit"
				:self-path="currentFileInfo ? currentFileInfo.path : ''"
				:select-values="selectValues"
				:multi-select-values="multiSelectValues"
				:select-key="selectKey"
				:ai-suggestions="aiSuggestions"
				:regenerating-field="regeneratingField"
				@update="handleMetadataUpdate"
				@accept-suggestion="acceptAiSuggestion"
				@dismiss-suggestion="dismissAiSuggestion"
				@regenerate-suggestion="regenerateAiSuggestion" />
		</NcDialog>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '../utils/toast.js'
import { formatFieldValue, splitMulti } from '../utils/fieldValue.js'
import { accountsOf, onAccountNames, rememberAccountNames } from '../utils/accountNames.js'
import AccountBubble from '../components/fields/AccountBubble.vue'
import { translate as t } from '@nextcloud/l10n'
import { errorText, isFieldError } from '../utils/errorText.js'
import { missingRequired } from '../utils/requiredFields.js'
import { parseValue, displayName } from '../components/fields/filelinkUtils.js'
import axios from '@nextcloud/axios'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import CreationIcon from 'vue-material-design-icons/Creation.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import FileIcon from 'vue-material-design-icons/File.vue'
import FolderOffIcon from 'vue-material-design-icons/FolderOff.vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import TagOffIcon from 'vue-material-design-icons/TagOff.vue'

import MetadataForm from './MetadataForm.vue'
import { loadFileMetadata, saveFileFields, whenSaved } from './columns/MetaVoxAPI.js'
import { matchesStored, onStoreChange } from './columns/MetadataStore.js'

// Per-instance counter for the ARIA id pair below. Vue 3 removed the `_uid`
// that Vue 2 components used for this.
let instanceCounter = 0

export default {
	name: 'FilesSidebarTab',

	components: {
		NcButton,
		NcDialog,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		AccountBubble,
		ChevronDownIcon,
		CreationIcon,
		PencilIcon,
		FileIcon,
		FolderOffIcon,
		LockIcon,
		TagOffIcon,
		MetadataForm,
	},

	props: {
		// New NC33 API props
		node: {
			type: Object,
			default: null,
		},
		folder: {
			type: Object,
			default: null,
		},
		view: {
			type: Object,
			default: null,
		},
		active: {
			type: Boolean,
			default: false,
		},
		// Legacy prop for backwards compatibility
		fileInfo: {
			type: Object,
			default: null,
		},
		// Shown in another app through <metavox-metadata-form>.
		embedded: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['loaded', 'saved', 'error'],

	data() {
		return {
			instanceId: ++instanceCounter,
			// Bumped when names come in, so people are read again.
			namesSeen: 0,
			editDialogOpen: false,
			loading: true,
			saving: false,
			error: null,
			groupfolderId: null,
			groupfolderFields: [],
			itemFields: [],
			metadata: {},
			originalMetadata: {},
			// Why a field cannot be saved: field name => message, under the field.
			fieldErrors: {},
			// The server's verdict, the same one the grid and every save use.
			editable: false,
			selectValues: {},
			multiSelectValues: {},
			selectKey: 0,
			loadController: null,
			// The file last asked for: another tab and back asks nothing again.
			requestedFileId: null,
			// The file whose values the panel shows; a reload of it happens in place.
			shownFileId: null,
			// A change that came while saving or editing; shown once that is done.
			missedChange: false,
			// A refresh was stopped for the dialog: what is shown may be out of date.
			staleShown: false,
			teamfolderInfoExpanded: false,
			aiAvailable: false,
			aiGenerating: false,
			aiSuggestions: {},
			aiError: null,
			regeneratingField: null,
			backlinks: [],
		}
	},

	computed: {
		// Unified file info - works with both new NC33 API (node) and legacy API (fileInfo)
		currentFileInfo() {
			if (this.node) {
				// NC33 new API - convert Node to fileInfo-like object
				return {
					id: this.node.fileid,
					name: this.node.basename,
					path: this.node.path,
					type: this.node.type === 'folder' ? 'dir' : 'file',
					permissions: this.node.permissions,
					mountType: this.node.attributes?.mountType || '',
					mountPoint: this.node.attributes?.mountPoint || '',
					attributes: this.node.attributes || {},
				}
			}
			// Legacy API - return the fileInfo prop
			return this.fileInfo
		},

		// The server answered only because the user may see the file.
		hasPermission() {
			return this.groupfolderId !== null
		},

		canEdit() {
			return this.editable
		},

		hasChanges() {
			return JSON.stringify(this.metadata) !== JSON.stringify(this.originalMetadata)
		},

		isGroupfolderRoot() {
			if (!this.currentFileInfo) return false
			const isDirectory = this.currentFileInfo.type === 'dir'
			const isMountRoot = this.currentFileInfo.attributes?.['is-mount-root'] === true
			const result = isDirectory && this.groupfolderId && isMountRoot

			return result
		},

		isInGroupfolder() {
			const result = !!this.groupfolderId && !this.isGroupfolderRoot
			return result
		},

		itemType() {
			if (!this.currentFileInfo) return 'File'
			return this.currentFileInfo.type === 'dir' ? 'Folder' : 'File'
		},

		// The disclosure needs a stable pair of ids to wire aria-controls to
		// aria-labelledby. The id is per-instance so two sidebars on one page
		// (the legacy and the NC33 registration both mount one) cannot collide.
		teamfolderToggleId() {
			return `metavox-teamfolder-toggle-${this.instanceId}`
		},

		// Backlinks grouped by the field they came through. One group is the
		// common case and renders as a plain list; the heading only appears
		// once there is something to tell apart.
		backlinkGroups() {
			const byField = new Map()
			for (const link of this.backlinks) {
				const key = link.fieldName || ''
				if (!byField.has(key)) {
					byField.set(key, { fieldName: key, fieldLabel: link.fieldLabel || key, links: [] })
				}
				byField.get(key).links.push(link)
			}
			return [...byField.values()]
		},

		// NcDialog arranges these itself, primary last, per the design guidelines.
		dialogButtons() {
			return [
				{
					// TRANSLATORS Button that closes the edit dialog without saving; keep it short
					label: this.t('metavox', 'Cancel'),
					callback: () => this.cancelEdit(),
				},
				{
					label: this.saving
						// TRANSLATORS Label of the save button while the metadata is being saved; keep it short
						? this.t('metavox', 'Saving …')
						// TRANSLATORS Button that saves the metadata in the edit dialog; keep it short
						: this.t('metavox', 'Save'),
					variant: 'primary',
					disabled: this.saving || !this.hasChanges,
					// NcDialog closes after a callback unless it returns false;
					// saveFromDialog closes it once everything is saved.
					callback: () => { this.saveFromDialog(); return false },
				},
			]
		},

		teamfolderPanelId() {
			return `metavox-teamfolder-panel-${this.instanceId}`
		},

		loadingName() {
			return this.aiGenerating
				// TRANSLATORS Shown in the sidebar while AI fills in the metadata of the file
				? this.t('metavox', 'Generating metadata with AI …')
				// TRANSLATORS Shown in the sidebar while the metadata of the file loads
				: this.t('metavox', 'Loading metadata …')
		},

		itemHeading() {
			return this.itemType === 'Folder'
				// TRANSLATORS Noun: heading of the metadata of the selected folder in the sidebar
				? this.t('metavox', 'Folder metadata')
				// TRANSLATORS Noun: heading of the metadata of the selected file in the sidebar
				: this.t('metavox', 'File metadata')
		},

		// Accessible name of the pencil button, and the title of the dialog it opens.
		editLabel() {
			return this.itemType === 'Folder'
				// TRANSLATORS Title of the dialog to edit the metadata of a folder, also the accessible name of the pencil button that opens it
				? this.t('metavox', 'Edit folder metadata')
				// TRANSLATORS Title of the dialog to edit the metadata of a file, also the accessible name of the pencil button that opens it
				: this.t('metavox', 'Edit file metadata')
		},

		emptyItemName() {
			return this.itemType === 'Folder'
				// TRANSLATORS Heading shown in the sidebar when a Team folder has no metadata fields for folders
				? this.t('metavox', 'No folder metadata')
				// TRANSLATORS Heading shown in the sidebar when a Team folder has no metadata fields for files
				: this.t('metavox', 'No file metadata')
		},

		emptyItemDescription() {
			return this.itemType === 'Folder'
				? this.t('metavox', 'Ask an administrator to add metadata fields for folders here.')
				: this.t('metavox', 'Ask an administrator to add metadata fields for files here.')
		},

		aiButtonLabel() {
			return this.aiGenerating
				// TRANSLATORS Label of the AI button while AI fills in the metadata; keep it short
				? this.t('metavox', 'Generating …')
				// TRANSLATORS Button in the edit dialog that lets AI fill in the metadata of the file; AI = artificial intelligence
				: this.t('metavox', 'Generate with AI')
		},
	},

	watch: {
		// NC33+: once per file, when this tab is the one shown.
		node: {
			immediate: true,
			handler() {
				this.loadWhenShown()
			},
		},
		active() {
			this.loadWhenShown()
		},
		// Legacy: watch fileInfo
		fileInfo: {
			immediate: true,
			handler(newFileInfo) {
				if (newFileInfo?.id) {
					this.loadMetadata()
				}
			},
		},
	},

	mounted() {
		this.stopNames = onAccountNames(() => { this.namesSeen++ })
		this.stopListening = onStoreChange((ids) => {
			const fileId = Number(this.currentFileInfo?.id)
			if (fileId && ids.includes(fileId)) {
				this.followStore(fileId)
			}
		})
	},

	beforeUnmount() {
		this.stopNames?.()
		this.stopListening?.()
		this.loadController?.abort()
	},

	methods: {
		t,

		loadWhenShown() {
			const fileId = Number(this.node?.fileid)
			if (fileId && this.active && fileId !== this.requestedFileId) {
				this.loadMetadata()
			}
		},

		async loadMetadata() {
			const fileId = this.currentFileInfo?.id
			if (!fileId) {
				return
			}
			this.requestedFileId = Number(fileId)
			this.loadController?.abort()
			const controller = new AbortController()
			this.loadController = controller

			// The file already on screen refreshes in place: no spinner, no flash.
			const refresh = this.shownFileId === Number(fileId) && !this.error
			if (!refresh) {
				this.loading = true
				this.shownFileId = null
				this.staleShown = false
			}
			this.error = null

			try {
				let data
				try {
					// One request: the server finds the team folder and says what the user may do.
					data = await loadFileMetadata(fileId, { signal: controller.signal })
				} catch (error) {
					// Not in a team folder, or not a file the user may see.
					if (error?.response?.status === 404) {
						this.groupfolderId = null
						return
					}
					throw error
				}
				if (controller.signal.aborted) {
					return
				}
				this.groupfolderId = data.groupfolderId
				this.editable = data.editable

				// Once per file shown, not on every refresh of it.
				if (!refresh) {
					// Immediately restore spinner if a generation was in progress before refresh
					const pendingKey = `metavox_ai_generating_${fileId}`
					if (sessionStorage.getItem(pendingKey) === '1') {
						this.aiGenerating = true
					} else {
						this.aiSuggestions = {}
						this.aiError = null
					}

					// Check AI availability (non-blocking), then check for pending generation
					this.checkAiAvailability().then(() => {
						if (this.aiAvailable) {
							this.checkPendingAiGeneration()
						}
					})
				}

				const appliesToFolder = (field) => field.applies_to_groupfolder === 1 || field.applies_to_groupfolder === '1'
				// The team folder's own fields carry their values in the team folder list.
				this.groupfolderFields = data.teamFolderFields.filter(appliesToFolder)
				this.itemFields = data.fields.filter((field) => !appliesToFolder(field))
				const fieldsWithValues = [...this.groupfolderFields, ...this.itemFields]
				rememberAccountNames(fieldsWithValues.flatMap((field) => field.accounts ?? []))

				// Extract metadata values from ALL fields
				const metadataMap = {}
				fieldsWithValues.forEach((field) => {
					if (field.field_name && field.value !== undefined) {
						metadataMap[field.field_name] = field.value
					}
				})

				// Initialize select and multiselect values for ALL fields
				this.selectValues = {}
				this.multiSelectValues = {}
				fieldsWithValues.forEach((field) => {
					if (field.field_type === 'select') {
						this.selectValues[field.field_name] = metadataMap[field.field_name] || null
					} else if (field.field_type === 'multiselect') {
						const value = metadataMap[field.field_name]
						if (value) {
							this.multiSelectValues[field.field_name] = splitMulti(value)
						} else {
							this.multiSelectValues[field.field_name] = []
						}
					}
				})

				this.metadata = metadataMap
				this.originalMetadata = { ...metadataMap }
				this.selectKey++ // Force re-render of select components

				// Load "Linked from" backlinks (non-blocking)
				this.loadBacklinks(Number(fileId), { keep: refresh })
				this.shownFileId = Number(fileId)
				this.staleShown = false
				this.$emit('loaded', { fileId, fields: this.itemFields })
			} catch (error) {
				if (controller.signal.aborted) {
					return
				}
				console.error('Error loading metadata:', error)
				// A refresh that fails keeps what is shown.
				if (refresh) {
					return
				}
				this.error = errorText(error, this.t('metavox', 'Could not load metadata. Check the connection and try again.'))
				this.$emit('error', this.error)
			} finally {
				if (this.loadController === controller) {
					this.loading = false
					this.loadController = null
				}
			}
		},

		// keep: a refresh shows the old list until the new one is there.
		async loadBacklinks(fileId = Number(this.currentFileInfo?.id), { keep = false } = {}) {
			if (!keep || !this.groupfolderId || !fileId) {
				this.backlinks = []
			}
			if (!this.groupfolderId || !fileId) {
				return
			}
			try {
				const url = generateUrl(
					'/apps/metavox/api/groupfolders/{gfId}/files/{fileId}/backlinks',
					{ gfId: this.groupfolderId, fileId },
				)
				const resp = await axios.get(url)
				// An answer for a file no longer shown is dropped.
				if (Number(this.currentFileInfo?.id) === fileId) {
					this.backlinks = Array.isArray(resp.data) ? resp.data : []
				}
			} catch (error) {
				// Backlinks are a nice-to-have; never block the sidebar on them.
				if (Number(this.currentFileInfo?.id) === fileId) {
					this.backlinks = []
				}
			}
		},

		// The store changed this file: show it, unless the panel already does.
		// While saving or editing it waits, and follows once that is done.
		followStore(fileId) {
			if (this.saving || this.editDialogOpen) {
				this.missedChange = true
				return
			}
			// After the save that changed it, or the reload brings the old value.
			whenSaved(fileId).then(() => {
				if (Number(this.currentFileInfo?.id) !== fileId) {
					return
				}
				if (this.saving || this.editDialogOpen) {
					this.missedChange = true
					return
				}
				// A load still running may have been answered before this change: start again.
				if (this.error || this.staleShown || this.loadController || !matchesStored(fileId, this.itemValues())) {
					this.loadMetadata()
				}
			})
		},

		// What the panel shows for the file's own fields.
		itemValues() {
			return Object.fromEntries(this.itemFields.map((field) => [field.field_name, this.originalMetadata[field.field_name]]))
		},

		openEditDialog() {
			// A refresh landing now would overwrite what is typed: it follows the dialog.
			if (this.loadController && !this.loading) {
				this.loadController.abort()
				this.loadController = null
				this.missedChange = true
				this.staleShown = true
			}
			this.fieldErrors = {}
			this.editDialogOpen = true
		},

		closeEditDialog() {
			this.editDialogOpen = false
			// The dialog goes at once (v-if), before it can give the focus back.
			this.$nextTick(() => {
				if (!document.activeElement || document.activeElement === document.body) {
					this.$refs.editButton?.$el?.focus?.()
				}
			})
			const fileId = Number(this.currentFileInfo?.id)
			if (this.missedChange && fileId) {
				this.missedChange = false
				this.followStore(fileId)
			}
		},

		fileUrl(fileId) {
			return generateUrl('/f/{fileId}', { fileId })
		},

		/** @return {Promise<boolean>} whether everything is saved */
		async saveMetadata() {
			if (!this.canEdit || !this.hasChanges) {
				return true
			}

			// Sync select and multiselect values before saving (only for item fields, not groupfolder fields)
			this.itemFields.forEach((field) => {
				if (field.field_type === 'select') {
					const value = this.selectValues[field.field_name]
					this.metadata[field.field_name] = value || ''
				} else if (field.field_type === 'multiselect') {
					const values = this.multiSelectValues[field.field_name]
					const joinedValue = Array.isArray(values) ? values.join(';#') : ''
					this.metadata[field.field_name] = joinedValue
				}
			})

			// Required is required: not saved until each has its value.
			this.fieldErrors = missingRequired(this.itemFields, this.metadata)
			if (Object.keys(this.fieldErrors).length > 0) {
				this.$nextTick(() => this.$refs.form?.focusFirstError?.())
				return false
			}

			this.saving = true
			this.error = null

			try {
				// Only what changed: a field someone else edited meanwhile stays theirs.
				const patch = {}
				for (const field of this.itemFields) {
					const name = field.field_name
					if ((this.metadata[name] ?? '') !== (this.originalMetadata[name] ?? '')) {
						patch[name] = this.metadata[name] ?? ''
					}
				}
				if (Object.keys(patch).length === 0) {
					this.originalMetadata = { ...this.metadata }
					return true
				}

				// The grid's own way of saving: it shows the change at once.
				await saveFileFields(this.currentFileInfo.id, patch)

				this.originalMetadata = { ...this.metadata }
				// The panel reads the fields' values; a file outside the grid gets no reload.
				for (const field of this.itemFields) {
					if (field.field_name in patch) {
						field.value = patch[field.field_name]
					}
				}
				// TRANSLATORS Confirmation message after the metadata of a file was saved
				showSuccess(this.t('metavox', 'Metadata saved'))
				this.$emit('saved', { fileId: this.currentFileInfo.id, metadata: patch })

				// File-link values are normalised + deduped server-side, and
				// file links and accounts get their names there, so reload to
				// show the stored value by name when one of those was saved.
				if (this.itemFields.some((f) => ['filelink', 'user'].includes(f.field_type) && f.field_name in patch)) {
					this.loadMetadata()
				}
				return true
			} catch (error) {
				// A refused value goes under its field, in the dialog that stays open.
				const refused = isFieldError(error) ? error.response.data.fields : {}
				const names = Object.keys(refused)
				if (names.length > 0 && names.every((name) => this.itemFields.some((f) => f.field_name === name))) {
					this.fieldErrors = { ...refused }
					this.$nextTick(() => this.$refs.form?.focusFirstError?.())
					return false
				}
				this.error = errorText(error, this.t('metavox', 'Could not save metadata. Try again.'))
				showError(this.error)
				this.$emit('error', this.error)
				return false
			} finally {
				this.saving = false
			}
		},

		handleMetadataUpdate(fieldName, value) {
			this.metadata = {
				...this.metadata,
				[fieldName]: value,
			}
			if (fieldName in this.fieldErrors) {
				const rest = { ...this.fieldErrors }
				delete rest[fieldName]
				this.fieldErrors = rest
			}
		},

		// Closing without saving must leave nothing behind: the form edits
		// `metadata` in place, so anything typed survives a reopen unless it is
		// put back to what the server last returned.
		cancelEdit() {
			this.metadata = { ...this.originalMetadata }
			this.resetSelectValues()
			this.aiSuggestions = {}
			this.aiError = null
			this.closeEditDialog()
		},

		async saveFromDialog() {
			// Not saved: the dialog stays open with the message beside its field.
			if (await this.saveMetadata()) {
				this.closeEditDialog()
			}
		},

		// The dialog's own close button and Escape both route through here.
		onDialogToggle(open) {
			if (!open) {
				this.cancelEdit()
			}
		},

		// Rebuild the select/multiselect mirrors from `metadata`, which the
		// form keeps separately from the raw values.
		resetSelectValues() {
			this.itemFields.forEach((field) => {
				if (field.field_type === 'select') {
					this.selectValues[field.field_name] = this.metadata[field.field_name] || null
				} else if (field.field_type === 'multiselect' || field.field_type === 'multi_select') {
					const value = this.metadata[field.field_name]
					this.multiSelectValues[field.field_name] = value
						? splitMulti(value)
						: []
				}
			})
			this.selectKey++
		},

		reload() {
			this.loadMetadata()
		},

		async checkAiAvailability() {
			try {
				const response = await axios.get(generateUrl('/apps/metavox/api/ai/status'))
				this.aiAvailable = response.data?.available === true
			} catch (e) {
				this.aiAvailable = false
			}
		},

		async generateAiMetadata() {
			if (!this.groupfolderId || !this.currentFileInfo?.id) return

			// Prevent duplicate concurrent calls
			if (this._aiGeneratePromise) {
				return
			}

			this.aiGenerating = true
			this.aiError = null
			this.aiSuggestions = {}

			// Persist generating state so it survives page refresh
			const aiKey = `metavox_ai_generating_${this.currentFileInfo.id}`
			sessionStorage.setItem(aiKey, '1')

			this._aiGeneratePromise = axios.post(
				generateUrl('/apps/metavox/api/ai/generate'),
				{
					fileId: this.currentFileInfo.id,
					groupfolderId: this.groupfolderId,
				},
			)

			try {
				const response = await this._aiGeneratePromise

				const suggestions = response.data?.suggestions || {}
				if (Object.keys(suggestions).length === 0) {
					this.aiError = this.t('metavox', 'AI could not generate any suggestions for this file.')
				} else {
					this.aiSuggestions = suggestions
				}
			} catch (error) {
				console.error('AI autofill error:', error)
				this.aiError = errorText(error, this.t('metavox', 'Could not generate metadata. Try again.'))
			} finally {
				this.aiGenerating = false
				this._aiGeneratePromise = null
				sessionStorage.removeItem(aiKey)
				// The error card lives inside the edit dialog. A generation resumed
				// after a page refresh runs with the dialog shut, so without this the
				// failure would be silent and the AI would just appear to do nothing.
				if (this.aiError && !this.editDialogOpen) {
					showError(this.aiError)
				}
			}
		},

		checkPendingAiGeneration() {
			if (!this.currentFileInfo?.id || !this.groupfolderId) return
			const aiKey = `metavox_ai_generating_${this.currentFileInfo.id}`
			if (sessionStorage.getItem(aiKey) === '1') {
				// A generation was in progress before refresh — restart it
				this.generateAiMetadata()
			}
		},

		acceptAiSuggestion(fieldName, value) {
			this.handleMetadataUpdate(fieldName, value)

			// Update select/multiselect values if needed
			const field = this.itemFields.find(f => f.field_name === fieldName)
			if (field) {
				if (field.field_type === 'select') {
					this.selectValues[fieldName] = value
					this.selectKey++
				} else if (field.field_type === 'multiselect') {
					this.multiSelectValues[fieldName] = splitMulti(value)
					this.selectKey++
				}
			}

			// Remove from suggestions
			const newSuggestions = { ...this.aiSuggestions }
			delete newSuggestions[fieldName]
			this.aiSuggestions = newSuggestions
		},

		dismissAiSuggestion(fieldName) {
			const newSuggestions = { ...this.aiSuggestions }
			delete newSuggestions[fieldName]
			this.aiSuggestions = newSuggestions
		},

		async regenerateAiSuggestion(fieldName) {
			if (!this.groupfolderId || !this.currentFileInfo?.id) return
			if (this.regeneratingField) return

			this.regeneratingField = fieldName
			const previousValue = this.aiSuggestions[fieldName] || ''

			try {
				const response = await axios.post(
					generateUrl('/apps/metavox/api/ai/generate'),
					{
						fileId: this.currentFileInfo.id,
						groupfolderId: this.groupfolderId,
						rejectedSuggestions: { [fieldName]: previousValue },
					},
				)

				const suggestions = response.data?.suggestions || {}
				if (suggestions[fieldName]) {
					const newSuggestions = { ...this.aiSuggestions }
					newSuggestions[fieldName] = suggestions[fieldName]
					this.aiSuggestions = newSuggestions
				} else {
					this.aiError = this.t('metavox', 'AI could not generate a new suggestion for this field.')
				}
			} catch (error) {
				console.error('AI regenerate error:', error)
				this.aiError = errorText(error, this.t('metavox', 'Could not regenerate the suggestion. Try again.'))
			} finally {
				this.regeneratingField = null
			}
		},

		// The targets of a filelink field, as {fileId, name} — empty for every
		// other field type, which is what the template branches on. A target
		// that is gone or hidden from this user says so and gets no link.
		fileLinks(field) {
			if (field.field_type !== 'filelink') return []
			return (Array.isArray(field.resolved) ? field.resolved : []).map((item, i) => ({
				key: `${field.id}-${item.fileId ?? 'x' + i}`,
				fileId: item.exists ? item.fileId : null,
				name: item.exists
					? item.name
					// TRANSLATORS Shown instead of a file name when a linked file was deleted or the user cannot see it
					: this.t('metavox', 'Unavailable file'),
			}))
		},

		// Whom a People field holds, by name; null for another field type or no value.
		people(field) {
			void this.namesSeen
			if (field.field_type !== 'user' || !field.value) return null
			return accountsOf(field.value)
		},

		formatFieldValue(field) {
			// For groupfolder fields, the value is in field.value
			// For item fields, the value is in this.metadata[field.field_name]
			const value = field.value !== undefined ? field.value : this.metadata[field.field_name]

			if (!value || value === '') {
				// Em dash rather than a bare hyphen: it reads as "nothing here"
				// typographically. Not translated — punctuation has no locale
				// variant worth a translation key.
				return '—'
			}

			// File links hold ids: show the names as you see the files.
			if (field.field_type === 'filelink') {
				const names = Object.fromEntries((field.resolved || []).filter((i) => i.fileId != null).map((i) => [i.fileId, i.name]))
				return parseValue(value).map((token) => displayName(token, names)
					// TRANSLATORS Shown instead of a file name when a linked file was deleted or the user cannot see it
					|| this.t('metavox', 'Unavailable file')).join(', ')
			}

			// Everything else reads the same here as in the grid and the filter,
			// so it shares one definition — see utils/fieldValue.js.
			return formatFieldValue(value, field.field_type, field) || value
		},
	},
}
</script>

<style scoped>
.metavox-sidebar {
	padding: 16px;
}

.metavox-sidebar__state-actions {
	display: flex;
	justify-content: flex-end;
	margin-top: 16px;
}

.metadata-content {
	min-height: 200px;
}

.metadata-section {
	margin-bottom: 24px;
}

/* The heading wraps to two lines at sidebar width, so it gets the flexible
   space and the button keeps its own. An icon-only button is what fits here:
   at 325px the heading leaves 49px for a label that needs 69. */
.metadata-section__header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	gap: calc(2 * var(--default-grid-baseline));
	margin-bottom: 16px;
	padding-bottom: 12px;
	border-bottom: 1px solid var(--color-border);
}

.metadata-section__heading {
	margin: 0 0 12px 0;
	font-size: 20px;
	font-weight: var(--font-weight-heading, 600);
	color: var(--color-main-text);
}

.metadata-section__header .metadata-section__heading {
	margin: 0;
	min-width: 0;
	overflow-wrap: anywhere;
}

.metadata-section__header > .button-vue {
	flex: 0 0 auto;
}

/* Team folder metadata — a disclosure above the editable form.
   No tinted panel: Nextcloud groups sidebar content with a heading and
   whitespace, and a tint here forced the supporting text onto a surface it is
   not toned for (--color-text-maxcontrast measured 4.89:1 on it — a bare
   pass). A section rule does the separating instead, as the Files Sharing tab
   does between its own sections. */
.teamfolder-info {
	padding-bottom: calc(4 * var(--default-grid-baseline));
	margin-bottom: calc(4 * var(--default-grid-baseline));
	/* --color-border is Nextcloud's weight for a decorative divider and
	   measures 1.23:1 on the dark sidebar. This rule is the only thing
	   separating two sections, so it carries meaning and takes the
	   maxcontrast border, which holds 3:1 in both themes (1.4.11). */
	border-bottom: 2px solid var(--color-border-maxcontrast);
}

.teamfolder-info__heading {
	margin: 0;
	font-size: 20px;
	font-weight: var(--font-weight-heading, 600);
}

.teamfolder-info__heading .teamfolder-info__toggle {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	width: 100%;
	min-height: var(--default-clickable-area);
	/* Core gives every <button> `margin: 3px` — off the 4px grid, and it pushes
	   this section out of line with the one below. Core's selector is also one
	   class deep but loads after this scoped style, so a plain `.toggle` rule
	   loses on source order; scoping it to the heading wins on specificity
	   without reaching for !important. */
	margin: 0;
	/* No inline padding: the chevron is 20px and the 4px gap after it puts the
	   text at the same inset as every label below, so the two section headings
	   start on one edge. */
	padding: 0;
	background: transparent;
	border: none;
	border-radius: var(--border-radius-element);
	color: var(--color-main-text);
	font: inherit;
	font-size: inherit;
	font-weight: var(--font-weight-heading, 600);
	text-align: start;
	cursor: pointer;
}

.teamfolder-info__toggle:hover {
	background: var(--color-background-hover);
}

/* Nextcloud core styles `button:not(.button-vue):focus` with a background, not
   :focus-visible, so this toggle stayed tinted after a mouse click — it read as
   still pressed, and the tint is the same one hover uses, so hover stopped
   meaning anything. Keyboard focus keeps its ring below. */
.teamfolder-info__toggle:focus:not(:focus-visible) {
	background: transparent;
}

.teamfolder-info__toggle:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
}

.teamfolder-info__chevron {
	flex-shrink: 0;
	transition: transform var(--animation-quick) ease-in-out;
}

.teamfolder-info__chevron--collapsed {
	transform: rotate(-90deg);
}

/* No horizontal padding here: the rows carry the same label inset as the
   editable fields below, so adding it again would push this block 12px to
   their right and break the shared left edge. */
.teamfolder-info__content {
	padding-block: calc(2 * var(--default-grid-baseline)) 0;
}

/* Supporting text, on the same left edge as the rows it introduces. */
.teamfolder-info__hint {
	margin: 0 0 calc(3 * var(--default-grid-baseline)) 0;
	padding-inline-start: calc(var(--border-radius-element) + var(--default-grid-baseline));
	font-size: var(--font-size-small);
	color: var(--color-text-maxcontrast);
}

/* Read-only field pairs */
/* Nextcloud's server.css carries a global `dt, dd { padding: 12px }`, which
   put 24px of padding around every one-line row and made this list four times
   taller than it needed to be. Reset it here; the row spacing below is the
   only spacing this list should have.

   The rhythm matches the editable form below (16px between fields, 8px from a
   label to its value), so both halves of the panel read as the same kind of
   list — the labels were already identical in size, weight and colour. */
.field-display-list {
	display: flex;
	flex-direction: column;
	gap: calc(4 * var(--default-grid-baseline));
	margin: 0;
}

/* Reset only the block padding core adds. A blanket `padding: 0` here is more
   specific than the .field-display__label/__value rules below, so it also wiped
   their label inset and pulled the whole block 12px out of line. */
.field-display-list dt,
.field-display-list dd {
	padding-block: 0;
	padding-inline-end: 0;
}

.field-display {
	display: flex;
	flex-direction: column;
}

/* Small and low-contrast at once is the combination that fails 1.4.3, and
   --color-text-maxcontrast is tuned for the main background rather than the
   tinted block this label sits on. The label carries the field name, so it is
   content: it keeps the full-contrast colour and the weight does the
   de-emphasising. */
/* Nextcloud ships the weight as a token, so a raw 500 would not follow a theme
   that changes it. The inset matches --form-element-label-offset, which is what
   NcFormGroup gives its own label, so these rows line up with the editable
   fields in the block below instead of sitting 12px to their right.
   text-align is reset because a <dt> inherits `end` from core's dl styling. */
/* The value carries the element weight, so the label takes the default one:
   with both at 500 the weight told them apart not at all. Now the pair differs
   on three axes at once — size, weight and colour — without a tint, which every
   measured surface would have pushed the 13px label to a bare pass on. */
.field-display__label {
	font-size: var(--font-size-small);
	font-weight: var(--font-weight-default, 400);
	color: var(--color-text-maxcontrast);
	text-align: start;
	padding-inline-start: calc(var(--border-radius-element) + var(--default-grid-baseline));
	margin-bottom: calc(2 * var(--default-grid-baseline));
}

/* A start-edge rule was tried here to mark where the value begins, the way a
   control's border does in the editable form. It measured 1.17:1 against the
   sidebar in light and 1.23:1 in dark — invisible, while still costing a
   second text edge. The pair is told apart by weight, size and colour, and by
   the 8px/16px rhythm the form uses; that is enough without a line. */
.field-display__value {
	margin: 0;
	font-size: var(--default-font-size);
	font-weight: var(--font-weight-element, 500);
	color: var(--color-main-text);
	text-align: start;
	padding-inline-start: calc(var(--border-radius-element) + var(--default-grid-baseline));
	overflow-wrap: anywhere;
}

/* The AI action sits above the form in the dialog, right-aligned like the
   dialog's own buttons. */
.edit-dialog__ai {
	display: flex;
	justify-content: flex-end;
	margin-bottom: calc(4 * var(--default-grid-baseline));
}

/* Same treatment as a backlink row below: icon, filename, underline on hover. */
.account-values {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

.file-link-values {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	margin: 0;
	padding: 0;
	list-style: none;
}

.file-link-value__link,
.file-link-value__missing {
	display: flex;
	align-items: center;
	gap: calc(2 * var(--default-grid-baseline));
	min-height: var(--clickable-area-small);
	overflow: hidden;
	color: var(--color-main-text);
}

.file-link-value__missing {
	color: var(--color-text-maxcontrast);
}

.file-link-value__name {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.file-link-value__link:hover .file-link-value__name {
	text-decoration: underline;
}

.file-link-value__link:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
	border-radius: var(--border-radius-small);
}

.backlinks-section {
	margin-top: 16px;
}

.backlinks-list {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.backlink-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 8px;
	border-radius: var(--border-radius-element);
}

.backlink-item:hover {
	background-color: var(--color-background-hover);
}

.backlink-link {
	display: flex;
	align-items: center;
	gap: 8px;
	min-height: var(--clickable-area-small);
	overflow: hidden;
	color: var(--color-main-text);
}

.backlink-link:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
	border-radius: var(--border-radius-small);
}

.backlink-name {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.backlink-link:hover .backlink-name {
	text-decoration: underline;
}

/* Only rendered when there is more than one source field, so it is a real
   grouping rather than a repeated label. */
.backlinks-group__heading {
	margin: calc(2 * var(--default-grid-baseline)) 0 var(--default-grid-baseline);
	font-size: var(--font-size-small);
	font-weight: var(--font-weight-default, 400);
	color: var(--color-text-maxcontrast);
}

.backlinks-group__heading:first-of-type {
	margin-top: 0;
}

@media (prefers-reduced-motion: reduce) {
	.teamfolder-info__chevron {
		transition: none;
	}
}
</style>

<!-- NcDialog teleports to body, so a scoped rule never reaches it. -->
<style>
/* Below 512px the component library drops the `large` width and makes the
   dialog a full-bleed sheet. That is right for a phone, but it leaves the form
   on NcDialog's 12px gutter: the container pads 12px at the start and 0 at the
   end, and `.dialog__content` supplies the end. A text input then stops 2px
   from the screen edge. The guidelines ask for 16px, so add the missing 4px on
   both sides — one class deeper than `.dialog__content`, which wins on
   specificity instead of on source order. */
@media only screen and (max-width: 512px) {
	.dialog__content.metavox-edit-dialog__content {
		padding-inline: var(--default-grid-baseline) calc(4 * var(--default-grid-baseline));
	}
}
</style>
