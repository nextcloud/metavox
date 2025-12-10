/**
 * TesterMeta Files Plugin voor Nextcloud 31
 * Enhanced Version - Met Nextcloud sidebar integratie en nieuwe CSS
 * GLOBAL FIELDS REMOVED - Only Team folder functionality
 * UPDATED: Added improved multiselect support with dropdown checkboxes
 */

console.log('🧩 files-plugin.js gestart (Enhanced Version - Team folder + Sidebar + Improved Multiselect)');

// Add CSS styles using Nextcloud's native design system
(function addMetaVoxStyles() {
	const css = `

	/* Single Select Styling - voeg toe aan je bestaande CSS */

/* Single select wrapper */
.select-wrapper {
	position: relative;
	width: 100%;
}

/* Single select trigger (zelfde styling als multiselect) */
.select-trigger {
	display: flex;
	align-items: center;
	justify-content: space-between;
	width: 100%;
	min-height: 44px;
	padding: 0 12px;
	background: var(--color-main-background);
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large);
	color: var(--color-main-text);
	font-size: var(--default-font-size);
	cursor: pointer;
	box-sizing: border-box;
	transition: border-color 0.1s ease-in-out;
}

.select-trigger:hover {
	border-color: var(--color-primary-element);
}

.select-trigger.focus,
.select-trigger.open {
	border-color: var(--color-primary-element);
	outline: none;
	box-shadow: 0 0 0 2px var(--color-primary-element-light);
}

.select-trigger[data-disabled="true"] {
	background: var(--color-background-darker);
	color: var(--color-text-maxcontrast);
	cursor: not-allowed;
	opacity: 0.7;
}

.select-display {
	flex: 1;
	text-align: left;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.select-arrow {
	margin-left: 8px;
	opacity: 0.7;
	transition: transform 0.15s ease-in-out;
}

.select-trigger.open .select-arrow {
	transform: rotate(180deg);
}

/* Single select dropdown */
.select-dropdown-options {
	position: absolute;
	top: 100%;
	left: 0;
	right: 0;
	z-index: 9999;
	margin-top: 2px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	box-shadow: 0 4px 28px rgba(0, 0, 0, .1);
	max-height: 100px; /* Zelfde beperkte hoogte als multiselect */
	overflow-y: auto;
	overflow-x: hidden;
}

/* Single select dropdown naar boven */
.select-dropdown-options.dropdown-up {
	top: auto;
	bottom: 100%;
	margin-top: 0;
	margin-bottom: 2px;
}

/* Single select options */
.select-option {
	display: flex;
	align-items: center;
	padding: 8px 16px; /* Compactere padding */
	cursor: pointer;
	font-size: var(--default-font-size);
	border-radius: 0;
	transition: background-color 0.1s ease-in-out;
}

.select-option:hover {
	background: var(--color-background-hover);
}

.select-option.selected {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
	font-weight: 600;
}

.select-option:first-child {
	border-radius: var(--border-radius-large) var(--border-radius-large) 0 0;
}

.select-option:last-child {
	border-radius: 0 0 var(--border-radius-large) var(--border-radius-large);
}
		/* Use Nextcloud's native form control styling */
		.metavox-sidebar-section .form-control,
		.metavox-sidebar-section input[type="text"],
		.metavox-sidebar-section input[type="number"],
		.metavox-sidebar-section input[type="date"],
		.metavox-sidebar-section textarea,
		.metavox-sidebar-section select {
			width: 100%;
			min-height: 44px;
			padding: 0 12px;
			background: var(--color-main-background);
			border: 2px solid var(--color-border-maxcontrast);
			border-radius: var(--border-radius-large);
			color: var(--color-main-text);
			font-size: var(--default-font-size);
			box-sizing: border-box;
			transition: border-color 0.1s ease-in-out;
		}

		.metavox-sidebar-section input:focus,
		.metavox-sidebar-section textarea:focus,
		.metavox-sidebar-section select:focus {
			border-color: var(--color-primary-element);
			outline: none;
			box-shadow: 0 0 0 2px var(--color-primary-element-light);
		}

		.metavox-sidebar-section input:hover,
		.metavox-sidebar-section textarea:hover,
		.metavox-sidebar-section select:hover {
			border-color: var(--color-primary-element);
		}

		.metavox-sidebar-section textarea {
			min-height: 80px;
			padding: 12px;
			resize: vertical;
		}

/* Normale styling MET border voor alle form elements */
.metavox-sidebar-section-admin .form-control,
.metavox-sidebar-section-admin input[type="text"],
.metavox-sidebar-section-admin input[type="number"],
.metavox-sidebar-section-admin input[type="date"],
.metavox-sidebar-section-admin textarea,
.metavox-sidebar-section-admin select {
    width: 100%;
    min-height: 44px;
    padding: 0 12px;
    background: var(--color-main-background);
    border: 2px solid var(--color-border-maxcontrast);
    border-radius: var(--border-radius-large);
    color: var(--color-main-text);
    font-size: var(--default-font-size);
    box-sizing: border-box;
    transition: border-color 0.1s ease-in-out;
}

/* Alleen de admin notice ZONDER border */

.metavox-admin-notice {
    width: 100%;
    min-height: 44px;
    padding: 20px;
    background: var(--color-background-dark);
    border: none;
    border-radius: var(--border-radius-large);
    color: var(--color-main-text);
    font-size: var(--default-font-size);
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    justify-content: center;
    cursor: not-allowed;
    opacity: 0.8;
    margin-bottom: 8px;
}

/* Extra ruimte boven de eerste velden na admin notice */
.metavox-admin-notice + .metavox-field-display {
    margin-top: 16px;
} 

.metavox-sidebar-section-admin > .metavox-field-display {
    background: var(--color-background-dark) !important;
    border: none !important;
    min-height: 44px;
    padding: 16px;
    border-radius: var(--border-radius-large);
    display: flex;
    flex-direction: column;
    justify-content: center;
    cursor: not-allowed;
    opacity: 0.8;
}

.metavox-sidebar-section-admin input:focus,
.metavox-sidebar-section-admin textarea:focus,
.metavox-sidebar-section-admin select:focus {
    outline: none;
    box-shadow: 0 0 0 2px var(--color-primary-element-light);
}

.metavox-sidebar-section-admin textarea {
    min-height: 80px;
    padding: 12px;
    resize: vertical;
}

.metavox-sidebar-section-admin .metavox-field-label {
    font-weight: bold;
    margin-bottom: 4px;
}

.metavox-sidebar-section-admin .metavox-field-value {
    color: var(--color-text-lighter);
}

		/* Multiselect using same styling as regular inputs */
		.multiselect-wrapper {
			position: relative;
			width: 100%;
		}

		.multiselect-trigger {
			display: flex;
			align-items: center;
			justify-content: space-between;
			width: 100%;
			min-height: 44px;
			padding: 0 12px;
			background: var(--color-main-background);
			border: 2px solid var(--color-border-maxcontrast);
			border-radius: var(--border-radius-large);
			color: var(--color-main-text);
			font-size: var(--default-font-size);
			cursor: pointer;
			box-sizing: border-box;
			transition: border-color 0.1s ease-in-out;
		}

		.multiselect-trigger:hover {
			border-color: var(--color-primary-element);
		}

		.multiselect-trigger:focus,
		.multiselect-trigger.focus {
			border-color: var(--color-primary-element);
			outline: none;
			box-shadow: 0 0 0 2px var(--color-primary-element-light);
		}

		.multiselect-trigger[data-disabled="true"] {
			background: var(--color-background-darker);
			color: var(--color-text-maxcontrast);
			cursor: not-allowed;
			opacity: 0.7;
		}

		.multiselect-display {
			flex: 1;
			text-align: left;
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}

		.multiselect-arrow {
			margin-left: 8px;
			opacity: 0.7;
			transition: transform 0.15s ease-in-out;
		}

		.multiselect-trigger.open .multiselect-arrow {
			transform: rotate(180deg);
		}

		/* Dropdown using Nextcloud popover menu styling */
		.multiselect-dropdown-options {
			position: absolute;
			top: 100%;
			left: 0;
			right: 0;
			z-index: 9999;
			margin-top: 2px;
			background: var(--color-main-background);
			border: 1px solid var(--color-border);
			border-radius: var(--border-radius-large);
			box-shadow: 0 4px 28px rgba(0, 0, 0, .1);
			max-height: 200px;
			overflow-y: auto;
			overflow-x: hidden;
		}

		/* Voeg toe na de bestaande .multiselect-dropdown-options regel */
.multiselect-dropdown-options.dropdown-up {
	top: auto;
	bottom: 100%;
	margin-top: 0;
	margin-bottom: 2px;
}

	/* Style options like Nextcloud menu items */
		.multiselect-option {
			display: flex;
			align-items: center;
			padding: 8px 16px;
			cursor: pointer;
			font-size: var(--default-font-size);
			border-radius: 0;
			transition: background-color 0.1s ease-in-out;
		}

		.multiselect-option:hover {
			background: var(--color-background-hover);
		}

		.multiselect-option:first-child {
			border-radius: var(--border-radius-large) var(--border-radius-large) 0 0;
		}

		.multiselect-option:last-child {
			border-radius: 0 0 var(--border-radius-large) var(--border-radius-large);
		}

		.multiselect-option input[type="checkbox"] {
			margin-right: 12px;
			width: auto;
		}

		/* Use Nextcloud's form styling classes */
		.metavox-sidebar-section {
			padding: 0 0 24px 0;
		}

		/* Nextcloud section headers */
		.metavox-sidebar-section h3 {
			font-size: 20px;
			font-weight: 600;
			color: var(--color-main-text);
			margin: 0 0 16px 0;
			border-bottom: 1px solid var(--color-border);
			padding-bottom: 8px;
		}

/* Use Nextcloud button styling */
.metavox-sidebar-button {
  display: inline-block;
  padding: 12px 24px;
  background: var(--color-primary-element);
  border: none;
  border-radius: var(--border-radius-pill);
  color: var(--color-primary-element-text);
  font-weight: 600;
  font-size: var(--default-font-size);
  cursor: pointer;
  margin-top: 16px;
  width: 100%;
  transition: background 0.1s ease-in-out;
}

.metavox-sidebar-button:hover {
  background: var(--color-primary-element-hover);
}

.metavox-sidebar-button:active {
  background: var(--color-primary-element-hover);
  transform: translateY(1px);
}

		.metavox-sidebar-button:disabled {
			background: var(--color-text-maxcontrast);
			color: var(--color-main-background);
			cursor: not-allowed;
		}

		/* Field labels using Nextcloud typography */
		.metavox-field-label {
			display: block;
			margin-bottom: 8px;
			font-weight: 600;
			color: var(--color-main-text);
			font-size: var(--default-font-size);
		}

		/* Read-only field display using note/info box pattern */
		.metavox-field-display {
			padding: 16px;
			margin-bottom: 16px;
			background: var(--color-background-hover);
			border: 1px solid var(--color-border);
			border-radius: var(--border-radius-large);
			border-left: 4px solid var(--color-primary-element);
		}

		.metavox-field-display .metavox-field-label {
			font-weight: 600;
			color: var(--color-main-text);
			font-size: var(--default-font-size);
			margin-bottom: 4px;
		}

		.metavox-field-display .metavox-field-value {
			color: var(--color-text-lighter);
			font-size: var(--default-font-size);
			word-wrap: break-word;
			line-height: 1.4;
		}

		/* Empty state using Nextcloud's empty content pattern */
		.metavox-sidebar-empty {
			text-align: center;
			padding: 32px 16px;
			color: var(--color-text-maxcontrast);
		}

		.metavox-sidebar-empty h4 {
			margin: 0 0 8px 0;
			color: var(--color-main-text);
			font-weight: 600;
			font-size: 18px;
		}

		.metavox-sidebar-empty p {
			margin: 0 0 8px 0;
			line-height: 1.5;
			font-size: var(--default-font-size);
		}

		/* Checkbox container using Nextcloud patterns */
		.checkbox-container {
			display: flex;
			align-items: center;
			gap: 12px;
			margin-top: 8px;
		}

		.checkbox-container input[type="checkbox"] {
			width: auto;
			margin: 0;
		}

		/* Form spacing */
		.metavox-sidebar-form {
			margin-top: 16px;
		}

		.metavox-sidebar-form > div {
			margin-bottom: 20px;
		}
	`;

	// Create and inject style element
	const styleElement = document.createElement('style');
	styleElement.type = 'text/css';
	styleElement.innerHTML = css;
	document.head.appendChild(styleElement);
	
	console.log('✅ MetaVox CSS styles injected');
})();

