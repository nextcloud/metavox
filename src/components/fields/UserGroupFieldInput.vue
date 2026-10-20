<template>
  <div class="user-field-wrapper">
    <!-- One chosen: shown as Nextcloud shows a person, with change and clear. -->
    <div v-if="!isMultiple && chosen.length > 0 && !isEditing" class="selected-user-display">
      <AccountBubble :account="chosen[0]" :size="28" />
      <!-- TRANSLATORS Accessible name of the pencil button that picks a different account, group or team for the field -->
      <NcButton v-if="!disabled"
        :aria-label="t('metavox', 'Change')"
        variant="tertiary"
        @click="isEditing = true">
        <template #icon>
          <PencilIcon :size="16" />
        </template>
      </NcButton>
      <!-- TRANSLATORS Accessible name of the button that removes the chosen account from the field -->
      <NcButton v-if="!disabled"
        :aria-label="t('metavox', 'Clear selection')"
        variant="tertiary"
        @click="clearSelection">
        <template #icon>
          <CloseIcon :size="16" />
        </template>
      </NcButton>
    </div>

    <!-- TRANSLATORS Accessible name of the search box of a People field when the field has no label of its own -->
    <NcSelect v-else
      :model-value="isMultiple ? chosenIds : (chosenIds[0] ?? null)"
      :aria-label-combobox="fieldLabel || t('metavox', 'Search people')"
      :input-id="inputId"
      label-outside
      :options="options"
      :disabled="disabled"
      :loading="loading"
      :filterable="false"
      :placeholder="placeholder || searchPlaceholder"
      :multiple="isMultiple"
      :close-on-select="!isMultiple"
      :reduce="option => option.id"
      label="label"
      @search="onSearch"
      @update:model-value="onSelect">
      <template #option="option">
        <div class="user-option">
          <NcAvatar v-if="option.kind === 'user'"
            :user="option.id"
            :display-name="option.label"
            :size="24" />
          <span v-else class="user-option__icon">
            <AccountMultipleIcon v-if="option.kind === 'group'" :size="18" />
            <AccountGroupIcon v-else :size="18" />
          </span>
          <span class="option-name">{{ option.label }}</span>
        </div>
      </template>
      <template #no-options="{ search }">
        {{ noOptionsText(search) }}
      </template>
    </NcSelect>
  </div>
</template>

<script>
import { translate } from '@nextcloud/l10n'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue'
import AccountMultipleIcon from 'vue-material-design-icons/AccountMultiple.vue'
import AccountBubble from './AccountBubble.vue'
import { accountItems, accountsOf, onAccountNames, rememberAccountNames } from '../../utils/accountNames.js'
import { accountOptions } from '../../utils/fieldOptions.js'

/**
 * The picker of a People field: accounts, groups and teams, one or several,
 * as the field takes them. The search is Nextcloud's sharee search, so it
 * finds whom you could share with. The value is their ids, joined with ;#.
 */
export default {
  name: 'UserGroupFieldInput',
  components: {
    NcSelect,
    NcAvatar,
    NcButton,
    PencilIcon,
    CloseIcon,
    AccountGroupIcon,
    AccountMultipleIcon,
    AccountBubble
  },
  props: {
    modelValue: {
      type: [String, Array],
      default: ''
    },
    // With field_options, and the names the server sent with its value (accounts).
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
    // The visible label lives in the parent form, so the combobox needs the
    // same text as its own accessible name.
    fieldLabel: {
      type: String,
      default: ''
    },
    placeholder: {
      type: String,
      default: ''
    },
    // Several, also when the field takes one (a search filter).
    multiple: {
      type: Boolean,
      default: false
    }
  },
  emits: ['update:modelValue', 'input'],
  data() {
    return {
      loading: false,
      results: [],
      isEditing: false,
      searchTimer: null,
      // Bumped when names come in, so the labels are read again.
      namesSeen: 0
    }
  },
  computed: {
    takes() {
      return accountOptions(this.field?.field_options)
    },
    isMultiple() {
      // A value with several from before the field took one shows them all, to take some out.
      return this.multiple || this.takes.multiple || this.chosenIds.length > 1
    },
    chosenIds() {
      return Array.isArray(this.modelValue) ? this.modelValue.map(String) : accountItems(this.modelValue)
    },
    chosen() {
      void this.namesSeen
      return accountsOf(this.chosenIds.join(';#'))
    },
    // The chosen ones first: the select shows a choice by its option.
    options() {
      const ids = new Set(this.chosenIds)
      return [
        ...this.chosen.map((person) => ({ id: person.id, kind: person.kind, label: person.label })),
        ...this.results.filter((option) => !ids.has(option.id)),
      ]
    },
    searchPlaceholder() {
      const kinds = this.takes.kinds
      if (kinds.length === 1 && kinds[0] === 'user') {
        // TRANSLATORS Placeholder of the search box of a People field that takes accounts only
        return this.t('metavox', 'Type to search accounts …')
      }
      // TRANSLATORS Placeholder of the search box of a People field that also takes groups or teams
      return this.t('metavox', 'Type to search people and groups …')
    }
  },
  watch: {
    'field.accounts': {
      immediate: true,
      handler(accounts) {
        if (Array.isArray(accounts)) rememberAccountNames(accounts)
      }
    }
  },
  mounted() {
    this.stopNames = onAccountNames(() => { this.namesSeen++ })
  },
  beforeUnmount() {
    this.stopNames?.()
    clearTimeout(this.searchTimer)
  },
  methods: {
    t: translate,
    noOptionsText(search) {
      if (search.length < 2) {
        // TRANSLATORS Shown in the account search list until at least 2 characters are typed
        return this.t('metavox', 'Type at least 2 characters …')
      }
      // TRANSLATORS Shown in the search list of a People field when nothing matches the search
      return this.t('metavox', 'No one found')
    },
    onSearch(query) {
      clearTimeout(this.searchTimer)
      if (!query || query.length < 2) {
        this.results = []
        return
      }
      this.searchTimer = setTimeout(async () => {
        this.loading = true
        try {
          const response = await axios.get(generateUrl('/apps/metavox/api/accounts'), {
            params: { search: query, kinds: this.takes.kinds.join(',') }
          })
          const found = Array.isArray(response.data) ? response.data : []
          rememberAccountNames(found)
          this.results = found.map((person) => ({ id: person.id, kind: person.kind, label: person.name || person.id }))
        } catch (error) {
          this.results = []
        } finally {
          this.loading = false
        }
      }, 300)
    },
    onSelect(value) {
      const ids = Array.isArray(value) ? value : (value ? [value] : [])
      const joined = ids.join(';#')
      this.$emit('update:modelValue', joined)
      this.$emit('input', joined)
      if (!this.isMultiple) {
        this.isEditing = false
      }
    },
    clearSelection() {
      this.$emit('update:modelValue', '')
      this.$emit('input', '')
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

.user-option__icon {
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: var(--color-background-darker);
  color: var(--color-text-maxcontrast);
}

.option-name {
  font-weight: 500;
  color: var(--color-main-text);
}
</style>
