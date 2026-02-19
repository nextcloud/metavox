<template>
	<div class="backup-restore">
		<div class="settings-section">
			<h2>{{ t('metavox', 'Backup & Restore') }}</h2>
			<p class="settings-section-desc">
				{{ t('metavox', 'Automatic daily backups of all metadata. You can also create manual backups and restore from any backup.') }}
			</p>

			<!-- Create backup -->
			<div class="backup-actions">
				<NcButton type="primary"
					:disabled="creatingBackup"
					@click="createBackup">
					{{ creatingBackup ? t('metavox', 'Creating backup...') : t('metavox', 'Create backup now') }}
				</NcButton>
			</div>

			<!-- Backup list -->
			<div v-if="loading" class="loading">
				{{ t('metavox', 'Loading backups...') }}
			</div>

			<div v-else-if="backups.length === 0" class="no-backups">
				<NcNoteCard type="info">
					{{ t('metavox', 'No backups available yet. Backups are created automatically every 24 hours, or you can create one manually.') }}
				</NcNoteCard>
			</div>

			<div v-else class="backup-list">
				<div v-for="backup in backups"
					:key="backup.filename"
					class="backup-row">
					<div class="backup-info">
						<span class="backup-date">{{ formatDate(backup.created_at) }}</span>
						<span class="backup-meta">
							v{{ backup.version }} &middot;
							{{ totalEntries(backup) }} {{ t('metavox', 'entries') }} &middot;
							{{ formatSize(backup.size) }}
						</span>
					</div>
					<div class="backup-actions-row">
						<NcButton type="secondary"
							@click="downloadBackup(backup.filename)">
							{{ t('metavox', 'Download') }}
						</NcButton>
						<NcButton type="secondary"
							:disabled="restoring === backup.filename"
							@click="confirmRestore(backup)">
							{{ restoring === backup.filename ? t('metavox', 'Restoring...') : t('metavox', 'Restore') }}
						</NcButton>
					</div>
				</div>
			</div>
		</div>

		<!-- Restore confirmation dialog -->
		<NcDialog v-if="showConfirmDialog"
			:name="t('metavox', 'Restore backup')"
			@closing="showConfirmDialog = false">
			<p>{{ t('metavox', 'Are you sure you want to restore this backup? This will replace all current metadata with the backup data.') }}</p>
			<p><strong>{{ t('metavox', 'Backup from') }}: {{ formatDate(selectedBackup?.created_at) }}</strong></p>
			<p v-if="selectedBackup">
				{{ selectedBackup.counts?.metavox_gf_fields || 0 }} {{ t('metavox', 'field definitions') }},
				{{ selectedBackup.counts?.metavox_gf_metadata || 0 }} {{ t('metavox', 'groupfolder metadata') }},
				{{ selectedBackup.counts?.metavox_file_gf_meta || 0 }} {{ t('metavox', 'file metadata entries') }}
			</p>
			<template #actions>
				<NcButton type="tertiary" @click="showConfirmDialog = false">
					{{ t('metavox', 'Cancel') }}
				</NcButton>
				<NcButton type="error" @click="doRestore">
					{{ t('metavox', 'Restore') }}
				</NcButton>
			</template>
		</NcDialog>

		<div v-if="message" :class="['message', messageType]">
			{{ message }}
		</div>
	</div>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'BackupRestore',

	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
	},

	emits: ['notification'],

	data() {
		return {
			backups: [],
			loading: true,
			creatingBackup: false,
			restoring: null,
			showConfirmDialog: false,
			selectedBackup: null,
			message: '',
			messageType: 'success',
		}
	},

	mounted() {
		this.loadBackups()
	},

	methods: {
		async loadBackups() {
			this.loading = true
			try {
				const response = await axios.get(generateUrl('/apps/metavox/api/backup/list'))
				this.backups = response.data.backups || []
			} catch (error) {
				console.error('Failed to load backups:', error)
				this.showMessage(this.t('metavox', 'Failed to load backups'), 'error')
			} finally {
				this.loading = false
			}
		},

		async createBackup() {
			this.creatingBackup = true
			try {
				const response = await axios.post(generateUrl('/apps/metavox/api/backup/trigger'))
				if (response.data.success) {
					this.showMessage(this.t('metavox', 'Backup created successfully'), 'success')
					await this.loadBackups()
				} else {
					this.showMessage(this.t('metavox', 'Failed to create backup'), 'error')
				}
			} catch (error) {
				console.error('Failed to create backup:', error)
				this.showMessage(this.t('metavox', 'Failed to create backup'), 'error')
			} finally {
				this.creatingBackup = false
			}
		},

		downloadBackup(filename) {
			const url = generateUrl('/apps/metavox/api/backup/download') + '?filename=' + encodeURIComponent(filename)
			window.open(url, '_blank')
		},

		confirmRestore(backup) {
			this.selectedBackup = backup
			this.showConfirmDialog = true
		},

		async doRestore() {
			this.showConfirmDialog = false
			if (!this.selectedBackup) return

			this.restoring = this.selectedBackup.filename
			try {
				const response = await axios.post(generateUrl('/apps/metavox/api/backup/restore'), {
					filename: this.selectedBackup.filename,
				})
				if (response.data.success) {
					const r = response.data.restored
					this.showMessage(
						this.t('metavox', 'Restore completed: {fields} fields, {gfMeta} groupfolder metadata, {fileMeta} file metadata entries', {
							fields: r.metavox_gf_fields,
							gfMeta: r.metavox_gf_metadata,
							fileMeta: r.metavox_file_gf_meta,
						}),
						'success',
					)
				} else {
					this.showMessage(this.t('metavox', 'Restore failed'), 'error')
				}
			} catch (error) {
				console.error('Failed to restore backup:', error)
				this.showMessage(this.t('metavox', 'Restore failed: ') + (error.response?.data?.error || error.message), 'error')
			} finally {
				this.restoring = null
			}
		},

		totalEntries(backup) {
			if (!backup.counts) return 0
			return Object.values(backup.counts).reduce((sum, count) => sum + Number(count), 0)
		},

		formatDate(dateStr) {
			if (!dateStr) return this.t('metavox', 'Unknown')
			const date = new Date(dateStr)
			return date.toLocaleString(undefined, {
				year: 'numeric',
				month: 'long',
				day: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
			})
		},

		formatSize(bytes) {
			if (!bytes) return '0 B'
			const units = ['B', 'KB', 'MB']
			let i = 0
			let size = bytes
			while (size >= 1024 && i < units.length - 1) {
				size /= 1024
				i++
			}
			return size.toFixed(i > 0 ? 1 : 0) + ' ' + units[i]
		},

		showMessage(text, type) {
			this.message = text
			this.messageType = type
			setTimeout(() => {
				this.message = ''
			}, 8000)
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
.backup-restore {
	max-width: 800px;
}

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

.backup-actions {
	margin-bottom: 24px;
}

.backup-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.backup-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 16px 20px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
}

.backup-actions-row {
	display: flex;
	gap: 8px;
}

.backup-info {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.backup-date {
	font-weight: 500;
	color: var(--color-main-text);
}

.backup-meta {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.no-backups {
	margin-top: 8px;
}

.loading {
	padding: 20px;
	color: var(--color-text-maxcontrast);
	text-align: center;
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
		border: 1px solid #f5c6cb;
	}
}
</style>
