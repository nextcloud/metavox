<template>
  <div class="metadata-fields">
    <!-- Loading state -->
    <div v-if="loading" class="loading-container">
      <NcLoadingIcon :size="44" />
      <p>{{ t('metavox', 'Loading metadata fields …') }}</p>
    </div>

    <div v-else>
      <!-- The hidden input the import action drives. It has no visible
           control of its own, so it carries no label; the menu item names
           the action. -->
      <!-- Clipped to 1px, but that does not take it out of the tab order:
           measured tabIndex 0 and it accepts focus, so a keyboard user
           lands on an invisible control. tabindex="-1" keeps it reachable
           for the Import button that clicks it, and off the tab path; the
           aria-label names it for anyone who reaches it anyway (§8). -->
      <!-- TRANSLATORS Accessible name of the hidden file chooser used to import fields; JSON is a file format -->
      <input :aria-label="t('metavox', 'Choose a JSON file to import')"
        ref="jsonFileInput"
        type="file"
        accept=".json"
        class="file-input"
        tabindex="-1"
        :disabled="importing"
        @change="handleFileSelect">

      <!-- Existing Fields List -->
      <div class="mv-block">
        <div class="section-header">
          <!-- The count belongs to the heading, so it travels with it. Loose
               in a space-between row it was a third flex child: with actions
               beside it, it drifted to the middle of the row (measured 435px
               from the title); without them it sat hard right. Same markup,
               two different places, depending on a neighbour it has nothing
               to do with. Grouped, it is always immediately after the title
               in every pane. -->
          <div class="section-header__title">
            <!-- TRANSLATORS Heading of the list of metadata fields -->
            <h3>{{ t('metavox', 'Existing metadata fields') }}</h3>

            <!-- While a selection exists the count is replaced by what will
                 happen to it, the way the Files list swaps its header for
                 "{count} selected". Both states occupy the same slot so the
                 row does not jump. -->
            <span v-if="!selected.length" class="field-count">{{ filteredFieldsCount }}</span>
            <span v-else class="field-count field-count--selected">
              <!-- TRANSLATORS Shown beside the list heading while fields are selected; %n is the number of selected fields -->
              {{ n('metavox', '%n field selected', '%n fields selected', selected.length) }}
            </span>
          </div>

          <!-- Import and export used to be the first ~400px of this tab, above
               the list an administrator came here to read. They are rare work
               — a migration, once — and they belong in a menu beside the
               heading rather than in chrome of their own (§6: collapse beyond
               three actions).

               They stay on this tab rather than moving to one of their own,
               because both are already scoped: export takes the fields this
               tab shows and strips the scope prefix, import forces
               applies_to_groupfolder to this scope. A separate tab would have
               to ask which set the file belongs to — something the reader
               already said by standing here (WCAG 2.2 SC 3.3.7). -->
          <!-- The create action is a button, the rare ones are behind the
               menu. Core never hides a create action in an overflow: the
               app-password form puts "Create new app password" as a primary
               button (AuthTokenSetup.vue:19-24) and systemtags does the same
               (SystemTagForm.vue:271-276). The view picker in the Files app
               keeps "New view" inside its menu because that menu IS the view
               switcher — this one is an overflow, which is a different thing.

               Import and export stay here rather than on a tab of their own:
               both are already scoped to the fields this tab shows, so a
               separate tab would have to ask which set a file belongs to —
               something the reader said by standing here (WCAG 2.2 SC
               3.3.7). -->
          <div class="section-header__actions">
            <!-- Only while something is selected, and it is the destructive
                 variant because it removes data that cannot be recovered
                 (§3: error is reserved for bulk or irreversible work — this
                 is both). -->
            <NcButton v-if="selected.length"
              variant="error"
              :disabled="deletingBulk"
              @click="confirmBulkDelete">
              <template #icon>
                <NcLoadingIcon v-if="deletingBulk" :size="20" />
                <DeleteIcon v-else :size="20" />
              </template>
              <!-- TRANSLATORS Button that deletes the selected fields -->
              {{ t('metavox', 'Delete selected') }}
            </NcButton>

            <NcButton v-if="canSelect && filteredSelectableIds.length"
              variant="tertiary"
              @click="toggleSelectAll">
              {{ selectAllLabel }}
            </NcButton>

            <NcButton variant="primary" @click="openAddForm">
              <template #icon>
                <PlusIcon :size="20" />
              </template>
              <!-- TRANSLATORS Button that opens the dialog to create a metadata field; keep it short -->
              {{ t('metavox', 'New field') }}
            </NcButton>

            <!-- TRANSLATORS Accessible name of the menu button with the import and export actions -->
            <NcActions :aria-label="t('metavox', 'Import and export')">
              <NcActionButton :disabled="importing" @click="triggerFileInput">
                <template #icon>
                  <UploadIcon :size="20" />
                </template>
                <!-- TRANSLATORS Menu action that imports fields from a JSON file; JSON is a file format -->
                {{ t('metavox', 'Import from JSON …') }}
              </NcActionButton>
              <NcActionButton :disabled="exporting || fields.length === 0" @click="exportFields">
                <template #icon>
                  <ExportIcon :size="20" />
                </template>
                <!-- TRANSLATORS Menu action that downloads the fields as a JSON file; JSON is a file format -->
                {{ t('metavox', 'Export to JSON') }}
              </NcActionButton>
            </NcActions>
          </div>
        </div>
        
        <!-- Search Field -->
        <div class="search-container">
          <!-- TRANSLATORS Label of the search box that filters the field list -->
          <NcTextField :label="t('metavox', 'Search fields')"
            :model-value="searchQuery"
            @update:model-value="searchQuery = $event"
            :placeholder="searchPlaceholder"
            :show-trailing-button="!!searchQuery"
            trailing-button-icon="close"
            @trailing-button-click="searchQuery = ''">
            <template #icon>
              <MagnifyIcon :size="20" />
            </template>
          </NcTextField>
        </div>
        
        <!-- The "Found N fields matching …" banner is gone: the count beside
             the heading already says it, from the same filteredFields, so the
             two could never disagree — they were the same sentence 34px
             apart. Removed from the folder list and the permissions list for
             the same reason; this was the third copy. -->

        <!-- Empty Search Results -->
        <div v-if="searchQuery && filteredFields.length === 0" class="empty-search">
          <!-- TRANSLATORS {query} is the text typed in the search box -->
          <p>{{ t('metavox', 'No fields found matching "{query}"', { query: searchQuery }, undefined, PLAIN_TEXT) }}</p>
          <NcButton @click="searchQuery = ''" variant="secondary">
            <!-- TRANSLATORS Button that empties the search box -->
            {{ t('metavox', 'Clear search') }}
          </NcButton>
        </div>

        <!-- Empty State -->
        <div v-if="fields.length === 0 && !searchQuery" class="empty-content">
          <component :is="scopeIcon" :size="64" class="empty-icon" />
          <h4>{{ t('metavox', 'No metadata fields configured') }}</h4>
          <p>{{ emptyHint }}</p>
        </div>

        <!-- Fields List -->
        <ul v-else-if="(!searchQuery && fields.length > 0) || (searchQuery && filteredFields.length > 0)" class="fields-list">
          <!-- A field is a row, not a card. The three bold "Label: value" lines
               this replaced cost ~148px each and are also a translation
               problem (§13 forbids building a sentence from fragments); the
               subline says the same things in reading order, and the full
               description is in the edit dialog where it can be changed. -->
          <NcListItem
            v-for="field in filteredFields"
            :key="field.id"
            :name="getDisplayName(field)"
            :force-display-actions="true"
            @click="editField(field, $event)">
            <template #icon>
              <!-- The checkbox takes the icon's place, the way Files does it.
                   It only appears once the usage counts have landed: before
                   that the app does not know which fields can go, and a
                   checkbox that might be refused is the guessing this was
                   meant to remove. -->
              <!-- The wrapper exists so editField() can recognise a click
                   that belongs to the selection control.

                   Not @click.stop: NcCheckboxRadioSwitch renders a <label>
                   around an opacity-0 input, and stopping the click on the
                   wrapper also stops the label forwarding it to that input
                   — measured, the dialog stayed shut but nothing selected
                   either. The row handler checks the origin instead, which
                   leaves the label's own behaviour intact. -->
              <span v-if="canSelect" class="field-select">
                <NcCheckboxRadioSwitch
                  :model-value="selected.includes(field.id)"
                  :disabled="!canDeleteField(field)"
                  :aria-label="selectionLabel(field)"
                  :title="canDeleteField(field) ? undefined : selectionLabel(field)"
                  type="checkbox"
                  @update:model-value="toggleSelected(field.id, $event)" />
              </span>
              <component v-else
                :is="getFieldIcon(field.field_type || field.type)"
                :size="20" />
            </template>

            <template #subname>
              {{ fieldSubline(field) }}
            </template>

            <template #indicator>
              <!-- TRANSLATORS Badge on a field that must be filled in; keep it short -->
              <NcChip :text="t('metavox', 'Required')"
                v-if="showsRequired(field)"
                :aria-label="t('metavox', 'Required')"
                no-close />
            </template>

            <template #actions>
              <NcActionButton @click="editField(field)">
                <template #icon>
                  <EditIcon :size="20" />
                </template>
                <!-- TRANSLATORS Menu action that opens the dialog to edit a field; keep it short -->
                {{ t('metavox', 'Edit field') }}
              </NcActionButton>

              <NcActionButton
                :disabled="!canDeleteField(field) || deleting === field.id"
                @click="deleteField(field.id)">
                <template #icon>
                  <NcLoadingIcon v-if="deleting === field.id" :size="20" />
                  <DeleteIcon v-else :size="20" />
                </template>
                {{ deleteFieldLabel(field) }}
              </NcActionButton>
            </template>
          </NcListItem>
        </ul>
      </div>
    </div>

    <!-- Adding a field is the same form as editing one, so it is the same
         kind of surface: a dialog, not a grey card that grows inside the
         page (§3). As a panel it pushed the field list down, its Save row
         had no fixed place, and two different labels named the same things
         as the edit dialog beside it. -->
    <!-- TRANSLATORS Title of the dialog that creates a metadata field -->
    <NcDialog v-if="showAddForm" :name="t('metavox', 'Add field')"
      :no-close="saving"
      :close-on-click-outside="!saving"
      :buttons="addDialogButtons"
      size="normal"
      @closing="cancelAdd">

      <!-- Field Name (Internal) -->
      <div class="form-row">
        <!-- TRANSLATORS Label of the input for the internal name of a field -->
        <NcTextField :label="t('metavox', 'Field name')"
          :model-value="formData.name"
          @update:model-value="formData.name = $event"
          :placeholder="namePlaceholder"
          :helper-text="fieldNameError || serverErrors.field_name || fieldNameHelp"
          :error="!!(fieldNameError || serverErrors.field_name)"
          :show-trailing-button="!!formData.name"
          trailing-button-icon="check"
          required />
      </div>

      <!-- Field Label (Display Name) -->
      <div class="form-row">
        <!-- TRANSLATORS Label of the input for the name of a field that people see -->
        <NcTextField :label="t('metavox', 'Field label')"
          :model-value="formData.label"
          @update:model-value="formData.label = $event"
          :placeholder="labelPlaceholder"
          :helper-text="fieldLabelError || serverErrors.field_label || t('metavox', 'Display name shown to people')"
          :error="!!(fieldLabelError || serverErrors.field_label)"
          :show-trailing-button="!!formData.label"
          trailing-button-icon="check"
          required />
      </div>

      <!-- Field Type -->
      <div class="form-row">
        <!-- TRANSLATORS Label of the picker that sets the type of a field (text, number, date …) -->
        <label class="field-label">{{ t('metavox', 'Field type') }}</label>
        <NcSelect
          :model-value="selectedFieldType"
          :options="selectOptions"
          label="label"
          track-by="id"
          :searchable="false"
          :clearable="false"
          @update:model-value="onFieldTypeChange" />
      </div>

      <!-- Description -->
      <div class="form-row">
        <!-- TRANSLATORS Noun: label of the input for the description of a field -->
        <NcTextArea :label="t('metavox', 'Description')"
          :model-value="formData.description"
          @update:model-value="formData.description = $event"
          :placeholder="t('metavox', 'Optional description for this field')"
          :helper-text="t('metavox', 'Shown to people beside the field')"
          :rows="2" />
      </div>

      <!-- Required Field Checkbox; a checkbox always has an answer, so it is never required -->
      <div v-if="canBeRequired(formData.type)" class="form-row">
        <NcCheckboxRadioSwitch
          :model-value="formData.required"
          @update:model-value="formData.required = $event"
          type="checkbox">
          <!-- TRANSLATORS Checkbox label: the field must be filled in -->
          {{ t('metavox', 'Required field') }}
        </NcCheckboxRadioSwitch>
      </div>

      <!-- Include time component (date fields only) -->
      <div v-if="formData.type === 'date'" class="form-row">
        <NcCheckboxRadioSwitch
          :model-value="formData.includeTime"
          @update:model-value="formData.includeTime = $event"
          type="checkbox">
          <!-- TRANSLATORS Checkbox label for a date field: also store the time of day -->
          {{ t('metavox', 'Include time component') }}
        </NcCheckboxRadioSwitch>
        <p class="helper-text">
          {{ t('metavox', 'When enabled, this field stores both date and time (e.g. meetings, deadlines).') }}
        </p>
      </div>

      <!-- Options for select/multiselect fields -->
      <div v-if="showOptionsField" class="form-row">
        <!-- TRANSLATORS Heading above the list of choices of a dropdown or multi-select field -->
        <label class="field-label">{{ t('metavox', 'Dropdown options') }}</label>
        <div class="options-container">
          <div v-for="(option, index) in formData.options" :key="`option-add-${index}`" class="option-row">
            <NcTextField
              :model-value="option.value"
              @update:model-value="option.value = $event"
              :placeholder="t('metavox', 'Option value')" /> <!-- TRANSLATORS Placeholder of the input for one choice of a dropdown field -->
            <NcButton
              variant="tertiary"
              @click="removeOption(index)"
              :aria-label="t('metavox', 'Remove option')"> <!-- TRANSLATORS Accessible name of the button that removes one choice from a dropdown field -->
              <template #icon>
                <CloseIcon :size="20" />
              </template>
            </NcButton>
          </div>
          <NcButton 
            @click="addOption" 
            variant="secondary">
            <template #icon>
              <PlusIcon :size="20" />
            </template>
            <!-- TRANSLATORS Button that adds a choice to a dropdown field -->
            {{ t('metavox', 'Add option') }}
          </NcButton>
          <p v-if="fieldOptionsError || serverErrors.field_options" class="mv-field-error" role="status">
            {{ fieldOptionsError || serverErrors.field_options }}
          </p>
        </div>
      </div>
    </NcDialog>

    <!-- The title names the dialog, not the field it happens to hold: the
         field's own name is the first control inside it, where it can be
         edited rather than repeated uneditably in the header. -->
    <!-- TRANSLATORS Title of the dialog that edits a metadata field -->
    <NcDialog :name="t('metavox', 'Edit field')"
      v-if="editingField"
      :no-close="saving"
      :close-on-click-outside="!saving"
      :buttons="editDialogButtons"
      size="normal"
      @closing="cancelEdit">

      <div class="modal-content">
        <div class="modal-form">
          <!-- Field Name (Internal) -->
          <!-- Once the field is in use the name is fixed, so it stops being a
               control and becomes a label/value pair (§6). As a readonly
               NcTextField it kept a white background, a dark border and a
               text cursor: it looked completely editable and said nothing
               until you tried to type. `disabled` is not the answer either —
               core paints that as --color-text-maxcontrast *and* opacity 0.7,
               measured 2.91:1, under the 4.5 floor. Text is legible (15.91:1),
               still selectable, and honest about not being a field. -->
          <div v-if="getUsageCount(editingField) > 0" class="form-row form-row--static">
            <!-- TRANSLATORS Label of the internal name of a field, the identifier stored in the data -->
            <span class="form-row__label">{{ t('metavox', 'Internal field name') }}</span>
            <p class="form-row__value">{{ editData.name }}</p>
            <p class="field-help-text">
              {{ t('metavox', 'Fixed while the field is in use') }}
            </p>
          </div>

          <div v-else class="form-row">
            <!-- TRANSLATORS Label of the internal name of a field, the identifier stored in the data -->
            <NcTextField :label="t('metavox', 'Internal field name')"
              :model-value="editData.name"
              @update:model-value="editData.name = $event"
              :placeholder="namePlaceholder"
              :helper-text="serverErrors.field_name || t('metavox', 'Internal identifier used in the system')"
              :error="!!serverErrors.field_name"
              required />
          </div>

          <!-- Field Label (Display Name) -->
          <div class="form-row">
            <!-- TRANSLATORS Label of the input for the name of a field that people see -->
            <NcTextField :label="t('metavox', 'Display name')"
              :model-value="editData.label"
              @update:model-value="editData.label = $event"
              :placeholder="labelPlaceholder"
              :helper-text="serverErrors.field_label || t('metavox', 'Name shown to people')"
              :error="!!serverErrors.field_label"
              required />
          </div>

          <!-- Description -->
          <div class="form-row">
            <!-- TRANSLATORS Noun: label of the input for the description of a field -->
            <NcTextArea :label="t('metavox', 'Description')"
              :model-value="editData.description"
              @update:model-value="editData.description = $event"
              :helper-text="t('metavox', 'Shown to people beside the field')"
              :rows="3" />
          </div>

          <!-- Required Field; a checkbox always has an answer, so it is never required -->
          <div v-if="canBeRequired(editingField.field_type || editingField.type)" class="form-row">
            <NcCheckboxRadioSwitch
              :model-value="editData.required"
              @update:model-value="editData.required = $event"
              type="checkbox">
              <!-- TRANSLATORS Checkbox label: the field must be filled in -->
              {{ t('metavox', 'Required field') }}
            </NcCheckboxRadioSwitch>

            <!-- A field in use may become required: say what that does to the files it is on. -->
            <p v-if="getUsageCount(editingField) > 0 && editData.required && !(editingField.is_required || editingField.required)" class="field-help-text">
              <!-- TRANSLATORS Help under the "Required field" checkbox when a field that is already in use is made required -->
              {{ t('metavox', 'Files without a value are marked in the grid, and get one at their next save.') }}
            </p>
          </div>

          <!-- Include time component (date fields only) -->
          <div v-if="(editingField.field_type || editingField.type) === 'date'" class="form-row">
            <NcCheckboxRadioSwitch
              :model-value="editData.includeTime"
              @update:model-value="editData.includeTime = $event"
              type="checkbox">
              <!-- TRANSLATORS Checkbox label for a date field: also store the time of day -->
              {{ t('metavox', 'Include time component') }}
            </NcCheckboxRadioSwitch>
            <p class="helper-text">
              {{ t('metavox', 'When enabled, this field stores both date and time (e.g. meetings, deadlines).') }}
            </p>
          </div>

          <!-- Edit Options for select/multiselect fields -->
          <div v-if="(editingField.field_type || editingField.type) === 'select' || (editingField.field_type || editingField.type) === 'multiselect'" class="form-row">
            <!-- TRANSLATORS Heading above the list of choices of a dropdown or multi-select field -->
            <label class="field-label">{{ t('metavox', 'Dropdown options') }}</label>
            <div class="options-container">
              <div v-for="(option, index) in editData.options" :key="`option-edit-${index}`" class="option-row">
                <NcTextField
                  :model-value="option.value"
                  @update:model-value="option.value = $event"
                  :placeholder="t('metavox', 'Option value')" /> <!-- TRANSLATORS Placeholder of the input for one choice of a dropdown field -->
                <NcButton
                  variant="tertiary"
                  @click="removeEditOption(index)"
                  :aria-label="t('metavox', 'Remove option')"> <!-- TRANSLATORS Accessible name of the button that removes one choice from a dropdown field -->
                  <template #icon>
                    <CloseIcon :size="20" />
                  </template>
                </NcButton>
              </div>
              <NcButton 
                @click="addEditOption" 
                variant="secondary">
                <template #icon>
                  <PlusIcon :size="20" />
                </template>
                <!-- TRANSLATORS Button that adds a choice to a dropdown field -->
                {{ t('metavox', 'Add option') }}
              </NcButton>
            </div>
            <p v-if="serverErrors.field_options" class="mv-field-error" role="status">
              {{ serverErrors.field_options }}
            </p>
          </div>
          
          <!-- Was a hand-built .warning-box: `background: var(--color-warning)`
               with `strong { color: var(--color-warning) }`, so the word
               "Warning:" was painted in its own background colour and
               measured 1.00:1 — invisible. --color-warning is a background
               token, not a text colour (§5). NcNoteCard carries the icon, the
               accent border and a text colour that contrasts, and it needs no
               literal "Warning:" because the type says it. -->
          <NcNoteCard v-if="getUsageCount(editingField) > 0" type="warning">
            <!-- TRANSLATORS Warning in the edit dialog; %n is the number of Team folders that use the field -->
            {{ n('metavox', 'This field is used in %n Team folder. Some changes may be restricted.', 'This field is used in %n Team folders. Some changes may be restricted.', getUsageCount(editingField)) }}
          </NcNoteCard>
        </div>
        
      </div>
    </NcDialog>

    <!-- TRANSLATORS Title of the dialog that lists the fields of a JSON file before they are imported -->
    <NcDialog :name="t('metavox', 'Import preview')"
      v-if="importPreviewData"
      :no-close="importing"
      :close-on-click-outside="!importing"
      :buttons="importDialogButtons"
      size="normal"
      @closing="cancelImportPreview">

      <div class="modal-content">
        <div class="import-preview-header">
          <p>{{ importPreviewCount }}</p>
        </div>
        
        <div class="import-preview-list">
          <div v-for="(field, index) in importPreviewData" :key="field.field_name || field.name || `import-${index}`" class="import-preview-item">
            <div class="preview-field-icon">
              <component :is="getFieldIcon(field.field_type || 'text')" :size="20" />
            </div>
            <div class="preview-field-info">
              <!-- TRANSLATORS Shown in place of the name of a field that has no label or name -->
              <h4>{{ field.field_label || field.name || t('metavox', 'Unnamed field') }}</h4>
              <p>
                <!-- TRANSLATORS Label before the internal name of a field in the import preview, e.g. "Internal name: status" -->
                <strong>{{ t('metavox', 'Internal name:') }}</strong> {{ field.field_name || field.name }}
              </p>
              <p>
                <span class="preview-field-type">{{ getFieldTypeLabel(field.field_type) }}</span>
                <!-- TRANSLATORS Badge on a field that must be filled in; keep it short -->
                <span v-if="showsRequired(field)" class="preview-required-badge">{{ t('metavox', 'Required') }}</span>
              </p>
              <p v-if="field.field_description" class="preview-description">
                <!-- TRANSLATORS Label before the description of a field in the import preview -->
                <strong>{{ t('metavox', 'Description:') }}</strong> {{ field.field_description }}
              </p>
            </div>
          </div>
        </div>
        
      </div>
    </NcDialog>
  </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showConfirmation, showError, showSuccess } from '@nextcloud/dialogs'
