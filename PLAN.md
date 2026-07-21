# Route Registry Revamp

## Purpose

Replace the hardcoded `/tasks` and `/posts` API with a JSON-configured route
registry. A front-end developer should be able to add a mock route, choose its
HTTP method and response status, attach response headers, and point it at a
file without changing PHP.

The system remains file-backed. It has two response sources:

1. `dataFile` returns one JSON file, optionally selected by path parameters.
2. `listFile` identifies a collection's `list.json` configuration. GET uses
   that configuration to return records from the sibling `id/` directory.
   POST uses the sibling `schema.json` to validate and create a new record.

This plan intentionally removes the old fixed-resource REST layer. Schema
backed creation is retained because it is explicitly required. PUT, PATCH, and
DELETE are no longer implicit persistence operations; they can still be mock
routes, but simply serve their configured response source.

## Existing System

### Request flow

1. `public/index.php` sets the project root, loads server files, then calls
   `dispatch()` from `router.php`.
2. `router.php` sends `/` and `/api-test` to the API explorer.
3. For other requests, `router.php` currently admits only paths beginning with
   `tasks` or `posts`.
4. `server/api/route.php` maps those two public paths to fixed storage paths:
   `tasks` maps to `api/ALL/tasks`; `posts` maps to `api/posts`.
5. `server/api/rest.php` currently provides create, detail, replace, patch,
   and soft-delete behavior.
6. `server/api/list.php` scans `id/*.json` and uses `list.json` for filtering,
   sorting, projection, hidden fields, default filters, and pagination.
7. `api-test.php` separately hardcodes tasks/posts and renders fixed CRUD
   forms.

### Current data layout

```text
api/
  posts/
    schema.json
    list.json
    id/
      2.json
      13.json
```

Only `api/posts/` exists. The current `/tasks` route is therefore invalid:
the explorer advertises it, but GET returns `404 Resource not found` and POST
returns `404 Schema not found`.

### Current list.json meaning

The current `api/posts/list.json` is configuration rather than a response:

```json
{
  "fields": ["id", "_oc", "title", "isPublished", "createdAt", "modifiedAt", "version"],
  "_limit": 4,
  "last_id": 13
}
```

- `fields`: properties projected into each collection response item.
- `_limit`: default items per page.
- `last_id`: latest allocated numeric record ID.

The revamp retains this role. `listFile` must always reference this type of
configuration file, not a pre-rendered response file.

## Target Layout

```text
routes.json
api/
  posts/
    schema.json
    list.json
    id/
      2.json
      13.json
mocks/
  health.json
  login-success.json
public/
  index.php
server/
  helpers.php
  api/
    list.php
    route.php
router.php
api-test.php
```

`routes.json` is the only public route registry. JSON files under `api/` or
`mocks/` are internal data and are never automatically public endpoints.

## routes.json Contract

`routes.json` is a root-level JSON array. Every object defines one route.

```json
[
  {
    "method": "GET",
    "path": "/posts",
    "status": 200,
    "listFile": "api/posts/list.json",
    "headers": {
      "X-Mock-Source": "posts"
    }
  },
  {
    "method": "POST",
    "path": "/posts",
    "status": 201,
    "listFile": "api/posts/list.json",
    "headers": {
      "X-Mock-Operation": "created"
    }
  },
  {
    "method": "GET",
    "path": "/posts/{id}",
    "status": 200,
    "dataFile": "api/posts/id/{id}.json"
  },
  {
    "method": "POST",
    "path": "/auth/login",
    "status": 201,
    "dataFile": "mocks/login-success.json",
    "headers": {
      "Cache-Control": "no-store"
    }
  },
  {
    "method": "REPORT",
    "path": "/reports/{name}",
    "status": 200,
    "dataFile": "mocks/reports/{name}.json"
  }
]
```

### Required properties

| Property | Type | Rules |
| --- | --- | --- |
| `method` | string | Required. A valid HTTP method token. Store and compare it in uppercase. |
| `path` | string | Required. Starts with `/`, contains no query string or fragment, and may contain `{name}` parameters. |
| `status` | integer | Required. HTTP status from 100 through 599. |
| `dataFile` or `listFile` | string | Exactly one is required. It is relative to the project root and must end in `.json`. |

