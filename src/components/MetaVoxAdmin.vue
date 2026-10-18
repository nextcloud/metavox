<template>
	<!-- No id: the page's mount point already is #metavox-admin. -->
	<div class="metavox-admin">
		<!-- Subscription notice. Deliberately not dismissible: the close button
		     used to forget the choice on the next page load, which is worse than
		     no button at all — it asks for a decision and then ignores it. -->
		<NcNoteCard v-if="licenseBanner" type="info" class="metavox-admin__banner">
			{{ licenseBanner.message }}
			<a v-if="licenseBanner.link && pane !== 'support'"
				href="#support"
				@click.prevent="pane = 'support'">
				{{ licenseBanner.linkText }}
			</a>
		</NcNoteCard>

		<!-- Eight concerns, one pane at a time. Stacked, they measured 15603px —
		     17 screens, with Support starting at 14938px — and Nextcloud ships
		     no settings page anywhere near that: the most NcSettingsSections in
		     one file in the whole server is four. The one app with this problem,
		     user_ldap, answered it the same way (LDAPSettingsApp.vue:42-61): a
		     pill row over panes swapped with v-if, so the length goes away
		     rather than being annotated with a table of contents.

		     NcRadioGroup rather than user_ldap's raw NcCheckboxRadioSwitch: the
		     same pills, but it renders a real <fieldset> and takes a group
		     label, so the row has an accessible name. LDAP's own row is a bare
		     <div> whose inputs carry no name attribute. -->
		<!-- TRANSLATORS Accessible name of the hidden-label row of tabs that switches between the settings sections -->
		<NcRadioGroup v-model="pane" :label="t('metavox', 'Settings section')"
			hide-label
			class="metavox-admin__panes">
			<NcRadioGroupButton v-for="p in panes"
				:key="p.id"
				:value="p.id"
				:label="p.name">
				<template #icon>
					<!-- Decorative: the label names the pane, so the icon is
					     never the only thing identifying it (§7). -->
					<component :is="p.icon" :size="20" />
				</template>
			</NcRadioGroupButton>
		</NcRadioGroup>

		<!-- TRANSLATORS Heading of the settings section for metadata fields of Team folders -->
		<NcSettingsSection v-if="pane === 'teamfolder-metadata'" :name="t('metavox', 'Team folder metadata fields')"
			:description="t('metavox', 'Configure custom metadata fields that will be available for all Team folders. These fields help organize and categorize your Team folders.')">
			<MetadataFieldsEditor scope="teamfolder" @notification="showNotification" />
		</NcSettingsSection>

		<!-- TRANSLATORS Heading of the settings section for metadata fields of files -->
		<NcSettingsSection v-else-if="pane === 'file-metadata'" :name="t('metavox', 'File metadata fields')"
			:description="t('metavox', 'Configure custom metadata fields that will be available for files within Team folders. These fields help organize and categorize your files.')">
			<MetadataFieldsEditor scope="file" @notification="showNotification" />
		</NcSettingsSection>

		<!-- TRANSLATORS Heading of the settings section that sets fields and metadata per Team folder -->
		<NcSettingsSection v-else-if="pane === 'teamfolders'" :name="t('metavox', 'Manage Team folders')"
			:description="t('metavox', 'Configure which metadata search fields are available for each Team folder and manage Team folder-specific metadata.')">
			<ManageGroupfolders @notification="showNotification" />
		</NcSettingsSection>

		<!-- TRANSLATORS Heading of the settings section for searching on metadata -->
		<NcSettingsSection v-else-if="pane === 'search'" :name="t('metavox', 'Searching metadata')"
			:description="t('metavox', 'Let people search on metadata from Nextcloud\'s search bar, and keep a search as a folder in Files.')">
			<SearchSettings />
		</NcSettingsSection>

		<!-- Settings that hold for the whole app rather than for one kind of field. -->
		<template v-else-if="pane === 'general'">
			<!-- TRANSLATORS Heading of the settings section for what links people can save in URL fields -->
			<NcSettingsSection :name="t('metavox', 'Links in URL fields')"
				:description="t('metavox', 'Which links people can save in URL fields.')">
				<LinkSettings />
			</NcSettingsSection>
			<!-- TRANSLATORS Heading of the settings section for metadata suggestions made by AI; AI is artificial intelligence -->
			<NcSettingsSection :name="t('metavox', 'AI metadata generation')"
				:description="t('metavox', 'Allow people to generate metadata suggestions using AI. Requires an AI provider to be configured in Nextcloud.')">
				<AiSettings />
			</NcSettingsSection>
		</template>

		<!-- TRANSLATORS Noun: heading of the settings section that sets who may manage fields -->
		<NcSettingsSection v-else-if="pane === 'permissions'" :name="t('metavox', 'Permissions')">
			<PermissionsManager @notification="showNotification" />
		</NcSettingsSection>

		<!-- TRANSLATORS Heading of the settings section for metadata backups -->
		<NcSettingsSection v-else-if="pane === 'backup'" :name="t('metavox', 'Backup and restore')">
			<BackupRestore @notification="showNotification" />
		</NcSettingsSection>

		<!-- TRANSLATORS Heading of the settings section about subscriptions and support; MetaVox is the app name, do not translate it -->
		<NcSettingsSection v-else-if="pane === 'support'" :name="t('metavox', 'Support MetaVox')">
			<SupportSettings />
		</NcSettingsSection>
	</div>