class TesterMetaTab {
	constructor() {
		this.id = 'testermeta-sidebar-tab';
		this.name = t('testermeta', 'MetaVox');
		this.icon = 'testermeta-metadata-icon';
		this.isActive = false;
		this.el = null;
		this.fileInfo = null;
		this.context = null;
		this.groupfolderId = null;
		this.userPermissions = null;
		this.sidebarInitialized = false;

		// Bind methods to this instance
		this.mount = this.mount.bind(this);
		this.update = this.update.bind(this);
		this.destroy = this.destroy.bind(this);
		this.enabled = this.enabled.bind(this);
		this.setIsActive = this.setIsActive.bind(this);
		this.getIsActive = this.getIsActive.bind(this);
		this.scrollBottomReached = this.scrollBottomReached.bind(this);
		this.loadAndRenderMetadata = this.loadAndRenderMetadata.bind(this);
		this.renderCombinedMetadataForm = this.renderCombinedMetadataForm.bind(this);
		this.saveFileInGroupfolderMetadata = this.saveFileInGroupfolderMetadata.bind(this);
		this.saveMetadata = this.saveMetadata.bind(this);
		this.createFieldInputHtml = this.createFieldInputHtml.bind(this);
		this.formatFieldValue = this.formatFieldValue.bind(this);
		this.detectGroupfolder = this.detectGroupfolder.bind(this);
		this.detectFromMountPoint = this.detectFromMountPoint.bind(this);
		this.detectFromCurrentDirectory = this.detectFromCurrentDirectory.bind(this);
		this.detectFromPath = this.detectFromPath.bind(this);
		this.detectFromBreadcrumbs = this.detectFromBreadcrumbs.bind(this);
		this.getAllGroupfolders = this.getAllGroupfolders.bind(this);
		this.filterFieldsForContext = this.filterFieldsForContext.bind(this);
		this.checkGroupfolderPermissions = this.checkGroupfolderPermissions.bind(this);
		this.hasWritePermission = this.hasWritePermission.bind(this);
		this.hasMetadataPermission = this.hasMetadataPermission.bind(this);
		this.initializeSidebar = this.initializeSidebar.bind(this);
		this.createSidebarHTML = this.createSidebarHTML.bind(this);
		this.openSidebar = this.openSidebar.bind(this);
		this.closeSidebar = this.closeSidebar.bind(this);
		this.initMultiselectEvents = this.initMultiselectEvents.bind(this);
		this.updateMultiselectDisplay = this.updateMultiselectDisplay.bind(this);
	}

	sanitizeForSelector(str) {
    return str.replace(/[^a-zA-Z0-9_-]/g, '_');
}

	enabled(fileInfo, context) {
		// Only enable for files/folders in groupfolders
		return true; // Initial check, will be refined in mount/update
	}

	setIsActive(active) {
		this.isActive = active;
		console.log('📌 Tab active state:', active);
	}

	getIsActive() {
		return this.isActive;
	}

