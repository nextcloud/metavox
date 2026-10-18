<template>
	<div class="ai-settings">
		<NcCheckboxRadioSwitch type="switch"
			:model-value="aiEnabled"
			:disabled="saving"
			@update:model-value="toggleAi">
			<!-- TRANSLATORS Switch label in the admin settings; AI is artificial intelligence -->
			{{ t('metavox', 'Enable AI metadata generation') }}
		</NcCheckboxRadioSwitch>

		<p class="ai-settings__desc">
			<!-- TRANSLATORS "Generate with AI" is the name of a button in the Files sidebar; use the same translation as that button -->
			{{ t('metavox', 'When enabled, people see a "Generate with AI" button in the metadata sidebar. The AI reads file content and suggests values for metadata fields.') }}
		</p>
	</div>
</template>

<script>
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate, translatePlural } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * The AI metadata generation switch.
 *
 * It used to sit under the "Metadata statistics" heading, between a row of
 * counts and a telemetry opt-in. Statistics are something an administrator
 * reads; this changes what every user sees — a "Generate with AI" button in
 * the Files sidebar — and it is the most consequential switch on the page.
 * Nobody looking for it would open a pane named after counting.
 */
export default {
	name: 'AiSettings',

	components: {
		NcCheckboxRadioSwitch,
	},

	data() {
		return {
			aiEnabled: false,
			saving: false,
		}
	},

	async mounted() {
		try {
			const { data } = await axios.get(generateUrl('/apps/metavox/api/settings'))
			if (data.success) {
				this.aiEnabled = !!data.settings.ai_enabled
			}
		} catch (e) {
			// The switch stays off rather than claiming a state we could not read.
			console.error('Failed to load AI settings:', e)
		}
	},

	methods: {
		t: translate,
		n: translatePlural,

		/**
		 * The switch is uncontrolled for the moment it is flipped, so a failed
		 * save leaves the UI claiming the opposite of the server until a
		 * reload. Restore the previous value explicitly.
		 */
		async toggleAi(enabled) {
			const previous = this.aiEnabled
			this.aiEnabled = enabled
			this.saving = true
			try {
				await axios.post(generateUrl('/apps/metavox/api/settings'), { ai_enabled: enabled })
				showSuccess(enabled
					// TRANSLATORS Notification after the AI metadata switch was turned on; AI is artificial intelligence
					? this.t('metavox', 'AI metadata generation enabled.')
					// TRANSLATORS Notification after the AI metadata switch was turned off; AI is artificial intelligence
					: this.t('metavox', 'AI metadata generation disabled.'))
			} catch (e) {
				console.error('Failed to update AI settings:', e)
				this.aiEnabled = previous
				showError(this.t('metavox', 'Could not save the setting. It is unchanged.'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
/* The explanation belongs to the switch above it, so it sits closer to that
   than the next block does to this one, and it lines up with the switch's own
   label rather than with the box (§6). */
.ai-settings__desc {
	/* Body size. Core's Sharing settings explain every switch at 15px and
	   never at --font-size-small, and NcTextField's own helper text inherits
	   the same. Small *and* maxcontrast is the pairing §5 warns about. */
	margin: 4px 0 0;
	padding-inline-start: calc(var(--default-clickable-area) + 8px);
	color: var(--color-text-maxcontrast);
}
</style>
