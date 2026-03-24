<template>
	<div class="backup-restore">
		<div class="settings-section">
			<h2>{{ t('metavox', 'Backup & Restore') }}</h2>
			<p class="settings-section-desc">
				{{ t('metavox', 'Automatic daily backups of all metadata. You can also create manual backups and restore from any backup.') }}
			</p>

			<!-- Progress bar (shown during export/import) -->
			<div v-if="operationStatus && operationStatus.status === 'running'" class="progress-section">
				<div class="progress-header">
					<span class="progress-label">
						{{ operationStatus.operation === 'export' ? t('metavox', 'Creating backup...') : t('metavox', 'Restoring backup...') }}
					</span>
					<span class="progress-detail">
						{{ formatNumber(operationStatus.progress) }} / {{ formatNumber(operationStatus.total) }}
						<template v-if="operationStatus.table">
							&middot; {{ operationStatus.table }}
						</template>
					</span>
				</div>
				<div class="progress-bar-container">
					<div class="progress-bar-fill" :style="{ width: progressPercent + '%' }" />
				</div>
				<span class="progress-percent">{{ progressPercent }}%</span>
			</div>

			<!-- Completion message -->
			<div v-if="operationStatus && operationStatus.status === 'completed'" class="progress-section completed">
				<NcNoteCard type="success">
					{{ operationStatus.operation === 'export' ? t('metavox', 'Backup completed') : t('metavox', 'Restore completed') }}
					&middot; {{ operationStatus.duration }}s
					<template v-if="operationStatus.size">
						&middot; {{ formatSize(operationStatus.size) }}
					</template>
				</NcNoteCard>
			</div>

			<!-- Error message -->
			<div v-if="operationStatus && operationStatus.status === 'error'" class="progress-section">
				<NcNoteCard type="error">
					{{ operationStatus.operation === 'export' ? t('metavox', 'Backup failed') : t('metavox', 'Restore failed') }}:
					{{ operationStatus.error }}
				</NcNoteCard>
			</div>

			<!-- Create backup -->
			<div class="backup-actions">
				<NcButton type="primary"
					:disabled="isOperationRunning"
					@click="createBackup">
					{{ t('metavox', 'Create backup now') }}
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
							<template v-if="backup.compressed"> &middot; gz</template>
						</span>
					</div>
					<div class="backup-actions-row">
						<NcButton type="secondary"
							@click="downloadBackup(backup.filename)">
							{{ t('metavox', 'Download') }}
						</NcButton>
						<NcButton type="secondary"
							:disabled="isOperationRunning"
							@click="confirmRestore(backup)">
							{{ t('metavox', 'Restore') }}
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

	data() {
		return {
			backups: [],
			loading: true,
			showConfirmDialog: false,
			selectedBackup: null,
			operationStatus: null,
			pollTimer: null,
		}
	},

	computed: {
		isOperationRunning() {
			return this.operationStatus?.status === 'running'
		},
		progressPercent() {
			if (!this.operationStatus || !this.operationStatus.total) return 0
			return Math.min(100, Math.round((this.operationStatus.progress / this.operationStatus.total) * 100))
		},
	},

	mounted() {
		this.loadBackups()
		// Check if an operation is already running (survives page refresh)
		this.pollStatus()
	},

	beforeUnmount() {
		this.stopPolling()
	},

	methods: {
		async loadBackups() {
			this.loading = true
			try {
				const response = await axios.get(generateUrl('/apps/metavox/api/backup/list'))
				this.backups = response.data.backups || []
			} catch (error) {
				console.error('Failed to load backups:', error)
			} finally {
				this.loading = false
			}
		},

		async createBackup() {
			// Fire and forget — the request runs server-side, we poll for progress
			axios.post(generateUrl('/apps/metavox/api/backup/trigger')).then(() => {
				this.loadBackups()
			}).catch((error) => {
				if (error.response?.status !== 409) {
					console.error('Backup trigger failed:', error)
				}
			})

			// Start polling immediately
			this.operationStatus = {
				status: 'running',
				operation: 'export',
				progress: 0,
				total: 0,
				table: '',
			}
			this.startPolling()
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

			// Fire and forget
			axios.post(generateUrl('/apps/metavox/api/backup/restore'), {
				filename: this.selectedBackup.filename,
			}).then(() => {
				this.loadBackups()
			}).catch((error) => {
				if (error.response?.status !== 409) {
					console.error('Restore failed:', error)
				}
			})

			// Start polling immediately
			this.operationStatus = {
				status: 'running',
				operation: 'import',
				progress: 0,
				total: 0,
				table: 'preparing',
			}
			this.startPolling()
		},

		startPolling() {
			this.stopPolling()
			this.pollTimer = setInterval(() => this.pollStatus(), 2000)
		},

		stopPolling() {
			if (this.pollTimer) {
				clearInterval(this.pollTimer)
				this.pollTimer = null
			}
		},

		async pollStatus() {
			try {
				const response = await axios.get(generateUrl('/apps/metavox/api/backup/status'))
				const status = response.data

				if (status.status === 'running') {
					this.operationStatus = status
					if (!this.pollTimer) {
						this.startPolling()
					}
				} else if (status.status === 'completed' || status.status === 'error') {
					this.operationStatus = status
					this.stopPolling()
					if (status.status === 'completed') {
						this.loadBackups()
					}
				} else {
					// idle — no operation running
					if (this.pollTimer) {
						this.stopPolling()
					}
				}
			} catch (error) {
				// Status endpoint unavailable — stop polling
				this.stopPolling()
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
			const units = ['B', 'KB', 'MB', 'GB']
			let i = 0
			let size = bytes
			while (size >= 1024 && i < units.length - 1) {
				size /= 1024
				i++
			}
			return size.toFixed(i > 0 ? 1 : 0) + ' ' + units[i]
		},

		formatNumber(n) {
			if (!n) return '0'
			return Number(n).toLocaleString()
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

// Progress bar
.progress-section {
	margin-bottom: 20px;
	padding: 16px 20px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
}

.progress-section.completed {
	padding: 0;
	background: none;
}

.progress-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 8px;
}

.progress-label {
	font-weight: 600;
	color: var(--color-main-text);
}

.progress-detail {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.progress-bar-container {
	width: 100%;
	height: 8px;
	background: var(--color-border);
	border-radius: 4px;
	overflow: hidden;
}

.progress-bar-fill {
	height: 100%;
	background: var(--color-primary-element);
	border-radius: 4px;
	transition: width 0.5s ease;
}

.progress-percent {
	display: block;
	text-align: right;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
}
</style>
