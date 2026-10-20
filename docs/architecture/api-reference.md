# MetaVox API Reference

## Overview
The MetaVox API provides endpoints for metadata management, field configuration, views, filter values, and batch operations.

### Route Types

MetaVox exposes two types of API routes:

| Type | Base URL | Auth | Use case |
|------|----------|------|----------|
| **OCS** | `/ocs/v2.php/apps/metavox/api/v1/` | App password, OAuth, or session | External integrations, scripts, migrations |
| **Browser** | `/apps/metavox/api/` | Session (CSRF token required) | Internal UI, admin operations |

**For external integrations**, always use OCS routes — they don't require a CSRF token and work with app passwords.

**Browser routes** are used by the MetaVox frontend. They require a Nextcloud session and CSRF token. Some features (permissions, default values, AI, backup, settings, license, telemetry) are only available via browser routes. Browser routes are internal to the UI and not a stable API: their paths, parameters and answers can change in any release. They are listed here for reference.

**WebDAV**: in a folder listing of a team folder, MetaVox adds the `nc:metavox` property with each file's values. See [WebDAV property `nc:metavox`](#webdav-property-ncmetavox).

## Architecture

| Controller | Responsibility |
|------------|---------------|
| **`ApiFieldController`** | OCS endpoints: fields, file and team folder metadata, batch operations, statistics |
| **`ApiFilterController`** | OCS endpoints: directory metadata, filter values, sorted file IDs |
| **`ApiViewController`** | OCS endpoints: view CRUD per groupfolder |
| **`ViewController`** | Browser-based view management (admin UI, requires session) |
| **`LockController`** | Cell lock/unlock for concurrent editing |
| **`PresenceController`** | Presence tracking (leave on tab close) |
| **`AiAutofillController`** | AI metadata generation |
| **`BackupController`** | Backup & restore operations |
| **`SettingsController`** | Admin settings (AI toggle) |
| **`TelemetryController`** | Usage telemetry reporting |
| **`PermissionController`** | Grants of the manage permission (users and groups) |
| **`DefaultsController`** | Default values per team folder |
| **`LicenseController`** | License key, validation and usage |
| **`MetadataPropertyPlugin`** | The `nc:metavox` WebDAV property in folder listings |
| **`FieldService`** | Field definitions, metadata reads/writes — with distributed cache |
| **`ViewService`** | View CRUD — with distributed cache |

