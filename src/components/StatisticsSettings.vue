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

		</div>

		<!-- Support & Licensing Section -->
		<div class="settings-section">
			<h2>{{ t('metavox', 'Support & Licensing') }}</h2>
			<p class="settings-section-desc">
				{{ t('metavox', 'Need help or interested in a subscription for your organization?') }}
			</p>

			<div class="contact-info-block">
				<p>
					{{ t('metavox', 'Learn more about licensing and support') }}:
					<a href="https://voxcloud.nl" target="_blank" rel="noopener noreferrer">voxcloud.nl</a>
				</p>
				<p>
					{{ t('metavox', 'Questions about licensing?') }}
					<a href="mailto:info@voxcloud.nl">info@voxcloud.nl</a>
				</p>
			</div>

			<div class="contact-fields">
				<h4>{{ t('metavox', 'Your organization (optional)') }}</h4>
				<p class="field-desc">{{ t('metavox', 'These details are sent with your anonymous usage statistics so we can reach you if needed. They are never shared with third parties.') }}</p>

				<div class="field-row">
					<label for="organization-name">{{ t('metavox', 'Organization name') }}</label>
					<input id="organization-name"
						v-model="organizationName"
						type="text"
						:placeholder="t('metavox', 'e.g. Acme Corporation')"
						class="contact-input">
				</div>

				<div class="field-row">
					<label for="contact-email">{{ t('metavox', 'Contact email') }}</label>
					<input id="contact-email"
						v-model="contactEmail"
						type="email"
						:placeholder="t('metavox', 'e.g. admin@example.com')"
						class="contact-input">
				</div>

				<NcButton type="primary"
					:disabled="savingContact"
					@click="saveContactInfo">
					{{ savingContact ? t('metavox', 'Saving...') : t('metavox', 'Save') }}
				</NcButton>
			</div>

			<!-- Usage & License Status -->
			<div v-if="licenseStats" class="license-status">
				<h4>{{ t('metavox', 'Usage') }}</h4>

				<div class="usage-bar">
					<div class="usage-bar-label">
						<span>{{ t('metavox', 'Team folders with metadata') }}</span>
						<span class="usage-bar-count">{{ licenseStats.teamFoldersWithFields }} / {{ licenseStats.limits.teamFolderLimit || '∞' }}</span>
					</div>
					<div class="usage-bar-track">
						<div class="usage-bar-fill"
							:class="{ 'usage-warning': teamFolderPercent >= 80, 'usage-exceeded': teamFolderPercent >= 100 }"
							:style="{ width: Math.min(teamFolderPercent, 100) + '%' }" />
					</div>
				</div>

				<div class="usage-bar">
					<div class="usage-bar-label">
						<span>{{ t('metavox', 'Total metadata entries') }}</span>
						<span class="usage-bar-count">{{ (licenseStats.totalEntries || 0).toLocaleString() }}</span>
					</div>
				</div>

				<NcNoteCard v-if="licenseStats.limits.exceeded && !licenseStats.hasLicense" type="info">
					<p>{{ t('metavox', 'Your organization is getting great value from MetaVox! Subscribe for unlimited team folders, email support and guaranteed Nextcloud compatibility.') }}</p>
					<p><a href="https://voxcloud.nl/pricing/#metavox" target="_blank" rel="noopener noreferrer" style="color: var(--color-primary-element); font-weight: 500;">{{ t('metavox', 'View subscriptions on voxcloud.nl') }} →</a></p>
				</NcNoteCard>

				<NcNoteCard v-if="licenseStats.hasLicense && licenseStats.licenseValid" type="success">
					{{ t('metavox', 'Subscription active — thank you for supporting MetaVox!') }}
				</NcNoteCard>

				<NcNoteCard v-if="licenseStats.hasLicense && !licenseStats.licenseValid" type="warning">
					{{ t('metavox', 'Subscription key is invalid or expired. Please check your key or contact info@voxcloud.nl.') }}
				</NcNoteCard>
			</div>

			<!-- License Key -->
			<div class="license-key-section">
				<h4>{{ t('metavox', 'Subscription key') }}</h4>
				<div class="field-row">
					<input id="license-key"
						v-model="licenseKey"
						type="text"
						:placeholder="t('metavox', 'e.g. MVOX-XXXX-XXXX-XXXX-XXXX')"
						class="contact-input"
						@input="_userEditedLicenseKey = true">
				</div>
				<div class="license-key-actions">
					<button class="primary"
						:disabled="savingLicense"
						@click="saveLicenseKey">
						{{ savingLicense ? t('metavox', 'Saving...') : t('metavox', 'Save & activate') }}
					</button>
				</div>
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
			organizationName: '',
			contactEmail: '',
			savingContact: false,
			licenseKey: '',
			licenseStats: null,
			savingLicense: false,
			validatingLicense: false,
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

	computed: {
		teamFolderPercent() {
			if (!this.licenseStats?.limits?.teamFolderLimit) return 0
			return (this.licenseStats.teamFoldersWithFields / this.licenseStats.limits.teamFolderLimit) * 100
		},
	},

	mounted() {
		this.loadStatus()
		this.loadStats()
		this.loadLicenseStats()
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
					this.organizationName = settingsRes.data.settings.organization_name || ''
					this.contactEmail = settingsRes.data.settings.contact_email || ''
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
					this.showMessage(this.t('metavox', 'Subscription activated!'), 'success')
				} else {
					this.showMessage(this.t('metavox', 'Subscription key saved but validation failed: {reason}', { reason: valRes.data.validation?.reason || 'unknown' }), 'error')
				}

				await this.loadLicenseStats()
			} catch (error) {
				console.error('Failed to save/validate license key:', error)
				this.showMessage(this.t('metavox', 'Failed to save subscription key'), 'error')
			} finally {
				this.savingLicense = false
			}
		},

		async saveContactInfo() {
			this.savingContact = true
			try {
				await axios.post(generateUrl('/apps/metavox/api/settings'), {
					organization_name: this.organizationName,
					contact_email: this.contactEmail,
				})
				this.showMessage(this.t('metavox', 'Contact information saved.'), 'success')
			} catch (error) {
				console.error('Failed to save contact info:', error)
				this.showMessage(this.t('metavox', 'Failed to save contact information'), 'error')
			} finally {
				this.savingContact = false
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
