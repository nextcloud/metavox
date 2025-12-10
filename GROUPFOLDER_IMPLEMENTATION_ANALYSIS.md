# MetaVox Project - Group Folders Implementation Analysis

## Projectoverzicht
MetaVox is een Nextcloud App voor metadata en file management met ondersteuning voor team folders (groupfolders).

**Project locatie:** `/Users/samditmeijer/Downloads/metavox`

---

## 1. CONTROLLER BESTANDEN - GROUP FOLDER OPHALEN

### A. FieldController.php
**Locatie:** `/Users/samditmeijer/Downloads/metavox/lib/Controller/FieldController.php`

#### Methoden voor Group Folders:
- **getGroupfolders()** (line 191-198)
  - Route: GET `/api/groupfolders`
  - Decorators: `@NoAdminRequired`
  - Aanroept: `FieldService::getGroupfolders()`
  - Retourneert: JSONResponse met array van groupfolders

- **getGroupfolderMetadata()** (line 203-210)
  - Route: GET `/api/groupfolders/{groupfolderId}/metadata`
  - Decorators: `@NoAdminRequired`
  - Aanroept: `FieldService::getGroupfolderMetadata($groupfolderId)`

- **getGroupfolderFields()** (line 249-256)
  - Route: GET `/api/groupfolder-fields`
  - Decorators: `@NoAdminRequired`
  - Aanroept: `FieldService::getFieldsByScope('groupfolder')`

### B. UserFieldController.php
**Locatie:** `/Users/samditmeijer/Downloads/metavox/lib/Controller/UserFieldController.php`

#### Methoden met Authorisatie:
- **getAccessibleGroupfolders()** (line 34-49)
  - Route: GET `/api/user/groupfolders`
  - Decorators: `@NoAdminRequired`
  - Authenticatie: Haalt user session op en valideert authenticatie
  - Aanroept: `UserFieldService::getAccessibleGroupfolders($userId)`
  - **IMPORTANT:** Filtert groupfolders op basis van user permissions/groups

- **getGroupfolderFields()** (line 56-63)
  - Route: GET `/api/user/groupfolders/{groupfolderId}/fields`
  - Decorators: `@NoAdminRequired`
  - Aanroept: `UserFieldService::getGroupfolderFields($groupfolderId)`

- **getGroupfolderMetadata()** (line 84-91)
  - Route: GET `/api/user/groupfolders/{groupfolderId}/metadata`
  - Decorators: `@NoAdminRequired`
  - Aanroept: `UserFieldService::getGroupfolderMetadata($groupfolderId)`

### C. ApiFieldController.php
**Locatie:** `/Users/samditmeijer/Downloads/metavox/lib/Controller/ApiFieldController.php`

- **getGroupfolders()** (OCS API endpoint)
  - Route: GET `/api/v1/groupfolders`
  - Decorators: `@NoAdminRequired`, `@NoCSRFRequired`, `@CORS`
  - Aanroept: `FieldService::getGroupfolders()`

### D. FilterController.php
**Locatie:** `/Users/samditmeijer/Downloads/metavox/lib/Controller/FilterController.php`

- **filterFiles()** (line 36-66)
  - Route: POST `/api/groupfolders/{groupfolderId}/filter`
  - Parameters: `groupfolderId`, `filters`, `path`
  - Authenticatie check: `$this->userSession->getUser()`
  - Aanroept: `FilterService::filterFilesByMetadata($groupfolderId, $filters, $userId, $path)`

---

## 2. SERVICE BESTANDEN - IMPLEMENTATIE VAN FUNCTIONALITEIT

### A. FieldService.php
**Locatie:** `/Users/samditmeijer/Downloads/metavox/lib/Service/FieldService.php`

#### getGroupfolders() Methode (line 591-622)
```php
public function getGroupfolders(): array {
    // Query: SELECT folder_id, mount_point FROM group_folders ORDER BY folder_id
    // Source: Nextcloud's group_folders table
    // Returns: Array van groepfolders met id, mount_point, quota, size, acl
}
```

**Implementatiedetails:**
- Haalt direct uit Nextcloud's `group_folders` table
- Geen filtering op user permissions
- Geen join met metavox_gf_fields
- Zeer simpele query om versie-compatibility te behouden
- Error handling: Retourneert lege array bij error

**Database schema:**
```
group_folders table:
- folder_id (PK)
- mount_point
- quota
- size
- acl
- versioning
- trashbin_retention
- ...
```

