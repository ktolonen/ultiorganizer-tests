# PHPUnit Suites

## Purpose

The harness has four PHPUnit-driven non-crawl suites:

- `unit`
- `integration`
- `export`
- `api`

They run through the same runtime preparation flow as other case runs, but their assertions live in the repository test code rather than in crawl plan configuration.

## Unit

`unit` covers isolated PHP behavior that does not depend on a prepared runtime database.

Typical targets:

- helper functions
- pure or mostly pure logic
- code paths that are easier to validate with direct assertions than through HTTP requests

Current tests live under `tests/Unit/`.

Per-file lib coverage should prefer `tests/Unit/Lib/<DerivedClassName>LibTest.php`.

## Integration

`integration` covers DB-backed application behavior using the disposable MariaDB schema loaded from:

- production schema in the SUT
- harness fixture pack in this repository

Typical targets:

- configuration reads
- season, team, and pool queries
- DB-backed helper functions

Current tests live under `tests/Integration/`.

Per-file lib coverage should prefer `tests/Integration/Lib/<DerivedClassName>LibTest.php`.

## Export

`export` covers public export endpoint contracts over HTTP.

Typical targets:

- CSV endpoints under `ext/`
- JSON location export
- XML location export
- RSS feed output

Current tests live under `tests/Export/`.

## API

`api` covers the versioned JSON API over HTTP.

Typical targets:

- OpenAPI JSON
- token authentication failures
- authenticated fixture-backed API reads
- event visibility and token scope behavior

Current tests live under `tests/Api/`.

## Execution

Both suites run through PHPUnit using `phpunit.xml.dist`.

They inherit the prepared runtime environment, including:

- copied SUT
- generated test config
- disposable test database
- loaded fixture pack

## Artifacts

Each PHPUnit suite writes:

- raw suite log
- JUnit XML
- parsed test/failure counts in the case summary

If a test fails, the summary records the first failed test and marks the failure as `phpunit_test_failure`.

## Code coverage

The `unit` and `integration` suites run the SUT in-process, so they yield PHP
code coverage of the SUT's top-level `lib/*.php` files. The `export`, `api`,
`smoke`, and `crawl` suites are HTTP-driven and are not covered.

Coverage is collected with PCOV, which is installed in the `php-test` image but
disabled by default; the harness opts in per invocation only for the two
in-process suites. Each suite writes a raw `.cov` dump, and after a case's
suites finish, `phpcov` merges them into `coverage/` under the run directory:

- `coverage/html/` — browsable HTML report (also linked from the `report:html`
  index detail panel)
- `coverage/clover.xml` — Clover XML
- `coverage/coverage.txt` — text summary
- `coverage/coverage.json` — line-coverage percentage consumed by `report:html`

The coverage scope is the SUT's `lib/` tree, excluding the vendored third-party
directories, configured via the `<source>` block in `phpunit.xml.dist`. Cases
that run neither in-process suite (the smoke-only customization cases) produce
no `coverage/` directory.

## When To Use

Use `unit` when the behavior can be checked directly in PHP with minimal environment dependence.

Use `integration` when correctness depends on the loaded schema, fixtures, or DB-backed application helpers.

Use `export` when the meaningful check is parseable machine-readable output from `ext/`. Use `api` when the meaningful check is the versioned JSON API contract. Use `smoke` or `crawl` when the meaningful check is broader HTTP-visible runtime behavior rather than direct PHP assertions.

For top-level `lib/*.php` work, use the catalog in `config/lib-test-catalog.json` and the incremental commands documented in [Per-File Lib Tests](lib-tests.md).