</template>

<script>
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcRadioGroup from '@nextcloud/vue/components/NcRadioGroup'
import NcRadioGroupButton from '@nextcloud/vue/components/NcRadioGroupButton'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue'
import DatabaseArrowDownOutlineIcon from 'vue-material-design-icons/DatabaseArrowDownOutline.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'
import FolderCogOutlineIcon from 'vue-material-design-icons/FolderCogOutline.vue'
import LifebuoyIcon from 'vue-material-design-icons/Lifebuoy.vue'
import LockOutlineIcon from 'vue-material-design-icons/LockOutline.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import TuneVariantIcon from 'vue-material-design-icons/TuneVariant.vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import MetadataFieldsEditor from './MetadataFieldsEditor.vue'
import AiSettings from './AiSettings.vue'
import LinkSettings from './LinkSettings.vue'
import SearchSettings from './SearchSettings.vue'
import ManageGroupfolders from './ManageGroupfolders.vue'
import PermissionsManager from './PermissionsManager.vue'
import SupportSettings from './SupportSettings.vue'
import BackupRestore from './BackupRestore.vue'
import { subscriptionNudge as buildSubscriptionNudge } from '../composables/useSubscriptionNudge.js'

/** Remembers the pane per administrator, not per visit. */
const PANE_STORAGE_KEY = 'metavox-admin-pane'