### Optional response headers

`headers` is an optional object mapping header names to string values:

```json
"headers": {
  "Cache-Control": "no-store",
  "X-Mock-Scenario": "unauthenticated"
}
```

The server always sets `Content-Type: application/json; charset=utf-8` and
global CORS headers. The route configuration must not override:

- `Content-Type`
- `Access-Control-Allow-Origin`
- `Access-Control-Allow-Methods`
- `Access-Control-Allow-Headers`
- `Allow`
- `Content-Length`
- `Transfer-Encoding`
- `Connection`

Reject routes that configure reserved headers. This avoids invalid responses
and preserves correct OPTIONS/405 behavior. Non-JSON response support is out
of scope; add a dedicated content-type field later if it becomes necessary.

### Path parameters and data-file patterns

- `{name}` represents one non-empty URL segment.
- It cannot match `/`, an empty value, a NUL byte, `.` or `..`.
- Route parameter names must be valid identifiers: `[A-Za-z_][A-Za-z0-9_]*`.
- A parameter can be repeated in a route path only if each matching segment is
  the same value.
- A `dataFile` may use only parameters declared by `path`.
- `listFile` is not a pattern and must not contain `{...}`.
- Example: `/posts/{id}` and `api/posts/id/{id}.json` cause `/posts/13` to
  resolve to `api/posts/id/13.json`.

All paths are project-root-relative. Reject absolute paths, `..` segments, NUL
bytes, and any normalized target outside the project root. This applies both
when loading route configuration and after substituting path parameters.

## Route Dispatch Behavior

### Normal matching

1. Read and validate `routes.json` on each request. JSON edits therefore take
   effect immediately without restarting PHP.
2. Match the request path against configured route path patterns, ignoring the
   request method initially.
3. If no route path matches, return JSON `404`:

   ```json
   {"error":"Not found"}
   ```

4. If one or more paths match but none support the request method, return JSON
   `405` and `Allow: GET, POST, OPTIONS` using methods declared for that path.
5. If exactly one configured method/path pattern matches, serve that route.
6. A missing referenced response file returns JSON `404`. Invalid JSON in a
   referenced response or configuration file returns JSON `500`.

### Ambiguity validation

Reject invalid registry configuration before dispatching any route:

- Duplicate `method` plus equivalent `path` pattern.
- Same method with a static path and parameterized path that could both match,
  such as `/posts/new` and `/posts/{id}`.
- Equivalent parameterized patterns with different names, such as
  `/posts/{id}` and `/posts/{slug}`.
- Unused or undeclared placeholders in `dataFile`.

Rejecting ambiguity is preferable to declaration-order precedence because a
front-end developer can otherwise change behavior accidentally by moving JSON
objects.

### OPTIONS and CORS

`OPTIONS` is handled before normal route execution:

- Match the path using the same route registry.
- Return `204` and route-derived `Allow` / `Access-Control-Allow-Methods` for
  a known path.
- Return `404` for an unknown path.
- Set `Access-Control-Allow-Origin: *` for this local mock server.
- If the browser sends `Access-Control-Request-Headers`, validate it as a
  comma-separated header-name list and reflect it as
  `Access-Control-Allow-Headers`. This permits front-end tests with
  Authorization and custom headers instead of the current Content-Type-only
  preflight behavior.

## Route Source Behavior

### dataFile routes

`dataFile` routes are generic static JSON mocks:

1. Resolve placeholders from the matched request path.
2. Read and decode the selected JSON file.
3. Set the configured status and safe configured headers.
4. Return the decoded JSON response.

`dataFile` supports every configured request method. Request bodies, query
parameters, and headers do not alter the response in this initial version.
They are still accepted, allowing front-end request construction to be tested.

Examples:

- GET `/health` -> `mocks/health.json`
- POST `/auth/login` -> `mocks/login-success.json`
- GET `/posts/{id}` -> `api/posts/id/{id}.json`
- REPORT `/reports/{name}` -> `mocks/reports/{name}.json`

### listFile GET routes

