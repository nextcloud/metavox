<template>
	<div class="support-settings">
		<!-- Two routes, and they must not be confused: the commercial one goes
		     to Nextcloud, who sell and invoice the subscription under the ISV
		     agreement and run first-line support, and the one about the app
		     itself comes to us. A "Pricing details" button to voxcloud.nl used
		     to sit here, which routed a buyer past their own Nextcloud account
		     manager and contradicted the notice at the top of the page.

		     The second paragraph is gone too: it listed three of the four
		     benefits the sentence above it had already named. -->
		<div class="mv-block">
			<p class="mv-block__desc">
				{{ t('metavox', 'MetaVox is free and open source (AGPL-3.0). All features work without a subscription — nothing is gated. If your organization gets value from it, a subscription funds the maintenance and gives you guaranteed Nextcloud compatibility, email support and priority bug fixes.') }}
			</p>
			<!-- Two routes, and getting sent to the wrong one wastes a buyer's
			     time. They were --font-size-small in --color-text-maxcontrast:
			     the treatment for a hint under a field, which made the answer
			     to "how do I buy this" the smallest text in the block. Colour
			     follows the role, not the size (§5), and this is content.

			     Plain paragraphs, not a <dl>: core styles bare dt/dd for a
			     fixed-width label column — `dt { width: 130px; white-space:
			     nowrap; text-align: end }` plus 12px padding and inline-block
			     on both — so a 23-character term overflowed its 130px box and
			     painted straight over the answer beside it. Borrowing the
			     element borrows that layout, and beating it back would take
			     five overrides (§16).

			     The &#32; below is load-bearing: Vue strips whitespace between
			     two adjacent elements that sit on separate template lines, so
			     without it the colon runs straight into the address. -->
			<div class="contact-routes">
				<p>
					<!-- TRANSLATORS Bold lead-in before the sentence on where to buy a subscription; keep the colon -->
					<strong>{{ t('metavox', 'Subscriptions and pricing:') }}</strong>
					{{ salesContact[0] }}<a href="mailto:sales@nextcloud.com">sales@nextcloud.com</a>{{ salesContact[1] }}
				</p>
				<p>
					<!-- TRANSLATORS Bold lead-in before the support email address; keep the colon -->
					<strong>{{ t('metavox', 'Questions about the app:') }}</strong>&#32;<a href="mailto:info@voxcloud.nl">info@voxcloud.nl</a>
				</p>
			</div>
		</div>

		<!-- What the subscription is being asked for, in numbers: how much of
		     this installation runs on MetaVox. IntraVox puts the same block in
		     the same place, between the pitch and the key
		     (intravox SupportSettings.vue:26-64).

		     All three counts come from /api/license/stats. They used to be
		     read from /api/telemetry/stats on a separate pane, so the page
		     stated the same numbers twice from two sources that could
		     disagree; the field count moved into the licence payload rather
		     than adding a second call here. -->
		<div class="mv-block">
			<!-- TRANSLATORS Heading of the block with usage counts of this server -->
			<h3>{{ t('metavox', 'Your installation') }}</h3>

			<div class="stats-overview">
				<div class="stat-row">
					<div class="stat-info">
						<FormatListBulletedIcon class="stat-icon" :size="20" aria-hidden="true" />
						<!-- TRANSLATORS Noun: label next to the number of metadata fields on this server -->
						<span class="stat-label">{{ t('metavox', 'Metadata fields') }}</span>
					</div>
					<span class="stat-value">{{ installationStats.totalFields }}</span>
				</div>
				<div class="stat-row">
					<div class="stat-info">
						<FolderIcon class="stat-icon" :size="20" aria-hidden="true" />
						<!-- TRANSLATORS Label next to the number of Team folders that have metadata fields -->
						<span class="stat-label">{{ t('metavox', 'Team folders with metadata') }}</span>
					</div>
					<span class="stat-value">{{ installationStats.teamFolders }}</span>
				</div>
				<div class="stat-row">
					<div class="stat-info">
						<NoteIcon class="stat-icon" :size="20" aria-hidden="true" />
						<!-- TRANSLATORS Noun: label next to the number of stored metadata values on this server -->
						<span class="stat-label">{{ t('metavox', 'Metadata entries') }}</span>
					</div>
					<span class="stat-value">{{ installationStats.totalEntries }}</span>
				</div>
			</div>

			<NcNoteCard v-if="licenseStats && licenseStats.hasLicense && licenseStats.licenseValid" type="success">
				{{ t('metavox', 'Subscription active — thank you for supporting MetaVox!') }}
			</NcNoteCard>

			<NcNoteCard v-if="licenseStats && licenseStats.hasLicense && !licenseStats.licenseValid" type="warning">
				{{ t('metavox', 'Subscription key is invalid or expired.') }}
			</NcNoteCard>
		</div>

		<!-- Section 6: Subscription key -->
		<div class="mv-block">
			<!-- TRANSLATORS Heading of the section where the subscription key is entered -->
			<h3>{{ t('metavox', 'Subscription key') }}</h3>

			<div class="field-row">
				<!-- TRANSLATORS Label of the input field for the subscription key -->
				<NcTextField :label="t('metavox', 'Subscription key')"
					:model-value="licenseKey"
					:placeholder="licenseKeyPlaceholder"
					@update:model-value="onLicenseKeyInput" />
			</div>
			<div class="license-key-actions">
				<NcButton variant="primary"
					:disabled="savingLicense"
					@click="saveLicenseKey">
					{{ saveLicenseButtonLabel }}
				</NcButton>
				<NcButton v-if="licenseStats && licenseStats.hasLicense"
					variant="tertiary"
					:disabled="savingLicense"
					@click="removeLicenseKey">
					<!-- TRANSLATORS Button that deletes the saved subscription key -->
					{{ t('metavox', 'Remove subscription key') }}
				</NcButton>
			</div>
		</div>

		<!-- The other half of the same conversation: what this installation
		     sends back. It sat on a "Statistics" pane whose only other content
		     was the three counts above, so that pane held no statistic an
		     administrator reads and one privacy opt-in. IntraVox keeps the
		     opt-in here, after the key (intravox SupportSettings.vue:97). -->
		<div class="mv-block">
			<!-- TRANSLATORS Heading of the section about sending anonymous usage statistics -->
			<h3>{{ t('metavox', 'Anonymous usage statistics') }}</h3>
			<p class="mv-block__desc">
				{{ t('metavox', 'Help improve MetaVox by sharing anonymous usage statistics.') }}
			</p>

			<NcCheckboxRadioSwitch type="switch"
				:model-value="telemetryEnabled"
				:disabled="savingTelemetry"
				@update:model-value="toggleTelemetry">
				<!-- TRANSLATORS Label of the switch that turns sending anonymous usage statistics on or off -->
				{{ t('metavox', 'Share anonymous usage statistics') }}
			</NcCheckboxRadioSwitch>
			<p class="switch-desc">
				{{ t('metavox', 'We collect: field counts, Team folder counts, entry counts, version info (MetaVox, Nextcloud, PHP), and basic server configuration. No personal data or file contents are ever shared.') }}
			</p>

			<div v-if="telemetryEnabled" class="telemetry-info">
				<NcNoteCard type="success">
					<p>{{ t('metavox', 'Thank you for helping improve MetaVox!') }}</p>
					<p v-if="lastReport">
						<!-- TRANSLATORS {date} is the date and time of the last usage report, e.g. "9 October 2026, 14:30" -->
						{{ t('metavox', 'Last report sent: {date}', { date: formatDate(lastReport) }, undefined, PLAIN_TEXT) }}
					</p>
					<NcButton variant="secondary"
						:disabled="sendingTelemetry"
						@click="sendTelemetryNow">
						{{ sendReportButtonLabel }}
					</NcButton>
				</NcNoteCard>
				<NcNoteCard v-if="telemetryMessage" :type="telemetryMessageType">
					{{ telemetryMessage }}
				</NcNoteCard>
			</div>

			<!-- The full list was two always-open grey boxes of sixteen
			     bullets. It is a disclosure an administrator reads once, so it
			     collapses (§7); the summary above already states the gist. -->
			<details class="telemetry-details">
				<summary>
					<ChevronRightIcon class="telemetry-details__chevron" :size="20" aria-hidden="true" />
					<!-- TRANSLATORS Clickable title that expands the full list of sent and not sent data -->
					{{ t('metavox', 'Exactly what is and is not sent') }}
				</summary>
				<!-- TRANSLATORS Heading above the list of data that is sent; keep the colon -->
				<h4>{{ t('metavox', 'What we collect:') }}</h4>
				<ul>
					<li>{{ t('metavox', 'Number of metadata fields and their types') }}</li>
					<li>{{ t('metavox', 'Number of Team folders using MetaVox') }}</li>
					<li>{{ t('metavox', 'Total metadata entries count') }}</li>
					<li>{{ t('metavox', 'Total number of accounts and active accounts') }}</li>
					<li>{{ t('metavox', 'MetaVox, Nextcloud and PHP versions') }}</li>
					<li>{{ t('metavox', 'Anonymous instance identifier (hashed URL)') }}</li>
					<li>{{ t('metavox', 'Basic server configuration (database, OS, web server, language, timezone, country)') }}</li>
					<li>{{ t('metavox', 'Whether your Nextcloud has an Extended Support / Enterprise subscription (a single yes/no, sourced from Nextcloud\'s public API)') }}</li>
				</ul>
				<!-- TRANSLATORS Heading above the list of data that is never sent; keep the colon -->
				<h4>{{ t('metavox', 'What we never collect:') }}</h4>
				<ul class="not-collected">
					<li>{{ t('metavox', 'Metadata content or values') }}</li>
					<li>{{ t('metavox', 'File names or paths') }}</li>
					<li>{{ t('metavox', 'Account names or email addresses') }}</li>
					<li>{{ t('metavox', 'Organization name or contact email') }}</li>
					<li>{{ t('metavox', 'Your actual server URL') }}</li>
					<li>{{ t('metavox', 'Any personal or sensitive data') }}</li>
				</ul>
			</details>
		</div>

		<NcNoteCard v-if="message" :type="messageType">
			{{ message }}
		</NcNoteCard>
	</div>
