<template>
  <div class="file-link-field-wrapper">
    <ul v-if="tokens.length" class="file-link-list">
      <li v-for="(token, index) in tokens" :key="index" class="file-link-row" :class="{ 'file-link-row--missing': isMissing(token) }">
        <component :is="iconFor(token)" :size="20" class="row-icon" @click="openFile(token)" />
        <span class="row-name" :title="titleFor(token)" @click="openFile(token)">{{ nameFor(token) }}</span>
        <!-- TRANSLATORS Accessible name and tooltip of the button that removes a linked file from the field -->
        <NcButton :aria-label="t('metavox', 'Remove')"
          variant="tertiary"
          :disabled="disabled"
          :title="t('metavox', 'Remove')"
          @click="removeAt(index)">
          <template #icon>
            <CloseIcon :size="16" />
          </template>
        </NcButton>
      </li>
    </ul>
    <!-- Hidden while a rejection is on screen: "No files selected" is still
         true, but stacked above the message it reads as the outcome of the
         pick, as though nothing had been chosen at all. -->
    <p v-else-if="!error" class="file-link-empty">
      <!-- TRANSLATORS Shown in a file link field that does not link to any file yet -->
      {{ placeholder || t('metavox', 'No files selected') }}
    </p>

    <NcButton
      :id="inputId"
      :disabled="disabled"
      :aria-describedby="error ? errorId : undefined"
      :aria-invalid="error ? 'true' : undefined"
      variant="secondary"
      @click="addFile">
      <template #icon>
        <PlusIcon :size="20" />
      </template>
      <!-- TRANSLATORS Button that opens the file picker to link a file to the field -->
      {{ t('metavox', 'Add file') }}
    </NcButton>

    <!-- A rejected pick is a validation error, so it belongs on the field:
         "show an error state together with a short helper message that tells
         the user how to fix it" (NC developer manual, Design > Components >
         Input fields). Core does the same for the identical "name is already
         in use" case in apps/files NewNodeDialog.vue, with :error +
         :helper-text on NcTextField and no toast. This field is built from a
         button and a list rather than an NcTextField, so it reproduces what
         NcInputField renders: the same class, tied to the control through
         aria-describedby. role=status (polite) rather than alert — the user
         just acted, so this needs no interruption. -->
    <p
      :id="errorId"
      class="file-error input-field__helper-text-message input-field__helper-text-message--error"
      :class="{ 'file-error--empty': !error }"
      role="status">
      {{ error }}
    </p>
  </div>
</template>

<script>
import { translate } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import FileIcon from 'vue-material-design-icons/File.vue'
import FileDocumentIcon from 'vue-material-design-icons/FileDocument.vue'
import FileImageIcon from 'vue-material-design-icons/FileImage.vue'
import FilePdfBoxIcon from 'vue-material-design-icons/FilePdfBox.vue'
import FileVideoIcon from 'vue-material-design-icons/FileVideo.vue'
import FileMusicIcon from 'vue-material-design-icons/FileMusic.vue'
import FileCodeIcon from 'vue-material-design-icons/FileCode.vue'
import { generateUrl } from '@nextcloud/router'
import { parseValue, joinTokens, displayName, isWithin } from './filelinkUtils.js'

