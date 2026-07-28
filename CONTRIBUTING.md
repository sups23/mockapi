# Contributing to Seedbox

Thanks for contributing. Seedbox is a small PHP project, so prefer focused
changes that preserve the file-backed architecture and keep configuration
behavior explicit.

## Before You Start

- Read `README.md` and the relevant generator guide.
- Check existing issues before opening a new one.
- Do not include secrets, credentials, customer data, or generated runtime
  records in a pull request.

## Development

Start the local server from the project root:

```sh
php -S 0.0.0.0:8000 -t public public/index.php
```

When adding a resource, prefer the Seedbox Create Route workflow or commit the
resource's `schema.json`, `list.json`, `seed.json`, and route fixtures directly.
Runtime `id/*.json` records and `.write.lock` files are intentionally ignored.

## Pull Requests

- Explain the user-visible behavior and affected files.
- Add or update deterministic JSON fixtures when behavior needs examples.
- Update `README.md` when routes, configuration, or setup changes.
- Run the PHP lint and JSON validation commands from the README.
- Keep unrelated formatting or refactoring out of the change.

There is no automated test suite yet. Include manual smoke-test commands and
results for endpoint or UI changes.
