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
      :filterable="false"
      :placeholder="placeholder || t('metavox', 'Type to search users...')"
      :multiple="multiple"
      :reduce="option => option.id"
      label="displayname"
      @search="onSearch"
      @update:model-value="onSelect">
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
      <template #no-options="{ search }">
        {{ search.length < 2 ? t('metavox', 'Type at least 2 characters...') : t('metavox', 'No users found') }}
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
      internalValue: this.multiple ? [] : null,
      isEditing: false,
      searchTimer: null,
      currentUserLoaded: false
    }
  },
  computed: {
    selectedUser() {
      if (!this.modelValue || this.multiple) return null
      return this.users.find(u => u.id === this.modelValue) || {
        id: this.modelValue,
        displayname: this.modelValue
      }
    }
  },
  watch: {
    modelValue: {
      immediate: true,
      handler(newVal) {
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
        // Load current user's display name if not yet loaded
        if (newVal && !this.multiple && !this.currentUserLoaded) {
          this.loadCurrentUser(newVal)
        }
      }
    }
  },
  methods: {
    t(app, text) {
      return window.t ? window.t(app, text) : text
    },
    async loadCurrentUser(userId) {
      try {
        const response = await axios.get(generateUrl('/apps/metavox/api/users'), {
          params: { search: userId }
        })
        if (Array.isArray(response.data)) {
          const found = response.data.find(u => u.id === userId)
          if (found) {
            const user = { id: found.id, userId: found.id, displayname: found.displayname || found.id }
            // Add to users list if not already present
            if (!this.users.find(u => u.id === userId)) {
              this.users.push(user)
            }
            this.currentUserLoaded = true
          }
        }
      } catch (e) {
        // Fallback: show user ID
      }
    },
    onSearch(query, loading) {
      clearTimeout(this.searchTimer)

      if (!query || query.length < 2) {
        this.users = this.modelValue && !this.multiple
          ? this.users.filter(u => u.id === this.modelValue)
          : []
        return
      }

      this.searchTimer = setTimeout(async () => {
        loading(true)
        try {
          const response = await axios.get(generateUrl('/apps/metavox/api/users'), {
            params: { search: query }
          })
          if (Array.isArray(response.data)) {
            this.users = response.data.map(user => ({
              id: user.id,
              userId: user.id,
              displayname: user.displayname || user.id
            }))
          }
        } catch (error) {
          this.users = []
        } finally {
          loading(false)
        }
      }, 300)
    },
    onSelect(value) {
      if (this.multiple) {
        const joinedValue = Array.isArray(value) ? value.join(';#') : ''
        this.$emit('update:modelValue', joinedValue)
        this.$emit('input', joinedValue)
      } else {
        this.$emit('update:modelValue', value || '')
        this.$emit('input', value || '')
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
