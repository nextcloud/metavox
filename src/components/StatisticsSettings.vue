<template>
	<div class="statistics-settings">
		<!-- Metadata Statistics Section -->
		<div class="settings-section">
			<h2>{{ t('metavox', 'Metadata Statistics') }}</h2>
			<p class="settings-section-desc">
				{{ t('metavox', 'Overview of metadata fields and usage in your MetaVox installation.') }}
			</p>

			<div class="stats-overview">
				<div class="stat-row">
					<div class="stat-info">
						<span class="stat-icon">📋</span>
						<span class="stat-label">{{ t('metavox', 'Metadata Fields') }}</span>
					</div>
					<span class="stat-value">{{ stats.totalFields }}</span>
				</div>
				<div class="stat-row">
					<div class="stat-info">
						<span class="stat-icon">📁</span>
						<span class="stat-label">{{ t('metavox', 'Team Folders with Metadata') }}</span>
					</div>
					<span class="stat-value">{{ stats.groupfoldersWithMetadata }}</span>
				</div>
				<div class="stat-row">
					<div class="stat-info">
						<span class="stat-icon">📝</span>
						<span class="stat-label">{{ t('metavox', 'Total Metadata Entries') }}</span>
					</div>
					<span class="stat-value">{{ stats.totalEntries }}</span>
				</div>
			</div>

			<!-- The Future of MetaVox -->
			<div class="future-licensing-info">
				<h4>{{ t('metavox', 'The Future of MetaVox') }}</h4>
				<p>{{ t('metavox', 'MetaVox is and will remain free for most users. To keep MetaVox actively maintained and improved, we\'re considering a licensing model for larger organizations.') }}</p>
				<p><strong>{{ t('metavox', 'If we proceed, our promise') }}:</strong></p>
				<ul class="promise-list">
					<li>{{ t('metavox', 'A free tier for small and medium installations') }}</li>
					<li>{{ t('metavox', 'All current features remain available') }}</li>
					<li>{{ t('metavox', 'Transparent pricing based on actual usage') }}</li>
				</ul>
				<p class="feedback-note">{{ t('metavox', 'We\'re currently collecting anonymous statistics to help us understand usage patterns and establish fair limits. Your feedback matters - together we\'re exploring a sustainable future for MetaVox.') }}</p>
			</div>
		</div>

		<!-- AI Autofill Section -->
		<div class="settings-section">
			<h2>{{ t('metavox', 'AI Metadata Generation') }}</h2>
			<p class="settings-section-desc">
				{{ t('metavox', 'Allow users to generate metadata suggestions using AI. Requires an AI provider to be configured in Nextcloud.') }}
			</p>

			<div class="telemetry-settings">
				<div class="engagement-option">
					<NcCheckboxRadioSwitch
						type="switch"
						:model-value="aiEnabled"
						@update:model-value="toggleAi">
						<div class="option-info">
							<span class="option-label">{{ t('metavox', 'Enable AI metadata generation') }}</span>
							<span class="option-desc">{{ t('metavox', 'When enabled, users see a "Generate with AI" button in the metadata sidebar. The AI reads file content and suggests values for metadata fields.') }}</span>
						</div>
					</NcCheckboxRadioSwitch>
				</div>
			</div>
		</div>

		<!-- Telemetry Section -->
		<div class="settings-section">
			<h2>{{ t('metavox', 'Anonymous Usage Statistics') }}</h2>
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
							<span class="option-label">{{ t('metavox', 'Share anonymous usage statistics') }}</span>
							<span class="option-desc">{{ t('metavox', 'We collect: field counts, team folder counts, entry counts, version info (MetaVox, Nextcloud, PHP), and basic server configuration. No personal data or file contents are ever shared.') }}</span>
						</div>
					</NcCheckboxRadioSwitch>
				</div>

				<div v-if="telemetryEnabled" class="telemetry-info">
					<NcNoteCard type="success">
						<p>{{ t('metavox', 'Thank you for helping improve MetaVox!') }}</p>
						<p v-if="lastReport">
							{{ t('metavox', 'Last report sent') }}: {{ formatDate(lastReport) }}
						</p>
						<NcButton type="secondary"
							:disabled="sendingTelemetry"
							@click="sendTelemetryNow">
							{{ sendingTelemetry ? t('metavox', 'Sending...') : t('metavox', 'Send report now') }}
						</NcButton>
					</NcNoteCard>
				</div>

				<div class="telemetry-details">
					<h4>{{ t('metavox', 'What we collect') }}:</h4>
					<ul>
						<li>{{ t('metavox', 'Number of metadata fields and their types') }}</li>
						<li>{{ t('metavox', 'Number of groupfolders using MetaVox') }}</li>
						<li>{{ t('metavox', 'Total metadata entries count') }}</li>
						<li>{{ t('metavox', 'MetaVox, Nextcloud and PHP versions') }}</li>
						<li>{{ t('metavox', 'Anonymous instance identifier (hashed URL)') }}</li>
						<li>{{ t('metavox', 'Basic server configuration (database, OS, web server, language, timezone, country)') }}</li>
					</ul>
					<h4>{{ t('metavox', 'What we never collect') }}:</h4>
					<ul class="not-collected">
						<li>{{ t('metavox', 'Metadata content or values') }}</li>
						<li>{{ t('metavox', 'File names or paths') }}</li>
						<li>{{ t('metavox', 'User names or email addresses') }}</li>
						<li>{{ t('metavox', 'Your actual server URL') }}</li>
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
import { NcButton, NcCheckboxRadioSwitch, NcNoteCard } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'StatisticsSettings',

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
			message: '',
			messageType: 'success',
			stats: {
				totalFields: 0,
				groupfoldersWithMetadata: 0,
				totalEntries: 0,
			},
		}
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
			try {
				const response = await axios.post(generateUrl('/apps/metavox/api/telemetry/send'))
				if (response.data.success) {
					this.lastReport = Math.floor(Date.now() / 1000)
					this.showMessage(this.t('metavox', 'Report sent successfully!'), 'success')
				} else {
					this.showMessage(this.t('metavox', 'Failed to send report'), 'error')
				}
			} catch (error) {
				console.error('Failed to send telemetry report:', error)
				this.showMessage(this.t('metavox', 'Failed to send report'), 'error')
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
						? this.t('metavox', 'AI metadata generation enabled.')
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

		t(app, text, vars) {
			if (typeof OC !== 'undefined' && OC.L10N) {
				return OC.L10N.translate(app, text, vars)
			}
			if (vars) {
				return Object.keys(vars).reduce((result, key) => {
					return result.replace(`{${key}}`, vars[key])
				}, text)
			}
			return text
		}
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

/* Future licensing info */
.future-licensing-info {
	margin-top: 24px;
	padding: 20px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
	border-left: 4px solid var(--color-primary-element);
}

.future-licensing-info h4 {
	margin: 0 0 12px 0;
	font-size: 16px;
	font-weight: 600;
	color: var(--color-main-text);
}

.future-licensing-info p {
	margin: 0 0 12px 0;
	color: var(--color-main-text);
	line-height: 1.5;
}

.future-licensing-info p:last-child {
	margin-bottom: 0;
}

.promise-list {
	margin: 8px 0 16px 0;
	padding-left: 24px;
	color: var(--color-main-text);
}

.promise-list li {
	margin-bottom: 6px;
	line-height: 1.4;
}

.feedback-note {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
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
