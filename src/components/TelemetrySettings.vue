<template>
	<div class="telemetry-settings">
		<div class="settings-section">
			<h3>{{ t('metavox', 'Anonymous Usage Statistics') }}</h3>
			<p class="description">
				{{ t('metavox', 'Help improve MetaVox by sharing anonymous usage statistics. No personal data or file contents are ever shared.') }}
			</p>

			<div class="setting-row">
				<NcCheckboxRadioSwitch
					:checked="telemetryEnabled"
					@update:checked="toggleTelemetry">
					{{ t('metavox', 'Share anonymous usage statistics') }}
				</NcCheckboxRadioSwitch>
			</div>

			<div class="info-box">
				<h4>{{ t('metavox', 'What we collect:') }}</h4>
				<ul>
					<li>{{ t('metavox', 'Number of metadata fields and their types') }}</li>
					<li>{{ t('metavox', 'Number of groupfolders using MetaVox') }}</li>
					<li>{{ t('metavox', 'Total metadata entries count') }}</li>
					<li>{{ t('metavox', 'MetaVox, Nextcloud and PHP versions') }}</li>
					<li>{{ t('metavox', 'Anonymous instance identifier (hashed URL)') }}</li>
				</ul>
			</div>

			<div class="status-section" v-if="lastReport">
				<h4>{{ t('metavox', 'Last Report') }}</h4>
				<p>{{ formatDate(lastReport) }}</p>
			</div>

	
			<div v-if="message" :class="['message', messageType]">
				{{ message }}
			</div>
		</div>
	</div>
</template>

<script>
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'TelemetrySettings',

	components: {
		NcCheckboxRadioSwitch,
	},

	data() {
		return {
			telemetryEnabled: true,
			lastReport: null,
			message: '',
			messageType: 'success',
		}
	},

	mounted() {
		this.loadStatus()
	},

	methods: {
		async loadStatus() {
			try {
				const response = await axios.get(generateUrl('/apps/metavox/api/telemetry/status'))
				if (response.data.success) {
					this.telemetryEnabled = response.data.enabled
					this.lastReport = response.data.lastReport
				}
			} catch (error) {
				console.error('Failed to load telemetry status:', error)
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
						? this.t('metavox', 'Telemetry enabled')
						: this.t('metavox', 'Telemetry disabled'),
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
			return date.toLocaleString()
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
.telemetry-settings {
	max-width: 800px;
}

.settings-section {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px;
	margin-bottom: 20px;

	h3 {
		margin-top: 0;
		margin-bottom: 10px;
		font-size: 18px;
		font-weight: 600;
	}

	.description {
		color: var(--color-text-maxcontrast);
		margin-bottom: 20px;
	}
}

.setting-row {
	margin-bottom: 20px;
}

.info-box {
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	padding: 15px;
	margin-bottom: 20px;

	h4 {
		margin: 0 0 10px 0;
		font-size: 14px;
		font-weight: 600;
	}

	ul {
		margin: 0;
		padding-left: 20px;

		li {
			margin-bottom: 5px;
			color: var(--color-text-maxcontrast);
			font-size: 13px;
		}
	}
}

.status-section {
	margin-bottom: 20px;

	h4 {
		margin: 0 0 5px 0;
		font-size: 14px;
		font-weight: 600;
	}

	p {
		margin: 0;
		color: var(--color-text-maxcontrast);
	}
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
