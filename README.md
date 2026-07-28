# Seedbox

Seedbox is a standalone, file-backed PHP REST server for local API development.
It provides convention-based CRUD resources, declarative mock routes, JSON
fixtures, schema-aware validation, deterministic seed/reset behavior, and a
browser workspace for trying requests.

Seedbox has no database, package manager, authentication layer, gateway, or
runtime mode selection. It is intended for local development and test data,
not for public deployment.

Seedbox is released under the [MIT License](LICENSE). See
[CONTRIBUTING.md](CONTRIBUTING.md) for development and pull-request guidance,
and [SECURITY.md](SECURITY.md) for vulnerability reporting.

## Requirements

- PHP 8.0 or newer. Seedbox uses built-in PHP functions such as
  `str_starts_with` and `str_contains`.
- Write access to the project directory. CRUD mutations and the generators
  write JSON files and lock files under `api/` and `routes/`.
- No Composer dependencies or Node tooling are required.

## Setup

Run the built-in PHP server from the project root:

```sh
php -S 0.0.0.0:8000 -t public public/index.php
```

The `public/` directory is the document root, while `public/index.php` is the
front controller. The same front controller works with Herd:

```text
Document root: /workspace/public
```

Useful URLs:

| URL | Purpose |
| --- | --- |
| `/` | Seedbox workspace |
| `/seedbox` | Seedbox workspace alias |
| `/api/{resource}` | CRUD collection endpoint |
| `/routes-config` | Local-only CRUD resource generator |
| `/mock-route-config` | Local-only mock route generator |
| `/todos` | Independent Todos demo application |

The Todos application is not part of Seedbox's workspace UI. It is a separate
client at `public/todos/index.html` that uses the `todos` API resource.

## Request Architecture

`public/index.php` loads the server modules and calls `dispatch()` from
`router.php`.

Routing is handled as follows:

1. `/` and `/seedbox` render `seedbox.php`.
2. `/routes-config` invokes the CRUD resource generator.
3. `/mock-route-config` invokes the mock route generator.
4. `/api/...` is dispatched to generated CRUD routes first, then to custom
   routes registered in `routes/`.
5. Other paths are served from `public/`. Directories containing
   `index.html`, such as `/todos`, are supported.

All API responses are JSON and include `Access-Control-Allow-Origin: *`.
CRUD and custom API routes also return `Content-Type: application/json`.
Unknown paths return a JSON `404`; unsupported methods return `405`.

Important server modules:

- `seedbox.php`: browser workspace, request forms, response viewer, and
  generator forms.
- `router.php`: front-controller and built-in-server dispatch.
- `server/api/route.php`: CRUD/custom route matching and mutation handlers.
- `server/api/schema.php`: schema loading, type casting, and validation.
- `server/api/repository.php`: locks, atomic writes, seed hydration, and reset.
- `server/api/list.php`: projection, filtering, sorting, and pagination.
- `server/api/resource-config.php`: CRUD package generator.
- `server/api/mock-route-config.php`: custom mock route generator.
- `server/helpers.php`: JSON headers, query parsing, and nested field access.

## Project Storage

The project is configured through directories and JSON files:

```text
api/
  {resource}/
    schema.json       # field types and editability
    list.json         # projection, pagination, operation settings
    seed.json         # canonical fixture array, optional
    .write.lock       # generated mutation lock
    id/
      {number}.json   # runtime records, generated as needed
  mocks/
    {resource}/
      {response}.json # custom route response fixtures
routes/
  {resource}.json     # custom routes for the resource
public/
  index.php           # PHP front controller
  todos/index.html    # independent Todos client
seedbox.php           # Seedbox workspace
router.php            # server router
```

All response fixture paths must remain under `api/`. Runtime record files are
the current mutable state. `seed.json` is the canonical starting state and is
not changed by ordinary create, patch, or delete requests.

Resource names must match:

```text
^[a-z][a-z0-9-]*$
```

They may be up to 64 characters. `admin`, `routes-config`, `seedbox`, and
`mocks` are reserved names for generated resources.

