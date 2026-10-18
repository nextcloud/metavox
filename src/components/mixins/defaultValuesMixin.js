import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { PLAIN_TEXT } from '../../utils/fieldValue.js'

/**
 * Shared per-folder DEFAULT-VALUE behaviour for the admin (ManageGroupfolders)
 * and personal (MetaVoxPersonal) settings pages: holds the in-memory defaults
 * map + backfill status, and the load/save/apply/poll API calls. Keeps both
 * pages identical and the endpoints (already per-folder permission-gated) the
 * single source of truth.
 *
 * Host component contract:
 *  - getAssignedFieldIds(groupfolderId): number[]  — currently assigned field ids
 *  - getFileFields(): Array<field>                  — all file-level field defs
 *  - persistFieldConfig(groupfolderId): Promise     — saves the field assignment
 *    (called before applying defaults so the latest config is on the server).
 */
export default {
  data() {
    return {
      // groupfolderId -> { fieldId -> value }
      fileFieldDefaults: {},
      // groupfolderId -> { fieldId -> linked files as you see them } (file links)
      fileFieldDefaultFiles: {},
      // groupfolderId -> 'idle' | 'running'
      defaultsStatus: {},
      // groupfolderId -> interval handle
      defaultsPollTimers: {},
    }
  },

  beforeUnmount() {
    Object.keys(this.defaultsPollTimers).forEach(id => this.stopDefaultsPolling(id))
  },

  methods: {
    /**
     * The subline under a folder name in the folder list.
     *
     * It used to read "%n fields configured" off the raw assignment count,
     * which is every field assigned to the folder regardless of what it
     * describes. IntraVox showed "10 fields configured" while the Edit
     * metadata dialog offered four inputs: the other six were file fields,
     * which belong to the files inside the folder and are edited in the
     * Files app, not here. Nothing was corrupt and nothing was hidden — one
     * number was answering a different question than the dialog beside it.
     *
     * So name both, in the same two groups the Configure fields dialog
     * already uses. The team-folder count is first because it is the one
     * that predicts this row: it decides whether Edit metadata is enabled
     * and what a click on the row opens.
     *
     * Lives in the mixin because the admin and personal pages show the same
     * list; they already share the two counting methods this builds on.
     */
    fieldsSummary(groupfolderId) {
      const folderFields = this.getAssignedGroupfolderFieldsCount(groupfolderId)
      const fileFields = this.getAssignedFieldsCount(groupfolderId) - folderFields

      // A folder with nothing assigned says so once rather than twice.
      if (folderFields === 0 && fileFields === 0) {
        // TRANSLATORS Shown in a Team folder row when no metadata fields are assigned to it
        return t('metavox', 'No fields configured')
      }

      // One string with both numbers rather than two joined by a separator:
      // a translator needs to see the whole line to order it, and a bare
      // ", " as its own translatable unit gives them nothing to work with.
      // TRANSLATORS Summary in a Team folder row. {folderFields} is e.g. "2 Team folder fields", {fileFields} e.g. "3 file fields"; keep both placeholders
      return t('metavox', '{folderFields} · {fileFields}', {
        // TRANSLATORS %n is the number of fields that describe the Team folder itself
        folderFields: n('metavox', '%n Team folder field', '%n Team folder fields', folderFields),
        // TRANSLATORS %n is the number of fields that can be set on files in the Team folder
        fileFields: n('metavox', '%n file field', '%n file fields', fileFields),
      }, undefined, PLAIN_TEXT)
    },

    async loadFolderDefaults(groupfolderId) {
      try {
        const resp = await axios.get(
          generateUrl(`/apps/metavox/api/groupfolders/${groupfolderId}/defaults`)
        )
        this.fileFieldDefaults[groupfolderId] = (resp.data && resp.data.defaults) || {}
        this.fileFieldDefaultFiles[groupfolderId] = (resp.data && resp.data.resolved) || {}
      } catch (error) {
        this.fileFieldDefaults[groupfolderId] = {}
        this.fileFieldDefaultFiles[groupfolderId] = {}
      }
    },

    getDefaultResolved(groupfolderId, field) {
      return (this.fileFieldDefaultFiles[groupfolderId] || {})[field.id] || []
    },

    getDefaultValue(groupfolderId, field) {
      const folderDefaults = this.fileFieldDefaults[groupfolderId] || {}
      const value = folderDefaults[field.id]
      return value !== undefined && value !== null ? String(value) : ''
    },

    setDefaultValue(groupfolderId, field, value) {
      if (!this.fileFieldDefaults[groupfolderId]) {
        this.fileFieldDefaults[groupfolderId] = {}
      }
      this.fileFieldDefaults[groupfolderId][field.id] = value
    },

    /**
     * Persist the defaults of the currently-assigned file fields for a folder.
     * Mirrors the admin save: one POST per assigned file field (null clears).
     */
    async saveFolderDefaults(groupfolderId) {
      const assignedIds = this.getAssignedFieldIds(groupfolderId) || []
      const folderDefaults = this.fileFieldDefaults[groupfolderId] || {}
      const fileFields = this.getFileFields() || []
      const promises = fileFields
        .filter(field => assignedIds.includes(field.id))
        .map(field => {
          const raw = folderDefaults[field.id]
          const value = (raw === undefined || raw === '') ? null : raw
          return axios.post(
            generateUrl(`/apps/metavox/api/groupfolders/${groupfolderId}/defaults`),
            { fieldId: field.id, value }
          ).catch(error => {
            console.error(`Failed to save default for field ${field.id}:`, error)
            // TRANSLATORS {field} is the label of a metadata field, e.g. "Status"
            showError(t('metavox', 'Failed to save default value for {field}', { field: field.field_label }))
          })
        })
      await Promise.all(promises)
    },

    async triggerDefaults(groupfolderId) {
      // Persist config + defaults first so "Apply defaults now" runs on the
      // latest values, then trigger the server-side backfill and poll.
      this.defaultsStatus[groupfolderId] = 'running'
      try {
        await this.persistFieldConfig(groupfolderId)
      } catch (error) {
        this.defaultsStatus[groupfolderId] = 'idle'
        return // persistFieldConfig already surfaced the error
      }

      try {
        await axios.post(
          generateUrl(`/apps/metavox/api/groupfolders/${groupfolderId}/defaults/trigger`)
        )
      } catch (error) {
        console.error('Failed to trigger defaults backfill:', error)
        showError(t('metavox', 'Failed to apply defaults'))
        this.defaultsStatus[groupfolderId] = 'idle'
        return
      }

      this.startDefaultsPolling(groupfolderId)
    },

    startDefaultsPolling(groupfolderId) {
      this.stopDefaultsPolling(groupfolderId)
      // Safety cap: the backfill is processed by background cron. If cron is not
      // running (or the folder is huge) the status would otherwise spin forever.
      const startedAt = Date.now()
      const MAX_POLL_MS = 120000
      this.defaultsPollTimers[groupfolderId] = setInterval(() => {
        if (Date.now() - startedAt > MAX_POLL_MS) {
          this.defaultsStatus[groupfolderId] = 'idle'
          this.stopDefaultsPolling(groupfolderId)
          showError(t('metavox', 'Applying defaults is taking longer than expected. It will continue in the background.'))
          return
        }
        this.pollDefaultsStatus(groupfolderId)
      }, 2000)
    },

    stopDefaultsPolling(groupfolderId) {
      if (this.defaultsPollTimers[groupfolderId]) {
        clearInterval(this.defaultsPollTimers[groupfolderId])
        this.defaultsPollTimers[groupfolderId] = null
      }
    },

    async pollDefaultsStatus(groupfolderId) {
      try {
        const resp = await axios.get(
          generateUrl(`/apps/metavox/api/groupfolders/${groupfolderId}/defaults/status`)
        )
        const state = (resp.data && resp.data.state) || 'idle'
        if (state === 'running') {
          this.defaultsStatus[groupfolderId] = 'running'
        } else {
          this.defaultsStatus[groupfolderId] = 'idle'
          this.stopDefaultsPolling(groupfolderId)
        }
      } catch (error) {
        this.defaultsStatus[groupfolderId] = 'idle'
        this.stopDefaultsPolling(groupfolderId)
      }
    },
  },
}