export default {
  name: 'FileLinkFieldInput',
  components: {
    NcButton,
    PlusIcon,
    CloseIcon,
    FileIcon,
    FileDocumentIcon,
    FileImageIcon,
    FilePdfBoxIcon,
    FileVideoIcon,
    FileMusicIcon,
    FileCodeIcon
  },
  props: {
    modelValue: {
      type: String,
      default: ''
    },
    field: {
      type: Object,
      default: () => ({})
    },
    required: {
      type: Boolean,
      default: false
    },
    disabled: {
      type: Boolean,
      default: false
    },
    inputId: {
      type: String,
      default: ''
    },
    placeholder: {
      type: String,
      default: ''
    },
    // 'all', 'files', 'folders'
    selectionType: {
      type: String,
      default: 'all'
    },
    // Optional: limit to specific mimetypes
    mimetypes: {
      type: Array,
      default: () => []
    },
    // Team-folder root path (relative to the user's home, e.g. "/Projects").
    // When set, the picker opens here and links are constrained to this folder:
    // choosing a file outside it is rejected with a toast. Empty = no folder
    // context (e.g. the per-folder default editor) → picker opens at home and
    // the server-side team-folder validation remains the sole guard.
    basePath: {
      type: String,
      default: ''
    },
    // Path of the file being edited. A File link may not point at it: the
    // target would be the file you are already looking at, and the sidebar
    // would show the same row under both the field and "Linked from".
    // Empty = unknown (e.g. the admin default editor), which skips the
    // check; the server enforces it regardless.
    selfPath: {
      type: String,
      default: ''
    },
    // The linked files as this user sees them, from the server:
    // [{ fileId, name, path, exists }]. A value holds only ids, so this is
    // where names come from; an id missing here is gone or hidden to you.
    resolved: {
      type: Array,
      default: () => []
    }
  },
  emits: ['update:modelValue', 'input'],
  data() {
    return {
      error: ''
    }
  },
  computed: {
    // The stored value is one or more "<id>:" tokens joined with ';#'; a
    // file picked just now is a path until it is saved.
    tokens() {
      return parseValue(this.modelValue)
    },
    // Matches NcInputField's "<id>-helper-text", so the id is predictable and
    // unique per field even with several File link fields in one form.
    errorId() {
      return `${this.inputId || 'file-link'}-helper-text`
    },
    // fileId => { name, path } as you see the file.
    resolvedFiles() {
      const map = {}
      for (const info of (this.resolved || [])) {
        if (info && info.fileId != null && info.name) {
          map[info.fileId] = info
        }
      }
      return map
    },
    resolvedNames() {
      return Object.fromEntries(Object.entries(this.resolvedFiles).map(([id, info]) => [id, info.name]))
    }
  },
  methods: {
    t: translate,
    isMissing(token) {
      return token.fileId != null && !this.resolvedFiles[token.fileId]
    },
    nameFor(token) {
      // TRANSLATORS Shown in place of the name of a linked file that was deleted or cannot be accessed
      return this.isMissing(token) ? this.t('metavox', 'Unavailable file') : displayName(token, this.resolvedNames)
    },
    titleFor(token) {
      return token.fileId != null ? (this.resolvedFiles[token.fileId]?.path || this.nameFor(token)) : token.path
    },
    guessMimetype(filename) {
      const ext = (filename || '').split('.').pop()?.toLowerCase()
      const mimetypes = {
        jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', gif: 'image/gif',
        svg: 'image/svg+xml', webp: 'image/webp',
        pdf: 'application/pdf',
        doc: 'application/msword',
        docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        xls: 'application/vnd.ms-excel',
        xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ppt: 'application/vnd.ms-powerpoint',
        pptx: 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        txt: 'text/plain', md: 'text/markdown',
        mp4: 'video/mp4', webm: 'video/webm', mp3: 'audio/mpeg', wav: 'audio/wav',
        js: 'text/javascript', ts: 'text/typescript', json: 'application/json',
        html: 'text/html', css: 'text/css', php: 'text/php', py: 'text/python'
      }
      return mimetypes[ext] || 'application/octet-stream'
    },
    iconFor(token) {
      const mimetype = this.guessMimetype(this.nameFor(token))
      if (mimetype.startsWith('image/')) return 'FileImageIcon'
      if (mimetype === 'application/pdf') return 'FilePdfBoxIcon'
      if (mimetype.startsWith('video/')) return 'FileVideoIcon'
      if (mimetype.startsWith('audio/')) return 'FileMusicIcon'
      if (mimetype.startsWith('text/') || mimetype.includes('document')) return 'FileDocumentIcon'
      if (mimetype.includes('javascript') || mimetype.includes('json') || mimetype.includes('code')) return 'FileCodeIcon'
      return 'FileIcon'
    },
    emit(tokens) {
      const value = joinTokens(tokens)
      this.$emit('update:modelValue', value)
      this.$emit('input', value)
    },
    removeAt(index) {
      const next = this.tokens.slice()
      next.splice(index, 1)
      this.error = ''
      this.emit(next)
    },
    addFile() {
      this.error = ''
      // Open the picker inside the team folder when we know it, so the user
      // starts in the right place. The NC picker still allows navigating up,
      // so addPath() enforces the boundary on selection.
      const startPath = this.basePath && this.basePath !== '' ? this.basePath : '/'
      OC.dialogs.filepicker(
        this.t('metavox', 'Select a file or folder'),
        (path) => {
          if (path) {
            this.addPath(path)
          }
        },
        false, // multiselect
        this.mimetypes.length > 0 ? this.mimetypes : undefined,
        true, // modal
        OC.dialogs.FILEPICKER_TYPE_CHOOSE,
        startPath,
        { allowDirectoryChooser: this.selectionType !== 'files' }
      )
    },
    // Whether a picked path is the file this form is editing. The picker only
    // hands back a path, so this compares paths; the server repeats the check
    // on the file id and so also catches the same file reached another way.
    isSelf(path) {
      if (!this.selfPath) return false
      const norm = (p) => '/' + String(p || '').replace(/^\/+/, '').replace(/\/+$/, '')
      return norm(path) === norm(this.selfPath)
    },
    addPath(path) {
      // Enforce the team-folder boundary with immediate feedback. The backend
      // also drops out-of-folder tokens, but saying so here is clearer than a
      // link that silently disappears on save.
      if (!isWithin(path, this.basePath)) {
        this.error = this.t('metavox', 'Not added — only files inside this Team folder can be linked')
        return
      }
      if (this.isSelf(path)) {
        this.error = this.t('metavox', 'Not added — a file cannot be linked to itself')
        return
      }
      // Don't add the same file twice. The server also dedups on the file id.
      if (this.tokens.some((t) => t.path === path || this.resolvedFiles[t.fileId]?.path === path)) {
        this.error = this.t('metavox', 'Not added — this file is already linked')
        return
      }
      const next = this.tokens.slice()
      next.push({ fileId: null, path })
      this.error = ''
      this.emit(next)
    },
    openFile(token) {
      if (this.isMissing(token)) return
      // Prefer opening by fileid (robust to renames/moves). NC's canonical
      // "open file by id" route is /f/{fileid} (the same link share emails use);
      // fall back to the legacy dir+openfile form when there is no id.
      if (token.fileId != null) {
        window.open(generateUrl('/f/{fileId}', { fileId: token.fileId }), '_blank')
        return
      }
      if (!token.path) return
      const filesUrl = generateUrl('/apps/files/?dir={dir}&openfile={file}', {
        dir: token.path.substring(0, token.path.lastIndexOf('/')),
        file: token.path
      })
      window.open(filesUrl, '_blank')
    }
  }
}
</script>

