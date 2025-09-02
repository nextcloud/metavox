<div id="testermeta-admin">
    <h2><?php p($l->t('MetaVox - Custom Metadata Fields')); ?></h2>
    
    <!-- Tab Navigation - Global Fields Hidden -->
    <div class="tab-navigation">
        <!-- GLOBAL FIELDS TAB - TEMPORARILY HIDDEN (preserved for future use)
        <button class="tab-button" data-tab="fields"><?php p($l->t('Global Fields')); ?></button>
        -->
        <button class="tab-button active" data-tab="groupfolder-metadata-fields"><?php p($l->t('Team folder Metadata Fields')); ?></button>
        <button class="tab-button" data-tab="file-metadata-fields"><?php p($l->t('File Metadata Fields')); ?></button>
        <button class="tab-button" data-tab="groupfolders"><?php p($l->t('Manage Team folders')); ?></button>
    </div>
    
    <!-- Groupfolder Metadata Fields Management Tab -->
    <div id="groupfolder-metadata-fields-tab" class="tab-content active">
        <div class="metadata-section">
            <div class="section-header-with-import">
                <h3>📁 <?php p($l->t('Add New Team folder Metadata Field')); ?></h3>
                <details class="import-toggle">
                    <summary>📤 Import/Export JSON</summary>
                    <div class="import-content">
                        <div class="import-export-actions">
                            <form id="upload-gfm-json-form" class="compact-upload-form">
                                <label>Import:</label>
                                <input type="file" id="gfm-json-file" accept=".json" required>
                                <button type="submit">Import Fields</button>
                            </form>
                            <div class="export-section">
                                <label>Export:</label>
                                <button type="button" id="export-gfm-json" class="export-btn">📤 Export Team folder Metadata</button>
                            </div>
                        </div>
                        <small>Import or export Team folder metadata field definitions</small>
                    </div>
                </details>
            </div>
            <p><?php p($l->t('These fields apply to the Team folder itself and can be edited by administrators. They are visible to all users with access to the Team folder.')); ?></p>
            <form id="new-groupfolder-metadata-field-form">
                <div class="metadata-field">
                    <label for="gfm-field-name"><?php p($l->t('Field Name')); ?> <span class="required">*</span></label>
                    <input type="text" id="gfm-field-name" name="field_name" required 
                           placeholder="<?php p($l->t('e.g., project_budget')); ?>">
                    <small><?php p($l->t('Internal name for Team folder metadata (no spaces, lowercase)')); ?></small>
                </div>
                
                <div class="metadata-field">
                    <label for="gfm-field-label"><?php p($l->t('Field Label')); ?> <span class="required">*</span></label>
                    <input type="text" id="gfm-field-label" name="field_label" required 
                           placeholder="<?php p($l->t('e.g., Project Budget')); ?>">
                    <small><?php p($l->t('Display name shown to users')); ?></small>
                </div>
                
                <div class="metadata-field">
                    <label for="gfm-field-type"><?php p($l->t('Field Type')); ?></label>
                    <select id="gfm-field-type" name="field_type" class="dropdown-fix">
                        <option value="text"><?php p($l->t('Text')); ?></option>
                        <option value="textarea"><?php p($l->t('Textarea')); ?></option>
                        <option value="select"><?php p($l->t('Dropdown')); ?></option>
                        <option value="checkbox"><?php p($l->t('Checkbox')); ?></option>
                        <option value="number"><?php p($l->t('Number')); ?></option>
                        <option value="date"><?php p($l->t('Date')); ?></option>
                    </select>
                </div>
                
                <div class="metadata-field" id="gfm-field-options-group" style="display: none;">
                    <label for="gfm-field-options"><?php p($l->t('Options')); ?></label>
                    <textarea id="gfm-field-options" name="field_options" rows="4" 
                              placeholder="<?php p($l->t('One option per line')); ?>"></textarea>
                    <small><?php p($l->t('For select fields: enter one option per line')); ?></small>
                </div>
                
                <!-- IMPROVED CHECKBOX STYLING -->
                <div class="metadata-field">
                    <div class="checkbox-container">
                        <input type="checkbox" id="gfm-is-required" name="is_required" class="styled-checkbox">
                        <label for="gfm-is-required" class="checkbox-label">
                            <span class="checkbox-custom"></span>
                            <span class="checkbox-text"><?php p($l->t('Required Field')); ?></span>
                        </label>
                    </div>
                </div>
                
                <div class="metadata-field">
                    <label for="gfm-sort-order"><?php p($l->t('Sort Order')); ?></label>
                    <input type="number" id="gfm-sort-order" name="sort_order" value="0" min="0">
                    <small><?php p($l->t('Lower numbers appear first')); ?></small>
                </div>
                
                <div class="metadata-actions">
                    <button type="submit">📁 <?php p($l->t('Add Team folder Metadata Field')); ?></button>
                </div>
            </form>
        </div>
        
        <div class="metadata-section">
            <h3><?php p($l->t('Existing Team folder Metadata Fields')); ?></h3>
            <div id="groupfolder-metadata-fields-list">
                <div class="loading"><?php p($l->t('Loading fields...')); ?></div>
            </div>
        </div>
    </div>

    <!-- File Metadata Fields Management Tab -->
    <div id="file-metadata-fields-tab" class="tab-content">
        <div class="metadata-section">
            <div class="section-header-with-import">
                <h3>📄 <?php p($l->t('Add New File Metadata Field')); ?></h3>
                <details class="import-toggle">
                    <summary>📤 Import/Export JSON</summary>
                    <div class="import-content">
                        <div class="import-export-actions">
                            <form id="upload-fm-json-form" class="compact-upload-form">
                                <label>Import:</label>
                                <input type="file" id="fm-json-file" accept=".json" required>
                                <button type="submit">Import Fields</button>
                            </form>
                            <div class="export-section">
                                <label>Export:</label>
                                <button type="button" id="export-fm-json" class="export-btn">📤 Export File Metadata</button>
                            </div>
                        </div>
                        <small>Import or export file metadata field definitions</small>
                    </div>
                </details>
            </div>
            <p><?php p($l->t('These fields apply to individual files within Team folders. Users can edit these fields when viewing or uploading files.')); ?></p>
            <form id="new-file-metadata-field-form">
                <div class="metadata-field">
                    <label for="fm-field-name"><?php p($l->t('Field Name')); ?> <span class="required">*</span></label>
                    <input type="text" id="fm-field-name" name="field_name" required 
                           placeholder="<?php p($l->t('e.g., document_category')); ?>">
                    <small><?php p($l->t('Internal name for file metadata (no spaces, lowercase)')); ?></small>
                </div>
                
                <div class="metadata-field">
                    <label for="fm-field-label"><?php p($l->t('Field Label')); ?> <span class="required">*</span></label>
                    <input type="text" id="fm-field-label" name="field_label" required 
                           placeholder="<?php p($l->t('e.g., Document Category')); ?>">
                    <small><?php p($l->t('Display name shown to users')); ?></small>
                </div>
                
                <div class="metadata-field">
                    <label for="fm-field-type"><?php p($l->t('Field Type')); ?></label>
                    <select id="fm-field-type" name="field_type" class="dropdown-fix">
                        <option value="text"><?php p($l->t('Text')); ?></option>
                        <option value="textarea"><?php p($l->t('Textarea')); ?></option>
                        <option value="select"><?php p($l->t('Dropdown')); ?></option>
                        <option value="checkbox"><?php p($l->t('Checkbox')); ?></option>
                        <option value="number"><?php p($l->t('Number')); ?></option>
                        <option value="date"><?php p($l->t('Date')); ?></option>
                    </select>
                </div>
                
                <div class="metadata-field" id="fm-field-options-group" style="display: none;">
                    <label for="fm-field-options"><?php p($l->t('Options')); ?></label>
                    <textarea id="fm-field-options" name="field_options" rows="4" 
                              placeholder="<?php p($l->t('One option per line')); ?>"></textarea>
                    <small><?php p($l->t('For select fields: enter one option per line')); ?></small>
                </div>
                
                <!-- IMPROVED CHECKBOX STYLING -->
                <div class="metadata-field">
                    <div class="checkbox-container">
                        <input type="checkbox" id="fm-is-required" name="is_required" class="styled-checkbox">
                        <label for="fm-is-required" class="checkbox-label">
                            <span class="checkbox-custom"></span>
                            <span class="checkbox-text"><?php p($l->t('Required Field')); ?></span>
                        </label>
                    </div>
                </div>
                
                <div class="metadata-field">
                    <label for="fm-sort-order"><?php p($l->t('Sort Order')); ?></label>
                    <input type="number" id="fm-sort-order" name="sort_order" value="0" min="0">
                    <small><?php p($l->t('Lower numbers appear first')); ?></small>
                </div>
                
                <div class="metadata-actions">
                    <button type="submit">📄 <?php p($l->t('Add File Metadata Field')); ?></button>
                </div>
            </form>
        </div>
        
        <div class="metadata-section">
            <h3><?php p($l->t('Existing File Metadata Fields')); ?></h3>
            <div id="file-metadata-fields-list">
                <div class="loading"><?php p($l->t('Loading fields...')); ?></div>
            </div>
        </div>
    </div>

    <!-- Manage Groupfolders Tab -->
    <div id="groupfolders-tab" class="tab-content">
        <div class="metadata-section">
            <h3><?php p($l->t('Team folder Management')); ?></h3>
            <p><?php p($l->t('Configure which metadata fields are available for each Team folder and manage Team folder-specific metadata.')); ?></p>
            
            <div class="info-box">
                <h4>💡 <?php p($l->t('How it works:')); ?></h4>
                <ul>
                    <li><?php p($l->t('Configure Fields: Choose which fields are available for each Team folder')); ?></li>
                    <li><?php p($l->t('Edit Metadata: Set Team folder-specific metadata values (admin only)')); ?></li>
                    <li><?php p($l->t('File metadata can be edited by users within the Files app')); ?></li>
                </ul>
            </div>
            
            <div id="groupfolders-list">
                <div class="loading"><?php p($l->t('Loading Team folders...')); ?></div>
            </div>
        </div>
    </div>