export default {
	name: 'MetaVoxAdmin',

	components: {
		AccountGroupIcon,
		DatabaseArrowDownOutlineIcon,
		FileDocumentOutlineIcon,
		FolderCogOutlineIcon,
		LifebuoyIcon,
		LockOutlineIcon,
		MagnifyIcon,
		TuneVariantIcon,
		NcNoteCard,
		NcRadioGroup,
		NcRadioGroupButton,
		NcSettingsSection,
		MetadataFieldsEditor,
		AiSettings,
		LinkSettings,
		SearchSettings,
		ManageGroupfolders,
		PermissionsManager,
		SupportSettings,
		BackupRestore,
	},

	data() {
		return {
			pane: 'teamfolder-metadata',
			licenseStats: null,
		}
	},

	computed: {
		/**
		 * Short labels on purpose: a horizontal pill group has no flex-wrap and
		 * the grouped variant cancels the ellipsis rule, so a row that does not
		 * fit overflows rather than wrapping or truncating.
		 */
		panes() {
			// The icons are §7's worked example for this row: each names the
			// content behind the pane rather than the word on it, and no
			// glyph repeats — a repeated one misinforms, which is worse than
			// none at all.
			return [
				// TRANSLATORS Tab that opens the metadata fields of Team folders; keep it short, the tab row does not wrap
				{ id: 'teamfolder-metadata', name: t('metavox', 'Team folders'), icon: 'AccountGroupIcon' },
				// TRANSLATORS Tab that opens the metadata fields of files; keep it short, the tab row does not wrap
				{ id: 'file-metadata', name: t('metavox', 'Files'), icon: 'FileDocumentOutlineIcon' },
				// TRANSLATORS Tab that opens the fields and metadata set per Team folder; keep it short, the tab row does not wrap
				{ id: 'teamfolders', name: t('metavox', 'Per folder'), icon: 'FolderCogOutlineIcon' },
				// TRANSLATORS Tab that opens the search settings; keep it short, the tab row does not wrap
				{ id: 'search', name: t('metavox', 'Search'), icon: 'MagnifyIcon' },
				// TRANSLATORS Tab that opens the general settings, such as the links URL fields take and AI; keep it short, the tab row does not wrap
				{ id: 'general', name: t('metavox', 'General'), icon: 'TuneVariantIcon' },
				// TRANSLATORS Noun: tab that opens the permission settings; keep it short, the tab row does not wrap
				{ id: 'permissions', name: t('metavox', 'Permissions'), icon: 'LockOutlineIcon' },
				// TRANSLATORS Noun: tab that opens backup and restore; keep it short, the tab row does not wrap
				{ id: 'backup', name: t('metavox', 'Backup'), icon: 'DatabaseArrowDownOutlineIcon' },
				// TRANSLATORS Noun: tab that opens subscription and support; keep it short, the tab row does not wrap
				{ id: 'support', name: t('metavox', 'Support'), icon: 'LifebuoyIcon' },
			]
		},

		licenseBanner() {
			if (!this.licenseStats) return null
			const s = this.licenseStats
			const hasKey = !!s.licenseKeyMasked

			// Invalid/expired subscription key — friendly notice, not an error
			if (hasKey && !s.licenseValid) {
				return {
					// TRANSLATORS Notice above the settings tabs; MetaVox is the app name, do not translate it
					message: t('metavox', 'Your MetaVox subscription key needs attention.'),
					// TRANSLATORS Link that opens the Support tab; translate "Support" as in that tab
					linkText: t('metavox', 'Visit Support'),
					link: 'support',
				}
			}
			// The subscription suggestion, shared with the Support pane so the
			// two cannot drift apart. The old trigger fired from 50 users or 20
			// team folders; paid subscriptions start at 100 users in the price
			// list, so below that there was nothing to sell and the notice
			// arrived early.
			const nudge = buildSubscriptionNudge(s)
			if (nudge) {
				return {
					message: nudge,
					// TRANSLATORS Link after the subscription notice that opens the Support tab
					linkText: t('metavox', 'Learn more'),
					link: 'support',
				}
			}
			return null
		},
	},

	watch: {
		pane(value) {
			// A per-viewer convenience, so a reload does not throw the
			// administrator back to the first pane. localStorage can be
			// unavailable or throw, and the page must render regardless.
			try {
				window.localStorage.setItem(PANE_STORAGE_KEY, value)
			} catch (e) { /* private window, blocked storage — not worth reporting */ }
		},
	},

	async mounted() {
		this.restorePane()
		try {
			const { data } = await axios.get(generateUrl('/apps/metavox/api/license/stats'))
			if (data.success) this.licenseStats = data.stats
		} catch (e) { /* silently fail — banner just won't show */ }
	},

	methods: {
		t,

		/**
		 * Both the URL fragment and the stored value are untrusted input:
		 * anything that is not a known pane id is ignored rather than leaving
		 * the page showing nothing at all.
		 */
		restorePane() {
			const fromHash = window.location.hash.replace(/^#/, '')
			let stored = null
			try {
				stored = window.localStorage.getItem(PANE_STORAGE_KEY)
			} catch (e) { /* see above */ }
			// The AI pane moved into General.
			const wanted = { ai: 'general' }[fromHash || stored] ?? (fromHash || stored)
			if (wanted && this.panes.some((p) => p.id === wanted)) {
				this.pane = wanted
			}
		},

		showNotification(notification) {
			if (notification?.type === 'error') {
				showError(notification.message)
			} else {
				showSuccess(notification.message)
			}
		},
	},
}
</script>

<style scoped>
/* Scoped to the page root, one class deeper than core's global
   `html, body, div, … { margin: 0 }` reset. That reset loads after the app's
   styles, so a rule of equal specificity loses on source order and the margin
   silently does nothing — measured: the banner sat at x=0 while every section
   sat at x=28 (§6). */
/* NcSettingsSection insets itself on all four sides — margin: calc(var(
   --default-grid-baseline) * 7), so 28px — and these two blocks sit outside
   any section, so they reproduce that inset or they are the blocks on the
   page with a different edge.

   The top matters as much as the sides. Without it the first block took
   NcNoteCard's own 1rem and started 37px down, where the personal page,
   which opens straight onto a section, starts at 50px. Two pages of one app
   beginning at different heights, for no reason a reader could see. 28px
   here puts the first block exactly where a section would have been. */
#metavox-admin .metavox-admin__banner {
	margin: 28px 28px 24px;
	max-width: 900px;
}