<style scoped>
.file-link-field-wrapper {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.file-link-list {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.file-link-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 8px;
  border-radius: var(--border-radius);
  background-color: var(--color-background-hover);
}

.row-icon,
.row-name {
  cursor: pointer;
}

.row-name {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.row-name:hover {
  text-decoration: underline;
}

.file-link-row--missing .row-icon,
.file-link-row--missing .row-name {
  color: var(--color-text-maxcontrast);
  font-style: italic;
  cursor: default;
  text-decoration: none;
}

/* The <p> kept the browser's default block margins, which added ~16px above
   and below this line — the field then sat further from its own label than
   from the next field, and the grouping read wrong. */
.file-link-empty {
  margin: 0;
  color: var(--color-text-maxcontrast);
  font-size: var(--font-size-small);
}

/* Colour and size come from NcInputField's own helper text; only the spacing
   is ours. --color-error is the pale background tint and is near-invisible as
   text, so the readable tier is --color-error-text. */
.file-error {
  color: var(--color-error-text);
  font-size: var(--font-size-small);
  margin-block: var(--default-grid-baseline) 0;
  min-height: 1lh;
}

/* The line is always in the layout, so the dialog does not jump when a
   message appears or clears — core reserves the same space in apps/files
   NewNodeDialog.vue. Hidden from assistive tech while empty. */
.file-error--empty {
  visibility: hidden;
}

</style>
