<template>
	<div class="statistics-settings">
		<!-- Metadata Statistics Section -->
		<div class="settings-section">
			<h2>{{ labels.metadataStatistics }}</h2>
			<p class="settings-section-desc">
				{{ t('metavox', 'Overview of metadata fields and usage in your MetaVox installation.') }}
			</p>

			<div class="stats-overview">
				<div class="stat-row">
					<div class="stat-info">
						<span class="stat-icon">📋</span>
						<span class="stat-label">{{ labels.metadataFields }}</span>
					</div>
					<span class="stat-value">{{ stats.totalFields }}</span>
				</div>
				<div class="stat-row">
					<div class="stat-info">
						<span class="stat-icon">📁</span>
						<span class="stat-label">{{ labels.teamFoldersWithMetadata }}</span>
					</div>
					<span class="stat-value">{{ stats.groupfoldersWithMetadata }}</span>
				</div>
				<div class="stat-row">
					<div class="stat-info">
						<span class="stat-icon">📝</span>
						<span class="stat-label">{{ labels.totalEntries }}</span>
					</div>
					<span class="stat-value">{{ stats.totalEntries }}</span>
				</div>
			</div>

		</div>

		<!-- AI Autofill Section -->
		<div class="settings-section">
			<h2>{{ labels.aiHeading }}</h2>
			<p class="settings-section-desc">
				{{ t('metavox', 'Allow people to generate metadata suggestions using AI. Requires an AI provider to be configured in Nextcloud.') }}
			</p>

			<div class="telemetry-settings">
				<div class="engagement-option">
					<NcCheckboxRadioSwitch
						type="switch"
						:model-value="aiEnabled"
						@update:model-value="toggleAi">
						<div class="option-info">
							<span class="option-label">{{ labels.aiSwitch }}</span>
							<span class="option-desc">{{ labels.aiSwitchDescription }}</span>
						</div>
					</NcCheckboxRadioSwitch>
				</div>
			</div>
		</div>

		<!-- Telemetry Section -->
		<div class="settings-section">
			<h2>{{ labels.telemetryHeading }}</h2>
			<p class="settings-section-desc">
				{{ t('metavox', 'Help improve MetaVox by sharing anonymous usage statistics.') }}
			</p>

			<div class="telemetry-settings">
				<div class="engagement-option">
					<NcCheckboxRadioSwitch
						type="switch"
						:model-value="telemetryEnabled"
						@update:model-value="toggleTelemetry">
						<div class="option-info">
							<span class="option-label">{{ labels.telemetrySwitch }}</span>
							<span class="option-desc">{{ t('metavox', 'We collect: field counts, Team folder counts, entry counts, version info (MetaVox, Nextcloud, PHP), and basic server configuration. No personal data or file contents are ever shared.') }}</span>
						</div>
					</NcCheckboxRadioSwitch>
				</div>

				<div v-if="telemetryEnabled" class="telemetry-info">
					<NcNoteCard type="success">
						<p>{{ t('metavox', 'Thank you for helping improve MetaVox!') }}</p>
						<p v-if="lastReport">
							{{ lastReportLabel }}
						</p>
						<NcButton type="secondary"
							:disabled="sendingTelemetry"
							@click="sendTelemetryNow">
							{{ sendingTelemetry ? labels.sending : labels.sendReport }}
						</NcButton>
					</NcNoteCard>
					<NcNoteCard v-if="telemetryMessage" :type="telemetryMessageType" class="telemetry-result">
						{{ telemetryMessage }}
					</NcNoteCard>
				</div>

				<div class="telemetry-details">
					<h4>{{ labels.collected }}</h4>
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
					<h4>{{ labels.notCollected }}</h4>
					<ul class="not-collected">
						<li>{{ t('metavox', 'Metadata content or values') }}</li>
						<li>{{ t('metavox', 'File names or paths') }}</li>
						<li>{{ t('metavox', 'Account names or email addresses') }}</li>
						<li>{{ t('metavox', 'Organization name or contact email') }}</li>
						<li>{{ t('metavox', 'Your actual server URL') }}</li>
						<li>{{ t('metavox', 'Any personal or sensitive data') }}</li>
					</ul>
				</div>
			</div>
		</div>

		<div v-if="message" :class="['message', messageType]">
			{{ message }}
		</div>
	</div>
