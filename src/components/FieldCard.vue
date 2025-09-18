`<template>
  <div :class="['field-card', 'compact', { assigned: checked }]">
    <div class="field-card-content">
      <div class="field-checkbox-wrapper">
        <input 
          :id="'field-' + field.id"
          type="checkbox"
          :checked="checked"
          @change="$emit('update:checked', $event.target.checked)" />
        <label :for="'field-' + field.id" class="field-label">
          <div class="field-info">
            <div class="field-title">{{ field.field_label }}</div>
            <div class="field-details">
              <code class="field-name">{{ getDisplayName(field) }}</code>
              <span class="field-type">{{ field.field_type }}</span>
              <span v-if="isGroupfolderField" class="applies-to-badge groupfolder-badge">📁</span>
              <span v-else class="applies-to-badge files-badge">📄</span>
            </div>
          </div>
        </label>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'FieldCard',
  props: {
    field: {
      type: Object,
      required: true
    },
    checked: {
      type: Boolean,
      default: false
    }
  },
  emits: ['update:checked'],
  computed: {
    isGroupfolderField() {
      return this.field.applies_to_groupfolder === 1 || 
             this.field.applies_to_groupfolder === '1'
    }
  },
  methods: {
    getDisplayName(field) {
      let name = field.field_name
      // Remove prefixes for display
      if (name.startsWith('gf_')) {
        name = name.substring(3)
      } else if (name.startsWith('file_')) {
        name = name.substring(5)
      }
      return name
    }
  }
}
</script>

<style scoped>
.field-card {
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius);
  padding: 12px;
  transition: all 0.2s ease;
  cursor: pointer;
}

.field-card:hover {
  border-color: var(--color-border-dark);
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.field-card.assigned {
  border-color: var(--color-success);
  background: var(--color-success-light);
}

.field-card-content {
  display: flex;
  align-items: flex-start;
}

.field-checkbox-wrapper {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  width: 100%;
}

.field-checkbox-wrapper input[type="checkbox"] {
  margin: 2px 0 0 0;
  width: 16px;
  height: 16px;
  cursor: pointer;
}

.field-label {
  cursor: pointer;
  flex: 1;
  margin: 0 !important;
}

.field-info {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.field-title {
  font-weight: 500;
  font-size: 14px;
  color: var(--color-text);
  line-height: 1.3;
}

.field-details {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.field-name {
  background: var(--color-background-dark);
  padding: 2px 6px;
  border-radius: 3px;
  font-size: 11px;
  font-weight: 500;
  color: var(--color-text-lighter);
  font-family: monospace;
}

.field-type {
  font-size: 11px;
  color: var(--color-text-lighter);
  background: var(--color-background-hover);
  padding: 2px 6px;
  border-radius: 3px;
}

.applies-to-badge {
  display: inline-block;
  padding: 2px 6px;
  border-radius: 3px;
  font-size: 10px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.groupfolder-badge {
  background: var(--color-primary-light);
  color: var(--color-primary-text);
}

.files-badge {
  background: var(--color-warning-light);
  color: var(--color-warning-text);
}
</style>