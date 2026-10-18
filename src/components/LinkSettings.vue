<template>
	<div class="link-settings">
		<NcCheckboxRadioSwitch type="switch"
			:model-value="webOnly"
			:disabled="saving"
			@update:model-value="saveWebOnly">
			<!-- TRANSLATORS Switch label in the administration settings: URL fields then take only web and email addresses -->
			{{ t('metavox', 'Only web and email addresses') }}
		</NcCheckboxRadioSwitch>
		<p class="link-settings__desc">
			<!-- TRANSLATORS Explanation under a switch; keep http://, https://, mailto: and javascript: as they are -->
			{{ t('metavox', 'Links must start with http://, https:// or mailto:. When off, any link is allowed except one that runs code, such as javascript:.') }}
		</p>

		<!-- TRANSLATORS Label of a list of domains that links in URL fields may not point at -->
		<NcTextArea :label="t('metavox', 'Blocked domains')"
			v-model="blocked"
			:helper-text="errors.url_blocked_domains || blockedHelp"
			:error="!!errors.url_blocked_domains"
			:disabled="saving"
			resize="vertical"
			:rows="4" />
		<!-- TRANSLATORS Label of a list of domains: when it is filled in, links in URL fields may point at these domains only -->
		<NcTextArea :label="t('metavox', 'Only allow these domains')"
			v-model="allowed"
			:helper-text="errors.url_allowed_domains || allowedHelp"
			:error="!!errors.url_allowed_domains"
			:disabled="saving"
			resize="vertical"
			:rows="4" />
		<NcButton variant="primary" :disabled="saving || !changed" @click="saveLists">
			{{ t('metavox', 'Save') }}
		</NcButton>

		<p class="link-settings__note">
			{{ t('metavox', 'Applies to links people add or change. Links already saved stay as they are.') }}
		</p>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { errorText, isFieldError } from '../utils/errorText.js'

/** One domain per line; a top-level domain keeps its dot, as the admin typed it: .ru */
const asText = (domains) => (Array.isArray(domains) ? domains : []).map((domain) => (domain.includes('.') ? domain : `.${domain}`)).join('\n')

/**
 * What URL fields accept: web and email addresses only, blocked domains, or
 * some domains only. The server checks every link against it when it is saved.
 */
export default {
	name: 'LinkSettings',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcTextArea,
	},

	data() {
		return {
			webOnly: false,
			blocked: '',
			allowed: '',
			saved: { blocked: '', allowed: '' },
			errors: {},
			saving: false,
		}
	},

	computed: {
		changed() {
			return this.blocked !== this.saved.blocked || this.allowed !== this.saved.allowed
		},
		blockedHelp() {
			// TRANSLATORS Help under the list of blocked domains; keep .ru and example.com as they are
			return this.t('metavox', 'One per line: .ru blocks every .ru address, example.com that site and its subdomains.')
		},
		allowedHelp() {
			// TRANSLATORS Help under the list of the only allowed domains
			return this.t('metavox', 'One per line. Leave empty to allow every domain that is not blocked.')
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		t: translate,

		async load() {
			try {
				const { data } = await axios.get(generateUrl('/apps/metavox/api/settings'))
				if (data.success) {
					this.webOnly = !!data.settings.url_web_only
					this.blocked = this.saved.blocked = asText(data.settings.url_blocked_domains)
					this.allowed = this.saved.allowed = asText(data.settings.url_allowed_domains)
				}
			} catch (e) {
				// Empty and off rather than claiming a state we could not read.
				console.error('Failed to load link settings:', e)
			}
		},

		async saveWebOnly(on) {
			const previous = this.webOnly
			this.webOnly = on
			this.saving = true
			try {
				await axios.post(generateUrl('/apps/metavox/api/settings'), { url_web_only: on })
				showSuccess(this.t('metavox', 'Setting saved.'))
			} catch (e) {
				this.webOnly = previous
				showError(this.t('metavox', 'Could not save the setting. It is unchanged.'))
			} finally {
				this.saving = false
			}
		},

		async saveLists() {
			this.saving = true
			this.errors = {}
			try {
				await axios.post(generateUrl('/apps/metavox/api/settings'), {
					url_blocked_domains: this.blocked,
					url_allowed_domains: this.allowed,
				})
				// Shown as the server keeps them: one per line, without doubles.
				await this.load()
				showSuccess(this.t('metavox', 'Setting saved.'))
			} catch (e) {
				if (isFieldError(e)) {
					this.errors = e.response.data.fields
				} else {
					showError(errorText(e, this.t('metavox', 'Could not save the setting. It is unchanged.')))
				}
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.link-settings {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 12px;
	max-width: 600px;
}

.link-settings :deep(.textarea) {
	width: 100%;
}

/* As in AiSettings: the explanation lines up with its switch's label. */
.link-settings__desc {
	margin: -8px 0 4px;
	padding-inline-start: calc(var(--default-clickable-area) + 8px);
	color: var(--color-text-maxcontrast);
}

.link-settings__note {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
