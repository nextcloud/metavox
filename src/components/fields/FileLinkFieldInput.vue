<template>
  <div class="file-link-field-wrapper">
    <ul v-if="tokens.length" class="file-link-list">
      <li v-for="(token, index) in tokens" :key="index" class="file-link-row">
        <component :is="iconFor(token)" :size="20" class="row-icon" @click="openFile(token)" />
        <span class="row-name" :title="token.path || nameFor(token)" @click="openFile(token)">{{ nameFor(token) }}</span>
        <NcButton
          type="tertiary"
          :disabled="disabled"
          :aria-label="t('metavox', 'Remove')"
          :title="t('metavox', 'Remove')"
          @click="removeAt(index)">
          <template #icon>
            <CloseIcon :size="16" />
          </template>
        </NcButton>
      </li>
    </ul>
    <p v-else class="file-link-empty">
      {{ placeholder || t('metavox', 'No files selected') }}
    </p>

    <NcButton
      :id="inputId"
      :disabled="disabled"
      type="secondary"
      @click="addFile">
      <template #icon>
        <PlusIcon :size="20" />
      </template>
      {{ t('metavox', 'Add file') }}
    </NcButton>

    <p v-if="error" class="file-error">
      {{ error }}
    </p>
  </div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
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
import { showWarning } from '@nextcloud/dialogs'
import { parseValue, joinTokens, displayName } from './filelinkUtils.js'

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
    // Server-resolved current info: [{ fileId, name, path, exists }].
    // Used to show live names after the target was renamed/moved.
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
    // The stored value is one or more "<id>:path" tokens joined with ';#'.
    // A single legacy/single value is simply a one-element list.
    tokens() {
      return parseValue(this.modelValue)
    },
    // fileId => current name, from the server-resolved payload.
    resolvedNames() {
      const map = {}
      for (const info of (this.resolved || [])) {
        if (info && info.fileId != null && info.name) {
          map[info.fileId] = info.name
        }
      }
      return map
    }
  },
  methods: {
    t(app, text) {
      return window.t ? window.t(app, text) : text
    },
    nameFor(token) {
      return displayName(token, this.resolvedNames)
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
    // Whether a picked path lies within the configured team folder.
    isWithinBase(path) {
      if (!this.basePath || this.basePath === '') {
        return true // no folder context → don't block client-side
      }
      const base = this.basePath.replace(/\/+$/, '')
      return path === base || path.startsWith(base + '/')
    },
    addPath(path) {
      // Enforce the team-folder boundary with immediate feedback. The backend
      // also drops out-of-folder tokens, but a toast is clearer than a link
      // that silently disappears on save.
      if (!this.isWithinBase(path)) {
        showWarning(this.t('metavox', 'You can only link files inside this team folder'))
        return
      }
      // Don't add the same path twice. The server also dedups on the resolved
      // fileid (catches the same file via a different path).
      if (this.tokens.some((t) => t.path === path)) {
        showWarning(this.t('metavox', 'This file is already linked'))
        return
      }
      const next = this.tokens.slice()
      next.push({ fileId: null, path })
      this.emit(next)
    },
    openFile(token) {
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

.file-link-empty {
  color: var(--color-text-maxcontrast);
  font-size: 0.9em;
}

.file-error {
  color: var(--color-error);
  font-size: 0.85em;
}
</style>