</div>

<style>
/* Ultra Clean MetaVox Admin - Minimalistic Design with Edit Modal + Groupfolder Search */
#testermeta-admin {
    margin: 0;
    padding: 20px;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
    background: #ffffff;
    color: #1f2937;
    line-height: 1.6;
}

/* Typography - Clean & Minimal */
#testermeta-admin h2 {
    margin: 0 0 40px 0;
    color: #111827;
    font-size: 28px;
    font-weight: 300;
    letter-spacing: -0.5px;
}

#testermeta-admin h3 {
    margin: 0 0 16px 0;
    color: #374151;
    font-size: 20px;
    font-weight: 400;
}

#testermeta-admin h4 {
    margin: 0 0 12px 0;
    color: #4b5563;
    font-size: 16px;
    font-weight: 500;
}

#testermeta-admin p {
    margin: 0 0 16px 0;
    color: #6b7280;
    font-size: 14px;
}

/* Clean Tab Navigation */
.tab-navigation {
    border-bottom: 1px solid #e5e7eb;
    margin-bottom: 32px;
    overflow-x: auto;
    white-space: nowrap;
}

.tab-button {
    background: none;
    border: none;
    padding: 12px 24px;
    cursor: pointer;
    color: #6b7280;
    font-size: 14px;
    font-weight: 400;
    border-bottom: 2px solid transparent;
    margin-right: 8px;
    transition: all 0.2s ease;
    white-space: nowrap;
}