	// Initialize sidebar if not already done
	initializeSidebar() {
		if (this.sidebarInitialized || document.querySelector('.metavox-sidebar')) {
			return;
		}

		// Create sidebar HTML structure
		const sidebarHTML = this.createSidebarHTML();
		document.body.insertAdjacentHTML('beforeend', sidebarHTML);

		// Bind sidebar events
		const closeBtn = document.querySelector('.metavox-sidebar-close');
		const backdrop = document.querySelector('.metavox-sidebar-backdrop');
		
		if (closeBtn) {
			closeBtn.addEventListener('click', this.closeSidebar);
		}
		if (backdrop) {
			backdrop.addEventListener('click', this.closeSidebar);
		}

		// ESC key to close
		document.addEventListener('keydown', (e) => {
			if (e.key === 'Escape' && document.querySelector('.metavox-sidebar.open')) {
				this.closeSidebar();
			}
		});

		this.sidebarInitialized = true;
		console.log('✅ Sidebar initialized');
	}

	// Create sidebar HTML structure
	createSidebarHTML() {
		return `
			<!-- Sidebar backdrop for mobile -->
			<div class="metavox-sidebar-backdrop"></div>
			
			<!-- Main sidebar -->
			<div class="metavox-sidebar" id="metavoxSidebar">
				<div class="metavox-sidebar-header">
					<h2 class="metavox-sidebar-title">File Metadata</h2>
					<button class="metavox-sidebar-close" type="button"></button>
				</div>
				
				<div class="metavox-sidebar-tabs">
					<button class="metavox-sidebar-tab active" data-tab="metadata">Metadata</button>
					<button class="metavox-sidebar-tab" data-tab="details">Details</button>
				</div>
				
				<div class="metavox-sidebar-content">
					<div class="metavox-sidebar-loading">Loading...</div>
				</div>
			</div>
		`;
	}

	// Open sidebar with animation
	openSidebar() {
		this.initializeSidebar();
		
		const sidebar = document.querySelector('.metavox-sidebar');
		const backdrop = document.querySelector('.metavox-sidebar-backdrop');
		
		if (sidebar && backdrop) {
			sidebar.classList.add('open');
			backdrop.classList.add('show');
			
			// Update sidebar content
			this.updateSidebarContent();
		}
	}

	// Close sidebar
	closeSidebar() {
		const sidebar = document.querySelector('.metavox-sidebar');
		const backdrop = document.querySelector('.metavox-sidebar-backdrop');
		
		if (sidebar && backdrop) {
			sidebar.classList.remove('open');
			backdrop.classList.remove('show');
		}
	}

	// Update sidebar content
	updateSidebarContent() {
		const content = document.querySelector('.metavox-sidebar-content');
		if (!content || !this.fileInfo) return;

		// Update title
		const title = document.querySelector('.metavox-sidebar-title');
		if (title) {
			const itemType = this.fileInfo.type === 'dir' ? 'Folder' : 'File';
			title.textContent = `${itemType} Metadata`;
		}

		// Load metadata into sidebar
		this.loadAndRenderMetadata();
	}

initMultiselectEvents() {
    const triggers = this.el.querySelectorAll('.multiselect-trigger:not([data-disabled="true"])');
    
    triggers.forEach(trigger => {
        const fieldName = trigger.getAttribute('data-field');
        const sanitizedFieldId = this.sanitizeForSelector('file-gf-field-' + fieldName);
        const dropdown = this.el.querySelector('#dropdown-' + sanitizedFieldId);
        
        // Controleer of dropdown bestaat
        if (!dropdown) {
            console.warn('Multiselect dropdown not found for field:', fieldName);
            return;
        }
        
        // Rest van je bestaande code blijft hetzelfde...
        trigger.addEventListener('click', (e) => {
            e.stopPropagation();
            
            // Je bestaande click handler code
            this.el.querySelectorAll('.multiselect-dropdown-options').forEach(dd => {
                if (dd !== dropdown) {
                    dd.style.display = 'none';
                    const otherTrigger = dd.parentNode.querySelector('.multiselect-trigger');
                    if (otherTrigger) otherTrigger.classList.remove('open');
                }
            });
            
            // Je bestaande toggle code
            const isOpen = dropdown.style.display === 'block';
            
            if (!isOpen) {
                const triggerRect = trigger.getBoundingClientRect();
                const viewportHeight = window.innerHeight;
                const dropdownMaxHeight = 120;
                const spaceBelow = viewportHeight - triggerRect.bottom;
                const spaceAbove = triggerRect.top;
                
                dropdown.classList.remove('dropdown-up');
                
                if (spaceBelow < dropdownMaxHeight && spaceAbove > dropdownMaxHeight) {
                    dropdown.classList.add('dropdown-up');
                }
                
                dropdown.style.display = 'block';
            } else {
                dropdown.style.display = 'none';
            }
            
            trigger.classList.toggle('open', !isOpen);
        });
        
        dropdown.addEventListener('change', (e) => {
            if (e.target.type === 'checkbox') {
                this.updateMultiselectDisplay(fieldName);
            }
        });
    });
}

// Initialize single select events
initSelectEvents() {
    const triggers = this.el.querySelectorAll('.select-trigger:not([data-disabled="true"])');
    
    triggers.forEach(trigger => {
        const fieldName = trigger.getAttribute('data-field');
        const sanitizedFieldId = this.sanitizeForSelector('file-gf-field-' + fieldName);
        const dropdown = this.el.querySelector('#select-dropdown-' + sanitizedFieldId);
        
        if (!dropdown) {
            console.warn('Select dropdown not found for field:', fieldName);
            return;
        }
		
		// Click trigger to toggle dropdown
		trigger.addEventListener('click', (e) => {
			e.preventDefault();
			e.stopPropagation();
			
			// Close other select dropdowns
			this.el.querySelectorAll('.select-dropdown-options').forEach(dd => {
				if (dd !== dropdown) {
					dd.style.display = 'none';
					const otherTrigger = dd.parentNode.querySelector('.select-trigger');
					if (otherTrigger) otherTrigger.classList.remove('open');
				}
			});
			
			// Close multiselect dropdowns too
			this.el.querySelectorAll('.multiselect-dropdown-options').forEach(dd => {
				dd.style.display = 'none';
				const otherTrigger = dd.parentNode.querySelector('.multiselect-trigger');
				if (otherTrigger) otherTrigger.classList.remove('open');
			});
			
			// Toggle current dropdown
			const isOpen = dropdown.style.display === 'block';
			
			if (!isOpen) {
				// Smart positioning (like multiselect)
				const triggerRect = trigger.getBoundingClientRect();
				const viewportHeight = window.innerHeight;
				const dropdownMaxHeight = 100;
				const spaceBelow = viewportHeight - triggerRect.bottom;
				const spaceAbove = triggerRect.top;
				
				// Reset positioning classes
				dropdown.classList.remove('dropdown-up');
				
				// If not enough space below but enough above, flip it
				if (spaceBelow < dropdownMaxHeight && spaceAbove > dropdownMaxHeight) {
					dropdown.classList.add('dropdown-up');
					console.log('Select dropdown flipped up for field:', fieldName);
				}
				
				dropdown.style.display = 'block';
			} else {
				dropdown.style.display = 'none';
			}
			
			trigger.classList.toggle('open', !isOpen);
		});
		
		// Handle option clicks
		dropdown.addEventListener('click', (e) => {
			e.stopPropagation();
			
			const option = e.target.closest('.select-option');
			if (option) {
				const selectedValue = option.getAttribute('data-value');
				
				// Update the select
				this.updateSelectDisplay(fieldName, selectedValue);
				
				// Close dropdown
				dropdown.style.display = 'none';
				trigger.classList.remove('open');
			}
		});
		
		// Prevent dropdown from closing when clicking inside it
		dropdown.addEventListener('click', (e) => {
			e.stopPropagation();
		});
	});
}

// Update multiselect display and hidden input
updateMultiselectDisplay(fieldName) {
    const sanitizedFieldId = this.sanitizeForSelector('file-gf-field-' + fieldName);
    const hiddenInput = this.el.querySelector('#' + sanitizedFieldId);
    const trigger = this.el.querySelector('.multiselect-trigger[data-field="' + fieldName + '"]');
    const checkboxes = this.el.querySelectorAll('#dropdown-' + sanitizedFieldId + ' input[type="checkbox"]');
    
    if (!hiddenInput || !trigger) return;
    
    const selectedValues = [];
    checkboxes.forEach(checkbox => {
        if (checkbox.checked) {
            selectedValues.push(checkbox.value);
        }
    });
    
    hiddenInput.value = selectedValues.join(';#');

    const display = trigger.querySelector('.multiselect-display');
    if (display) {
        display.textContent = selectedValues.length > 0 ? selectedValues.join(', ') : 'Select options...';
    }

    console.log('Multiselect updated for field:', fieldName, 'values:', selectedValues.join(';#'));
}

updateSelectDisplay(fieldName, selectedValue) {
    const sanitizedFieldId = this.sanitizeForSelector('file-gf-field-' + fieldName);
    const hiddenInput = this.el.querySelector('#' + sanitizedFieldId);
    const trigger = this.el.querySelector('.select-trigger[data-field="' + fieldName + '"]');
    const dropdown = this.el.querySelector('#select-dropdown-' + sanitizedFieldId);
    
    if (!hiddenInput || !trigger) return;
    
    hiddenInput.value = selectedValue;
    
    const display = trigger.querySelector('.select-display');
    if (display) {
        display.textContent = selectedValue || 'Select...';
    }
    
    if (dropdown) {
        dropdown.querySelectorAll('.select-option').forEach(option => {
            option.classList.remove('selected');
            if (option.getAttribute('data-value') === selectedValue) {
                option.classList.add('selected');
            }
        });

	// Close dropdowns when clicking outside (in initMultiselectEvents functie)
document.addEventListener('click', (e) => {
	if (!e.target.closest('.multiselect-wrapper') && !e.target.closest('.select-wrapper')) {
		// Close multiselect dropdowns
		this.el.querySelectorAll('.multiselect-dropdown-options').forEach(dropdown => {
			dropdown.style.display = 'none';
			dropdown.classList.remove('dropdown-up');
		});
		this.el.querySelectorAll('.multiselect-trigger').forEach(trigger => {
			trigger.classList.remove('open');
		});
		
		// Close select dropdowns
		this.el.querySelectorAll('.select-dropdown-options').forEach(dropdown => {
			dropdown.style.display = 'none';
			dropdown.classList.remove('dropdown-up');
		});
		this.el.querySelectorAll('.select-trigger').forEach(trigger => {
			trigger.classList.remove('open');
		});
	}
});

	}

	console.log('Select updated for field:', fieldName, 'value:', selectedValue);
}