</template>

<script>
import { translate } from '@nextcloud/l10n'
import { PLAIN_TEXT } from '../utils/fieldValue.js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import FormatListBulletedIcon from 'vue-material-design-icons/FormatListBulleted.vue'
import NoteIcon from 'vue-material-design-icons/Note.vue'
import axios from '@nextcloud/axios'
import { getCanonicalLocale } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'SupportSettings',

	// Templates read the t() options from the component.
	setup() {
		return { PLAIN_TEXT }
	},

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcTextField,
		ChevronRightIcon,
		FolderIcon,
		FormatListBulletedIcon,
		NoteIcon,
	},

	data() {
		return {
			licenseStats: null,
			licenseKey: '',
			savingLicense: false,
			_userEditedLicenseKey: false,
			telemetryEnabled: true,
			savingTelemetry: false,
			lastReport: null,
			sendingTelemetry: false,
			telemetryMessage: '',
			telemetryMessageType: 'success',
			message: '',
			messageType: 'success',
		}
	},

	computed: {
		// The sentence around the sales address, split where the link goes, so
		// it is one string for the translator.
		salesContact() {
			// TRANSLATORS {email} becomes the Nextcloud sales email address as a link; keep {email} unchanged
			const text = this.t('metavox', 'Handled by Nextcloud. Contact your Nextcloud account manager, or {email}.')
			const [before, after = ''] = text.split('{email}')
			return [before, after]
		},
		/**
		 * Zero until the call lands, rather than a template that reads
		 * `licenseStats.x` off null.
		 */
		installationStats() {
			return {
				totalFields: this.licenseStats?.totalFields ?? 0,
				teamFolders: this.licenseStats?.teamFoldersWithFields ?? 0,
				totalEntries: this.licenseStats?.totalEntries ?? 0,
			}
		},
		licenseKeyPlaceholder() {
			// TRANSLATORS Placeholder of the subscription key field; keep MVOX-XXXX-XXXX-XXXX-XXXX unchanged, it shows the key format
			return this.t('metavox', 'e.g. MVOX-XXXX-XXXX-XXXX-XXXX')
		},
		saveLicenseButtonLabel() {
			if (this.savingLicense) {
				// TRANSLATORS Button text while the subscription key is being saved; keep it short
				return this.t('metavox', 'Saving …')
			}
			// TRANSLATORS Button that saves and activates the subscription key; keep it short
			return this.t('metavox', 'Save & activate')
		},
		sendReportButtonLabel() {
			if (this.sendingTelemetry) {
				// TRANSLATORS Button text while the usage report is being sent; keep it short
				return this.t('metavox', 'Sending …')
			}
			// TRANSLATORS Button that sends the anonymous usage report right away; keep it short
			return this.t('metavox', 'Send report now')
		},
	},

	mounted() {
		this.loadLicenseStats()
		this.loadTelemetryStatus()
	},

	methods: {
		/**
		 * The field was a raw <input> with v-model and no label at all. As an
		 * NcTextField it reports through update:model-value, and the flag that
		 * stops a reload overwriting what the admin is typing is set here.
		 */
		onLicenseKeyInput(value) {
			this.licenseKey = value
			this._userEditedLicenseKey = true
		},

		async loadLicenseStats() {
			try {
				const response = await axios.get(generateUrl('/apps/metavox/api/license/stats'))
				if (response.data.success) {
					this.licenseStats = response.data.stats
					// Show masked key only on initial load, never overwrite user input
					if (this.licenseStats.hasLicense && !this._userEditedLicenseKey) {
						this.licenseKey = this.licenseStats.licenseKeyMasked || ''
					}
				}
			} catch (error) {
				console.error('Failed to load license stats:', error)
			}
		},

		async saveLicenseKey() {
			const key = this.licenseKey.trim()
			if (!key) {
				this.showMessage(this.t('metavox', 'Please enter a subscription key'), 'error')
				return
			}
			this.savingLicense = true
			try {
				// Save the key
				const saveRes = await axios.post(generateUrl('/apps/metavox/api/settings/license'), {
					licenseKey: key,
				})
				if (!saveRes.data.success) {
					this.showMessage(this.t('metavox', 'Failed to save subscription key'), 'error')
					return
				}

				// Immediately validate
				const valRes = await axios.post(generateUrl('/apps/metavox/api/license/validate'))
				if (valRes.data.success && valRes.data.validation?.valid) {
					// Report usage to bind instance to license
					await axios.post(generateUrl('/apps/metavox/api/license/update-usage'))
					// TRANSLATORS Confirmation after the subscription key was saved and found valid
					this.showMessage(this.t('metavox', 'Subscription activated!'), 'success')
				} else {
					// TRANSLATORS {reason} is the explanation from the subscription server, e.g. that the key has expired
					this.showMessage(this.t('metavox', 'Subscription key saved but validation failed: {reason}', {
						// TRANSLATORS Used as the reason when the subscription server gave none
						reason: valRes.data.validation?.reason || this.t('metavox', 'Unknown error'),
					}, undefined, PLAIN_TEXT), 'error')
				}

				await this.loadLicenseStats()
			} catch (error) {
				console.error('Failed to save/validate license key:', error)
				this.showMessage(this.t('metavox', 'Failed to save subscription key'), 'error')
			} finally {
				this.savingLicense = false
			}
		},

		async removeLicenseKey() {
			this.savingLicense = true
			try {
				await axios.post(generateUrl('/apps/metavox/api/settings/license'), {
					licenseKey: '',
				})
				this.licenseKey = ''
				this._userEditedLicenseKey = false
				await this.loadLicenseStats()
				// TRANSLATORS Confirmation after the subscription key was deleted
				this.showMessage(this.t('metavox', 'Subscription key removed.'), 'success')
			} catch (error) {
				this.showMessage(this.t('metavox', 'Failed to remove subscription key'), 'error')
			} finally {
				this.savingLicense = false
			}
		},

		async loadTelemetryStatus() {
			try {
				const res = await axios.get(generateUrl('/apps/metavox/api/telemetry/status'))
				if (res.data.success) {
					this.telemetryEnabled = res.data.enabled
					this.lastReport = res.data.lastReport
				}
			} catch (error) {
				console.error('Failed to load telemetry status:', error)
			}
		},

		/**
		 * The switch is uncontrolled for the moment it is flipped, so a failed
		 * save leaves it claiming the opposite of the server until a reload.
		 * Restore the previous value explicitly — the same fix AiSettings has.
		 */
		async toggleTelemetry(enabled) {
			const previous = this.telemetryEnabled
			this.telemetryEnabled = enabled
			this.savingTelemetry = true
			try {
				await axios.post(generateUrl('/apps/metavox/api/telemetry/settings'), { enabled })
				this.showMessage(
					enabled
						? this.t('metavox', 'Thank you! Anonymous usage statistics enabled.')
						: this.t('metavox', 'Anonymous usage statistics disabled.'),
					'success',
				)
			} catch (error) {
				console.error('Failed to update telemetry settings:', error)
				this.telemetryEnabled = previous
				this.showMessage(this.t('metavox', 'Could not save the setting. It is unchanged.'), 'error')
			} finally {
				this.savingTelemetry = false
			}
		},

		async sendTelemetryNow() {
			this.sendingTelemetry = true
			this.telemetryMessage = ''
			try {
				const response = await axios.post(generateUrl('/apps/metavox/api/telemetry/send'))
				const data = response.data
				if (data.success) {
					this.lastReport = Math.floor(Date.now() / 1000)
					// TRANSLATORS Confirmation after the usage report was sent
					this.telemetryMessage = this.t('metavox', 'Report sent')
					this.telemetryMessageType = 'success'
				} else {
					// The server says why in a fixed reason; only the telemetry server's own text is passed on.
					if (data.reason === 'unreachable') {
						this.telemetryMessage = this.t('metavox', 'Could not reach the telemetry server. Please try again later.')
					} else if (data.reason === 'server_error' && data.message) {
						// TRANSLATORS {error} is the error text returned by the usage statistics server, often in English
						this.telemetryMessage = this.t('metavox', 'The telemetry server returned an error: {error}', { error: data.message }, undefined, PLAIN_TEXT)
					} else {
						this.telemetryMessage = this.t('metavox', 'Failed to send report')
					}
					this.telemetryMessageType = 'error'
				}
			} catch (error) {
				console.error('Failed to send telemetry report:', error)
				this.telemetryMessage = this.t('metavox', 'Could not reach the telemetry server. Please try again later.')
				this.telemetryMessageType = 'error'
			} finally {
				this.sendingTelemetry = false
			}
		},

		showMessage(text, type) {
			this.message = text
			this.messageType = type
			setTimeout(() => {
				this.message = ''
			}, 5000)
		},

		formatDate(timestamp) {
			// TRANSLATORS Shown instead of a date when no usage report has been sent yet
			if (!timestamp) return this.t('metavox', 'Never')
			const date = new Date(timestamp * 1000)
			return date.toLocaleString(getCanonicalLocale(), {
				year: 'numeric',
				month: 'long',
				day: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
			})
		},

		t: translate,
	},
}
</script>

