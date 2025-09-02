// TesterMeta Admin JavaScript - Deel 1: Basis Setup en Field Forms
// GLOBAL FIELDS TEMPORARILY HIDDEN - code preserved for future use
console.log('🔧 TesterMeta Admin geladen - Deel 1: Basis Setup');

$(document).ready(function() {
    
    // Current tab state
    let currentTab = 'groupfolder-metadata-fields'; // Changed default tab
    
    // Tab switching
    $('.tab-button').click(function(e) {
        e.preventDefault();
        const tab = $(this).data('tab');
        console.log('🔄 Switching to tab:', tab);
        switchTab(tab);
    });
    
    function switchTab(tab) {
        $('.tab-button').removeClass('active');
        $('.tab-content').removeClass('active');
        
        $('[data-tab="' + tab + '"]').addClass('active');
        $('#' + tab + '-tab').addClass('active');
        
        currentTab = tab;
        
        // Load content based on tab
        switch(tab) {
            case 'groupfolders':
                loadGroupfolders();
                break;
            case 'groupfolder-metadata-fields':
                loadGroupfolderMetadataFields();
                break;
            case 'file-metadata-fields':
                loadFileMetadataFields();
                break;
            /* GLOBAL FIELDS - HIDDEN BUT PRESERVED
            case 'fields':
                // Global fields loading would go here
                break;
            */
        }
    }
    
    // Show/hide options field for groupfolder metadata fields
    $('#gfm-field-type').change(function() {
        const fieldType = $(this).val();
        console.log('🔄 Groupfolder metadata field type changed to:', fieldType);
        if (fieldType === 'select') {
            $('#gfm-field-options-group').show();
        } else {
            $('#gfm-field-options-group').hide();
        }
    });

    // Show/hide options field for file metadata fields
    $('#fm-field-type').change(function() {
        const fieldType = $(this).val();
        console.log('🔄 File metadata field type changed to:', fieldType);
        if (fieldType === 'select') {
            $('#fm-field-options-group').show();
        } else {
            $('#fm-field-options-group').hide();
        }
    });

    // Trigger initial state check for dropdowns
    setTimeout(function() {
        $('#gfm-field-type').trigger('change');
        $('#fm-field-type').trigger('change');
    }, 100);

    // Add new groupfolder metadata field
    $('#new-groupfolder-metadata-field-form').submit(function(e) {
        e.preventDefault();
        
        let fieldName = $('#gfm-field-name').val().trim();
        
        // 🆕 HIDDEN PREFIX: Add 'gf_' prefix internally (hidden from user)
        if (fieldName && !fieldName.startsWith('gf_')) {
            fieldName = 'gf_' + fieldName;
            console.log('🏷️ Internal prefix added (hidden from user):', fieldName);
        }
        
        const formData = {
            field_name: fieldName,
            field_label: $('#gfm-field-label').val(),
            field_type: $('#gfm-field-type').val(),
            field_options: $('#gfm-field-options').val().split('\n').filter(function(opt) { return opt.trim(); }).join('\n'),
            is_required: $('#gfm-is-required').is(':checked') ? 1 : 0,
            sort_order: parseInt($('#gfm-sort-order').val()) || 0,
            applies_to_groupfolder: 1  // Always 1 for groupfolder metadata fields
        };
        
        console.log('📝 Creating groupfolder metadata field:', formData);
        
        $.ajax({
            url: OC.generateUrl('/apps/metavox/api/groupfolder-fields'),
            method: 'POST',
            data: formData,
            success: function(response) {
                console.log('✅ Groupfolder metadata field created:', response);
                if (response.id || response.success) {
                    showMessage('Team folder metadata field added successfully', 'success');
                    $('#new-groupfolder-metadata-field-form')[0].reset();
                    $('#gfm-field-options-group').hide();
                    loadGroupfolderMetadataFields();
                } else {
                    showMessage('Error adding Team folder metadata field', 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ Error creating groupfolder metadata field:', error, xhr.responseText);
                let errorMsg = 'Error adding groupfolder metadata field';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg += ': ' + xhr.responseJSON.message;
                }
                showMessage(errorMsg, 'error');
            }
        });
    });

    // Add new file metadata field
    $('#new-file-metadata-field-form').submit(function(e) {
        e.preventDefault();
        
        const formData = {
            field_name: $('#fm-field-name').val(),
            field_label: $('#fm-field-label').val(),
            field_type: $('#fm-field-type').val(),
            field_options: $('#fm-field-options').val().split('\n').filter(function(opt) { return opt.trim(); }).join('\n'),
            is_required: $('#fm-is-required').is(':checked') ? 1 : 0,
            sort_order: parseInt($('#fm-sort-order').val()) || 0,
            applies_to_groupfolder: 0  // Always 0 for file metadata fields
        };
        
        console.log('📝 Creating file metadata field:', formData);
        
        $.ajax({
            url: OC.generateUrl('/apps/metavox/api/groupfolder-fields'),
            method: 'POST',
            data: formData,
            success: function(response) {
                console.log('✅ File metadata field created:', response);
                if (response.id || response.success) {
                    showMessage('File metadata field added successfully', 'success');
                    $('#new-file-metadata-field-form')[0].reset();
                    $('#fm-field-options-group').hide();
                    loadFileMetadataFields();
                } else {
                    showMessage('Error adding file metadata field', 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ Error creating file metadata field:', error, xhr.responseText);
                let errorMsg = 'Error adding file metadata field';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg += ': ' + xhr.responseJSON.message;
                }
                showMessage(errorMsg, 'error');
            }
        });
    });
    
    // Utility Functions
    function escapeHtml(text) {
        if (!text) return '';
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.toString().replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    function showMessage(message, type) {
        type = type || 'info';
        
        // Remove existing messages
        $('.success-message, .error-message, .info-message').remove();
        
        const messageDiv = $('<div>').addClass(type + '-message').text(message);
        
        $('#testermeta-admin').prepend(messageDiv);
        
        // Auto-remove after 5 seconds
        setTimeout(function() {
            messageDiv.fadeOut(function() {
                messageDiv.remove();
            });
        }, 5000);
        
        // Also use OC notifications if available
        if (typeof OC !== 'undefined' && OC.Notification) {
            if (type === 'error') {
                OC.Notification.showTemporary(message);
            } else if (type === 'success') {
                OC.Notification.showTemporary(message);
            }
        }
    }

    // Auto-load content when page loads
    setTimeout(function() {
        // Check initial tab and load appropriate content
        if ($('.tab-button[data-tab="groupfolders"]').hasClass('active')) {
            loadGroupfolders();
        } else if ($('.tab-button[data-tab="groupfolder-metadata-fields"]').hasClass('active')) {
            loadGroupfolderMetadataFields();
        } else if ($('.tab-button[data-tab="file-metadata-fields"]').hasClass('active')) {
            loadFileMetadataFields();
        }
        
        // Default to first available tab if no active tab
        if (!$('.tab-button.active').length) {
            $('.tab-button').first().click();
        }
    }, 500);

    console.log('🎯 TesterMeta Admin Deel 1 geïnitialiseerd!');
    
    // DEEL 2 VOLGT...
// TesterMeta Admin JavaScript - Deel 2: JSON Import/Export functionaliteit
console.log('🔧 TesterMeta Admin - Deel 2: JSON Import/Export');

// Voeg dit toe aan deel 1, na de form submissions:

    // JSON IMPORT/EXPORT FUNCTIONALITY
    
    // Groupfolder Metadata Fields JSON Import 
    $('#upload-gfm-json-form').submit(function(e) {
        e.preventDefault();
        console.log('📁 Groupfolder metadata fields JSON form submitted');
        
        const file = $('#gfm-json-file')[0].files[0];
        if (!file) {
            showMessage('Please select a JSON file', 'error');
            return;
        }
        
        console.log('📁 Processing groupfolder metadata JSON file:', file.name);
        processJsonFile(file, 'groupfolder-metadata');
    });

    // File Metadata Fields JSON Import 
    $('#upload-fm-json-form').submit(function(e) {
        e.preventDefault();
        console.log('📁 File metadata fields JSON form submitted');
        
        const file = $('#fm-json-file')[0].files[0];
        if (!file) {
            showMessage('Please select a JSON file', 'error');
            return;
        }
        
        console.log('📁 Processing file metadata JSON file:', file.name);
        processJsonFile(file, 'file-metadata');
    });
    
    // Add export buttons dynamically
    function addExportButtons() {
        // Groupfolder metadata fields export
        if ($('#export-gfm-json').length === 0) {
            const gfmExportButton = '<button id="export-gfm-json" type="button" style="margin-left: 10px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; border: none; box-shadow: 0 4px 6px rgba(16, 185, 129, 0.2); padding: 12px 20px; border: 2px solid #e5e7eb; cursor: pointer;">📤 Export GF Metadata</button>';
            $('#upload-gfm-json-form .form-group').append(gfmExportButton);
        }

        // File metadata fields export
        if ($('#export-fm-json').length === 0) {
            const fmExportButton = '<button id="export-fm-json" type="button" style="margin-left: 10px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; border: none; box-shadow: 0 4px 6px rgba(16, 185, 129, 0.2); padding: 12px 20px; border: 2px solid #e5e7eb; cursor: pointer;">📤 Export File Metadata</button>';
            $('#upload-fm-json-form .form-group').append(fmExportButton);
        }
    }
    
    // Add export buttons
    setTimeout(addExportButtons, 100);
    
    // Export handlers
    $(document).on('click', '#export-gfm-json', function() {
        console.log('📤 Exporting groupfolder metadata fields to JSON...');
        exportFieldsToJson('groupfolder-metadata');
    });

    $(document).on('click', '#export-fm-json', function() {
        console.log('📤 Exporting file metadata fields to JSON...');
        exportFieldsToJson('file-metadata');
    });
    
    function processJsonFile(file, type) {
        console.log('📋 Processing JSON file:', file.name, 'Type:', type);
        
        const reader = new FileReader();
        reader.onload = function(e) {
            try {
                const jsonData = JSON.parse(e.target.result);
                console.log('📋 JSON data parsed:', jsonData);
                
                // Validate JSON structure
                if (!Array.isArray(jsonData)) {
                    throw new Error('JSON must be an array of field definitions');
                }
                
                // Show preview
                showJsonImportPreview(jsonData, type);
                
            } catch (error) {
                console.error('❌ JSON parse error:', error);
                showMessage('Invalid JSON file: ' + error.message, 'error');
            }
        };
        
        reader.readAsText(file);
    }
    
    function showJsonImportPreview(jsonData, type) {
        const typeLabels = {
            'global': 'Global Fields',
            'groupfolder-metadata': 'Groupfolder Metadata Fields',
            'file-metadata': 'File Metadata Fields'
        };
        const typeLabel = typeLabels[type] || type;
        
        let previewHTML = '<div class="json-import-preview">' +
            '<div class="preview-header">' +
                '<h3>📋 JSON Import Preview (' + typeLabel + ')</h3>' +
                '<p>Found <strong>' + jsonData.length + '</strong> field(s) to import:</p>' +
            '</div>' +
            '<div class="fields-preview">';
        
        jsonData.forEach((field, index) => {
            previewHTML += '<div class="field-preview-item">' +
                '<strong>' + (field.field_label || field.field_name || 'Unnamed Field') + '</strong>' +
                ' (' + (field.field_type || 'text') + ')' +
                '<br><small>Name: ' + (field.field_name || 'field_' + index) + '</small>' +
                (field.field_options ? '<br><small>Options: ' + field.field_options.split('\n').length + ' options</small>' : '') +
            '</div>';
        });
        
        previewHTML += '</div>' +
        '</div>';
        
        // Show preview - insert after the import toggle
        const targetSelectors = {
            'global': '.section-header-with-import',
            'groupfolder-metadata': '#groupfolder-metadata-fields-tab .section-header-with-import',
            'file-metadata': '#file-metadata-fields-tab .section-header-with-import'
        };
        const targetElement = $(targetSelectors[type]);
        targetElement.after(previewHTML);
        
        // Add sticky floating action buttons
        const floatingActionsHTML = '<div class="floating-import-actions">' +
            '<div class="floating-actions-content">' +
                '<span class="floating-info">Ready to import ' + jsonData.length + ' fields</span>' +
                '<div class="floating-buttons">' +
                    '<button id="cancel-json-import" class="floating-cancel-btn">❌ Cancel</button>' +
                    '<button id="confirm-json-import" class="floating-import-btn" data-type="' + type + '">✅ Import ' + jsonData.length + ' Fields</button>' +
                '</div>' +
            '</div>' +
        '</div>';
        
        $('body').append(floatingActionsHTML);
        
        // Store data for import
        window.pendingJsonImport = { data: jsonData, type: type };
        
        // Bind actions
        $('#confirm-json-import').click(function() {
            const importType = $(this).data('type');
            importJsonFields(window.pendingJsonImport.data, importType);
        });
        
        $('#cancel-json-import').click(function() {
            cancelJsonImport();
        });
        
        // Close the import dropdown
        $('.import-toggle[open]').removeAttr('open');
        
        // Scroll to preview
        $('.json-import-preview')[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    
    function importJsonFields(jsonData, type) {
        console.log('📦 Starting JSON import of', jsonData.length, 'fields (type:', type, ')...');
        
        let importedCount = 0;
        let errorCount = 0;
        const results = [];
        
        // Update UI
        $('#confirm-json-import').text('Importing...').prop('disabled', true);
        
        // Determine API endpoint and applies_to_groupfolder value
        let apiEndpoint, appliesToGroupfolder;
        
        switch(type) {
            case 'global':
                apiEndpoint = '/apps/metavox/api/fields';
                appliesToGroupfolder = null; // Not applicable
                break;
            case 'groupfolder-metadata':
                apiEndpoint = '/apps/metavox/api/groupfolder-fields';
                appliesToGroupfolder = 1;
                break;
            case 'file-metadata':
                apiEndpoint = '/apps/metavox/api/groupfolder-fields';
                appliesToGroupfolder = 0;
                break;
        }
        
        // Process each field
        const processField = (index) => {
            if (index >= jsonData.length) {
                // All done
                console.log('✅ JSON import completed:', {
                    imported: importedCount,
                    errors: errorCount,
                    total: jsonData.length,
                    type: type
                });
                
                showMessage(`${type} fields import completed: ${importedCount} imported, ${errorCount} errors`, 
                    errorCount === 0 ? 'success' : 'info');
                
                cancelJsonImport();
                
                // Reload appropriate content
                switch(type) {
                    case 'global':
                        setTimeout(() => location.reload(), 1000);
                        break;
                    case 'groupfolder-metadata':
                        setTimeout(() => loadGroupfolderMetadataFields(), 1000);
                        break;
                    case 'file-metadata':
                        setTimeout(() => loadFileMetadataFields(), 1000);
                        break;
                }
                return;
            }
            
            const field = jsonData[index];
            
            // Prepare field data with defaults
            let fieldName = field.field_name || 'imported_field_' + index;
            
            // 🆕 ADD HIDDEN PREFIXES FOR JSON IMPORT
            if (type === 'groupfolder-metadata' && !fieldName.startsWith('gf_')) {
                fieldName = 'gf_' + fieldName;
                console.log('🏷️ JSON import: Added gf_ prefix to', field.field_name, '→', fieldName);
            } else if (type === 'file-metadata' && !fieldName.startsWith('file_')) {
                fieldName = 'file_' + fieldName;
                console.log('🏷️ JSON import: Added file_ prefix to', field.field_name, '→', fieldName);
            }
            
            const fieldData = {
                field_name: fieldName,
                field_label: field.field_label || field.field_name || 'Imported Field ' + (index + 1),
                field_type: field.field_type || 'text',
                field_options: field.field_options || '',
                is_required: field.is_required ? 1 : 0,
                sort_order: field.sort_order || (index + 1) * 10
            };
            
            // Add applies_to_groupfolder for groupfolder fields
            if (appliesToGroupfolder !== null) {
                fieldData.applies_to_groupfolder = appliesToGroupfolder;
            }
            
            console.log('📝 Importing field', index + 1, ':', fieldData);
            
            $.ajax({
                url: OC.generateUrl(apiEndpoint),
                method: 'POST',
                data: fieldData,
                success: function(response) {
                    console.log('✅ Field imported:', response);
                    if (response.id || response.success) {
                        importedCount++;
                        results.push({ field: fieldData, status: 'success' });
                    } else {
                        errorCount++;
                        results.push({ field: fieldData, status: 'error', message: 'Unknown error' });
                    }
                    
                    // Process next field
                    setTimeout(() => processField(index + 1), 100);
                },
                error: function(xhr, status, error) {
                    console.error('❌ Error importing field:', error, xhr.responseText);
                    errorCount++;
                    
                    let errorMsg = error;
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMsg = xhr.responseJSON.message;
                    }
                    
                    results.push({ field: fieldData, status: 'error', message: errorMsg });
                    
                    // Process next field
                    setTimeout(() => processField(index + 1), 100);
                }
            });
        };
        
        // Start processing
        processField(0);
    }
    
    function exportFieldsToJson(type) {
        console.log('📤 Exporting', type, 'fields to JSON...');
        
        // Determine API endpoint and filter
        let apiEndpoint, filterFn, filename;
        
        switch(type) {
            case 'global':
                apiEndpoint = '/apps/metavox/api/fields';
                filterFn = () => true; // Export all global fields
                filename = 'testermeta_global_fields_';
                break;
            case 'groupfolder-metadata':
                apiEndpoint = '/apps/metavox/api/groupfolder-fields';
                filterFn = (field) => field.applies_to_groupfolder === 1 || field.applies_to_groupfolder === '1';
                filename = 'testermeta_groupfolder_metadata_fields_';
                break;
            case 'file-metadata':
                apiEndpoint = '/apps/metavox/api/groupfolder-fields';
                filterFn = (field) => field.applies_to_groupfolder === 0 || field.applies_to_groupfolder === '0' || !field.applies_to_groupfolder;
                filename = 'testermeta_file_metadata_fields_';
                break;
        }
        
        $.ajax({
            url: OC.generateUrl(apiEndpoint),
            method: 'GET',
            success: function(fields) {
                console.log('✅ Fields loaded for export:', fields);
                
                // Filter fields based on type
                const filteredFields = fields.filter(filterFn);
                console.log('✅ Filtered fields for export:', filteredFields);
                
                // Clean up fields for export (remove IDs and internal data)
                const exportData = filteredFields.map(field => {
                    // 🆕 REMOVE HIDDEN PREFIXES FROM EXPORT
                    let cleanFieldName = field.field_name;
                    if (type === 'groupfolder-metadata' && cleanFieldName.startsWith('gf_')) {
                        cleanFieldName = cleanFieldName.substring(3);
                        console.log('🏷️ Export: Removed gf_ prefix from', field.field_name, '→', cleanFieldName);
                    } else if (type === 'file-metadata' && cleanFieldName.startsWith('file_')) {
                        cleanFieldName = cleanFieldName.substring(5);
                        console.log('🏷️ Export: Removed file_ prefix from', field.field_name, '→', cleanFieldName);
                    }
                    
                    const cleanField = {
                        field_name: cleanFieldName,
                        field_label: field.field_label,
                        field_type: field.field_type,
                        field_options: field.field_options || '',
                        is_required: field.is_required ? true : false,
                        sort_order: field.sort_order || 0
                    };
                    
                    return cleanField;
                });
                
                // Create download
                const dataStr = JSON.stringify(exportData, null, 2);
                const dataBlob = new Blob([dataStr], { type: 'application/json' });
                
                const downloadLink = document.createElement('a');
                downloadLink.href = URL.createObjectURL(dataBlob);
                downloadLink.download = filename + new Date().toISOString().split('T')[0] + '.json';
                
                document.body.appendChild(downloadLink);
                downloadLink.click();
                document.body.removeChild(downloadLink);
                
                showMessage(type + ' fields exported to JSON file', 'success');
            },
            error: function(xhr, status, error) {
                console.error('❌ Error loading fields for export:', error);
                showMessage('Error exporting ' + type + ' fields: ' + error, 'error');
            }
        });
    }
    
    function cancelJsonImport() {
        $('.json-import-preview').remove();
        $('.floating-import-actions').remove();
        $('#json-file').val(''); // Clear global file input
        $('#gfm-json-file').val(''); // Clear groupfolder metadata file input
        $('#fm-json-file').val(''); // Clear file metadata file input
        window.pendingJsonImport = null;
    }

    console.log('🎯 TesterMeta Admin Deel 2 geïnitialiseerd!');
    
// TesterMeta Admin JavaScript - Deel 3: Edit Functionaliteit en Field Lists
console.log('🔧 TesterMeta Admin - Deel 3: Edit en Field Lists');

    // Delete handlers
    $(document).on('click', '.delete-gfm-field', function() {
        const fieldId = $(this).data('field-id');
        if (confirm('Are you sure you want to delete this team folder metadata field?')) {
            deleteGroupfolderField(fieldId, 'groupfolder-metadata');
        }
    });

    $(document).on('click', '.delete-fm-field', function() {
        const fieldId = $(this).data('field-id');
        if (confirm('Are you sure you want to delete this file metadata field?')) {
            deleteGroupfolderField(fieldId, 'file-metadata');
        }
    });
    
    // =============================
    // 🆕 EDIT FUNCTIONALITY - NEW!
    // =============================
    
    // Edit groupfolder metadata field
    $(document).on('click', '.edit-gfm-field', function() {
        const fieldId = $(this).data('field-id');
        console.log('✏️ Edit groupfolder metadata field:', fieldId);
        openEditModal(fieldId, 'groupfolder-metadata');
    });

    // Edit file metadata field
    $(document).on('click', '.edit-fm-field', function() {
        const fieldId = $(this).data('field-id');
        console.log('✏️ Edit file metadata field:', fieldId);
        openEditModal(fieldId, 'file-metadata');
    });

    // Function to open edit modal
    function openEditModal(fieldId, type) {
        console.log('🔄 Opening edit modal for field:', fieldId, 'type:', type);
        
        // First, get the field data
        $.ajax({
            url: OC.generateUrl('/apps/metavox/api/fields/' + fieldId),
            method: 'GET',
            success: function(field) {
                console.log('✅ Field data loaded for editing:', field);
                showEditModal(field, type);
            },
            error: function(xhr, status, error) {
                console.error('❌ Error loading field for editing:', error, xhr.responseText);
                showMessage('Error loading field data: ' + error, 'error');
            }
        });
    }

    // Function to show edit modal
    function showEditModal(field, type) {
        console.log('🖼️ Showing edit modal for field:', field);
        
        // Remove any existing modal
        $('#edit-field-modal').remove();
        
        // 🆕 HIDE PREFIX: Remove prefix from display name
        let displayName = field.field_name;
        if (type === 'groupfolder-metadata' && displayName.startsWith('gf_')) {
            displayName = displayName.substring(3);
        } else if (type === 'file-metadata' && displayName.startsWith('file_')) {
            displayName = displayName.substring(5);
        }
        
        const typeLabel = type === 'groupfolder-metadata' ? 'Team folder Metadata' : 'File Metadata';
        const fieldTypeOptions = [
            'text', 'textarea', 'select', 'number', 'date', 'checkbox'
        ];
        
        let optionsHtml = '';
        fieldTypeOptions.forEach(optionType => {
            const selected = field.field_type === optionType ? 'selected' : '';
            optionsHtml += `<option value="${optionType}" ${selected}>${optionType.charAt(0).toUpperCase() + optionType.slice(1)}</option>`;
        });
        
        // 🆕 FIX: Properly format field options with newlines instead of commas
        let formattedOptions = '';
        if (field.field_options) {
            if (Array.isArray(field.field_options)) {
                formattedOptions = field.field_options.join('\n');
            } else if (typeof field.field_options === 'string') {
                // Handle both comma-separated and newline-separated options
                formattedOptions = field.field_options.replace(/,\s*/g, '\n');
            }
        }
        
        const modalHtml = `
            <div id="edit-field-modal" class="modal-overlay">
                <div class="modal-container">
                    <div class="modal-header">
                        <h3>✏️ Edit ${typeLabel} Field</h3>
                        <button type="button" class="modal-close" id="modal-close-btn">×</button>
                    </div>
                    <form id="edit-field-form" data-field-id="${field.id}" data-field-type="${type}">
                        <div class="modal-body">
                            <div class="edit-field-group">
                                <label for="edit-field-name">Field Name <span class="required">*</span></label>
                                <input type="text" id="edit-field-name" name="field_name" value="${escapeHtml(displayName)}" required 
                                       placeholder="e.g., project_status">
                                <small>Internal name (no spaces, lowercase)</small>
                            </div>
                            
                            <div class="edit-field-group">
                                <label for="edit-field-label">Field Label <span class="required">*</span></label>
                                <input type="text" id="edit-field-label" name="field_label" value="${escapeHtml(field.field_label)}" required 
                                       placeholder="e.g., Project Status">
                                <small>Display name shown to users</small>
                            </div>
                            
                            <div class="edit-field-group">
                                <label for="edit-field-type">Field Type</label>
                                <select id="edit-field-type" name="field_type" class="edit-dropdown-fix">
                                    ${optionsHtml}
                                </select>
                            </div>
                            
                            <div class="edit-field-group" id="edit-field-options-group" style="display: ${field.field_type === 'select' ? 'block' : 'none'};">
                                <label for="edit-field-options">Options</label>
                                <textarea id="edit-field-options" name="field_options" rows="4" 
                                          placeholder="One option per line">${escapeHtml(formattedOptions)}</textarea>
                                <small>For select fields: enter one option per line</small>
                            </div>
                            
                            <div class="edit-field-group">
                                <div class="edit-checkbox-wrapper">
                                    <input type="checkbox" id="edit-is-required" name="is_required" ${field.is_required ? 'checked' : ''}>
                                    <label for="edit-is-required">Required Field</label>
                                </div>
                            </div>
                            
                            <div class="edit-field-group">
                                <label for="edit-sort-order">Sort Order</label>
                                <input type="number" id="edit-sort-order" name="sort_order" value="${field.sort_order || 0}" min="0">
                                <small>Lower numbers appear first</small>
                            </div>
                        </div>
                        
                        <div class="modal-footer">
                            <button type="button" class="btn-secondary" id="modal-cancel-btn">Cancel</button>
                            <button type="submit" class="btn-primary">💾 Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        `;
        
        $('body').append(modalHtml);
        
        // 🆕 FIX: Proper event binding for close buttons
        $('#modal-close-btn, #modal-cancel-btn').on('click', function(e) {
            e.preventDefault();
            closeEditModal();
        });
        
        // Close modal when clicking outside
        $('#edit-field-modal').on('click', function(e) {
            if (e.target === this) {
                closeEditModal();
            }
        });
        
        // Close modal with Escape key
        $(document).on('keydown.editModal', function(e) {
            if (e.keyCode === 27) { // Escape key
                closeEditModal();
            }
        });
        
        // Bind field type change handler for modal
        $('#edit-field-type').change(function() {
            const fieldType = $(this).val();
            if (fieldType === 'select') {
                $('#edit-field-options-group').show();
            } else {
                $('#edit-field-options-group').hide();
            }
        });
        
        // Bind form submission
        $('#edit-field-form').submit(function(e) {
            e.preventDefault();
            saveEditedField();
        });
        
        // Show modal with animation
        $('#edit-field-modal').fadeIn(200);
        $('#edit-field-name').focus();
    }

    // 🆕 FIX: Improved close function
    function closeEditModal() {
        // Remove keydown event listener
        $(document).off('keydown.editModal');
        
        $('#edit-field-modal').fadeOut(200, function() {
            $(this).remove();
        });
    }

    // Function to save edited field
    function saveEditedField() {
        const form = $('#edit-field-form');
        const fieldId = form.data('field-id');
        const fieldType = form.data('field-type');
        
        let fieldName = $('#edit-field-name').val().trim();
        
        // 🆕 ADD HIDDEN PREFIX: Add prefix back for internal storage
        if (fieldType === 'groupfolder-metadata' && fieldName && !fieldName.startsWith('gf_')) {
            fieldName = 'gf_' + fieldName;
            console.log('🏷️ Edit: Added gf_ prefix back to', $('#edit-field-name').val(), '→', fieldName);
        } else if (fieldType === 'file-metadata' && fieldName && !fieldName.startsWith('file_')) {
            fieldName = 'file_' + fieldName;
            console.log('🏷️ Edit: Added file_ prefix back to', $('#edit-field-name').val(), '→', fieldName);
        }
        
        const formData = {
            field_name: fieldName,
            field_label: $('#edit-field-label').val(),
            field_type: $('#edit-field-type').val(),
            field_options: $('#edit-field-options').val().split('\n').filter(function(opt) { return opt.trim(); }).join('\n'),
            is_required: $('#edit-is-required').is(':checked') ? 1 : 0,
            sort_order: parseInt($('#edit-sort-order').val()) || 0,
            applies_to_groupfolder: fieldType === 'groupfolder-metadata' ? 1 : 0
        };
        
        console.log('💾 Saving edited field:', { fieldId: fieldId, formData: formData });
        
        // Show saving state
        const saveButton = form.find('button[type="submit"]');
        const originalText = saveButton.text();
        saveButton.text('Saving...').prop('disabled', true);
        
        $.ajax({
            url: OC.generateUrl('/apps/metavox/api/fields/' + fieldId),
            method: 'PUT',
            data: formData,
            success: function(response) {
                console.log('✅ Field updated successfully:', response);
                if (response.success) {
                    showMessage('Field updated successfully', 'success');
                    closeEditModal();
                    
                    // Reload the appropriate field list
                    if (fieldType === 'groupfolder-metadata') {
                        loadGroupfolderMetadataFields();
                    } else if (fieldType === 'file-metadata') {
                        loadFileMetadataFields();
                    }
                } else {
                    showMessage('Error updating field: ' + (response.message || 'Unknown error'), 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ Error updating field:', error, xhr.responseText);
                let errorMsg = 'Error updating field';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg += ': ' + xhr.responseJSON.message;
                }
                showMessage(errorMsg, 'error');
            },
            complete: function() {
                saveButton.text(originalText).prop('disabled', false);
            }
        });
    }
    
    // Load functions for different field types
    function loadGroupfolderMetadataFields() {
        console.log('📋 Loading groupfolder metadata fields...');
        
        $.ajax({
            url: OC.generateUrl('/apps/metavox/api/groupfolder-fields'),
            method: 'GET',
            success: function(response) {
                console.log('✅ All groupfolder fields loaded:', response);
                // Filter for groupfolder metadata fields (applies_to_groupfolder = 1)
                const metadataFields = response.filter(field => 
                    field.applies_to_groupfolder === 1 || field.applies_to_groupfolder === '1'
                );
                console.log('✅ Groupfolder metadata fields filtered:', metadataFields);
                renderGroupfolderMetadataFieldsList(metadataFields);
            },
            error: function(xhr, status, error) {
                console.error('❌ Error loading groupfolder metadata fields:', error, xhr.responseText);
                $('#groupfolder-metadata-fields-list').html('<div class="error-message">Error loading fields: ' + error + '</div>');
            }
        });
    }

    function loadFileMetadataFields() {
        console.log('📋 Loading file metadata fields...');
        
        $.ajax({
            url: OC.generateUrl('/apps/metavox/api/groupfolder-fields'),
            method: 'GET',
            success: function(response) {
                console.log('✅ All groupfolder fields loaded:', response);
                // Filter for file metadata fields (applies_to_groupfolder = 0 or null)
                const fileFields = response.filter(field => 
                    field.applies_to_groupfolder === 0 || field.applies_to_groupfolder === '0' || !field.applies_to_groupfolder
                );
                console.log('✅ File metadata fields filtered:', fileFields);
                renderFileMetadataFieldsList(fileFields);
            },
            error: function(xhr, status, error) {
                console.error('❌ Error loading file metadata fields:', error, xhr.responseText);
                $('#file-metadata-fields-list').html('<div class="error-message">Error loading fields: ' + error + '</div>');
            }
        });
    }
    
    function renderGroupfolderMetadataFieldsList(fields) {
        const container = $('#groupfolder-metadata-fields-list');
        
        if (!fields || fields.length === 0) {
            container.html('<div class="no-fields-message">' +
                '<p>📁 No Team folder metadata fields created yet.</p>' +
                '<p>These fields apply to the Team folder itself and are managed by administrators.</p>' +
                '<p>Create your first Team folder metadata field using the form above.</p>' +
            '</div>');
            return;
        }
        
        let html = '<table class="grid">' +
                '<thead>' +
                    '<tr>' +
                        '<th>Label</th>' +
                        '<th>Name</th>' +
                        '<th>Type</th>' +
                        '<th>Required</th>' +
                        '<th>Sort Order</th>' +
                        '<th>Actions</th>' +
                    '</tr>' +
                '</thead>' +
                '<tbody>';
        
        fields.forEach(function(field) {
            // 🆕 HIDE PREFIX: Remove 'gf_' prefix from display
            const displayName = field.field_name.startsWith('gf_') ? 
                field.field_name.substring(3) : field.field_name;
            
            html += '<tr data-field-id="' + field.id + '" data-field-name="' + field.field_name + '">' +
                    '<td>' + escapeHtml(field.field_label) + ' <span class="applies-to-badge groupfolder-badge">📁 Groupfolder</span></td>' +
                    '<td><code>' + escapeHtml(displayName) + '</code></td>' +
                    '<td>' + field.field_type + '</td>' +
                    '<td>' + (field.is_required ? 'Yes' : 'No') + '</td>' +
                    '<td>' + field.sort_order + '</td>' +
                    '<td>' +
                        '<button class="edit-gfm-field" data-field-id="' + field.id + '">Edit</button>' +
                        '<button class="delete-gfm-field" data-field-id="' + field.id + '">Delete</button>' +
                    '</td>' +
                '</tr>';
        });
        
        html += '</tbody></table>';
        container.html(html);
    }

    function renderFileMetadataFieldsList(fields) {
        const container = $('#file-metadata-fields-list');
        
        if (!fields || fields.length === 0) {
            container.html('<div class="no-fields-message">' +
                '<p>📄 No file metadata fields created yet.</p>' +
                '<p>These fields apply to individual files within Team folders.</p>' +
                '<p>Create your first file metadata field using the form above.</p>' +
            '</div>');
            return;
        }
        
        let html = '<table class="grid">' +
                '<thead>' +
                    '<tr>' +
                        '<th>Label</th>' +
                        '<th>Name</th>' +
                        '<th>Type</th>' +
                        '<th>Required</th>' +
                        '<th>Sort Order</th>' +
                        '<th>Actions</th>' +
                    '</tr>' +
                '</thead>' +
                '<tbody>';
        
        fields.forEach(function(field) {
            // 🆕 HIDE PREFIX: Remove 'file_' prefix from display  
            const displayName = field.field_name.startsWith('file_') ? 
                field.field_name.substring(5) : field.field_name;
            
            html += '<tr data-field-id="' + field.id + '" data-field-name="' + field.field_name + '">' +
                    '<td>' + escapeHtml(field.field_label) + ' <span class="applies-to-badge files-badge">📄 Files</span></td>' +
                    '<td><code>' + escapeHtml(displayName) + '</code></td>' +
                    '<td>' + field.field_type + '</td>' +
                    '<td>' + (field.is_required ? 'Yes' : 'No') + '</td>' +
                    '<td>' + field.sort_order + '</td>' +
                    '<td>' +
                        '<button class="edit-fm-field" data-field-id="' + field.id + '">Edit</button>' +
                        '<button class="delete-fm-field" data-field-id="' + field.id + '">Delete</button>' +
                    '</td>' +
                '</tr>';
        });
        
        html += '</tbody></table>';
        container.html(html);
    }
    
    function deleteGroupfolderField(fieldId, type) {
        console.log('🗑️ Deleting', type, 'field:', fieldId);
        
        $.ajax({
            url: OC.generateUrl('/apps/metavox/api/fields/' + fieldId),
            method: 'DELETE',
            success: function(response) {
                console.log('✅', type, 'field deleted:', response);
                if (response.success) {
                    showMessage(type.replace('-', ' ') + ' field deleted successfully', 'success');
                    // Reload the appropriate field list
                    if (type === 'groupfolder-metadata') {
                        loadGroupfolderMetadataFields();
                    } else if (type === 'file-metadata') {
                        loadFileMetadataFields();
                    }
                } else {
                    showMessage('Error deleting ' + type.replace('-', ' ') + ' field: ' + (response.message || 'Unknown error'), 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ Error deleting', type, 'field:', error, xhr.responseText);
                let errorMsg = 'Error deleting ' + type.replace('-', ' ') + ' field';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg += ': ' + xhr.responseJSON.message;
                } else if (xhr.responseJSON && xhr.responseJSON.error) {
                    errorMsg += ': ' + xhr.responseJSON.error;
                }
                showMessage(errorMsg, 'error');
            }
        });
    }

    console.log('🎯 TesterMeta Admin Deel 3 geïnitialiseerd!');
    
    // DEEL 4 VOLGT...
// TesterMeta Admin JavaScript - Deel 4: COMPLEET met alle Metadata functies
console.log('🔧 TesterMeta Admin - Deel 4: Compleet met Metadata Editor');

// Voeg dit toe na deel 3 om het bestand compleet te maken:

    // Groupfolder functionality - IMPROVED with search and compact layout
    function loadGroupfolders() {
        console.log('📁 Loading groupfolders...');
        
        const container = $('#groupfolders-list');
        container.html('<div class="loading">Loading groupfolders...</div>');
        
        $.ajax({
            url: OC.generateUrl('/apps/metavox/api/groupfolders'),
            method: 'GET',
            success: function(response) {
                console.log('✅ Groupfolders loaded:', response);
                renderGroupfolders(response);
            },
            error: function(xhr, status, error) {
                console.error('❌ Error loading groupfolders:', error, xhr.responseText);
                let errorMsg = 'Error loading groupfolders';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg += ': ' + xhr.responseJSON.message;
                } else {
                    errorMsg += ': ' + error;
                }
                container.html('<div class="error-message">' + errorMsg + '</div>');
            }
        });
    }
    
    // 🆕 ENHANCED: Groupfolders with search and filter functionality
    function renderGroupfolders(groupfolders) {
        const container = $('#groupfolders-list');
        container.empty();
        
        if (!groupfolders || groupfolders.length === 0) {
            container.html('<div class="no-fields-message"><p>No Team folders found.</p><p>Make sure the Team folders app is installed and you have created some groupfolders.</p></div>');
            return;
        }
        
        // Add search and filter controls
        const searchControlsHtml = '<div class="groupfolder-search-controls">' +
            '<div class="search-input-wrapper">' +
                '<input type="text" id="groupfolder-search" placeholder="🔍 Search Team folders..." class="field-search-input">' +
                '<div class="search-results-count" id="groupfolder-search-count"></div>' +
            '</div>' +
            '<div class="groupfolder-stats">' +
                '<span class="total-folders">📁 Total: ' + groupfolders.length + '</span>' +
                '<button type="button" class="collapse-all-btn" id="collapse-all-groupfolders">📄 Collapse All</button>' +
                '<button type="button" class="expand-all-btn" id="expand-all-groupfolders">📂 Expand All</button>' +
            '</div>' +
        '</div>';
        
        container.append(searchControlsHtml);
        
        const groupfoldersContainer = $('<div class="groupfolders-container"></div>');
        container.append(groupfoldersContainer);
        
        groupfolders.forEach(function(groupfolder) {
            const groupfolderHtml = '<div class="groupfolder-item" ' +
                'data-groupfolder-id="' + groupfolder.id + '" ' +
                'data-search-text="' + escapeHtml(groupfolder.mount_point.toLowerCase()) + '">' +
                '<div class="groupfolder-header">' +
                    '<h3>📁 ' + escapeHtml(groupfolder.mount_point) + '</h3>' +
                    '<div class="groupfolder-actions">' +
                        '<button class="configure-fields" data-groupfolder-id="' + groupfolder.id + '">⚙️ Configure Fields</button>' +
                        '<button class="edit-metadata" data-groupfolder-id="' + groupfolder.id + '">✏️ Edit Metadata</button>' +
                    '</div>' +
                '</div>' +
                '<div class="metadata-form" id="metadata-form-' + groupfolder.id + '">' +
                    '<div class="loading">Loading metadata...</div>' +
                '</div>' +
                '<div class="groupfolder-fields-config" id="fields-config-' + groupfolder.id + '" style="display: none;"></div>' +
            '</div>';
            
            groupfoldersContainer.append(groupfolderHtml);
        });
        
        // Bind search functionality
        const searchInput = $('#groupfolder-search');
        const searchCount = $('#groupfolder-search-count');
        
        function updateGroupfolderSearchResults() {
            const searchTerm = searchInput.val().toLowerCase().trim();
            let visibleCount = 0;
            
            $('.groupfolder-item').each(function() {
                const item = $(this);
                const searchText = item.data('search-text') || '';
                
                if (!searchTerm || searchText.includes(searchTerm)) {
                    item.show();
                    visibleCount++;
                } else {
                    item.hide();
                    // Close any open panels when hiding
                    item.find('.metadata-form').removeClass('active');
                    item.find('.groupfolder-fields-config').hide();
                }
            });
            
            // Update search count
            if (searchTerm) {
                searchCount.text(visibleCount + ' of ' + groupfolders.length + ' found');
            } else {
                searchCount.text('');
            }
        }
        
        // Bind search event
        searchInput.on('input', updateGroupfolderSearchResults);
        
        // Bind collapse/expand buttons
        $('#collapse-all-groupfolders').on('click', function() {
            $('.metadata-form').removeClass('active');
            $('.groupfolder-fields-config').hide();
            console.log('📄 All groupfolder panels collapsed');
        });
        
        $('#expand-all-groupfolders').on('click', function() {
            // Show the first visible groupfolder's metadata editor
            const firstVisible = $('.groupfolder-item:visible').first();
            if (firstVisible.length) {
                const firstId = firstVisible.data('groupfolder-id');
                toggleMetadataEditor(firstId);
                console.log('📂 Opened first visible groupfolder:', firstId);
            }
        });
        
        // Bind events for groupfolder actions
        $(document).off('click', '.configure-fields').on('click', '.configure-fields', function() {
            const groupfolderId = $(this).data('groupfolder-id');
            console.log('⚙️ Configure fields for groupfolder:', groupfolderId);
            toggleFieldsConfiguration(groupfolderId);
        });
        
        $(document).off('click', '.edit-metadata').on('click', '.edit-metadata', function() {
            const groupfolderId = $(this).data('groupfolder-id');
            console.log('✏️ Edit metadata for groupfolder:', groupfolderId);
            toggleMetadataEditor(groupfolderId);
        });
        
        console.log('✅ Groupfolders rendered with search functionality for', groupfolders.length, 'folders');
    }
    
    function toggleFieldsConfiguration(groupfolderId) {
        const configDiv = $('#fields-config-' + groupfolderId);
        
        // Close all other config panels
        $('.groupfolder-fields-config').not('#fields-config-' + groupfolderId).hide();
        $('.metadata-form').removeClass('active');
        
        if (configDiv.is(':visible')) {
            configDiv.hide();
            return;
        }
        
        console.log('📋 Loading fields configuration for groupfolder:', groupfolderId);
        
        // Load available fields and current configuration
        $.when(
            $.ajax({
                url: OC.generateUrl('/apps/metavox/api/groupfolder-fields'),
                method: 'GET'
            }),
            $.ajax({
                url: OC.generateUrl('/apps/metavox/api/groupfolders/' + groupfolderId + '/fields'),
                method: 'GET'
            })
        ).done(function(fieldsResponse, assignedFieldsResponse) {
            const allFields = fieldsResponse[0];
            const assignedFieldIds = assignedFieldsResponse[0];
            
            console.log('✅ Fields config data loaded:', { allFields: allFields, assignedFieldIds: assignedFieldIds });
            
            renderFieldsConfiguration(groupfolderId, allFields, assignedFieldIds);
            configDiv.show();
        }).fail(function(xhr1, status1, error1) {
            console.error('❌ Error loading fields configuration:', error1);
            showMessage('Error loading fields configuration', 'error');
        });
    }
    
    // IMPROVED: Compact field configuration with search functionality
    function renderFieldsConfiguration(groupfolderId, allFields, assignedFieldIds) {
        const configDiv = $('#fields-config-' + groupfolderId);
        
        let html = '<div class="fields-config-container compact">' +
            '<div class="config-header">' +
                '<h4>⚙️ Configure Fields for this Team folder</h4>' +
                '<div class="field-stats">' +
                    '<span class="total-fields">Total: ' + allFields.length + '</span>' +
                    '<span class="assigned-fields">Assigned: ' + assignedFieldIds.length + '</span>' +
                '</div>' +
            '</div>';
        
        if (allFields.length === 0) {
            html += '<div class="no-fields-available">' +
                '<p>📝 No fields available. Create some fields first in the field management tabs.</p>' +
            '</div>';
        } else {
            // Search and filter controls
            html += '<div class="field-search-controls">' +
                '<div class="search-input-wrapper">' +
                    '<input type="text" id="field-search-' + groupfolderId + '" placeholder="🔍 Search fields..." class="field-search-input">' +
                    '<div class="search-results-count" id="search-count-' + groupfolderId + '"></div>' +
                '</div>' +
                '<div class="filter-buttons">' +
                    '<button type="button" class="filter-btn active" data-filter="all">All (' + allFields.length + ')</button>' +
                    '<button type="button" class="filter-btn" data-filter="assigned">✅ Assigned (' + assignedFieldIds.length + ')</button>' +
                    '<button type="button" class="filter-btn" data-filter="unassigned">⭕ Unassigned (' + (allFields.length - assignedFieldIds.length) + ')</button>' +
                    '<button type="button" class="filter-btn" data-filter="groupfolder">📁 Team folder</button>' +
                    '<button type="button" class="filter-btn" data-filter="file">📄 Files</button>' +
                '</div>' +
            '</div>';
            
            html += '<form class="fields-config-form compact-form" data-groupfolder-id="' + groupfolderId + '">' +
                '<div class="fields-grid compact" id="fields-grid-' + groupfolderId + '">';
            
            // Group fields by type for better organization
            const groupfolderMetadataFields = allFields.filter(field => 
                field.applies_to_groupfolder === 1 || field.applies_to_groupfolder === '1'
            );
            const fileMetadataFields = allFields.filter(field => 
                field.applies_to_groupfolder === 0 || field.applies_to_groupfolder === '0' || !field.applies_to_groupfolder
            );

            // Render groupfolder metadata fields
            if (groupfolderMetadataFields.length > 0) {
                html += '<div class="field-section" data-section="groupfolder">' +
                    '<h5 class="section-header">📁 Team folder Metadata Fields (' + groupfolderMetadataFields.length + ')</h5>' +
                    '<div class="fields-compact-grid">';
                
                groupfolderMetadataFields.forEach(function(field) {
                    const isChecked = assignedFieldIds.includes(field.id);
                    const displayName = field.field_name.startsWith('gf_') ? 
                        field.field_name.substring(3) : field.field_name;
                    
                    html += '<div class="field-card compact ' + (isChecked ? 'assigned' : 'unassigned') + '" ' +
                            'data-field-id="' + field.id + '" ' +
                            'data-field-type="groupfolder" ' +
                            'data-assigned="' + isChecked + '" ' +
                            'data-search-text="' + escapeHtml(field.field_label.toLowerCase() + ' ' + displayName.toLowerCase() + ' ' + field.field_type.toLowerCase()) + '">' +
                        '<div class="field-card-content">' +
                            '<div class="field-checkbox-wrapper">' +
                                '<input type="checkbox" id="field-' + field.id + '" name="field_ids[]" value="' + field.id + '" ' + (isChecked ? 'checked' : '') + '>' +
                                '<label for="field-' + field.id + '" class="field-label">' +
                                    '<div class="field-info">' +
                                        '<div class="field-title">' + escapeHtml(field.field_label) + '</div>' +
                                        '<div class="field-details">' +
                                            '<code class="field-name">' + escapeHtml(displayName) + '</code>' +
                                            '<span class="field-type">' + field.field_type + '</span>' +
                                            '<span class="applies-to-badge groupfolder-badge">📁</span>' +
                                        '</div>' +
                                    '</div>' +
                                '</label>' +
                            '</div>' +
                        '</div>' +
                    '</div>';
                });
                
                html += '</div></div>';
            }

            // Render file metadata fields
            if (fileMetadataFields.length > 0) {
                html += '<div class="field-section" data-section="file">' +
                    '<h5 class="section-header">📄 File Metadata Fields (' + fileMetadataFields.length + ')</h5>' +
                    '<div class="fields-compact-grid">';
                
                fileMetadataFields.forEach(function(field) {
                    const isChecked = assignedFieldIds.includes(field.id);
                    const displayName = field.field_name.startsWith('file_') ? 
                        field.field_name.substring(5) : field.field_name;
                    
                    html += '<div class="field-card compact ' + (isChecked ? 'assigned' : 'unassigned') + '" ' +
                            'data-field-id="' + field.id + '" ' +
                            'data-field-type="file" ' +
                            'data-assigned="' + isChecked + '" ' +
                            'data-search-text="' + escapeHtml(field.field_label.toLowerCase() + ' ' + displayName.toLowerCase() + ' ' + field.field_type.toLowerCase()) + '">' +
                        '<div class="field-card-content">' +
                            '<div class="field-checkbox-wrapper">' +
                                '<input type="checkbox" id="field-' + field.id + '" name="field_ids[]" value="' + field.id + '" ' + (isChecked ? 'checked' : '') + '>' +
                                '<label for="field-' + field.id + '" class="field-label">' +
                                    '<div class="field-info">' +
                                        '<div class="field-title">' + escapeHtml(field.field_label) + '</div>' +
                                        '<div class="field-details">' +
                                            '<code class="field-name">' + escapeHtml(displayName) + '</code>' +
                                            '<span class="field-type">' + field.field_type + '</span>' +
                                            '<span class="applies-to-badge files-badge">📄</span>' +
                                        '</div>' +
                                    '</div>' +
                                '</label>' +
                            '</div>' +
                        '</div>' +
                    '</div>';
                });
                
                html += '</div></div>';
            }
            
            html += '</div>' +
                '<div class="form-actions compact">' +
                    '<button type="submit" class="primary compact">💾 Save Configuration</button>' +
                    '<button type="button" class="secondary compact cancel-fields-config">❌ Cancel</button>' +
                    '<div class="quick-actions">' +
                        '<button type="button" class="quick-btn" id="select-all-' + groupfolderId + '">✅ Select All</button>' +
                        '<button type="button" class="quick-btn" id="deselect-all-' + groupfolderId + '">⭕ Deselect All</button>' +
                    '</div>' +
                '</div>' +
            '</form>';
        }
        
        html += '</div>';
        configDiv.html(html);
        
        // Bind search functionality
        const searchInput = $('#field-search-' + groupfolderId);
        const searchCount = $('#search-count-' + groupfolderId);
        
        function updateSearchResults() {
            const searchTerm = searchInput.val().toLowerCase().trim();
            const activeFilter = $('.filter-btn.active').data('filter');
            let visibleCount = 0;
            
            $('.field-card').each(function() {
                const card = $(this);
                const searchText = card.data('search-text') || '';
                const fieldType = card.data('field-type');
                const isAssigned = card.data('assigned');
                
                let show = true;
                
                // Apply search filter
                if (searchTerm && !searchText.includes(searchTerm)) {
                    show = false;
                }
                
                // Apply type/status filter
                if (activeFilter !== 'all') {
                    switch(activeFilter) {
                        case 'assigned':
                            if (!isAssigned) show = false;
                            break;
                        case 'unassigned':
                            if (isAssigned) show = false;
                            break;
                        case 'groupfolder':
                            if (fieldType !== 'groupfolder') show = false;
                            break;
                        case 'file':
                            if (fieldType !== 'file') show = false;
                            break;
                    }
                }
                
                if (show) {
                    card.show();
                    visibleCount++;
                } else {
                    card.hide();
                }
            });
            
            // Update search count
            if (searchTerm) {
                searchCount.text(visibleCount + ' found');
            } else {
                searchCount.text('');
            }
        }
        
        // Bind search events
        searchInput.on('input', updateSearchResults);
        $('.filter-btn').on('click', function() {
            $('.filter-btn').removeClass('active');
            $(this).addClass('active');
            updateSearchResults();
        });
        
        // Bind quick action buttons
        $('#select-all-' + groupfolderId).on('click', function(e) {
            e.preventDefault();
            $('.field-card:visible input[type="checkbox"]').prop('checked', true);
        });
        
        $('#deselect-all-' + groupfolderId).on('click', function(e) {
            e.preventDefault();
            $('.field-card:visible input[type="checkbox"]').prop('checked', false);
        });
        
        // Bind form submission
        $(document).off('submit', '.fields-config-form').on('submit', '.fields-config-form', function(e) {
            e.preventDefault();
            const gfId = $(this).data('groupfolder-id');
            saveFieldsConfiguration(gfId);
        });
        
        $(document).off('click', '.cancel-fields-config').on('click', '.cancel-fields-config', function() {
            configDiv.hide();
        });
        
        console.log('✅ Compact field configuration rendered with search functionality');
    }
    
    function saveFieldsConfiguration(groupfolderId) {
        const form = $('.fields-config-form[data-groupfolder-id="' + groupfolderId + '"]');
        const fieldIds = [];
        
        form.find('input[name="field_ids[]"]:checked').each(function() {
            fieldIds.push(parseInt($(this).val()));
        });
        
        console.log('💾 Saving field configuration:', { groupfolderId: groupfolderId, fieldIds: fieldIds });
        
        $.ajax({
            url: OC.generateUrl('/apps/metavox/api/groupfolders/' + groupfolderId + '/fields'),
            method: 'POST',
            data: { field_ids: fieldIds },
            success: function(response) {
                console.log('✅ Field configuration saved:', response);
                if (response.success) {
                    showMessage('Field configuration saved successfully', 'success');
                    $('#fields-config-' + groupfolderId).hide();
                } else {
                    showMessage('Error saving field configuration', 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ Error saving field configuration:', error, xhr.responseText);
                showMessage('Error saving field configuration', 'error');
            }
        });
    }
    
    function toggleMetadataEditor(groupfolderId) {
        const metadataDiv = $('#metadata-form-' + groupfolderId);
        
        // Close all other metadata panels and field config panels
        $('.metadata-form').not('#metadata-form-' + groupfolderId).removeClass('active');
        $('.groupfolder-fields-config').hide();
        
        if (metadataDiv.hasClass('active')) {
            metadataDiv.removeClass('active');
            return;
        }
        
        console.log('📝 Loading metadata for groupfolder:', groupfolderId);
        
        // Show loading state
        metadataDiv.html('<div class="loading">Loading metadata...</div>');
        metadataDiv.addClass('active');
        
        // Add CSS fix for metadata editor visibility
        addMetadataEditorCSS();
        
        // Load groupfolder metadata
        $.ajax({
            url: OC.generateUrl('/apps/metavox/api/groupfolders/' + groupfolderId + '/metadata'),
            method: 'GET'
        }).then(function(allFields) {
            console.log('✅ Groupfolder metadata loaded:', allFields);
            
            // Filter for groupfolder-only fields (applies_to_groupfolder = 1)
            const groupfolderOnlyFields = allFields.filter(field => {
                const appliesTo = field.applies_to_groupfolder || 0;
                console.log('🎯 Field ' + field.field_name + ' applies_to_groupfolder:', appliesTo);
                return appliesTo === 1;
            });
            
            console.log('📁 Groupfolder-only fields:', groupfolderOnlyFields);
            
            if (groupfolderOnlyFields.length === 0) {
                console.warn('⚠️ No groupfolder-only fields found');
                renderMetadataEditorWithInstructions(groupfolderId, allFields);
            } else {
                renderMetadataEditor(groupfolderId, groupfolderOnlyFields);
            }
            
        }).catch(function(error) {
            console.error('❌ Error loading metadata:', error);
            metadataDiv.html('<div class="error-message">Error loading metadata: ' + error + '</div>');
        });
    }
    
    function renderMetadataEditorWithInstructions(groupfolderId, allFields) {
        const metadataDiv = $('#metadata-form-' + groupfolderId);
        
        let instructionsHTML = '<div class="no-groupfolder-fields" style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 20px; margin: 10px 0; border-radius: 8px;">' +
            '<h4>📁 No Team folder Metadata Fields Available</h4>' +
            '<p>There are <strong>' + allFields.length + '</strong> fields configured for this Team folder, but none are set to apply to the Team folder itself.</p>' +
            
            '<div style="margin: 20px 0;">' +
                '<h5>💡 Solution: Create Team folder Metadata Fields</h5>' +
                '<ol style="text-align: left; margin: 10px 0;">' +
                    '<li>Go to the <strong>"Team folder Metadata Fields"</strong> tab</li>' +
                    '<li>Create fields specifically for Team folder metadata</li>' +
                    '<li>Assign them to this Team folder in <strong>"Configure Fields"</strong></li>' +
                    '<li>Come back here - the fields will now be available!</li>' +
                '</ol>' +
            '</div>' +
            
            '<div style="margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 4px;">' +
                '<h5>📋 Currently Available Fields (all for files):</h5>';
        
        allFields.forEach(field => {
            instructionsHTML += '<div style="margin: 5px 0; padding: 8px; background: white; border-radius: 3px; border-left: 3px solid #7b1fa2;">' +
                '<strong>' + field.field_label + '</strong> (' + field.field_name + ')' +
                '<br><small style="color: #666;">Type: ' + field.field_type + ' • Applies to: Files</small>' +
            '</div>';
        });
        
        instructionsHTML += '</div>' +
            
            '<div style="text-align: center; margin-top: 20px;">' +
                '<button id="go-to-gfm-fields-' + groupfolderId + '" type="button" style="padding: 10px 20px; background: #0066cc; color: white; border: none; border-radius: 4px; cursor: pointer; margin-right: 10px;">📁 Create Team folder Metadata Fields</button>' +
                '<button id="toggle-fields-config-' + groupfolderId + '" type="button" style="padding: 10px 20px; background: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer;">⚙️ Configure Fields</button>' +
            '</div>' +
        '</div>';
        
        metadataDiv.html(instructionsHTML);
        
        // Bind the button events using jQuery event handlers instead of inline onclick
        $('#go-to-gfm-fields-' + groupfolderId).off('click').on('click', function(e) {
            e.preventDefault();
            console.log('🔄 Switching to groupfolder metadata fields tab...');
            $('[data-tab="groupfolder-metadata-fields"]').click();
        });
        
        $('#toggle-fields-config-' + groupfolderId).off('click').on('click', function(e) {
            e.preventDefault();
            console.log('⚙️ Toggling fields configuration for groupfolder:', groupfolderId);
            const configDiv = $('#fields-config-' + groupfolderId);
            if (configDiv.is(':visible')) {
                configDiv.hide();
            } else {
                $('.configure-fields[data-groupfolder-id="' + groupfolderId + '"]').click();
            }
        });
        
        console.log('✅ Metadata editor instructions rendered with working buttons for groupfolder:', groupfolderId);
    }
    
    // 🔧 MISSING FUNCTION: renderMetadataEditor - dit was de oorzaak van de error!
    function renderMetadataEditor(groupfolderId, fields) {
        const metadataDiv = $('#metadata-form-' + groupfolderId);
        
        if (!fields || fields.length === 0) {
            metadataDiv.html('<div class="no-fields">' +
                '<p>📁 No groupfolder metadata fields configured for this groupfolder.</p>' +
                '<p><small>Create groupfolder metadata fields in the "Groupfolder Metadata Fields" tab.</small></p>' +
            '</div>');
            return;
        }
        
        // Generate the form for admin editing of groupfolder metadata
        const formHTML = generateGroupfolderMetadataForm(groupfolderId, fields);
        metadataDiv.html(formHTML);
        
        // Bind form submission
        $(document).off('submit', '.metadata-form[data-groupfolder-id="' + groupfolderId + '"]')
                  .on('submit', '.metadata-form[data-groupfolder-id="' + groupfolderId + '"]', function(e) {
            e.preventDefault();
            saveGroupfolderMetadata(groupfolderId);
        });
        
        // Bind cancel button
        $(document).off('click', '.cancel-metadata').on('click', '.cancel-metadata', function() {
            metadataDiv.removeClass('active');
        });
        
        console.log('✅ Metadata editor rendered for groupfolder', groupfolderId, 'with', fields.length, 'fields');
    }
    
    function generateGroupfolderMetadataForm(groupfolderId, fields) {
        const currentValues = {};
        
        // Extract current values from fields
        fields.forEach(field => {
            if (field.value !== undefined) {
                currentValues[field.field_name] = field.value;
            }
        });
        
        if (!fields || fields.length === 0) {
            return '<div class="no-fields"><p>No groupfolder metadata fields configured.</p></div>';
        }

        let formHTML = '<div class="field-list">' +
            '<h4>📁 Team folder Metadata <small>(Admin Only)</small></h4>' +
            '<p><small>This metadata applies to the entire Team folder and is visible to all users with access.</small></p>';
        
        fields.forEach(field => {
            const currentValue = currentValues[field.field_name] || field.value || '';
            
            formHTML += '<div class="field-item" data-field-name="' + field.field_name + '">' +
                '<div class="field-info">' +
                    '<div class="field-name">' + escapeHtml(field.field_label) + '</div>' +
                    '<div class="field-type">' + field.field_type + ' <span class="applies-to-badge groupfolder-badge">📁 Team folder</span></div>' +
                '</div>' +
                '<div class="field-value">' +
                    generateFieldInput(field, currentValue) +
                '</div>' +
            '</div>';
        });
        
        formHTML += '</div>';
        formHTML += '<div class="form-actions">' +
                        '<button type="submit" class="primary">💾 Save Team folder Metadata</button>' +
                        '<button type="button" class="cancel-metadata">Cancel</button>' +
                    '</div>';
        
        return '<form class="metadata-form" data-groupfolder-id="' + groupfolderId + '">' + formHTML + '</form>';
    }

    function generateFieldInput(field, currentValue) {
        const name = field.field_name;
        const required = field.is_required ? 'required' : '';
        const escapedValue = escapeHtml(currentValue);
        
        switch (field.field_type) {
            case 'text':
                return '<input type="text" name="' + name + '" value="' + escapedValue + '" ' + required + '>';
            
            case 'textarea':
                return '<textarea name="' + name + '" ' + required + '>' + escapedValue + '</textarea>';
            
            case 'select':
                let options = '<option value="">Select...</option>';
                if (field.field_options) {
                    const optionsList = typeof field.field_options === 'string' 
                        ? field.field_options.split('\n') 
                        : field.field_options;
                    
                    optionsList.forEach(option => {
                        const trimmedOption = option.trim();
                        if (trimmedOption) {
                            const selected = trimmedOption === currentValue ? 'selected' : '';
                            options += '<option value="' + escapeHtml(trimmedOption) + '" ' + selected + '>' + escapeHtml(trimmedOption) + '</option>';
                        }
                    });
                }
                return '<select name="' + name + '" ' + required + '>' + options + '</select>';
            
            case 'number':
                return '<input type="number" name="' + name + '" value="' + escapedValue + '" ' + required + '>';
            
            case 'date':
                return '<input type="date" name="' + name + '" value="' + escapedValue + '" ' + required + '>';
            
            case 'checkbox':
                const checked = (currentValue === '1' || currentValue === 'true' || currentValue === true) ? 'checked' : '';
                return '<input type="checkbox" name="' + name + '" value="1" ' + checked + '>';
            
            default:
                return '<input type="text" name="' + name + '" value="' + escapedValue + '" ' + required + '>';
        }
    }
    
    function saveGroupfolderMetadata(groupfolderId) {
        const form = $('.metadata-form[data-groupfolder-id="' + groupfolderId + '"]');
        const metadata = {};
        
        // Show saving state
        const saveButton = form.find('button.primary');
        const originalText = saveButton.text();
        saveButton.text('Saving...').prop('disabled', true);
        
        form.find('input, textarea, select').each(function() {
            const input = $(this);
            const name = input.attr('name');
            let value = input.val();
            
            if (input.attr('type') === 'checkbox') {
                value = input.is(':checked') ? '1' : '0';
            }
            
            if (name) {
                metadata[name] = value;
            }
        });
        
        console.log('💾 Saving groupfolder metadata:', { groupfolderId: groupfolderId, metadata: metadata });
        
        $.ajax({
            url: OC.generateUrl('/apps/metavox/api/groupfolders/' + groupfolderId + '/metadata'),
            method: 'POST',
            data: { metadata: metadata },
            success: function(response) {
                console.log('✅ Groupfolder metadata saved:', response);
                if (response.success) {
                    showMessage('Team folder metadata saved successfully', 'success');
                    $('#metadata-form-' + groupfolderId).removeClass('active');
                } else {
                    showMessage('Error saving metadata: ' + (response.message || 'Unknown error'), 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ Error saving groupfolder metadata:', error, xhr.responseText);
                let errorMsg = 'Error saving groupfolder metadata';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg += ': ' + xhr.responseJSON.message;
                }
                showMessage(errorMsg, 'error');
            },
            complete: function() {
                saveButton.text(originalText).prop('disabled', false);
            }
        });
    }
    
    function addMetadataEditorCSS() {
        // Only add CSS once
        if ($('#testermeta-admin-css').length > 0) {
            return;
        }
        
        $('<style id="testermeta-admin-css">').text(`
            .metadata-form.active,
            .metadata-form[data-groupfolder-id] {
                display: block !important;
                visibility: visible !important;
                opacity: 1 !important;
                background: white !important;
                border: 1px solid #e2e8f0 !important;
                border-radius: 8px !important;
                padding: 20px !important;
                margin: 10px 0 !important;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1) !important;
            }
            
            .field-list {
                display: block !important;
            }
            
            .field-item {
                display: flex !important;
                margin-bottom: 15px !important;
                align-items: center !important;
                padding: 10px !important;
                border: 1px solid #f0f0f0 !important;
                border-radius: 4px !important;
                background: #fafafa !important;
            }
            
            .field-info {
                flex: 1 !important;
                margin-right: 15px !important;
            }
            
            .field-name {
                font-weight: bold !important;
                color: #333 !important;
                margin-bottom: 4px !important;
            }
            
            .field-type {
                font-size: 12px !important;
                color: #666 !important;
            }
            
            .field-value {
                flex: 1 !important;
            }
            
            .field-value input,
            .field-value textarea,
            .field-value select {
                width: 100% !important;
                padding: 8px 12px !important;
                border: 1px solid #ddd !important;
                border-radius: 4px !important;
                font-size: 14px !important;
                background: white !important;
            }
            
            .field-value input:focus,
            .field-value textarea:focus,
            .field-value select:focus {
                border-color: #0066cc !important;
                outline: none !important;
                box-shadow: 0 0 0 2px rgba(0,102,204,0.2) !important;
            }
            
            .form-actions {
                margin-top: 20px !important;
                padding-top: 15px !important;
                border-top: 1px solid #eee !important;
                text-align: right !important;
            }
            
            .form-actions button {
                margin-left: 10px !important;
                padding: 10px 20px !important;
                border-radius: 4px !important;
                cursor: pointer !important;
                font-size: 14px !important;
                border: 1px solid #ddd !important;
                background: #f8f9fa !important;
                color: #333 !important;
            }
            
            .form-actions button.primary {
                background: #0066cc !important;
                color: white !important;
                border: 1px solid #0066cc !important;
            }
            
            .form-actions button:hover {
                opacity: 0.9 !important;
            }
            
            .no-fields {
                text-align: center !important;
                padding: 40px 20px !important;
                color: #666 !important;
            }
            
            .no-fields p {
                margin-bottom: 10px !important;
            }
            
            .applies-to-badge {
                display: inline-block !important;
                padding: 2px 6px !important;
                border-radius: 3px !important;
                font-size: 11px !important;
                font-weight: bold !important;
                margin-left: 8px !important;
            }
            
            .groupfolder-badge {
                background: #e3f2fd !important;
                color: #1976d2 !important;
            }
            
            .files-badge {
                background: #f3e5f5 !important;
                color: #7b1fa2 !important;
            }
        `).appendTo('head');
        
        console.log('✅ Metadata editor CSS added');
    }

    console.log('🎯 TesterMeta Admin Deel 4 (COMPLEET met renderMetadataEditor) geïnitialiseerd!');
    
    // ===== ADMIN.JS IS NU ECHT COMPLEET! =====
    // Alle functies zijn aanwezig:
    // ✅ loadGroupfolders()
    // ✅ renderGroupfolders()
    // ✅ toggleFieldsConfiguration()
    // ✅ renderFieldsConfiguration()
    // ✅ saveFieldsConfiguration()
    // ✅ toggleMetadataEditor()
    // ✅ renderMetadataEditorWithInstructions()
    // ✅ renderMetadataEditor() ← FIX: Deze ontbrak!
    // ✅ generateGroupfolderMetadataForm()
    // ✅ generateFieldInput()
    // ✅ saveGroupfolderMetadata()
    // ✅ addMetadataEditorCSS()

});