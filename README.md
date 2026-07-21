# File-backed JSON Mock API

Standalone PHP mock server with convention-based CRUD and declarative mock routes.

## Run

```sh
php -S 0.0.0.0:8000 -t public public/index.php
```

Open `/` for the API explorer. `/api-test` is an alias.

## Convention-based CRUD

Any directory under `api/` that contains both `list.json` and `schema.json` is automatically a CRUD resource. No route file needed.

```text
api/
  posts/
    schema.json    # Field types, defaults, editability
    list.json      # Fields projection, pagination, disable
    seed.json      # Initial data (optional)
    id/            # Runtime records
```

Exposes:

```text
GET     /api/posts
POST    /api/posts
GET     /api/posts/{id}
PATCH   /api/posts/{id}
DELETE  /api/posts/{id}
POST    /api/posts/reset
```

Disable specific operations in `list.json`:

```json
{
  "fields": ["id", "title", "version"],
  "_limit": 10,
  "last_id": 0,
  "disable": ["delete", "reset"]
}
```

Supported disable values: `list`, `create`, `read`, `patch`, `delete`, `reset`.

## list.json

```json
{
  "fields": ["id", "title", "isPublished", "version"],
  "_limit": 4,
  "last_id": 16,
  "disable": ["delete", "reset"]
}
```

| Field | Required | Description |
| --- | --- | --- |
| `fields` | yes | Projected response properties |
| `_limit` | no | Items per page (default 10) |
| `last_id` | no | Latest allocated record ID |
| `_hidden` | no | Fields to strip from responses |
| `defaultFilters` | no | Filters applied unless overridden |
| `disable` | no | Array of disabled operations |

## schema.json

```json
{
  "id": { "type": "number", "automatic": true },
  "title": { "type": "string", "editable": true, "default": "" },
  "content": { "type": "string", "editable": true, "default": "" },
  "tags": { "type": "array", "editable": true, "default": [] },
  "isPublished": { "type": "boolean", "editable": true, "default": false },
  "createdAt": { "type": "datetime", "automatic": true },
  "modifiedAt": { "type": "datetime", "automatic": true },
  "version": { "type": "number", "automatic": true }
}
```

Types: `string`, `number`, `boolean`, `array`, `datetime`.

Properties:
- `type` (required): field data type
- `editable` (boolean): field can be set via create/patch
- `automatic` (boolean): field is server-managed
- `default`: default value for create when field is omitted
- `required` (boolean): field must be supplied in create

Server-managed fields (`id`, `createdAt`, `modifiedAt`, `version`) are always automatic regardless of schema.

## Operations

### list

`GET /api/{resource}`

- Scans `api/{resource}/id/*.json` files
- Filters: `?field=value`, `?field__SEARCH=text`, `?field__LTE=10`, `?field__GTE=5`, `?field__NE=value`
- Search works on string/array fields; range operators only on number/datetime fields
- Unknown filter fields return `400`
- Sorting: `?_sort=field:ASC;other:DESC` or `?sortBy=field`
- Paging: `?_page=1` (page size from `list.json:_limit`)

### create

`POST /api/{resource}`

- Validates body against `schema.json`
- Rejects server-managed fields with `400`
- Rejects unknown/non-editable fields with `400`
- Applies schema defaults for omitted fields
- Allocates next numeric ID under exclusive lock
- Returns `201` with created record

### read

`GET /api/{resource}/{id}`

Serves the resolved JSON file.

### patch

`PATCH /api/{resource}/{id}`

- Validates body against `schema.json` (editable fields only)
- Rejects server-managed, unknown, and non-editable fields
- Acquires lock before re-reading record
- Updates only supplied fields, increments `version`, updates `modifiedAt`
- Writes atomically via temp-file-and-rename

### delete

`DELETE /api/{resource}/{id}`

- **Requires `{"version": <number>}` in the request body.** Get it from a `GET` read.
- Missing version: `400`
- Stale version: `409 Conflict`
- Hard deletes the record file under lock

### reset

`POST /api/{resource}/reset`

Wipes all runtime records in `id/` and re-seeds from `seed.json`. Returns seeded count and `last_id`.
Returns `400` if no `seed.json` exists. Use for test isolation and CI.

## seed.json

```json
[
  {
    "id": 3,
    "title": "Declarative route registry",
    "content": "...",
    "tags": ["architecture"],
    "isPublished": true,
    "createdAt": "2026-05-20T10:30:00.000Z",
    "modifiedAt": "2026-05-20T10:30:00.000Z",
    "version": 1
  }
]
```

- Must be a JSON array of objects with positive integer `id` values
- Automatically hydrated on first list/read request when `id/` is empty
- Empty array `[]` means the app starts with no records
- Used by the `reset` operation to restore initial state

## Storage

```text
api/
  posts/
    schema.json
    list.json
    seed.json
    id/
      3.json
      5.json
  todos/
    schema.json
    list.json
    seed.json
    id/
```

All JSON data files must be under `api/`.

## Explorer

Loads route groups, renders collapsible endpoint cards with path params, schema-based create defaults, filter builders, version-aware delete forms, reset confirmations, query/header editors, and response search with highlight/navigation. Light/dark theme persisted. Includes a **Create Route** wizard for generating new CRUD resources.

## Safety

Local development only. All routes are unauthenticated and cross-origin. The `/routes-config` endpoint is restricted to `localhost`. Do not expose on a shared network.