<style lang="scss" scoped>
/* No measure and no inset here: NcSettingsSection owns both (§6, "The measure
   belongs on the form"), and a second one on the child pushed this section's
   content 20px further in than the sections that had none — measured as three
   different left edges, 28 / 40 / 48, down one page. */

/* Settings sections */
.mv-block {
	margin-bottom: 32px;
}

.mv-block h3 {
	/* Nextcloud uses 20px for a heading; weight comes from the token rather
	   than `bold`, which does not follow a theme that changes it. */
	font-size: 20px;
	font-weight: var(--font-weight-heading, 600);
	margin-bottom: 8px;
}

.mv-block-desc {
	color: var(--color-text-maxcontrast);
	margin-bottom: 20px;
}

/* Where to write, by question. The question a reader arrives with carries the
   weight; the answer is ordinary body text, not a footnote. Each route is one
   paragraph, so the lead-in and the answer wrap as a single block of prose
   instead of being laid out against each other. */
.contact-routes {
	margin-top: 16px;

	p {
		margin: 0 0 8px;
		color: var(--color-main-text);
		line-height: 1.5;

		&:last-child {
			margin-bottom: 0;
		}
	}

	strong {
		font-weight: var(--font-weight-heading, 600);

	}

	a {
		color: var(--color-primary-element);
		text-decoration: underline;

		&:hover {
			text-decoration: none;
		}
	}
}

