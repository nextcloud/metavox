<template>
  <fieldset class="people-options">
    <!-- TRANSLATORS Heading above the checkboxes that say what a field of the type "People and groups" can hold -->
    <legend class="field-label">{{ t('metavox', 'Can hold') }}</legend>
    <NcCheckboxRadioSwitch v-for="kind in kinds"
      :key="kind.id"
      type="checkbox"
      :model-value="modelValue.kinds.includes(kind.id)"
      :disabled="modelValue.kinds.length === 1 && modelValue.kinds.includes(kind.id)"
      @update:model-value="toggleKind(kind.id, $event)">
      {{ kind.label }}
    </NcCheckboxRadioSwitch>
    <NcCheckboxRadioSwitch type="checkbox"
      class="people-options__multiple"
      :model-value="modelValue.multiple"
      @update:model-value="update({ multiple: $event })">
      <!-- TRANSLATORS Checkbox label for a field of the type "People and groups": more than one account, group or team can be chosen -->
      {{ t('metavox', 'Allow more than one') }}
    </NcCheckboxRadioSwitch>
    <p v-if="wasMultiple && !modelValue.multiple" class="helper-text">
      <!-- TRANSLATORS Help when "Allow more than one" is turned off for a field that is in use -->
      {{ t('metavox', 'Files that hold more than one keep them until someone edits the value.') }}
    </p>
  </fieldset>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { translate } from '@nextcloud/l10n'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { ACCOUNT_KINDS } from '../../utils/fieldOptions.js'

/** What a People field takes: accounts, groups, teams, one or several. */
export default {
  name: 'PeopleFieldOptions',
  components: { NcCheckboxRadioSwitch },
  props: {
    // {multiple, kinds}, as accountOptions() reads it
    modelValue: {
      type: Object,
      required: true
    },
    // The field took several before this edit.
    wasMultiple: {
      type: Boolean,
      default: false
    }
  },
  emits: ['update:modelValue'],
  computed: {
    kinds() {
      const labels = {
        // TRANSLATORS Checkbox under "Can hold" for a field of the type "People and groups"
        user: this.t('metavox', 'Accounts'),
        // TRANSLATORS Checkbox under "Can hold" for a field of the type "People and groups"
        group: this.t('metavox', 'Groups'),
        // TRANSLATORS Checkbox under "Can hold" for a field of the type "People and groups"; Teams is the Nextcloud app, use its official translation
        team: this.t('metavox', 'Teams'),
      }
      // Teams only with the Teams app; a field that has them keeps the box, to take them off.
      const teams = loadState('metavox', 'teams-available', true) || this.modelValue.kinds.includes('team')
      return ACCOUNT_KINDS.filter((id) => id !== 'team' || teams).map((id) => ({ id, label: labels[id] }))
    }
  },
  methods: {
    t: translate,
    toggleKind(kind, on) {
      const kinds = ACCOUNT_KINDS.filter((id) => id === kind ? on : this.modelValue.kinds.includes(id))
      if (kinds.length > 0) this.update({ kinds })
    },
    update(change) {
      this.$emit('update:modelValue', { ...this.modelValue, ...change })
    }
  }
}
</script>

<style scoped>
.people-options {
  display: flex;
  flex-direction: column;
  border: 0;
  margin: 0;
  padding: 0;
}

/* How many is its own choice, not a fourth kind. */
.people-options__multiple {
  margin-top: calc(2 * var(--default-grid-baseline));
}
</style>
