# AGENTS.md

## Purpose

`seedbox/` is a standalone PHP file-backed REST server. It has no database,
package manager, gateway, authentication layer, or runtime mode selection.

## Start The Server

Use the same front controller for PHP's built-in server and Herd:

```sh
php -S 0.0.0.0:8000 -t public public/index.php
```

Herd document root:

```text
/workspace/public
```

Useful URLs:

```text
/                 Seedbox API workspace
/seedbox          Seedbox workspace alias
/api/posts        Posts collection (convention-based CRUD)
/scenario-config  Active scenario selector (localhost only)
```

## Architecture

- `public/index.php` is the PHP-FPM/Herd front controller.
- `router.php` is also the built-in-server router when started with the command above.
- `server/api/route.php` dispatches CRUD requests by URL convention and custom mock routes from `routes/`.
- `server/api/schema.php` parses structured schema definitions with types, defaults, and flags.
- `server/api/repository.php` provides file locking, atomic writes, seed initialization, and reset.
- `server/api/resource-config.php` validates and atomically generates new CRUD resource packages.
- `server/api/scenario-config.php` validates and persists the active fixture scenario.
- `server/api/list.php` implements filtering, sorting, projection, pagination, and schema-aware filter validation.
- `server/helpers.php` contains nested-field, query, and JSON header helpers.
- `seedbox.php` renders the workspace shown at `/` and `/seedbox`.

## CRUD Resources (convention-based)

Any directory under `api/` containing both `schema.json` and `list.json` is automatically a CRUD resource. No route file needed.

```text
api/posts/
  schema.json    # Field types, defaults, editability
  list.json      # Fields projection, pagination, disable
  scenarios/     # Named fixture scenarios
    default/
      seed.json
      records/    # Runtime records for the scenario
```

Automatic endpoints:

```text
GET     /api/{resource}
POST    /api/{resource}
GET     /api/{resource}/{id}
PATCH   /api/{resource}/{id}
DELETE  /api/{resource}/{id}
POST    /api/{resource}/reset
```

Disable operations in `list.json`:

```json
{
  "fields": ["id", "title", "version"],
  "_limit": 10,
  "activeScenario": "default",
  "disable": ["delete", "reset"]
}
```

Supported: `list`, `create`, `read`, `patch`, `delete`, `reset`. Disabled operations return `404`.

## File Storage

```text
api/{resource}/
  schema.json
  list.json
  scenarios/{name}/
    seed.json
    records/{number}.json
```

IDs are sequential integers scoped to the active scenario and checked against existing record filenames before creation. `list.json:activeScenario` persists the globally selected scenario.

`schema.json` defines field types, editability flags, defaults, and automatic status. Reserved server-managed fields are `id`, `createdAt`, `modifiedAt`, and `version`.

## Mutation Rules

- `POST` creates a numeric ID, applies schema defaults, returns `201`.
- `PATCH` applies partial updates. Server-managed and non-editable fields are rejected with `400`.
- `DELETE` requires `{"version": <number>}` in the request body. Stale versions return `409`.
- All mutations acquire exclusive file locks and write atomically via temp-file-and-rename.
- Write failures are checked and reported rather than silently succeeding.

## Seedbox Workspace

The homepage is the primary Seedbox workspace. It includes:

- Route files rendered as groups with expand/collapse cards.
- **Create Route** wizard button for generating new CRUD resources with schema fields.
- Schema-driven create forms with default values pre-filled.
- Patch forms with editable field hints.
- Delete forms with version input and usage instructions.
- Reset buttons with destructive confirmation.
- List filters with field type hints and schema-aware operator guidance.
- CRUD request forms with path parameters, query/header editors.
- Response status bars and pretty-printed JSON bodies.
- Response search with highlight and match navigation.
- Persisted light/dark theme selection in `localStorage`.
- Scenario dropdowns that switch the globally active fixture dataset.

## Seed and Reset

- Each `scenarios/{name}/seed.json` is the canonical fixture dataset for that scenario. It is automatically hydrated on first access when the scenario's `records/` directory is empty.
- Empty scenario seed `[]` means that scenario starts with no records.
- `POST /api/{resource}/reset` wipes active scenario records and re-seeds them. Used for test isolation and CI.
- Seed records require unique positive integer `id` values and type-valid fields.

## Verification

There is no automated test suite. Run PHP lint and smoke-test the public routes:

```sh
php -l public/index.php
php -l router.php
php -l seedbox.php
php -l server/helpers.php
php -l server/api/schema.php
php -l server/api/repository.php
php -l server/api/resource-config.php
php -l server/api/scenario-config.php
php -l server/api/list.php
php -l server/api/route.php
curl -s http://localhost:8000/api/posts
curl -s http://localhost:8000/
```

PHP changes require restarting the built-in server. JSON data changes are read on the next request. Route file changes take effect immediately.