For `GET` plus `listFile`, use the referenced `list.json` as collection
configuration and scan the sibling `id/` directory.

Retain these current features:

- Ignore records with a non-empty `deletedAt`.
- Page using `_page` and `list.json:_limit`; page defaults to 1 and is at least
  1.
- Filter with regular query fields and nested dotted fields.
- Keep the existing `__SEARCH`, `__NE`, `__GTE`, and `__LTE` filter suffixes.
- Use comma-separated query values for OR matching on scalar/array fields.
- Apply `defaultFilters` in `list.json`, unless the request supplies the same
  filter field.
- Sort with `_sort=field:ASC;other:DESC` or `sortBy=field`.
- Project records using `list.json:fields`.
- Remove `list.json:_hidden` properties after loading the record.

Remove behavior that is not in the new contract:

- Legacy `list.json` flat-array response mode.
- `/paginate` convention and `handle_paginate()`.
- Relation expansion.
- Hierarchy traversal.
- Dedicated detail-record handling in `serve_record()`.

A valid `listFile` must decode to an object with a `fields` array. A missing or
invalid `fields` array is a `500` route-data configuration error, not a silent
empty collection.

### listFile POST routes: schema-backed creation

Only a POST route with `listFile` persists a new record. This is the sole
remaining write behavior.

For a route such as:

```json
{
  "method": "POST",
  "path": "/posts",
  "status": 201,
  "listFile": "api/posts/list.json"
}
```

the server must:

1. Find `api/posts/schema.json`, the sibling of `list.json`.
2. Require `Content-Type` to be JSON when present; accept a missing content
   type for development convenience.
3. Decode `php://input` as a JSON object. Invalid JSON, arrays, scalars, or an
   empty body return `400`.
4. Parse schema values in the existing `type|flag` form. Supported base types
   are `string`, `number`, `boolean`, `array`, `datetime`, and
   `string (date-time)`.
5. Treat the following as server-managed regardless of the schema flag:
   `id`, `_oc`, `createdAt`, `modifiedAt`, `docStat`, and `version`.
6. Treat all `|automatic` fields as server-managed. Ignore any supplied value
   for a server-managed field rather than allowing a client to forge it.
7. Reject a submitted editable field absent from the schema with `400`.
8. Validate and cast submitted editable fields:
   - `number`: require `is_numeric`; retain integer/float distinction.
   - `boolean`: accept JSON booleans plus `true`, `false`, `1`, and `0` for
     compatibility with existing behavior.
   - `array`: require a JSON array.
   - string/date-time types: cast scalar values to strings; do not introduce
     date parsing/format enforcement in this iteration.
9. Build a complete new record. Every non-server-managed schema field exists
   in the record, using `null` when it was not supplied.
10. Derive `_oc` from the list directory basename. For `api/posts/list.json`,
    use `_oc: "posts"`, preserving compatibility with current records and
    current list projection.
11. Allocate an ID greater than both `list.json:last_id` and any existing
    numeric `id/*.json` filename.
12. Set server values: numeric `id`, `version: 1`, `docStat: "Active"`, and
    UTC ISO-8601 `createdAt` / `modifiedAt` timestamps.
13. Write `id/{id}.json`, update `list.json:last_id`, then return the complete
    created record with the configured route status and response headers.

Do not restore old PUT, PATCH, optimistic-locking, or soft-delete behavior.
They can become explicit future capabilities only if routes gain a dedicated
mutation configuration contract.

### Safe concurrent creation

Creation fixes a defect in the existing REST implementation: it currently
calculates IDs and writes JSON without synchronization.

Use one resource-specific lock file, for example `api/posts/.write.lock`:

1. Open the lock file with `c` mode.
2. Acquire `LOCK_EX`.
3. Re-read `list.json` and ID filenames while holding the lock.
4. Determine the next ID and construct the record.
5. Write each JSON file to a unique temporary file in its destination
   directory.
6. Rename temporary files into place. Rename is atomic on the same filesystem.
7. Release the lock in a `finally` block.

If any write fails, return `500` and do not report success. Avoid partial data
where possible by writing the record before the `last_id` update; a future
create can recover from a stale `last_id` by scanning IDs.