.tab-button.active {
    color: #3b82f6;
    border-bottom-color: #3b82f6;
    font-weight: 500;
}

.tab-button:hover:not(.active) {
    color: #374151;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

/* Clean Metadata Sections */
.metadata-section {
    margin-bottom: 40px;
    padding: 24px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: #ffffff;
}

.metadata-field {
    margin-bottom: 20px;
}

.metadata-field label {
    display: block;
    font-weight: 500;
    margin-bottom: 6px;
    color: #374151;
    font-size: 14px;
}

.metadata-field input,
.metadata-field textarea,
.metadata-field select {
    width: 100%;
    max-width: 500px;
    padding: 12px 16px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    background: #ffffff;
    font-size: 14px;
    color: #111827;
    box-sizing: border-box;
    transition: border-color 0.2s ease;
}

.metadata-field input:focus,
.metadata-field textarea:focus,
.metadata-field select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

/* Clean Dropdown Styling */
.dropdown-fix {
    all: unset !important;
    display: block !important;
    width: 100% !important;
    max-width: 500px !important;
    padding: 12px 16px !important;
    border: 1px solid #d1d5db !important;
    border-radius: 6px !important;
    background: #ffffff !important;
    color: #374151 !important;
    font-size: 14px !important;
    font-weight: 400 !important;
    cursor: pointer !important;
    box-sizing: border-box !important;
    min-height: 44px !important;
    -webkit-appearance: menulist !important;
    -moz-appearance: menulist !important;
    appearance: menulist !important;
    transition: border-color 0.2s ease !important;
}

.dropdown-fix:focus {
    outline: none !important;
    border-color: #3b82f6 !important;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1) !important;
}

/* Clean Actions */
.metadata-actions {
    margin-top: 24px;
}

.metadata-actions button {
    padding: 12px 24px;
    background: #3b82f6;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 500;
    font-size: 14px;
    transition: background-color 0.2s ease;
}

.metadata-actions button:hover {
    background: #2563eb;
}

/* ===================================
   🎯 IMPROVED CHECKBOX STYLING - NEW!
   ===================================*/

/* Checkbox Container */
.checkbox-container {
    margin-bottom: 4px;
    max-width: 500px;
}

/* Hidden native checkbox */
.styled-checkbox {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
    pointer-events: none;
}

/* Checkbox Label Container */
.checkbox-label {
    display: flex !important;
    align-items: center;
    gap: 12px;
    cursor: pointer;
    padding: 16px;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    transition: all 0.2s ease;
    font-weight: 500 !important;
    color: #374151 !important;
    font-size: 14px !important;
    margin: 0 !important;
    user-select: none;
    position: relative;
    overflow: hidden;
}

.checkbox-label::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, #3b82f6 0%, #8b5cf6 100%);
    opacity: 0;
    transition: opacity 0.2s ease;
}

