<template>
	<div class="metavox-check">
		<div class="check-field">
			<label>{{ t('metavox', 'Metadata field') }}</label>
			<NcSelect
				v-model="selectedField"
				:options="groupedFields"
				:placeholder="t('metavox', 'Select a metadata field')"
				label="label"
				track-by="name"
				:loading="loadingFields"
				@input="updateValue"
			>
				<template #option="option">
					<span v-if="option.isHeader" class="field-group-header">{{ option.label }}</span>
					<span v-else>{{ option.label }}</span>
				</template>
			</NcSelect>
		</div>

		<div v-if="selectedField && !selectedField.isHeader" class="check-field">
			<label>{{ t('metavox', 'Value to check') }}</label>

			<!-- Select/Dropdown field -->
			<NcSelect
				v-if="selectedField.type === 'select' || selectedField.type === 'dropdown'"
				v-model="selectedCheckOption"
				:options="fieldOptions"
				:placeholder="t('metavox', 'Select a value')"
				label="label"
				track-by="value"
				@input="onSelectOptionChange"
			/>

			<!-- Checkbox/Boolean field -->
			<NcSelect
				v-else-if="selectedField.type === 'checkbox' || selectedField.type === 'boolean'"
				v-model="selectedCheckOption"
				:options="booleanOptions"
				:placeholder="t('metavox', 'Select a value')"
				label="label"
				track-by="value"
				@input="onSelectOptionChange"
			/>

			<!-- Date field -->
			<input
				v-else-if="selectedField.type === 'date'"
				v-model="checkValue"
				type="date"
				class="check-input"
			>

			<!-- Number field -->
			<input
				v-else-if="selectedField.type === 'number'"
				v-model="checkValue"
				type="number"
				class="check-input"
				:placeholder="t('metavox', 'Enter a number')"
			>

			<!-- Default: text input -->
			<input
				v-else
				v-model="checkValue"
				type="text"
				class="check-input"
				:placeholder="t('metavox', 'Enter expected value')"
			>
		</div>

		<div v-if="selectedField" class="check-field">
			<label>{{ t('metavox', 'Team folder (optional)') }}</label>
			<NcSelect
				v-model="selectedGroupfolder"
				:options="groupfolders"
				:placeholder="t('metavox', 'Auto-detect')"
				label="label"
				track-by="id"
				:loading="loadingGroupfolders"
				@input="updateValue"
			/>
			<p class="hint">{{ t('metavox', 'Leave empty to auto-detect from file location') }}</p>
		</div>

		<div v-if="selectedField && checkValue" class="check-preview">
			<span class="preview-label">{{ t('metavox', 'Check configuration:') }}</span>
			<code>{{ selectedField.label }} {{ operatorLabel }} "{{ checkValue }}"</code>
		</div>
	</div>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'MetadataCheck',

	components: {
		NcSelect,
	},

	props: {
		value: {
			type: String,
			default: '',
		},
		check: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			fields: [],
			groupfolders: [],
			selectedField: null,
			selectedGroupfolder: null,
			selectedCheckOption: null,
			checkValue: '',
			loadingFields: false,
			loadingGroupfolders: false,
		}
	},

	computed: {
		operatorLabel() {
			const op = this.check?.operator || 'is'
			const labels = {
				'is': '=',
				'!is': '≠',
				'matches': '~ (regex)',
				'!matches': '!~ (regex)',
				'contains': 'contains',
				'!contains': 'not contains',
			}
			return labels[op] || op
		},
		fieldOptions() {
			if (!this.selectedField?.options) {
				return []
			}
			// Handle both array of strings and array of objects
			return this.selectedField.options.map(opt => {
				if (typeof opt === 'string') {
					return { label: opt, value: opt }
				}
				return { label: opt.label || opt.value, value: opt.value || opt.label }
			})
		},
		booleanOptions() {
			return [
				{ label: this.t('metavox', 'Yes / True'), value: 'true' },
				{ label: this.t('metavox', 'No / False'), value: 'false' },
			]
		},
		groupedFields() {
			const groupfolderFields = this.fields.filter(f => f.appliesToGroupfolder)
			const fileFields = this.fields.filter(f => !f.appliesToGroupfolder)

			const result = []

			if (fileFields.length > 0) {
				result.push({
					label: this.t('metavox', '── File fields ──'),
					name: '__header_file__',
					isHeader: true,
					$isDisabled: true,
				})
				result.push(...fileFields)
			}

			if (groupfolderFields.length > 0) {
				result.push({
					label: this.t('metavox', '── Team folder fields ──'),
					name: '__header_groupfolder__',
					isHeader: true,
					$isDisabled: true,
				})
				result.push(...groupfolderFields)
			}

			return result
		},
	},

	mounted() {
		this.loadFields()
		this.loadGroupfolders()
		this.parseExistingConfig()
	},

	methods: {
		async loadFields() {
			this.loadingFields = true
			try {
				const response = await axios.get(generateUrl('/apps/metavox/api/groupfolder-fields'))
				this.fields = (response.data || []).map(f => ({
					name: f.field_name,
					label: f.field_label || f.field_name,
					type: f.field_type,
					options: f.field_options || [],
					appliesToGroupfolder: f.applies_to_groupfolder === true || f.applies_to_groupfolder === 1,
				}))
			} catch (error) {
				console.error('Failed to load metadata fields:', error)
				this.fields = []
			} finally {
				this.loadingFields = false
			}
		},

		async loadGroupfolders() {
			this.loadingGroupfolders = true
			try {
				const response = await axios.get(generateUrl('/apps/metavox/api/groupfolders'))
				this.groupfolders = (response.data || []).map(gf => ({
					id: gf.id,
					label: gf.mount_point || gf.label || `Team folder ${gf.id}`,
				}))
			} catch (error) {
				console.error('Failed to load groupfolders:', error)
				this.groupfolders = []
			} finally {
				this.loadingGroupfolders = false
			}
		},

		parseExistingConfig() {
			if (!this.value) {
				return
			}

			try {
				const config = JSON.parse(this.value)

				if (config.field_name) {
					const existing = this.fields.find(f => f.name === config.field_name)
					if (existing) {
						this.selectedField = existing
					} else {
						this.selectedField = {
							name: config.field_name,
							label: config.field_name,
						}
					}
				}

				if (config.value) {
					this.checkValue = config.value
					// Set selectedCheckOption for select/boolean fields
					if (this.selectedField) {
						const fieldType = this.selectedField.type
						if (fieldType === 'select' || fieldType === 'dropdown') {
							this.selectedCheckOption = this.fieldOptions.find(o => o.value === config.value) || null
						} else if (fieldType === 'checkbox' || fieldType === 'boolean') {
							this.selectedCheckOption = this.booleanOptions.find(o => o.value === config.value) || null
						}
					}
				}

				if (config.groupfolder_id) {
					const existing = this.groupfolders.find(gf => gf.id === config.groupfolder_id)
					if (existing) {
						this.selectedGroupfolder = existing
					} else {
						this.selectedGroupfolder = {
							id: config.groupfolder_id,
							label: `Team folder ${config.groupfolder_id}`,
						}
					}
				}
			} catch (e) {
				console.warn('Failed to parse existing check config:', e)
			}
		},

		onSelectOptionChange(option) {
			this.selectedCheckOption = option
			this.checkValue = option?.value || ''
			this.updateValue()
		},

		updateValue() {
			const config = {
				field_name: this.selectedField?.name || '',
				value: this.checkValue || '',
			}

			if (this.selectedGroupfolder?.id) {
				config.groupfolder_id = this.selectedGroupfolder.id
			}

			this.$emit('input', JSON.stringify(config))
		},

		t(app, text, vars) {
			if (typeof window.t === 'function') {
				return window.t(app, text, vars)
			}
			if (vars) {
				return Object.keys(vars).reduce((result, key) => {
					return result.replace(`{${key}}`, vars[key])
				}, text)
			}
			return text
		},
	},

	watch: {
		fields() {
			if (this.value && this.fields.length > 0) {
				this.parseExistingConfig()
			}
		},
		groupfolders() {
			if (this.value && this.groupfolders.length > 0) {
				this.parseExistingConfig()
			}
		},
		checkValue() {
			this.updateValue()
		},
		selectedField(newField, oldField) {
			// Reset check value when field changes
			if (newField?.name !== oldField?.name) {
				this.checkValue = ''
				this.selectedCheckOption = null
			}
		},
	},
}
</script>

<style scoped>
.metavox-check {
	padding: 8px 0;
}

.check-field {
	margin-bottom: 12px;
}

.check-field label {
	display: block;
	font-weight: 600;
	margin-bottom: 4px;
	font-size: 13px;
	color: var(--color-main-text);
}

.check-field .hint {
	font-size: 12px;
	color: var(--color-text-lighter);
	margin: 4px 0 0 0;
}

.check-input {
	width: 100%;
	padding: 8px 12px;
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 14px;
}

.check-input:focus {
	border-color: var(--color-primary-element);
	outline: none;
}

.check-preview {
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 8px 12px;
	margin-top: 12px;
}

.preview-label {
	font-size: 11px;
	color: var(--color-text-lighter);
	display: block;
	margin-bottom: 4px;
}

.check-preview code {
	font-family: monospace;
	font-size: 13px;
	color: var(--color-main-text);
}

.field-group-header {
	font-weight: 600;
	font-size: 11px;
	color: var(--color-text-lighter);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}
</style>