## Schema and Seed-Data Migration

The present posts schema declares `id` as `string|automatic`, but ID filenames
and newly generated IDs are numeric. Correct it to:

```json
"id": "number|automatic"
```

Existing seed records may use string IDs and at least one record has
`version: null`. Normalize seed data before or alongside this revamp:

- Convert every `id` to a number matching its filename.
- Set every `version` to a positive numeric value; use `1` where no usable
  version exists.
- Keep the current `_oc`, timestamps, `docStat`, and content fields.

This makes list projection, detail data files, and created records share one
stable contract.

## File-by-File Implementation Plan

### Add `routes.json`

- Add the initial posts list GET route.
- Add the posts create POST route using the same list file.
- Add the posts detail GET route using `{id}` and a `dataFile` pattern.
- Add a small static mock route such as `GET /health` using `mocks/health.json`.
- Do not add tasks. It has no backing storage.

### Replace `server/api/route.php`

Remove these old concepts:

- `public_resource()` fixed tasks/posts regular expression.
- `resource_storage()` special `ALL/tasks` versus `posts` mapping.
- fixed POST/PUT/PATCH/DELETE/GET dispatch conventions.
- `public_resource_url()` behavior from the old REST layer.

Implement a generic route module with focused functions:

- `load_routes($docRoot)`: load and validate the root registry.
- `normalize_route($route, $index, $docRoot)`: validate fields, compile path
  metadata, validate response file patterns/headers, and return normalized
  route data.
- `match_route_path($route, $uri)`: return captured parameters or `null`.
- `resolve_route_file($docRoot, $filePattern, $params)`: substitute declared
  parameters safely and return a canonical file path.
- `methods_for_path($routes, $uri)`: find configured methods for OPTIONS/405.
- `send_route_response($route, $body)`: set status, fixed JSON headers, safe
  configured headers, then emit JSON.
- `serve_data_file_route(...)`: resolve/decode/serve a response JSON file.
- `serve_list_file_route(...)`: call list GET logic or POST creation logic.
- `handle_api_route($docRoot, $uri)`: coordinate all normal non-OPTIONS
  dispatch and errors.

Keep function signatures explicit; do not introduce PHP callbacks or code
execution through `routes.json`.

### Simplify `server/api/list.php`

Keep only functions used for GET/listFile collection responses:

- `strip_hidden_fields()`
- `is_deleted()`
- `matches_filters()` and `match_filter()`
- `parse_sort()` and `sort_items()`
- one strict `serve_list_json($listPath)` implementation

Change `serve_list_json()` to report invalid list configuration through the
generic route error helper rather than echoing an empty array. Its caller must
have already validated that the file is inside the project root.

Delete:

- `handle_paginate()`
- legacy flat-array fallback
- `serve_record()`
- `handle_hierarchy()`
- calls to relation resolution

### Replace/remove `server/api/rest.php`

Delete this file after moving the minimal schema parsing, value casting, and
safe POST creation code into `server/api/route.php` or a new narrowly scoped
`server/api/create.php`.

If creation remains reasonably sized, keep it in `route.php`; otherwise create
`create.php` with only:

- schema parsing/editability checks
- request-object validation
- value casting
- locked ID allocation
- atomic record/list JSON writing

Do not retain old REST handler names or route-specific assumptions.

### Delete `server/schema.php`

Its helpers only support old schema CRUD. Move the small parser needed for
POST creation into the route/create module. Do not preserve a separate generic
schema layer unless it has a current caller.

### Reduce `server/helpers.php`

Retain only helpers required by the final code:

- JSON/CORS response helper(s)
- nested field parsing/getting for list filters and projection
- raw query-string parsing that preserves dots in keys

Remove unused code:

- static file MIME map / `serve_file()`
- generic HTML `respond()` helper
- UUID helpers
- relation resolution
- production error helper

### Update `router.php`

- Keep explorer routing for `/` and `/api-test`.
- Parse the request URI path once.
- Handle OPTIONS using registry-derived methods.
- Send every other path to generic route dispatch, without a segment allowlist.
- Remove hardcoded API CORS methods.