	// Check groupfolder permissions via Nextcloud API
	async checkGroupfolderPermissions(groupfolderId, filePath) {
		if (!groupfolderId) {
			console.log('🔒 Geen groupfolder ID, return no permissions');
			return { canWrite: false, canCreate: false, canDelete: false, canShare: false };
		}

		try {
			// Check via Nextcloud's built-in permission system
			// Gebruik de fileInfo.permissions property die Nextcloud al berekent
			const permissions = this.fileInfo.permissions || 0;
			
			// Nextcloud permission constants
			const NC_PERMISSION_READ = 1;
			const NC_PERMISSION_UPDATE = 2;
			const NC_PERMISSION_CREATE = 4;
			const NC_PERMISSION_DELETE = 8;
			const NC_PERMISSION_SHARE = 16;
			
			const canRead = (permissions & NC_PERMISSION_READ) !== 0;
			const canWrite = (permissions & NC_PERMISSION_UPDATE) !== 0;
			const canCreate = (permissions & NC_PERMISSION_CREATE) !== 0;
			const canDelete = (permissions & NC_PERMISSION_DELETE) !== 0;
			const canShare = (permissions & NC_PERMISSION_SHARE) !== 0;

			console.log('🔒 Nextcloud permissions berekend:', {
				rawPermissions: permissions,
				canRead,
				canWrite,
				canCreate,
				canDelete,
				canShare,
				filePath: filePath
			});

			// Voor metadata: gebruiker moet write permissions hebben om metadata te bewerken
			// Alleen in speciale gevallen (zoals geconfigureerd door admin) kan dit afwijken
			let canEditMetadata = canWrite; // Default: metadata editing vereist write permissions
			
			// Voor groupfolders: respecteer de write permissions strikt
			canEditMetadata = canWrite || canCreate; // Write of create permissions vereist
			
			return {
				canRead,
				canWrite,
				canCreate,
				canDelete,
				canShare,
				canEditMetadata
			};
		} catch (error) {
			console.warn('⚠️ Error checking permissions:', error);
			// Bij fout: conservatief benaderen - alleen lezen toestaan
			return { 
				canRead: true, 
				canWrite: false, 
				canCreate: false, 
				canDelete: false, 
				canShare: false,
				canEditMetadata: false 
			};
		}
	}

	// Check write permission op basis van Nextcloud permissions
	hasWritePermission() {
		if (!this.userPermissions) {
			return false;
		}
		return this.userPermissions.canWrite;
	}

	// Check metadata permission - strikt gebaseerd op write permissions
	hasMetadataPermission() {
		if (!this.userPermissions) {
			return false;
		}
		
		// Voor groupfolders: strikte controle - gebruiker moet write of create kunnen
		// Dit respecteert de Nextcloud groupfolder permission structuur
		return this.userPermissions.canEditMetadata && 
			   (this.userPermissions.canWrite || this.userPermissions.canCreate);
	}

	// Filter velden op basis van context
	filterFieldsForContext(fields, context) {
		if (!fields || !Array.isArray(fields)) {
			console.log('🔍 Geen velden om te filteren:', fields);
			return [];
		}

		console.log('🔍 Filteren velden voor context:', context, 'van', fields.length, 'velden');

		let filteredFields = [];

		switch (context) {
			case 'groupfolder-metadata':
				filteredFields = fields.filter(field => {
					const appliesTo = field.applies_to_groupfolder;
					const isGroupfolderField = appliesTo === 1 || appliesTo === '1';
					return isGroupfolderField;
				});
				break;

			case 'file-metadata':
				filteredFields = fields.filter(field => {
					const appliesTo = field.applies_to_groupfolder;
					const isFileField = appliesTo === 0 || appliesTo === '0' || appliesTo === null || appliesTo === undefined;
					return isFileField;
				});
				break;

			default:
				console.warn('⚠️ Onbekende filter context:', context);
				filteredFields = fields;
				break;
		}

		console.log(`✅ Filtering resultaat: ${filteredFields.length} van ${fields.length} velden voor context '${context}'`);
		return filteredFields;
	}

