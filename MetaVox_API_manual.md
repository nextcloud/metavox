# 1.0. Basics

## 1.1. Base URL

All API endpoints are relative to your Nextcloud installation:

https://your-nextcloud-domain.com/ocs/v2.php/apps/metavox/api/

## 1.2. Authentication

All endpoints require authentication using your Nextcloud credentials.
Use HTTP Basic Authentication or app passwords.

## 1.3. Response Format

All responses are wrapped in the standard Nextcloud OCS format:

{

\"ocs\": {

\"meta\": {

\"status\": \"ok\",

\"statuscode\": 200,

\"message\": \"OK\"

},

\"data\": {

// Actual response data here

}

}

}

**\
**

# 2.0. Understanding Field Types and Scopes

MetaVox supports two main categories of metadata fields, both stored in
the metavox_gf_fields table but distinguished by
the applies_to_groupfolder field:

## 2.1. Team Folder Metadata Fields (applies_to_groupfolder: 1)

Fields that describe the **team folder itself** as a container/project:

-   Created via POST /groupfolders/fields with applies_to_groupfolder: 1

-   Applied to the team folder as a whole (project info, department,
    budget, etc.)

-   Accessed via /groupfolders/{id}/metadata endpoints

-   Typically prefixed with gf\_ (e.g., gf_project_code)

-   Examples: project status, department, budget, manager

## 2.2. File Metadata Fields within Team Folders (applies_to_groupfolder: 0)

Fields that describe **individual files/folders** within a team folder:

-   Created via POST /groupfolders/fields with applies_to_groupfolder: 0

-   Applied to individual files within the team folder context

-   Accessed via /groupfolders/{id}/files/{fileId}/metadata endpoints

-   Typically prefixed with file_gf\_ (e.g., file_gf_document_type)

-   Examples: document type, version, author, review status

[]{#_Toc209266257 .anchor}**Key Differences**

  ------------------------------------------------------------------------------------------
  **Aspect**      **Team Folder Fields**        **File Fields**
  --------------- ----------------------------- --------------------------------------------
  **Applies to**  Team folder itself            Individual files within team folder

  **Field value** applies_to_groupfolder: 1     applies_to_groupfolder: 0

  **API           /groupfolders/{id}/metadata   /groupfolders/{id}/files/{fileId}/metadata
  endpoints**                                   

  **Prefix        gf\_                          file_gf\_
  convention**                                  

  **Use case**    Project-level metadata        Document-level metadata
  ------------------------------------------------------------------------------------------

## 2.3. Usage Example: Project Management

**Scenario:** You have a team folder for \"Project Alpha\" containing
various documents.

**Step 1:** Set team folder-level metadata (describes the project
itself):

curl -X POST
\"https://your-domain.com/ocs/v2.php/apps/metavox/api/groupfolders/5/metadata\"
\\

-u \"username:password\" \\

-d \'{

\"metadata\": {

\"project_code\": \"ALPHA-2024\",

\"project_manager\": \"John Doe\",

\"budget\": \"100000\",

\"status\": \"Active\"

}

}\'

**Step 2:** Set file-level metadata within the same team folder
(describes individual documents):

\# For requirements.pdf (file ID 123)

curl -X POST
\"https://your-domain.com/ocs/v2.php/apps/metavox/api/groupfolders/5/files/123/metadata\"
\\

-u \"username:password\" \\

-d \'{

\"metadata\": {

\"document_type\": \"Requirements\",

\"version\": \"1.2\",

\"author\": \"Jane Smith\",

\"review_status\": \"Approved\"

}

}\'

\# For design.pdf (file ID 124)

curl -X POST
\"https://your-domain.com/ocs/v2.php/apps/metavox/api/groupfolders/5/files/124/metadata\"
\\

-u \"username:password\" \\

-d \'{

\"metadata\": {

\"document_type\": \"Design\",

\"version\": \"2.1\",

\"author\": \"Bob Wilson\",