Every route declares who may call it with `#[RequireAccess]`; `AccessMiddleware` checks it before the controller runs. The **Requires** lines below follow these rules. *May change the team folder* means Nextcloud's update permission on the team folder's top folder, ACL included. Nextcloud admins pass every check. See [Access errors](#access-errors) for the answers when a check fails.

## Important Notes
- All batch operations work with **groupfolder file fields** (stored in `metavox_gf_fields` and `metavox_file_gf_meta`)
- Batch update/delete operations require both `file_id` and `groupfolder_id` per item
- The view list and the file-level fields of a team folder return `Cache-Control: private, max-age=600`

## Field Naming Convention

Field names (`field_name`) must follow a strict naming convention:

### Rules
- Starts with the **prefix of its kind** (see below): `file_gf_` for a file field, `gf_` for a team folder field (`applies_to_groupfolder: 1`)
- After the prefix: only **lowercase letters** (a-z), **numbers** (0-9), and **underscores** (_), **starting with a letter**
- No spaces, uppercase letters, or special characters
- Checked by the server when a field is created, renamed or moved to the other kind, on both the web routes and the OCS API. An invalid name is a `400 Bad Request` with `reason: "invalid_input"` and the message under `fields.field_name`
- A field stored without its prefix by an older version keeps working while its name and kind stay the same

### Prefix Convention

MetaVox uses prefixes to distinguish between field scopes:

| Scope | Prefix | Example | Description |
|-------|--------|---------|-------------|
| Team folder metadata | `gf_` | `gf_publication_status` | Metadata that applies to the team folder itself |
| File metadata | `file_gf_` | `file_gf_department` | Metadata that applies to individual files within a team folder |

**Important:** The MetaVox admin UI adds the prefix automatically. The API does **not**: send the full name, prefix included. A name without the prefix of its kind is refused; it is not added for you, so the name you send is the name you write values with.

### Examples

```bash
# Correct — file field with prefix
curl -X POST ".../api/v1/groupfolder-fields" \
  -d '{"field_name": "file_gf_department", "field_label": "Department", "field_type": "select"}'

# Correct — team folder field with prefix
curl -X POST ".../api/v1/groupfolder-fields" \
  -d '{"field_name": "gf_classification", "field_label": "Classification", "field_type": "text", "applies_to_groupfolder": 1}'

# Invalid — will be rejected (no prefix)
curl -X POST ".../api/v1/groupfolder-fields" \
  -d '{"field_name": "department", ...}'

# Invalid — will be rejected (uppercase, spaces, or the prefix of the other kind)
curl -X POST ".../api/v1/groupfolder-fields" \
  -d '{"field_name": "file_gf_Department", ...}'
curl -X POST ".../api/v1/groupfolder-fields" \
  -d '{"field_name": "file_gf_my field", ...}'
curl -X POST ".../api/v1/groupfolder-fields" \
  -d '{"field_name": "file_gf_classification", "applies_to_groupfolder": 1, ...}'
```

Each refusal is the same validation error:
```json
{
  "ocs": {
    "meta": {"status": "failure", "statuscode": 400, "message": ""},
    "data": {
      "error": "Validation failed",
      "reason": "invalid_input",
      "fields": {
        "field_name": "A file field name starts with file_gf_, followed by lowercase letters, numbers and underscores, beginning with a letter (for example file_gf_department)."
      }
    }
  }
}
```
For a team folder field the message names `gf_` and `gf_classification`. The web routes answer with the same object, without the `ocs` envelope. The message is translated; integrations should read `reason` and the `fields` key.

---

## Fields

### Create a field

**Endpoint**: `POST /ocs/v2.php/apps/metavox/api/v1/groupfolder-fields`
**Requires**: Nextcloud admin

**Request body — select / multiselect** (list-shape `field_options`):
```json
{
  "field_name": "file_gf_publicatiestatus",
  "field_label": "Publicatiestatus",
  "field_type": "select",
  "field_description": "Status van de publicatie",
  "field_options": ["Open", "Gesloten", "In behandeling"],
  "is_required": false,
  "sort_order": 1
}
```

**Request body — date** (object-shape `field_options`, since 2.1.0):
```json
{
  "field_name": "file_gf_meeting_start",
  "field_label": "Meeting start",
  "field_type": "date",
  "field_options": { "includeTime": true },
  "is_required": false
}
```

For `field_type: "date"`, `field_options` is an **object** with a single boolean `includeTime`:
- `false` (or omitted) → field stores `YYYY-MM-DD`
- `true` → field stores `YYYY-MM-DDTHH:mm:ss` (floating ISO 8601, no `Z`, no timezone)

See the [Date / DateTime fields](#date--datetime-fields) section below for the value-format contract on metadata writes, and the [SharePoint migration mapping](#sharepoint-migration-mapping).

**What the server checks.** Everything that is refused is named at once: a `400` with `reason: "invalid_input"` and a message per parameter in `fields`.

| Parameter | Takes | Refused with |
|---|---|---|
| `field_name` | see [Field Naming Convention](#field-naming-convention); unique | `A field with this name already exists`, and the naming messages |
| `field_label` | text, at most 255 characters | `Enter a label`, `This text is too long` |
| `field_type` | `text`, `textarea`, `number`, `date`, `select`, `multiselect`, `checkbox`, `url`, `user`, `filelink`; also `dropdown`, `multi_select`, `boolean` and `usergroup`, stored as `select`, `multiselect`, `checkbox` and `user`. Left out is `text`. | `Choose one of the field types` |
| `field_description` | text, at most 2000 characters | `This text is too long` |
| `field_options` | `select`, `multiselect`: at least one option, as a list of texts, a list of `{"value", "label"}`, or text with one option per line; empty and double options are left out. `date`: `{"includeTime": true}` or `false`. `user`: `{"multiple": true, "kinds": ["user", "group", "team"]}`, both optional; left out is one account, as before. Other types have none; what is sent is ignored. | `Add at least one option`, `An option cannot contain ;#`, `For a date field, send {"includeTime": true} or false`, `For a user field, send {"multiple": true or false, "kinds": ["user", "group", "team"]}`, `Choose at least one of accounts, groups and teams` |
| `is_required`, `applies_to_groupfolder` | `true`/`false`, `1`/`0`, as JSON or text | `Use true or false` |
| `sort_order` | a whole number from 0 | `Enter a whole number` |

Options are stored as a list of values; a label is kept only when it differs from its value:

```json
"field_options": [{"value": "nl", "label": "Nederland"}, {"value": "be", "label": "België"}]
```

### Update a field

**Endpoint**: `PUT /ocs/v2.php/apps/metavox/api/v1/groupfolder-fields/{id}`
**Requires**: Nextcloud admin

Send only what changes: a parameter left out keeps its stored value. What is sent is checked as on create. Left-out options stay; on a change of type they go along to the new type and are checked for it (a dropdown's options become those of a multi-select).

A field that has values in a team folder keeps its name and type: changing them is a `400` with `fields.field_name` (`Internal name cannot be changed when field is in use`) or `fields.field_type` (`Field type cannot be changed when field is in use`). Making it required is allowed: files without a value then show as missing one. A field name from before the naming convention may stay as it is while the field is changed otherwise.

An unknown id is a `404`.

Flipping `includeTime` on an existing field does **not** rewrite previously-stored values — they remain in their original format. Existing date-only values rendered through a now-`includeTime=true` field are parsed as local midnight; existing datetime values rendered through a now-`includeTime=false` field display only the date portion.

### Delete a field

**Endpoint**: `DELETE /ocs/v2.php/apps/metavox/api/v1/groupfolder-fields/{id}`
**Requires**: Nextcloud admin

A field that is still assigned to a team folder is not deleted: `400` with `reason: "invalid_input"` and `fields.field`. An unknown id is a `404`.

### Get all field definitions

**Endpoint**: `GET /ocs/v2.php/apps/metavox/api/v1/groupfolder-fields`
**Requires**: logged in

Admins, and users with the manage permission on a team folder they may change, get every field. Other users get only the fields of their own team folders.

### Assign fields to a groupfolder

**Endpoint**: `POST /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/fields`
**Requires**: admin, or a member with the MetaVox manage permission who may change the team folder

**Request body**:
```json
{"field_ids": [4, 5, 6]}
```

The team folder gets exactly these fields: one left out is unassigned, `[]` unassigns all. `field_ids` must be sent, so a misspelled key cannot unassign everything; left out, or with an id of no field, is a `400` with `fields.field_ids`.

### Get assigned fields for a groupfolder

**Endpoint**: `GET /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/fields`
**Requires**: member of the team folder

Returns the list of fields assigned to this groupfolder.

### Get file-level fields for a groupfolder

**Endpoint**: `GET /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/file-fields`
**Requires**: member of the team folder
**Response includes `Cache-Control: private, max-age=600`**

Returns fields assigned to this groupfolder that apply to individual files (not folder-level). Used by the view editor to populate column options.

---

## File Metadata

> **Team-folder resolution (since 2.2.3).** The group-folder-less endpoints
> (`.../files/{fileId}/metadata` and the bulk read) resolve the team folder a
> file **physically lives in**, from your own mount — the same folder the Files
> UI shows. Reads and writes therefore always agree on one folder. A file that
> is not inside any team folder has no valid metadata scope and returns
> **`404`** with `reason: "not_found"` instead of silently reading/writing
> nothing. The group-folder-scoped endpoints additionally reject a mismatch
> between the URL's `{groupfolderId}` and the file's actual folder with
> **`400`** and `reason: "wrong_team_folder"`.

### Get metadata for a single file

**Endpoint**: `GET /ocs/v2.php/apps/metavox/api/v1/files/{fileId}/metadata`
**Requires**: read permission on the file in its team folder (ACL included)

Returns all metadata for a file, scoped to the team folder it lives in. Requires
read access to the file. Returns `404` if the file is not in a team folder.

**Response**:
```json
{
  "ocs": {
    "data": {
      "file_gf_publicatiestatus": "Open",
      "file_gf_verantwoordelijke": "j.doe"
    }
  }
}
```

### Save metadata for a single file

**Endpoint**: `POST /ocs/v2.php/apps/metavox/api/v1/files/{fileId}/metadata`
**Requires**: update permission on the file in its team folder (ACL included)

Accepts a partial metadata object — only the fields included are updated.
Requires write access. Writes into the team folder the file lives in; returns
`404` if the file is not in a team folder.

**Request body**:
```json
{
  "metadata": {
    "file_gf_publicatiestatus": "Gesloten",
    "file_gf_verantwoordelijke": "a.jansen",
    "file_gf_meeting_start": "2026-05-13T14:30:00",
    "file_gf_publication_date": "2026-04-15"
  }
}
```

Every value is checked against its field: see [Values per field type](#values-per-field-type). A multi-select may also be sent as a JSON list. Empty string and `null` clear a value.

**Validation errors** (HTTP `400`) — all-or-nothing: one refused value refuses the whole request, nothing is saved:
```json
{
  "ocs": {
    "meta": {"status": "failure", "statuscode": 400, "message": ""},
    "data": {
      "error": "Validation failed",
      "reason": "invalid_input",
      "fields": {
        "file_gf_meeting_start": "Enter a date and time as YYYY-MM-DDTHH:MM:SS",
        "file_gf_status": "Choose one of the options of this field"
      }
    }
  }
}
```

### Get metadata for multiple files (bulk read)

**Endpoint**: `GET /ocs/v2.php/apps/metavox/api/v1/files/metadata/bulk?file_ids=123,456,789`
**Requires**: logged in; each file is checked for read permission

Returns metadata for up to **100 file IDs** per request; more is a `400` with
`fields.file_ids`. Each file is scoped to
**its own** team folder (resolved independently), so file 123 returns folder A's
values and file 456 returns folder B's — no cross-folder mixing. Files you
cannot access, or that are not in any team folder, are omitted from the response;
when none is left, the answer is a `404`.

**Response**:
```json
{
  "ocs": {
    "data": {
      "123": {"file_gf_publicatiestatus": "Open"},
      "456": {"file_gf_publicatiestatus": "Gesloten"}
    }
  }
}
```

### Get file metadata within a groupfolder

**Endpoint**: `GET /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/files/{fileId}/metadata`
**Requires**: read permission on the file in its team folder (ACL included)

Same as single file metadata, but the `{groupfolderId}` must match the folder the
file is actually in — otherwise `400` with `reason: "wrong_team_folder"`
(since 2.2.3). Use this form when you already know the folder; otherwise the
group-folder-less endpoint resolves it for you.

### Save file metadata within a groupfolder

**Endpoint**: `POST /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/files/{fileId}/metadata`
**Requires**: update permission on the file in its team folder (ACL included)

Same as single file save, but the `{groupfolderId}` must match the file's actual
folder — writing to a non-existent or mismatched folder returns `400` with
`reason: "wrong_team_folder"` (since 2.2.3), instead of silently storing the
value under a folder nothing reads back. Supports `unlock` and `unlock_field`
parameters to atomically save and release a cell lock. The same date-value
validation applies — see [Date / DateTime fields](#date--datetime-fields).

---

## Groupfolders

### List all groupfolders

**Endpoint**: `GET /ocs/v2.php/apps/metavox/api/v1/groupfolders`
**Requires**: logged in

Returns the groupfolders the authenticated user is a member of. Admins get all groupfolders; add `?scope=member` to get only their own.

### Get groupfolder metadata

**Endpoint**: `GET /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/metadata`
**Requires**: member of the team folder

Returns folder-level metadata (not per-file).

### Save groupfolder metadata

**Endpoint**: `POST /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/metadata`
**Requires**: admin, or a member who may change the team folder

Same payload shape and date-value validation as the file-metadata save endpoints — see [Date / DateTime fields](#date--datetime-fields).

---

## Values per field type

Every route that writes a value checks it against its field first: the Files grid, the sidebar, a bulk edit, the OCS API and the batch, default values. A value the field does not take is a `400` with `reason: "invalid_input"` and, per field, what it takes. A value that is accepted is stored in one form:

| Field type | Accepted | Stored | Refused, for example |
|---|---|---|---|
| `text`, `textarea` | text, or a number | as sent | a list, `true`/`false`, text that is not UTF-8 |
| `number` | `12`, `3.5`, `-0.25`, as text or JSON number; spaces around it are dropped | `"3.5"` | `"1,5"`, `"abc"`, `"1e999"` |
| `date` | a date that exists, see [Date / DateTime fields](#date--datetime-fields) | `"2026-04-15"` | `"2026-02-30"`, `"tomorrow"`, `"15/04/2026"` |
| `select` | one of the field's options, in any case | the option as written in the field | a value that is not an option |
| `multiselect` | options joined with `;#`, or a JSON list | `"Draft;#Final"`, without doubles or empty parts | one value that is not an option |
| `checkbox` | `1`/`0`, `true`/`false`, as text, number or JSON | `"1"` or `"0"` | `"yes"`, `"maybe"`, `2` |
| `url` | any link but one that runs code; see [Links](#links-in-url-fields) | as sent | `javascript:`, `data:`, `vbscript:` |
| `user` | account ids, `group:<id>` and `team:<id>`, joined with `;#` or as a JSON list; see below | `"anna;#group:staff"`, without doubles or empty parts | see below |
| `filelink` | `"<fileId>:"` links joined with `;#`, or a JSON list | links to files in the team folder that you may open; see below | something that is no link |

`""` and `null` clear a value.

**Required fields.** A required field that has a value cannot be emptied, and a required checkbox cannot be unticked: `400` with `This field is required` or `This box must be ticked`. A file that came in without metadata may stay without; saving its other fields is not refused.

**Values from before these checks** stay as they are. A request that sends one back unchanged is not refused for it.

**Fields the team folder does not use** are left out, like unknown field names. The batch names them per file in `unused_fields`.

**People** (`user`) hold accounts, and groups and teams where the field's `kinds` take them; several where `multiple` is on. What is new in a value must exist and be yours to share with, as Nextcloud's sharing settings say: with only sharing within your own groups, an account in one of your groups and a group you are in; groups only when sharing with groups is on; a team you can see. Refused with `Choose an existing account`, `Choose an existing group`, `Choose an existing team`, `Choose someone in one of your groups`, `Choose one of your own groups`, `Groups cannot be chosen on this server`, `This field takes no groups` (or `teams`, `accounts`) and `Choose only one`. What a value held already stays, also when it could not be chosen now: a field that takes one keeps several from before until someone takes them out.

**File links** point at files in the same team folder that you may open, ACL included; admins may link any file in it. A link to a file outside the team folder, or to one you may not open, is left out without a message, so the answer tells nothing about files you cannot see. A link that is already in the value stays when you save it again, also when you cannot open that file: someone who can put it there.

### Links in URL fields

What a URL field takes is set by the administration in the admin settings, tab *General* (see [Settings](#settings)):

| Setting | Effect | Refused with |
|---|---|---|
| default | any link but one that runs code | `This kind of link is not allowed` |
| `url_web_only` | only `http://`, `https://` and `mailto:` addresses | `Enter an address that starts with http://, https:// or mailto:` |
| `url_blocked_domains` | no link to these domains or their subdomains; `ru` blocks every `.ru` address | `Links to this domain are not allowed` |
| `url_allowed_domains` | when not empty, links to these domains and their subdomains only | `Only links to approved domains are allowed` |

The domain of an email address (`mailto:`) and of an address without a scheme counts too. Links already saved are not checked again.

## Date / DateTime fields

> Added in 2.1.0 — resolves SharePoint migration data loss (#65).

A single `field_type: "date"` covers both date-only and date+time use cases, controlled by the `field_options.includeTime` flag.

### Field-definition shape

| `includeTime` | Stored value format | Description |
|---|---|---|
| `false` (default) | `YYYY-MM-DD` | Date-only (legacy MetaVox behavior) |
| `true` | `YYYY-MM-DDTHH:mm:ss` | Floating ISO 8601 — no `Z`, no timezone offset |

`field_options` is an **object** for date fields (not a list, as it is for select/multiselect):
```json
{ "includeTime": true }
```

Pre-2.1.0 date fields with `field_options = null` or `[]` are treated as `includeTime = false`. When such a field is updated via the API without explicitly setting `field_options`, it normalizes to `{"includeTime": false}` on save.

### Value-format contract (writes)

Every write endpoint checks date values on the server (see [Values per field type](#values-per-field-type)). The date must exist: `2026-02-30` is refused.

| Field's `includeTime` | Accepted | Stored |
|---|---|---|
| `false` | `"2026-04-15"` | `"2026-04-15"` |
| `true` | `"2026-04-15T14:30:00"`, or `"2026-04-15T14:30"` as a browser's date-time input sends it | `"2026-04-15T14:30:00"` |

Empty string `""` and JSON `null` clear the value.

**Floating semantics — no timezone conversion.** MetaVox never adds, strips, or converts timezones. A value of `"2026-04-15T14:30:00"` means "14:30 wherever the reader is" — same wall-clock everywhere. This matches SharePoint's behavior for `SPFieldDateTime` columns that omit the `Z`.

**Rejected forms** (return `400`):
- `"2026-04-15T14:30:00Z"` — explicit UTC suffix
- `"2026-04-15T14:30:00+02:00"` — explicit offset
- `"2026-04-15 14:30:00"` — space instead of `T`
- `"15/04/2026"` — locale-formatted dates
- Any cross-format mismatch (date-only value for `includeTime=true` field, or vice versa)

### SharePoint migration mapping

| SharePoint `SPFieldDateTime.DisplayFormat` | MetaVox |
|---|---|
| `DateOnly` (0) | `field_type: "date"`, `field_options: {"includeTime": false}` |
| `DateTime` (1) | `field_type: "date"`, `field_options: {"includeTime": true}` |

CSV importer support for SharePoint datetime columns is planned for a future release.

### Read path

`GET` endpoints return the stored value verbatim — no formatting, no timezone conversion:
```json
{
  "ocs": {
    "data": {
      "file_gf_meeting_start": "2026-05-13T14:30:00",
      "file_gf_publication_date": "2026-04-15"
    }
  }
}
```

Lexicographic sort works correctly across both formats because ISO 8601 sorts chronologically by string comparison. No code-side adjustment needed when mixing date-only and datetime values in the same query.

---

## Views

### List views for a groupfolder

**Endpoint**: `GET /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/views`
**Requires**: member of the team folder
**Response includes `Cache-Control: private, max-age=600`**

**Response**:
```json
{
  "views": [
    {
      "id": 1,
      "gf_id": 3,
      "name": "WOO open",
      "is_default": true,
      "columns": [
        {"field_id": 12, "field_name": "file_gf_publicatiestatus", "visible": true, "filterable": true, "column_order": 0,
         "format": [{"op": "oneOf", "value": ["Verlopen"], "tone": "error"}]},
        {"field_id": 13, "field_name": "file_gf_verantwoordelijke", "visible": true, "filterable": false, "column_order": 1}
      ],
      "filters": {"file_gf_publicatiestatus": ["Open"]},
      "sort_field": "file_gf_publicatiestatus",
      "sort_order": "ASC"
    }
  ],
  "can_manage": true
}
```

A column's highlight rules come back under `format`, in their stored form (see [Highlight rules](#highlight-rules-format)).

`can_manage` is `true` when the caller may create, change and delete views of this team folder.

### Create a view

**Endpoint**: `POST /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/views`
**Requires**: admin, or a member who may change the team folder

**Request body**:
```json
{
  "name": "WOO open",
  "is_default": false,
  "columns": [
    {"field_id": 12, "field_name": "file_gf_publicatiestatus", "visible": true, "filterable": true, "column_order": 0}
  ],
  "filters": {"file_gf_publicatiestatus": ["Open"]},
  "sort_field": "file_gf_publicatiestatus",
  "sort_order": "ASC"
}
```

| Key | Accepted |
|---|---|
| `name` | Required. Text of 1–100 characters, not only spaces. |
| `columns`, `filters` | A list and an object. Left out, they are empty. `null` or another type is a `400`. |
| `sort_field` | `null` or text of at most 100 characters. |
| `sort_order` | `null`, `""`, `asc` or `desc`, in any case. |

Send `field_id` with each column: highlight rules are kept by field id (see below).

### Update a view

**Endpoint**: `PUT /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/views/{viewId}`
**Requires**: admin, or a member who may change the team folder
Body: as for create, plus an optional `position` (a whole number from 0).

An update replaces the view: a key you leave out is stored empty. Leaving out `columns` removes every column, and with it every highlight rule. Inside a column you do send, leaving out `format` keeps that column's rules.

### Highlight rules (`format`)

A column can colour its cells by their value, for example a deadline before today in red. The rules are a list under the column's `format` key. The browser decides what matches (see **How a rule matches** below); the server checks and stores the rules. For the user's side, see [Highlight Rules](../user/highlight-rules.md).

```json
{"field_id": 14, "field_name": "file_gf_deadline", "visible": true, "filterable": true,
 "format": [
   {"op": "before", "value": {"today": 0}, "tone": "error", "preset": "overdue"},
   {"op": "nextDays", "value": 7, "tone": "warning", "label": "Due soon"},
   {"op": "empty", "tone": "info"}
 ]}
```

| Key | |
|---|---|
| `op` | The condition; see the table below. |
| `value` | What the condition compares with; see the table below. Left out for `empty`, `!empty`, `isTrue` and `isFalse`. |
| `tone` | `error`, `warning`, `success` or `info`. The theme decides the colour. |
| `label` | Optional. The text shown when someone points at the icon in a coloured cell, for example what needs to happen: "Approve first". A screen reader reads it with the cell. Control and format characters (such as zero-width and direction marks; not the emoji joiner) are removed and the ends trimmed; what is left may be at most 40 characters (Unicode code points, so one emoji can count as several). A longer label refuses the view with `Use at most 40 characters`. Without a label the icon shows the preset's name (see `preset`), or else the condition in words, such as "before today". |
| `preset` | Optional. `overdue`, `dueToday`, `dueSoon`, `missing`, `checked`, `unchecked` or `assignedToViewer`. Any other value is dropped. The preset's name is shown only while `op` and `value` are still the preset's own (for example `nextDays` 7 for `dueSoon`). |

| `op` | `value` |
|---|---|
| `empty`, `!empty`, `isTrue`, `isFalse` | none |
| `nextDays`, `olderThanDays` | whole number of days, 1–3650 |
| `greater`, `less`, `greaterOrEqual`, `lessOrEqual` | a number, as a number or as text (`"2.5"`) |
| `before`, `after` | `{"today": 0}`, or a date `"YYYY-MM-DD"` |
| `is` | `{"today": 0}`, `{"me": true}` (the person looking), or text of 1–200 characters |
| `!is`, `contains`, `!contains` | text of 1–200 characters |
| `oneOf`, `containsAll` | a list of 1–50 texts of 1–200 characters |

| Field type | `op` |
|---|---|
| text, textarea, url | `is`, `!is`, `contains`, `!contains`, `empty`, `!empty` |
| number | `is`, `!is`, `greater`, `less`, `greaterOrEqual`, `lessOrEqual`, `empty`, `!empty` |
| date | `before`, `after`, `is`, `nextDays`, `olderThanDays`, `empty`, `!empty` |
| select | `is`, `!is`, `oneOf`, `empty`, `!empty` |
| multiselect | `oneOf` (has one of), `containsAll` (has all of), `empty`, `!empty` |
| checkbox | `isTrue`, `isFalse` |
| user | `is`, `!is`, `empty`, `!empty` |
| filelink, and any other type | `empty`, `!empty` |

`{"today": 0}` is for date fields and `{"me": true}` for user fields. On a number field the value of `is` and `!is` must be a number; on a date field the value of `is` must be a date.

- **Per column:** at most 10 rules. All rules of a view together take at most 32 KB (UTF-8, as `JSON.stringify` writes them without escapes).
- **Keeping and clearing:** in a column you send, `format` left out keeps the stored rules of that field; `[]` or `{}` clears them; `null` is a `400`. Leave `format` out unless you change it: an OCS list can be up to 600 s old.
- **Stored form:** the answer shows the rules as stored. Numbers become text, text is trimmed, unknown keys are dropped.
- **Decimals:** send them as text for `is`, `!is`, `contains`, `!contains` and the list items. Whole numbers may be JSON numbers.
- **Field type:** the server does not check whether a condition fits the field. A rule that does not fit, for example after the field changed type, is skipped: it colours nothing, does not stop the rules below it, and blocks no save.
- Rules need a JSON body. Views, and so their rules, are not in the MetaVox backup, and other open browsers see a change after a reload.

**How a rule matches.**

- **Order:** rules are tried from the top; the first that matches colours the cell. When a rule cannot be decided for a cell (see below), the search stops there: the cell stays uncoloured, even if a rule further down would match.
- **Empty:** no value, empty text, or a multiselect or file link without items. `0` is a value.
- **Empty values and comparisons:** on an empty value every comparison is false: `is`, `contains`, `oneOf`, `containsAll`, `greater`, `less`, `greaterOrEqual`, `lessOrEqual`, `before`, `after`, `nextDays`, `olderThanDays`, and `is` with `{"today": 0}`, or with `{"me": true}` when a user is signed in (see **The person viewing**). `!is` and `!contains` are true on an empty value. A checkbox that was never set counts as not checked: `isFalse` is true, `isTrue` is false.
- **Text and choices:** compared without regard to case. The rule's text is trimmed; the stored value is not, except that each item of a multiselect is. `contains` looks for part of the text; on a multiselect, `oneOf` and `containsAll` compare whole items.
- **Numbers:** read the way PHP `is_numeric` reads them (`1.5`, `1e3`, spaces around; not `1,5`). `is` compares numbers, so `1.0` is `1`. A stored value that is not a number cannot be decided, also for `!is`.
- **Dates:** compared by day; a time is ignored. `{"today": 0}` is the day on the viewer's device, and the grid colours again when the day changes. `nextDays` n holds from today up to and including today + n; `olderThanDays` n holds before today − n. A stored value that is not a date cannot be decided.
- **The person viewing:** `is` with `{"me": true}` compares with the user id of the signed-in user, without regard to case. When no user is known it cannot be decided, also on an empty cell.
- **Long values:** a value longer than 512 characters reaches the file list cut off. Only `empty` and `!empty` are decided for it; every other condition, `isTrue` and `isFalse` included, cannot be decided.
- **Folders:** a folder has its own metadata and is coloured like a file, also when its value is empty: `empty` marks a folder without a value.
- **Never coloured:** a row whose values are still loading, and the folder of another storage shown inside the team folder (such as a nested team folder), which has no MetaVox values there.

**Errors.** A refused view is a `400` with `reason: "invalid_input"`. `fields` names the part, using the index you sent:

```json
{
  "ocs": {
    "meta": {"status": "failure", "statuscode": 400, "message": ""},
    "data": {
      "error": "Validation failed",
      "reason": "invalid_input",
      "fields": {"columns.3.format.1.value": "Enter a number"}
    }
  }
}
```

A name that is missing, blank or too long answers with only `error` and `reason`.

### Delete a view

**Endpoint**: `DELETE /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/views/{viewId}`
**Requires**: admin, or a member who may change the team folder

### Reorder views

**Endpoint**: `PUT /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/views/reorder`
**Requires**: admin, or a member who may change the team folder

**Request body**:
```json
{"view_ids": [3, 1, 5, 2]}
```

The order of the IDs determines the tab display order. Items that are no view id are skipped; without any, the answer is a `400` on `view_ids`.

---

## Filter Values and Directory Metadata

> The column configuration endpoints (`.../groupfolders/{groupfolderId}/columns`) were removed in 1.8.67. A view's `columns` decide which columns the file list shows; see [Views](#views).

### Get all filter values (batch)

**Endpoint**: `GET /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/all-filter-values`
**Requires**: member of the team folder

Values of files that still exist and that you can read. A change shows in the next call.

Returns distinct values for all fields in one request. For select/multiselect/checkbox fields, returns configured options from field definition. For text/date/number fields, queries the database.

**Optional parameter**: `field_names` (comma-separated) to limit to specific fields.

**Response**:
```json
{
  "file_gf_publicatiestatus": ["Open", "Gesloten", "In behandeling"],
  "file_gf_verantwoordelijke": ["j.doe", "a.jansen"],
  "file_gf_is_verwerkt": ["1", "0"]
}
```

### Get scoped filter values (current directory)

**Endpoint**: `POST /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/filter-values`
**Requires**: member of the team folder

Returns distinct values scoped to specific file IDs (e.g., files in the current directory).

**Request body**:
```json
{"file_ids": [123, 456, 789]}
```

> **Note**: For checkbox fields, values are `"1"` (checked) and `"0"` (unchecked). The frontend maps these to "Yes"/"No".

### Get sorted and filtered file IDs

**Endpoint**: `GET /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/sorted-file-ids`
**Requires**: member of the team folder

Returns an ordered list of file IDs matching the given sort and filter criteria. Used for server-side sorting on large datasets.

**Parameters**:
- `sort_field` — field name to sort by
- `sort_order` — `asc` or `desc` (default: `asc`)
- `sort_field_type` — `text`, `number`, `date`, or `checkbox` (default: `text`)
- `filters` — JSON object of filter criteria, e.g. `{"file_gf_publicatiestatus": ["Open"]}`

**Response**:
```json
{"ocs": {"data": [456, 123, 789]}}
```

### Get directory metadata (for file list columns)

**Endpoint**: `GET /ocs/v2.php/apps/metavox/api/v1/groupfolders/{groupfolderId}/directory-metadata?file_ids=1,2,3`
**Requires**: member of the team folder; each file is checked for read permission

Returns metadata for up to **200 file IDs** per request, limited to column-configured fields; more is a `400` with `fields.file_ids`. Used by the file list to populate metadata columns.

**Response**:
```json
{
  "123": {"file_gf_publicatiestatus": "Open", "file_gf_verantwoordelijke": "j.doe"},
  "456": {"file_gf_publicatiestatus": "Gesloten"}
}
```

---

## Batch Operations

### 1. Batch Update File Metadata

Update metadata for multiple files in one request.

**Endpoint**: `POST /ocs/v2.php/apps/metavox/api/v1/files/metadata/batch-update`
**Requires**: logged in; each file is checked for update permission

**Request Body**:
```json
{
  "updates": [
    {
      "file_id": 290,
      "groupfolder_id": 1,
      "metadata": {
        "file_gf_testfield": "test value",
        "file_gf_status": "In Progress",
        "file_gf_priority": "High"
      }
    },
    {
      "file_id": 628,
      "groupfolder_id": 1,
      "metadata": {
        "file_gf_testfield": "another value",
        "file_gf_status": "Completed",
        "file_gf_priority": "Medium"
      }
    }
  ]
}
```

**Response**:
```json
{
  "ocs": {
    "meta": {"status": "ok", "statuscode": 200},
    "data": {
      "success": true,
      "total": 2,
      "successful": 2,
      "failed": 0,
      "results": [
        {"file_id": 290, "success": true, "fields_updated": 3},
        {"file_id": 628, "success": true, "fields_updated": 3}
      ]
    }
  }
}
```

An item with a value its field does not take is refused; the other items are saved. A field the team folder does not use is left out and named:
```json
"results": [
  {"file_id": 290, "success": true, "fields_updated": 1, "unused_fields": ["file_gf_priority"]},
  {"file_id": 628, "success": false, "fields_updated": 0, "error": "Validation failed", "reason": "invalid_input",
   "fields": {"file_gf_status": "Choose one of the options of this field"}}
]
```

**cURL Example**:
```bash
curl -X POST "https://your-nextcloud.com/ocs/v2.php/apps/metavox/api/v1/files/metadata/batch-update" \
  -H "OCS-APIRequest: true" \
  -H "Content-Type: application/json" \
  -u "username:app-password" \
  -d '{
    "updates": [
      {
        "file_id": 290,
        "groupfolder_id": 1,
        "metadata": {
          "file_gf_testfield": "test value",
          "file_gf_status": "In Progress"
        }
      }
    ]
  }'
```

---

### 2. Batch Delete File Metadata

Delete metadata from multiple files.

**Endpoint**: `POST /ocs/v2.php/apps/metavox/api/v1/files/metadata/batch-delete`
**Requires**: logged in; each file is checked for update permission

**Request Body (Delete All Metadata)**:
```json
{
  "deletes": [
    {"file_id": 290, "groupfolder_id": 1, "field_names": null},
    {"file_id": 628, "groupfolder_id": 1, "field_names": null}
  ]
}
```

**Request Body (Delete Specific Fields)**:
```json
{
  "deletes": [
    {"file_id": 290, "groupfolder_id": 1, "field_names": ["file_gf_testfield", "file_gf_status"]},
    {"file_id": 628, "groupfolder_id": 1, "field_names": ["file_gf_testfield"]}
  ]
}
```

**Response**:
```json
{
  "ocs": {
    "meta": {"status": "ok", "statuscode": 200},
    "data": {
      "success": true,
      "total": 2,
      "successful": 2,
      "failed": 0,
      "results": [
        {"file_id": 290, "groupfolder_id": 1, "success": true, "fields_deleted": 2},
        {"file_id": 628, "groupfolder_id": 1, "success": true, "fields_deleted": 1}
      ]
    }
  }
}
```

**Required fields keep their value.** Deleting all metadata of a file leaves them and names them in `kept_required_fields`. An item that names a required field is refused: `success: false`, `reason: "invalid_input"`, `fields: {"<name>": "This field is required"}`.

---

### 3. Batch Copy File Metadata

Copy metadata from one file to multiple files within the same groupfolder.

**Endpoint**: `POST /ocs/v2.php/apps/metavox/api/v1/files/metadata/batch-copy`
**Requires**: logged in; read permission on the source file, update permission on each target

**Request Body (Copy All Fields)**:
```json
{
  "source_file_id": 290,
  "source_groupfolder_id": 1,
  "target_file_ids": [628, 789, 101],
  "groupfolder_id": 1
}
```

**Request Body (Copy Specific Fields)**:
```json
{
  "source_file_id": 290,
  "source_groupfolder_id": 1,
  "target_file_ids": [628, 789],
  "groupfolder_id": 1,
  "field_names": ["file_gf_testfield", "file_gf_status", "file_gf_priority"]
}
```

`source_file_id` and `target_file_ids` are required. A source in a team folder without fields has nothing to copy: `404`.

**Response**:
```json
{
  "ocs": {
    "meta": {"status": "ok", "statuscode": 200},
    "data": {
      "success": true,
      "source_file_id": 290,
      "fields_copied": 3,
      "target_results": [
        {"file_id": 628, "success": true, "fields_updated": 3},
        {"file_id": 789, "success": true, "fields_updated": 3},
        {"file_id": 101, "success": true, "fields_updated": 3}
      ]
    }
  }
}
```

---

### 4. Get Metadata Statistics

**Endpoint**: `GET /ocs/v2.php/apps/metavox/api/v1/metadata/statistics`
**Requires**: Nextcloud admin

**Response**:
```json
{
  "ocs": {
    "meta": {"status": "ok", "statuscode": 200},
    "data": {
      "total_fields": 25,
      "total_values": 1547,
      "files_with_metadata": 423,
      "fields_by_type": [
        {"field_type": "text", "count": "15"},
        {"field_type": "select", "count": "5"},
        {"field_type": "date", "count": "3"},
        {"field_type": "multi_select", "count": "2"}
      ]
    }
  }
}
```

---

## WebDAV property `nc:metavox`

MetaVox adds the property `{http://nextcloud.org/ns}metavox` (`nc:metavox`) to folder listings, so a client gets each file's values with the listing instead of one API call per folder. The Files app uses it for the grid.

**Request**: a `PROPFIND` with `Depth: 1` on a folder inside a team folder, under `/remote.php/dav/files/{userId}/...`, that asks for the property:

```bash
curl -X PROPFIND "https://your-nextcloud.com/remote.php/dav/files/jdoe/Shared%20Documents/2026" \
  -u "jdoe:app-password" -H "Depth: 1" -H "Content-Type: application/xml" \
  -d '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns"><d:prop><d:getetag/><nc:metavox/></d:prop></d:propfind>'
```

**Value**: a JSON document per file, as text:

```json
{"v":1,"g":3,"e":1,"f":{"file_gf_publicatiestatus":"Open","file_gf_tags":"WOO;#Besluit"},"t":["file_gf_notes"]}
```

| Key | |
|---|---|
| `v` | Format version, always `1`. Ignore a value with another version. |
| `g` | The team folder id. |
| `e` | `1` when you may change the file's metadata; left out otherwise. |
| `f` | Field name → stored value, as text. Only non-empty values; left out when there are none. Multi-value fields keep their `;#` separators; user and File Link fields hold their stored form, not display names; the names of people are in `n`. |
| `t` | Fields whose value in `f` was cut off; left out when none was. |
| `n` | The names of the accounts, groups and teams in the file's People (`user`) values, by id, as you may see them: a team you cannot see has none. Left out when there are none. |

- **Cut-off:** a value longer than 512 characters is cut at 512, and back to the last whole `;#` item when it has one. The value in `f` is always the start of the full value. Read the full value from [Get metadata for a single file](#get-metadata-for-a-single-file).
- **Folder root:** the listed team folder root has only `v` and `g`. A nested mount, such as another team folder, has only `v`.
- **Not served:** with another `Depth`, outside a team folder, on `/remote.php/webdav`, or when MetaVox is not enabled for you, the property is `404` in the multistatus. A file the listing cannot check stays `404` too; read it through the API.
- **Read-only:** a `PROPPATCH` of the property is refused with `403`.
- A listing that asks for the property registers you as present in that team folder, like the Files app.

---

## Migration Workflow

Complete OCS-only workflow for programmatic setup — suitable for migrations from SharePoint or other systems. All steps use app password authentication; no browser session required.

### Step 1 — Create fields (admin)

```bash
curl -X POST ".../ocs/v2.php/apps/metavox/api/v1/groupfolder-fields" \
  -H "OCS-APIRequest: true" -H "Content-Type: application/json" \
  -u "admin:app-password" \
  -d '{"field_name": "file_gf_publicatiestatus", "field_label": "Publicatiestatus", "field_type": "select", "field_options": ["Open", "Gesloten"]}'
```

Repeat for each field. Note the returned `id` for step 2.

### Step 2 — Assign fields to groupfolder (admin)

```bash
curl -X POST ".../ocs/v2.php/apps/metavox/api/v1/groupfolders/3/fields" \
  -H "OCS-APIRequest: true" -H "Content-Type: application/json" \
  -u "admin:app-password" \
  -d '{"field_ids": [4, 5, 6]}'
```

### Step 3 — Create views

```bash
curl -X POST ".../ocs/v2.php/apps/metavox/api/v1/groupfolders/3/views" \
  -H "OCS-APIRequest: true" -H "Content-Type: application/json" \
  -u "admin:app-password" \
  -d '{"name": "WOO open", "is_default": true, "columns": [{"field_id": 4, "field_name": "file_gf_publicatiestatus", "visible": true, "filterable": true, "column_order": 0}], "filters": {"file_gf_publicatiestatus": ["Open"]}, "sort_field": "file_gf_publicatiestatus", "sort_order": "ASC"}'
```

### Step 4 — Grant the manage permission (admin)

```bash
# Grant the manage permission to a group
curl -X POST ".../index.php/apps/metavox/api/permissions/group" \
  -H "Content-Type: application/json" -b "session-cookie" \
  -d '{"group_id": "woo-beheerders", "permission_type": "manage_fields", "groupfolder_id": 3}'
```

> **Note**: Permission endpoints are on traditional routes (require session or admin). App password works if the user is a Nextcloud admin.

### Step 5 — Import metadata in batches of ≤100 files

```bash
curl -X POST ".../ocs/v2.php/apps/metavox/api/v1/files/metadata/batch-update" \
  -H "OCS-APIRequest: true" -H "Content-Type: application/json" \
  -u "admin:app-password" \
  -d '{"updates": [{"file_id": 290, "groupfolder_id": 3, "metadata": {"file_gf_publicatiestatus": "Open"}}]}'
```

For large datasets (10 000+ files), paginate in batches of 100 on the client side.

### Step 6 — Trigger backup (optional)

```bash
curl -X POST ".../index.php/apps/metavox/api/backup/trigger" \
  -b "session-cookie"
```

---

## Error Handling

All batch operations return a result per item. A file that could not be handled has `success: false`, a fixed `reason` to act on, and an `error` text for people:

```json
{
  "ocs": {
    "meta": {"status": "ok", "statuscode": 200},
    "data": {
      "success": true,
      "total": 3,
      "successful": 1,
      "failed": 2,
      "results": [
        {"file_id": 123, "success": true, "fields_updated": 3, "unknown_fields": ["file_gf_staus"]},
        {"file_id": 456, "success": false, "error": "File not found", "reason": "not_found"},
        {"file_id": 789, "success": false, "fields_updated": 0, "error": "Could not save the metadata of this file",
         "reason": "save_failed", "requestId": "Hk3dL9qPzX0a", "failed_fields": ["file_gf_status", "file_gf_notes"]}
      ]
    }
  }
}
```

| `reason` | Meaning |
|---|---|
| `not_found` | The file does not exist, or you cannot see it. |
| `read_only` | You can see the file but not change its metadata. |
| `wrong_team_folder` | The file is not in the `groupfolder_id` you sent. |
| `invalid_id` | The item has no valid `file_id`. |
| `save_failed` | The values could not be stored. A batch saves each team folder in one go, so nothing of this file and of the other files of that team folder in the batch was saved; other team folders were. `failed_fields` names the fields. |
| `delete_failed` | The metadata could not be deleted. |

- `unknown_fields` lists field names in your request that MetaVox does not know; they are skipped. Field names match regardless of case.
- Answers never contain internal error text. For `save_failed` and `delete_failed`, give the `requestId` to your Nextcloud admin: it finds the details in the Nextcloud log.

### Access errors

When a **Requires** check fails, the controller does not run. The answer has an `error` text and a fixed `reason`:

| HTTP | `reason` | Meaning |
|---|---|---|
| `404` | `not_found` | You are not a member of the team folder, or you cannot see the file. MetaVox answers as if it is not there. |
| `403` | `read_only` | You can see the file or team folder but may not change it. |
| `403` | `not_manager` | The route needs the MetaVox manage permission on this team folder. |
| `403` | `forbidden` | The route is for Nextcloud admins. |
| `400` | `invalid_id` | A team folder id or file id in the URL is `0`. |
| `400` | `wrong_team_folder` | The file is not in the team folder of the URL. |

An id in the URL is digits only: `/groupfolders/12abc/...` matches no route and is a `404`.

An unexpected server error is a `500` with `reason: "internal_error"` and a `requestId` to find the details in the Nextcloud log.

### Parameters

A parameter of the wrong kind is refused, not read as something else: a `400` with `reason: "invalid_input"` and, in `fields`, the parameter and what it takes. Before, `"abc"` was read as `0`, `"no"` as yes and junk was dropped from a list of ids.

| Parameter takes | Accepted | Refused with |
|---|---|---|
| true or false | `true`, `false`, `1`, `0`, as JSON or text | `Use true or false` |
| a whole number | `12`, `"12"` | `Enter a whole number` |
| a list of ids | `[1, 2]`, `"1,2"` | `Enter a list of IDs`; more than a route takes: `Too many IDs in one request` |
| a list of names | `["a", "b"]`, `"a,b"` | `Enter a list of names` |
| values per field | `{"file_gf_status": "Open"}` | `Send each field name with its value` |
| one of a few words | `"asc"`, `"desc"` | `This value is not valid` |

A required parameter that is left out is `This field is required`.

```json
{
  "ocs": {
    "meta": {"status": "failure", "statuscode": 400, "message": ""},
    "data": {
      "error": "Validation failed",
      "reason": "invalid_input",
      "fields": {"file_ids": "Enter a list of IDs"}
    }
  }
}
```

### OCS v1

Use `/ocs/v2.php`. On `/ocs/v1.php` Nextcloud answers every request with HTTP `200` (a `403` becomes `401`), and the status is only in `ocs.meta.statuscode`.

---

## Best Practices

1. **Batch Size**: Limit batches to 100 items per request for optimal performance
2. **Transaction Safety**: All batch operations use database transactions
3. **Error Handling**: Check individual results for partial failures
4. **Field Names**: Use the exact field names as defined in MetaVox
5. **Authentication**: Use app passwords for external scripts — no session or CSRF token needed

---

## JavaScript/TypeScript Example

```javascript
// Batch update example
async function batchUpdateMetadata(files, groupfolderId) {
  const updates = files.map(file => ({
    file_id: file.id,
    groupfolder_id: groupfolderId,
    metadata: {
      file_gf_testfield: file.testValue,
      file_gf_status: file.status,
      file_gf_priority: file.priority
    }
  }));

  const response = await fetch(
    '/ocs/v2.php/apps/metavox/api/v1/files/metadata/batch-update',
    {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'OCS-APIRequest': 'true'
      },
      body: JSON.stringify({ updates })
    }
  );

  const result = await response.json();

  if (result.ocs.data.failed > 0) {
    console.error('Some updates failed:', result.ocs.data.results);
  }

  return result.ocs.data;
}
```

---

## Summary

### OCS API (app password / OAuth — no CSRF)

| Area | Endpoints |
|------|-----------|
| **Fields** | Create, update, delete fields; assign to groupfolder; list file-level fields |
| **File Metadata** | Read/write per file; bulk read (≤100); groupfolder-scoped read/write |
| **Groupfolders** | List, read/write folder-level metadata |
| **Views** | List, create, update, delete, reorder views per groupfolder |
| **Batch operations** | Batch update, delete, copy metadata (≤100 files/request) |
| **Filter values** | All values (batch), scoped values, sorted file IDs |
| **Directory metadata** | Bulk metadata for file list columns (≤200 files) |
| **Statistics** | Metadata usage stats |

### WebDAV

| Area | Endpoints |
|------|-----------|
| **`nc:metavox` property** | Values, team folder and edit right per file in a Depth 1 `PROPFIND` of a team folder |

### Browser API (session + CSRF, internal to the UI)

| Area | Endpoints |
|------|-----------|
| **Initialization** | Single-call bootstrap (groupfolders + fields + views) |
| **User endpoints** | Team folders the user manages, their fields and metadata |
| **Fields** | Bulk delete field definitions |
| **File metadata** | Backlinks, bulk save, clear metadata, export |
| **Default values** | Read and set defaults per team folder, apply them, status |
| **Cell locking** | Lock/unlock cells for concurrent editing |
| **Presence** | Track active users per groupfolder |
| **AI Autofill** | AI-powered metadata generation |
| **Backup & Restore** | Create, list, download, restore metadata backups |
| **Settings** | Admin app settings (AI toggle) |
| **License** | License key, validation, usage |
| **Telemetry** | Anonymous usage reporting |
| **Permissions** | Grant and revoke the manage permission (user/group) |
| **Utilities** | User search |

---

## Cell Locking (Real-Time Collaboration)

Requires `notify_push` and Redis for full functionality.

### Lock a cell

**Endpoint**: `POST /apps/metavox/api/groupfolders/{groupfolderId}/files/{fileId}/lock`
**Requires**: update permission on the file in its team folder

**Request body**:
```json
{"field_name": "file_gf_publicatiestatus"}
```

**Response** (success):
```json
{"locked": false}
```

**Response** (already locked by another user, HTTP 409):
```json
{"error": "Someone else is editing this value", "reason": "busy", "locked": true, "lockedBy": "jane"}
```

### Unlock a cell

**Endpoint**: `POST /apps/metavox/api/groupfolders/{groupfolderId}/files/{fileId}/unlock`
**Requires**: read permission on the file in its team folder

**Request body**:
```json
{"field_name": "file_gf_publicatiestatus"}
```

### Presence leave

Called via `sendBeacon` on tab close to clean up presence tracking.

**Endpoint**: `POST /apps/metavox/api/presence/leave`
**Requires**: logged in; removes only the caller's own presence

---

## AI Autofill

Requires a Nextcloud AI task processing provider (e.g., the LLM2 app).

### Check AI availability

**Endpoint**: `GET /apps/metavox/api/ai/status`
**Requires**: logged in

**Response**:
```json
{"available": true}
```

### Generate metadata suggestions

**Endpoint**: `POST /apps/metavox/api/ai/generate`
**Requires**: update permission on the file in its team folder

**Request body**:
```json
{
  "fileId": 290,
  "groupfolderId": 3,
  "rejectedSuggestions": {}
}
```

**Response**:
```json
{
  "suggestions": {
    "file_gf_onderwerp": "Project planning",
    "file_gf_auteur": "Jan de Vries"
  }
}
```

---

## Backup & Restore

Admin-only endpoints for metadata backup and restore.

### List backups

**Endpoint**: `GET /apps/metavox/api/backup/list`
**Requires**: Nextcloud admin

### Trigger backup

**Endpoint**: `POST /apps/metavox/api/backup/trigger`
**Requires**: Nextcloud admin

Creates a gzip-compressed JSON backup of all metadata tables. Maximum 7 backups are retained.

### Restore backup

**Endpoint**: `POST /apps/metavox/api/backup/restore`
**Requires**: Nextcloud admin

**Request body**:
```json
{"filename": "metavox_backup_2026-03-24_120000.json.gz"}
```

### Download backup

**Endpoint**: `GET /apps/metavox/api/backup/download?filename=metavox_backup_2026-03-24_120000.json.gz`
**Requires**: Nextcloud admin

### Backup status

**Endpoint**: `GET /apps/metavox/api/backup/status`
**Requires**: Nextcloud admin

Returns the current status of a running backup or restore operation. Used by the frontend to show progress.

**Response**:
```json
{"status": "idle"}
```

---

## Settings

Admin-only endpoints for app configuration.

### Get settings

**Endpoint**: `GET /apps/metavox/api/settings`
**Requires**: Nextcloud admin

**Response**:
```json
{
  "success": true,
  "settings": {
    "ai_enabled": true,
    "url_web_only": false,
    "url_blocked_domains": ["ru"],
    "url_allowed_domains": [],
    "search_filter": true,
    "search_folders": false
  }
}
```

### Save settings

**Endpoint**: `POST /apps/metavox/api/settings`
**Requires**: Nextcloud admin

Send only the settings to change. The domain lists take a list or text with one domain per line; `.ru`, `Example.com` and `https://example.com/page` are read as `ru` and `example.com`.

**Request body**:
```json
{"ai_enabled": false, "url_blocked_domains": ".ru\nexample.com"}
```

A list that holds something that is no domain is a `400` with `fields.url_blocked_domains` or `fields.url_allowed_domains`, and nothing is saved from it.

---

## Telemetry

See [Telemetry](../admin/telemetry.md) for details. All telemetry endpoints require a Nextcloud admin.

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/apps/metavox/api/telemetry/status` | GET | Check if telemetry is enabled |
| `/apps/metavox/api/telemetry/stats` | GET | View collected statistics |
| `/apps/metavox/api/telemetry/send` | POST | Manually trigger telemetry report |
| `/apps/metavox/api/telemetry/settings` | POST | Enable or disable telemetry |

---

## Permissions

See [Permissions](../admin/permissions.md) for details. All permission endpoints require a Nextcloud admin.

MetaVox has one permission of its own: `manage_fields`, the manage permission. It lets a member who may change a team folder assign fields and set default values. Grant it with `{"user_id": "jdoe", "permission_type": "manage_fields", "groupfolder_id": 3}` (or `group_id` for a group); leave out `groupfolder_id` to grant it for every team folder. Any other `permission_type` is a `400`.

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/apps/metavox/api/permissions` | GET | List all permissions |
| `/apps/metavox/api/permissions/groups` | GET | List available groups |
| `/apps/metavox/api/permissions/user` | POST | Grant permission to a user |
| `/apps/metavox/api/permissions/group` | POST | Grant permission to a group |
| `/apps/metavox/api/permissions/user/{id}` | DELETE | Revoke user permission |
| `/apps/metavox/api/permissions/group/{id}` | DELETE | Revoke group permission |

---

## Initialization

### Bootstrap endpoint

**Endpoint**: `GET /apps/metavox/api/init`
**Requires**: logged in

Single-call initialization for the files plugin. Returns all data needed to render metadata columns in one request, eliminating multiple sequential API calls.

**Parameters**:
- `dir` — current directory path (used to detect groupfolder)

**Response**:
```json
{
  "groupfolders": [
    {"id": 3, "mount_point": "Shared Documents", ...}
  ],
  "groupfolder_id": 3,
  "fields": [
    {"field_name": "file_gf_publicatiestatus", "field_label": "Publicatiestatus", "field_type": "select", ...}
  ],
  "views": [
    {"id": 1, "name": "WOO open", "is_default": true, "columns": [...], ...}
  ],
  "can_manage": true
}
```

`can_manage` is `true` when the caller may change the detected team folder (and so its views).

> **Note**: This endpoint also registers presence for the detected groupfolder (for real-time sync).

---

## User Endpoints (Browser Routes)

Non-admin endpoints for the personal settings page and the search filter. These require a browser session.

| Endpoint | Method | Requires | Description |
|----------|--------|----------|-------------|
| `/apps/metavox/api/user/groupfolders` | GET | logged in | Team folders the caller may manage (manage permission and may change the team folder), each with `can_manage: true` |
| `/apps/metavox/api/user/groupfolder-fields` | GET | logged in | Field definitions: all of them for admins and managers, otherwise those of the caller's team folders |
| `/apps/metavox/api/user/groupfolders/{id}/fields` | GET | member | Assigned fields of a groupfolder |
| `/apps/metavox/api/user/groupfolders/{id}/fields` | POST | manage permission | Assign fields to a groupfolder (`{"field_ids": [...]}`) |
| `/apps/metavox/api/user/groupfolders/{id}/field-values?field={name}` | GET | member | Distinct stored values of a field, from files the caller can read: `{"values": [...]}` |
| `/apps/metavox/api/user/groupfolders/{id}/metadata` | GET | member | Groupfolder metadata |

---

## Utility Endpoints (Browser Routes)

### Search people

**Endpoint**: `GET /apps/metavox/api/accounts?search={query}&kinds=user,group,team`

**Requires**: logged in

Returns up to 25 matching accounts, groups and teams, of the kinds asked for (`user` when left out). Used by the `user` field type's picker. Nextcloud's sharee search finds them, so its settings decide whom you can find (user enumeration, own groups only, group sharing, the teams you can see); the caller is always included when the search matches. `id` is what the field stores.

**Response**:
```json
[
  {"id": "jdoe", "kind": "user", "name": "Jane Doe"},
  {"id": "group:staff", "kind": "group", "name": "Staff"},
  {"id": "team:Ab12Cd34", "kind": "team", "name": "Project X"}
]
```

---

## File Metadata (Browser Routes)

### Get backlinks of a file

**Endpoint**: `GET /apps/metavox/api/groupfolders/{groupfolderId}/files/{fileId}/backlinks`
**Requires**: read permission on the file in this team folder

Lists the files whose File Link field points to this file. Referring files you cannot see are left out.

**Response**:
```json
[
  {"referringFileId": 812, "referringFileName": "Besluit.pdf", "referringFilePath": "/Shared Documents/2026/Besluit.pdf",
   "groupfolderId": 3, "fieldName": "file_gf_bijlage", "fieldLabel": "Bijlage"}
]
```

### Save metadata for several files

**Endpoint**: `POST /apps/metavox/api/files/bulk-metadata`
**Requires**: logged in; each file is checked for update permission

**Request body**:
```json
{"fileIds": [123, 456], "metadata": {"file_gf_publicatiestatus": "Open"}, "mergeStrategy": "overwrite"}
```

`mergeStrategy` is `overwrite` (default) or `fill-empty`, which keeps values already filled in (a stored `"0"` too). Files may be in different team folders.

**Response**:
```json
{"success": false, "successCount": 1, "errorCount": 1, "errors": ["File 456: No permission to change the metadata of this file"]}
```

### Clear metadata of several files

**Endpoint**: `POST /apps/metavox/api/files/clear-metadata`
**Requires**: logged in; each file is checked for update permission

Removes all file metadata of the given files. Files you may not change are counted in `errorCount`.

**Request body**:
```json
{"fileIds": [123, 456]}
```

**Response**:
```json
{"status": "success", "successCount": 2, "errorCount": 0}
```

### Export metadata

**Endpoint**: `POST /apps/metavox/api/files/export-metadata`
**Requires**: logged in; files you cannot read are left out

Exports metadata for selected files as a JSON array (the client formats it as CSV). File Link values come as file names.

**Request body**:
```json
{"fileIds": [123, 456, 789]}
```

**Response**:
```json
[
  {"fileId": 123, "path": "/Shared Documents/Plan.docx", "name": "Plan.docx", "metadata": {"file_gf_publicatiestatus": "Open"}}
]
```

---

## Fields (Browser Routes)

### Delete several fields

**Endpoint**: `POST /apps/metavox/api/groupfolder-fields/bulk-delete`
**Requires**: Nextcloud admin

**Request body**:
```json
{"ids": [4, 5, 6]}
```

**Response**:
```json
{"success": true, "deleted": [4, 6], "skipped": [{"id": 5, "reason": "in_use"}]}
```

A field still assigned to a team folder is skipped with `in_use`; an unknown id with `not_deleted`.

---

## Default Values

Default values per team folder fill in file fields that have no value. A background job applies them every 5 minutes.

### Get defaults

**Endpoint**: `GET /apps/metavox/api/groupfolders/{groupfolderId}/defaults`
**Requires**: admin, or a member with the MetaVox manage permission who may change the team folder

**Response**:
```json
{"defaults": {"12": "Open", "14": "2026-01-01"}, "resolved": {}}
```

`defaults` is keyed by field id. `resolved` holds, per File Link field, the linked files as the caller sees them.

### Set or clear a default

**Endpoint**: `POST /apps/metavox/api/groupfolders/{groupfolderId}/defaults`
**Requires**: admin, or a member with the MetaVox manage permission who may change the team folder

**Request body**:
```json
{"fieldId": 12, "value": "Open"}
```

`value: null` or `""` clears the default. The value is checked as a value of that field is (see [Values per field type](#values-per-field-type)) and stored in the same form; a file-link default must point at files in this team folder that you may open. A value that does not fit is a `400` with `reason: "invalid_input"` and `fields.value`; a field that is not an assigned file field of this team folder is a `400` too.

### Apply defaults now

**Endpoint**: `POST /apps/metavox/api/groupfolders/{groupfolderId}/defaults/trigger`
**Requires**: admin, or a member with the MetaVox manage permission who may change the team folder

Starts a pass over all files of the team folder and queues the background job for the next cron run. It returns at once; it does not fill in files during the request.

**Response**:
```json
{"success": true, "queued": true}
```

### Default status

**Endpoint**: `GET /apps/metavox/api/groupfolders/{groupfolderId}/defaults/status`
**Requires**: admin, or a member with the MetaVox manage permission who may change the team folder

**Response**:
```json
{"state": "running", "pending": true}
```

`state` is `running` while files are still being filled in, otherwise `idle`.

---

## License

All license endpoints require a Nextcloud admin.

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/apps/metavox/api/license/stats` | GET | License state and usage: `{"success": true, "stats": {...}}`; the key comes masked |
| `/apps/metavox/api/settings/license` | POST | Save the license key: `{"licenseKey": "..."}` |
| `/apps/metavox/api/license/validate` | POST | Validate the key with the license server: `{"success": true, "validation": {...}}` |
| `/apps/metavox/api/license/update-usage` | POST | Report usage to the license server: `{"success": true, "result": {...}}` |