#### getGroupfolderMetadata() Methode (line 623-657)
```php
SELECT f.id, f.field_name, f.field_label, f.field_type, 
       f.field_options, f.is_required, f.applies_to_groupfolder, 
       v.field_value as value
FROM metavox_gf_fields f
INNER JOIN metavox_gf_assigns gf ON f.id = gf.field_id AND gf.groupfolder_id = :groupfolder_id
LEFT JOIN metavox_gf_metadata v ON f.field_name = v.field_name AND v.groupfolder_id = :groupfolder_id
ORDER BY f.sort_order
```

**Implementatiedetails:**
- INNER JOIN met `metavox_gf_assigns` = alleen assigned fields
- LEFT JOIN met `metavox_gf_metadata` = include field values
- Filteren op `applies_to_groupfolder` flag mogelijk
- Retourneert field definities met huidige waarden

#### getFieldsByScope() Methode (line 125-175)
```php
public function getFieldsByScope(string $scope = 'global'): array
```
- **scope = 'groupfolder'** -> Query `metavox_gf_fields` table
- **scope = 'global'** -> Query `metavox_fields` table
- Includes caching per request
- Haalt full field data op inclusief options

### B. UserFieldService.php
**Locatie:** `/Users/samditmeijer/Downloads/metavox/lib/Service/UserFieldService.php`

#### getAccessibleGroupfolders() Methode (line 34-113)
```php
public function getAccessibleGroupfolders(string $userId): array
```

**AUTHORISATIE LOGIC:**

1. **User Validation:**
   - Haalt user object op via `IUserManager::get($userId)`
   - Retourneert lege array als user niet bestaat

2. **Admin Check:**
   - `$this->groupManager->isInGroup($userId, 'admin')`
   - `$this->groupManager->isAdmin($userId)`
   - (Note: Admins worden NIET automatisch alle groepfolders gegeven in deze methode!)

3. **Group Membership Query:**
   ```php
   SELECT f.* FROM group_folders f
   // Vervolgens per folder:
   SELECT group_id, permissions FROM group_folders_groups 
   WHERE folder_id = :folder_id
   ```

4. **Access Check Logic:**
   ```php
   foreach ($userGroups as $userGroup) {
       if (in_array($userGroup, $folderGroups)) {
           $hasAccess = true;  // User is in at least one folder group
           break;
       }
   }
   ```

5. **Return Format:**
   ```php
   [
       'id' => $folderId,
       'mount_point' => $mountPoint,
       'groups' => $folderGroups,
       'quota' => $quota,
       'size' => $size,
       'acl' => $acl
   ]
   ```

**Belangrijke opmerking:** User moet in minstens één van de folder's geassocieerde groepen zitten!

#### getGroupfolderMetadata() Methode (line 142-175)
```php
public function getGroupfolderMetadata(int $groupfolderId): array
```
- INNER JOIN met `metavox_gf_assigns` (only assigned fields)
- LEFT JOIN met `metavox_gf_metadata` (to get values)
- Filtert op `groupfolder_id`
- Ordered by sort_order

### C. FilterService.php
**Locatie:** `/Users/samditmeijer/Downloads/metavox/lib/Service/FilterService.php`

#### filterFilesByMetadata() Methode (line 42-144)
```php
public function filterFilesByMetadata(
    int $groupfolderId, 
    array $filters, 
    string $userId, 
    string $path = '/'
): array
```

**Query Strategie:**
1. Base query: `SELECT DISTINCT fm.file_id FROM metavox_file_gf_meta`
2. Filter: `WHERE groupfolder_id = :groupfolder_id`
3. Voor elk filter: INNER JOIN met `metavox_file_gf_meta` alias
4. Build WHERE conditions op basis van operator (equals, contains, greater_than, etc.)
5. Get file details: `$this->getFileDetailsWithMetadata($groupfolderId, $fileIds, $userId)`

**Database Optimization:**
- Indexes aanwezig (Migration Version20250101000010):
  - `idx_gf_file_meta_filter`: (groupfolder_id, field_name, field_value)
  - `idx_gf_file_meta_file_id`: (file_id, groupfolder_id)
  - `idx_gf_file_meta_timestamps`: (created_at, updated_at)
- Verwachte performance: 40-100x sneller met indexes

#### buildFilterCondition() Methode (line 150-247)
Ondersteunde operators:
- **String operators:** equals, not_equals, contains, not_contains, starts_with, ends_with
- **Numeric operators:** greater_than, less_than, greater_or_equal, less_or_equal
- **Empty operators:** is_empty, is_not_empty
- **Array operators:** one_of (for multiselect), between (for date ranges)
- **Checkbox:** Special handling voor '1' vs '' (empty)

