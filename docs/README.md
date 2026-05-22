# Documentation

This directory contains short topic-oriented documents for the Ultiorganizer test harness.

## Core

- [Architecture](architecture.md): high-level structure, execution model, runtime boundaries, and reporting model
- [Runtime](runtime.md): disposable runtime copy, webroot, and isolation rules
- [Local Workflow](local-workflow.md): common local commands and worktree-oriented usage
- [MCP](mcp.md): MCP wrapper purpose, scope, and extension rules
- [AI Docs](ai/README.md): repo-local AI skills and helper guidance

## Testing

- [PHP Syntax Lint](lint.md): SUT-wide `php -l` syntax checks
- [Export Contract Testing](export.md): CSV, JSON, XML, and RSS endpoint contracts
- [REST API Contract Testing](api.md): versioned JSON API auth and response contracts
- [PHPUnit Suites](phpunit.md): `unit` and `integration` suite purpose and behavior
- [Per-File Lib Tests](lib-tests.md): catalog, naming convention, checkpoint status, and incremental lib-test commands
- [Deep Coverage Readiness](lib-test-deep-coverage.md): what broader per-file coverage requires from the harness versus the SUT
- [Lib Test Pitfalls](lib-test-pitfalls.md): concrete gotchas with shims, process reuse, aggregate queries, exit branches, and output buffering
- [Smoke Testing](smoke.md): deterministic public page sanity checks
- [Crawl Testing](crawl.md): broader HTTP and security-oriented runtime probing

## Test Data And Cases

- [Matrix](matrix.md): case model and how to add or change cases
- [Fixtures](fixtures.md): fixture pack purpose and design rules

## Results

- [Reporting](reporting.md): report locations, summary structure, and failure classes

## Maintenance

- [Lib Test Triage](lib-test-triage.md): changed-file failure classification for per-file lib tests