</template>

<script>
import { translate } from '@nextcloud/l10n'
import { PLAIN_TEXT } from '../utils/fieldValue.js'
import { NcButton, NcCheckboxRadioSwitch, NcNoteCard } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'StatisticsSettings',

	// Templates read the t() options from the component.
	setup() {
		return { PLAIN_TEXT }
	},

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
	},

	data() {
		return {
			telemetryEnabled: true,
			aiEnabled: true,
			lastReport: null,
			sendingTelemetry: false,
			telemetryMessage: '',
			telemetryMessageType: 'success',
			message: '',
			messageType: 'success',
			stats: {
				totalFields: 0,
				groupfoldersWithMetadata: 0,
				totalEntries: 0,
			},
		}
	},

	computed: {
		labels() {
			return {
				// TRANSLATORS Heading of the section with usage counts of metadata on this server
				metadataStatistics: this.t('metavox', 'Metadata statistics'),
				// TRANSLATORS Noun: label next to the number of metadata fields on this server
				metadataFields: this.t('metavox', 'Metadata fields'),
				// TRANSLATORS Label next to the number of Team folders that have metadata fields
				teamFoldersWithMetadata: this.t('metavox', 'Team folders with metadata'),
				// TRANSLATORS Label next to the total number of metadata values on this server
				totalEntries: this.t('metavox', 'Total metadata entries'),
				// TRANSLATORS Heading of the settings section for metadata suggestions made by AI; AI is artificial intelligence
				aiHeading: this.t('metavox', 'AI metadata generation'),
				// TRANSLATORS Switch label in the admin settings; AI is artificial intelligence
				aiSwitch: this.t('metavox', 'Enable AI metadata generation'),
				// TRANSLATORS "Generate with AI" is the name of a button in the Files sidebar; use the same translation as that button
				aiSwitchDescription: this.t('metavox', 'When enabled, people see a "Generate with AI" button in the metadata sidebar. The AI reads file content and suggests values for metadata fields.'),
				// TRANSLATORS Heading of the section about sending anonymous usage statistics
				telemetryHeading: this.t('metavox', 'Anonymous usage statistics'),
				// TRANSLATORS Label of the switch that turns sending anonymous usage statistics on or off
				telemetrySwitch: this.t('metavox', 'Share anonymous usage statistics'),
				// TRANSLATORS Button text while the usage report is being sent; keep it short
				sending: this.t('metavox', 'Sending …'),
				// TRANSLATORS Button that sends the anonymous usage report right away; keep it short
				sendReport: this.t('metavox', 'Send report now'),
				// TRANSLATORS Heading above the list of data that is sent; keep the colon
				collected: this.t('metavox', 'What we collect:'),
				// TRANSLATORS Heading above the list of data that is never sent; keep the colon
				notCollected: this.t('metavox', 'What we never collect:'),
			}
		},

		lastReportLabel() {
			// TRANSLATORS {date} is the date and time of the last usage report, e.g. "9 October 2026, 14:30"
			return this.t('metavox', 'Last report sent: {date}', { date: this.formatDate(this.lastReport) }, undefined, PLAIN_TEXT)
		},
	},

	mounted() {
		this.loadStatus()
		this.loadStats()
	},

	methods: {
		async loadStatus() {
			try {
				const [telemetryRes, settingsRes] = await Promise.all([
					axios.get(generateUrl('/apps/metavox/api/telemetry/status')),
					axios.get(generateUrl('/apps/metavox/api/settings')),
				])
				if (telemetryRes.data.success) {
					this.telemetryEnabled = telemetryRes.data.enabled
					this.lastReport = telemetryRes.data.lastReport
				}
				if (settingsRes.data.success) {
					this.aiEnabled = settingsRes.data.settings.ai_enabled
				}
			} catch (error) {
				console.error('Failed to load settings:', error)
			}
		},

		async loadStats() {
			try {
				const response = await axios.get(generateUrl('/apps/metavox/api/telemetry/stats'))
				if (response.data.success) {
					this.stats = {
						totalFields: response.data.stats?.totalFields || 0,
						groupfoldersWithMetadata: response.data.stats?.groupfoldersWithMetadata || 0,
						totalEntries: response.data.stats?.totalEntries || 0,
					}
				}
			} catch (error) {
				console.error('Failed to load statistics:', error)
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
					// TRANSLATORS Notification after the usage report was sent
					this.telemetryMessage = this.t('metavox', 'Report sent successfully')
					this.telemetryMessageType = 'success'
				} else if (data.reason === 'server_error' || data.reason === 'error') {
					const serverMsg = data.message || ''
					if (serverMsg.startsWith('HTTP ') || serverMsg.includes('error')) {
						// TRANSLATORS {error} is the error text returned by the usage statistics server, often in English
						this.telemetryMessage = this.t('metavox', 'The telemetry server returned an error: {error}', { error: serverMsg }, undefined, PLAIN_TEXT)
					} else if (serverMsg.includes('cURL') || serverMsg.includes('connect') || serverMsg.includes('timeout') || serverMsg.includes('resolve')) {
						this.telemetryMessage = this.t('metavox', 'Could not reach the telemetry server. Please try again later.')
					} else {
						this.telemetryMessage = serverMsg
							// TRANSLATORS {error} is the error text returned by the usage statistics server, often in English
							? this.t('metavox', 'The telemetry server returned an error: {error}', { error: serverMsg }, undefined, PLAIN_TEXT)
							: this.t('metavox', 'Failed to send report')
					}
					this.telemetryMessageType = 'error'
				} else {
					this.telemetryMessage = this.t('metavox', 'Failed to send report')
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

		async toggleAi(enabled) {
			try {
				await axios.post(generateUrl('/apps/metavox/api/settings'), {
					ai_enabled: enabled,
				})
				this.aiEnabled = enabled
				this.showMessage(
					enabled
						// TRANSLATORS Notification after the AI metadata switch was turned on; AI is artificial intelligence
						? this.t('metavox', 'AI metadata generation enabled.')
						// TRANSLATORS Notification after the AI metadata switch was turned off; AI is artificial intelligence
						: this.t('metavox', 'AI metadata generation disabled.'),
					'success',
				)
			} catch (error) {
				console.error('Failed to update AI settings:', error)
				this.showMessage(this.t('metavox', 'Failed to update settings'), 'error')
			}
		},

		async toggleTelemetry(enabled) {
			try {
				await axios.post(generateUrl('/apps/metavox/api/telemetry/settings'), {
					enabled: enabled
				})
				this.telemetryEnabled = enabled
				this.showMessage(
					enabled
						? this.t('metavox', 'Thank you! Anonymous usage statistics enabled.')
						: this.t('metavox', 'Anonymous usage statistics disabled.'),
					'success'
				)
			} catch (error) {
				console.error('Failed to update telemetry settings:', error)
				this.showMessage(this.t('metavox', 'Failed to update settings'), 'error')
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
			return date.toLocaleString(undefined, {
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
.statistics-settings {
	max-width: 800px;
}

/* Settings sections */
.settings-section {
	margin-bottom: 32px;
}

.settings-section h2 {
	font-size: 20px;
	font-weight: bold;
	margin-bottom: 8px;
}

.settings-section-desc {
	color: var(--color-text-maxcontrast);
	margin-bottom: 20px;
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
	border-radius: var(--border-radius-large);
}

.stat-info {
	display: flex;
	align-items: center;
	gap: 12px;
}

.stat-icon {
	font-size: 1.5em;
}

.stat-label {
	font-weight: 500;
	color: var(--color-main-text);
}

.stat-value {
	font-size: 24px;
	font-weight: 700;
	color: var(--color-primary);
}


/* Contact info block */
.contact-info-block {
	margin-bottom: 20px;
	padding: 16px 20px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);

	p {
		margin: 0 0 8px 0;
		line-height: 1.5;

		&:last-child {
			margin-bottom: 0;
		}
	}

	a {
		color: var(--color-primary-element);
		font-weight: 500;
		text-decoration: none;

		&:hover {
			text-decoration: underline;
		}
	}
}

.contact-fields {
	h4 {
		margin: 0 0 8px 0;
		font-size: 14px;
		font-weight: 600;
	}

	.field-desc {
		font-size: 13px;
		color: var(--color-text-maxcontrast);
		margin-bottom: 16px;
	}
}

.field-row {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 12px;

	label {
		font-weight: 500;
		font-size: 14px;
	}
}

.contact-input {
	width: 100%;
	max-width: 400px;
	padding: 8px 12px;
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 14px;

	&:focus {
		border-color: var(--color-primary-element);
		outline: none;
	}
}

/* License status */
.license-status {
	margin-top: 24px;

	h4 {
		margin: 0 0 12px 0;
		font-size: 14px;
		font-weight: 600;
	}
}

.usage-bar {
	margin-bottom: 16px;
}

.usage-bar-label {
	display: flex;
	justify-content: space-between;
	margin-bottom: 4px;
	font-size: 14px;
}

.usage-bar-count {
	font-weight: 600;
}

.usage-bar-track {
	height: 8px;
	background: var(--color-background-darker, #e0e0e0);
	border-radius: 4px;
	overflow: hidden;
}

.usage-bar-fill {
	height: 100%;
	background: var(--color-primary-element);
	border-radius: 4px;
	transition: width 0.3s ease;

	&.usage-warning {
		background: var(--color-warning, #e9a211);
	}

	&.usage-exceeded {
		background: var(--color-error, #e9322d);
	}
}

.license-key-section {
	margin-top: 20px;

	h4 {
		margin: 0 0 8px 0;
		font-size: 14px;
		font-weight: 600;
	}
}

.license-key-actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
}

/* Telemetry section */
.telemetry-settings {
	margin-top: 20px;
}

.engagement-option {
	padding: 8px 0;
}

.option-info {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.option-label {
	font-weight: 500;
	color: var(--color-main-text);
}

.option-desc {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.telemetry-info {
	margin-top: 16px;
}

.telemetry-details {
	margin-top: 24px;
	padding: 16px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
}

.telemetry-details h4 {
	margin: 0 0 12px 0;
	font-size: 14px;
	font-weight: 600;
	color: var(--color-main-text);
}

.telemetry-details h4:not(:first-child) {
	margin-top: 20px;
}

.telemetry-details ul {
	margin: 0;
	padding-left: 24px;
	color: var(--color-text-maxcontrast);
}

.telemetry-details ul li {
	margin-bottom: 6px;
	line-height: 1.4;
}

.telemetry-details ul.not-collected {
	color: var(--color-main-text);
}

.telemetry-details ul.not-collected li {
	display: flex;
	align-items: flex-start;
	gap: 8px;
}

.telemetry-details ul.not-collected li::before {
	content: '✓';
	color: var(--color-success-text, #2d7b43);
	font-weight: 600;
	flex-shrink: 0;
}

.telemetry-details ul.not-collected li::marker {
	content: '';
}

.message {
	margin-top: 15px;
	padding: 10px 15px;
	border-radius: var(--border-radius);
	font-size: 14px;

	&.success {
		background: var(--color-success-light, #d4edda);
		color: var(--color-success, #155724);
		border: 1px solid var(--color-success, #c3e6cb);
	}

	&.error {
		background: var(--color-error-light, #f8d7da);
		color: var(--color-error, #721c24);
		border: 1px solid var(--color-error, #f5c6cb);
	}
}
</style>