import { translate, translatePlural } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { errorText, isFieldError } from '../utils/errorText.js'
import { includesTime, optionItems } from '../utils/fieldOptions.js'
import { PLAIN_TEXT } from '../utils/fieldValue.js'
import { fieldTypeLabel, fieldTypeOptions } from '../utils/fieldTypes.js'
import { canBeRequired } from '../utils/requiredFields.js'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcChip from '@nextcloud/vue/components/NcChip'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
// Icons
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import EditIcon from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import FileIcon from 'vue-material-design-icons/File.vue'
import TextIcon from 'vue-material-design-icons/Text.vue'
import NumericIcon from 'vue-material-design-icons/Numeric.vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import MenuDownIcon from 'vue-material-design-icons/MenuDown.vue'
import CheckboxIcon from 'vue-material-design-icons/CheckboxMarked.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import UploadIcon from 'vue-material-design-icons/Upload.vue'
import ImportIcon from 'vue-material-design-icons/Import.vue'
import ExportIcon from 'vue-material-design-icons/Export.vue'
import LinkIcon from 'vue-material-design-icons/Link.vue'
import AccountIcon from 'vue-material-design-icons/Account.vue'

/**
 * How long a background usage-count read stays good for.
 */
const USAGE_COUNT_TTL = 15 * 60 * 1000

