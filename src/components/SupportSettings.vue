<template>
	<div class="support-settings">
		<!-- Section 1: About MetaVox -->
		<div class="settings-section">
			<h2>{{ t('metavox', 'Support MetaVox') }}</h2>
			<p class="settings-section-desc">
				{{ t('metavox', 'MetaVox is free and open source (AGPL-3.0). You can use all features without a subscription — no limits, no restrictions, no catch.') }}
			</p>
			<p class="settings-section-desc">
				{{ t('metavox', 'If MetaVox is valuable to your organization, consider subscribing. Your subscription funds active development, guaranteed Nextcloud compatibility, and email support.') }}
			</p>
		</div>

		<!-- Section 2: What's included -->
		<div class="settings-section">
			<h2>{{ t('metavox', 'What a subscription includes') }}</h2>

			<div class="includes-list">
				<div class="includes-item">
					<span class="includes-check">&#x2705;</span>
					<div class="includes-text">
						<span class="includes-label">{{ t('metavox', 'Guaranteed compatibility') }}</span>
						<span class="includes-desc">{{ t('metavox', 'Tested with every new Nextcloud release') }}</span>
					</div>
				</div>
				<div class="includes-item">
					<span class="includes-check">&#x2705;</span>
					<div class="includes-text">
						<span class="includes-label">{{ t('metavox', 'Email support') }}</span>
						<span class="includes-desc">{{ t('metavox', 'Direct support from the developers') }}</span>
					</div>
				</div>
				<div class="includes-item">
					<span class="includes-check">&#x2705;</span>
					<div class="includes-text">
						<span class="includes-label">{{ t('metavox', 'Priority bug fixes') }}</span>
						<span class="includes-desc">{{ t('metavox', 'Your issues get priority attention') }}</span>
					</div>
				</div>
				<div class="includes-item">
					<span class="includes-check">&#x2705;</span>
					<div class="includes-text">
						<span class="includes-label">{{ t('metavox', 'Active development') }}</span>
						<span class="includes-desc">{{ t('metavox', 'New features and improvements') }}</span>
					</div>
				</div>
			</div>
		</div>

		<!-- Section 3: Pricing -->
		<div class="settings-section">
			<h2>{{ t('metavox', 'Pricing') }}</h2>

			<div class="pricing-table">
				<div class="pricing-row">
					<span class="pricing-tier">{{ t('metavox', '1–50 users') }}</span>
					<span class="pricing-price">{{ t('metavox', '€49/year') }}</span>
				</div>
				<div class="pricing-row">
					<span class="pricing-tier">{{ t('metavox', '51–250 users') }}</span>
					<span class="pricing-price">{{ t('metavox', '€149/year') }}</span>
				</div>
				<div class="pricing-row">
					<span class="pricing-tier">{{ t('metavox', '251–1000 users') }}</span>
					<span class="pricing-price">{{ t('metavox', '€349/year') }}</span>
				</div>
				<div class="pricing-row">
					<span class="pricing-tier">{{ t('metavox', '1000+ users') }}</span>
					<span class="pricing-price">{{ t('metavox', 'Contact us') }}</span>
				</div>
			</div>

			<p class="pricing-note">
				{{ t('metavox', 'That\'s less than €1 per week for the smallest tier.') }}
			</p>

			<NcButton type="primary"
				:href="pricingUrl"
				target="_blank"
				rel="noopener noreferrer">
				{{ t('metavox', 'View pricing & subscribe') }}
			</NcButton>
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

		<!-- Section 5: Your organization -->
		<div class="settings-section">
			<div class="contact-fields">
				<h2>{{ t('metavox', 'Your organization (optional)') }}</h2>
				<p class="field-desc">{{ t('metavox', 'These details help us reach you if needed. They are never shared.') }}</p>

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

		<!-- Section 7: Contact -->
		<div class="settings-section">
			<div class="contact-info-block">
				<p>
					{{ t('metavox', 'Learn more about MetaVox') }}:
					<a href="https://voxcloud.nl" target="_blank" rel="noopener noreferrer">voxcloud.nl</a>
				</p>
				<p>
					{{ t('metavox', 'Questions or feedback?') }}
					<a href="mailto:info@voxcloud.nl">info@voxcloud.nl</a>
				</p>
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
			organizationName: '',
			contactEmail: '',
			savingContact: false,
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
		this.loadStatus()
		this.loadLicenseStats()
	},

	methods: {
		async loadStatus() {
			try {
				const settingsRes = await axios.get(generateUrl('/apps/metavox/api/settings'))
				if (settingsRes.data.success) {
					this.organizationName = settingsRes.data.settings.organization_name || ''
					this.contactEmail = settingsRes.data.settings.contact_email || ''
				}
			} catch (error) {
				console.error('Failed to load settings:', error)
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

/* What's included list */
.includes-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-bottom: 24px;
}

.includes-item {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	padding: 12px 20px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
}

.includes-check {
	font-size: 1.2em;
	flex-shrink: 0;
}

.includes-text {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.includes-label {
	font-weight: 600;
	color: var(--color-main-text);
}

.includes-desc {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

/* Pricing table */
.pricing-table {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 16px;
}

.pricing-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 20px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
}

.pricing-tier {
	font-weight: 500;
	color: var(--color-main-text);
}

.pricing-price {
	font-size: 16px;
	font-weight: 700;
	color: var(--color-primary);
}

.pricing-note {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
	font-size: 14px;
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
	h2 {
		margin: 0 0 8px 0;
		font-size: 20px;
		font-weight: bold;
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
