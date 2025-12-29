<template>
  <div class="file-link-field-wrapper">
    <div class="file-input-container">
      <NcTextField
        :id="inputId"
        :value="displayPath"
        :disabled="true"
        :placeholder="placeholder || t('metavox', 'No file selected')"
        class="file-path-input" />
      <NcButton
        :disabled="disabled"
        type="secondary"
        @click="openFilePicker">
        <template #icon>
          <FolderIcon :size="20" />
        </template>
        {{ t('metavox', 'Browse') }}
      </NcButton>
      <NcButton
        v-if="modelValue"
        :disabled="disabled"
        type="tertiary"
        @click="clearSelection"
        :title="t('metavox', 'Clear selection')">
        <template #icon>
          <CloseIcon :size="20" />
        </template>
      </NcButton>
    </div>

    <!-- File info display -->
    <div v-if="modelValue && fileInfo" class="file-info">
      <div class="file-preview" @click="openFile">
        <component :is="getFileIcon(fileInfo.mimetype)" :size="32" class="file-icon" />
        <div class="file-details">
          <span class="file-name">{{ fileInfo.name }}</span>
          <span class="file-path">{{ fileInfo.path }}</span>
        </div>
        <OpenInNew :size="16" class="open-icon" />
      </div>
    </div>

    <!-- Error message -->
    <p v-if="error" class="file-error">
      {{ error }}
    </p>
  </div>
</template>

<script>
import { NcTextField, NcButton } from '@nextcloud/vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import FileIcon from 'vue-material-design-icons/File.vue'
import FileDocumentIcon from 'vue-material-design-icons/FileDocument.vue'
import FileImageIcon from 'vue-material-design-icons/FileImage.vue'
import FilePdfBoxIcon from 'vue-material-design-icons/FilePdfBox.vue'
import FileVideoIcon from 'vue-material-design-icons/FileVideo.vue'
import FileMusicIcon from 'vue-material-design-icons/FileMusic.vue'
import FileCodeIcon from 'vue-material-design-icons/FileCode.vue'
import FolderOpenIcon from 'vue-material-design-icons/FolderOpen.vue'
import { generateUrl } from '@nextcloud/router'

export default {
  name: 'FileLinkFieldInput',
  components: {
    NcTextField,
    NcButton,
    FolderIcon,
    CloseIcon,
    OpenInNew,
    FileIcon,
    FileDocumentIcon,
    FileImageIcon,
    FilePdfBoxIcon,
    FileVideoIcon,
    FileMusicIcon,
    FileCodeIcon,
    FolderOpenIcon
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
    }
  },
  emits: ['update:modelValue', 'input'],
  data() {
    return {
      fileInfo: null,
      error: ''
    }
  },
  computed: {
    displayPath() {
      if (!this.modelValue) return ''
      // Extract filename from path
      const parts = this.modelValue.split('/')
      return parts[parts.length - 1] || this.modelValue
    }
  },
  watch: {
    modelValue: {
      immediate: true,
      handler(newValue) {
        if (newValue) {
          this.parseFileInfo(newValue)
        } else {
          this.fileInfo = null
        }
      }
    }
  },
  methods: {
    t(app, text) {
      return window.t ? window.t(app, text) : text
    },
    parseFileInfo(path) {
      // Parse the stored value to extract file info
      // Format: "fileId:path" or just "path"
      const parts = path.split('/')
      const name = parts[parts.length - 1] || path

      this.fileInfo = {
        path: path,
        name: name,
        mimetype: this.guessMimetype(name)
      }
    },
    guessMimetype(filename) {
      const ext = filename.split('.').pop()?.toLowerCase()
      const mimetypes = {
        // Images
        'jpg': 'image/jpeg',
        'jpeg': 'image/jpeg',
        'png': 'image/png',
        'gif': 'image/gif',
        'svg': 'image/svg+xml',
        'webp': 'image/webp',
        // Documents
        'pdf': 'application/pdf',
        'doc': 'application/msword',
        'docx': 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls': 'application/vnd.ms-excel',
        'xlsx': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt': 'application/vnd.ms-powerpoint',
        'pptx': 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt': 'text/plain',
        'md': 'text/markdown',
        // Media
        'mp4': 'video/mp4',
        'webm': 'video/webm',
        'mp3': 'audio/mpeg',
        'wav': 'audio/wav',
        // Code
        'js': 'text/javascript',
        'ts': 'text/typescript',
        'json': 'application/json',
        'html': 'text/html',
        'css': 'text/css',
        'php': 'text/php',
        'py': 'text/python'
      }
      return mimetypes[ext] || 'application/octet-stream'
    },
    getFileIcon(mimetype) {
      if (!mimetype) return 'FileIcon'

      if (mimetype.startsWith('image/')) return 'FileImageIcon'
      if (mimetype === 'application/pdf') return 'FilePdfBoxIcon'
      if (mimetype.startsWith('video/')) return 'FileVideoIcon'
      if (mimetype.startsWith('audio/')) return 'FileMusicIcon'
      if (mimetype.startsWith('text/') || mimetype.includes('document')) return 'FileDocumentIcon'
      if (mimetype.includes('javascript') || mimetype.includes('json') || mimetype.includes('code')) return 'FileCodeIcon'
      if (mimetype === 'httpd/unix-directory') return 'FolderOpenIcon'

      return 'FileIcon'
    },
    openFilePicker() {
      this.error = ''

      // Determine picker type
      let pickerType = OC.dialogs.FILEPICKER_TYPE_CHOOSE

      if (this.selectionType === 'folders') {
        pickerType = OC.dialogs.FILEPICKER_TYPE_CHOOSE
      }

      // Use Nextcloud's built-in file picker
      OC.dialogs.filepicker(
        this.t('metavox', 'Select a file or folder'),
        (path) => {
          if (path) {
            this.$emit('update:modelValue', path)
            this.$emit('input', path)
          }
        },
        false, // multiselect
        this.mimetypes.length > 0 ? this.mimetypes : undefined, // mimetypes filter
        true, // modal
        pickerType,
        '/', // start path
        {
          allowDirectoryChooser: this.selectionType !== 'files'
        }
      )
    },
    clearSelection() {
      this.$emit('update:modelValue', '')
      this.$emit('input', '')
      this.fileInfo = null
    },
    openFile() {
      if (!this.modelValue) return

      // Open the file in Nextcloud Files app
      const filesUrl = generateUrl('/apps/files/?dir={dir}&openfile={file}', {
        dir: this.modelValue.substring(0, this.modelValue.lastIndexOf('/')),
        file: this.modelValue
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

.file-input-container {
  display: flex;
  align-items: center;
  gap: 8px;
}

.file-path-input {
  flex: 1;
}

.file-path-input :deep(.input-field__input) {
  background-color: var(--color-background-hover);
}

.file-info {
  margin-top: 4px;
}

.file-preview {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  background: var(--color-background-hover);
  border-radius: var(--border-radius-large);
  cursor: pointer;
  transition: background-color 0.2s ease;
}

.file-preview:hover {
  background: var(--color-primary-element-light);
}

.file-icon {
  color: var(--color-primary-element);
  flex-shrink: 0;
}

.file-details {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
}

.file-name {
  font-weight: 600;
  color: var(--color-main-text);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.file-path {
  font-size: 12px;
  color: var(--color-text-maxcontrast);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.open-icon {
  color: var(--color-text-maxcontrast);
  flex-shrink: 0;
}

.file-error {
  font-size: 12px;
  color: var(--color-error);
  margin: 0;
}
</style>