	async detectGroupfolder(fileInfo) {
		if (!fileInfo) {
			console.log('🔍 Geen fileInfo beschikbaar');
			return null;
		}

		console.log('🔍 Detecteren groupfolder voor:', {
			path: fileInfo.path,
			name: fileInfo.name,
			id: fileInfo.id,
			mountPoint: fileInfo.mountPoint,
			mountType: fileInfo.mountType,
			isMountRoot: fileInfo.attributes?.['is-mount-root'],
			permissions: fileInfo.permissions
		});

		// Method 1: Check voor mount-root directories (groupfolders zelf)
		if (fileInfo.type === 'dir' && 
			fileInfo.mountType === 'group' && 
			fileInfo.attributes?.['is-mount-root'] === true) {
			
			console.log('🔍 Item is een mount-root directory (groupfolder zelf)');
			const groupfolders = await this.getAllGroupfolders();
			
			for (const gf of groupfolders) {
				if (gf.mount_point === fileInfo.name) {
					console.log('✅ Mount-root matches groupfolder:', gf);
					return gf.id;
				}
			}
		}

		// Method 2: Check mountPoint - meest betrouwbare methode voor items IN groupfolders
		if (fileInfo.mountPoint && fileInfo.mountPoint !== '/' && fileInfo.mountPoint !== '') {
			console.log('🔍 MountPoint gevonden:', fileInfo.mountPoint);
			const mountResult = await this.detectFromMountPoint(fileInfo.mountPoint);
			if (mountResult) {
				console.log('✅ Groupfolder gevonden via mountPoint:', mountResult);
				return mountResult;
			}
		}

		// Method 3: Check via het volledige path van het fileInfo
		if (fileInfo.path && fileInfo.path !== '/' && fileInfo.path !== '') {
			console.log('🔍 Checking path via fileInfo.path:', fileInfo.path);
			const pathResult = await this.detectFromPath(fileInfo.path);
			if (pathResult) {
				console.log('✅ Groupfolder gevonden via fileInfo.path:', pathResult);
				return pathResult;
			}
		}

		// Method 4: Voor items binnen groupfolders - check mountType
		if (fileInfo.mountType === 'group') {
			console.log('🔍 Item heeft mountType "group" - waarschijnlijk in groupfolder');
			
			// Probeer via current directory
			const currentDirResult = await this.detectFromCurrentDirectory();
			if (currentDirResult) {
				console.log('✅ Groupfolder gevonden via current directory (group mount):', currentDirResult);
				return currentDirResult;
			}
		}

		// Method 5: Fallback - check via current directory (algemeen)
		const currentDirResult = await this.detectFromCurrentDirectory();
		if (currentDirResult) {
			console.log('✅ Groupfolder gevonden via current directory (fallback):', currentDirResult);
			return currentDirResult;
		}

		console.log('❌ Geen groupfolder gedetecteerd');
		return null;
	}

	async detectFromMountPoint(mountPoint) {
		if (!mountPoint) return null;
		
		try {
			const groupfolders = await this.getAllGroupfolders();
			
			for (const gf of groupfolders) {
				if (mountPoint === gf.mount_point || 
					mountPoint.endsWith('/' + gf.mount_point) ||
					gf.mount_point === mountPoint.replace(/^\//, '')) {
					console.log('✅ MountPoint matches groupfolder:', gf);
					return gf.id;
				}
			}
		} catch (error) {
			console.warn('⚠️ Error getting groupfolders for mountPoint detection:', error);
		}

		return null;
	}

	async detectFromCurrentDirectory() {
		try {
			// Check Files app context
			if (window.OCA && window.OCA.Files && window.OCA.Files.App && window.OCA.Files.App.fileList) {
				const fileList = window.OCA.Files.App.fileList;
				const currentDir = fileList.getCurrentDirectory ? fileList.getCurrentDirectory() : fileList._currentDirectory;
				
				if (currentDir && currentDir !== '/' && currentDir !== '') {
					const result = await this.detectFromPath(currentDir);
					if (result) return result;
				}
			}

			// Check URL parameters
			const urlParams = new URLSearchParams(window.location.search);
			if (urlParams.has('dir')) {
				const dirPath = urlParams.get('dir');
				if (dirPath && dirPath !== '/' && dirPath !== '') {
					const result = await this.detectFromPath(dirPath);
					if (result) return result;
				}
			}

			// Check breadcrumbs
			return await this.detectFromBreadcrumbs();
		} catch (error) {
			console.warn('⚠️ Error detecting from current directory:', error);
		}

		return null;
	}

	async detectFromPath(path) {
		if (!path || path === '/' || path === '') {
			return null;
		}

		try {
			const groupfolders = await this.getAllGroupfolders();

			for (const gf of groupfolders) {
				const mountPoint = '/' + gf.mount_point;
				
				if (path.startsWith(mountPoint + '/') || path === mountPoint) {
					console.log('✅ Path matches groupfolder:', gf);
					return gf.id;
				}
				
				if (path.startsWith(gf.mount_point + '/') || path === gf.mount_point) {
					console.log('✅ Path matches groupfolder (no leading slash):', gf);
					return gf.id;
				}
			}
		} catch (error) {
			console.warn('⚠️ Error getting groupfolders for detection:', error);
		}

		return null;
	}

	async detectFromBreadcrumbs() {
		try {
			const breadcrumbs = document.querySelectorAll('.breadcrumb a, #breadcrumb a, .files-controls .breadcrumb a');

			for (const breadcrumb of breadcrumbs) {
				const href = breadcrumb.href || breadcrumb.getAttribute('href');
				
				if (href && href.includes('dir=')) {
					const match = href.match(/dir=([^&]+)/);
					if (match) {
						const dirPath = decodeURIComponent(match[1]);
						const result = await this.detectFromPath(dirPath);
						if (result) return result;
					}
				}
			}
		} catch (error) {
			console.warn('⚠️ Error detecting from breadcrumbs:', error);
		}

		return null;
	}

	async getAllGroupfolders() {
		if (this._cachedGroupfolders) {
			return this._cachedGroupfolders;
		}

		try {
			const response = await fetch(OC.generateUrl('/apps/metavox/api/groupfolders'), {
				method: 'GET',
				headers: {
					'Content-Type': 'application/json',
					'requesttoken': OC.requestToken
				}
			});

			if (response.ok) {
				this._cachedGroupfolders = await response.json();
				return this._cachedGroupfolders;
			}
		} catch (error) {
			console.warn('⚠️ Error fetching groupfolders:', error);
		}

		return [];
	}

	async mount(el, fileInfo, context) {
		console.log('🔧 TesterMeta tab mount voor bestand:', fileInfo);
		
		this.el = el;
		this.fileInfo = fileInfo;
		this.context = context;
		this.isActive = true;

		// Use Nextcloud sidebar styling
		this.el.innerHTML = '<div class="metavox-sidebar-section">' +
			'<div class="metavox-sidebar-loading">' +
				t('testermeta', 'Loading metadata...') +
			'</div>' +
		'</div>';

		await this.loadAndRenderMetadata();
	}

	async update(fileInfo) {
		console.log('🔄 TesterMeta tab update voor bestand:', fileInfo);
		
		this.fileInfo = fileInfo;
		
		// Reset state bij update
		this.groupfolderId = null;
		this.userPermissions = null;
		this._cachedGroupfolders = null;
		
		await this.loadAndRenderMetadata();
	}

	destroy() {
		console.log('💥 TesterMeta tab destroy');
		this.isActive = false;
		
		// Reset alle state
		this.groupfolderId = null;
		this.userPermissions = null;
		this.fileInfo = null;
		this.context = null;
		this._cachedGroupfolders = null;
		
		if (this.el) {
			this.el.innerHTML = '';
		}
	}

	scrollBottomReached() {}

	async loadAndRenderMetadata() {
		if (!this.fileInfo || !this.el) {
			console.warn('⚠️ Geen fileInfo of element beschikbaar');
			return;
		}

		try {
			console.log('📡 Laden metadata voor bestand:', {
				id: this.fileInfo.id,
				name: this.fileInfo.name,
				path: this.fileInfo.path,
				type: this.fileInfo.type,
				permissions: this.fileInfo.permissions
			});

			// 1. Detecteer groupfolder
			this.groupfolderId = await this.detectGroupfolder(this.fileInfo);
			console.log('🔍 Groupfolder detectie resultaat:', this.groupfolderId);

			// ALLEEN WERKEN ALS HET EEN GROUPFOLDER IS
			if (!this.groupfolderId) {
				console.log('🚫 Geen groupfolder gedetecteerd - toon alleen message');
				this.el.innerHTML = '<div class="metavox-sidebar-section">' +
					'<div class="metavox-sidebar-empty">' +
						'<h4>' + t('testermeta', 'Team folder Only') + '</h4>' +
						'<p>' + t('testermeta', 'Metadata functionality is only available for files and folders within Team folders.') + '</p>' +
						'<p><em>' + t('testermeta', 'This file is not in a Team folder, so no metadata can be managed here.') + '</em></p>' +
					'</div>' +
				'</div>';
				return;
			}

			// 2. Check permissions
			this.userPermissions = await this.checkGroupfolderPermissions(this.groupfolderId, this.fileInfo.path);
			console.log('🔒 User permissions:', this.userPermissions);

			let allFileFields = [];
			let allGroupfolderFields = [];

			// 3. Laad metadata voor groupfolder
			console.log('🟢 BESTAND IN GROUPFOLDER - Laden metadata voor GF ID:', this.groupfolderId);
			
			try {
				// Laad groupfolder metadata
				const groupfolderUrl = OC.generateUrl('/apps/metavox/api/groupfolders/{groupfolderId}/metadata', {
					groupfolderId: this.groupfolderId
				});

				const groupfolderResponse = await fetch(groupfolderUrl, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
						'requesttoken': OC.requestToken
					}
				});

				if (groupfolderResponse.ok) {
					allGroupfolderFields = await groupfolderResponse.json();
					console.log('📋 Groupfolder metadata velden ontvangen:', allGroupfolderFields);
				}

				// Laad file metadata binnen groupfolder
				const fileInGroupfolderUrl = OC.generateUrl('/apps/metavox/api/groupfolders/{groupfolderId}/files/{fileId}/metadata', {
					groupfolderId: this.groupfolderId,
					fileId: this.fileInfo.id
				});

				const fileInGroupfolderResponse = await fetch(fileInGroupfolderUrl, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
						'requesttoken': OC.requestToken
					}
				});