#### getFileDetailsWithMetadata() Methode (line 252-288)
```php
private function getFileDetailsWithMetadata(
    int $groupfolderId, 
    array $fileIds, 
    string $userId
): array
```
- Haalt file details op via `IRootFolder::getUserFolder($userId)->getById($fileId)`
- Gets metadata via `getFileMetadata($groupfolderId, $fileId)`
- Returns: Array met file info (id, name, path, type, mime, size, mtime, permissions)

---

## 3. DATABASE SCHEMA & TABELLEN

### Group Folder Tabellen (Nextcloud core):
- **group_folders**
  - folder_id (PK)
  - mount_point
  - quota
  - size
  - acl
  - versioning
  - trashbin_retention

- **group_folders_groups**
  - folder_id (FK)
  - group_id (FK to oc_groups)
  - permissions

### MetaVox Groupfolder Tabellen:

#### metavox_gf_fields
- id (PK)
- field_name
- field_label
- field_type
- field_description
- field_options (JSON)
- is_required
- sort_order
- applies_to_groupfolder (0=file metadata, 1=team folder metadata)
- created_at
- updated_at

#### metavox_gf_assigns
- id (PK)
- groupfolder_id (FK to group_folders.folder_id)
- field_id (FK to metavox_gf_fields.id)

#### metavox_gf_metadata
- id (PK)
- groupfolder_id (FK to group_folders.folder_id)
- field_name
- field_value
- created_at
- updated_at

#### metavox_file_gf_meta
- id (PK)
- groupfolder_id (FK to group_folders.folder_id)
- file_id (FK to oc_filecache.fileid)
- field_name
- field_value
- created_at
- updated_at
- **INDEXES:**
  - idx_gf_file_meta_filter: (groupfolder_id, field_name, field_value)
  - idx_gf_file_meta_file_id: (file_id, groupfolder_id)
  - idx_gf_file_meta_timestamps: (created_at, updated_at)

---

## 4. AUTHORISATIE & PERMISSIE CHECKS

### PermissionService.php
**Locatie:** `/Users/samditmeijer/Downloads/metavox/lib/Service/PermissionService.php`

#### Permission Types:
```php
const PERM_VIEW_METADATA = 'view_metadata';
const PERM_EDIT_METADATA = 'edit_metadata';
const PERM_MANAGE_FIELDS = 'manage_fields';
```

#### hasPermission() Methode (line 36-64)
```php
public function hasPermission(
    string $userId, 
    string $permissionType, 
    ?int $groupfolderId = null,
    ?string $fieldScope = null
): bool
```

