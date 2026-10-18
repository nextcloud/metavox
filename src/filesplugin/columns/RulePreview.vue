<template>
	<!-- The column as the file list shows it, on a few values: the same state,
	     wash, bar and icon as the grid. Headed like Talk's chat preview, by
	     NcFormGroup; not a table to step through: read in order, each value
	     comes with its state. -->
	<NcFormGroup class="mv-grid-preview" :label="t('metavox', 'In the file list')">
		<div class="mv-grid-preview__frame">
			<table role="presentation">
				<thead>
					<tr>
						<th>{{ t('metavox', 'Name') }}</th>
						<th :title="field.field_label">{{ field.field_label }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in rows" :key="row.key">
						<td class="mv-grid-preview__name" aria-hidden="true">
							<img :src="FILE_ICON" alt="" width="20" height="20">
							<span class="mv-grid-preview__bar" />
						</td>
						<td :data-mv-tone="row.state?.tone">
							<span class="mv-grid-preview__value">
								<span v-if="row.state"
									class="mv-tone-icon"
									role="img"
									:aria-label="row.state.note"
									:title="row.state.note">
									<svg viewBox="0 0 24 24"
										width="16"
										height="16"
										aria-hidden="true"
										focusable="false">
										<path fill="currentColor" :d="ICON_PATHS[row.state.tone]" />
									</svg>
								</span>
								<img v-if="row.person"
									class="metavox-cell-avatar"
									:src="avatar(row.value)"
									alt=""
									width="20"
									height="20"
									@error="$event.target.hidden = true">
								<span class="mv-grid-preview__text">{{ row.text }}</span>
							</span>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</NcFormGroup>
</template>

<script setup>
import { computed } from 'vue'
import NcFormGroup from '@nextcloud/vue/components/NcFormGroup'
import { getCurrentUser } from '@nextcloud/auth'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl, imagePath } from '@nextcloud/router'
import { todayKey } from '../../utils/dateField.js'
import { previewRows } from './RulePreviewRows.js'
import { ICON_PATHS } from './ToneIcons.js'

const props = defineProps({
	// The column: field_type, field_options, field_label.
	field: { type: Object, required: true },
	// Its rules in order, the one being edited in its place.
	rules: { type: Array, required: true },
})

const FILE_ICON = imagePath('core', 'filetypes/text.svg')
const me = getCurrentUser()?.uid ?? null

const rows = computed(() => previewRows(props.field, props.rules, { today: todayKey(), me }))
const avatar = (uid) => generateUrl('/avatar/{userId}/20', { userId: uid })
</script>

<style scoped>
.mv-grid-preview {
	margin: 4px 0 12px;
}

.mv-grid-preview__frame {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element, 8px);
	overflow: hidden;
}

table {
	inline-size: 100%;
	border-collapse: collapse;
	table-layout: fixed;
	background: var(--color-main-background);
}

th,
td {
	block-size: calc(var(--default-clickable-area, 34px) + 8px);
	padding: 0 12px;
	text-align: start;
	border-block-start: 1px solid var(--color-border);
}

th {
	block-size: var(--default-clickable-area, 34px);
	border-block-start: 0;
	font-weight: normal;
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* A cell without a state reads like the grid's: muted. */
td:not([data-mv-tone]) {
	color: var(--color-text-maxcontrast);
}

.mv-grid-preview__name {
	color: var(--color-text-maxcontrast);
}

.mv-grid-preview__name > * {
	vertical-align: middle;
}

.mv-grid-preview__name img {
	margin-inline-end: 8px;
}

/* Where the name would be: the column is what this is about. */
.mv-grid-preview__bar {
	display: inline-block;
	inline-size: 55%;
	block-size: 8px;
	border-radius: 4px;
	background: var(--color-background-darker);
}

tr:nth-child(3n + 2) .mv-grid-preview__bar {
	inline-size: 40%;
}

tr:nth-child(3n) .mv-grid-preview__bar {
	inline-size: 65%;
}

.mv-grid-preview__value {
	display: flex;
	align-items: center;
	gap: 6px;
	min-inline-size: 0;
}

.mv-grid-preview__value .mv-tone-icon {
	margin-inline-end: 0;
}

.mv-grid-preview__text {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
</style>