export default {
  name: 'MetadataFieldsEditor',
  // Templates read the t() options from the component.
  setup() {
    return { PLAIN_TEXT }
  },
  
  components: {
    NcButton,
    NcTextArea,
    NcTextField,
    NcSelect,
    NcCheckboxRadioSwitch,
    NcDialog,
    NcActions,
    NcChip,
    NcListItem,
    NcNoteCard,
    NcLoadingIcon,
    NcActionButton,
    PlusIcon,
    CloseIcon,
    EditIcon,
    DeleteIcon,
    FolderIcon,
    FileIcon,
    TextIcon,
    NumericIcon,
    CalendarIcon,
    MenuDownIcon,
    CheckboxIcon,
    CheckIcon,
    MagnifyIcon,
    UploadIcon,
    ImportIcon,
    ExportIcon,
    LinkIcon,
    AccountIcon,
  },

  props: {
    /**
     * Which set of field definitions this editor manages.
     *
     * The two sets live in one table, metavox_gf_fields, and are told apart
     * by applies_to_groupfolder: 0 for fields that describe a file, 1 for
     * fields that describe a team folder. Everything else about the screen —
     * the form, the list, the import and export, the validation — is the same
     * for both, which is why this is a prop and no longer two components.
     */
    scope: {
      type: String,
      required: true,
      validator: (value) => ['file', 'teamfolder'].includes(value),
    },
  },

  emits: ['notification'],

  data() {
    return {
      loading: false,
      saving: false,
      deleting: null,
      importing: false,
      exporting: false,
      showAddForm: false,
      // Set on the first save attempt, so a message appears when the reader
      // has tried to submit rather than while they are still filling it in.
      addFormTouched: false,
      // What the server refused on the last save, per parameter; gone as
      // soon as the form changes.
      serverErrors: {},
      editingField: null,
      fields: [],
      searchQuery: '',
      selectedFile: null,
      importPreviewData: null,
      
      // Usage counts are loaded in the background and cached; see
      // loadFieldUsageCountsInBackground for the window.
      usageCountsCache: null,
      selected: [],
      deletingBulk: false,
      usageCountsExpiry: null,
      
      // Form data
      formData: {
        name: '',
        label: '',
        type: 'text',
        description: '',
        required: false,
        includeTime: false,
        options: []
      },

      // Selected field type object for NcSelect
      selectedFieldType: null,

      // Edit data
      editData: {
        name: '',
        label: '',
        description: '',
        required: false,
        includeTime: false,
        options: []
      }
    }
  },
  
  computed: {
    /**
     * Selection only opens once the usage counts are in. Before that the
     * app cannot say which fields are deletable, and offering a checkbox
     * that might be refused on submit is exactly the guessing this feature
     * is meant to remove.
     */
    canSelect() {
      return this.usageCountsCache !== null
    },

    /** Deletable ids among the rows currently shown, not the whole set. */
    filteredSelectableIds() {
      return this.filteredFields.filter((f) => this.canDeleteField(f)).map((f) => f.id)
    },

    allSelectableSelected() {
      const ids = this.filteredSelectableIds
      return ids.length > 0 && ids.every((id) => this.selected.includes(id))
    },

    selectAllLabel() {
      if (this.allSelectableSelected) {
        // TRANSLATORS Button that unticks all selected fields in the list
        return this.t('metavox', 'Clear selection')
      }
      // TRANSLATORS Button that ticks every field in the list that can be deleted
      return this.t('metavox', 'Select all deletable')
    },

    /**
     * Everything that differs between the two sets of fields, derived from the
     * one prop rather than duplicated down a second component.
     *
     * The name prefix is the part worth reading carefully: the backend strips
     * /^(file_gf_|gf_)/ (ApiFieldController.php:69), so those two strings are
     * the contract, and a field's stored name is the prefix plus what the
     * administrator typed.
     */
    isFileScope() {
      return this.scope === 'file'
    },

    namePrefix() {
      return this.isFileScope ? 'file_gf_' : 'gf_'
    },

    appliesToGroupfolder() {
      return this.isFileScope ? 0 : 1
    },

    scopeIcon() {
      return this.isFileScope ? 'FileIcon' : 'FolderIcon'
    },

    namePlaceholder() {
      return this.isFileScope
        // TRANSLATORS Placeholder of the internal field name input. The example names may be translated but must stay lowercase a-z, 0-9 and _ without spaces
        ? this.t('metavox', 'e.g., author, document_type, status')
        // TRANSLATORS Placeholder of the internal field name input. The example names may be translated but must stay lowercase a-z, 0-9 and _ without spaces
        : this.t('metavox', 'e.g., department, project_type, priority')
    },

    searchPlaceholder() {
      // TRANSLATORS Placeholder of the search box that filters the field list
      return this.t('metavox', 'Search fields …')
    },

    fieldNameHelp() {
      // TRANSLATORS Help text under the internal field name input; keep "a-z", "0-9" and "_" as they are
      return this.t('metavox', 'Only lowercase letters (a-z), numbers (0-9) and underscores (_)')
    },

    labelPlaceholder() {
      return this.isFileScope
        ? this.t('metavox', 'e.g. Author, Document type, Status')
        : this.t('metavox', 'e.g. Department, Project type, Priority')
    },

    emptyHint() {
      return this.isFileScope
        ? this.t('metavox', 'Add your first metadata field to get started organizing your files.')
        : this.t('metavox', 'Add your first metadata field to get started organizing your Team folders.')
    },

    /**
     * The add dialog's footer. Same shape as the edit one beside it, so the
     * two forms are operated the same way — the old inline panel said "Add
     * Field" on a button that also existed in the heading above it.
     */
    addDialogButtons() {
      return [
        {
          // TRANSLATORS Button that closes a dialog without saving
          label: this.t('metavox', 'Cancel'),
          disabled: this.saving,
          callback: () => this.cancelAdd(),
        },
        {
          label: this.saving
            // TRANSLATORS Dialog button label while the changes are being saved
            ? this.t('metavox', 'Saving …')
            // TRANSLATORS Button that creates the new metadata field
            : this.t('metavox', 'Create field'),
          variant: 'primary',
          disabled: this.saving,
          callback: () => { this.addFormTouched = true; this.addField(); return false },
        },
      ]
    },

    /**
     * The footer, as configuration rather than markup: NcDialog places the
     * buttons, puts the primary one last and handles focus. A callback that
     * returns false keeps the dialog open, which is what a save that may fail
     * validation needs.
     */
    editDialogButtons() {
      return [
        {
          // TRANSLATORS Button that closes a dialog without saving
          label: this.t('metavox', 'Cancel'),
          disabled: this.saving,
          callback: () => this.cancelEdit(),
        },
        {
          label: this.saving
            // TRANSLATORS Dialog button label while the changes are being saved
            ? this.t('metavox', 'Saving …')
            // TRANSLATORS Button that saves the changes in a dialog
            : this.t('metavox', 'Save'),
          variant: 'primary',
          disabled: this.saving || !this.isEditFormValid,
          callback: () => { this.saveEdit(); return false },
        },
      ]
    },

    importDialogButtons() {
      return [
        {
          // TRANSLATORS Button that closes a dialog without saving
          label: this.t('metavox', 'Cancel'),
          disabled: this.importing,
          callback: () => this.cancelImportPreview(),
        },
        {
          label: this.importing
            // TRANSLATORS Dialog button label while the fields from the JSON file are being imported
            ? this.t('metavox', 'Importing …')
            // TRANSLATORS Button that imports the fields listed in the preview
            : this.t('metavox', 'Import fields'),
          variant: 'primary',
          disabled: this.importing,
          callback: () => { this.confirmImport(); return false },
        },
      ]
    },

    importPreviewCount() {
      const count = this.importPreviewData ? this.importPreviewData.length : 0
      // This wants n(), but no locale in this app declares a pluralForm yet
      // (en, fr and sv have none), so a plural call would serve the singular
      // for every count. Keeping the existing string until the locales carry
      // the header; see the note in the pull request.
      // TRANSLATORS %n is the number of fields found in the JSON file that will be imported
      return this.n('metavox', 'Found %n field to import', 'Found %n fields to import', count)
    },

    exportFilename() {
      const date = new Date().toISOString().slice(0, 10)
      return this.isFileScope
        ? `file-metadata-fields-${date}.json`
        : `teamfolder-metadata-fields-${date}.json`
    },

    filteredFieldsCount() {
      const count = this.filteredFields.length
      return this.searchQuery 
        // TRANSLATORS %n is the number of fields that match the search; shown beside the list heading
        ? this.n('metavox', '%n field found', '%n fields found', count)
        // TRANSLATORS %n is the number of fields in the list; shown beside the list heading
        : this.n('metavox', '%n field', '%n fields', count)
    },
    
    // Filter fields based on search query
    filteredFields() {
      if (!this.searchQuery.trim()) {
        return this.fields
      }
      
      const query = this.searchQuery.toLowerCase().trim()
      return this.fields.filter(field => {
        const displayName = this.getDisplayName(field).toLowerCase()
        const internalName = (field.field_name || field.name || '').toLowerCase()
        const description = (field.field_description || field.description || '').toLowerCase()
        const fieldType = this.getFieldTypeLabel(field.field_type || field.type).toLowerCase()
        
        return displayName.includes(query) || 
               internalName.includes(query) ||
               description.includes(query) || 
               fieldType.includes(query)
      })
    },
    
    // Field type options for NcSelect
    selectOptions() {
      return fieldTypeOptions()
    },
    
    showOptionsField() {
      return this.formData.type === 'select' || this.formData.type === 'multiselect'
    },
    
    /**
     * A message on the field, not a toast (§3). These used to be three
     * showError calls in addField() that could never fire: isFormValid
     * already checks all three, so the primary button was simply grey with
     * nothing saying why.
     *
     * Empty while the field is untouched — telling someone a field they have
     * not reached yet is wrong is noise.
     */
    fieldLabelError() {
      if (!this.addFormTouched) return ''
      return String(this.formData.label || '').trim()
        ? ''
        : this.t('metavox', 'Enter a label people will see')
    },

    fieldOptionsError() {
      if (!this.addFormTouched || !this.showOptionsField) return ''
      const has = this.formData.options
        && this.formData.options.some((o) => String(o.value || '').trim())
      return has ? '' : this.t('metavox', 'Add at least one option')
    },

    fieldNameError() {
      const name = String(this.formData.name || '').trim()
      if (!name) return ''
      if (/\s/.test(name)) return this.t('metavox', 'Spaces are not allowed — use underscores instead')
      if (/[A-Z]/.test(name)) return this.t('metavox', 'Uppercase letters are not allowed — use lowercase only')
      // TRANSLATORS Error under the field name input; keep "a-z" as it is
      if (/^[0-9_]/.test(name)) return this.t('metavox', 'Must start with a lowercase letter (a-z)')
      if (/[^a-z0-9_]/.test(name)) {
        const invalid = name.match(/[^a-z0-9_]/)[0]
        // TRANSLATORS Error under the field name input. {char} is the character that is not allowed, e.g. "#"; keep "a-z", "0-9" and "_" as they are
        return this.t('metavox', 'Invalid character "{char}" — only a-z, 0-9 and _ allowed', { char: invalid }, undefined, PLAIN_TEXT)
      }
      return ''
    },

    isFormValid() {
      try {
        const name = String(this.formData.name || '').trim()
        const hasName = name.length > 0
        const validName = /^[a-z][a-z0-9_]*$/.test(name)
        const hasLabel = String(this.formData.label || '').trim().length > 0
        const hasValidOptions = !this.showOptionsField ||
                               (this.formData.options && this.formData.options.some(o => String(o.value || '').trim().length > 0))

        return hasName && validName && hasLabel && hasValidOptions
      } catch (error) {
        console.error('Error in isFormValid:', error)
        return false
      }
    },
    
    isEditFormValid() {
      try {
        const hasName = String(this.editData.name || '').trim().length > 0
        const hasLabel = String(this.editData.label || '').trim().length > 0
        const hasValidOptions = !(this.editingField?.field_type === 'select' || this.editingField?.field_type === 'multiselect') || 
                               (this.editData.options && this.editData.options.some(o => String(o.value || '').trim().length > 0))
        
        return hasName && hasLabel && hasValidOptions
      } catch (error) {
        console.error('Error in isEditFormValid:', error)
        return false
      }
    }
  },
  
  watch: {
    formData: { deep: true, handler() { this.serverErrors = {} } },
    editData: { deep: true, handler() { this.serverErrors = {} } },

    /**
     * Field ids are per scope, so a selection made on Team folders would
     * point at different fields on Files. Clear it rather than carry it.
     */
    scope() {
      this.selected = []
    },

    'formData.type'(newType) {
      // Initialize options array for select/multiselect fields
      if (this.showOptionsField && (!this.formData.options || this.formData.options.length === 0)) {
        this.formData.options = [{ value: '' }]
      }
    }
  },
  
  mounted() {
    this.loadFields()
    // Initialize the selected field type
    this.selectedFieldType = this.selectOptions.find(opt => opt.id === 'text')
  },
  
  methods: {
    canBeRequired,

    /** The Required badge: the flag, unless the type can never be required. */
    showsRequired(field) {
      return canBeRequired(field.field_type || field.type) && !!(field.is_required || field.required)
    },

    // Handle field type selection
    onFieldTypeChange(selectedOption) {
      this.selectedFieldType = selectedOption
      this.formData.type = selectedOption ? selectedOption.id : 'text'
    },

    async loadFields() {
      this.loading = true
      try {
        const response = await axios.get(generateUrl('/apps/metavox/api/groupfolder-fields'))
        
        // One table holds both sets; applies_to_groupfolder tells them apart.
        // The column arrives as an int over JSON but as a string from some
        // callers, so both forms are compared, and a file field may arrive with
        // the column absent or null, which means 0.
        const wanted = this.appliesToGroupfolder
        const fields = (response.data || []).filter(field => {
          const raw = field.applies_to_groupfolder
          const value = (raw === undefined || raw === null || raw === '') ? 0 : Number(raw)
          return value === wanted
        })
        
        // Start every count at 0 so a field always renders a number
        fields.forEach(field => {
          field.usage_count = 0
        })
        
        // Toon de velden METEEN
        this.fields = fields
        
        // Laad usage counts in de achtergrond (non-blocking, met cache)
        this.loadFieldUsageCountsInBackground()
        
      } catch (error) {
        console.error('Failed to load metadata fields:', error)
        showError(this.t('metavox', 'Failed to load metadata fields'))
        this.fields = []
      } finally {
        this.loading = false
      }
    },

    
    async loadFieldUsageCountsInBackground() {
      try {
        // Serve from cache while it is still inside the window
        const now = Date.now()
        if (this.usageCountsCache && this.usageCountsExpiry && this.usageCountsExpiry > now) {
          this.applyUsageCountsFromCache(this.usageCountsCache)
          return
        }
        
        // Haal alle groupfolders op
        const groupfoldersResponse = await axios.get(generateUrl('/apps/metavox/api/groupfolders'))
        const groupfolders = groupfoldersResponse.data || []
        
        if (groupfolders.length === 0) {
          // Cache an empty result too, so an install with no metadata does not
        // re-query on every render
          this.usageCountsCache = new Map()
          this.usageCountsExpiry = now + USAGE_COUNT_TTL
          return
        }
        
        // Maak een map om usage bij te houden
        const usageMap = new Map()
        this.fields.forEach(field => usageMap.set(field.id, 0))
        
        // Laad alle field assignments parallel
        const assignmentPromises = groupfolders.map(async (groupfolder) => {
          try {
            const response = await axios.get(
              generateUrl(`/apps/metavox/api/groupfolders/${groupfolder.id}/fields`)
            )
            return response.data || []
          } catch (error) {
            console.error(`Failed to load assignments for groupfolder ${groupfolder.id}:`, error)
            return [] // Fail gracefully
          }
        })
        
        const allAssignments = await Promise.all(assignmentPromises)
        
        // Tel usage
        allAssignments.forEach(fieldIds => {
          fieldIds.forEach(fieldId => {
            if (usageMap.has(fieldId)) {
              usageMap.set(fieldId, usageMap.get(fieldId) + 1)
            }
          })
        })
        
        // Cache the result
        this.usageCountsCache = usageMap
        this.usageCountsExpiry = now + USAGE_COUNT_TTL

        // Pas de counts toe
        this.applyUsageCountsFromCache(usageMap)

      } catch (error) {
        console.error('Failed to load usage counts in background:', error)
        // Bij error, cache niet updaten maar wel oude cache gebruiken als beschikbaar
        if (this.usageCountsCache && this.usageCountsExpiry > Date.now()) {
          this.applyUsageCountsFromCache(this.usageCountsCache)
        }
      }
    },

    
    applyUsageCountsFromCache(usageMap) {
      // Update usage counts in de bestaande fields array
      this.fields.forEach(field => {
        field.usage_count = usageMap.get(field.id) || 0
      })
      
      // Force reactivity update
      this.fields = [...this.fields]
    },

    
    invalidateUsageCountsCache() {
      this.usageCountsCache = null
      this.usageCountsExpiry = null
    },

    async addField() {
      const fieldName = String(this.formData.name || '').trim()
      const fieldLabel = String(this.formData.label || '').trim()

      // Stop here rather than posting a field the server will reject. The
      // three showError calls this replaced could never fire — the primary
      // button was disabled by the same condition — so the reader got a grey
      // button and no reason. The messages are on the fields now (§3), and
      // addFormTouched has just been set by the button, so they are visible.
      if (!this.isFormValid) {
        return
      }

      this.saving = true
      
      let finalFieldName = fieldName
      if (finalFieldName && !finalFieldName.startsWith(this.namePrefix)) {
        finalFieldName = this.namePrefix + finalFieldName
      }
      
      const fieldData = {
        field_name: finalFieldName,
        field_label: fieldLabel,
        field_type: this.formData.type,
        field_description: String(this.formData.description || '').trim(),
        is_required: this.formData.required && canBeRequired(this.formData.type) ? 1 : 0,
        field_options: this.formData.type === 'date'
          ? { includeTime: !!this.formData.includeTime }
          : this.formData.options
              .filter(o => String(o.value || '').trim().length > 0)
              .map(o => String(o.value).trim())
              .join('\n'),
        sort_order: 0,
        applies_to_groupfolder: this.appliesToGroupfolder
      }
      
      try {
        const response = await axios.post(generateUrl('/apps/metavox/api/groupfolder-fields'), fieldData)

        // Invalideer cache omdat er een nieuw field is
        this.invalidateUsageCountsCache()

        // Reload fields to ensure consistency
        await this.loadFields()

        this.resetForm()
        this.showAddForm = false
      this.addFormTouched = false
        // TRANSLATORS Notification after a field was created. {name} is the label of the new field, e.g. "Status"
        showSuccess(this.t('metavox', 'Field "{name}" added', { name: fieldLabel }))
      } catch (error) {
        this.showRefusal(error, this.t('metavox', 'Failed to add field'))
      } finally {
        this.saving = false
      }
    },
    
    async deleteField(fieldId) {
      this.deleting = fieldId
      
      try {
        await axios.delete(generateUrl(`/apps/metavox/api/groupfolder-fields/${fieldId}`))
        
        // Invalideer cache omdat een field is verwijderd
        this.invalidateUsageCountsCache()
        
        // Reload all fields to refresh usage counts
        await this.loadFields()
        
        // TRANSLATORS Notification after a metadata field was deleted
        showSuccess(this.t('metavox', 'Field deleted'))
      } catch (error) {
        showError(errorText(error, this.t('metavox', 'Failed to delete field')))
      } finally {
        this.deleting = null
      }
    },
    
    /**
     * Close the row menu before the dialog opens.
     *
     * NcListItem owns its NcActions and forwards only `forceMenu`, not
     * `open`, so the menu cannot be closed through a prop. Mounting a dialog
     * from an action click leaves the popper in `--shown`: the dialog takes
     * focus, so the click-outside and Escape that would normally dismiss it
     * never arrive, and it sits over the page behind the dialog — measured
     * still visible two seconds later. Clicking the trigger again is what a
     * user would do and is the one thing that reliably toggles it shut.
     */
    closeRowMenu() {
      // Runs after the action click has settled, so aria-expanded is already
      // true on the trigger that opened the menu. Clicking it toggles the
      // popper shut and leaves the dialog alone — verified on dev.
      document.querySelector('[aria-haspopup="menu"][aria-expanded="true"]')?.click()
    },

    editField(field, event) {
      // A click inside the selection control is a selection, not a request
      // to edit. Checked here as well as stopped in the template, because
      // the component's internal structure is not ours to depend on.
      if (event?.target?.closest?.('.field-select')) return
      this.editingField = field
      // Let the action click finish before toggling the trigger, otherwise
      // aria-expanded is still false and there is nothing to find.
      this.$nextTick(() => this.closeRowMenu())
      const rawOpts = field.field_options || field.options
      this.editData = {
        name: field.field_name || field.name,
        label: field.field_label || this.getDisplayName(field),
        description: field.field_description || field.description || '',
        required: !!(field.is_required || field.required),
        includeTime: includesTime(rawOpts),
        options: this.parseFieldOptions(rawOpts)
      }
    },

    async saveEdit() {
      if (!this.editingField || !this.isEditFormValid) return

      this.saving = true

      // Prepare the updated field data
      const fieldType = this.editingField.field_type || this.editingField.type
      const fieldData = {
        field_name: String(this.editData.name || '').trim(),
        field_label: String(this.editData.label || '').trim(),
        field_type: fieldType,
        field_description: String(this.editData.description || '').trim(),
        is_required: this.editData.required && canBeRequired(fieldType) ? 1 : 0,
        // A list; an option with a label of its own (set through the API)
        // keeps it, as this form shows only the values.
        field_options: fieldType === 'date'
          ? { includeTime: !!this.editData.includeTime }
          : (this.editData.options
              ? this.editData.options
                  .filter(o => String(o.value || '').trim().length > 0)
                  .map(o => (o.label ? { value: String(o.value).trim(), label: o.label } : String(o.value).trim()))
              : [])
      }
      
      // Prefix a new name for this scope; an old one without its prefix stays.
      const renamed = fieldData.field_name !== (this.editingField.field_name || this.editingField.name)
      if (renamed && fieldData.field_name && !fieldData.field_name.startsWith(this.namePrefix)) {
        fieldData.field_name = this.namePrefix + fieldData.field_name
      }
      
      try {
        await axios.put(generateUrl(`/apps/metavox/api/groupfolder-fields/${this.editingField.id}`), fieldData)
        
        // Invalideer cache omdat field is gewijzigd (labels kunnen zijn veranderd)
        this.invalidateUsageCountsCache()
        
        // Reload fields to ensure consistency
        await this.loadFields()
        
        this.cancelEdit()
        // TRANSLATORS Notification after the changes to a metadata field were saved
        showSuccess(this.t('metavox', 'Field updated'))
      } catch (error) {
        this.showRefusal(error, this.t('metavox', 'Failed to update field'))
      } finally {
        this.saving = false
      }
    },
    
    /**
     * Why the server refused a save: under the input it is about, the rest
     * as a notification.
     */
    showRefusal(error, fallback) {
      const fields = isFieldError(error) ? error.response.data.fields : {}
      this.serverErrors = fields
      const shown = ['field_name', 'field_label', 'field_options']
      if (Object.keys(fields).length === 0 || Object.keys(fields).some((key) => !shown.includes(key))) {
        showError(errorText(error, fallback))
      }
    },

    cancelEdit() {
      this.editingField = null
      this.editData = {
        name: '',
        label: '',
        description: '',
        required: false,
        includeTime: false,
        options: []
      }
    },
    
    addOption() {
      this.formData.options.push({ value: '' })
    },
    
    removeOption(index) {
      this.formData.options.splice(index, 1)
    },
    
    addEditOption() {
      if (!this.editData.options) {
        this.editData.options = []
      }
      this.editData.options.push({ value: '' })
    },
    
    removeEditOption(index) {
      this.editData.options.splice(index, 1)
    },
    
    openAddForm() {
      this.addFormTouched = false
      this.showAddForm = true
    },

    cancelAdd() {
      this.resetForm()
      this.showAddForm = false
    },
    
    resetForm() {
      this.formData = {
        name: '',
        label: '',
        type: 'text',
        description: '',
        required: false,
        includeTime: false,
        options: []
      }
      this.selectedFieldType = this.selectOptions.find(opt => opt.id === 'text')
    },
    
    getFieldTypeLabel(type) {
      return fieldTypeLabel(type)
    },
    
    getFieldIcon(type) {
      const icons = {
        text: 'TextIcon',
        textarea: 'TextIcon',
        number: 'NumericIcon',
        date: 'CalendarIcon',
        select: 'MenuDownIcon',
        multiselect: 'MenuDownIcon',
        checkbox: 'CheckboxIcon',
        // Every type the picker offers needs an entry, or the list draws a
        // text icon for it and two different types look the same. filelink was
        // mapped in only one of the two editors; url and user in neither.
        filelink: 'FileIcon',
        url: 'LinkIcon',
        user: 'AccountIcon',
      }
      return icons[type] || 'TextIcon'
    },
    
    /**
     * One line under the field name: what it is, what it is called in the
     * data, and where it is used. A middle dot rather than three labelled
     * paragraphs — the labels were the bulk of the old row's height, and
     * "Internal Name:" + value is a concatenated sentence a translator
     * cannot reorder (§13).
     */
    /**
     * Assignment is stated on every row, not only on the used ones.
     *
     * It used to append "in N team folders" when a field was used and
     * nothing when it was not, so "this can be deleted" was signalled by
     * absence — readable only by comparing a row against its neighbours,
     * across 48 of them. And because the count arrives from a background
     * call, an empty subline also meant "not known yet": two different
     * states that looked identical. Saying it either way makes the loading
     * state visible as the one case where no row says anything.
     */
    fieldSubline(field) {
      const parts = [
        this.getFieldTypeLabel(field.field_type || field.type),
        field.field_name || field.name,
      ]
      if (this.usageCountsCache !== null) {
        const used = this.getUsageCount(field)
        parts.push(used > 0
          // TRANSLATORS Part of the summary line under a field in the list, after the type and internal name; %n is the number of Team folders that use the field
          ? this.n('metavox', 'in %n Team folder', 'in %n Team folders', used)
          // TRANSLATORS Part of the summary line under a field in the list: no Team folder uses the field yet
          : this.t('metavox', 'not assigned'))
      }
      return parts.filter(Boolean).join(' · ')
    },

    /**
     * A field in use cannot be deleted. The count arrives from a background
     * read, so until it has, treat the answer as unknown rather than zero —
     * otherwise every delete is briefly enabled on a fresh page.
     */
    /** Says why a row cannot be picked, not just that it cannot. */
    selectionLabel(field) {
      const name = this.getDisplayName(field)
      if (this.canDeleteField(field)) {
        // TRANSLATORS Accessible name of the checkbox that selects a field in the list. {field} is the field label, e.g. "Status"
        return this.t('metavox', 'Select {field}', { field: name }, undefined, PLAIN_TEXT)
      }
      const used = this.getUsageCount(field)
      // TRANSLATORS Accessible name and tooltip of the disabled checkbox of a field that cannot be deleted. {field} is the field label, e.g. "Status"; %n is the number of Team folders that use it
      return this.n('metavox', '{field} is used in %n Team folder and cannot be deleted',
        '{field} is used in %n Team folders and cannot be deleted',
        used, { field: name }, PLAIN_TEXT)
    },

    toggleSelected(id, on) {
      if (on) {
        if (!this.selected.includes(id)) this.selected.push(id)
      } else {
        this.selected = this.selected.filter((x) => x !== id)
      }
    },

    /**
     * Select-all takes the deletable rows that are currently visible, not
     * every field. At 48 fields with 36 in use, "all" would otherwise be a
     * selection that is three-quarters no-op and a counter that overstates
     * what will happen.
     */
    toggleSelectAll() {
      this.selected = this.allSelectableSelected ? [] : [...this.filteredSelectableIds]
    },

    async confirmBulkDelete() {
      const count = this.selected.length
      if (!count) return

      const ok = await showConfirmation({
        // TRANSLATORS Title of the dialog that confirms deleting the selected fields
        name: this.t('metavox', 'Delete fields'),
        // TRANSLATORS %n is the number of selected fields that will be deleted
        text: this.n('metavox', 'Delete %n selected field? Its values are removed everywhere and this cannot be undone.',
          'Delete %n selected fields? Their values are removed everywhere and this cannot be undone.',
          count),
        // TRANSLATORS Button in the confirmation dialog that deletes the selected fields
        labelConfirm: this.t('metavox', 'Delete fields'),
        // TRANSLATORS Button that closes a dialog without saving
        labelReject: this.t('metavox', 'Cancel'),
        severity: 'warning',
      })
      if (!ok) return

      this.deletingBulk = true
      try {
        const { data } = await axios.post(
          generateUrl('/apps/metavox/api/groupfolder-fields/bulk-delete'),
          { ids: this.selected })

        const removed = (data.deleted || []).length
        const skipped = (data.skipped || []).length

        // The server re-checks usage, so a selection can come back partly
        // refused even when the list looked right — say so rather than
        // reporting a clean success.
        if (removed && skipped) {
          // TRANSLATORS Notification after deleting several fields. {removed} is the number of fields deleted, {skipped} the number not deleted because they are still in use; the plural form follows {skipped}
          this.$emit('notification', { type: 'warning', message: this.n('metavox', '{removed} deleted, {skipped} skipped because it is still in use',
            '{removed} deleted, {skipped} skipped because they are still in use',
            skipped, { removed, skipped }) })
        } else if (removed) {
          // TRANSLATORS %n is the number of fields that were deleted
          this.$emit('notification', { message: this.n('metavox', '%n field deleted', '%n fields deleted', removed) })
        } else {
          this.$emit('notification', { type: 'error', message: this.t('metavox', 'Nothing was deleted — the fields are still in use.') })
        }

        this.selected = []
        this.usageCountsCache = null
        await this.loadFields()
        this.loadFieldUsageCountsInBackground()
      } catch (error) {
        console.error('Bulk delete failed:', error)
        this.$emit('notification', { type: 'error',
          message: this.t('metavox', 'Could not delete the fields. Nothing was changed.') })
      } finally {
        this.deletingBulk = false
      }
    },

    canDeleteField(field) {
      if (this.usageCountsCache === null) {
        return false
      }
      return this.getUsageCount(field) === 0
    },

    deleteFieldLabel(field) {
      if (this.usageCountsCache === null) {
        // TRANSLATORS Menu action that deletes a field; keep it short
        return this.t('metavox', 'Delete field')
      }
      return this.getUsageCount(field) > 0
        // TRANSLATORS Disabled menu action of a field that is used in a Team folder and so cannot be deleted; keep it short
        ? this.t('metavox', 'Cannot delete (in use)')
        // TRANSLATORS Menu action that deletes a field; keep it short
        : this.t('metavox', 'Delete field')
    },

    getDisplayName(field) {
      // Prioritize field_label, then fallback logic
      if (field.field_label && field.field_label.trim()) {
        return field.field_label
      }
      
      // If no field_label, try to get from field_name 
      if (field.field_name) {
        // Show the name without its scope prefix
        if (field.field_name.startsWith(this.namePrefix)) {
          return field.field_name.substring(this.namePrefix.length)
        }
        return field.field_name
      }
      
      // Last fallback
      // TRANSLATORS Shown in place of the name of a field that has no label or name
      return field.name || this.t('metavox', 'Unnamed field')
    },
    
    getUsageCount(field) {
      // Gebruik altijd usage_count, met 0 als fallback
      return field.usage_count !== undefined ? field.usage_count : 0
    },
    
    // The rows of the options editor; a label only when it is not the value.
    parseFieldOptions(options) {
      return optionItems(options).map((item) => (item.label !== item.value ? { value: item.value, label: item.label } : { value: item.value }))
    },
    
    // Import and export
    triggerFileInput() {
      this.$refs.jsonFileInput.click()
    },
    
    handleFileSelect(event) {
      const file = event.target.files[0]
      if (!file) return
      
      this.selectedFile = file
      
      // Read and validate the file
      const reader = new FileReader()
      reader.onload = (e) => {
        try {
          const jsonData = JSON.parse(e.target.result)
          
          // Validate JSON structure
          if (!Array.isArray(jsonData)) {
            // TRANSLATORS Error when an imported file is not a list of fields; JSON is a file format, keep it as it is
            throw new Error(this.t('metavox', 'JSON must be an array of field definitions'))
          }
          
          // Show preview
          this.importPreviewData = jsonData
        } catch (error) {
          console.error('JSON parse error:', error)
          // TRANSLATORS {error} is the technical reason the file could not be read, e.g. "Unexpected token"; JSON is a file format
          showError(this.t('metavox', 'Invalid JSON file: {error}', { error: error.message }))
          this.selectedFile = null
        }
      }
      reader.readAsText(file)
    },

    async confirmImport() {
      if (!this.importPreviewData || this.importPreviewData.length === 0) {
        showError(this.t('metavox', 'No fields to import'))
        return
      }
      
      this.importing = true
      let importedCount = 0
      let errorCount = 0
      
      try {
        // Process each field individually
        for (const fieldData of this.importPreviewData) {
          try {
            // Prepare field data for import
            const importData = {
              field_name: fieldData.field_name || fieldData.name,
              field_label: fieldData.field_label || fieldData.label,
              field_type: fieldData.field_type || fieldData.type || 'text',
              field_description: fieldData.field_description || fieldData.description || '',
              is_required: fieldData.is_required || fieldData.required ? 1 : 0,
              // As exported: the server reads every form options come in.
              field_options: fieldData.field_options ?? fieldData.options ?? '',
              sort_order: fieldData.sort_order || 0,
              applies_to_groupfolder: this.appliesToGroupfolder
            }
            
            // Prefix the name for this scope if it is not already prefixed
            if (importData.field_name && !importData.field_name.startsWith(this.namePrefix)) {
              importData.field_name = this.namePrefix + importData.field_name
            }
            
            await axios.post(generateUrl('/apps/metavox/api/groupfolder-fields'), importData)
            importedCount++
          } catch (error) {
            console.error('Failed to import field:', error)
            errorCount++
          }
        }
        
        // Imported fields change the counts, so drop the cache
        this.invalidateUsageCountsCache()
        
        // Reload fields
        await this.loadFields()
        
        // Show results
        if (errorCount === 0) {
          // TRANSLATORS Notification after an import; %n is the number of fields imported
          showSuccess(this.n('metavox', 'Imported %n field', 'Imported %n fields', importedCount))
        } else {
          // TRANSLATORS Notification after an import with failures. {imported} is the number of fields imported, {errors} the number that failed; the plural form follows {errors}
          showSuccess(this.n('metavox', 'Import completed: {imported} imported, {errors} error',
            'Import completed: {imported} imported, {errors} errors',
            errorCount, { imported: importedCount, errors: errorCount }))
        }
        
        // Reset import state
        this.cancelImportPreview()
        this.selectedFile = null
        if (this.$refs.jsonFileInput) {
          this.$refs.jsonFileInput.value = ''
        }
        
      } catch (error) {
        console.error('Import failed:', error)
        // TRANSLATORS {error} is the reason the import failed, e.g. a server message or "Please try again."
        showError(this.t('metavox', 'Failed to import fields: {error}', {
          // TRANSLATORS Fallback reason shown after "Failed to import fields:" when the server gave none
          error: errorText(error, this.t('metavox', 'Please try again.')),
        }))
      } finally {
        this.importing = false
      }
    },
    
    cancelImportPreview() {
      this.importPreviewData = null
      this.selectedFile = null
      if (this.$refs.jsonFileInput) {
        this.$refs.jsonFileInput.value = ''
      }
    },
    
    async exportFields() {
      if (this.fields.length === 0) return
      
      this.exporting = true
      
      try {
        // Prepare data for export
        const exportData = this.fields.map(field => {
          // Export the name without its scope prefix
          let exportFieldName = field.field_name
          if (exportFieldName && exportFieldName.startsWith(this.namePrefix)) {
            exportFieldName = exportFieldName.substring(this.namePrefix.length)
          }
          
          return {
            field_name: exportFieldName,
            field_label: field.field_label,
            field_type: field.field_type,
            field_description: field.field_description,
            is_required: field.is_required,
            field_options: field.field_options,
            sort_order: field.sort_order,
            applies_to_groupfolder: field.applies_to_groupfolder
          }
        })
        
        // Create JSON string
        const jsonString = JSON.stringify(exportData, null, 2)
        
        // Create download link
        const blob = new Blob([jsonString], { type: 'application/json' })
        const url = URL.createObjectURL(blob)
        const link = document.createElement('a')
        link.href = url
        link.download = this.exportFilename
        document.body.appendChild(link)
        link.click()
        document.body.removeChild(link)
        URL.revokeObjectURL(url)
        
        // TRANSLATORS Notification after an export; %n is the number of fields written to the JSON file
        showSuccess(this.n('metavox', 'Exported %n field', 'Exported %n fields', this.fields.length))
      } catch (error) {
        console.error('Export failed:', error)
        showError(this.t('metavox', 'Failed to export fields'))
      } finally {
        this.exporting = false
      }
    },
    
    // The platform's own, rather than a local reimplementation. The one this
    // replaced read OC.L10N directly and had no plural form at all, which is
    // why no count on this screen went through n().
    t: translate,
    n: translatePlural,
  }
}
</script>