/* Checkbox Label Hover State */
.checkbox-label:hover {
    background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
    border-color: #cbd5e1;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

/* Custom Checkbox Visual */
.checkbox-custom {
    width: 20px;
    height: 20px;
    border: 2px solid #d1d5db;
    border-radius: 4px;
    background: #ffffff;
    position: relative;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

/* Checkmark */
.checkbox-custom::after {
    content: '✓';
    font-size: 14px;
    font-weight: bold;
    color: #ffffff;
    opacity: 0;
    transform: scale(0.5);
    transition: all 0.2s ease;
}

/* Checked State */
.styled-checkbox:checked + .checkbox-label {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    border-color: #3b82f6;
    color: #1e40af !important;
}

.styled-checkbox:checked + .checkbox-label::before {
    opacity: 1;
}

.styled-checkbox:checked + .checkbox-label .checkbox-custom {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    border-color: #1d4ed8;
    transform: scale(1.05);
    box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.2);
}

.styled-checkbox:checked + .checkbox-label .checkbox-custom::after {
    opacity: 1;
    transform: scale(1);
}

/* Checkbox Text */
.checkbox-text {
    font-weight: 500;
    letter-spacing: 0.025em;
}

/* Checked Text Color */
.styled-checkbox:checked + .checkbox-label .checkbox-text {
    font-weight: 600;
}

/* Focus State */
.styled-checkbox:focus + .checkbox-label {
    outline: 2px solid #3b82f6;
    outline-offset: 2px;
}

/* Disabled State */
.styled-checkbox:disabled + .checkbox-label {
    opacity: 0.6;
    cursor: not-allowed;
    background: #f3f4f6;
    border-color: #d1d5db;
}

.styled-checkbox:disabled + .checkbox-label .checkbox-custom {
    background: #f9fafb;
    border-color: #e5e7eb;
}

/* ===================================
   OLD CHECKBOX STYLES FOR BACKWARDS COMPATIBILITY
   ===================================*/

/* Keep old checkbox wrapper for any existing instances */
.checkbox-wrapper {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
}

.checkbox-wrapper input[type="checkbox"] {
    width: 16px;
    height: 16px;
    margin: 0;
    accent-color: #3b82f6;
    cursor: pointer;
}

.checkbox-wrapper label {
    margin: 0 !important;
    font-weight: 400;
    cursor: pointer;
    color: #374151;
    font-size: 14px;
}

/* Clean Info Box */
.info-box {
    margin: 24px 0;
    padding: 16px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    background: #f9fafb;
}

.info-box h4 {
    margin: 0 0 8px 0;
    font-size: 14px;
    font-weight: 500;
    color: #374151;
}

.info-box ul {
    margin: 0;
    padding-left: 16px;
    font-size: 13px;
    line-height: 1.5;
    color: #6b7280;
}

.info-box li {
    margin-bottom: 4px;
}

/* Clean Required Indicator */
.required {
    color: #dc2626;
    margin-left: 4px;
    font-weight: 500;
}

/* Clean Small Text */
small {
    display: block;
    color: #9ca3af;
    font-size: 12px;
    margin-top: 4px;
    line-height: 1.4;
}

/* Clean Loading Indicator */
.loading {
    text-align: center;
    padding: 40px;
    color: #9ca3af;
    font-style: italic;
    font-size: 14px;
}

.loading::before {
    content: '';
    display: inline-block;
    width: 20px;
    height: 20px;
    border: 2px solid #e2e8f0;
    border-top: 2px solid #3b82f6;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin-right: 10px;
    vertical-align: middle;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Import/Export Styling */
.section-header-with-import {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 16px;
}

.import-toggle {
    flex-shrink: 0;
    margin-left: 20px;
}

.import-toggle summary {
    cursor: pointer;
    font-size: 12px;
    color: #6b7280;
    font-weight: 500;
    padding: 6px 12px;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 4px;
    list-style: none;
    transition: all 0.2s ease;
    user-select: none;
}

.import-toggle summary:hover {
    background: #f3f4f6;
    color: #374151;
}

.import-toggle summary::-webkit-details-marker {
    display: none;
}

.import-toggle[open] summary {
    background: #e0f2fe;
    color: #0284c7;
    border-color: #7dd3fc;
}

.import-content {
    position: absolute;
    z-index: 1000;
    background: #ffffff;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    padding: 16px;
    margin-top: 4px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    min-width: 320px;
    right: 0;
}

.import-export-actions {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.compact-upload-form {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.compact-upload-form label,
.export-section label {
    font-size: 12px;
    font-weight: 500;
    color: #374151;
    margin-bottom: 4px;
}

.export-section {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding-top: 12px;
    border-top: 1px solid #f3f4f6;
}

.compact-upload-form input[type="file"] {
    font-size: 12px;
    padding: 6px 8px;
    border: 1px solid #d1d5db;
    border-radius: 4px;
    background: #ffffff;
}

.compact-upload-form button {
    background: #059669;
    color: white;
    padding: 6px 12px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 500;
    transition: background-color 0.2s ease;
}

.compact-upload-form button:hover {
    background: #047857;
}

.export-btn {
    background: #0284c7;
    color: white;
    padding: 6px 12px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 500;
    transition: background-color 0.2s ease;
}

.export-btn:hover {
    background: #0369a1;
}

.import-content small {
    color: #9ca3af;
    font-size: 11px;
    margin-top: 4px;
    line-height: 1.3;
}

/* Clean Messages */
.success-message,
.error-message,
.info-message {
    padding: 12px 16px;
    border-radius: 6px;
    margin: 16px 0;
    font-size: 14px;
    border: 1px solid;
}

.success-message {
    background: #f0fdf4;
    color: #15803d;
    border-color: #bbf7d0;
}

.error-message {
    background: #fef2f2;
    color: #dc2626;
    border-color: #fecaca;
}

.info-message {
    background: #eff6ff;
    color: #1d4ed8;
    border-color: #bfdbfe;
}

/* ============================
   🆕 EDIT MODAL STYLES - NEW!
   ============================*/

/* Modal Overlay */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(4px);
    z-index: 10000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    box-sizing: border-box;
}

/* Modal Container */
.modal-container {
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    max-width: 600px;
    width: 100%;
    max-height: 90vh;
    overflow: hidden;
    animation: modalSlideIn 0.3s ease-out;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: scale(0.95) translateY(-20px);
    }
    to {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
}

/* Modal Header */
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 24px 24px 16px 24px;
    border-bottom: 1px solid #e5e7eb;
    background: #f9fafb;
}

.modal-header h3 {
    margin: 0;
    color: #111827;
    font-size: 18px;
    font-weight: 600;
}

.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    color: #6b7280;
    cursor: pointer;
    padding: 4px;
    border-radius: 4px;
    transition: all 0.2s ease;
    line-height: 1;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-close:hover {
    background: #e5e7eb;
    color: #374151;
}

/* Modal Body */
.modal-body {
    padding: 24px;
    max-height: 60vh;
    overflow-y: auto;
}

/* Edit Field Groups */
.edit-field-group {
    margin-bottom: 20px;
}

.edit-field-group label {
    display: block;
    font-weight: 500;
    margin-bottom: 6px;
    color: #374151;
    font-size: 14px;
}

.edit-field-group input,
.edit-field-group textarea,
.edit-field-group select {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    background: #ffffff;
    font-size: 14px;
    color: #111827;
    box-sizing: border-box;
    transition: border-color 0.2s ease;
}

.edit-field-group input:focus,
.edit-field-group textarea:focus,
.edit-field-group select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

/* Edit Dropdown Fix */
.edit-dropdown-fix {
    all: unset !important;
    display: block !important;
    width: 100% !important;
    padding: 12px 16px !important;
    border: 1px solid #d1d5db !important;
    border-radius: 6px !important;
    background: #ffffff !important;
    color: #374151 !important;
    font-size: 14px !important;
    font-weight: 400 !important;
    cursor: pointer !important;
    box-sizing: border-box !important;
    min-height: 44px !important;
    -webkit-appearance: menulist !important;
    -moz-appearance: menulist !important;
    appearance: menulist !important;
    transition: border-color 0.2s ease !important;
}

.edit-dropdown-fix:focus {
    outline: none !important;
    border-color: #3b82f6 !important;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1) !important;
}

/* Edit Checkbox Wrapper */
.edit-checkbox-wrapper {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
}

.edit-checkbox-wrapper input[type="checkbox"] {
    width: 16px;
    height: 16px;
    margin: 0;
    accent-color: #3b82f6;
    cursor: pointer;
}

.edit-checkbox-wrapper label {
    margin: 0 !important;
    font-weight: 400;
    cursor: pointer;
    color: #374151;
    font-size: 14px;
}

/* Modal Footer */
.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    padding: 16px 24px 24px 24px;
    border-top: 1px solid #e5e7eb;
    background: #f9fafb;
}

.modal-footer button {
    padding: 10px 20px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    border: 1px solid transparent;
}

.btn-primary {
    background: #3b82f6;
    color: white;
    border-color: #3b82f6;
}

.btn-primary:hover {
    background: #2563eb;
    border-color: #2563eb;
}

.btn-primary:disabled {
    background: #9ca3af;
    border-color: #9ca3af;
    cursor: not-allowed;
    opacity: 0.6;
}

.btn-secondary {
    background: #ffffff;
    color: #6b7280;
    border-color: #d1d5db;
}

.btn-secondary:hover {
    background: #f9fafb;
    border-color: #9ca3af;
    color: #374151;
}

/* ================================
   GROUPFOLDER SEARCH STYLES
   ================================*/

/* Groupfolder Search Controls */
.groupfolder-search-controls {
    margin-bottom: 24px;
    padding: 16px;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}

.groupfolder-search-controls .search-input-wrapper {
    position: relative;
    flex: 1;
    min-width: 250px;
}

.groupfolder-search-controls .field-search-input {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    background: #ffffff;
    font-size: 14px;
    box-sizing: border-box;
    transition: border-color 0.2s ease;
}

.groupfolder-search-controls .field-search-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.groupfolder-search-controls .search-results-count {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 12px;
    color: #9ca3af;
    font-weight: 500;
}

.groupfolder-stats {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.total-folders {
    padding: 4px 8px;
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
}

.collapse-all-btn,
.expand-all-btn {
    padding: 6px 12px;
    background: #ffffff;
    border: 1px solid #d1d5db;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    color: #6b7280;
}

.collapse-all-btn:hover,
.expand-all-btn:hover {
    background: #f9fafb;
    border-color: #9ca3af;
    color: #374151;
}

.expand-all-btn {
    background: #f0fdf4;
    color: #16a34a;
    border-color: #bbf7d0;
}

.expand-all-btn:hover {
    background: #dcfce7;
    border-color: #86efac;
}

/* Groupfolder Container */
.groupfolders-container {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

/* Compact Field Configuration - Ultra Clean */
.fields-config-container {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 24px;
    margin: 20px 0;
}

.config-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 1px solid #f3f4f6;
}

.config-header h4 {
    margin: 0;
    font-size: 18px;
    font-weight: 500;
    color: #111827;
}

.field-stats {
    display: flex;
    gap: 12px;
    font-size: 12px;
}

.field-stats span {
    padding: 4px 8px;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 4px;
    color: #6b7280;
    font-weight: 500;
}

.total-fields {
    background: #eff6ff !important;
    color: #1d4ed8 !important;
    border-color: #bfdbfe !important;
}

.assigned-fields {
    background: #f0fdf4 !important;
    color: #16a34a !important;
    border-color: #bbf7d0 !important;
}

/* Search Controls - Minimalist */
.field-search-controls {
    margin-bottom: 24px;
    padding: 16px;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
}

.search-input-wrapper {
    position: relative;
    margin-bottom: 16px;
}

.field-search-input {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    background: #ffffff;
    font-size: 14px;
    box-sizing: border-box;
    transition: border-color 0.2s ease;
}

.field-search-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.search-results-count {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 12px;
    color: #9ca3af;
    font-weight: 500;
}

.filter-buttons {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.filter-btn {
    padding: 6px 12px;
    background: #ffffff;
    border: 1px solid #d1d5db;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    color: #6b7280;
}

.filter-btn:hover {
    background: #f9fafb;
    border-color: #9ca3af;
}

.filter-btn.active {
    background: #3b82f6;
    color: #ffffff;
    border-color: #3b82f6;
}

/* Clean Field Grid */
.field-section {
    margin-bottom: 32px;
}

.section-header {
    margin: 0 0 16px 0;
    padding: 8px 0;
    font-size: 14px;
    font-weight: 600;
    color: #374151;
    border-bottom: 1px solid #e5e7eb;
}

.fields-compact-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 12px;
}

/* Ultra Clean Field Cards */
.field-card.compact {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 16px;
    transition: all 0.2s ease;
    cursor: pointer;
}

.field-card.compact:hover {
    border-color: #9ca3af;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.field-card.compact.assigned {
    border-color: #16a34a;
    background: #f0fdf4;
}

.field-card-content {
    display: flex;
    align-items: flex-start;
    gap: 12px;
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
    accent-color: #3b82f6;
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
    color: #111827;
    line-height: 1.3;
}

.field-details {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.field-name {
    background: #f3f4f6;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 500;
    color: #6b7280;
    font-family: 'SF Mono', Monaco, 'Cascadia Code', monospace;
}

.field-type {
    font-size: 11px;
    color: #9ca3af;
    background: #f9fafb;
    padding: 2px 6px;
    border-radius: 3px;
    font-weight: 400;
}

/* Clean Badges */
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
    background: #dbeafe;
    color: #1d4ed8;
}

.files-badge {
    background: #fce7f3;
    color: #be185d;
}

/* Clean Form Actions */
.form-actions.compact {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-top: 24px;
    padding: 16px;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
}

.form-actions.compact button {
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    border: 1px solid transparent;
}

.form-actions.compact button.primary {
    background: #3b82f6;
    color: white;
    border-color: #3b82f6;
}

.form-actions.compact button.primary:hover {
    background: #2563eb;
    border-color: #2563eb;
}

.form-actions.compact button.secondary {
    background: #ffffff;
    color: #6b7280;
    border-color: #d1d5db;
}

.form-actions.compact button.secondary:hover {
    background: #f9fafb;
    border-color: #9ca3af;
}

.quick-actions {
    display: flex;
    gap: 8px;
}

.quick-btn {
    padding: 6px 12px !important;
    background: #ffffff !important;
    color: #6b7280 !important;
    border: 1px solid #d1d5db !important;
    border-radius: 4px !important;
    font-size: 12px !important;
    font-weight: 500 !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
}

.quick-btn:hover {
    background: #f9fafb !important;
    border-color: #9ca3af !important;
}

/* Clean Groupfolder Styles */
.groupfolder-item {
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    margin-bottom: 24px;
    background: #ffffff;
    overflow: hidden;
}

.groupfolder-header {
    padding: 20px 24px;
    background: #f9fafb;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.groupfolder-header h3 {
    margin: 0;
    color: #111827;
    font-weight: 500;
}

.groupfolder-actions button {
    background: #3b82f6;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 6px;
    cursor: pointer;
    margin-left: 12px;
    font-size: 13px;
    font-weight: 500;
    transition: background-color 0.2s ease;
}

.groupfolder-actions button:hover {
    background: #2563eb;
}

/* Clean Grid Tables */
.grid {
    width: 100%;
    border-collapse: collapse;
    margin-top: 16px;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    overflow: hidden;
}

.grid th,
.grid td {
    padding: 12px 16px;
    text-align: left;
    border-bottom: 1px solid #f3f4f6;
}

.grid th {
    background: #f9fafb;
    font-weight: 500;
    color: #374151;
    font-size: 13px;
}

.grid tbody tr:hover {
    background: #f9fafb;
}

.grid code {
    background: #f3f4f6;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 12px;
    color: #4b5563;
    font-family: 'SF Mono', Monaco, monospace;
}

.grid button {
    margin-right: 8px;
    padding: 6px 12px;
    border: 1px solid #d1d5db;
    background: #ffffff;
    cursor: pointer;
    border-radius: 4px;
    font-weight: 400;
    font-size: 12px;
    transition: all 0.2s ease;
}

.grid button:hover {
    background: #f9fafb;
    border-color: #9ca3af;
}

.edit-gfm-field,
.delete-gfm-field,
.edit-fm-field,
.delete-fm-field {
    padding: 6px 12px;
    margin-right: 8px;
    border: 1px solid #d1d5db;
    background: #ffffff;
    cursor: pointer;
    border-radius: 4px;
    font-weight: 400;
    font-size: 12px;
    transition: all 0.2s ease;
}

.edit-gfm-field,
.edit-fm-field {
    background: #0ea5e9;
    color: white;
    border-color: #0ea5e9;
}

.edit-gfm-field:hover,
.edit-fm-field:hover {
    background: #0284c7;
    border-color: #0284c7;
}

.delete-gfm-field,
.delete-fm-field {
    background: #dc2626;
    color: white;
    border-color: #dc2626;
}

.delete-gfm-field:hover,
.delete-fm-field:hover {
    background: #b91c1c;
    border-color: #b91c1c;
}

/* No Fields Message */
.no-fields-message {
    text-align: center;
    padding: 40px 24px;
    color: #6b7280;
    background: #f9fafb;
    border: 1px dashed #d1d5db;
    border-radius: 6px;
    margin: 16px 0;
}

.no-fields-message p {
    font-size: 14px;
    margin: 8px 0;
}

.no-fields-available {
    text-align: center;
    padding: 40px 24px;
    color: #6b7280;
    background: #f9fafb;
    border: 1px dashed #d1d5db;
    border-radius: 6px;
    margin: 16px 0;
}

.no-fields-available p {
    font-size: 14px;
    margin: 8px 0;
}

/* Clean Responsive Design */
@media (max-width: 768px) {
    #testermeta-admin {
        padding: 16px;
    }
    
    .modal-container {
        margin: 10px;
        width: calc(100% - 20px);
        max-height: 95vh;
    }
    
    .modal-header,
    .modal-body,
    .modal-footer {
        padding: 16px;
    }
    
    .modal-header h3 {
        font-size: 16px;
    }
    
    .groupfolder-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 16px;
    }
    
    .metadata-section {
        padding: 16px;
        margin-bottom: 24px;
    }
    
    .metadata-field input,
    .metadata-field select,
    .metadata-field textarea,
    .edit-field-group input,
    .edit-field-group select,
    .edit-field-group textarea {
        max-width: 100%;
        font-size: 16px;
    }
    
    .checkbox-label {
        padding: 14px !important;
        font-size: 13px !important;
    }
    
    .checkbox-custom {
        width: 18px;
        height: 18px;
    }
    
    .fields-compact-grid {
        grid-template-columns: 1fr;
        gap: 8px;
    }
    
    .form-actions.compact {
        flex-direction: column;
        align-items: stretch;
        gap: 12px;
    }
    
    .filter-buttons {
        gap: 6px;
    }
    
    .filter-btn {
        padding: 6px 10px;
        font-size: 11px;
    }
    
    .tab-button {
        padding: 10px 16px;
        font-size: 13px;
    }
    
    .section-header-with-import {
        flex-direction: column;
        align-items: flex-start;
        gap: 16px;
    }
    
    .import-toggle {
        margin-left: 0;
    }
    
    /* Mobile Groupfolder Search */
    .groupfolder-search-controls {
        flex-direction: column;
        align-items: stretch;
        gap: 12px;
    }
    
    .groupfolder-search-controls .search-input-wrapper {
        min-width: auto;
    }
    
    .groupfolder-stats {
        justify-content: space-between;
        flex-wrap: nowrap;
    }
    
    .total-folders {
        flex: 1;
        text-align: center;
    }
    
    .collapse-all-btn,
    .expand-all-btn {
        flex: 1;
        padding: 8px 12px;
        font-size: 11px;
    }
}