				if (fileInGroupfolderResponse.ok) {
					allFileFields = await fileInGroupfolderResponse.json();
					console.log('📋 File-in-groupfolder metadata velden ontvangen:', allFileFields);
				}

			} catch (gfError) {
				console.error('❌ Fout bij laden groupfolder metadata:', gfError);
			}

			// 4. Filter velden
			const filteredGroupfolderFields = this.filterFieldsForContext(allGroupfolderFields, 'groupfolder-metadata');
			const filteredFileFields = this.filterFieldsForContext(allFileFields, 'file-metadata');

			console.log('🎯 Render metadata met permissions:', {
				filteredFileFields: filteredFileFields.length,
				filteredGroupfolderFields: filteredGroupfolderFields.length,
				groupfolderId: this.groupfolderId,
				hasWritePermission: this.hasWritePermission(),
				hasMetadataPermission: this.hasMetadataPermission()
			});

			this.renderCombinedMetadataForm(filteredFileFields, filteredGroupfolderFields);

		} catch (error) {
			console.error('❌ Fout bij laden metadata:', error);
			this.el.innerHTML = '<div class="metavox-sidebar-section">' +
				'<div class="metavox-sidebar-empty">' +
					'<h4>' + t('testermeta', 'Error loading metadata') + '</h4>' +
					'<p class="error-details">' + error.message + '</p>' +
				'</div>' +
			'</div>';
		}
	}

	renderCombinedMetadataForm(fileFields, groupfolderFields) {
		let formHtml = '';

		const isInGroupfolder = !!this.groupfolderId;
		const isDirectory = this.fileInfo.type === 'dir';
		const isMountRoot = this.fileInfo.attributes?.['is-mount-root'] === true;
		const isGroupfolderRoot = isDirectory && isInGroupfolder && isMountRoot;
		
		// Permission checks
		const canEditMetadata = this.hasMetadataPermission();
		const canWrite = this.hasWritePermission();

		console.log('🎯 Render context decision:', {
			isInGroupfolder,
			isDirectory, 
			isMountRoot,
			isGroupfolderRoot,
			canEditMetadata,
			canWrite,
			fileName: this.fileInfo.name,
			permissions: this.fileInfo.permissions
		});

		// Permission warning voor read-only access
		if (isInGroupfolder && !canEditMetadata) {
			formHtml += '<div class="metavox-sidebar-section">' +
				'<div class="metavox-field-display" style="background: var(--color-warning-light); border-color: var(--color-warning);">' +
					'<span class="metavox-field-label">Permission Notice</span>' +
					'<span class="metavox-field-value">' +
						'Read-only access: You can view metadata but cannot edit it. This also means that documents/files within the folder cannot be modified.' +
					'</span>' +
				'</div>' +
			'</div>';
		}

		// === SCENARIO 1: Groupfolder root ===
		if (isGroupfolderRoot && groupfolderFields && groupfolderFields.length > 0) {
			formHtml += '<div class="metavox-admin-notice">' +  // class veranderd
	'<span class="metavox-field-label">Admin Notice</span>' +
	'<span class="metavox-field-value">' +
		'This metadata applies to the entire Team folder and can only be edited by administrators in the admin settings.' +
	'</span>' +
'</div>';

// Gewone velden houden de oude class
groupfolderFields.forEach(field => {
	const value = this.formatFieldValue(field);
	formHtml += '<div class="metavox-field-display">' +  // blijftzelfde
		'<span class="metavox-field-label">' + field.field_label + '</span>' +
		'<span class="metavox-field-value">' + value + '</span>' +
	'</div>';
			});
		}
		// === SCENARIO 2: Items in groupfolder ===
		else if (isInGroupfolder && !isGroupfolderRoot) {
			// Groupfolder info (always readonly)
			if (groupfolderFields && groupfolderFields.length > 0) {
				formHtml += '<div class="metavox-sidebar-section">' +
					'<h3>Team folder Information</h3>';
				
				groupfolderFields.forEach(field => {
					const value = this.formatFieldValue(field);
					formHtml += '<div class="metavox-field-display">' +
						'<span class="metavox-field-label">' + field.field_label + '</span>' +
						'<span class="metavox-field-value">' + value + '</span>' +
					'</div>';
				});
				
				formHtml += '</div>';
			}

			// Item metadata (editable if permissions allow)
			if (fileFields && fileFields.length > 0) {
				const itemType = isDirectory ? 'Folder' : 'File';
				
				formHtml += '<div class="metavox-sidebar-section">' +
					'<h3>' + itemType + ' Metadata</h3>';
				
				if (canEditMetadata) {
					formHtml += '<div class="metavox-sidebar-form">' +
						'<form id="testermeta-file-in-groupfolder-form">';
					
					fileFields.forEach(field => {
						formHtml += '<div style="margin-bottom: 16px;">' +
							'<label class="metavox-field-label" for="file-gf-field-' + field.field_name + '">' +
								field.field_label +
								(field.is_required ? '<span style="color: var(--color-error);">*</span>' : '') +
							'</label>' +
							this.createFieldInputHtml(field, 'file-gf-field-', canEditMetadata) +
						'</div>';
					});
					
					formHtml += '<button type="submit" class="metavox-sidebar-button">Save ' + itemType + ' Metadata</button>' +
					'</form>' +
					'</div>';
				} else {
					// Read-only view
					fileFields.forEach(field => {
						const value = this.formatFieldValue(field);
						formHtml += '<div class="metavox-field-display">' +
							'<span class="metavox-field-label">' + field.field_label + '</span>' +
							'<span class="metavox-field-value">' + value + '</span>' +
						'</div>';
					});
				}
				
				formHtml += '</div>';
			}
		}

		// === SCENARIO 3: Geen velden beschikbaar ===
let showMetadataMessage = false;
let messageType = 'metadata';
let contextMessage = '';

if (isGroupfolderRoot) {
    // Root folder: alleen teamfolder velden zijn relevant
    if (!groupfolderFields || groupfolderFields.length === 0) {
        showMetadataMessage = true;
        messageType = 'Team folder metadata';
        contextMessage = 'No Team folder metadata fields are configured for this Team folder. Contact your administrator to set up Team folder metadata fields.';
    }
} else if (isInGroupfolder) {
    // Binnen team folder: file/folder velden zijn relevant
    if (!fileFields || fileFields.length === 0) {
        showMetadataMessage = true;
        messageType = isDirectory ? 'folder metadata' : 'file metadata';
        contextMessage = 'No ' + messageType + ' fields are configured for items in this Team folder. Contact your administrator to set up file metadata fields.';
    }
} else {
    // Buiten team folder: reguliere file velden
    if (!fileFields || fileFields.length === 0) {
        showMetadataMessage = true;
        messageType = isDirectory ? 'folder metadata' : 'file metadata';
        contextMessage = 'No ' + messageType + ' fields are configured. Contact your administrator to set up metadata fields.';
    }
}

if (showMetadataMessage) {
    formHtml += '<div class="metavox-sidebar-section">' +
        '<div class="metavox-sidebar-empty">' +
            '<h4>No ' + messageType + ' fields configured</h4>' +
            '<p>' + contextMessage + '</p>' +
        '</div>' +
    '</div>';
}

		this.el.innerHTML = formHtml;

		// Initialize multiselect events after rendering
		if (canEditMetadata && isInGroupfolder && !isGroupfolderRoot) {
			setTimeout(() => {
				this.initMultiselectEvents();
				this.initSelectEvents();
			}, 100);
		}

		// Bind form events - alleen als gebruiker edit rechten heeft
		const fileInGroupfolderForm = this.el.querySelector('#testermeta-file-in-groupfolder-form');
		if (fileInGroupfolderForm && isInGroupfolder && !isGroupfolderRoot && canEditMetadata) {
			fileInGroupfolderForm.addEventListener('submit', (e) => {
				e.preventDefault();
				this.saveFileInGroupfolderMetadata(fileFields);
			});
		}
	}

	// Format field value for display (handles multiselect values)
	formatFieldValue(field) {
		let value = field.value || '(Not set)';
		
		// Handle multiselect values - they are stored as semicolon-separated strings
		if (field.field_type === 'multiselect' && value && value !== '(Not set)') {
			// Split semicolon-separated values and join with a more readable format
			const values = value.split(';#').map(v => v.trim()).filter(v => v);
			if (values.length > 1) {
				value = values.join(', ');
			}
		}
		
		return value;
	}