<style scoped>
/* No measure and no inset here: NcSettingsSection owns both (§6, "The measure
   belongs on the form"), and a second one on the child pushed this section's
   content 20px further in than the sections that had none — measured as three
   different left edges, 28 / 40 / 48, down one page. */

.loading-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 48px 20px;
  text-align: center;
}


.mv-block {
  /* A block inside an NcSettingsSection, not a card on top of one: the
     section already supplies the surface, the measure and the rule that
     separates it from the next. The card look this carried — a radius and a
     hardcoded rgba box-shadow that no theme can reach — drew a second frame
     inside the first, and the shadow was invisible in dark mode anyway. */
  margin-bottom: 24px;
}

.section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  /* A heading and its count do not fit on one line on a phone, and this row
     cannot scroll, so it wraps. */
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 16px;
  padding-bottom: 16px;
}

/* Heading and count as one unit, so space-between pushes the pair left and
   the actions right, rather than distributing three items across the row.
   baseline, not center: the count is smaller than the h3 and centring it
   leaves it floating against the cap height. */
.section-header__title {
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: 8px;
  min-width: 0;
}

.section-header h3 {
  /* min-width: 0 lets a long heading wrap instead of forcing the flex row
     wider than its container. */
  min-width: 0;
  overflow-wrap: anywhere;
  margin: 0;
  /* Nextcloud uses 20px for a heading and has no variable for it. */
  font-size: 20px;
  font-weight: var(--font-weight-heading, 600);
  color: var(--color-main-text);
}

