# PHPUnit Suites

## Purpose

The harness has two PHPUnit-driven non-crawl suites:

- `unit`
- `integration`

They run through the same runtime preparation flow as other case runs, but their assertions live in the repository test code rather than in crawl plan configuration.

## Unit

`unit` covers isolated PHP behavior that does not depend on a prepared runtime database.

Typical targets:

- helper functions
- pure or mostly pure logic
- code paths that are easier to validate with direct assertions than through HTTP requests

Current tests live under `tests/Unit/`.

## Integration

`integration` covers DB-backed application behavior using the disposable MariaDB schema loaded from:

- production schema in the SUT
- harness fixture pack in this repository

Typical targets:

- configuration reads
- season, team, and pool queries
- DB-backed helper functions

Current tests live under `tests/Integration/`.

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

## When To Use

Use `unit` when the behavior can be checked directly in PHP with minimal environment dependence.

Use `integration` when correctness depends on the loaded schema, fixtures, or DB-backed application helpers.

Use `smoke` or `crawl` when the meaningful check is HTTP-visible runtime behavior rather than direct PHP assertions.