createFieldInputHtml(field, prefix, isEditable = true) {
    prefix = prefix || '';
    const inputId = prefix + field.field_name;
    const sanitizedInputId = this.sanitizeForSelector(inputId);
    const value = field.value || '';
    const required = field.is_required ? 'required' : '';
    const disabled = !isEditable ? 'disabled' : '';

    switch (field.field_type) {
        case 'textarea':
            return '<textarea id="' + sanitizedInputId + '" name="' + field.field_name + '" placeholder="' + field.field_label + '" ' + required + ' ' + disabled + '>' + value + '</textarea>';
        case 'number':
            return '<input type="number" id="' + sanitizedInputId + '" name="' + field.field_name + '" placeholder="' + field.field_label + '" value="' + value + '" ' + required + ' ' + disabled + '>';
        case 'date':
            return '<input type="date" id="' + sanitizedInputId + '" name="' + field.field_name + '" value="' + value + '" ' + required + ' ' + disabled + '>';

        case 'select':
            let singleSelectHtml = '<div class="select-wrapper">';
            const currentValue = value || '';
            
            // Gebruik gesanitized ID
            singleSelectHtml += '<input type="hidden" id="' + sanitizedInputId + '" name="' + field.field_name + '" value="' + currentValue + '" ' + disabled + '>';
            
            const selectDisplayText = currentValue || 'Select...';
            singleSelectHtml += '<div class="select-trigger" data-field="' + field.field_name + '" ' + (disabled ? 'data-disabled="true"' : '') + '>' +
                '<span class="select-display">' + selectDisplayText + '</span>' +
                '<span class="select-arrow">▼</span>' +
            '</div>';
            
            // Gebruik gesanitized field name voor dropdown
            singleSelectHtml += '<div class="select-dropdown-options" id="select-dropdown-' + this.sanitizeForSelector('file-gf-field-' + field.field_name) + '" style="display: none;">';
            
            // Rest van de code...
            singleSelectHtml += '<div class="select-option" data-value="">' +
                '<span>Select...</span>' +
            '</div>';
            
            let selectOptions = [];
            if (field.field_options) {
                if (typeof field.field_options === 'string') {
                    selectOptions = field.field_options.split('\n').filter(opt => opt.trim() !== '').map(opt => opt.trim());
                } else if (Array.isArray(field.field_options)) {
                    selectOptions = field.field_options;
                }
            }
            
            selectOptions.forEach(function(option) {
                const isSelected = currentValue === option;
                singleSelectHtml += '<div class="select-option' + (isSelected ? ' selected' : '') + '" data-value="' + option + '">' +
                    '<span>' + option + '</span>' +
                '</div>';
            });
            
            singleSelectHtml += '</div>' + '</div>';
            return singleSelectHtml;

        case 'multiselect':
            let multiselectHtml = '<div class="multiselect-wrapper">';
            
            let selectedValues = [];
            if (value && value.trim()) {
                selectedValues = value.split(';#').map(v => v.trim()).filter(v => v);
            }
            
            // Gebruik gesanitized ID
            multiselectHtml += '<input type="hidden" id="' + sanitizedInputId + '" name="' + field.field_name + '" value="' + value + '" ' + disabled + '>';
            
            const displayText = selectedValues.length > 0 ? selectedValues.join(', ') : 'Select options...';
            multiselectHtml += '<div class="multiselect-trigger" data-field="' + field.field_name + '" ' + (disabled ? 'data-disabled="true"' : '') + '>' +
                '<span class="multiselect-display">' + displayText + '</span>' +
                '<span class="multiselect-arrow">▼</span>' +
            '</div>';
            
            // Gebruik gesanitized field name voor dropdown
            multiselectHtml += '<div class="multiselect-dropdown-options" id="dropdown-' + this.sanitizeForSelector('file-gf-field-' + field.field_name) + '" style="display: none;">';
            
            // Rest van de code...
            let multiselectOptions = [];
            if (field.field_options) {
                if (typeof field.field_options === 'string') {
                    multiselectOptions = field.field_options.split('\n').filter(opt => opt.trim() !== '').map(opt => opt.trim());
                } else if (Array.isArray(field.field_options)) {
                    multiselectOptions = field.field_options;
                }
            }
            
            multiselectOptions.forEach(function(option, index) {
                const isChecked = selectedValues.includes(option);
                const checkboxId = sanitizedInputId + '-option-' + index;
                multiselectHtml += '<label class="multiselect-option" for="' + checkboxId + '">' +
                    '<input type="checkbox" id="' + checkboxId + '" value="' + option + '" ' + 
                    (isChecked ? 'checked' : '') + ' ' + (disabled ? 'disabled' : '') + '>' +
                    '<span>' + option + '</span>' +
                '</label>';
            });
            
            multiselectHtml += '</div>' + '</div>';
            return multiselectHtml;

        case 'checkbox':
            const checked = (value === '1' || value === 'true' || value === true) ? 'checked' : '';
            return '<div class="checkbox-container">' +
                '<input type="checkbox" id="' + sanitizedInputId + '" name="' + field.field_name + '" value="1" ' + checked + ' ' + disabled + '>' +
                '<span class="checkbox-checkmark"></span>' +
            '</div>';

        default:
            return '<input type="text" id="' + sanitizedInputId + '" name="' + field.field_name + '" placeholder="' + field.field_label + '" value="' + value + '" ' + required + ' ' + disabled + '>';
    }
}

	async saveFileInGroupfolderMetadata(fields) {
		if (!this.hasMetadataPermission()) {
			OC.Notification.showTemporary(t('testermeta', 'You do not have permission to edit metadata in this Team folder'));
			return;
		}

		if (!this.groupfolderId) {
			console.warn('No groupfolder ID available');
			return;
		}

		const form = this.el.querySelector('#testermeta-file-in-groupfolder-form');
		if (!form) return;

		const formData = {};
		fields.forEach(field => {
			const input = form.querySelector('[name="' + field.field_name + '"]');
			if (input) {
				let value = '';
				if (field.field_type === 'checkbox') {
					value = input.checked ? '1' : '0';
				} else if (field.field_type === 'multiselect') {
					// IMPROVED MULTISELECT HANDLING - get value from hidden input
					console.log('Processing multiselect field:', field.field_name);
					
					// The multiselect uses a hidden input to store the value
					if (input.type === 'hidden') {
						value = input.value || '';
					}
					console.log('Multiselect values for', field.field_name, ':', value);
				} else {
					value = input.value || '';
				}
				formData[field.field_name] = value;
			}
		});

		const itemType = this.fileInfo.type === 'dir' ? 'Folder' : 'File';
		console.log('Saving ' + itemType.toLowerCase() + '-in-groupfolder metadata:', formData);
		
		await this.saveMetadata('/apps/metavox/api/groupfolders/{groupfolderId}/files/{fileId}/metadata', { 
			groupfolderId: this.groupfolderId, 
			fileId: this.fileInfo.id 
		}, formData, itemType + ' metadata');
	}

	async saveMetadata(urlTemplate, params, formData, type) {
		try {
			// Show loading state
			const submitButton = this.el.querySelector('button[type="submit"]');
			if (submitButton) {
				submitButton.disabled = true;
				submitButton.textContent = 'Saving...';
			}

			const finalUrl = OC.generateUrl(urlTemplate, params);
			console.log('SAVE API CALL:', {
				urlTemplate,
				params,
				finalUrl,
				formData,
				type
			});

			const response = await fetch(finalUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'requesttoken': OC.requestToken
				},
				body: JSON.stringify({ metadata: formData })
			});

			const result = await response.json();
			
			if (result.success) {
				OC.Notification.showTemporary(t('testermeta', type + ' saved successfully'));
				console.log(type + ' successfully saved');
				
				// Refresh metadata to show updated values
				setTimeout(() => {
					this.loadAndRenderMetadata();
				}, 500);
			} else {
				throw new Error(result.message || 'Unknown error');
			}
		} catch (error) {
			console.error('Error saving ' + type + ':', error);
			OC.Notification.showTemporary(t('testermeta', 'Error saving ' + type + ': ' + error.message));
		} finally {
			// Reset button state
			const submitButton = this.el.querySelector('button[type="submit"]');
			if (submitButton) {
				submitButton.disabled = false;
				const buttonText = type.includes('Folder') ? 'Save Folder Metadata' : 
								  type.includes('File') ? 'Save File Metadata' : 
								  'Save Metadata';
				submitButton.textContent = buttonText;
			}
		}
	}
}