The repository keeps the generic Todos sample client and its supporting sample
fixtures visible in source control. Its mutable runtime files remain ignored:
`api/todos/id/*.json` and `api/todos/.write.lock`.

## CRUD Resources

Any directory under `api/` containing both `schema.json` and `list.json` is
automatically treated as a CRUD resource. No PHP route file is needed.

For a resource named `posts`, the generated endpoints are:

| Method | Path | Operation |
| --- | --- | --- |
| `GET` | `/api/posts` | List records |
| `POST` | `/api/posts` | Create a record |
| `GET` | `/api/posts/{id}` | Read a numeric record |
| `PATCH` | `/api/posts/{id}` | Partially update a record |
| `DELETE` | `/api/posts/{id}` | Delete a record |
| `POST` | `/api/posts/reset` | Delete runtime records and reseed |

Only numeric IDs are recognized by generated routes. A custom route cannot
replace a generated collection, numeric record path, or reset path.

### `schema.json`

The schema is an object keyed by field name. Each field definition supports:

- `type`: `string`, `number`, `boolean`, `array`, or `datetime`.
- `editable`: allows clients to submit the field on create and patch.
- `automatic`: marks a server-managed field.
- `default`: value used when an editable create request omits the field.
- `required`: accepted as schema metadata; request completeness is otherwise
  controlled by the fields and defaults configured by the resource.

The server-managed fields are `id`, `createdAt`, `modifiedAt`, and `version`.
They should be declared with `automatic: true` and cannot be submitted by API
clients. A standard schema looks like this:

```json
{
  "id": { "type": "number", "automatic": true },
  "name": { "type": "string", "editable": true, "default": "" },
  "price": { "type": "number", "editable": true, "default": 0 },
  "active": { "type": "boolean", "editable": true, "default": true },
  "tags": { "type": "array", "editable": true, "default": [] },
  "createdAt": { "type": "datetime", "automatic": true },
  "modifiedAt": { "type": "datetime", "automatic": true },
  "version": { "type": "number", "automatic": true }
}
```

Create and patch requests are JSON objects. Unknown, automatic, server-managed,
or non-editable fields produce `400`. Submitted values are cast and checked by
type:

- Numbers accept numeric values and numeric strings.
- Booleans accept `true`, `false`, `1`, `0`, and their string forms.
- Arrays must be JSON arrays.
- Strings reject arrays and are otherwise converted to strings.
- Datetimes are treated as string fields by request casting.

### `list.json`

`list.json` controls collection responses and enabled operations:

```json
{
  "fields": ["id", "name", "price", "active", "version"],
  "_limit": 10,
  "last_id": 3,
  "disable": [],
  "_hidden": [],
  "defaultFilters": {}
}
```

- `fields` is the collection response projection. Nested paths are supported.
- `_limit` is the default page size and is always at least `1`.
- `last_id` tracks the last allocated numeric ID. ID allocation also scans
  existing files to avoid collisions.
- `disable` accepts `list`, `create`, `read`, `patch`, `delete`, and `reset`.
  Disabled operations return `404`.
- `_hidden` removes fields from loaded records before projection.
- `defaultFilters` applies filters when a request does not provide the same
  filter key.

List responses use `fields`; individual reads return the full stored record.

### `seed.json` and Runtime State

`seed.json` contains a JSON array of records. Seed IDs must be unique positive
integers and values must satisfy the schema. An empty `seed.json` (`[]`) starts
the resource empty.

When an enabled CRUD operation first accesses a resource and `id/` has no JSON
records, Seedbox validates and hydrates the seed records into `id/{id}.json`.
It also updates `list.json:last_id` to the highest seed ID. Existing runtime
records are not overwritten automatically.

Reset explicitly clears `id/*.json`, validates and writes the seed records,
and updates `last_id`:

```sh
curl -X POST http://localhost:8000/api/posts/reset
```

Mutation behavior:

- Create returns `201`, assigns the next numeric ID, sets `version` to `1`,
  and generates UTC `createdAt` and `modifiedAt` timestamps.
- Create applies schema defaults to omitted editable fields.
- Patch accepts only editable fields, increments `version`, and updates
  `modifiedAt`.