.field-count {
  /* A count beside a heading is supporting text, not a badge: a filled pill
     says "something needs attention here", and this is just how many there
     are. §6 — supporting text is --font-size-small in --color-text-maxcontrast.
     Two of the three copies of this rule also used 0.9em and a hardcoded
     radius, neither of which is a token. */
  color: var(--color-text-maxcontrast);
  font-size: var(--font-size-small);
  font-weight: var(--font-weight-default, 400);
}

/* The file input is driven by the menu item, so it is out of the layout but
   still focusable-by-proxy: display: none would take it out of the
   accessibility tree, and the button that opens it is the named control. */
/* The create button and the overflow beside it. */
.section-header__actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.file-input {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
}


/* A message for a control that is not an NcTextField — the options block is
   a list of rows — reproducing what NcInputField renders so the two read the
   same. role=status, not alert: the reader just pressed a button. */
.mv-field-error {
  margin: 4px 0 0;
  color: var(--color-error-text);
  font-size: var(--font-size-small);
}

.form-row {
  margin-bottom: 20px;
}

.field-label {
  display: block;
  margin-bottom: 8px;
  font-weight: var(--font-weight-element);
  color: var(--color-main-text);
}

/* Body size, not --font-size-small: NcTextField's own helper text sets no
   size and inherits 15px, so a 13px hint beside it made two helper texts in
   one dialog at two sizes. Match the library rather than the habit. */