### Update `public/index.php`

- Keep loading helpers, list logic, generic route logic, and the router.
- Remove includes for deleted `schema.php` and `rest.php`.
- Include `create.php` only if creation is extracted from `route.php`.

### Rebuild `api-test.php`

The explorer must load `routes.json`, not scan or hardcode resources.

For each valid route, render:

- HTTP method badge, including unknown/custom methods.
- Configured path and configured success status.
- Response source type and root-relative source file.
- Configured response headers.
- One input for each path parameter.
- Query-string key/value input(s).
- Request-header key/value input(s).
- Optional raw JSON body textarea.
- Try It button and formatted response status/body output.

For a GET/listFile route, expose convenience fields for `_page` and `_sort`;
also retain generic query fields for filters. Do not recreate old generated
PATCH/PUT/DELETE UI. The generic body textarea is enough to exercise a
schema-backed POST route and arbitrary mock methods.

Render a configuration error card if `routes.json` cannot be parsed. Avoid PHP
warnings or a broken explorer page.

### Update `README.md`

Document:

- startup command and explorer URLs
- routes.json object contract
- data-file patterns and path parameter rules
- list-file GET behavior and list.json options
- schema-backed POST behavior
- response header support
- 404, 405, OPTIONS, and CORS behavior
- local-development-only warning because all configured write routes remain
  unauthenticated and cross-origin
- that JSON configuration changes are visible on the next request and PHP
  changes require a server restart

Remove statements that public resources are fixed tasks/posts or that standard
PUT/PATCH/DELETE persistence exists.

## Execution Order

1. Add `routes.json` and static mock seed file(s).
2. Normalize posts schema and seed record types.
3. Implement registry loading, validation, matching, file-pattern resolution,
   configured headers, 404/405, and OPTIONS behavior.
4. Simplify GET/listFile behavior in `server/api/list.php` while preserving
   supported query options.
5. Implement locked schema-backed POST/listFile creation.
6. Update bootstrap/router includes and remove hardcoded resource admission.
7. Delete obsolete REST/schema/unreachable list code and unused helpers.
8. Rebuild the explorer from routes.json.
9. Update README.
10. Lint and smoke-test the new contract.

## Verification

### PHP lint

```sh
php -l public/index.php
php -l router.php
php -l api-test.php
php -l server/helpers.php
php -l server/api/list.php
php -l server/api/route.php
```

If `server/api/create.php` is introduced:

```sh
php -l server/api/create.php
```

### Manual server run

```sh
php -S 0.0.0.0:8000 -t public public/index.php
```

### Smoke tests

```sh
curl -i http://localhost:8000/posts
curl -i 'http://localhost:8000/posts?_page=1&_sort=title:ASC'
curl -i http://localhost:8000/posts/13
curl -i http://localhost:8000/health
curl -i -X POST http://localhost:8000/posts -H 'Content-Type: application/json' -d '{"title":"Created by route registry","content":"example","tags":["mock"],"isPublished":false}'
curl -i -X OPTIONS http://localhost:8000/posts -H 'Origin: http://localhost:3000' -H 'Access-Control-Request-Method: POST' -H 'Access-Control-Request-Headers: Content-Type, Authorization'
curl -i -X DELETE http://localhost:8000/posts
curl -i http://localhost:8000/does-not-exist
```

Expected outcomes:

- `/posts` uses `api/posts/list.json` options and returns projected records.
- `/posts/{id}` resolves its configured file pattern.
- Static routes return their configured JSON, status, and safe response
  headers.
- POST `/posts` validates the sibling schema, writes one new ID file, updates
  `last_id`, and returns generated server metadata.
- OPTIONS lists configured methods and permits requested custom headers.
- DELETE `/posts` returns `405` unless a DELETE route is explicitly added.
- Unknown paths return JSON `404`.
- No request path relies on a hardcoded resource name.

### Concurrency test

Send multiple POST requests to one listFile-backed collection in parallel.
Verify every successful response has a unique numeric ID, matching ID filename,
and a monotonic `list.json:last_id` value. This specifically validates the new
exclusive lock and atomic-write behavior.