- Delete requires the current version in the JSON body. A stale version returns
  `409`.
- All create, patch, delete, reset, seed, and generator writes use exclusive
  lock files and temporary-file-then-rename atomic writes.

Examples:

```sh
curl -s http://localhost:8000/api/posts

curl -s -X POST http://localhost:8000/api/posts \
  -H 'Content-Type: application/json' \
  -d '{"title":"Local fixture","tags":["dev"],"isPublished":false}'

curl -s -X PATCH http://localhost:8000/api/posts/3 \
  -H 'Content-Type: application/json' \
  -d '{"title":"Updated fixture"}'

curl -s -X DELETE http://localhost:8000/api/posts/3 \
  -H 'Content-Type: application/json' \
  -d '{"version":1}'
```

## List Queries

Collection requests support page, sort, field, nested-field, and operator
filters:

```text
GET /api/posts?_page=2
GET /api/posts?_sort=title:ASC;id:DESC
GET /api/posts?title=design
GET /api/posts?tags__SEARCH=api
GET /api/posts?priority__GTE=3
GET /api/posts?priority__LTE=5
GET /api/posts?status__NE=archived
GET /api/posts?author.name=Taylor
```

Rules:

- `_page` is one-based. The page size comes from `list.json:_limit`.
- `_sort` accepts semicolon-separated `field:ASC` or `field:DESC` values.
- `sortBy=field` is supported as an ascending sort fallback.
- Plain scalar string filters are case-insensitive substring matches.
- Comma-separated values are alternatives for scalar and array fields.
- `__SEARCH` performs substring matching on strings and array members.
- `__NE` excludes matching values.
- `__GTE` and `__LTE` support numeric and datetime schema fields. Numeric
  comparisons are used for numeric values.
- Nested fields use dot notation and bracket notation such as
  `profile.name` or `profile["name"]`.
- Unknown filter and sort fields return `400` when a schema is available.
- `defaultFilters` can use `and` and `or` objects for compound defaults.

## Custom Mock Routes

Custom routes are declared per resource in `routes/{resource}.json`. The first
path segment after `/api/` selects the registry. Response fixtures normally
live in `api/mocks/{resource}/`.

```json
{
  "/api/posts/total": {
    "GET": {
      "file": "api/mocks/posts/total.json",
      "status": 200
    }
  },
  "/api/posts/{slug}/preview": {
    "GET": {
      "file": "api/mocks/posts/preview.json",
      "status": 200,
      "headers": { "X-Mock": "true" }
    }
  }
}
```

Route configuration rules:

- Paths must start with `/api/` and cannot contain a query string or fragment.
- HTTP method names must contain only letters.
- Each method requires `file` or `path`, beginning with `api/`.
- `status` is optional and must be between `100` and `599`; it defaults to
  `200`.
- Header keys and values must be strings.
- Content, CORS, connection, transfer-encoding, content-length, and `Allow`
  headers are reserved and cannot be overridden.
- `{name}` parameters match one non-empty path segment and are substituted in
  the fixture path.
- Static paths match before parameterized paths.
- A missing path returns `404`; a known path with an unsupported method returns
  `405` with `Allow`; `OPTIONS` returns `204` with allowed methods.
- Request bodies, query parameters, and request headers do not change a static
  fixture response.

Do not register mocks for `/api/{resource}`, `/api/{resource}/reset`, or
`/api/{resource}/{numeric-id}` because generated CRUD routes take precedence.

## Todos Sample App

`/todos` is an independent generic demo application showing how a small client
can consume both generated CRUD endpoints and custom mock routes. It is not a
Seedbox-branded product screen and does not change the server architecture.

The sample uses:

| Endpoint | Type | Purpose |
| --- | --- | --- |
| `/api/todos` | Generated CRUD | List, create, patch, and delete todos |
| `/api/todos/stats` | Static mock | Display sample aggregate counts |
| `/api/todos/tips` | Static mock | Display client-facing sample guidance |
| `/api/todos/{slug}/preview` | Parameterized mock | Demonstrate path matching and fixture substitution |