// Registratie code
(function() {
	'use strict';
	let tabRegistered = false;

	function registerTab() {
		if (tabRegistered) return;
		console.log('Registering TesterMeta sidebar tab...');
		
		if (!window.OCA || !window.OCA.Files || !window.OCA.Files.Sidebar) {
			console.log('Files app not yet available');
			return false;
		}

		const instance = new TesterMetaTab();

		try {
			if (window.OCA.Files.Sidebar._tabs && window.OCA.Files.Sidebar._tabs[instance.id]) {
				console.log('TesterMeta tab already registered, skip');
				return true;
			}

			if (window.OCA.Files.Sidebar.registerTab) {
				window.OCA.Files.Sidebar.registerTab({
					id: instance.id,
					name: instance.name,
					icon: instance.icon,
					enabled: instance.enabled,
					setIsActive: instance.setIsActive,
					getIsActive: instance.getIsActive,
					mount: instance.mount,
					update: instance.update,
					destroy: instance.destroy,
					scrollBottomReached: instance.scrollBottomReached
				});
				tabRegistered = true;
				console.log('TesterMeta tab registered successfully');
				return true;
			}
			console.error('registerTab method not available');
			return false;
		} catch (error) {
			console.error('Error registering tab:', error);
			return false;
		}
	}

	// Multiple registration attempts for reliability
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function() {
			setTimeout(registerTab, 100);
		});
	} else {
		setTimeout(registerTab, 100);
	}
	
	// Fallback registration
	setTimeout(function() {
		if (!tabRegistered) registerTab();
	}, 2000);
	
	// Additional fallback for slow loading
	setTimeout(function() {
		if (!tabRegistered) registerTab();
	}, 5000);
})();

// Enhanced File action met sidebar integration
OC.Plugins.register('OCA.Files.FileList', {
	attach: function (fileList) {
		console.log('TesterMeta Enhanced plugin attached to FileList');
		
		// Add standalone sidebar trigger button to toolbar
		const instance = new TesterMetaTab();
		
		// Create sidebar trigger button
		const toolbarHtml = '<div class="metavox-toolbar-actions" style="display: inline-block; margin-left: 10px;">' +
			'<button class="metavox-sidebar-trigger" id="metavox-open-sidebar" title="Open Metadata Sidebar">' +
				'<span class="icon testermeta-metadata-icon" style="display: inline-block; width: 16px; height: 16px; margin-right: 4px;"></span>' +
				'Metadata' +
			'</button>' +
		'</div>';
		
		// Add to Files toolbar
		const toolbar = document.querySelector('.files-controls .actions, #controls .actions');
		if (toolbar) {
			toolbar.insertAdjacentHTML('beforeend', toolbarHtml);
			
			// Bind click event
			const openSidebarBtn = document.getElementById('metavox-open-sidebar');
			if (openSidebarBtn) {
				openSidebarBtn.addEventListener('click', function() {
					// Get currently selected file or first file in list
					const selectedFiles = fileList.getSelectedFiles();
					let targetFile = null;
					
					if (selectedFiles.length > 0) {
						targetFile = selectedFiles[0];
					} else {
						// Get first file from file list
						const firstFileRow = fileList.$fileList.find('tr.file:first');
						if (firstFileRow.length > 0) {
							const fileName = firstFileRow.attr('data-file');
							targetFile = fileList.getModelForFile(fileName);
						}
					}
					
					if (targetFile) {
						// Create temporary fileInfo object
						const fileInfo = {
							id: targetFile.get('id'),
							name: targetFile.get('name'),
							path: targetFile.get('path') || fileList.getCurrentDirectory(),
							type: targetFile.get('type'),
							permissions: targetFile.get('permissions'),
							mountPoint: targetFile.get('mountPoint'),
							mountType: targetFile.get('mountType'),
							attributes: targetFile.attributes || {}
						};
						
						// Set fileInfo and open sidebar
						instance.fileInfo = fileInfo;
						instance.openSidebar();
					} else {
						OC.Notification.showTemporary(t('testermeta', 'Please select a file or folder to view metadata'));
					}
				});
			}
		}
		
		// Register file action for individual files
		fileList.fileActions.registerAction({
			name: 'testermeta_metadata_action',
			displayName: 'Edit Metadata',
			mime: 'all',
			permissions: OC.PERMISSION_READ,
			iconClass: 'icon-info',
			actionHandler: function (filename, context) {
				console.log('TesterMeta metadata action started for', filename);
				
				// Open sidebar automatically with metadata tab
				if (window.OCA && window.OCA.Files && window.OCA.Files.Sidebar) {
					const sidebar = window.OCA.Files.Sidebar;
					const fileModel = context.fileList.getModelForFile(filename);
					
					if (fileModel) {
						const filePath = '/' + fileModel.get('path') + '/' + filename;
						console.log('Opening sidebar for:', filePath);
						
						// Open sidebar
						sidebar.open(filePath);
						
						// Switch to metadata tab
						setTimeout(() => {
							sidebar.setActiveTab('testermeta-sidebar-tab');
						}, 200);
					} else {
						console.warn('Could not find file model for:', filename);
						OC.Notification.showTemporary(t('testermeta', 'Could not open metadata for this file'));
					}
				} else {
					console.warn('Sidebar not available');
					OC.Notification.showTemporary(t('testermeta', 'Sidebar not available'));
				}
			}
		});
		
		console.log('TesterMeta file action registered');
	}
});