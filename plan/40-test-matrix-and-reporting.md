# Test Matrix and Reporting Plan

## Matrix Design

The test harness must support multiple combinations of:

- customization
- configuration profile
- fixture pack
- enabled suites

Each case should be declared explicitly in repository-owned configuration rather than assembled ad hoc at runtime.

## Recommended Matrix Fields

Each matrix case should define:

- `id`
- `customization`
- `config_profile`
- `fixture_pack`
- `suites`
- `description`
- optional `tags`

## PoC Matrix

Initial PoC matrix should contain one case only:

- `baseline-default`
  - customization: `cust/default`
  - config profile: `baseline`
  - fixture pack: `baseline`
  - suites: `unit`, `integration`, `smoke`

## Reporting Requirements

Every test run must produce results that can be read at these levels:

- overall run
- matrix case
- suite
- individual test case

Each reported result should include:

- customization id
- configuration profile id
- matrix case id
- suite name
- test name
- status
- duration

## Artifact Layout

Recommended report layout:

- `reports/junit/` for JUnit XML
- `reports/summary/` for Markdown or HTML summaries
- `reports/logs/` for raw logs
- `reports/cases/<case-id>/` for case-scoped outputs

## Failure Classification

Failures should be grouped into at least:

- bootstrap or configuration failure
- container startup failure
- database initialization failure
- fixture load failure
- test assertion failure
- page render or runtime failure
- MCP invocation or wrapper failure

## Summary Expectations

Human-readable summaries should show:

- when the run happened
- which SUT path or branch was tested
- which matrix cases ran
- pass/fail counts by suite
- failed test cases with short reasons
- locations of detailed artifacts

## Branch and Worktree Context

Because validation may happen before a PR exists, reports should record branch context where available:

- branch name
- commit SHA if available
- local worktree or checkout path
- optional run label supplied by the caller

## Acceptance Criteria

Reporting is sufficient when:

- a developer or agent can tell exactly which customization and config failed
- failed cases can be traced to logs quickly
- JUnit output can be reused later by CI systems if added
- summaries remain readable for single-case and multi-case runs
