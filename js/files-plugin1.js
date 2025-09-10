/**
 * TesterMeta Files Plugin voor Nextcloud 31
 * Enhanced Version - Met Nextcloud groupfolder permission handling
 * GLOBAL FIELDS REMOVED - Only Team folder functionality
 */

console.log('🧩 files-plugin.js gestart (Enhanced Version - Team folder only)');

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

	// 🆕 Check groupfolder permissions via Nextcloud API
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

		this.el.innerHTML = '<div class="testermeta-container">' +
			'<div class="loading">' +
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
			console.log('📁 Groupfolder detectie resultaat:', this.groupfolderId);

			// ALLEEN WERKEN ALS HET EEN GROUPFOLDER IS
			if (!this.groupfolderId) {
				console.log('🚫 Geen groupfolder gedetecteerd - toon alleen message');
				this.el.innerHTML = '<div class="testermeta-container">' +
					'<div class="emptycontent">' +
						'<div class="icon-info"></div>' +
						'<h3>' + t('testermeta', 'Team folder Only') + '</h3>' +
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
			this.el.innerHTML = '<div class="testermeta-container">' +
				'<div class="emptycontent">' +
					'<div class="icon-error"></div>' +
					'<p>' + t('testermeta', 'Error loading metadata') + '</p>' +
					'<p class="error-details">' + error.message + '</p>' +
				'</div>' +
			'</div>';
		}
	}

	renderCombinedMetadataForm(fileFields, groupfolderFields) {
		let formHtml = '<div class="testermeta-container">';

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
			formHtml += '<div class="permission-warning">' +
				'<div class="icon-info"></div>' +
				'<h4>🔒 ' + t('testermeta', 'Read-Only Access') + '</h4>' +
				'<p>' + t('testermeta', 'You have read-only access to this Team folder. You can view metadata, but you cannot edit it — this also means that documents/files within the folder cannot be modified.') + '</p>' +
			'</div>';
		}

		// === SCENARIO 1: Groupfolder root ===
		if (isGroupfolderRoot && groupfolderFields && groupfolderFields.length > 0) {
			const badgeText = '🔒 Admin Only';
			formHtml += '<div class="metadata-section readonly-section">' +
				'<h4>📁 ' + t('testermeta', 'Team folder Metadata') + ' <span class="readonly-badge">' + badgeText + '</span></h4>' +
				'<p><small>' + t('testermeta', 'This metadata applies to the entire Team folder and can only be edited by administrators in the admin settings.') + '</small></p>' +
				'<div class="readonly-fields">';
			
			groupfolderFields.forEach(field => {
				const value = field.value || t('testermeta', '(Not set)');
				formHtml += '<div class="readonly-field">' +
					'<label class="readonly-label">' + field.field_label + ':</label>' +
					'<span class="readonly-value">' + value + '</span>' +
				'</div>';
			});
			
			formHtml += '</div>' +
				'<div class="admin-hint">' +
					'<p><em>💡 ' + t('testermeta', 'To edit this Team folder metadata, go to Admin Settings → MetaVox → Team folders') + '</em></p>' +
				'</div>' +
			'</div>';
		}
		// === SCENARIO 2: Items in groupfolder ===
		else if (isInGroupfolder && !isGroupfolderRoot) {
			// Groupfolder info (always readonly)
			if (groupfolderFields && groupfolderFields.length > 0) {
				formHtml += '<div class="metadata-section readonly-section">' +
					'<h4>📁 ' + t('testermeta', 'Team folder Information') + ' <span class="readonly-badge">🔒 Read-only</span></h4>' +
					'<p><small>' + t('testermeta', 'This information applies to the entire Team folder') + '</small></p>' +
					'<div class="readonly-fields">';
				
				groupfolderFields.forEach(field => {
					const value = field.value || t('testermeta', '(Not set)');
					formHtml += '<div class="readonly-field">' +
						'<label class="readonly-label">' + field.field_label + ':</label>' +
						'<span class="readonly-value">' + value + '</span>' +
					'</div>';
				});
				
				formHtml += '</div></div>';
			}

			// Item metadata (editable if permissions allow)
			if (fileFields && fileFields.length > 0) {
				const itemType = isDirectory ? 'Folder' : 'File';
				const itemIcon = isDirectory ? '📂' : '📄';
				const permissionBadge = canEditMetadata ? '' : ' <span class="readonly-badge">🔒 Read-only</span>';
				
				formHtml += '<div class="metadata-section">' +
					'<h4>' + itemIcon + ' ' + t('testermeta', itemType + ' Metadata') + permissionBadge + '</h4>' +
					'<p><small>' + t('testermeta', 'This metadata applies only to this specific ' + itemType.toLowerCase()) + '</small></p>';
				
				if (canEditMetadata) {
					formHtml += '<form id="testermeta-file-in-groupfolder-form">';
					
					fileFields.forEach(field => {
						formHtml += '<div class="metadata-field">' +
							'<label for="file-gf-field-' + field.field_name + '">' +
								field.field_label +
								(field.is_required ? '<span class="required">*</span>' : '') +
							'</label>' +
							this.createFieldInputHtml(field, 'file-gf-field-', canEditMetadata) +
						'</div>';
					});
					
					formHtml += '<div class="metadata-actions">' +
							'<button type="submit" class="primary">💾 ' + t('testermeta', 'Save ' + itemType + ' Metadata') + '</button>' +
						'</div>' +
					'</form>';
				} else {
					// Read-only view
					formHtml += '<div class="readonly-fields">';
					fileFields.forEach(field => {
						const value = field.value || t('testermeta', '(Not set)');
						formHtml += '<div class="readonly-field">' +
							'<label class="readonly-label">' + field.field_label + ':</label>' +
							'<span class="readonly-value">' + value + '</span>' +
						'</div>';
					});
					formHtml += '</div>';
				}
				
				formHtml += '</div>';
			}
		}

		// === SCENARIO 3: Geen velden beschikbaar ===
		if ((!fileFields || fileFields.length === 0) && (!groupfolderFields || groupfolderFields.length === 0)) {
			let messageType = 'metadata';
			let contextMessage = '';
			
			if (isGroupfolderRoot) {
				messageType = 'Team folder metadata';
				contextMessage = 'No Team folder metadata fields are configured for this Team folder. Contact your administrator to set up Team folder metadata fields.';
			} else if (isInGroupfolder) {
				messageType = isDirectory ? 'folder metadata' : 'file metadata';
				contextMessage = 'No ' + messageType + ' fields are configured for items in this Team folder. Contact your administrator to set up file metadata fields.';
			}
			
			formHtml += '<div class="emptycontent">' +
				'<div class="icon-info"></div>' +
				'<p>' + t('testermeta', 'No ' + messageType + ' fields configured') + '</p>' +
				'<p><em>' + contextMessage + '</em></p>' +
			'</div>';
		}

		formHtml += '</div>';
		this.el.innerHTML = formHtml;

		// Add enhanced styling
		this.addEnhancedCSS();

		// Bind form events - alleen als gebruiker edit rechten heeft
		const fileInGroupfolderForm = this.el.querySelector('#testermeta-file-in-groupfolder-form');
		if (fileInGroupfolderForm && isInGroupfolder && !isGroupfolderRoot && canEditMetadata) {
			fileInGroupfolderForm.addEventListener('submit', (e) => {
				e.preventDefault();
				this.saveFileInGroupfolderMetadata(fileFields);
			});
		}
	}

	// Enhanced CSS met permission styling
	addEnhancedCSS() {
		if ($('#testermeta-enhanced-css').length > 0) {
			return;
		}

		$('<style id="testermeta-enhanced-css">').text(`
			.permission-warning {
				background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%) !important;
				border: 1px solid #f59e0b !important;
				border-radius: 8px !important;
				padding: 12px 16px !important;
				margin-bottom: 16px !important;
				display: flex !important;
				align-items: flex-start !important;
				gap: 10px !important;
			}
			
			.permission-warning .icon-info {
				font-size: 16px !important;
				color: #f59e0b !important;
				margin-top: 1px !important;
			}
			
			.permission-warning h4 {
				color: #92400e !important;
				margin: 0 0 4px 0 !important;
				font-weight: 600 !important;
				font-size: 14px !important;
			}
			
			.permission-warning p {
				color: #92400e !important;
				margin: 0 !important;
				font-size: 13px !important;
				line-height: 1.4 !important;
			}
			
			.readonly-section {
				background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%) !important;
				border: 2px solid #cbd5e1 !important;
				border-radius: 12px !important;
				padding: 20px !important;
				margin-bottom: 20px !important;
				position: relative !important;
			}
			
			.readonly-section::before {
				content: '' !important;
				position: absolute !important;
				top: 0 !important;
				left: 0 !important;
				right: 0 !important;
				height: 4px !important;
				background: linear-gradient(90deg, #64748b 0%, #94a3b8 100%) !important;
				border-radius: 12px 12px 0 0 !important;
			}
			
			.readonly-badge {
				display: inline-block !important;
				background: #6c757d !important;
				color: white !important;
				padding: 4px 12px !important;
				border-radius: 16px !important;
				font-size: 11px !important;
				font-weight: bold !important;
				margin-left: 10px !important;
				text-transform: uppercase !important;
			}
			
			.readonly-fields {
				display: grid !important;
				grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)) !important;
				gap: 15px !important;
				margin-top: 15px !important;
			}
			
			.readonly-field {
				background: #ffffff !important;
				padding: 15px !important;
				border-radius: 10px !important;
				border: 1px solid #e2e8f0 !important;
				box-shadow: 0 1px 3px rgba(0,0,0,0.1) !important;
			}
			
			.readonly-label {
				font-weight: 600 !important;
				color: #374151 !important;
				font-size: 13px !important;
				display: block !important;
				margin-bottom: 8px !important;
				text-transform: uppercase !important;
				letter-spacing: 0.5px !important;
			}
			
			.readonly-value {
				color: #1f2937 !important;
				font-size: 14px !important;
				font-weight: 500 !important;
				word-break: break-word !important;
			}
			
			.metadata-section {
				margin-bottom: 25px !important;
				padding: 25px !important;
				border: 2px solid #e2e8f0 !important;
				border-radius: 16px !important;
				background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%) !important;
				box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1) !important;
				position: relative !important;
				overflow: hidden !important;
			}
			
			.metadata-section::before {
				content: '' !important;
				position: absolute !important;
				top: 0 !important;
				left: 0 !important;
				right: 0 !important;
				height: 4px !important;
				background: linear-gradient(90deg, #3b82f6 0%, #8b5cf6 50%, #06b6d4 100%) !important;
			}
			
			.metadata-section h4 {
				color: #1e293b !important;
				margin: 0 0 20px 0 !important;
				font-weight: 600 !important;
				font-size: 18px !important;
				display: flex !important;
				align-items: center !important;
			}
			
			.metadata-field {
				margin-bottom: 20px !important;
			}
			
			.metadata-field label {
				display: block !important;
				font-weight: 600 !important;
				color: #374151 !important;
				margin-bottom: 8px !important;
				font-size: 14px !important;
			}
			
			.metadata-field input,
			.metadata-field textarea,
			.metadata-field select {
				width: 100% !important;
				padding: 12px 16px !important;
				border: 2px solid #e5e7eb !important;
				border-radius: 10px !important;
				font-size: 14px !important;
				transition: all 0.3s ease !important;
				box-sizing: border-box !important;
				background: #ffffff !important;
			}
			
			.metadata-field input:focus,
			.metadata-field textarea:focus,
			.metadata-field select:focus {
				outline: none !important;
				border-color: #3b82f6 !important;
				box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1) !important;
				transform: translateY(-1px) !important;
			}
			
			.metadata-field input:disabled,
			.metadata-field textarea:disabled,
			.metadata-field select:disabled {
				background: #f3f4f6 !important;
				color: #6b7280 !important;
				cursor: not-allowed !important;
				border-color: #d1d5db !important;
			}
			
			.metadata-field textarea {
				min-height: 100px !important;
				resize: vertical !important;
			}
			
			.metadata-actions {
				margin-top: 25px !important;
				padding-top: 20px !important;
				border-top: 2px solid #f1f5f9 !important;
				display: flex !important;
				gap: 12px !important;
				justify-content: flex-end !important;
			}
			
			.metadata-actions button {
				padding: 8px 16px !important;
				border: 1px solid transparent !important;
				border-radius: 8px !important;
				font-weight: 500 !important;
				cursor: pointer !important;
				transition: all 0.2s ease !important;
				font-size: 13px !important;
				text-transform: none !important;
				letter-spacing: normal !important;
			}
			
			.metadata-actions button.primary {
				background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%) !important;
				color: white !important;
				border-color: #3b82f6 !important;
				box-shadow: 0 1px 3px rgba(59, 130, 246, 0.2) !important;
			}
			
			.metadata-actions button.primary:hover {
				background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%) !important;
				transform: translateY(-1px) !important;
				box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3) !important;
			}
			
			.metadata-actions button.primary:disabled {
				background: #d1d5db !important;
				cursor: not-allowed !important;
				transform: none !important;
				box-shadow: none !important;
			}
			
			.admin-hint {
				margin-top: 20px !important;
				padding: 15px !important;
				background: linear-gradient(135deg, #fff3cd 0%, #fef3c7 100%) !important;
				border: 2px solid #fbbf24 !important;
				border-radius: 10px !important;
			}
			
			.admin-hint p {
				margin: 0 !important;
				font-size: 13px !important;
				color: #92400e !important;
				font-weight: 500 !important;
			}
			
			.emptycontent {
				text-align: center !important;
				padding: 60px 20px !important;
				color: #64748b !important;
				background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%) !important;
				border-radius: 16px !important;
				border: 2px dashed #cbd5e1 !important;
			}
			
			.emptycontent .icon-info {
				font-size: 48px !important;
				color: #94a3b8 !important;
				margin-bottom: 20px !important;
			}
			
			.emptycontent .icon-error {
				font-size: 48px !important;
				color: #ef4444 !important;
				margin-bottom: 20px !important;
			}
			
			.emptycontent h3 {
				color: #1e293b !important;
				margin: 0 0 15px 0 !important;
				font-size: 20px !important;
				font-weight: 600 !important;
			}
			
			.emptycontent p {
				font-size: 16px !important;
				margin-bottom: 10px !important;
				font-weight: 500 !important;
			}
			
			.emptycontent em {
				font-size: 14px !important;
				color: #94a3b8 !important;
			}
			
			.required {
				color: #ef4444 !important;
				margin-left: 4px !important;
				font-weight: 700 !important;
			}
			
			.loading {
				text-align: center !important;
				padding: 40px !important;
				color: #64748b !important;
				font-style: italic !important;
				font-size: 14px !important;
			}
			
			.loading::before {
				content: '' !important;
				display: inline-block !important;
				width: 20px !important;
				height: 20px !important;
				border: 2px solid #e2e8f0 !important;
				border-top: 2px solid #3b82f6 !important;
				border-radius: 50% !important;
				animation: spin 1s linear infinite !important;
				margin-right: 10px !important;
				vertical-align: middle !important;
			}
			
			@keyframes spin {
				0% { transform: rotate(0deg); }
				100% { transform: rotate(360deg); }
			}
			
			@media (max-width: 768px) {
				.readonly-fields {
					grid-template-columns: 1fr !important;
				}
				
				.metadata-actions {
					flex-direction: column !important;
				}
				
				.metadata-actions button {
					width: 100% !important;
				}
				
				.metadata-section {
					padding: 20px 15px !important;
				}
			}
		`).appendTo('head');
	}

	createFieldInputHtml(field, prefix, isEditable = true) {
		prefix = prefix || '';
		const inputId = prefix + field.field_name;
		const value = field.value || '';
		const required = field.is_required ? 'required' : '';
		const disabled = !isEditable ? 'disabled' : '';

		switch (field.field_type) {
			case 'textarea':
				return '<textarea id="' + inputId + '" name="' + field.field_name + '" placeholder="' + field.field_label + '" ' + required + ' ' + disabled + '>' + value + '</textarea>';
			case 'number':
				return '<input type="number" id="' + inputId + '" name="' + field.field_name + '" placeholder="' + field.field_label + '" value="' + value + '" ' + required + ' ' + disabled + '>';
			case 'date':
				return '<input type="date" id="' + inputId + '" name="' + field.field_name + '" value="' + value + '" ' + required + ' ' + disabled + '>';
			case 'select':
				let selectHtml = '<select id="' + inputId + '" name="' + field.field_name + '" ' + required + ' ' + disabled + '>';
				selectHtml += '<option value="">' + t('testermeta', 'Select...') + '</option>';
				
				// 🔧 FIX: Properly handle field_options - kan string of array zijn
				let options = [];
				if (field.field_options) {
					if (typeof field.field_options === 'string') {
						// String splitsen op newlines en lege regels eruit filteren
						options = field.field_options.split('\n').filter(function(opt) {
							return opt.trim() !== '';
						}).map(function(opt) {
							return opt.trim();
						});
					} else if (Array.isArray(field.field_options)) {
						// Al een array
						options = field.field_options;
					}
				}
				
				options.forEach(function(option) {
					const selected = value === option ? 'selected' : '';
					selectHtml += '<option value="' + option + '" ' + selected + '>' + option + '</option>';
				});
				selectHtml += '</select>';
				return selectHtml;
			case 'checkbox':
                const checked = (value === '1' || value === 'true' || value === true) ? 'checked' : '';
                return '<div class="checkbox-container">' +
               '<input type="checkbox" id="' + inputId + '" name="' + field.field_name + '" value="1" ' + checked + ' ' + disabled + '>' +
               '<span class="checkbox-checkmark"></span>' +
           '</div>';
		}
	}

	async saveFileInGroupfolderMetadata(fields) {
		if (!this.hasMetadataPermission()) {
			OC.Notification.showTemporary(t('testermeta', 'You do not have permission to edit metadata in this Team folder'));
			return;
		}

		if (!this.groupfolderId) {
			console.warn('⚠️ Geen groupfolder ID beschikbaar');
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
				} else {
					value = input.value || '';
				}
				formData[field.field_name] = value;
			}
		});

		const itemType = this.fileInfo.type === 'dir' ? 'Folder' : 'File';
		console.log('💾 Opslaan ' + itemType.toLowerCase() + '-in-groupfolder metadata:', formData);
		
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
				submitButton.textContent = '💾 ' + t('testermeta', 'Saving...');
			}

			const finalUrl = OC.generateUrl(urlTemplate, params);
			console.log('🌐 SAVE API CALL:', {
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
				console.log('✅ ' + type + ' succesvol opgeslagen');
				
				// Refresh metadata to show updated values
				setTimeout(() => {
					this.loadAndRenderMetadata();
				}, 500);
			} else {
				throw new Error(result.message || 'Unknown error');
			}
		} catch (error) {
			console.error('❌ Fout bij opslaan ' + type + ':', error);
			OC.Notification.showTemporary(t('testermeta', 'Error saving ' + type + ': ' + error.message));
		} finally {
			// Reset button state
			const submitButton = this.el.querySelector('button[type="submit"]');
			if (submitButton) {
				submitButton.disabled = false;
				const buttonText = type.includes('Folder') ? 'Save Folder Metadata' : 
								  type.includes('File') ? 'Save File Metadata' : 
								  'Save Metadata';
				submitButton.textContent = '💾 ' + t('testermeta', buttonText);
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
		console.log('🔧 Registreren TesterMeta sidebar tab...');
		
		if (!window.OCA || !window.OCA.Files || !window.OCA.Files.Sidebar) {
			console.log('⏳ Files app nog niet beschikbaar');
			return false;
		}

		const instance = new TesterMetaTab();

		try {
			if (window.OCA.Files.Sidebar._tabs && window.OCA.Files.Sidebar._tabs[instance.id]) {
				console.log('⚠️ TesterMeta tab al geregistreerd, skip');
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
				console.log('✅ TesterMeta tab geregistreerd');
				return true;
			}
			console.error('❌ registerTab methode niet beschikbaar');
			return false;
		} catch (error) {
			console.error('❌ Fout bij registreren tab:', error);
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

// Enhanced File action met permission awareness en sidebar integration
OC.Plugins.register('OCA.Files.FileList', {
	attach: function (fileList) {
		console.log('🟢 TesterMeta Enhanced plugin gekoppeld aan FileList');
		
		fileList.fileActions.registerAction({
			name: 'testermeta_metadata_action',
			displayName: '📝 ' + t('testermeta', 'Edit Metadata'),
			mime: 'all',
			permissions: OC.PERMISSION_READ, // Minimaal read permission vereist
			iconClass: 'icon-info',
			actionHandler: function (filename, context) {
				console.log('⚙️ TesterMeta metadata actie gestart voor', filename);
				
				// Open sidebar automatisch met metadata tab
				if (window.OCA && window.OCA.Files && window.OCA.Files.Sidebar) {
					const sidebar = window.OCA.Files.Sidebar;
					const fileModel = context.fileList.getModelForFile(filename);
					
					if (fileModel) {
						const filePath = '/' + fileModel.get('path') + '/' + filename;
						console.log('📂 Opening sidebar for:', filePath);
						
						// Open sidebar
						sidebar.open(filePath);
						
						// Switch to metadata tab
						setTimeout(() => {
							sidebar.setActiveTab('testermeta-sidebar-tab');
						}, 200);
					} else {
						console.warn('⚠️ Could not find file model for:', filename);
						OC.Notification.showTemporary(t('testermeta', 'Could not open metadata for this file'));
					}
				} else {
					console.warn('⚠️ Sidebar not available');
					OC.Notification.showTemporary(t('testermeta', 'Sidebar not available'));
				}
			}
		});
		
		console.log('✅ TesterMeta file action geregistreerd');
	}
});