/* The pill row switches between the sections, so it shares their edge. */
#metavox-admin .metavox-admin__panes {
	margin-inline: 28px;
	margin-bottom: 24px;
	max-width: 900px;
}

/* The banner is conditional — an installation with a valid subscription gets
   none — and then the pane row is the first block on the page and has to
   carry the inset itself. Without this the row sits flush against the top on
   exactly the installations we never look at. */
#metavox-admin .metavox-admin__panes:first-child {
	margin-top: 28px;
}

/* The library renders these labels at the body size and weight — measured
   15px/400, the same as the paragraph directly under them. That is what made
   the row read as "buttons, maybe?": nothing but the blue fill said the pills
   were a control rather than a line of links.

   A segmented control is chrome, not content, so it takes the smaller step of
   the scale. Nextcloud's own Team folders app reaches for the same contrast by
   hand (13.5px/700) — the size is right even though its markup is not: it
   hand-builds role="tab" without aria-controls or a tabpanel, which is a tab
   bar a screen reader announces and then cannot follow. The whole server
   defines role="tab" exactly once, in an appstore carousel, and no core
   settings page uses tabs at all. So: their type, our component.

   500 rather than their 700. Bold on a 13px pill against the blue fill was
   heavier than the h2 above it, which inverts the hierarchy the smaller size
   is here to fix. */
#metavox-admin .metavox-admin__panes :deep([class*='radioGroupButton__label']) {
	font-size: var(--font-size-small);
	font-weight: 500;
}

/* The grouped variant is flex-wrap: nowrap by design — the pills are meant to
   read as one segmented control — so seven of them measured 649px inside a
   393px viewport and 284px of the row was simply unreachable: the page does
   not scroll sideways.

   Below the phone breakpoint they wrap instead. That costs the joined-segment
   look, which is the right trade: a control you cannot reach is worse than a
   control drawn as separate buttons. The radii are restored per pill so a
   wrapped row does not show half-rounded ends mid-line. */
@media (max-width: 660px) {
	#metavox-admin .metavox-admin__panes :deep([class*='ncFormBox']) {
		flex-wrap: wrap;
		gap: 8px;
	}

	#metavox-admin .metavox-admin__panes :deep([class*='radioGroupButton']) {
		border-radius: var(--border-radius-element);
		border-inline-width: 2px;
		/* Without this the last pill on a wrapped line stretches to fill it —
		   measured 337px against ~107px for its neighbours, which reads as the
		   selected one. A pill is as wide as its label. */
		flex: 0 0 auto;
	}
}

@media (max-width: 512px) {
	#metavox-admin .metavox-admin__banner {
		font-size: var(--font-size-small);
	}
}

/* A heading long enough to overflow its box. Measured at 320px:
   "Teammap-metadatavelden" ran 22px past the viewport, and Dutch and German
   both produce compounds like it (§13: a layout must not depend on string
   length).

   break-word, not anywhere. A hyphen is already a legal break point, so the
   Dutch compounds break cleanly at it down to 200px without any rule at all —
   `anywhere` only adds the licence to split mid-syllable, which is what it
   did. This is the fallback for a long word with no hyphen in it.

   :deep() because the element belongs to the library, not to this template. */
:deep(.settings-section__name) {
	/* The box first. Measured at 320px: the <h2> was 314px wide inside a 264px
	   section, so the glyphs fitted their own box and the box overhung the
	   screen — no wrapping rule can fix that, which is why break-word alone
	   looked like it was doing nothing. */
	min-width: 0;
	max-width: 100%;
	overflow-wrap: break-word;
}
</style>
