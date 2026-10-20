<template>
  <!-- An account as Nextcloud shows one, with its avatar and status. -->
  <NcUserBubble v-if="account.kind === 'user'"
    :user="account.id"
    :display-name="account.label"
    :size="size" />
  <!-- A group or team: the same pill, with its icon. -->
  <span v-else
    class="account-bubble"
    :style="{ height: size + 'px' }">
    <span class="account-bubble__icon" :style="{ width: size + 'px', height: size + 'px' }">
      <AccountMultipleIcon v-if="account.kind === 'group'" :size="Math.round(size * 0.7)" />
      <AccountGroupIcon v-else :size="Math.round(size * 0.7)" />
    </span>
    <span class="account-bubble__name" :class="{ 'account-bubble__name--missing': !account.known && account.kind === 'team' }">{{ account.label }}</span>
  </span>
</template>

<script>
import NcUserBubble from '@nextcloud/vue/components/NcUserBubble'
import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue'
import AccountMultipleIcon from 'vue-material-design-icons/AccountMultiple.vue'

export default {
  name: 'AccountBubble',
  components: { NcUserBubble, AccountGroupIcon, AccountMultipleIcon },
  props: {
    // {id, kind, label, known}, from accountsOf()
    account: {
      type: Object,
      required: true
    },
    size: {
      type: Number,
      default: 20
    }
  }
}
</script>

<style scoped>
.account-bubble {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  max-width: 100%;
  padding-inline-end: 8px;
  border-radius: var(--border-radius-pill, 999px);
  background: var(--color-background-dark);
  vertical-align: middle;
}

.account-bubble__icon {
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  background: var(--color-background-darker);
  color: var(--color-text-maxcontrast);
}

.account-bubble__name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.account-bubble__name--missing {
  color: var(--color-text-maxcontrast);
  font-style: italic;
}
</style>