/* Clean Focus States */
*:focus-visible {
    outline: 2px solid #3b82f6 !important;
    outline-offset: 2px !important;
}

/* Animation for checkbox interactions */
@keyframes checkboxPop {
    0% { transform: scale(1); }
    50% { transform: scale(1.1); }
    100% { transform: scale(1.05); }
}

.styled-checkbox:checked + .checkbox-label .checkbox-custom {
    animation: checkboxPop 0.3s ease-in-out;
}
/* Simplified JSON Import Preview */
.json-import-preview {
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
    border: 2px solid #3b82f6;
    border-radius: 8px;
    padding: 24px;
    margin: 20px 0;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
    animation: slideInDown 0.3s ease-out;
}

@keyframes slideInDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.preview-header h3 {
    margin: 0 0 8px 0;
    color: #1e40af;
    font-size: 18px;
    font-weight: 600;
}

.preview-header p {
    margin: 0 0 16px 0;
    color: #1e40af;
    font-size: 14px;
}

.fields-preview {
    max-height: 300px;
    overflow-y: auto;
    margin: 16px 0 0 0;
    padding: 12px;
    background: #ffffff;
    border: 1px solid #bfdbfe;
    border-radius: 6px;
}

.field-preview-item {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    padding: 12px;
    margin: 8px 0;
    transition: all 0.2s ease;
}