/* Stats overview */
.stats-overview {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-bottom: 24px;
}

.stat-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 16px 20px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-element);
}

.stat-info {
	display: flex;
	align-items: center;
	gap: 12px;
}

/* The icon sizes itself at 20px (§9). */
.stat-icon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.stat-label {
	font-weight: 500;
	color: var(--color-main-text);
}

.stat-value {
	/* `--color-primary` is the header colour, not a text colour, and it is
	   not guaranteed to contrast with --color-background-hover on a themed
	   instance. These are read-only counts, so they take the ordinary text
	   colour and let weight and size do the work (§5). */
	font-size: 24px;
	/* --font-weight-bold is not a Nextcloud token — it resolves to nothing,
	   so every one of these was silently running on its own fallback. §6
	   names three weights and 700 is not among them; a stat figure is a
	   heading's weight. */
	font-weight: var(--font-weight-heading, 600);
	color: var(--color-main-text);
	font-variant-numeric: tabular-nums;
}

.field-row {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 12px;

	label {
		font-weight: 500;
		font-size: var(--font-size-small);
	}
}

/* License key section */
.license-key-actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
}

/* Telemetry */

/* The explanation belongs to the switch above it, so it lines up with the
   switch's label rather than with the box (§6) — same rule as AiSettings. */