**Logic:**
1. Admins always have all permissions
2. Check user-specific permissions
3. Check group-based permissions (via user's groups)
4. Return true if any permission found

#### Database Table: metavox_permissions
```
- id (PK)
- user_id (nullable)
- group_id (nullable)
- permission_type
- groupfolder_id (nullable - null = global permission)
- field_scope (nullable)
- created_at
- updated_at
```

#### Permission Queries:
```sql
-- User-specific
SELECT id FROM metavox_permissions
WHERE user_id = :user_id
  AND permission_type = :type
  AND (groupfolder_id = :gf_id OR groupfolder_id IS NULL)
  AND (field_scope = :scope OR field_scope IS NULL)

-- Group-based
SELECT id FROM metavox_permissions
WHERE group_id = :group_id
  AND permission_type = :type
  AND (groupfolder_id = :gf_id OR groupfolder_id IS NULL)
  AND (field_scope = :scope OR field_scope IS NULL)
```

### PermissionController.php
**Locatie:** `/Users/samditmeijer/Downloads/metavox/lib/Controller/PermissionController.php`

#### Admin-only Endpoints:
- **getGroups()** (line 48-68)
  - Check: `!$this->isAdmin()` -> return 403
  - Gets all groups from `IGroupManager::search('')`

- **getAllPermissions()** (line 74-85)
  - Admin required
  - Returns all permission records

- **grantUserPermission()** (line 137-167)
  - Admin required
  - Creates permission record

- **grantGroupPermission()** (line 172-202)
  - Admin required
  - Creates permission record

- **revokeUserPermission()** (line 207-238)
  - Admin required
  - Deletes permission record

- **revokeGroupPermission()** (line 243-273)
  - Admin required
  - Deletes permission record

#### User Endpoints:
- **getMyPermissions()** (line 92-104)
  - No admin required
  - Returns user's own permissions (direct + via groups)

- **checkPermission()** (line 111-132)
  - No admin required
  - Checks if user has specific permission
  - Returns `{ hasPermission: bool }`

---

## 5. CURRENT PERMISSION/AUTHORISATIE IMPLEMENTATION

### At FieldController Level (Web Interface):
- **NO AUTHORIZATION CHECKS** - alle methoden hebben `@NoAdminRequired`
- `getGroupfolders()` - geen filtering, returns all folders
- `getGroupfolderMetadata()` - direct access, geen user check
- `getGroupfolderFields()` - geen access control

### At UserFieldController Level (User-focused):
- **HAS AUTHORIZATION** - filtered groupfolders
- `getAccessibleGroupfolders()` - FILTERS op user's group membership
- Only returns folders user has access to via their groups

### At FilterController Level:
- **HAS USER AUTHENTICATION**
- `filterFiles()` - valideert user session
- Haalt user ID op via `$this->userSession->getUser()`
- Passed userId naar FilterService

### Observation:
```
1. FieldController (public API):
   - getGroupfolders() -> ALL folders (no filtering)
   - NO permission checks!

2. UserFieldController (user API):
   - getAccessibleGroupfolders() -> FILTERED folders
   - DOES permission check via group membership

3. FilterController:
   - Has user session validation
   - Passes userId to service
```

---

## 6. ROUTE CONFIGURATIE

**File:** `/Users/samditmeijer/Downloads/metavox/appinfo/routes.php`

### Web Routes (standard):
```
GET    /api/groupfolders                                    -> field#getGroupfolders
GET    /api/groupfolders/{groupfolderId}/metadata          -> field#getGroupfolderMetadata
GET    /api/groupfolders/{groupfolderId}/fields            -> field#getGroupfolderAssignedFields
POST   /api/groupfolders/{groupfolderId}/fields            -> field#setGroupfolderFields
GET    /api/groupfolder-fields                             -> field#getGroupfolderFields
POST   /api/groupfolders/{groupfolderId}/filter            -> filter#filterFiles
GET    /api/groupfolders/{groupfolderId}/filter-fields     -> filter#getFilterFields
```

### User Routes (avec filtering):
```
GET    /api/user/groupfolders                              -> user_field#getAccessibleGroupfolders
GET    /api/user/groupfolders/{groupfolderId}/metadata     -> user_field#getGroupfolderMetadata
```

### OCS API Routes (external/CORS):
```
GET    /ocs/v2.php/apps/metavox/api/v1/groupfolders       -> apiField#getGroupfolders
GET    /ocs/v2.php/apps/metavox/api/v1/groupfolders/{id}/metadata
```

---

## 7. SAMENVATTING & BEVINDINGEN

### Sterke punten:
1. **Separate filtering logic** - UserFieldService vs FieldService
2. **Permission system implemented** - PermissionService met user/group support
3. **Database optimization** - Indexes voor filter performance
4. **Multiple access layers** - Web routes, OCS API, User-specific endpoints
5. **Detailed logging** - error_log() statements voor debugging

### Aandachtspunten:
1. **FieldController lacks authorization** - getGroupfolders() heeft geen filtering
2. **Group membership check only** - Geen fine-grained field-level permissions
3. **No explicit permission check in filters** - FilterController valideert user session maar niet folder access
4. **Admin shortcut** - Admins krijgen alle permissies zonder explicit records

### Filteringmechanisme:
- **FieldService.getGroupfolders()** -> Alle folders
- **UserFieldService.getAccessibleGroupfolders()** -> Gefilterde folders op basis van user's group membership
- **FilterService** -> Haalt files op, geen folder-level filtering

### Authorization Flow:
```
Request -> Controller -> Service -> Database
   |          |           |
   v          v           v
User Session  Permission  Group Membership
              Check       Check
```

---

## 8. KEY FILES REFERENCE

| File | Purpose | Key Methods |
|------|---------|------------|
| `/lib/Controller/FieldController.php` | Web UI for global & groupfolder fields | getGroupfolders, getGroupfolderMetadata, getGroupfolderFields |
| `/lib/Controller/UserFieldController.php` | User-specific groupfolder access | getAccessibleGroupfolders, getGroupfolderMetadata |
| `/lib/Controller/ApiFieldController.php` | OCS REST API endpoint | getGroupfolders, getGroupfolderMetadata |
| `/lib/Controller/FilterController.php` | File filtering by metadata | filterFiles, getFilterFields |
| `/lib/Controller/PermissionController.php` | Permission management | hasPermission, grantUserPermission, grantGroupPermission |
| `/lib/Service/FieldService.php` | Field & metadata operations | getGroupfolders, getGroupfolderMetadata, getFieldsByScope |
| `/lib/Service/UserFieldService.php` | User-filtered groupfolder access | getAccessibleGroupfolders, getGroupfolderMetadata |
| `/lib/Service/FilterService.php` | Advanced file filtering | filterFilesByMetadata, buildFilterCondition |
| `/lib/Service/PermissionService.php` | Permission system | hasPermission, grantUserPermission, grantGroupPermission |

