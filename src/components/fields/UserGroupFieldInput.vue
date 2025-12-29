<template>
  <div class="user-field-wrapper">
    <!-- Show user bubble when value is selected -->
    <div v-if="modelValue && selectedUser && !isEditing" class="selected-user-display">
      <NcUserBubble
        :user="selectedUser.id"
        :display-name="selectedUser.displayname"
        :size="28"
        :show-user-status="true">
        {{ selectedUser.displayname }}
      </NcUserBubble>
      <NcButton
        v-if="!disabled"
        type="tertiary"
        :aria-label="t('metavox', 'Change user')"
        @click="startEditing">
        <template #icon>
          <PencilIcon :size="16" />
        </template>
      </NcButton>
      <NcButton
        v-if="!disabled"
        type="tertiary"
        :aria-label="t('metavox', 'Clear selection')"
        @click="clearSelection">
        <template #icon>
          <CloseIcon :size="16" />
        </template>
      </NcButton>
    </div>

    <!-- Show select when no value or editing -->
    <NcSelect
      v-else
      :id="inputId"
      v-model="internalValue"
      :options="users"
      :disabled="disabled"
      :loading="loading"
      :placeholder="placeholder || t('metavox', 'Select user...')"
      :multiple="multiple"
      :reduce="option => option.id"
      label="displayname"
      @search-change="onSearch"
      @input="onSelect">
      <template #option="{ option }">
        <div v-if="option" class="user-option">
          <NcAvatar
            :user="option.userId || option.id"
            :display-name="option.displayname"
            :size="24" />
          <div class="option-info">
            <span class="option-name">{{ option.displayname }}</span>
          </div>
        </div>
      </template>
      <template #noResult>
        {{ t('metavox', 'No users found') }}
      </template>
    </NcSelect>
  </div>
</template>

<script>
import { NcSelect, NcAvatar, NcUserBubble, NcButton } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'

export default {
  name: 'UserGroupFieldInput',
  components: {
    NcSelect,
    NcAvatar,
    NcUserBubble,
    NcButton,
    PencilIcon,
    CloseIcon
  },
  props: {
    modelValue: {
      type: [String, Array],
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
    multiple: {
      type: Boolean,
      default: false
    }
  },
  emits: ['update:modelValue', 'input'],
  data() {
    return {
      loading: false,
      users: [],
      allUsers: [],
      internalValue: this.multiple ? [] : null,
      isEditing: false
    }
  },
  computed: {
    selectedUser() {
      if (!this.modelValue || this.multiple) return null
      return this.allUsers.find(u => u.id === this.modelValue) || {
        id: this.modelValue,
        displayname: this.modelValue
      }
    }
  },
  watch: {
    modelValue: {
      immediate: true,
      handler(newVal) {
        // Sync internal value when prop changes
        if (this.multiple) {
          if (!newVal) {
            this.internalValue = []
          } else if (typeof newVal === 'string') {
            this.internalValue = newVal.split(';#').filter(v => v)
          } else {
            this.internalValue = newVal
          }
        } else {
          this.internalValue = newVal || null
        }
      }
    },
    allUsers: {
      handler() {
        // When users are loaded, ensure options include current value
        if (this.modelValue && !this.multiple) {
          const exists = this.allUsers.find(u => u.id === this.modelValue)
          if (!exists && this.modelValue) {
            // Add a placeholder user so it displays
            this.allUsers.push({
              id: this.modelValue,
              displayname: this.modelValue,
              userId: this.modelValue
            })
            this.users = [...this.allUsers]
          }
        }
      }
    }
  },
  mounted() {
    this.loadUsers()
  },
  methods: {
    t(app, text) {
      return window.t ? window.t(app, text) : text
    },
    async loadUsers() {
      this.loading = true
      try {
        const response = await axios.get(generateUrl('/apps/metavox/api/users'))

        if (Array.isArray(response.data)) {
          this.allUsers = response.data.map(user => ({
            id: user.id,
            userId: user.id,
            displayname: user.displayname || user.id
          }))
          this.users = [...this.allUsers]
        }
      } catch (error) {
        console.error('Failed to load users:', error)
        this.users = []
        this.allUsers = []
      } finally {
        this.loading = false
      }
    },
    onSearch(query) {
      if (!query) {
        this.users = [...this.allUsers]
        return
      }

      const lowerQuery = query.toLowerCase()
      this.users = this.allUsers.filter(user =>
        user.displayname.toLowerCase().includes(lowerQuery) ||
        user.id.toLowerCase().includes(lowerQuery)
      )
    },
    onSelect(value) {
      // With :reduce, value is already the id (string) or array of ids
      console.log('UserGroupFieldInput onSelect called with:', value)
      if (this.multiple) {
        const joinedValue = Array.isArray(value) ? value.join(';#') : ''
        console.log('UserGroupFieldInput emitting (multiple):', joinedValue)
        this.$emit('update:modelValue', joinedValue)
        this.$emit('input', joinedValue)
      } else {
        // value is already the user id string
        console.log('UserGroupFieldInput emitting (single):', value)
        this.$emit('update:modelValue', value || '')
        this.$emit('input', value || '')
        // Stop editing after selection
        this.isEditing = false
      }
    },
    startEditing() {
      this.isEditing = true
    },
    clearSelection() {
      this.$emit('update:modelValue', '')
      this.$emit('input', '')
      this.internalValue = null
      this.isEditing = false
    }
  }
}
</script>

<style scoped>
.user-field-wrapper {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.selected-user-display {
  display: flex;
  align-items: center;
  gap: 8px;
}

.selected-user-display :deep(.user-bubble__wrapper) {
  background: var(--color-background-hover);
  border-radius: var(--border-radius-large);
  padding: 4px 8px;
}

.user-option {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 4px 0;
}

.option-info {
  display: flex;
  flex-direction: column;
}

.option-name {
  font-weight: 500;
  color: var(--color-main-text);
}
</style>