.field-preview-item:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
}

.field-preview-item strong {
    color: #1e293b;
    font-size: 14px;
}

.field-preview-item small {
    color: #64748b;
    font-size: 12px;
    line-height: 1.4;
}

/* Floating Sticky Action Buttons - ALWAYS VISIBLE */
.floating-import-actions {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 9999;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-top: 2px solid #e5e7eb;
    box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.1);
    animation: slideInUp 0.3s ease-out;
}

@keyframes slideInUp {
    from {
        opacity: 0;
        transform: translateY(100%);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.floating-actions-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    max-width: 1200px;
    margin: 0 auto;
    padding: 16px 24px;
}

.floating-info {
    font-size: 14px;
    font-weight: 500;
    color: #374151;
    display: flex;
    align-items: center;
    gap: 8px;
}

.floating-info::before {
    content: '📋';
    font-size: 16px;
}

.floating-buttons {
    display: flex;
    gap: 12px;
    align-items: center;
}

.floating-import-btn {
    background: linear-gradient(135deg, #16a34a 0%, #15803d 100%) !important;
    color: white !important;
    padding: 14px 28px !important;
    border: none !important;
    border-radius: 8px !important;
    cursor: pointer !important;
    font-weight: 600 !important;
    font-size: 15px !important;
    box-shadow: 0 4px 12px rgba(22, 163, 74, 0.3) !important;
    transition: all 0.2s ease !important;
    animation: pulse 2s infinite;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    min-width: 180px;
}

.floating-import-btn:hover {
    background: linear-gradient(135deg, #15803d 0%, #166534 100%) !important;
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 16px rgba(22, 163, 74, 0.4) !important;
}

.floating-cancel-btn {
    background: #f9fafb !important;
    color: #6b7280 !important;
    padding: 14px 24px !important;
    border: 1px solid #d1d5db !important;
    border-radius: 8px !important;
    cursor: pointer !important;
    font-weight: 500 !important;
    font-size: 15px !important;
    transition: all 0.2s ease !important;
}

.floating-cancel-btn:hover {
    background: #f3f4f6 !important;
    color: #374151 !important;
    border-color: #9ca3af !important;
}

@keyframes pulse {
    0%, 100% {
        box-shadow: 0 4px 12px rgba(22, 163, 74, 0.3);
    }
    50% {
        box-shadow: 0 4px 12px rgba(22, 163, 74, 0.5);
    }
}

/* Mobile responsive adjustments for floating actions */
@media (max-width: 768px) {
    .floating-actions-content {
        flex-direction: column;
        gap: 12px;
        padding: 16px;
    }
    
    .floating-info {
        font-size: 13px;
        text-align: center;
    }
    
    .floating-buttons {
        width: 100%;
        justify-content: center;
    }
    
    .floating-import-btn,
    .floating-cancel-btn {
        flex: 1;
        min-width: auto;
        padding: 12px 20px !important;
        font-size: 14px !important;
    }
}
<style>