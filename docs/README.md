# Documentation

Short topic docs for the Ultiorganizer test harness.

## Core

- [Architecture](architecture.md): components, run flow, suites
- [Runtime](runtime.md): disposable SUT copy, webroot, isolation rules
- [Local Workflow](local-workflow.md): commands, alternate checkouts, PR context
- [MCP](mcp.md): thin MCP wrapper
- [AI Docs](ai/README.md): repo-local agent skills

## Suites

- [PHP Syntax Lint](lint.md): `php -l` over the SUT
- [PHPUnit Suites](phpunit.md): `unit`, `integration`, `export`, `api`, `smoke`; code coverage
- [Export Contracts](export.md): `ext/` CSV, JSON, XML, RSS
- [REST API Contracts](api.md): `/api/v1`
- [Smoke Testing](smoke.md): page allowlist and page-level HTTP tests
- [Crawl Testing](crawl.md): declarative crawl plans
- [Client JavaScript Tests](js-tests.md): host Node tests for `script/*.js`

## Per-File Lib Tests

- [Lib Tests](lib-tests.md): catalog, naming, commands
- [Lib Test Pitfalls](lib-test-pitfalls.md): gotchas to read before writing a lib test
- [Lib Test Triage](lib-test-triage.md): classifying a failure after an SUT change
- [Deep Coverage Limits](lib-test-deep-coverage.md): what the SUT structure makes untestable in-process

## Data, Cases, Results

- [Matrix](matrix.md): cases and when to add one
- [Fixtures](fixtures.md): the baseline fixture pack
- [Reporting](reporting.md): report layout, summaries, failure classes