.field-help-text {
  margin: 8px 0 0 0;
  color: var(--color-text-maxcontrast);
}

/* The options were in a --color-background-darker well: the heaviest grey in
   the palette, meant for a sunken area, wrapped around a run of ordinary
   fields. Repeated fields in a form are grouped by whitespace and their
   heading, not by a container (§6, "Separators: between sections, never
   between fields"). Dropping the box also puts the inputs back on the
   dialog's own left edge instead of 16px inside it. */
.options-container {
  display: flex;
  flex-direction: column;
  /* The NcFormGroup distance between fields in one group. */
  gap: 8px;
}

/* A value that is fixed takes the form's rhythm as text rather than posing as
   a control (§6, "A read-only label/value list"). */
.form-row--static {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

/* Matches NcTextField's own label: 13px, so the pair sits in the same
   rhythm as the editable fields around it (§6). The label is the one part
   the library does render small. */
.form-row__label {
  color: var(--color-text-maxcontrast);
  font-size: var(--font-size-small);
}

.form-row__value {
  margin: 0;
  color: var(--color-main-text);
  font-family: var(--font-face-monospace, monospace);
}

.option-row {
  display: flex;
  gap: 8px;
  align-items: center;
  margin-bottom: 12px;
}

.option-row:last-child {
  margin-bottom: 0;
}

.option-row .text-field {
  flex: 1;
}

.form-actions {
  display: flex;
  gap: 12px;
  margin-top: 24px;
  padding-top: 20px;
  border-top: 1px solid var(--color-border);
}

.search-container {
  margin-bottom: 20px;
}

.empty-search,
.empty-content {
  text-align: center;
  padding: 40px 20px;
  color: var(--color-text-maxcontrast);
}

.empty-icon {
  margin-bottom: 16px;
  opacity: 0.5;
}

.empty-search h4,
.empty-content h4 {
  margin: 0 0 8px 0;
  color: var(--color-text-maxcontrast);
}

.empty-search p,
.empty-content p {
  margin: 0 0 20px 0;
}

/* NcListItem draws the row: its own hover background, radius, focus ring and
   actions menu. The block that stood here built a card by hand — a filled
   background, a border and four stacked paragraphs — which §6 names as the
   wrong treatment for a row that is not a section boundary. Measured: 58
   rows at ~148px each, 8554px of list, against ~48px per row now. */
/* Sits where the type icon would be, so the row keeps its rhythm whether
   the list is in selection mode or not. */
.field-select {
  display: inline-flex;
  align-items: center;
}

.fields-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.modal-content {
  padding: 20px;
}

.modal-form {
  margin-bottom: 24px;
}

.import-preview-header {
  /* Body text reads from the start edge; centring is for empty states and
     short confirmations. */
  margin-bottom: 16px;
}

.import-preview-header p {
  margin: 0;
  color: var(--color-text-maxcontrast);
}

.import-preview-list {
  max-height: 400px;
  overflow-y: auto;
  margin-bottom: 24px;
}

.import-preview-item {
  display: flex;
  align-items: flex-start;
  gap: 16px;
  padding: 16px;
  border-bottom: 1px solid var(--color-border);
}

.import-preview-item:last-child {
  border-bottom: none;
}

.preview-field-icon {
  flex-shrink: 0;
  margin-top: 4px;
  color: var(--color-primary-element);
}

.preview-field-info {
  flex: 1;
  min-width: 0;
}

.preview-field-info h4 {
  margin: 0 0 8px 0;
  font-size: var(--default-font-size);
  color: var(--color-main-text);
}

.preview-field-info p {
  margin: 4px 0;
  font-size: var(--font-size-small);
  color: var(--color-text-maxcontrast);
}

.preview-field-type,
.preview-required-badge {
  display: inline-block;
  padding: 4px 8px;
  border-radius: var(--border-radius-pill);
  font-size: var(--font-size-small);
  font-weight: var(--font-weight-element);
  margin-inline-end: 8px;
}

.preview-field-type {
  background: var(--color-background-darker);
  color: var(--color-text-maxcontrast);
}

.preview-required-badge {
  background: var(--color-warning);
  color: var(--color-warning-text);
}

.preview-description {
  margin-top: 8px !important;
}

/* Responsive adjustments */
@media (max-width: 768px) {
  .import-export-row {
    flex-direction: column;
    gap: 24px;
  }

  /* Column direction: the basis would size the height. Let each section be
     as tall as its own content. */
  .import-section,
  .export-section {
    flex: 0 0 auto;
  }
  
  .field-main {
    flex-direction: column;
    align-items: stretch;
  }
  
  .field-actions {
    align-self: flex-end;
    margin-top: 16px;
  }
  /* An empty state does not need 48px of air on a phone. */
  .empty-content,
  .empty-search,
  .loading-container {
    padding-block: 24px;
  }

}
</style>