\"review_status\": \"In Review\"

}

}\'

This way:

-   **Team folder metadata** describes \"Project Alpha\" as a whole

-   **File metadata** describes each document within the project
    individually

# 3.0. File Metadata Management

## 3.1. Get File Metadata

Retrieve metadata for a specific file.

**Endpoint:** GET /files/{fileId}/metadata

**Parameters:**

-   fileId (path parameter): Nextcloud file ID (integer)

**Response:**

{

\"ocs\": {

\"data\": \[

{

\"id\": 1,

\"field_name\": \"document_type\",

\"field_label\": \"Document Type\",

\"field_type\": \"select\",

\"field_options\": \[\"Report\", \"Invoice\", \"Contract\"\],

\"is_required\": true,

\"value\": \"Report\"

}

\]

}

}

## 3.2. Save File Metadata

Save metadata values for a specific file.

**Endpoint:** POST /files/{fileId}/metadata

**Parameters:**

-   fileId (path parameter): Nextcloud file ID (integer)

-   metadata (body): Object with field names as keys and values

**Example Request:**

curl -X POST
\"https://your-domain.com/ocs/v2.php/apps/metavox/api/files/123/metadata\"
\\

-u \"username:password\" \\

-H \"Content-Type: application/json\" \\

-d \'{

\"metadata\": {

\"document_type\": \"Invoice\",

\"priority\": \"High\",

\"department\": \"Finance\"

}

}\'

**\
Response:**

{

\"ocs\": {

\"data\": {

\"success\": true

}

}

}

# 4.0. Team Folder Management

## 4.1. Get All Team Folders

Retrieve all available team folders.

**Endpoint:** GET /groupfolders

**Parameters:** None

**Response:**

{

\"ocs\": {

\"data\": \[

{

\"id\": 1,

\"mount_point\": \"Team Documents\",

\"groups\": \[\],

\"quota\": -3,

\"size\": 0,

\"acl\": false

}

\]

}

}

# 5.0. Team Folder Field Management

## 5.1. Get Team Folder Fields

Retrieve all fields configured for team folders.

**Endpoint:** GET /groupfolders/fields

**Parameters:** None

**Response:**

{

\"ocs\": {

\"data\": \[

{

\"id\": 1,

\"field_name\": \"gf_project_code\",

\"field_label\": \"Project Code\",

\"field_type\": \"text\",

\"field_description\": \"Internal project identifier\",

\"field_options\": \[\],

\"is_required\": true,

\"sort_order\": 1,

\"scope\": \"groupfolder\",

\"applies_to_groupfolder\": 1

},

{

\"id\": 2,

\"field_name\": \"file_gf_document_type\",

\"field_label\": \"Document Type\",

\"field_type\": \"select\",

\"field_description\": \"Type of document\",

\"field_options\": \[\"Requirements\", \"Design\", \"Manual\"\],

\"is_required\": false,

\"sort_order\": 2,

\"scope\": \"groupfolder\",

\"applies_to_groupfolder\": 0

}

\]

}

}

**\
**

## 5.2. Get Single Field

Retrieve a specific field by ID (works for both team folder and file
fields).

**Endpoint:** GET /fields/{id}

**Parameters:**

-   id (path parameter): Field ID (integer)

**Response:**

{

\"ocs\": {

\"data\": {

\"id\": 1,

\"field_name\": \"gf_project_code\",

\"field_label\": \"Project Code\",

\"field_type\": \"text\",

\"field_description\": \"Internal project identifier\",

\"field_options\": \[\],

\"is_required\": true,

\"sort_order\": 1,

\"scope\": \"groupfolder\",

\"applies_to_groupfolder\": 1

}

}

}

**\
**

## 5.3. Create Team Folder Field

Create a new field for team folders (either for the team folder itself
or for files within team folders).

**Endpoint:** POST /groupfolders/fields

**Parameters:**

-   field_name (required): Unique identifier for the field

