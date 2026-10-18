<template>
	<div class="search-settings">
		<NcCheckboxRadioSwitch type="switch"
			:model-value="filter"
			:disabled="saving"
			@update:model-value="(on) => save('search_filter', on)">
			{{ t('metavox', 'Metadata filter in the search bar') }}
		</NcCheckboxRadioSwitch>
		<p class="search-settings__desc">
			{{ t('metavox', 'Users can find files by a field and its value from Nextcloud\'s search bar. Light: one search reads an index.') }}
		</p>

		<!-- Without the filter it can still be turned off, not on: turning the
		     filter back on then doesn't bring the folders along. -->
		<NcCheckboxRadioSwitch type="switch"
			:model-value="folders"
			:disabled="saving || (!filter && !folders)"
			@update:model-value="(on) => save('search_folders', on)">
			{{ t('metavox', 'Save a filter as a folder in Files') }}
		</NcCheckboxRadioSwitch>
		<p class="search-settings__desc">
			{{ t('metavox', 'Users can keep a filter as a folder under "Metadata searches" in Files (Nextcloud 33 and newer). Opening one shows up to 500 files and asks more of the server than a search. Works only with the filter on.') }}
		</p>

		<p class="search-settings__note">
			{{ t('metavox', 'A change shows once a page is loaded again.') }}
		</p>
	</div>
</template>

<script>
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * The admin's two switches for searching metadata. The filter starts on, as
 * users had it; the folders start off: they cost more per opening, so it is
 * the admin who weighs them.
 */
export default {
	name: 'SearchSettings',

	components: {
		NcCheckboxRadioSwitch,
	},

	data() {
		return {
			filter: false,
			folders: false,
			saving: false,
		}
	},

	async mounted() {
		try {
			const { data } = await axios.get(generateUrl('/apps/metavox/api/settings'))
			if (data.success) {
				this.filter = !!data.settings.search_filter
				this.folders = !!data.settings.search_folders
			}
		} catch (e) {
			// The switches stay off rather than claiming a state we could not read.
			console.error('Failed to load search settings:', e)
		}
	},

	methods: {
		t: translate,

		async save(key, on) {
			const name = key === 'search_filter' ? 'filter' : 'folders'
			const previous = this[name]
			this[name] = on
			this.saving = true
			try {
				await axios.post(generateUrl('/apps/metavox/api/settings'), { [key]: on })
				showSuccess(this.t('metavox', 'Setting saved.'))
			} catch (e) {
				console.error('Failed to update search settings:', e)
				this[name] = previous
				showError(this.t('metavox', 'Could not save the setting. It is unchanged.'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
/* As in AiSettings: the explanation lines up with its switch's label. */
.search-settings__desc {
	margin: 4px 0 16px;
	padding-inline-start: calc(var(--default-clickable-area) + 8px);
	color: var(--color-text-maxcontrast);
}

.search-settings__note {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