The mock registry is `routes/todos.json`; response fixtures are under
`api/mocks/todos/`. The client loads the three sample routes on startup and
renders their responses in the Sample API panel. The aggregate values are
static fixture data and are intentionally not recalculated after CRUD changes.

The Todo CRUD schema is defined in `api/todos/schema.json` and includes
`title`, `description`, and `isCompleted`. Its collection projection is defined
in `api/todos/list.json`. To restore the sample data during development:

```sh
curl -X POST http://localhost:8000/api/todos/reset
```

## Generators

The Seedbox workspace exposes two local-only generators:

- **Create Route** posts a schema, selected CRUD operations, limit, and seed
  array to `POST /routes-config`. It creates `schema.json`, `list.json`,
  `seed.json`, and initial runtime records.
- **Add Mock Route** posts a resource, path, method, status, and response body
  to `POST /mock-route-config`. It creates a response fixture under
  `api/mocks/{resource}/` and updates `routes/{resource}.json`.

Both endpoints accept only requests from `127.0.0.1` or `::1`. They require
`POST` with `Content-Type: application/json`; `OPTIONS` is supported for local
browser requests. They are not intended to be exposed through a proxy or
shared network.

Use the detailed guides when generating project data:

- [gen_api.md](gen_api.md): CRUD resource modeling, schema/list/seed files,
  fixture quality, and validation checklist.
- [gen_routes.md](gen_routes.md): custom route ownership, registry syntax,
  response fixtures, matching behavior, and validation checklist.

## Seedbox Workspace

The homepage is a browser-based local API workspace. It provides:

- Route groups loaded from `routes/` and generated CRUD resources.
- Expand/collapse endpoint cards and filtering by group, path, method, or
  operation.
- Path parameter inputs, query editors, request headers, and JSON request
  bodies.
- Schema-driven create and patch forms with type hints and defaults.
- Version-aware delete forms and destructive reset confirmation.
- Response status, URL, timing metadata, and formatted JSON bodies.
- Response search with match highlighting and previous/next navigation.
- Persistent light/dark theme selection in browser `localStorage`.

Route files are read on each page request. JSON configuration changes are
available on the next request. Restart the built-in server after PHP changes.

## Security and Limitations

Seedbox is a local development server:

- API routes are unauthenticated and cross-origin.
- Generator endpoints are localhost-restricted but mutate the filesystem.
- The server should not be bound to a shared or public network.
- Fixture files can contain sensitive data if you add it; do not commit real
  credentials, tokens, or customer data.
- Concurrent mutations are serialized per resource using `.write.lock`, but
  this is not a replacement for a production database.

## Open-Source Development

The repository includes GitHub Actions validation in
`.github/workflows/ci.yml`. Every push and pull request lints tracked PHP
files and parses tracked JSON files. Issue forms are available for bug reports
and feature requests, and pull requests use the checklist in
`.github/pull_request_template.md`.

The current release history is tracked in [CHANGELOG.md](CHANGELOG.md). Runtime
records and lock files are ignored so contributors can run the server locally
without committing mutable state. Configuration, seed data, mock fixtures, and
the generic Todos sample client remain publishable source files.

## Verification

There is no automated test suite. Run PHP lint over the server modules:

```sh
php -l public/index.php
php -l router.php
php -l seedbox.php
php -l server/helpers.php
php -l server/api/schema.php
php -l server/api/repository.php
php -l server/api/resource-config.php
php -l server/api/mock-route-config.php
php -l server/api/list.php
php -l server/api/route.php
```

Smoke-test the primary routes:

```sh
curl -i http://localhost:8000/
curl -i http://localhost:8000/seedbox
curl -i http://localhost:8000/api/posts
curl -i http://localhost:8000/todos
```

Validate JSON configuration with PHP when needed:

```sh
php -r 'json_decode(file_get_contents("api/posts/schema.json"), true, 512, JSON_THROW_ON_ERROR);'
php -r 'json_decode(file_get_contents("api/posts/list.json"), true, 512, JSON_THROW_ON_ERROR);'
php -r 'json_decode(file_get_contents("api/posts/seed.json"), true, 512, JSON_THROW_ON_ERROR);'
```