-   field_label (required): Display name for the field

-   field_type (optional): Field type - default: \"text\"

    -   Available types: text, textarea, select, number, date, checkbox

-   field_description (optional): Description of the field

-   field_options (optional): Array of options for select fields

-   is_required (optional): Boolean - default: false

-   sort_order (optional): Integer for ordering - default: 0

-   applies_to_groupfolder (required): Integer - determines field usage:

    -   1 = Team folder metadata field

    -   0 = File metadata field within team folders

**Example Request (Team Folder Field):**

curl -X POST
\"https://your-domain.com/ocs/v2.php/apps/metavox/api/groupfolders/fields\"
\\

-u \"username:password\" \\

-H \"Content-Type: application/json\" \\

-d \'{

\"field_name\": \"project_status\",

\"field_label\": \"Project Status\",

\"field_type\": \"select\",

\"field_description\": \"Current status of the project\",

\"field_options\": \[\"Planning\", \"Active\", \"On Hold\",
\"Completed\"\],

\"is_required\": true,

\"applies_to_groupfolder\": 1

}\'

**Example Request (File Field):**

curl -X POST
\"https://your-domain.com/ocs/v2.php/apps/metavox/api/groupfolders/fields\"
\\

-u \"username:password\" \\

-H \"Content-Type: application/json\" \\

-d \'{

\"field_name\": \"document_type\",

\"field_label\": \"Document Type\",

\"field_type\": \"select\",

\"field_description\": \"Type of document\",

\"field_options\": \[\"Requirements\", \"Design\", \"Manual\"\],

\"is_required\": false,

\"applies_to_groupfolder\": 0

}\'

**Response:**

{

\"ocs\": {

\"data\": {

\"id\": 5,

\"success\": true

}

}

}

## 5.4. Update Field

Update an existing field.

**Endpoint:** PUT /fields/{id}

**Parameters:**

-   id (path parameter): Field ID (integer)

-   field_name (required): Field identifier

-   field_label (required): Field display name

-   field_type (required): Field type

-   field_description (optional): Field description

-   field_options (optional): Field options (string or array)

-   is_required (optional): Boolean

-   sort_order (optional): Integer

-   applies_to_groupfolder (optional): Integer (for team folder fields)

**Example Request:**

curl -X PUT
\"https://your-domain.com/ocs/v2.php/apps/metavox/api/fields/5\" \\

-u \"username:password\" \\

-H \"Content-Type: application/json\" \\

-d \'{

\"field_name\": \"project_status\",

\"field_label\": \"Project Status (Updated)\",

\"field_type\": \"select\",

\"field_description\": \"Updated description\",

\"field_options\": \[\"Planning\", \"Active\", \"On Hold\",
\"Completed\", \"Cancelled\"\],

\"is_required\": true,

\"sort_order\": 10

}\'

**\
**

## 5.5. Delete Field

Delete a field and all associated metadata.

**Endpoint:** DELETE /fields/{id}

**Parameters:**

-   id (path parameter): Field ID (integer)

**Response:**

{

\"ocs\": {

\"data\": {

\"success\": true

}

}

}

[]{#_Toc209266270 .anchor}**Get Team Folder Metadata**

Retrieve metadata for a specific team folder.

**Endpoint:** GET /groupfolders/{groupfolderId}/metadata

**Parameters:**

-   groupfolderId (path parameter): Team folder ID (integer)

**Response:**

{

\"ocs\": {

\"data\": \[

{

\"id\": 1,

\"field_name\": \"project_code\",

\"field_label\": \"Project Code\",

\"field_type\": \"text\",

\"field_options\": \[\],

\"is_required\": true,

\"applies_to_groupfolder\": 1,

\"value\": \"PRJ-2024-001\"

}

\]

}

}

## 5.6 Save Team Folder Metadata

Save metadata values for a specific team folder.

**Endpoint:** POST /groupfolders/{groupfolderId}/metadata

