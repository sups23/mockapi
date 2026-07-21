# File-backed JSON Mock API

Standalone PHP mock server with declarative keyed route configuration. Add or change
endpoints by editing files in `routes/` — no PHP changes needed.

## Run

```sh
php -S 0.0.0.0:8000 -t public public/index.php
```

Open `/` for the API explorer. `/api-test` is an alias.

## Route files

Routes are organized in `routes/` as one JSON file per resource. Each file
maps URL patterns to HTTP method configurations. The filename (minus `.json`)
must match the first path segment after `/api/`.

```text
routes/
  posts.json       -> /api/posts/**
  health.json      -> /api/health
  custom-data.json -> /api/custom-data/**
```

### Route file format

```json
{
  "/api/posts": {
    "GET": { "operation": "list", "status": 200 },
    "POST": { "operation": "create", "status": 201 }
  },
  "/api/posts/{id}": {
    "GET": { "operation": "read", "status": 200 },
    "PATCH": { "operation": "patch", "status": 200 },
    "DELETE": { "operation": "delete", "status": 200 }
  },
  "/api/posts/reset": {
    "POST": { "operation": "reset", "status": 200 }
  }
}
```

Exact static paths (like `/api/posts/reset`) are matched before dynamic
`{id}` patterns. This eliminates ambiguity without depending on file order.

### Route config

| Field | Required | Description |
| --- | --- | --- |
| `operation` | yes | `list`, `create`, `read`, `patch`, `delete`, `mock`, or `reset` |
| `status` | no | HTTP status code 100–599 (default 200) |
| `path` | mock only | Internal JSON file path starting with `api/`. Required for `mock` |
| `headers` | no | Response header name/value strings |

### Routing

- Request URL determines the route file: `/api/posts/5` loads `routes/posts.json`
- Exact path keys are matched before dynamic `{param}` patterns
- Unknown path: `404`
- Known path, unknown method: `405` with `Allow` header
- Configuration errors: `500` with details (local dev only)

JSON edits take effect immediately. PHP changes require a restart.

## Operations

### list

```json
"GET": { "operation": "list", "status": 200 }
```

- Scans `api/{resource}/id/*.json` files
- Filters: `?field=value`, `?field__SEARCH=text`, `?field__LTE=10`, `?field__GTE=5`, `?field__NE=value`
- Search works on string/array fields; range operators work only on number fields
- Unknown filter fields return `400`
- Sorting: `?_sort=field:ASC;other:DESC` or `?sortBy=field`
- Paging: `?_page=1` (page size from `list.json:_limit`)

### create

```json
"POST": { "operation": "create", "status": 201 }
```

- Validates request body against sibling `schema.json`
- Rejects server-managed fields (`id`, `createdAt`, `modifiedAt`, `version`) with `400`
- Rejects unknown/non-editable fields with `400`
- Applies schema defaults for omitted editable fields
- Allocates next numeric ID under exclusive write lock
- Returns created record with server fields

### read

```json
"GET": { "operation": "read", "status": 200 }
```

Serves the resolved JSON file.

### patch

```json
"PATCH": { "operation": "patch", "status": 200 }
```

- Validates body against `schema.json` (editable fields only)
- Rejects server-managed, unknown, and non-editable fields
- Acquires lock before re-reading record
- Updates only supplied fields, increments `version`, updates `modifiedAt`
- Writes atomically via temp-file-and-rename

### delete

```json
"DELETE": { "operation": "delete", "status": 200 }
```

- **Requires `{"version": <number>}` in the request body.** Get it from a `GET` read.
- Missing version: `400`
- Stale version: `409 Conflict`
- Hard deletes the record file under lock

### mock

```json
"GET": { "operation": "mock", "status": 200, "path": "api/mocks/health.json" }
```

Serves a static JSON file. `path` is required. Any HTTP method. Supports `{param}` placeholders.

### reset

```json
"POST": { "operation": "reset", "status": 200 }
```

Wipes all runtime records in `id/` and re-seeds from `seed.json`. Returns seeded count and `last_id`.
Returns `400` if no `seed.json` exists. All destructive. Use for test isolation and CI.

## list.json

```json
{
  "fields": ["id", "title", "isPublished", "createdAt", "modifiedAt", "version"],
  "_limit": 4,
  "last_id": 16
}
```

- `fields` (required): projected response properties
- `_limit`: items per page (default 10)
- `last_id`: latest allocated record ID
- `_hidden`: fields to strip from responses
- `defaultFilters`: filters applied unless overridden

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
- Used by the `reset` operation to restore initial state
- IDs must be unique

## Storage

```text
routes/
  posts.json
  health.json
  custom-data.json
api/
  posts/
    schema.json
    list.json
    seed.json
    id/
      3.json
      5.json
  mocks/
    health.json
  custom-data-file/
    custom-url.json
```

Only `routes/` files define endpoints. All JSON data files must be under `api/`.

## Route creator

`POST /routes-config` creates a complete CRUD resource in one request. Available from `localhost` only.

```json
{
  "model": "products",
  "routes": {
    "list": true, "create": true,
    "read": true, "patch": true,
    "delete": true, "reset": true
  },
  "schema": {
    "name": { "type": "string", "editable": true, "default": "" },
    "price": { "type": "number", "editable": true, "default": 0 }
  },
  "limit": 10,
  "seed": []
}
```

| Field | Required | Description |
| --- | --- | --- |
| `model` | yes | Lowercase letters, digits, hyphens. Must start with a letter. |
| `routes` | yes | Object with boolean keys: `list`, `create`, `read`, `patch`, `delete`, `reset`. At least one must be `true`. |
| `schema` | yes | User-editable fields only. Server fields (`id`, `createdAt`, `modifiedAt`, `version`) added automatically. |
| `limit` | no | Items per page (default 10, max 100). |
| `seed` | no | JSON array of seed records with unique positive integer `id` values. |

Generates `routes/{model}.json`, `api/{model}/schema.json`, `api/{model}/list.json`, `api/{model}/seed.json`, and the `api/{model}/id/` directory.

Returns `201` on success, `400` for invalid input, `409` if the resource already exists. Only `POST` is accepted; `OPTIONS` supported for CORS preflight.

The explorer includes a **Create Route** wizard (topbar button) that builds this request payload from a form.

## Explorer

Loads route groups, renders collapsible endpoint cards with path params, schema-based create defaults, filter builders, version-aware delete forms, reset confirmations, query/header editors, and response search with highlight/navigation. Light/dark theme persisted. Includes a **Create Route** wizard for generating new CRUD resources.

## Safety

Local development only. All API routes are unauthenticated and cross-origin. The `/routes-config` endpoint is restricted to `localhost`. Do not expose on a shared network.
