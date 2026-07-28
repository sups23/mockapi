# Generate API Data

Use this guide when a user asks for mock CRUD API data from a product description, requirements, screenshots, API specifications, or an existing codebase.

## Goal

Create or update convention-based CRUD resource packages under `api/{resource}/`:

```text
api/{resource}/
  schema.json
  list.json
  seed.json
  id/
```

Prefer the existing Seedbox **Create Route** wizard or `POST /routes-config` for a new resource. Use direct JSON edits only when updating an existing resource or when a user explicitly asks for files.

## Input Handling

1. Inspect supplied requirements, designs, API contracts, and relevant source files before inferring a data model.
2. When context is vague, choose a small, conventional resource model that supports the described screen or workflow. State assumptions in the final response.
3. When exact API details are supplied, preserve resource names, field names, types, response shape, pagination, and example values.
4. When an existing codebase is supplied, derive entities from types, forms, table columns, client requests, fixtures, and domain terminology. Do not invent fields that conflict with the code.
5. Ask one focused question only when a missing decision materially changes the API contract. Otherwise choose a conservative default.

## Resource Design

- Use lowercase, hyphenated plural resource names, such as `posts`, `order-items`, or `team-members`.
- Keep each resource focused on one domain entity.
- Use `string` for text, enum-like labels, URLs, and identifiers that are not numeric database IDs.
- Use `number` for quantities, prices, rankings, and numeric measurements.
- Use `boolean` for toggles and state flags.
- Use `array` for tags, labels, and simple collections.
- Use ISO 8601 strings for `datetime` fields.
- Include only fields needed by the described UI or behavior. Prefer realistic defaults and fixtures over speculative models.
- Do not define user fields named `id`, `createdAt`, `modifiedAt`, or `version`; the server manages them.

## Create the Files

### `schema.json`

Define server fields and editable user fields. Every user field needs a valid type and should normally be editable with a default.

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

### `list.json`

Expose fields needed by collection views and configure operations deliberately.

```json
{
  "fields": ["id", "name", "price", "active", "version"],
  "_limit": 10,
  "last_id": 3,
  "disable": []
}
```

Use `disable` when the described API should not allow a generated CRUD operation. Available values are `list`, `create`, `read`, `patch`, `delete`, and `reset`.

### `seed.json`

Provide a representative JSON array with unique positive integer IDs. Seed records must satisfy the schema and include server-managed values.

```json
[
  {
    "id": 1,
    "name": "Starter plan",
    "price": 19,
    "active": true,
    "tags": ["popular"],
    "createdAt": "2026-01-15T09:00:00.000Z",
    "modifiedAt": "2026-01-15T09:00:00.000Z",
    "version": 0
  }
]
```

Use `[]` when the resource should begin empty. Set `last_id` to the highest seed ID.

## Fixture Quality

- Use enough records to exercise tables, empty states, filters, status badges, and pagination when those appear in the source material.
- Make values internally consistent. Dates, totals, statuses, and relationships should agree.
- Avoid secrets, real customer data, production credentials, and unnecessary personal information.
- Keep fixtures deterministic. Do not generate changing timestamps or random values unless explicitly requested.
- Update both `seed.json` and runtime `id/{id}.json` when the user expects immediate current runtime data. Otherwise use `POST /api/{resource}/reset` to hydrate the seed.

## Validate

1. Confirm schema names and types match the requirements or code.
2. Confirm every seed record has a unique positive integer `id` and valid values.
3. Confirm `list.json.fields` contains only schema fields and `last_id` matches the highest fixture ID.
4. Run PHP lint and request the affected list and read endpoints.
5. Report the resources, assumptions, and verification performed.
