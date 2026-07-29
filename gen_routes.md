# Generate Mock Routes

Use this guide when a user asks for custom mock endpoints from a product description, requirements, API specification, frontend code, or an existing codebase.

## Goal

Create static mock routes and response fixtures that supplement an existing CRUD resource:

```text
routes/{resource}.json
api/{resource}/scenarios/{scenario}/mocks/{route-name}.json
```

Prefer the Seedbox **Add Mock Route** button or `POST /mock-route-config` for one new route. It creates the response fixture in every existing scenario; edit those files afterward for scenario-specific responses. Use direct file edits for multiple related routes, parameterized routes, headers, or updates to an existing registry.

## Input Handling

1. Inspect supplied API calls, types, components, routes, and fixtures before designing endpoints.
2. Extract each required HTTP method, URL, response shape, status code, error case, and path parameter.
3. If the input is vague, model only endpoints necessary for the stated workflow. Use conventional REST-like names and realistic static JSON.
4. If an API contract is exact, preserve its path, method, casing, status, headers, and payload shape.
5. If the request spans both data and endpoints, create CRUD resource data using `gen_api.md` first, then add only non-CRUD behavior here.

## Choose the Route Owner

For a route under `/api/posts/...`, use `routes/posts.json`. The first URL segment after `/api/` selects the registry.

Attach a mock to a CRUD resource only for a non-CRUD subpath, for example:

```text
GET /api/posts/total
GET /api/posts/featured
POST /api/posts/import
GET /api/posts/{slug}/preview
```

Do not create mocks for generated CRUD URLs:

```text
/api/posts
/api/posts/reset
/api/posts/{numeric-id}
```

Generated CRUD routes take precedence and would intercept those requests.

## Route Registry Format

Each JSON key is a path and each nested key is an HTTP method.

```json
{
  "/api/posts/total": {
    "GET": {
      "file": "api/posts/scenarios/{{activeScenario}}/mocks/total.json",
      "status": 200
    }
  },
  "/api/posts/{slug}/preview": {
    "GET": {
      "file": "api/posts/scenarios/{{activeScenario}}/mocks/preview.json",
      "status": 200,
      "headers": {
        "X-Mock": "true"
      }
    }
  }
}
```

- Route paths must start with `/api/` and cannot include a query string or fragment.
- Methods are alphabetic HTTP method names.
- Each method requires `file` or `path`, beginning with `api/`.
- `status` is optional and defaults to `200`; valid values are `100` through `599`.
- `headers` is optional. Keys and values are strings. Do not set content type, CORS, connection, transfer encoding, content length, or `Allow` headers.

## Response Fixtures

Create valid JSON under `api/{resource}/scenarios/{scenario}/mocks/` for every
scenario that should support the route. Use `{{activeScenario}}` in the route
fixture path so the server selects the active scenario at request time.

```json
{
  "count": 5,
  "updatedAt": "2026-01-15T09:00:00.000Z"
}
```

Name files from the final meaningful URL segment:

```text
/api/posts/total            -> api/posts/scenarios/{{activeScenario}}/mocks/total.json
/api/posts/featured         -> api/posts/scenarios/{{activeScenario}}/mocks/featured.json
/api/posts/{slug}/preview   -> api/posts/scenarios/{{activeScenario}}/mocks/preview.json
```

When two responses need the same filename, add the method or a clear domain qualifier, such as `preview-post.json` or `preview-error.json`. Do not use opaque hashes in fixture names.

## Matching Behavior

- Static routes match before parameterized routes.
- `{name}` parameters match one non-empty path segment and can be substituted into a fixture path.
- `{{activeScenario}}` is substituted server-side from the resource's active
  scenario before the fixture path is resolved.
- A missing path returns `404`.
- A known path with an unsupported method returns `405` and an `Allow` header.
- `OPTIONS` returns `204` with allowed methods.
- Request bodies, query parameters, and request headers do not alter a static fixture response unless separate routes are created for distinct paths or methods.

## Fixture Quality

- Match the exact response shape the client consumes, including nested objects, arrays, nullable values, pagination metadata, and error bodies.
- Provide representative success, empty, validation-error, and not-found responses when requirements call for them.
- Keep fixtures deterministic and free of real secrets or customer data.
- Avoid routes that duplicate generated CRUD behavior; use the CRUD resource package for collections and numeric records.

## Validate

1. Confirm the registry file matches the first `/api/` path segment.
2. Confirm every scenario response fixture exists under
   `api/{resource}/scenarios/{scenario}/mocks/` and contains valid JSON.
3. Confirm static and parameterized paths do not conflict unexpectedly.
4. Confirm no mock shadows a generated CRUD collection, numeric ID, or reset route.
5. Request each endpoint and verify its status, headers, and JSON response.
