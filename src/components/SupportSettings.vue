<template>
	<div class="support-settings">
		<!-- Section 1: About MetaVox + CTA -->
		<div class="settings-section">
			<h2>{{ t('metavox', 'Support MetaVox') }}</h2>
			<p class="settings-section-desc">
				{{ t('metavox', 'MetaVox is free and open source (AGPL-3.0). All features work without a subscription. If MetaVox is valuable to your organization, a subscription supports active development and gives you guaranteed Nextcloud compatibility and email support.') }}
			</p>
			<p class="settings-section-desc subscription-includes">
				{{ t('metavox', 'A subscription includes: guaranteed Nextcloud compatibility, email support, priority bug fixes, and active development.') }}
			</p>
			<div class="cta-block">
				<NcButton type="primary"
					:href="pricingUrl"
					target="_blank"
					rel="noopener noreferrer">
					{{ t('metavox', 'View pricing & plans') }}
				</NcButton>
				<p class="cta-contact">
					{{ t('metavox', 'Questions?') }}
					<a href="mailto:info@voxcloud.nl">info@voxcloud.nl</a>
				</p>
			</div>
		</div>

		<!-- Section 4: Your installation -->
		<div class="settings-section">
			<h2>{{ t('metavox', 'Your installation') }}</h2>

			<div v-if="licenseStats" class="stats-overview">
				<div class="stat-row">
					<div class="stat-info">
						<span class="stat-icon">📁</span>
						<span class="stat-label">{{ t('metavox', 'Team folders with metadata') }}</span>
					</div>
					<span class="stat-value">{{ licenseStats.teamFoldersWithFields }}</span>
				</div>
				<div class="stat-row">
					<div class="stat-info">
						<span class="stat-icon">📝</span>
						<span class="stat-label">{{ t('metavox', 'Total metadata entries') }}</span>
					</div>
					<span class="stat-value">{{ (licenseStats.totalEntries || 0).toLocaleString() }}</span>
				</div>
				<div class="stat-row">
					<div class="stat-info">
						<span class="stat-icon">👥</span>
						<span class="stat-label">{{ t('metavox', 'Total users') }}</span>
					</div>
					<span class="stat-value">{{ licenseStats.totalUsers || 0 }}</span>
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
		<div class="settings-section">
			<h2>{{ t('metavox', 'Subscription key') }}</h2>

			<div class="field-row">
				<input id="license-key"
					v-model="licenseKey"
					type="text"
					:placeholder="t('metavox', 'e.g. MVOX-XXXX-XXXX-XXXX-XXXX')"
					class="contact-input"
					@input="_userEditedLicenseKey = true">
			</div>
			<div class="license-key-actions">
				<NcButton type="primary"
					:disabled="savingLicense"
					@click="saveLicenseKey">
					{{ savingLicense ? t('metavox', 'Saving...') : t('metavox', 'Save & activate') }}
				</NcButton>
				<NcButton v-if="licenseStats && licenseStats.hasLicense"
					type="tertiary"
					:disabled="savingLicense"
					@click="removeLicenseKey">
					{{ t('metavox', 'Remove subscription key') }}
				</NcButton>
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
	name: 'SupportSettings',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
	},

	data() {
		return {
			licenseStats: null,
			licenseKey: '',
			savingLicense: false,
			_userEditedLicenseKey: false,
			message: '',
			messageType: 'success',
		}
	},

	computed: {
		pricingUrl() {
			const lang = (window.document?.documentElement?.lang || '').split('-')[0]
			return lang === 'nl' ? 'https://voxcloud.nl/pricing/#metavox' : 'https://voxcloud.nl/en/pricing/#metavox'
		},
	},

	mounted() {
		this.loadLicenseStats()
	},

	methods: {
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

		async removeLicenseKey() {
			this.savingLicense = true
			try {
				await axios.post(generateUrl('/apps/metavox/api/settings/license'), {
					licenseKey: '',
				})
				this.licenseKey = ''
				this._userEditedLicenseKey = false
				await this.loadLicenseStats()
				this.showMessage(this.t('metavox', 'Subscription key removed.'), 'success')
			} catch (error) {
				this.showMessage(this.t('metavox', 'Failed to remove subscription key'), 'error')
			} finally {
				this.savingLicense = false
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
		},
	},
}
</script>

<style lang="scss" scoped>
.support-settings {
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

/* CTA block (about + view-pricing button) */
.subscription-includes {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.cta-block {
	display: flex;
	align-items: center;
	gap: 16px;
	flex-wrap: wrap;
	margin-top: 8px;
}

.cta-contact {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 14px;

	a {
		color: var(--color-primary-element);
		font-weight: 500;
		text-decoration: none;

		&:hover {
			text-decoration: underline;
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

/* License key section */
.license-key-actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
}

.message {
	margin-top: 15px;
	padding: 10px 15px;
	border-radius: var(--border-radius);
	font-size: 14px;

	&.success {
		background: #d4edda;
		color: #155724;
		border: 1px solid #c3e6cb;
	}

	&.error {
		background: #f8d7da;
		color: #721c24;
		border: 1px solid var(--color-error, #f5c6cb);
	}
}
</style>