.switch-desc {
	/* Body size. Core's Sharing settings explain every switch at 15px and
	   never at --font-size-small, and NcTextField's own helper text inherits
	   the same. Small *and* maxcontrast is the pairing §5 warns about. */
	margin: 4px 0 0;
	padding-inline-start: calc(var(--default-clickable-area) + 8px);
	color: var(--color-text-maxcontrast);
}

.telemetry-info {
	margin-top: 16px;
}

.telemetry-details {
	margin-top: 16px;

	summary {
		/* A disclosure is a control: it needs a target, a focus ring and a
		   pointer, none of which a bare <summary> has (§9, SC 2.5.8).

		   `display: flex` gives all of that but removes the marker box, so
		   the row renders as stranded text with nothing saying it opens —
		   which is exactly how it shipped. The chevron is an explicit
		   element for that reason, and the native marker is turned off so
		   there are not two. */
		display: flex;
		align-items: center;
		gap: 4px;
		min-height: var(--default-clickable-area);
		padding-inline: 4px;
		border-radius: var(--border-radius-element);
		color: var(--color-main-text);
		cursor: pointer;
		list-style: none;

		&::-webkit-details-marker {
			display: none;
		}

		&:hover {
			background-color: var(--color-background-hover);
		}

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: -2px;
		}
	}

	.telemetry-details__chevron {
		flex-shrink: 0;
		color: var(--color-text-maxcontrast);
		transition: transform var(--animation-quick, 100ms) ease-in-out;
	}

	&[open] .telemetry-details__chevron {
		transform: rotate(90deg);
	}

	h4 {
		margin: 16px 0 8px;
		font-size: var(--font-size-small);
		font-weight: var(--font-weight-heading, 600);
	}

	ul {
		margin: 0;
		padding-inline-start: 24px;
		color: var(--color-text-maxcontrast);
	}

	li {
		margin-bottom: 6px;
		line-height: 1.4;
	}

	.not-collected {
		padding-inline-start: 0;
		color: var(--color-main-text);
		list-style: none;

		li {
			display: flex;
			align-items: flex-start;
			gap: 8px;

			&::before {
				content: '✓';
				color: var(--color-success-text, var(--color-success));
				font-weight: var(--font-weight-heading, 600);
				flex-shrink: 0;
			}
		}
	}
}

</style>