**Parameters:**

-   groupfolderId (path parameter): Team folder ID (integer)

-   metadata (body): Object with field names as keys and values

**Example Request:**

curl -X POST
\"https://your-domain.com/ocs/v2.php/apps/metavox/api/groupfolders/5/metadata\"
\\

-u \"username:password\" \\

-H \"Content-Type: application/json\" \\

-d \'{

\"metadata\": {

\"project_code\": \"PRJ-2024-002\",

\"project_status\": \"Active\",

\"budget\": \"50000\"

}

}\'

## 5.7. Get Team Folder File Metadata

Retrieve metadata for a file within a specific team folder.

**Endpoint:** GET /groupfolders/{groupfolderId}/files/{fileId}/metadata

**Parameters:**

-   groupfolderId (path parameter): Team folder ID (integer)

-   fileId (path parameter): File ID (integer)

## 5.7. Save Team Folder File Metadata

Save metadata for a file within a specific team folder.

**Endpoint:** POST /groupfolders/{groupfolderId}/files/{fileId}/metadata

**Parameters:**

-   groupfolderId (path parameter): Team folder ID (integer)

-   fileId (path parameter): File ID (integer)

-   metadata (body): Object with field names as keys and values

## 5.8. Get Assigned Fields for Team Folder

Get which fields are assigned to a specific team folder.

**Endpoint:** GET /groupfolders/{groupfolderId}/assigned-fields

**Parameters:**

-   groupfolderId (path parameter): Team folder ID (integer)

**Response:**

{

\"ocs\": {

\"data\": \[1, 3, 5\]

}

}

## 5.9. Set Team Folder Fields

Assign specific fields to a team folder. This determines which fields
are available for both the team folder itself and files within it.

**Endpoint:** POST /groupfolders/{groupfolderId}/fields

**Parameters:**

-   groupfolderId (path parameter): Team folder ID (integer)

-   field_ids (body): Array of field IDs to assign

**Example Request:**

curl -X POST
\"https://your-domain.com/ocs/v2.php/apps/metavox/api/groupfolders/5/fields\"
\\

-u \"username:password\" \\

-H \"Content-Type: application/json\" \\

-d \'{

\"field_ids\": \[1, 3, 5, 7\]

}\'

**Response:**

{

\"ocs\": {

\"data\": {

\"success\": true

}

}

}

**Important:** This endpoint assigns fields to the team folder.
The applies_to_groupfolder value of each field determines whether it can
be used for:

-   Team folder metadata (applies_to_groupfolder: 1)

-   File metadata within the team folder (applies_to_groupfolder: 0)

# 6.0. Usage Examples

[]{#_Toc209266277 .anchor}**Complete Workflow Example**

1.  **Create a team folder field:**

curl -X POST
\"https://your-domain.com/ocs/v2.php/apps/metavox/api/groupfolders/fields\"
\\

-u \"username:password\" \\

-d \"field_name=project_phase&field_label=Project
Phase&field_type=select&field_options\[\]=Planning&field_options\[\]=Development&field_options\[\]=Testing&field_options\[\]=Deployment&applies_to_groupfolder=1\"

2.  **Assign field to team folder:**

curl -X POST
\"https://your-domain.com/ocs/v2.php/apps/metavox/api/groupfolders/1/fields\"
\\

-u \"username:password\" \\

-d \"field_ids\[\]=1\"

3.  **Set team folder metadata:**

curl -X POST
\"https://your-domain.com/ocs/v2.php/apps/metavox/api/groupfolders/1/metadata\"
\\

-u \"username:password\" \\

-d \"metadata\[project_phase\]=Development\"

4.  **Set file metadata within team folder:**

curl -X POST
\"https://your-domain.com/ocs/v2.php/apps/metavox/api/groupfolders/1/files/123/metadata\"
\\

-u \"username:password\" \\

-d \"metadata\[project_phase\]=Testing\"
